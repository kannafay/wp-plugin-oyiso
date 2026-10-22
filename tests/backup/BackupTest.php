<?php

declare(strict_types=1);

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;

final class BackupRequestStopped extends RuntimeException
{
    public function __construct(public readonly mixed $body, public readonly mixed $title, public readonly mixed $args)
    {
        parent::__construct('WordPress response ended');
    }
}

final class BackupTest extends TestCase
{
    private mixed $original;
    private array $post;
    private array $request;
    private array $files;
    private string $method;
    private Closure $dieFilter;

    protected function setUp(): void
    {
        wp_set_current_user(OYISO_AGENT_TEST_ADMIN);
        $this->original = get_option('oyiso', null);
        $this->post = $_POST; $this->request = $_REQUEST; $this->files = $_FILES;
        $this->method = $_SERVER['REQUEST_METHOD'];
        $this->dieFilter = static fn(): Closure => static function ($body = '', $title = '', $args = []): never {
            throw new BackupRequestStopped($body, $title, $args);
        };
        add_filter('wp_die_ajax_handler', $this->dieFilter);
        update_option('oyiso', ['backup_fixture' => 'original'], false);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(OYISO_AGENT_TEST_ADMIN);
        if ($this->original === null) delete_option('oyiso'); else update_option('oyiso', $this->original, false);
        $_POST = $this->post; $_REQUEST = $this->request; $_FILES = $this->files;
        $_SERVER['REQUEST_METHOD'] = $this->method;
        remove_filter('wp_die_ajax_handler', $this->dieFilter);
    }

    private function payload(array $data = ['backup_fixture' => 'restored']): array
    {
        return ['plugin' => 'oyiso', 'option_name' => 'oyiso', 'blog_id' => get_current_blog_id(), 'data' => $data];
    }

