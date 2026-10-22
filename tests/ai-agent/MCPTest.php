<?php

declare(strict_types=1);

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
    http_response_code(404);
    exit;
}

use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Tools\DTO\FunctionCall;

final class MCPTest extends TestCase
{
    private array $options;
    private array $tasks = [];
    private array $registered = [];
    private int $writes = 0;
    private bool $permission = true;

    protected function setUp(): void
    {
        $this->options = Oyiso_Agent_Config::options();
        update_option('oyiso', ['agent_enabled' => true, 'agent_mcp_namespaces' => ['fixture-mcp']], false);
        wp_set_current_user(OYISO_AGENT_TEST_ADMIN);
        foreach (['read', 'write', 'unknown', 'private', 'large', 'throws', 'error'] as $kind) {
            $name = 'fixture-mcp/' . $kind;
            $args = [
                'label' => 'Fixture ' . $kind,
                'description' => 'Fixture MCP tool ' . $kind,
                'category' => 'oyiso-agent',
                'input_schema' => ['type' => 'object', 'properties' => ['value' => ['type' => 'string']], 'additionalProperties' => false, 'default' => new stdClass()],
                'output_schema' => ['type' => 'object', 'additionalProperties' => true],
                'permission_callback' => fn(): bool => $this->permission && current_user_can('manage_options'),
                'execute_callback' => function (mixed $input) use ($kind): array|WP_Error {
                    if ($kind === 'throws') throw new RuntimeException('Fixture failure');
                    if ($kind === 'error') return new WP_Error('fixture_error', 'Fixture error');
                    if (in_array($kind, ['write', 'unknown'], true)) $this->writes++;
                    return ['value' => $kind === 'large' ? str_repeat('大', 18000) : ((array) $input)['value'] ?? 'empty'];
                },
                'meta' => ['mcp' => ['public' => $kind !== 'private'], 'annotations' => $kind === 'unknown' ? [] : ['readonly' => in_array($kind, ['read', 'large'], true)]],
            ];
            self::assertInstanceOf(WP_Ability::class, WP_Abilities_Registry::get_instance()->register($name, $args));
            $this->registered[] = $name;
        }
        update_option('oyiso', array_replace(Oyiso_Agent_Config::options(), ['agent_write_tools' => ['fixture-mcp/write', 'fixture-mcp/unknown', 'fixture-mcp/throws', 'fixture-mcp/error']]), false);
    }

    protected function tearDown(): void
    {
        Oyiso_Agent_Abilities::bind(null);
        wp_set_current_user(OYISO_AGENT_TEST_ADMIN);
        foreach ($this->registered as $name) wp_unregister_ability($name);
        foreach ($this->tasks as $id) {
            wp_clear_scheduled_hook(Oyiso_Agent_Runner::HOOK, [$id]);
            as_unschedule_all_actions(Oyiso_Agent_Runner::HOOK, [$id], 'oyiso-agent');
            Oyiso_Agent_Store::unlock('run_' . $id);
            delete_option('oyiso_agent_lock_run_' . $id);
            wp_delete_post($id, true);
        }
        update_option('oyiso', $this->options, false);
    }

    private function task(): array
    {
        $run = Oyiso_Agent_Store::create('MCP integration fixture');
        self::assertIsArray($run);
        $this->tasks[] = $run['id'];
        return $run;
    }

    private function call(array &$run, string $name, string $json = '{}'): array|WP_Error
    {
        Oyiso_Agent_Abilities::bind(new Oyiso_Agent_Tools($run));
        return wp_get_ability('oyiso/mcp-call-tool')->execute(['name' => $name, 'input_json' => $json]);
    }

