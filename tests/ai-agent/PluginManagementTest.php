<?php

declare(strict_types=1);

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Tools\DTO\FunctionCall;

/** Native activation/deactivation is exercised only against uniquely named temporary plugins. */
final class PluginManagementTest extends TestCase
{
    private array $options;
    private array $active;
    private array $runs = [];
    private array $files = [];
    private array $dependencies = [];
    private string $prefix;
    private string $base;

    protected function setUp(): void
    {
        wp_set_current_user(OYISO_AGENT_TEST_ADMIN);
        $this->options = Oyiso_Agent_Config::options();
        $this->active = get_option('active_plugins', []);
        $this->prefix = 'oyiso-agent-fixture-' . str_replace('-', '', wp_generate_uuid4());
        $reflection = new ReflectionClass(WP_Plugin_Dependencies::class);
        foreach ($reflection->getProperties(ReflectionProperty::IS_STATIC) as $property) $this->dependencies[$property->getName()] = $property->getValue();
        $this->base = $this->plugin('base');
        update_option('oyiso', array_replace($this->options, ['agent_enabled' => true, 'agent_plugin_tools_version' => '2', 'agent_mcp_namespaces' => ['oyiso-wordpress'], 'agent_write_tools' => Oyiso_Agent_Plugins::ACTIONS]), false);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(OYISO_AGENT_TEST_ADMIN);
        wp_get_current_user()->remove_cap('activate_plugins');
        Oyiso_Agent_Abilities::bind(null);
        foreach ($this->runs as $id) {
            wp_clear_scheduled_hook(Oyiso_Agent_Runner::HOOK, [$id]);
            as_unschedule_all_actions(Oyiso_Agent_Runner::HOOK, [$id], 'oyiso-agent');
            Oyiso_Agent_Store::unlock('run_' . $id);
            Oyiso_Agent_Store::cancel($id); wp_delete_post($id, true);
        }
        // Restore the original list verbatim. Never activate/deactivate any pre-existing plugin.
        update_option('active_plugins', $this->active);
        foreach ($this->files as $file) {
            Oyiso_Agent_Store::unlock('plugin_' . hash('sha256', $file));
            delete_option('oyiso_agent_lock_plugin_' . hash('sha256', $file));
            foreach (['activated', 'deactivated'] as $event) {
                remove_all_actions(($event === 'activated' ? 'activate_' : 'deactivate_') . $file);
                delete_option($this->prefix . '_' . $event . '_' . basename(dirname($file)));
            }
            // These exact files and directories were created by plugin() for this test.
            unlink(WP_PLUGIN_DIR . '/' . $file); rmdir(dirname(WP_PLUGIN_DIR . '/' . $file));
        }
        wp_clean_plugins_cache(false);
        $reflection = new ReflectionClass(WP_Plugin_Dependencies::class);
        foreach ($this->dependencies as $name => $value) $reflection->getProperty($name)->setValue(null, $value);
        update_option('oyiso', $this->options, false);
    }

    private function plugin(string $suffix, string $headers = '', string $code = ''): string
    {
        $slug = $this->prefix . '-' . $suffix;
        $file = $slug . '/fixture.php'; $path = WP_PLUGIN_DIR . '/' . $file;
        self::assertDirectoryDoesNotExist(dirname($path)); self::assertTrue(mkdir(dirname($path)));
        $source = "<?php\n/*\nPlugin Name: Oyiso fixture $suffix\nVersion: 1.0\n$headers\n*/\n";
        foreach (['activated', 'deactivated'] as $event) {
            $option = var_export($this->prefix . '_' . $event . '_' . $slug, true);
            $source .= ($event === 'activated' ? 'register_activation_hook' : 'register_deactivation_hook') . '(__FILE__, static function (): void { update_option(' . $option . ', (int) get_option(' . $option . ', 0) + 1, false); });' . "\n";
        }
        self::assertNotFalse(file_put_contents($path, $source . $code)); $this->files[] = $file;
        wp_clean_plugins_cache(false); $this->resetDependencies(); return $file;
    }

    private function resetDependencies(): void
    {
        $reflection = new ReflectionClass(WP_Plugin_Dependencies::class);
        foreach ($reflection->getDefaultProperties() as $name => $value) $reflection->getProperty($name)->setValue(null, $value);
    }

