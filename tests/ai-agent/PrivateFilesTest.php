<?php

declare(strict_types=1);

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;

final class PrivateFilesTest extends TestCase
{
    private string $directory;
    private string $initialDirectory;
    private Closure $filter;
    private Closure $legacyFilter;
    private string $legacyDirectory;
    private array $extraDirectories = [];
    private array $runs = [];
    private array $users = [];
    private array $original = [];

    protected function setUp(): void
    {
        $this->directory = $this->initialDirectory = wp_normalize_path(sys_get_temp_dir()) . '/oyiso-private-test-' . wp_generate_uuid4();
        $this->filter = fn(): string => $this->directory;
        add_filter('oyiso_agent_private_directory', $this->filter);
        $this->legacyDirectory = $this->initialDirectory . '-legacy';
        $this->legacyFilter = fn(): string => $this->legacyDirectory;
        add_filter('oyiso_agent_legacy_private_directory', $this->legacyFilter);
        wp_set_current_user(OYISO_AGENT_TEST_ADMIN);
        foreach (['oyiso', 'oyiso_agent_connections'] as $key) $this->original[$key] = get_option($key, null);
        update_option('oyiso', ['agent_enabled' => true], false);
    }

    protected function tearDown(): void
    {
        $this->directory = $this->initialDirectory;
        wp_set_current_user(OYISO_AGENT_TEST_ADMIN);
        foreach ($this->runs as $id) { Oyiso_Agent_Store::unlock('run_' . $id); wp_delete_post($id, true); }
        foreach ($this->users as $id) wp_delete_user($id);
        foreach ($this->original as $key => $value) { if ($value === null) delete_option($key); else update_option($key, $value, false); }
        remove_filter('oyiso_agent_private_directory', $this->filter);
        remove_filter('oyiso_agent_legacy_private_directory', $this->legacyFilter);
        foreach ([$this->initialDirectory, $this->legacyDirectory, ...$this->extraDirectories] as $directory) {
            if (!is_dir($directory)) continue;
            foreach (new DirectoryIterator($directory) as $file) if (!$file->isDot() && ($file->isFile() || $file->isLink())) unlink($file->getPathname());
            rmdir($directory);
        }
    }

    private function createRun(): array
    {
        $run = Oyiso_Agent_Store::create('Private storage fixture'); self::assertIsArray($run);
        $this->runs[] = $run['id']; $run['status'] = 'completed'; Oyiso_Agent_Store::save($run); return $run;
    }

    private function upload(string $name = 'reference.md', string $content = 'Private reference'): array
    {
        $upload = Oyiso_Agent_Attachments::inspect($name, $content); self::assertIsArray($upload); return $upload;
    }

    private function legacy(int $parent, string $name = 'legacy.txt', string $content = 'Legacy payload'): int
    {
        $id = wp_insert_post(['post_type' => Oyiso_Agent_Attachments::TYPE, 'post_status' => 'private', 'post_author' => get_current_user_id(), 'post_parent' => $parent, 'post_title' => $name, 'post_mime_type' => 'text/plain', 'post_content' => base64_encode($content)]);
        update_post_meta($id, '_oyiso_agent_file_size', strlen($content)); return $id;
    }

    private function request(string $method, string $route, array $params = []): WP_REST_Response
    {
        $request = new WP_REST_Request($method, '/oyiso-agent/v1/' . $route);
        foreach ($params as $key => $value) $request->set_param($key, $value);
        return rest_do_request($request);
    }

    public function testFilesAreEncryptedAndDatabaseContainsOnlyMetadata(): void
    {
        $run = $this->createRun();
        $files = Oyiso_Agent_Attachments::save($run['id'], [$this->upload('reference.php', '<?php echo "fixture";')]);
        self::assertIsArray($files); $id = $files[0]['id'];
        self::assertSame('', get_post($id)->post_content);
        $relative = get_post_meta($id, '_oyiso_agent_file_path', true);
        self::assertMatchesRegularExpression('/^[a-f0-9-]{36}\.bin$/', $relative);
        $path = Oyiso_Agent_Private_Files::path($relative);
        self::assertFileExists($path); self::assertStringNotContainsString(wp_normalize_path(ABSPATH), $path);
        self::assertStringStartsWith("OYISO-ENC-1\n", file_get_contents($path));
        self::assertStringNotContainsString('<?php echo "fixture";', file_get_contents($path));
        self::assertSame(Oyiso_Agent_Private_Files::VERSION, get_post_meta($id, '_oyiso_agent_file_storage', true));
        self::assertSame(base64_encode('<?php echo "fixture";'), Oyiso_Agent_Attachments::read($id)['data']);
        foreach (['../wp-config.php', ABSPATH . 'wp-config.php', 'php://filter/resource=wp-config.php', 'wrong.bin'] as $bad) self::assertInstanceOf(WP_Error::class, Oyiso_Agent_Private_Files::path($bad));
        file_put_contents($path, 'tampered');
        self::assertSame('agent_file_checksum', Oyiso_Agent_Attachments::read($id)->get_error_code());
    }