    private function request(array $run, string $actionId, string $decision = 'execute'): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/oyiso-agent/v1/runs/' . $run['id'] . '/mcp/' . $actionId);
        $request->set_param('action', $decision);
        return rest_do_request($request);
    }

    public function testCatalogAndDiscoveryOnlyExposePublicSelectedNamespaces(): void
    {
        $catalog = Oyiso_Agent_MCP::catalog();
        self::assertArrayNotHasKey('fixture-mcp/private', $catalog);
        self::assertArrayNotHasKey('mcp-adapter/execute-ability', $catalog);
        self::assertArrayNotHasKey('oyiso/apply-product-update', $catalog);
        self::assertSame('fixture-mcp（6 个工具）', Oyiso_Agent_MCP::namespaceOptions()['fixture-mcp']);
        $run = $this->task();
        $list = Oyiso_Agent_MCP::call('mcp-list-tools', ['search' => 'read'], $run);
        self::assertSame(['fixture-mcp/read'], array_column($list['tools'], 'name'));
        self::assertSame([], Oyiso_Agent_MCP::call('mcp-list-tools', ['page' => 2], $run)['tools']);
        $info = Oyiso_Agent_MCP::call('mcp-describe-tool', ['name' => 'fixture-mcp/write'], $run);
        self::assertFalse($info['readonly']);
        self::assertArrayHasKey('value', $info['input_schema']['properties']);
        self::assertTrue(Oyiso_Agent_MCP::call('mcp-describe-tool', ['name' => 'fixture-mcp/read'], $run)['readonly']);
        update_option('oyiso', ['agent_enabled' => true, 'agent_mcp_namespaces' => 'invalid'], false);
        self::assertSame([], Oyiso_Agent_MCP::selected());
        self::assertNotContains('oyiso/mcp-call-tool', Oyiso_Agent_Abilities::allowed());
        update_option('oyiso', ['agent_enabled' => true, 'agent_mcp_namespaces' => ['mcp-adapter']], false);
        self::assertSame([], Oyiso_Agent_MCP::selected());
    }

    public function testReadsValidateArgumentsAndPermissionsAndLimitOutput(): void
    {
        $run = $this->task();
        self::assertSame(['output' => ['value' => 'hello']], $this->call($run, 'fixture-mcp/read', '{"value":"hello"}'));
        self::assertSame(['output' => ['value' => 'empty']], $this->call($run, 'fixture-mcp/read'));
        foreach (['[]', 'null', '{broken', '{"unexpected":true}', '{"value":3}'] as $json) self::assertInstanceOf(WP_Error::class, $this->call($run, 'fixture-mcp/read', $json));
        self::assertInstanceOf(WP_Error::class, $this->call($run, 'fixture-mcp/private'));
        $large = $this->call($run, 'fixture-mcp/large');
        self::assertTrue($large['truncated']);
        self::assertLessThanOrEqual(24000, strlen($large['output']));
        $this->permission = false;
        self::assertSame('agent_mcp_forbidden', $this->call($run, 'fixture-mcp/read')->get_error_code());
    }

    public function testWritesWaitForApprovalAndCannotExecuteTwice(): void
    {
        $run = $this->task();
        $pending = $this->call($run, 'fixture-mcp/write', '{"value":"changed"}');
        self::assertSame('pending', $pending['status']);
        self::assertSame(0, $this->writes);
        self::assertTrue(Oyiso_Agent_MCP::pending($run));
        self::assertSame($pending['action_id'], $this->call($run, 'fixture-mcp/write', '{"value":"changed"}')['action_id']);
        self::assertCount(1, $run['mcp_actions']);
        $run['status'] = 'waiting'; Oyiso_Agent_Store::save($run);
        $result = $this->request($run, $pending['action_id']);
        self::assertSame(200, $result->get_status());
        self::assertSame('queued', $result->get_data()['status']);
        self::assertSame('completed', $result->get_data()['mcp_actions'][0]['status']);
        self::assertSame(1, $this->writes);
        self::assertSame(409, $this->request($run, $pending['action_id'])->get_status());
        self::assertSame(1, $this->writes);
        $saved = Oyiso_Agent_Store::get($run['id']);
        self::assertStringContainsString('changed', wp_json_encode($saved['messages']));
        self::assertFalse(Oyiso_Agent_MCP::pending($saved));
    }

    public function testUnknownWriteAnnotationsRequireApprovalAndDismissDoesNotWrite(): void
    {
        $run = $this->task();
        $pending = $this->call($run, 'fixture-mcp/unknown');
        self::assertSame('pending', $pending['status']);
        $run['status'] = 'waiting'; Oyiso_Agent_Store::save($run);
        self::assertSame('dismissed', $this->request($run, $pending['action_id'], 'dismiss')->get_data()['mcp_actions'][0]['status']);
        self::assertSame(0, $this->writes);
        self::assertSame(404, $this->request($run, wp_generate_uuid4())->get_status());
        self::assertSame(400, $this->request($run, $pending['action_id'], 'invalid')->get_status());
    }

    public function testRevokedToolsPermissionsBusyOrCancelledRunsCannotWrite(): void
    {
        $run = $this->task();
        $pending = $this->call($run, 'fixture-mcp/write');
        Oyiso_Agent_Store::save($run);
        self::assertSame(409, $this->request($run, $pending['action_id'])->get_status());
        $run['status'] = 'waiting'; Oyiso_Agent_Store::save($run);
        update_option('oyiso', ['agent_enabled' => true, 'agent_mcp_namespaces' => []], false);
        self::assertSame(400, $this->request($run, $pending['action_id'])->get_status());
        update_option('oyiso', ['agent_enabled' => true, 'agent_mcp_namespaces' => ['fixture-mcp'], 'agent_write_tools' => ['fixture-mcp/write']], false);
        $this->permission = false;
        self::assertSame('failed', $this->request($run, $pending['action_id'])->get_data()['mcp_actions'][0]['status']);
        self::assertSame(0, $this->writes);
        $run = $this->task(); $this->permission = true;
        $pending = $this->call($run, 'fixture-mcp/write');
        $run['status'] = 'waiting'; Oyiso_Agent_Store::save($run);
        wp_set_current_user(0);
        self::assertSame(401, $this->request($run, $pending['action_id'])->get_status());
        wp_set_current_user(OYISO_AGENT_TEST_ADMIN);
        Oyiso_Agent_Store::cancel($run['id']);
        self::assertSame('cancelled', Oyiso_Agent_Store::get($run['id'])['mcp_actions'][0]['status']);
        self::assertSame(403, $this->request($run, $pending['action_id'])->get_status());
        self::assertSame(0, $this->writes);
    }

    public function testErrorsAndInterruptedWritesStayVisibleWithoutAutomaticRetry(): void
    {
        foreach (['throws', 'error'] as $tool) {
            $run = $this->task(); $pending = $this->call($run, 'fixture-mcp/' . $tool);
            $run['status'] = 'waiting'; Oyiso_Agent_Store::save($run);
            $result = $this->request($run, $pending['action_id'])->get_data();
            self::assertSame('failed', $result['mcp_actions'][0]['status']);
            self::assertStringContainsString('Fixture', $result['mcp_actions'][0]['result']);
        }
        $run = $this->task(); $pending = $this->call($run, 'fixture-mcp/write');
        $run['mcp_actions'][0]['status'] = 'executing'; $run['status'] = 'waiting'; Oyiso_Agent_Store::save($run);
        self::assertSame(409, $this->request($run, $pending['action_id'])->get_status());
        self::assertSame(0, $this->writes);
    }

    public function testRunnerPausesAfterMcpWriteProposalAndRetainsACompleteToolHistory(): void
    {
        $run = $this->task();
        $call = new FunctionCall('fixture-call', WP_AI_Client_Ability_Function_Resolver::ability_name_to_function_name('oyiso/mcp-call-tool'), ['name' => 'fixture-mcp/write', 'input_json' => '{}']);
        $turn = new Oyiso_Agent_Turn(new ModelMessage([new MessagePart($call)]), 25, 'fixture-model');
        $gateway = new class($turn) implements Oyiso_Agent_Model_Gateway {
            public function __construct(private Oyiso_Agent_Turn $turn) {}
            public function generate(array $history, array $abilities, ?string $selection = null, ?Closure $onText = null): Oyiso_Agent_Turn|WP_Error { return $this->turn; }
        };
        (new Oyiso_Agent_Runner($gateway))->step($run['id']);
        $saved = Oyiso_Agent_Store::get($run['id']);
        self::assertSame('waiting', $saved['status']);
        self::assertSame('pending', $saved['mcp_actions'][0]['status']);
        self::assertSame(0, $this->writes);
        self::assertCount(2, $saved['messages']);
        self::assertStringContainsString('尚未执行', $saved['conversation'][0]['text']);
    }

    public function testWoodMartActualRegistrationAndSettingsUseTheCurrentSiteWithoutCredentials(): void
    {
        $catalog = Oyiso_Agent_MCP::catalog();
        if (!isset($catalog['woodmart/list-theme-settings'])) self::markTestSkipped('WoodMart MCP is not installed.');
        update_option('oyiso', ['agent_enabled' => true], false);
        self::assertArrayHasKey('woodmart/list-theme-settings', Oyiso_Agent_MCP::selected());
        self::assertTrue(Oyiso_Agent_MCP::readonly($catalog['woodmart/list-theme-settings']));
        self::assertTrue(Oyiso_Agent_MCP::readonly($catalog['woodmart/gutenberg-get-content']));
        self::assertFalse(Oyiso_Agent_MCP::readonly($catalog['woodmart/update-theme-setting']));
        $run = $this->task();
        $result = $this->call($run, 'woodmart/list-theme-settings', '{"search":"primary","per_page":1}');
        self::assertIsArray($result);
        self::assertNotEmpty($result['output']);
        $section = array_values(array_filter(CSF::$args['sections']['oyiso'], static fn(array $item): bool => ($item['id'] ?? '') === 'agent-tools'))[0];
        $field = array_values(array_filter($section['fields'], static fn(array $item): bool => ($item['id'] ?? '') === 'agent_mcp_namespaces'))[0];
        ob_start(); CSF::field($field, ['woodmart'], 'oyiso'); $html = ob_get_clean();
        $dom = new DOMDocument(); $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        self::assertSame(1, (new DOMXPath($dom))->query('//input[@value="woodmart"][@checked]')->length);
        self::assertStringContainsString('woodmart/update-theme-setting', $html);
        self::assertStringNotContainsString('type="password"', $html);
        self::assertStringContainsString('无需另存应用程序密码', $html);
    }

    public function testReadOnlyDefaultHidesAndBlocksEveryUnapprovedMcpWriteIncludingUnknownAnnotations(): void
    {
        update_option('oyiso', ['agent_enabled' => true, 'agent_mcp_namespaces' => ['fixture-mcp'], 'agent_allow_product_trash' => true], false);
        $run = $this->task();
        self::assertSame(['fixture-mcp/large', 'fixture-mcp/read'], array_column(Oyiso_Agent_MCP::call('mcp-list-tools', [], $run)['tools'], 'name'));
        self::assertIsArray($this->call($run, 'fixture-mcp/read'));
        foreach (['write', 'unknown', 'throws', 'error'] as $kind) {
            $name = 'fixture-mcp/' . $kind;
            self::assertSame('agent_write_disabled', $this->call($run, $name)->get_error_code());
            self::assertSame('agent_write_disabled', Oyiso_Agent_MCP::call('mcp-call-tool', ['name' => $name, 'input_json' => '{}'], $run)->get_error_code());
            self::assertInstanceOf(WP_Error::class, Oyiso_Agent_MCP::call('mcp-describe-tool', ['name' => $name], $run));
        }
        self::assertSame(0, $this->writes);
        self::assertSame([], $run['mcp_actions'] ?? []);
    }

    public function testEachMcpWriteNeedsItsOwnGrantAndOldCardsCannotBypassRevocation(): void
    {
        update_option('oyiso', array_replace(Oyiso_Agent_Config::options(), ['agent_write_tools' => ['fixture-mcp/write']]), false);
        $run = $this->task();
        self::assertSame('agent_write_disabled', $this->call($run, 'fixture-mcp/unknown')->get_error_code());
        $pending = $this->call($run, 'fixture-mcp/write');
        $run['status'] = 'waiting'; Oyiso_Agent_Store::save($run);
        update_option('oyiso', array_replace(Oyiso_Agent_Config::options(), ['agent_write_tools' => ['fixture-mcp/unknown']]), false);
        self::assertSame(403, $this->request($run, $pending['action_id'])->get_status());
        self::assertSame('agent_write_disabled', Oyiso_Agent_MCP::approve($run, $pending['action_id'], 'execute')->get_error_code());
        self::assertSame('pending', Oyiso_Agent_Store::get($run['id'])['mcp_actions'][0]['status']);
        self::assertSame(0, $this->writes);
        self::assertSame('dismissed', $this->request($run, $pending['action_id'], 'dismiss')->get_data()['mcp_actions'][0]['status']);
    }

    public function testMcpWriteRechecksSavedGrantAfterAnotherRequestRevokesIt(): void
    {
        global $wpdb;
        $run = $this->task(); $pending = $this->call($run, 'fixture-mcp/write');
        $options = Oyiso_Agent_Config::options();
        try {
            foreach ([['agent_write_tools' => []], ['agent_mcp_namespaces' => []]] as $revoked) {
                $wpdb->update($wpdb->options, ['option_value' => maybe_serialize(array_replace($options, $revoked))], ['option_name' => 'oyiso']);
                self::assertTrue(Oyiso_Agent_Permissions::allows('fixture-mcp/write'), 'The old request still has a cached grant.');
                self::assertSame('agent_write_disabled', $this->call($run, 'fixture-mcp/write')->get_error_code());
                self::assertSame('agent_write_disabled', Oyiso_Agent_MCP::approve($run, $pending['action_id'], 'execute')->get_error_code());
                self::assertSame('pending', $run['mcp_actions'][0]['status']);
                self::assertSame(0, $this->writes);
            }
        } finally {
            $wpdb->update($wpdb->options, ['option_value' => maybe_serialize($options)], ['option_name' => 'oyiso']);
            wp_cache_delete('oyiso', 'options'); wp_cache_delete('alloptions', 'options');
        }
    }

    public function testModelCannotInvokeRawMcpAbilitiesOrUseDispatcherToBypassReadOnly(): void
    {
        update_option('oyiso', array_replace(Oyiso_Agent_Config::options(), ['agent_write_tools' => []]), false);
        $run = $this->task();
        $parts = [];
        foreach ([['fixture-mcp/write', ['value' => 'raw']], ['oyiso/mcp-call-tool', ['name' => 'fixture-mcp/write', 'input_json' => '{}']]] as [$name, $args]) {
            $parts[] = new MessagePart(new FunctionCall(wp_generate_uuid4(), WP_AI_Client_Ability_Function_Resolver::ability_name_to_function_name($name), $args));
        }
        $turn = new Oyiso_Agent_Turn(new ModelMessage($parts), 10, 'fixture-model');
        $gateway = new class($turn) implements Oyiso_Agent_Model_Gateway {
            public function __construct(private Oyiso_Agent_Turn $turn) {}
            public function generate(array $history, array $abilities, ?string $selection = null, ?Closure $onText = null): Oyiso_Agent_Turn|WP_Error { return $this->turn; }
        };
        (new Oyiso_Agent_Runner($gateway))->step($run['id']);
        $saved = Oyiso_Agent_Store::get($run['id']);
        self::assertSame(0, $this->writes);
        self::assertSame([], $saved['mcp_actions'] ?? []);
        self::assertStringContainsString('agent_tool_denied', wp_json_encode($saved['messages']));
        self::assertStringContainsString('agent_write_disabled', wp_json_encode($saved['messages']));
    }

    public function testMcpWritesAreGroupedByPluginAndReadOnlyToolsCannotBeGranted(): void
    {
        update_option('oyiso', array_replace(Oyiso_Agent_Config::options(), ['agent_write_tools' => []]), false);
        ob_start(); Oyiso_Agent_Admin::tools(); Oyiso_Agent_MCP::render(); $html = ob_get_clean();
        $dom = new DOMDocument(); $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);
        self::assertSame(0, $xpath->query('//input[@data-agent-write-toggle][@checked]')->length);
        foreach (Oyiso_Agent_Permissions::writable() as $name => $ability) {
            self::assertSame(1, $xpath->query('//tr//input[@type="hidden"][@name="oyiso[agent_write_tools][]"][@value="' . $name . '"]')->length, $name);
        }
        self::assertSame(1, $xpath->query('//tr[@data-agent-mcp-plugin="fixture-mcp"]//input[@data-agent-write-toggle]')->length);
        self::assertSame(1, $xpath->query('//tr[@data-agent-mcp-plugin="fixture-mcp"]//input[@name="oyiso[agent_mcp_namespaces][]"][@value="fixture-mcp"][@checked]')->length);
        self::assertSame(4, $xpath->query('//tr[@data-agent-mcp-plugin="fixture-mcp"]//input[@data-agent-write-grant][@disabled]')->length);
        self::assertSame(2, $xpath->query('//tr[@data-agent-tool-group]')->length);
        self::assertSame(1, $xpath->query('//tr[@data-agent-tool-group]//input[@data-agent-write-toggle]')->length);
        self::assertSame(0, $xpath->query('//tr[@data-agent-mcp-plugin]//details[@open]')->length);
        self::assertSame(0, $xpath->query('//input[@value="fixture-mcp/read"]')->length);
        self::assertStringNotContainsString('oyiso[agent_allow_product_trash]', $html);
        self::assertSame([], Oyiso_Agent_Permissions::sanitize(['*', 'fixture-mcp/read', 'unknown/write', ['fixture-mcp/write']]));
        self::assertFalse(Oyiso_Agent_Permissions::allows('fixture-mcp/write'));
        self::assertInstanceOf(WP_Error::class, Oyiso_Agent_Permissions::check('fixture-mcp/write'));
    }

    public function testPluginSwitchPreservesCompleteAndPartialLegacyWriteGrants(): void
    {
        $all = ['fixture-mcp/error', 'fixture-mcp/throws', 'fixture-mcp/unknown', 'fixture-mcp/write'];
        foreach ([[], ['fixture-mcp/write'], $all] as $grants) {
            update_option('oyiso', array_replace(Oyiso_Agent_Config::options(), ['agent_write_tools' => $grants]), false);
            ob_start(); Oyiso_Agent_MCP::render(); $html = ob_get_clean();
            $dom = new DOMDocument(); $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
            $xpath = new DOMXPath($dom);
            $row = '//tr[@data-agent-mcp-plugin="fixture-mcp"]';
            self::assertSame($grants === $all ? 1 : 0, $xpath->query($row . '//input[@data-agent-write-toggle][@checked]')->length);
            self::assertSame($grants !== [] && $grants !== $all ? 1 : 0, $xpath->query($row . '//input[@data-agent-write-toggle][@data-partial="true"]')->length);
            $submitted = [];
            foreach ($xpath->query($row . '//input[@data-agent-write-grant][not(@disabled)]') as $input) $submitted[] = $input->getAttribute('value');
            self::assertSame($grants, $submitted);
            self::assertSame($grants, Oyiso_Agent_Permissions::sanitize($submitted));
        }
    }

    public function testPluginTableSelectionsRoundTripThroughNativeSettingsAndCanDisableAllPlugins(): void
    {
        $sections = array_values(array_filter(CSF::$args['sections']['oyiso'], static fn(array $section): bool => ($section['parent'] ?? '') === 'ai-agent'));
        $toolSection = array_values(array_filter($sections, static fn(array $section): bool => $section['id'] === 'agent-tools'))[0];
        $form = (new ReflectionClass(CSF_Options::class))->newInstanceWithoutConstructor();
        $form->unique = 'oyiso'; $form->pre_fields = array_merge(...array_column($sections, 'fields'));
        $previousPost = $_POST;
        try {
            foreach ([['fixture-mcp'], [], ['woodmart']] as $namespaces) {
                update_option('oyiso', ['agent_enabled' => true, 'agent_mcp_namespaces' => $namespaces, 'agent_write_tools' => ['oyiso/apply-product-update', 'fixture-mcp/write']], false);
                $form->options = Oyiso_Agent_Config::options();
                ob_start();
                foreach ($toolSection['fields'] as $field) CSF::field($field, $form->options[$field['id'] ?? ''] ?? '', 'oyiso');
                $html = ob_get_clean();
                $dom = new DOMDocument(); $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
                $xpath = new DOMXPath($dom);
                $fields = ['agent_enabled' => '1'];
                foreach (['agent_mcp_namespaces', 'agent_write_tools'] as $field) {
                    $fields[$field] = [];
                    foreach ($xpath->query('//input[@name="oyiso[' . $field . '][]"][not(@disabled)][@type="hidden" or @checked]') as $input) $fields[$field][] = $input->getAttribute('value');
                }
                $payload = ['csf_options_nonceoyiso' => wp_create_nonce('csf_options_nonce'), 'oyiso' => $fields];
                $_POST = ['data' => wp_slash(wp_json_encode($payload))];
                self::assertTrue($form->set_options(true));
                self::assertSame([], $form->errors);
                self::assertSame($namespaces, Oyiso_Agent_Config::options()['agent_mcp_namespaces']);
                self::assertSame(['oyiso/apply-product-update', 'fixture-mcp/write'], Oyiso_Agent_Permissions::granted());
                self::assertSame(in_array('fixture-mcp', $namespaces, true), isset(Oyiso_Agent_MCP::selected()['fixture-mcp/read']));
                self::assertSame(in_array('fixture-mcp', $namespaces, true), in_array('fixture-mcp/write', Oyiso_Agent_Permissions::granted(true), true));
            }
            self::assertSame(['fixture-mcp'], Oyiso_Agent_MCP::sanitizeNamespaces(['', 'fixture-mcp', 'fixture-mcp', 'unknown', ['fixture-mcp']]));
            self::assertSame([], Oyiso_Agent_MCP::sanitizeNamespaces('fixture-mcp'));
        } finally { $_POST = $previousPost; }
    }

    public function testPluginPermissionAssetsWorkBeforeTheAgentIsEnabled(): void
    {
        update_option('oyiso', ['agent_enabled' => false], false);
        wp_dequeue_script('oyiso-mcp-settings'); wp_deregister_script('oyiso-mcp-settings');
        wp_dequeue_style('oyiso-mcp-settings'); wp_deregister_style('oyiso-mcp-settings');
        try {
            Oyiso_Agent_MCP::assets('index.php');
            self::assertFalse(wp_script_is('oyiso-mcp-settings', 'enqueued'));
            wp_set_current_user(0);
            Oyiso_Agent_MCP::assets('plugins_page_oyiso');
            self::assertFalse(wp_script_is('oyiso-mcp-settings', 'enqueued'));
            wp_set_current_user(OYISO_AGENT_TEST_ADMIN);
            Oyiso_Agent_MCP::assets('plugins_page_oyiso');
            self::assertTrue(wp_script_is('oyiso-mcp-settings', 'enqueued'));
            self::assertTrue(wp_style_is('oyiso-mcp-settings', 'enqueued'));
            self::assertFalse(Oyiso_Agent_Config::enabled());
        } finally {
            wp_dequeue_script('oyiso-mcp-settings'); wp_deregister_script('oyiso-mcp-settings');
            wp_dequeue_style('oyiso-mcp-settings'); wp_deregister_style('oyiso-mcp-settings');
        }
    }
}