    private function task(): array
    {
        $run = Oyiso_Agent_Store::create('Plugin management fixture');
        self::assertIsArray($run); $this->runs[] = $run['id']; return $run;
    }

    private function tool(array &$run, string $name, array $input = []): mixed
    {
        Oyiso_Agent_Abilities::bind(new Oyiso_Agent_Tools($run));
        return wp_get_ability('oyiso/' . $name)->execute($input);
    }

    private function prepare(array &$run, string $file, bool $activate = true): string
    {
        self::assertIsArray($this->tool($run, 'list-plugins', ['search' => $file]));
        $result = $this->tool($run, $activate ? 'activate-plugin' : 'deactivate-plugin', ['plugin_file' => $file]);
        self::assertIsArray($result); self::assertSame('pending', $result['status']);
        $run['status'] = 'waiting'; Oyiso_Agent_Store::save($run); return $result['action_id'];
    }

    private function confirm(array $run, string $id, string $decision = 'execute'): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/oyiso-agent/v1/runs/' . $run['id'] . '/mcp/' . $id);
        $request->set_param('action', $decision); return rest_do_request($request);
    }

    private function hookCount(string $file, string $event): int
    {
        return (int) get_option($this->prefix . '_' . $event . '_' . basename(dirname($file)), 0);
    }

    public function testQueryIsReadOnlyPaginatedAndSnapshotsOnlyReturnedPlugins(): void
    {
        for ($i = 1; $i <= 11; $i++) $this->plugin('page-' . $i);
        $run = $this->task(); $input = ['search' => $this->prefix, 'status' => 'inactive'];
        $first = $this->tool($run, 'list-plugins', $input);
        self::assertSame(12, $first['total']); self::assertSame(2, $first['pages']); self::assertCount(10, $first['plugins']);
        self::assertCount(10, $run['plugin_snapshots']);
        foreach ($first['plugins'] as $plugin) {
            self::assertSame('inactive', $plugin['status']); self::assertSame('1.0', $plugin['version']);
            self::assertArrayHasKey($plugin['file'], $run['plugin_snapshots']); self::assertArrayNotHasKey('fingerprint', $plugin);
        }
        self::assertCount(2, $this->tool($run, 'list-plugins', $input + ['page' => 2])['plugins']);
        self::assertSame([], $this->tool($run, 'list-plugins', ['search' => $this->prefix, 'status' => 'active'])['plugins']);
        self::assertSame([], $this->tool($run, 'list-plugins', $input + ['page' => 3])['plugins']);
        self::assertSame($this->active, get_option('active_plugins'));
        self::assertSame([], $run['mcp_actions'] ?? []);
        self::assertInstanceOf(WP_Error::class, $this->tool($run, 'list-plugins', ['status' => 'invalid']));
    }

    public function testActivationRequiresConfirmationAndNativeHooksRunOnlyOnce(): void
    {
        $run = $this->task(); $id = $this->prepare($run, $this->base);
        self::assertFalse(is_plugin_active($this->base)); self::assertSame(0, $this->hookCount($this->base, 'activated'));
        self::assertSame($id, $this->tool($run, 'activate-plugin', ['plugin_file' => $this->base])['action_id']); self::assertCount(1, $run['mcp_actions']);
        self::assertSame('agent_plugin_confirmation_required', Oyiso_Agent_Plugins::apply($run, $id)->get_error_code());
        $view = Oyiso_Agent_Store::view($run);
        self::assertArrayNotHasKey('plugin_fingerprint', $view['mcp_actions'][0]); self::assertArrayNotHasKey('plugin_snapshots', $view);
        self::assertSame(['file' => $this->base, 'name' => 'Oyiso fixture base', 'version' => '1.0', 'before' => 'inactive', 'after' => 'active'], $view['mcp_actions'][0]['plugin']);
        $response = $this->confirm($run, $id); self::assertSame(200, $response->get_status());
        self::assertSame('completed', $response->get_data()['mcp_actions'][0]['status']); self::assertTrue(is_plugin_active($this->base));
        self::assertSame(1, $this->hookCount($this->base, 'activated')); self::assertSame(409, $this->confirm($run, $id)->get_status());
        self::assertSame(1, $this->hookCount($this->base, 'activated'));
        $next = $this->task(); self::assertIsArray($this->tool($next, 'list-plugins', ['search' => $this->base]));
        self::assertSame('unchanged', $this->tool($next, 'activate-plugin', ['plugin_file' => $this->base])['status']);
        self::assertEmpty($next['mcp_actions'] ?? []);
    }

    public function testDeactivationPreservesPluginSettingsAndExecutesItsNativeHook(): void
    {
        self::assertNull(activate_plugin($this->base));
        $run = $this->task(); $id = $this->prepare($run, $this->base, false);
        self::assertTrue(is_plugin_active($this->base));
        self::assertSame('completed', $this->confirm($run, $id)->get_data()['mcp_actions'][0]['status']);
        self::assertFalse(is_plugin_active($this->base)); self::assertSame(1, $this->hookCount($this->base, 'deactivated'));
        self::assertSame(1, $this->hookCount($this->base, 'activated'));
        $next = $this->task(); self::assertIsArray($this->tool($next, 'list-plugins', ['search' => $this->base]));
        self::assertSame('unchanged', $this->tool($next, 'deactivate-plugin', ['plugin_file' => $this->base])['status']);
    }

    public function testReadBeforeWriteUsesThisTurnAndAnExactExistingFile(): void
    {
        $run = $this->task();
        self::assertSame('agent_plugin_read_required', $this->tool($run, 'activate-plugin', ['plugin_file' => $this->base])->get_error_code());
        foreach (['../wp-config.php', 'not-a-plugin/file.php', 'Oyiso fixture base', ''] as $file) self::assertInstanceOf(WP_Error::class, $this->tool($run, 'activate-plugin', ['plugin_file' => $file]));
        self::assertIsArray($this->tool($run, 'list-plugins', ['search' => $this->base]));
        Oyiso_Agent_Runner::message($run, '下一轮'); self::assertArrayNotHasKey('plugin_snapshots', $run);
        self::assertSame('agent_plugin_read_required', $this->tool($run, 'activate-plugin', ['plugin_file' => $this->base])->get_error_code());
        self::assertIsArray($this->tool($run, 'list-plugins', ['search' => $this->base]));
        update_option('active_plugins', [...$this->active, $this->base]);
        self::assertSame('agent_plugin_read_required', $this->tool($run, 'deactivate-plugin', ['plugin_file' => $this->base])->get_error_code());
        self::assertSame('agent_unknown_tool', Oyiso_Agent_Plugins::call($run, 'invalid', [])->get_error_code());
    }

    public function testDefaultIsReadOnlyAndWordPressSourceGovernsAllPluginTools(): void
    {
        update_option('oyiso', array_replace(Oyiso_Agent_Config::options(), ['agent_write_tools' => []]), false);
        self::assertContains('oyiso/list-plugins', Oyiso_Agent_Abilities::allowed());
        foreach (Oyiso_Agent_Plugins::ACTIONS as $name) { self::assertNotContains($name, Oyiso_Agent_Abilities::allowed()); self::assertSame('oyiso-wordpress', Oyiso_Agent_Permissions::source($name)); }
        $run = $this->task(); self::assertIsArray($this->tool($run, 'list-plugins'));
        self::assertInstanceOf(WP_Error::class, $this->tool($run, 'activate-plugin', ['plugin_file' => $this->base]));
        self::assertSame('agent_write_disabled', (new Oyiso_Agent_Tools($run))->execute('activate-plugin', ['plugin_file' => $this->base])->get_error_code());
        update_option('oyiso', array_replace(Oyiso_Agent_Config::options(), ['agent_mcp_namespaces' => []]), false);
        self::assertNotContains('oyiso/list-plugins', Oyiso_Agent_Abilities::allowed());
        self::assertSame('agent_plugin_management_denied', (new Oyiso_Agent_Tools($run))->execute('list-plugins', [])->get_error_code());
        update_option('oyiso', array_replace(Oyiso_Agent_Config::options(), ['agent_mcp_namespaces' => ['oyiso-wordpress'], 'agent_write_tools' => Oyiso_Agent_Plugins::ACTIONS]), false);
        wp_get_current_user()->add_cap('activate_plugins', false);
        self::assertNotContains('oyiso/list-plugins', Oyiso_Agent_Abilities::allowed());
        self::assertSame('agent_plugin_management_denied', (new Oyiso_Agent_Tools($run))->execute('list-plugins', [])->get_error_code());
    }

    public function testRevokedGrantsSourcesCapabilitiesAndCancelledTasksCannotConfirm(): void
    {
        $settings = Oyiso_Agent_Config::options(); $run = $this->task(); $id = $this->prepare($run, $this->base);
        update_option('oyiso', array_replace($settings, ['agent_write_tools' => []]), false);
        self::assertSame(403, $this->confirm($run, $id)->get_status());
        update_option('oyiso', array_replace($settings, ['agent_mcp_namespaces' => []]), false);
        self::assertSame(400, $this->confirm($run, $id)->get_status());
        update_option('oyiso', array_replace($settings, ['agent_enabled' => false]), false);
        self::assertSame(403, $this->confirm($run, $id)->get_status());
        update_option('oyiso', $settings, false); wp_set_current_user(0);
        self::assertSame(401, $this->confirm($run, $id)->get_status()); wp_set_current_user(OYISO_AGENT_TEST_ADMIN);
        wp_get_current_user()->add_cap('activate_plugins', false);
        self::assertSame('failed', $this->confirm($run, $id)->get_data()['mcp_actions'][0]['status']);
        self::assertFalse(is_plugin_active($this->base)); wp_get_current_user()->remove_cap('activate_plugins');
        $next = $this->task(); $nextId = $this->prepare($next, $this->base); Oyiso_Agent_Store::cancel($next['id']);
        self::assertSame(403, $this->confirm($next, $nextId)->get_status());
        self::assertFalse(is_plugin_active($this->base)); self::assertSame(0, $this->hookCount($this->base, 'activated'));
    }

    public function testDismissalDoesNotMutateEvenAfterWritePermissionIsRevoked(): void
    {
        $run = $this->task(); $id = $this->prepare($run, $this->base);
        update_option('oyiso', array_replace(Oyiso_Agent_Config::options(), ['agent_write_tools' => []]), false);
        self::assertSame('dismissed', $this->confirm($run, $id, 'dismiss')->get_data()['mcp_actions'][0]['status']);
        self::assertFalse(is_plugin_active($this->base)); self::assertSame(409, $this->confirm($run, $id)->get_status());
    }

    public function testChangedFilesAndPluginStateInvalidateOldConfirmationCards(): void
    {
        $run = $this->task(); $id = $this->prepare($run, $this->base);
        $path = WP_PLUGIN_DIR . '/' . $this->base;
        file_put_contents($path, str_replace('Version: 1.0', 'Version: 2.0', file_get_contents($path)));
        $response = $this->confirm($run, $id)->get_data();
        self::assertSame('failed', $response['mcp_actions'][0]['status']);
        self::assertStringContainsString('旧方案已失效', $response['mcp_actions'][0]['result']); self::assertFalse(is_plugin_active($this->base));
        $run = $this->task(); $id = $this->prepare($run, $this->base);
        self::assertNull(activate_plugin($this->base));
        self::assertSame('failed', $this->confirm($run, $id)->get_data()['mcp_actions'][0]['status']);
        self::assertSame(1, $this->hookCount($this->base, 'activated'));
    }

    public function testDependenciesRequirementsAndSelfProtectionAreRecheckedAtExecution(): void
    {
        $dependent = $this->plugin('dependent', 'Requires Plugins: ' . dirname($this->base));
        $incompatible = $this->plugin('incompatible', 'Requires PHP: 99.0');
        $run = $this->task();
        foreach ([$dependent, $incompatible] as $file) {
            self::assertIsArray($this->tool($run, 'list-plugins', ['search' => $file]));
            self::assertInstanceOf(WP_Error::class, $this->tool($run, 'activate-plugin', ['plugin_file' => $file])); self::assertFalse(is_plugin_active($file));
        }
        $self = plugin_basename(dirname(__DIR__, 2) . '/oyiso.php');
        self::assertIsArray($this->tool($run, 'list-plugins', ['search' => $self]));
        self::assertSame('agent_plugin_self', $this->tool($run, 'deactivate-plugin', ['plugin_file' => $self])->get_error_code());
        self::assertNull(activate_plugin($this->base));
        $id = $this->prepare($run, $this->base, false);
        self::assertNull(activate_plugin($dependent));
        $response = $this->confirm($run, $id)->get_data();
        self::assertSame('failed', $response['mcp_actions'][0]['status']); self::assertStringContainsString('依赖', $response['mcp_actions'][0]['result']);
        self::assertTrue(is_plugin_active($this->base));
        self::assertIsArray($this->tool($run, 'list-plugins', ['search' => $this->base]));
        self::assertSame('agent_plugin_dependency', $this->tool($run, 'deactivate-plugin', ['plugin_file' => $this->base])->get_error_code());
        deactivate_plugins([$dependent, $this->base], false, false);
        $run = $this->task(); $id = $this->prepare($run, $this->base);
        file_put_contents(WP_PLUGIN_DIR . '/' . $this->base, str_replace('Version: 1.0', "Version: 1.0\nRequires PHP: 99.0", file_get_contents(WP_PLUGIN_DIR . '/' . $this->base)));
        self::assertSame('failed', $this->confirm($run, $id)->get_data()['mcp_actions'][0]['status']);
        self::assertFalse(is_plugin_active($this->base));
    }

    public function testPerPluginLockPreventsConcurrentMutationsAcrossTasks(): void
    {
        $run = $this->task(); $id = $this->prepare($run, $this->base);
        $lock = 'plugin_' . hash('sha256', $this->base); self::assertTrue(Oyiso_Agent_Store::lock($lock));
        try {
            $response = $this->confirm($run, $id)->get_data();
            self::assertSame('failed', $response['mcp_actions'][0]['status']); self::assertStringContainsString('另一项操作', $response['mcp_actions'][0]['result']);
            self::assertFalse(is_plugin_active($this->base));
        } finally { Oyiso_Agent_Store::unlock($lock); }
        $run = $this->task(); $id = $this->prepare($run, $this->base);
        self::assertSame('completed', $this->confirm($run, $id)->get_data()['mcp_actions'][0]['status']);
    }

    public function testPluginOutputAndExceptionsDoNotCorruptTheConfirmationResponseOrHoldLocks(): void
    {
        foreach (['throws' => 'throw new RuntimeException("Fixture activation error");', 'echo' => 'echo "Fixture unexpected output";'] as $suffix => $code) {
            $file = $this->plugin($suffix, '', $code); $run = $this->task(); $id = $this->prepare($run, $file);
            $level = ob_get_level(); ob_start(); $response = $this->confirm($run, $id); $output = ob_get_clean();
            self::assertSame($level, ob_get_level()); self::assertSame('', $output);
            self::assertSame('failed', $response->get_data()['mcp_actions'][0]['status']);
            self::assertSame($suffix === 'echo', is_plugin_active($file));
            if ($suffix === 'echo') self::assertStringContainsString('当前状态为已启用', $response->get_data()['mcp_actions'][0]['result']);
            $lock = 'plugin_' . hash('sha256', $file); self::assertTrue(Oyiso_Agent_Store::lock($lock)); Oyiso_Agent_Store::unlock($lock);
        }
    }

    public function testRunnerCreatesAnOwnedConfirmationCardAndPausesWithoutActivating(): void
    {
        $run = $this->task(); Oyiso_Agent_Runner::message($run, '启用测试插件'); Oyiso_Agent_Store::save($run);
        $parts = [];
        foreach (['list-plugins' => ['search' => $this->base], 'activate-plugin' => ['plugin_file' => $this->base]] as $name => $args) $parts[] = new MessagePart(new FunctionCall('fixture-' . $name, WP_AI_Client_Ability_Function_Resolver::ability_name_to_function_name('oyiso/' . $name), $args));
        $model = new OyisoAgentScriptedModel([new Oyiso_Agent_Turn(new ModelMessage($parts), 25, 'fixture-model')]);
        (new Oyiso_Agent_Runner($model))->step($run['id']); $saved = Oyiso_Agent_Store::get($run['id']);
        self::assertSame('waiting', $saved['status']); self::assertSame('pending', $saved['mcp_actions'][0]['status']);
        self::assertSame('assistant', $saved['conversation'][$saved['mcp_actions'][0]['message_index']]['role']);
        self::assertStringContainsString('插件启停方案已生成，尚未执行', $saved['conversation'][1]['text']);
        self::assertContains('oyiso/list-plugins', $model->allowed); self::assertContains('oyiso/activate-plugin', $model->allowed);
        self::assertFalse(is_plugin_active($this->base));
        $view = Oyiso_Agent_Store::view($saved); self::assertArrayNotHasKey('plugin_fingerprint', $view['mcp_actions'][0]);
    }
}