    private function request(array $params, string $method = 'POST', string $handler = 'import'): array
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_POST = wp_slash($params); $_REQUEST = $_POST; $_FILES = [];
        ob_start();
        try {
            Oyiso_Plugin_Backup::$handler();
            self::fail('The handler must end the WordPress response.');
        } catch (BackupRequestStopped $stop) {
            $text = ob_get_contents();
            return ['json' => json_decode($text, true), 'text' => $text, 'stop' => $stop];
        } finally {
            ob_end_clean();
        }
    }

    private function directoryState(): array
    {
        $directory = WP_CONTENT_DIR . '/oyiso-private/backups/site-' . get_current_blog_id();
        $files = glob($directory . '/*') ?: [];
        return ['exists' => is_dir($directory), 'files' => array_map(static fn(string $file): array => [$file, filesize($file), filemtime($file)], $files)];
    }

    private function php(string $source): array
    {
        $process = proc_open([PHP_BINARY, '-r', $source], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        self::assertSame('', $error);
        return json_decode($output, true, 64, JSON_THROW_ON_ERROR);
    }

    public function testExportHandlerReturnsAnAttachmentBodyWithoutCreatingAServerFile(): void
    {
        $before = $this->directoryState();
        $source = 'require ' . var_export(dirname(__DIR__) . '/ai-agent/bootstrap.php', true) . ';'
            . 'add_filter("option_oyiso", static fn(): array => ["backup_fixture" => "downloaded"]);'
            . '$_REQUEST["_wpnonce"] = wp_create_nonce("oyiso_plugin_backup_export");'
            . 'Oyiso_Plugin_Backup::export();';
        self::assertSame(['backup_fixture' => 'downloaded'], $this->php($source)['data']);
        self::assertSame($before, $this->directoryState());
    }

    public function testMultisiteRequiresTheMatchingNumericSiteIdBeforeSaving(): void
    {
        $source = 'define("ABSPATH", ' . var_export(ABSPATH, true) . ');'
            . 'require ABSPATH . "wp-includes/plugin.php"; require ABSPATH . "wp-includes/class-wp-error.php";'
            . 'function is_multisite(): bool {return true;} function get_current_blog_id(): int {return 7;}'
            . 'function update_option($key, $value): bool {$GLOBALS["writes"]++; return true;} $GLOBALS["writes"]=0;'
            . 'require ' . var_export(dirname(__DIR__, 2) . '/src/update-backup/backup/index.php', true) . ';'
            . '$results=[]; foreach ([null, 8, "7", 7] as $site) {'
            . '$payload=["plugin"=>"oyiso","option_name"=>"oyiso","data"=>["fixture"=>"matching"]];'
            . 'if ($site !== null) $payload["blog_id"]=$site;'
            . '$result=Oyiso_Plugin_Backup::restore(json_encode($payload));'
            . '$results[]=$result instanceof WP_Error ? $result->get_error_code() : $result;}'
            . 'echo json_encode(["results"=>$results,"writes"=>$GLOBALS["writes"]]);';
        self::assertSame(['results' => ['oyiso_backup_site', 'oyiso_backup_site', 'oyiso_backup_site', true], 'writes' => 1], $this->php($source));
    }

    public function testDownloadDocumentRoundTripsUnicodeHtmlAndNestedSettingsWithoutFiles(): void
    {
        $data = ['backup_fixture' => "中文 O'Reilly \\ path\n<p><a href=\"https://example.com/?a=1&b=2\">Link</a></p>", 'nested' => ['enabled' => true, 'list' => [['value' => 12]]]];
        update_option('oyiso', $data, false);
        $before = $this->directoryState();
        $json = Oyiso_Plugin_Backup::document();
        self::assertIsString($json);
        $payload = json_decode($json, true);
        self::assertSame('oyiso', $payload['plugin']);
        self::assertSame(get_current_blog_id(), $payload['blog_id']);
        self::assertSame($data, $payload['data']);
        update_option('oyiso', ['backup_fixture' => 'changed'], false);
        self::assertTrue(Oyiso_Plugin_Backup::restore($json));
        self::assertSame($data, get_option('oyiso'));
        self::assertTrue(Oyiso_Plugin_Backup::restore($json));
        self::assertSame($before, $this->directoryState());
    }

    public function testLegacyJsonAndUtf8BomAreAcceptedAndEmptyConfigurationRemainsValid(): void
    {
        $payload = $this->payload(['backup_fixture' => 'legacy']);
        if (!is_multisite()) unset($payload['blog_id']);
        self::assertTrue(Oyiso_Plugin_Backup::restore("\xEF\xBB\xBF" . wp_json_encode($payload)));
        self::assertSame('legacy', get_option('oyiso')['backup_fixture']);
        self::assertTrue(Oyiso_Plugin_Backup::restore(wp_json_encode($this->payload([]))));
        self::assertSame([], get_option('oyiso'));
    }

    public function testMalformedForeignOversizedAndInvalidConfigurationsNeverOverwriteSettings(): void
    {
        $invalid = ['', '{', 'null', '[]', '"text"', str_repeat(' ', Oyiso_Plugin_Backup::MAX_BYTES + 1), str_repeat('[', 70) . str_repeat(']', 70)];
        foreach ([['plugin' => 'other'], ['option_name' => 'users'], ['data' => 'invalid'], ['data' => [123]], ['data' => ['' => 1]]] as $extra) {
            $invalid[] = wp_json_encode(array_replace($this->payload(), $extra));
        }
        foreach ($invalid as $json) {
            self::assertInstanceOf(WP_Error::class, Oyiso_Plugin_Backup::restore($json));
            self::assertSame(['backup_fixture' => 'original'], get_option('oyiso'));
        }
    }

    public function testOversizedOrUnencodableExportsFailWithoutCreatingFiles(): void
    {
        $before = $this->directoryState();
        $filter = static fn(): array => ['large' => str_repeat('a', Oyiso_Plugin_Backup::MAX_BYTES)];
        add_filter('option_oyiso', $filter, 100);
        try { self::assertSame('oyiso_backup_size', Oyiso_Plugin_Backup::document()->get_error_code()); }
        finally { remove_filter('option_oyiso', $filter, 100); }
        $resource = fopen('php://memory', 'r+');
        $filter = static fn(): array => ['unsupported' => $resource];
        add_filter('option_oyiso', $filter, 100);
        try { self::assertSame('oyiso_backup_encode', Oyiso_Plugin_Backup::document()->get_error_code()); }
        finally { remove_filter('option_oyiso', $filter, 100); fclose($resource); }
        $filter = static fn(): string => 'invalid configuration';
        add_filter('option_oyiso', $filter, 100);
        try { self::assertSame('oyiso_backup_data', Oyiso_Plugin_Backup::document()->get_error_code()); }
        finally { remove_filter('option_oyiso', $filter, 100); }
        self::assertSame($before, $this->directoryState());
    }

    public function testAjaxRestoresPostedJsonAndPreservesSlashesWithoutAFileUpload(): void
    {
        $data = ['backup_fixture' => "Quoted ' \" \\ \n 中文", 'html' => '<p>Text &amp; link</p>'];
        $before = $this->directoryState();
        $result = $this->request(['nonce' => wp_create_nonce('oyiso_plugin_backup_import'), 'backup' => wp_json_encode($this->payload($data))]);
        self::assertTrue($result['json']['success']);
        self::assertSame($data, get_option('oyiso'));
        self::assertSame([], $_FILES);
        self::assertSame($before, $this->directoryState());
    }

    public function testAjaxRejectsNonPostInvalidNonceMissingContentAndAnonymousRequests(): void
    {
        $nonce = wp_create_nonce('oyiso_plugin_backup_import');
        $json = wp_json_encode($this->payload());
        self::assertFalse($this->request(['nonce' => $nonce, 'backup' => $json], 'GET')['json']['success']);
        self::assertSame('-1', (string) $this->request(['nonce' => 'invalid', 'backup' => $json])['stop']->body);
        foreach ([[], ['file' => 'backup.json'], ['backup' => []], ['backup' => 'invalid'], ['backup' => str_repeat('a', Oyiso_Plugin_Backup::MAX_BYTES + 1)]] as $params) {
            self::assertFalse($this->request(['nonce' => $nonce] + $params)['json']['success']);
        }
        wp_set_current_user(0);
        self::assertFalse($this->request(['nonce' => $nonce, 'backup' => $json])['json']['success']);
        self::assertSame('无权限下载配置备份。', $this->request([], 'GET', 'export')['stop']->body);
        self::assertSame(['backup_fixture' => 'original'], get_option('oyiso'));
    }

    public function testFailedDatabaseWriteReturnsAnErrorInsteadOfReportingSuccess(): void
    {
        $filter = static fn($value, $old) => $old;
        add_filter('pre_update_option_oyiso', $filter, 10, 2);
        try { self::assertSame('oyiso_backup_save', Oyiso_Plugin_Backup::restore(wp_json_encode($this->payload()))->get_error_code()); }
        finally { remove_filter('pre_update_option_oyiso', $filter, 10); }
    }

    public function testDownloadChecksTheNonceAndReportsEncodingErrorsBeforeSendingAFile(): void
    {
        $denied = $this->request(['_wpnonce' => 'invalid'], 'GET', 'export');
        self::assertSame(403, $denied['stop']->args['response']);
        $filter = static fn(): array => ['large' => str_repeat('a', Oyiso_Plugin_Backup::MAX_BYTES)];
        add_filter('option_oyiso', $filter, 100);
        try {
            $result = $this->request(['_wpnonce' => wp_create_nonce('oyiso_plugin_backup_export')], 'GET', 'export');
            self::assertSame(413, $result['stop']->args['response']);
        } finally { remove_filter('option_oyiso', $filter, 100); }
    }

    public function testAuthenticatedUsersWithoutManageOptionsCannotDownloadOrRestore(): void
    {
        $nonce = wp_create_nonce('oyiso_plugin_backup_import');
        $filter = static fn(array $caps): array => [];
        add_filter('user_has_cap', $filter);
        try {
            self::assertFalse($this->request(['nonce' => $nonce, 'backup' => wp_json_encode($this->payload())])['json']['success']);
            self::assertSame('无权限下载配置备份。', $this->request([], 'GET', 'export')['stop']->body);
        } finally { remove_filter('user_has_cap', $filter); }
        self::assertSame(['backup_fixture' => 'original'], get_option('oyiso'));
    }

    public function testPanelAndRoutesHaveNoServerBackupListOrLegacyFileEndpoints(): void
    {
        $before = $this->directoryState();
        foreach (['create', 'download', 'restore_local', 'delete_local'] as $action) {
            self::assertFalse(has_action('wp_ajax_oyiso_plugin_backup_' . $action));
        }
        self::assertSame(10, has_action('admin_post_oyiso_plugin_backup_export', [Oyiso_Plugin_Backup::class, 'export']));
        self::assertSame(10, has_action('wp_ajax_oyiso_plugin_backup_import', [Oyiso_Plugin_Backup::class, 'import']));
        self::assertFalse(has_action('admin_post_nopriv_oyiso_plugin_backup_export'));
        self::assertFalse(has_action('wp_ajax_nopriv_oyiso_plugin_backup_import'));
        ob_start(); Oyiso_Plugin_Backup::render(); $html = ob_get_clean();
        self::assertStringContainsString('下载配置备份', $html);
        self::assertStringContainsString('admin-post.php?action=oyiso_plugin_backup_export', $html);
        self::assertStringNotContainsString('本地备份记录', $html);
        self::assertStringNotContainsString('oyiso-private', $html);
        self::assertStringNotContainsString('<form', $html);
        Oyiso_Plugin_Backup::assets('index.php');
        Oyiso_Plugin_Backup::assets('plugins_page_oyiso');
        self::assertTrue(wp_style_is('oyiso-plugin-backup', 'enqueued'));
        self::assertTrue(wp_script_is('oyiso-plugin-backup', 'enqueued'));
        self::assertSame($before, $this->directoryState());
    }
}