    public function testDefaultDirectoryIsPortableAndSitePrivateDirectoriesHaveProtectionFiles(): void
    {
        remove_filter('oyiso_agent_private_directory', $this->filter);
        try {
            self::assertSame(wp_normalize_path(WP_CONTENT_DIR) . '/oyiso-private/agent-files/site-' . get_current_blog_id(), Oyiso_Agent_Private_Files::directory());
        } finally { add_filter('oyiso_agent_private_directory', $this->filter); }
        $this->directory = wp_normalize_path(WP_CONTENT_DIR) . '/oyiso-private/agent-test-' . wp_generate_uuid4();
        $this->extraDirectories[] = $this->directory;
        self::assertSame($this->directory, Oyiso_Agent_Private_Files::directory());
        self::assertStringContainsString('http_response_code(403)', file_get_contents($this->directory . '/index.php'));
        self::assertStringContainsString('Require all denied', file_get_contents($this->directory . '/.htaccess'));
        self::assertStringContainsString('<deny users="*"', file_get_contents($this->directory . '/web.config'));
        $relative = Oyiso_Agent_Private_Files::write('site-private fixture');
        self::assertSame('site-private fixture', Oyiso_Agent_Private_Files::read($relative, hash('sha256', 'site-private fixture')));
        self::assertTrue(Oyiso_Agent_Private_Files::remove($relative));
        file_put_contents($this->directory . '/.htaccess', 'changed protection');
        self::assertIsString(Oyiso_Agent_Private_Files::directory());
        self::assertStringContainsString('Require all denied', file_get_contents($this->directory . '/.htaccess'));
        $this->directory = $this->initialDirectory;
    }

    private function externalFile(int $parent, string $content = 'Outside-site reference'): array
    {
        $id = $this->legacy($parent, 'external.txt', $content);
        wp_update_post(['ID' => $id, 'post_content' => '']);
        $relative = wp_generate_uuid4() . '.bin';
        wp_mkdir_p($this->legacyDirectory);
        file_put_contents($this->legacyDirectory . '/' . $relative, $content);
        update_post_meta($id, '_oyiso_agent_file_path', $relative);
        update_post_meta($id, '_oyiso_agent_file_sha256', hash('sha256', $content));
        return [$id, $relative];
    }

    public function testExternalFilesMigrateWithoutChangingReferencesAndSurviveSiteMoves(): void
    {
        $run = $this->createRun(); [$id, $relative] = $this->externalFile($run['id']);
        self::assertSame('Outside-site reference', Oyiso_Agent_Private_Files::read($relative, hash('sha256', 'Outside-site reference')));
        self::assertSame(base64_encode('Outside-site reference'), Oyiso_Agent_Attachments::read($id)['data']);
        self::assertSame($relative, get_post_meta($id, '_oyiso_agent_file_path', true));
        self::assertSame('', get_post($id)->post_content);
        self::assertSame(Oyiso_Agent_Private_Files::VERSION, get_post_meta($id, '_oyiso_agent_file_storage', true));
        self::assertFileDoesNotExist($this->legacyDirectory . '/' . $relative);
        $source = Oyiso_Agent_Private_Files::path($relative);
        self::assertStringNotContainsString('Outside-site reference', file_get_contents($source));
        self::assertTrue(Oyiso_Agent_Attachments::migrate($id));
        $this->directory = $this->initialDirectory . '-restored'; $this->extraDirectories[] = $this->directory;
        $destination = Oyiso_Agent_Private_Files::path($relative);
        self::assertTrue(copy($source, $destination));
        self::assertSame(base64_encode('Outside-site reference'), Oyiso_Agent_Attachments::read($id)['data']);
        self::assertTrue(Oyiso_Agent_Private_Files::remove($relative));
        $this->directory = $this->initialDirectory;
    }

