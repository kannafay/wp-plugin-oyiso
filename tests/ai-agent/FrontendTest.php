<?php

declare(strict_types=1);

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;

final class FrontendTest extends TestCase
{
    private array $options;
    private array $posts = [];
    private array $runs = [];
    private array $globals = [];
    private string $uri;

    protected function setUp(): void
    {
        wp_set_current_user(OYISO_AGENT_TEST_ADMIN);
        $this->options = Oyiso_Agent_Config::options();
        $this->uri = $_SERVER['REQUEST_URI'];
        foreach (['wp_query', 'wp_the_query', 'post', 'current_screen', 'wp_scripts', 'wp_styles', 'title'] as $key) $this->globals[$key] = $GLOBALS[$key] ?? null;
        update_option('oyiso', array_replace($this->options, ['agent_enabled' => true, 'agent_frontend' => false, 'agent_plugin_tools_version' => '2', 'agent_mcp_namespaces' => ['oyiso-wordpress', 'oyiso-woocommerce'], 'agent_write_tools' => ['oyiso/apply-post-update']]), false);
        set_current_screen('front');
        $GLOBALS['wp_query'] = new WP_Query(); $GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
        self::assertFalse(is_admin()); $this->resetAssets();
    }

    protected function tearDown(): void
    {
        wp_set_current_user(OYISO_AGENT_TEST_ADMIN); Oyiso_Agent_Abilities::bind(null);
        foreach ($this->runs as $id) { Oyiso_Agent_Store::cancel($id); wp_delete_post($id, true); }
        foreach ($this->posts as $id) wp_delete_post($id, true);
        update_option('oyiso', $this->options, false);
        foreach ($this->globals as $key => $value) { if ($value === null) unset($GLOBALS[$key]); else $GLOBALS[$key] = $value; }
        $_SERVER['REQUEST_URI'] = $this->uri;
    }

    private function resetAssets(): void
    {
        $GLOBALS['wp_scripts'] = new WP_Scripts(); $GLOBALS['wp_styles'] = new WP_Styles();
    }

    private function enable(): void
    {
        update_option('oyiso', array_replace(Oyiso_Agent_Config::options(), ['agent_frontend' => true]), false);
    }

    private function markup(): string
    {
        ob_start(); Oyiso_Agent_Admin::panel(); return ob_get_clean();
    }

    private function visit(string $type): WP_Post
    {
        $id = wp_insert_post(['post_type' => $type, 'post_status' => 'draft', 'post_title' => '前台助手测试', 'post_content' => '<p>已保存的内容</p>', 'post_excerpt' => '已保存的摘要', 'post_author' => OYISO_AGENT_TEST_ADMIN], true);
        self::assertIsInt($id); $this->posts[] = $id;
        $GLOBALS['wp_query'] = new WP_Query(['p' => $id, 'post_type' => $type, 'post_status' => 'draft']); $GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
        $_SERVER['REQUEST_URI'] = '/frontend-fixture/?p=' . $id . '&_wpnonce=secret&key=secret#access_token=secret';
        return get_post($id);
    }

