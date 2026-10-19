<?php

declare(strict_types=1);

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
    http_response_code(404);
    exit;
}

use PHPUnit\Framework\TestCase;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;

final class ConnectorTest extends TestCase
{
    private array $options;
    private mixed $secrets;
    private mixed $connections;
    private ?Closure $http = null;

    protected function setUp(): void
    {
        wp_set_current_user(OYISO_AGENT_TEST_ADMIN);
        $this->options = Oyiso_Agent_Config::options();
        $this->secrets = get_option('oyiso_agent_secrets', false);
        $this->connections = get_option('oyiso_agent_connections', false);
        delete_option('oyiso_agent_connections');
        delete_option('oyiso_agent_secrets');
        $options = $this->options;
        unset($options['agent_selection'], $options['agent_advanced']);
        foreach (Oyiso_Agent_Connectors::presets() as $id => $preset) {
            unset($options['agent_' . $id . '_url'], $options['agent_' . $id . '_model']);
        }
        update_option('oyiso', array_merge($options, ['agent_provider' => '', 'agent_model' => '']), false);
        Oyiso_Agent_Connectors::register();
    }

    protected function tearDown(): void
    {
        if ($this->http !== null) remove_filter('pre_http_request', $this->http, 10);
        update_option('oyiso', $this->options, false);
        if ($this->secrets === false) delete_option('oyiso_agent_secrets');
        else update_option('oyiso_agent_secrets', $this->secrets, false);
        if ($this->connections === false) delete_option('oyiso_agent_connections');
        else update_option('oyiso_agent_connections', $this->connections, false);
        Oyiso_Agent_Connectors::register();
        wp_set_current_user(OYISO_AGENT_TEST_ADMIN);
    }

    private function configure(string $id, array $extra = []): void
    {
        $options = Oyiso_Agent_Config::saveOptions(array_merge(Oyiso_Agent_Config::options(), ['agent_' . $id . '_key' => 'fixture-' . $id . '-key'], $extra));
        update_option('oyiso', $options, false);
        Oyiso_Agent_Connectors::register();
    }

    private function http(Closure $callback): void
    {
        if ($this->http !== null) remove_filter('pre_http_request', $this->http, 10);
        $this->http = $callback;
        add_filter('pre_http_request', $callback, 10, 3);
    }

    private static function response(array|string $body, int $status = 200): array
    {
        return ['headers' => ['content-type' => 'application/json'], 'body' => is_array($body) ? wp_json_encode($body) : $body, 'response' => ['code' => $status, 'message' => 'fixture'], 'cookies' => [], 'filename' => null];
    }

    private static function completion(array $message, string $finish = 'stop'): array
    {
        return ['id' => 'fixture-response', 'choices' => [['message' => array_merge(['role' => 'assistant'], $message), 'finish_reason' => $finish]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30]];
    }

    private function request(string $method, string $path): WP_REST_Response
    {
        return rest_get_server()->dispatch(new WP_REST_Request($method, '/oyiso-agent/v1/' . $path));
    }

    public function testPresetsRegisterWithoutNetworkAndShareNativeClient(): void
    {
        self::assertSame(['deepseek', 'gemini', 'agnes'], array_keys(Oyiso_Agent_Connectors::presets()));
        $this->http(static function () { self::fail('Rendering or registering a preset must not call its API.'); });
        $providers = Oyiso_Agent_Config::providers();
        foreach (Oyiso_Agent_Connectors::presets() as $id => $preset) {
            self::assertArrayHasKey('oyiso-' . $id, $providers);
            self::assertTrue(AiClient::defaultRegistry()->hasProvider('oyiso-' . $id));
            self::assertFalse(AiClient::defaultRegistry()->isProviderConfigured('oyiso-' . $id));
            $connector = wp_get_connector('oyiso-' . $id);
            self::assertSame('none', $connector['authentication']['method']);
            self::assertArrayNotHasKey('setting_name', $connector['authentication']);
            $connection = Oyiso_Agent_Connectors::load($id, false);
            self::assertSame($preset['url'], $connection->url);
            self::assertSame($preset['model'], $connection->model);
        }
        self::assertSame('', Oyiso_Agent_Connectors::preset('google'));
        self::assertSame('gemini', Oyiso_Agent_Connectors::preset('oyiso-gemini'));
        self::assertSame('', Oyiso_Agent_Connectors::preset('oyiso-chatgpt-oauth'));
    }