    public function testFailedRelocationPreservesSourceAndCanBeRetried(): void
    {
        $run = $this->createRun(); [$id, $relative] = $this->externalFile($run['id']);
        $source = $this->legacyDirectory . '/' . $relative;
        file_put_contents($source, 'damaged');
        self::assertSame('agent_file_checksum', Oyiso_Agent_Attachments::migrate($id)->get_error_code());
        self::assertFileExists($source);
        self::assertSame('', get_post_meta($id, '_oyiso_agent_file_storage', true));
        file_put_contents($source, 'Outside-site reference');
        $this->directory = ABSPATH . 'wp-content/private-files';
        self::assertInstanceOf(WP_Error::class, Oyiso_Agent_Attachments::migrate($id));
        self::assertFileExists($source);
        $this->directory = $this->initialDirectory;
        $destination = Oyiso_Agent_Private_Files::path($relative);
        file_put_contents($destination, 'partial destination');
        self::assertTrue(Oyiso_Agent_Attachments::migrate($id));
        self::assertFileDoesNotExist($source);
        self::assertSame(base64_encode('Outside-site reference'), Oyiso_Agent_Attachments::read($id)['data']);
    }

    public function testDeletionCleansExternalFilesBeforeTheirMigration(): void
    {
        $run = $this->createRun(); [$id, $relative] = $this->externalFile($run['id']);
        self::assertSame([$id], Oyiso_Agent_Attachments::remove([$id]));
        self::assertFileDoesNotExist($this->legacyDirectory . '/' . $relative);
        self::assertSame(410, $this->request('GET', 'files/' . $id)->get_status());
    }

    public function testPublicUnwritableAndMalformedDirectoriesCannotBeUsed(): void
    {
        foreach ([ABSPATH . 'wp-content/private-files', ABSPATH . 'wp-content/../private-files', 'relative/path', 'php://memory'] as $directory) {
            $this->directory = $directory;
            self::assertSame('agent_private_directory', Oyiso_Agent_Private_Files::directory()->get_error_code());
        }
        $this->directory = $this->initialDirectory; wp_mkdir_p($this->directory);
        $blocker = $this->directory . '/blocker'; file_put_contents($blocker, 'fixture'); $this->directory = $blocker . '/child';
        self::assertInstanceOf(WP_Error::class, Oyiso_Agent_Private_Files::directory());
        $this->directory = $this->initialDirectory;
    }

    public function testMigrationIsVerifiedIdempotentAndPreservesPayloadWhenStorageFails(): void
    {
        $run = $this->createRun(); $id = $this->legacy($run['id']);
        $this->directory = ABSPATH . 'wp-content/private-files';
        self::assertInstanceOf(WP_Error::class, Oyiso_Agent_Attachments::migrate($id));
        self::assertSame(base64_encode('Legacy payload'), get_post($id)->post_content);
        $this->directory = $this->initialDirectory;
        self::assertTrue(Oyiso_Agent_Attachments::migrate($id));
        self::assertSame('', get_post($id)->post_content);
        $path = get_post_meta($id, '_oyiso_agent_file_path', true);
        self::assertSame('Legacy payload', base64_decode(Oyiso_Agent_Attachments::read($id)['data']));
        self::assertTrue(Oyiso_Agent_Attachments::migrate($id)); self::assertSame($path, get_post_meta($id, '_oyiso_agent_file_path', true));
        $broken = $this->legacy($run['id']); wp_update_post(['ID' => $broken, 'post_content' => 'bad base64!']);
        self::assertInstanceOf(WP_Error::class, Oyiso_Agent_Attachments::migrate($broken)); self::assertSame('bad base64!', get_post($broken)->post_content);
        $fallback = $this->legacy($run['id']); $this->directory = ABSPATH . 'wp-content/private-files';
        self::assertSame(base64_encode('Legacy payload'), Oyiso_Agent_Attachments::read($fallback)['data']);
        $this->directory = $this->initialDirectory;
    }