    public function testFrontendIsOptInAndUnprivilegedVisitorsReceiveNoAssistantAssetsOrMarkup(): void
    {
        self::assertSame(10, has_action('wp_enqueue_scripts', [Oyiso_Agent_Admin::class, 'frontendAssets']));
        self::assertSame(10, has_action('wp_footer', [Oyiso_Agent_Admin::class, 'panel']));
        foreach ([false, true] as $enabled) {
            update_option('oyiso', array_replace(Oyiso_Agent_Config::options(), ['agent_frontend' => $enabled]), false);
            $this->resetAssets(); Oyiso_Agent_Admin::frontendAssets();
            self::assertSame($enabled, wp_script_is('oyiso-agent', 'enqueued'));
            self::assertSame($enabled, wp_style_is('oyiso-agent-frontend', 'enqueued'));
            self::assertSame($enabled, str_contains($this->markup(), 'id="oyiso-agent-launcher"'));
            self::assertSame($enabled, in_array('oyiso-agent-frontend', Oyiso_Agent_Admin::bodyClasses(['original']), true));
        }
        foreach (['guest', 'subscriber'] as $role) {
            $user = $role === 'guest' ? 0 : wp_insert_user(['user_login' => 'oyiso-frontend-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(48), 'role' => 'subscriber']);
            self::assertIsInt($user);
            try {
                wp_set_current_user($user); $this->resetAssets(); Oyiso_Agent_Admin::frontendAssets();
                self::assertFalse(wp_script_is('oyiso-agent', 'enqueued')); self::assertFalse(wp_style_is('oyiso-agent-frontend', 'enqueued'));
                self::assertSame('', $this->markup()); self::assertSame([], Oyiso_Agent_Page_Context::current());
                self::assertSame(['original'], Oyiso_Agent_Admin::bodyClasses(['original']));
                self::assertFalse(wp_style_is('oyiso-admin-theme', 'enqueued'));
            } finally { wp_set_current_user(OYISO_AGENT_TEST_ADMIN); if ($user > 0) wp_delete_user($user); }
        }
        update_option('oyiso', array_replace(Oyiso_Agent_Config::options(), ['agent_enabled' => false]), false);
        $this->resetAssets(); Oyiso_Agent_Admin::frontendAssets();
        self::assertFalse(wp_script_is('oyiso-agent', 'enqueued')); self::assertSame('', $this->markup());
        self::assertFalse(Oyiso_Agent_Config::frontendEnabled());
    }

    public function testFrontendLoadsItsOwnDependenciesAndClosingTheSwitchKeepsBackendAccess(): void
    {
        $this->enable(); Oyiso_Agent_Admin::frontendAssets();
        foreach (['oyiso-agent', 'oyiso-agent-panel', 'oyiso-agent-page', 'oyiso-agent-file-preview', 'oyiso-modal-motion', 'jquery'] as $handle) self::assertTrue(wp_scripts()->query($handle, 'registered') !== false, $handle);
        foreach (['oyiso-agent', 'oyiso-agent-frontend', 'oyiso-agent-file-preview', 'oyiso-modals', 'oyiso-admin-theme', 'dashicons'] as $handle) self::assertTrue(wp_style_is($handle, 'enqueued'), $handle);
        self::assertTrue(wp_scripts()->all_deps(['oyiso-agent'])); self::assertTrue(wp_styles()->all_deps(['oyiso-agent-frontend']));
        self::assertFalse(wp_script_is('oyiso-agent-model-settings', 'enqueued')); self::assertFalse(wp_script_is('oyiso-agent-files', 'enqueued'));
        self::assertStringContainsString('"nonce":', wp_scripts()->registered['oyiso-agent-panel']->extra['data'] ?? '');
        update_option('oyiso', array_replace(Oyiso_Agent_Config::options(), ['agent_frontend' => false]), false);
        set_current_screen('dashboard'); $GLOBALS['title'] = '仪表盘'; $this->resetAssets(); Oyiso_Agent_Admin::assets('index.php');
        self::assertTrue(wp_script_is('oyiso-agent', 'enqueued')); self::assertStringContainsString('id="oyiso-agent-panel"', $this->markup());
        self::assertFalse(wp_style_is('oyiso-agent-frontend', 'enqueued'));
    }

    public function testRealFrontendBootstrapWorksWithoutLoadingTheSettingsFramework(): void
    {
        $this->enable();
        $process = proc_open([PHP_BINARY, __DIR__ . '/frontend-bootstrap-check.php', (string) OYISO_AGENT_TEST_ADMIN], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); self::assertSame(0, proc_close($process), $error);
        self::assertSame('', $error);
        self::assertSame(['admin' => false, 'settings_framework_unchanged' => true, 'script' => true, 'modals' => true, 'frontend_style' => true, 'panel' => true], json_decode($output, true));
    }

    public function testFrontendContextRecognizesEditableContentAndNeverTrustsClientFormValues(): void
    {
        $this->enable();
        foreach (['post', 'page', 'product'] as $type) {
            $post = $this->visit($type); $seed = Oyiso_Agent_Page_Context::current();
            self::assertSame($post->ID, $seed['post_id']); self::assertSame($type, $seed['post_type']);
            self::assertSame('frontend', $seed['editor']); self::assertFalse($seed['dirty']);
            self::assertStringNotContainsString('secret', $seed['url']);
            $seed['fields'] = ['title' => '任意表单输入', 'content' => '不能当作编辑器']; $seed['editor'] = 'classic'; $seed['dirty'] = true; $seed['selection'] = '私密输入';
            $parsed = Oyiso_Agent_Page_Context::parse(wp_json_encode($seed)); self::assertIsArray($parsed);
            self::assertSame('frontend', $parsed['editor']); self::assertFalse($parsed['dirty']); self::assertSame('', $parsed['selection']);
            self::assertSame('前台助手测试', $parsed['fields']['title']);
            self::assertSame('<p>已保存的内容</p>', $parsed['fields'][$type === 'product' ? 'description' : 'content']);
            self::assertStringContainsString('前台浏览页', Oyiso_Agent_Page_Context::prompt($parsed));
        }
        update_option('oyiso', array_replace(Oyiso_Agent_Config::options(), ['agent_frontend' => false]), false);
        self::assertSame('agent_frontend_disabled', Oyiso_Agent_Page_Context::parse(wp_json_encode($seed))->get_error_code());
        self::assertSame([], Oyiso_Agent_Page_Context::current());
    }

    public function testFrontendWriteStillNeedsAConfirmedPreviewAndCannotClaimToFillAnEditor(): void
    {
        $this->enable(); $post = $this->visit('post');
        $page = Oyiso_Agent_Page_Context::parse(wp_json_encode(Oyiso_Agent_Page_Context::current()));
        $run = Oyiso_Agent_Store::create('Front-end preview fixture'); self::assertIsArray($run); $this->runs[] = $run['id'];
        Oyiso_Agent_Runner::message($run, '修改当前文章', [], $page); Oyiso_Agent_Abilities::bind(new Oyiso_Agent_Tools($run));
        self::assertSame('frontend', wp_get_ability('oyiso/get-current-page')->execute([])['editor']);
        self::assertSame('saved', wp_get_ability('oyiso/get-post')->execute(['post_id' => $post->ID])['source']);
        $proposal = wp_get_ability('oyiso/prepare-post-update')->execute(['post_id' => $post->ID, 'fields' => ['content' => '<p>确认后修改</p>']]); self::assertIsArray($proposal);
        self::assertSame('<p>已保存的内容</p>', get_post($post->ID)->post_content);
        $run['status'] = 'waiting'; Oyiso_Agent_Store::save($run);
        $request = new WP_REST_Request('POST', '/oyiso-agent/v1/runs/' . $run['id'] . '/content/' . $proposal['proposal_id']);
        $request->set_param('action', 'apply'); self::assertSame(200, rest_do_request($request)->get_status());
        self::assertSame('<p>确认后修改</p>', get_post($post->ID)->post_content);
    }

    public function testFrontendSettingDefaultsOffAndPageUrlsExcludeCredentials(): void
    {
        $options = Oyiso_Agent_Config::options(); unset($options['agent_frontend']); update_option('oyiso', $options, false);
        self::assertFalse(Oyiso_Agent_Config::frontendEnabled()); self::assertFalse(Oyiso_Agent_Config::saveOptions($options)['agent_frontend']);
        $this->enable(); self::assertTrue(Oyiso_Agent_Config::saveOptions($options)['agent_frontend']);
        self::assertFalse(Oyiso_Agent_Config::saveOptions($options + ['agent_frontend' => '0'])['agent_frontend']);
        self::assertSame('/shop/?p=12', Oyiso_Agent_Page_Context::url('/shop/?p=12&key=secret&api_key=secret#token=secret'));
        self::assertSame('', Oyiso_Agent_Page_Context::url('https://example.invalid/shop/?p=12'));
        self::assertSame('', Oyiso_Agent_Page_Context::url('//example.invalid/shop/'));
        self::assertSame('', Oyiso_Agent_Page_Context::url('javascript:alert(1)'));
        $section = array_values(array_filter(CSF::$args['sections']['oyiso'], static fn(array $section): bool => ($section['id'] ?? '') === 'agent-model'))[0];
        $field = array_values(array_filter($section['fields'], static fn(array $field): bool => ($field['id'] ?? '') === 'agent_frontend'))[0];
        self::assertSame('switcher', $field['type']); self::assertFalse($field['default']);
    }
}