    public function testPresetKeysAreIndependentEncryptedAndNeverRendered(): void
    {
        foreach (array_keys(Oyiso_Agent_Connectors::presets()) as $id) $this->configure($id, ['agent_enabled' => true]);
        $stored = wp_json_encode(get_option('oyiso_agent_secrets'));
        foreach (array_keys(Oyiso_Agent_Connectors::presets()) as $id) {
            self::assertSame('fixture-' . $id . '-key', Oyiso_Agent_Config::secret($id . '_key'));
            self::assertStringNotContainsString('fixture-' . $id . '-key', $stored);
            self::assertSame('', Oyiso_Agent_Config::options()['agent_' . $id . '_key']);
        }
        $saved = Oyiso_Agent_Config::saveOptions(array_merge(Oyiso_Agent_Config::options(), ['agent_deepseek_key_clear' => true, 'agent_gemini_key' => '']));
        update_option('oyiso', $saved, false);
        self::assertSame('', Oyiso_Agent_Config::secret('deepseek_key'));
        self::assertSame('fixture-gemini-key', Oyiso_Agent_Config::secret('gemini_key'));
        self::assertTrue(Oyiso_Agent_Config::secretStatus()['agnes_key']);
        self::assertFalse(Oyiso_Agent_Config::secretStatus()['deepseek_key']);
        $modelSection = array_values(array_filter(CSF::$args['sections']['oyiso'], static fn(array $s): bool => ($s['id'] ?? '') === 'agent-model'))[0];
        ob_start();
        foreach ($modelSection['fields'] as $field) CSF::field($field, $saved[$field['id'] ?? ''] ?? '', 'oyiso');
        $html = ob_get_clean();
        self::assertStringContainsString('data-agent-settings', $html);
        self::assertStringContainsString('data-provider-field="key"', $html);
        self::assertStringNotContainsString('fixture-gemini-key', $html);
        self::assertStringNotContainsString('fixture-agnes-key', $html);
        self::assertStringNotContainsString('oauth', $html);
        $status = $this->request('GET', 'status')->get_data();
        self::assertTrue($status['secrets']['gemini_key']);
        self::assertStringNotContainsString('fixture-gemini-key', wp_json_encode($status));
    }

    public function testInvalidEndpointsAndKeysAreRejectedBeforeSending(): void
    {
        foreach (['http://api.example.com', 'https://localhost', 'https://127.0.0.1/v1', 'https://10.0.0.1', 'https://[::1]', 'https://host.local', 'https://example.com:8080', 'https://user:password@example.com', 'https://example.com/?key=secret', 'https://example.com/#fragment', "https://example.com/\n", 'https://example.com\\@localhost', 123] as $url) {
            self::assertNotNull(Oyiso_Agent_Connectors::validateUrl($url), var_export($url, true));
        }
        self::assertNull(Oyiso_Agent_Connectors::validateUrl(''));
        self::assertNull(Oyiso_Agent_Connectors::validateUrl('https://api.example.com/v1/'));
        self::assertNotNull(Oyiso_Agent_Connectors::validateKey("key\ninjected"));
        self::assertNotNull(Oyiso_Agent_Connectors::validateKey(str_repeat('x', 4097)));
        self::assertNull(Oyiso_Agent_Connectors::validateKey(''));
        $this->configure('deepseek', ['agent_deepseek_url' => 'https://127.0.0.1/v1']);
        $this->http(static function () { self::fail('Invalid URL must not send credentials.'); });
        self::assertInstanceOf(WP_Error::class, Oyiso_Agent_Connectors::test('deepseek'));
        self::assertInstanceOf(WP_Error::class, Oyiso_Agent_Connectors::load('unknown'));
        self::assertFalse(AiClient::defaultRegistry()->isProviderConfigured('oyiso-deepseek'));
    }