    public function testImageOptimizationResizesLargeImagesAndKeepsOtherFileTypesIntact(): void
    {
        $image = imagecreatetruecolor(2600, 1600); imagefill($image, 0, 0, imagecolorallocate($image, 240, 180, 120));
        ob_start(); imagepng($image); $png = ob_get_clean(); imagedestroy($image);
        $upload = $this->upload('large.png', $png . str_repeat('x', 5 * 1048576));
        self::assertLessThan(4 * 1048576, $upload['size']);
        self::assertLessThanOrEqual(2048, getimagesizefromstring($upload['content'])[0]);
        self::assertSame('image/webp', $upload['mime']); self::assertSame('large.webp', $upload['name']);
        self::assertSame('%PDF-1.4 fixture', $this->upload('source.pdf', '%PDF-1.4 fixture')['content']);
        self::assertSame('hello', $this->upload('source.txt', 'hello')['content']);
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        self::assertSame($gif, $this->upload('animation.gif', $gif)['content']);
        $pixel = imagecreatetruecolor(1, 1); ob_start(); imagewebp($pixel); $frame = substr(ob_get_clean(), 12); imagedestroy($pixel);
        $chunk = static fn(string $tag, string $data): string => $tag . pack('V', strlen($data)) . $data . (strlen($data) % 2 ? "\0" : '');
        $body = 'WEBP' . $chunk('VP8X', "\x02" . str_repeat("\0", 9)) . $chunk('ANIM', str_repeat("\0", 6)) . str_repeat($chunk('ANMF', str_repeat("\0", 12) . "\x64\0\0\0" . $frame), 2);
        $animated = 'RIFF' . pack('V', strlen($body)) . $body;
        self::assertSame($animated, $this->upload('animation.webp', $animated)['content']);
        self::assertSame($png . 'acTL', $this->upload('animation.png', $png . 'acTL')['content']);
        self::assertSame([], glob($this->directory . '/*') ?: []);
    }

    public function testDeletingFilesLeavesSafeConversationMarkersAndDeletingRunsRemovesBytes(): void
    {
        $run = $this->createRun(); $files = Oyiso_Agent_Attachments::save($run['id'], [$this->upload()]);
        Oyiso_Agent_Runner::message($run, 'Read it', $files); $run['status'] = 'completed'; Oyiso_Agent_Store::save($run);
        $path = Oyiso_Agent_Private_Files::path(get_post_meta($files[0]['id'], '_oyiso_agent_file_path', true));
        $result = $this->request('DELETE', 'files', ['ids' => [$files[0]['id']]]);
        self::assertSame(200, $result->get_status()); self::assertFileDoesNotExist($path);
        self::assertSame(410, $this->request('GET', 'files/' . $files[0]['id'])->get_status());
        self::assertTrue(Oyiso_Agent_Store::view($run)['conversation'][0]['attachments'][0]['deleted']);
        self::assertStringContainsString('已删除', Oyiso_Agent_Attachments::parts([$files[0]['id']])[0]->getText());
        $second = Oyiso_Agent_Attachments::save($run['id'], [$this->upload('again.md')]);
        $secondPath = Oyiso_Agent_Private_Files::path(get_post_meta($second[0]['id'], '_oyiso_agent_file_path', true));
        self::assertIsArray(Oyiso_Agent_Store::remove([$run['id']])); self::assertFileDoesNotExist($secondPath);
    }