    public function testEachPresetCompletesToolRoundTripIncludingReasoningAndSignatures(): void
    {
        foreach (Oyiso_Agent_Connectors::presets() as $id => $preset) {
            $this->configure($id);
            $requests = [];
            $this->http(static function ($pre, $args, $url) use ($id, $preset, &$requests) {
                self::assertSame($preset['url'] . '/chat/completions', $url);
                self::assertSame('Bearer fixture-' . $id . '-key', $args['headers']['Authorization']);
                self::assertSame(0, $args['redirection']);
                self::assertTrue($args['reject_unsafe_urls']);
                $body = json_decode($args['body'], true);
                $requests[] = $body;
                self::assertSame($preset['model'], $body['model']);
                self::assertIsString($body['messages'][0]['content']);
                $reasoning = $id === 'deepseek' ? '' : 'fixture-private-reasoning';
                if (count($requests) === 1) {
                    return self::response(self::completion(['content' => null, 'reasoning_content' => $reasoning, 'tool_calls' => [['id' => 'fixture-call', 'type' => 'function', 'function' => ['name' => 'oyiso_connection_probe', 'arguments' => '{"value":"ok"}'], 'extra_content' => ['google' => ['thought_signature' => 'fixture-signature']]]]], 'tool_calls'));
                }
                self::assertSame($reasoning, $body['messages'][1]['reasoning_content']);
                self::assertSame('fixture-signature', $body['messages'][1]['tool_calls'][0]['extra_content']['google']['thought_signature']);
                self::assertSame('fixture-call', $body['messages'][2]['tool_call_id']);
                self::assertSame(['value' => 'ok'], json_decode($body['messages'][2]['content'], true));
                return self::response(self::completion(['content' => 'OK']));
            });
            $result = Oyiso_Agent_Connectors::test($id);
            self::assertIsArray($result, $result instanceof WP_Error ? $result->get_error_message() : '');
            self::assertSame($preset['model'], $result['model']);
            self::assertCount(2, $requests);
        }
    }

    public function testAgentUsesExactPresetModelAndCustomEndpoint(): void
    {
        $this->configure('agnes', ['agent_provider' => 'oyiso-agnes', 'agent_model' => 'other-global-preference', 'agent_agnes_model' => 'fixture-custom-model', 'agent_agnes_url' => 'https://api.example.com/v1/']);
        $this->http(static function ($pre, $args, $url) {
            self::assertSame('https://api.example.com/v1/chat/completions', $url);
            $body = json_decode($args['body'], true);
            self::assertSame('fixture-custom-model', $body['model']);
            self::assertStringContainsString('当前 WordPress 商店后台', $body['messages'][0]['content']);
            self::assertArrayHasKey('tools', $body);
            return self::response(self::completion(['content' => '已读取。']));
        });
        $turn = (new Oyiso_Agent_Model())->generate([(new UserMessage([new MessagePart('Read a product')]))->toArray()], ['oyiso/get-product']);
        self::assertInstanceOf(Oyiso_Agent_Turn::class, $turn, $turn instanceof WP_Error ? $turn->get_error_message() : '');
        self::assertSame('fixture-custom-model', $turn->model);
        self::assertSame(30, $turn->tokens);
        self::assertSame('已读取。', $turn->message->getParts()[0]->getText());
        $directory = Oyiso_Agent_Agnes_Provider::modelMetadataDirectory();
        self::assertTrue($directory->hasModelMetadata('fixture-custom-model'));
        self::assertFalse($directory->hasModelMetadata('unknown'));
        self::assertCount(1, array_filter($directory->getModelMetadata('fixture-custom-model')->getSupportedOptions(), static fn($option): bool => $option->getName()->isFunctionDeclarations()));
        $this->expectException(InvalidArgumentException::class);
        $directory->getModelMetadata('unknown');
    }

    public function testConnectionTestsRequireAdminAndSavedKey(): void
    {
        wp_set_current_user(0);
        self::assertSame(401, $this->request('POST', 'connections/deepseek/test')->get_status());
        wp_set_current_user(OYISO_AGENT_TEST_ADMIN);
        self::assertSame(400, $this->request('POST', 'connections/deepseek/test')->get_status());
        self::assertSame(404, $this->request('GET', 'connections/deepseek/test')->get_status());
        self::assertSame(404, $this->request('POST', 'connections/chatgpt/test')->get_status());
    }

    public function testConnectionFailuresNeverExposeKeysOrClaimToolSupport(): void
    {
        $this->configure('gemini');
        foreach ([self::response(['error' => ['message' => 'Invalid fixture-gemini-key']], 401), self::response('not-json'), new WP_Error('offline', 'fixture-gemini-key'), self::response(self::completion(['content' => 'Plain chat only']))] as $response) {
            $this->http(static fn() => $response);
            $result = $this->request('POST', 'connections/gemini/test');
            self::assertSame(400, $result->get_status());
            self::assertStringNotContainsString('fixture-gemini-key', wp_json_encode($result->get_data()));
        }
        $this->http(static fn() => self::response(self::completion(['tool_calls' => [['id' => 'bad-call', 'type' => 'function', 'function' => ['name' => 'unexpected_function', 'arguments' => '{}']]]], 'tool_calls')));
        self::assertInstanceOf(WP_Error::class, Oyiso_Agent_Connectors::test('gemini'));
    }
}