    public function testFileManagerFiltersAndDeletionRespectOwnershipAndActiveWorkers(): void
    {
        $run = $this->createRun(); $files = Oyiso_Agent_Attachments::save($run['id'], [$this->upload('search-me.md'), $this->upload('source.pdf', '%PDF-fixture')]);
        $result = $this->request('GET', 'files', ['type' => 'text', 'search' => 'search-me']);
        self::assertSame(200, $result->get_status()); self::assertSame([$files[0]['id']], array_column($result->get_data()['items'], 'id'));
        self::assertArrayNotHasKey('path', $result->get_data()['items'][0]);
        self::assertStringNotContainsString(base64_encode('Private reference'), wp_json_encode($result->get_data()));
        self::assertTrue(Oyiso_Agent_Store::lock('run_' . $run['id']));
        self::assertSame(409, $this->request('DELETE', 'files', ['ids' => [$files[0]['id']]])->get_status()); Oyiso_Agent_Store::unlock('run_' . $run['id']);
        $user = wp_insert_user(['user_login' => 'private-file-test-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'administrator']); $this->users[] = $user;
        wp_set_current_user($user); $other = $this->createRun(); $otherFiles = Oyiso_Agent_Attachments::save($other['id'], [$this->upload()]);
        wp_set_current_user(OYISO_AGENT_TEST_ADMIN);
        self::assertSame(404, $this->request('DELETE', 'files', ['ids' => [$files[0]['id'], $otherFiles[0]['id']]])->get_status());
        self::assertFalse((bool) get_post_meta($files[0]['id'], '_oyiso_agent_file_deleted', true));
        $owned = array_column($this->request('GET', 'files')->get_data()['items'], 'id'); self::assertNotContains($otherFiles[0]['id'], $owned);
        wp_set_current_user(0); self::assertSame(401, $this->request('GET', 'files')->get_status()); self::assertSame(401, $this->request('POST', 'files/migrate')->get_status());
    }

    public function testBatchMigrationKeepsFailuresAndCleansAttachmentsOfAlreadyDeletedRuns(): void
    {
        $run = $this->createRun(); $first = $this->legacy($run['id']); $second = $this->legacy($run['id']);
        [$external, $relative] = $this->externalFile($run['id']);
        $ids = [$first, $second, $external];
        $restrict = static function (string $sql) use (&$ids): string {
            return str_contains($sql, 'ORDER BY p.ID ASC LIMIT 10') ? str_replace('WHERE p.post_type', 'WHERE p.ID IN (' . implode(',', $ids) . ') AND p.post_type', $sql) : $sql;
        };
        $scheduled = wp_next_scheduled(Oyiso_Agent_File_Migration::HOOK);
        add_filter('query', $restrict);
        try {
            $this->directory = ABSPATH . 'wp-content/private-files';
            self::assertSame(0, Oyiso_Agent_File_Migration::batch()['migrated']);
            self::assertNotEmpty(get_post_meta($first, '_oyiso_agent_file_migration_error', true));
            self::assertNotSame('', get_post($first)->post_content);
            $this->directory = $this->initialDirectory;
            foreach ($ids as $id) delete_post_meta($id, '_oyiso_agent_file_migration_error');
            self::assertSame(3, Oyiso_Agent_File_Migration::batch()['migrated']);
            self::assertSame('', get_post($first)->post_content); self::assertSame('', get_post($second)->post_content);
            self::assertSame(Oyiso_Agent_Private_Files::VERSION, get_post_meta($external, '_oyiso_agent_file_storage', true));
            self::assertFileDoesNotExist($this->legacyDirectory . '/' . $relative);
            $deleted = $this->createRun(); $third = $this->legacy($deleted['id']); $ids = [$third];
            wp_update_post(['ID' => $deleted['id'], 'post_status' => 'trash']);
            self::assertSame(1, Oyiso_Agent_File_Migration::batch()['migrated']);
            self::assertSame('', get_post($third)->post_content); self::assertNotEmpty(get_post_meta($third, '_oyiso_agent_file_deleted', true));
        } finally {
            remove_filter('query', $restrict); $this->directory = $this->initialDirectory;
            if ($scheduled === false) wp_clear_scheduled_hook(Oyiso_Agent_File_Migration::HOOK);
        }
    }

    public function testCompressionFallbackAndMissingFileErrorsAreExplicit(): void
    {
        $image = imagecreatetruecolor(32, 32); ob_start(); imagepng($image); $png = ob_get_clean(); imagedestroy($image);
        $noEditors = static fn(): array => [];
        add_filter('wp_image_editors', $noEditors);
        try {
            self::assertSame($png, $this->upload('small.png', $png)['content']);
            self::assertSame('agent_image_compress', Oyiso_Agent_Attachments::inspect('large.png', $png . str_repeat('x', 5 * 1048576))->get_error_code());
        } finally { remove_filter('wp_image_editors', $noEditors); }
        $run = $this->createRun(); $files = Oyiso_Agent_Attachments::save($run['id'], [$this->upload()]);
        $id = $files[0]['id']; $relative = get_post_meta($id, '_oyiso_agent_file_path', true);
        self::assertTrue(Oyiso_Agent_Private_Files::remove($relative));
        self::assertSame('agent_file_missing', Oyiso_Agent_Attachments::read($id)->get_error_code());
        update_post_meta($id, '_oyiso_agent_file_path', '../wp-config.php');
        self::assertSame('agent_file_path', Oyiso_Agent_Attachments::read($id)->get_error_code());
        self::assertInstanceOf(WP_Error::class, Oyiso_Agent_Attachments::erase($id));
        delete_post_meta($id, '_oyiso_agent_file_path');
    }

    public function testFileManagementRemainsAvailableWhenChatIsDisabled(): void
    {
        update_option('oyiso', ['agent_enabled' => false], false);
        ob_start(); Oyiso_Agent_File_Manager::render(); $markup = ob_get_clean();
        self::assertStringContainsString('文件管理', $markup); self::assertStringContainsString('PDF', $markup);
        Oyiso_Agent_File_Manager::assets('plugins_page_oyiso');
        self::assertTrue(wp_script_is('oyiso-agent-files', 'enqueued'));
        self::assertSame(200, $this->request('GET', 'files')->get_status());
        self::assertSame(400, $this->request('DELETE', 'files', ['ids' => []])->get_status());
        self::assertSame(400, $this->request('GET', 'files', ['type' => 'executable'])->get_status());
    }
}
