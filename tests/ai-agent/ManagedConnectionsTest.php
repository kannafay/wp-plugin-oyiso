<?php

declare(strict_types=1);

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
    http_response_code(404);
    exit;
}

use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Messages\Enums\MessagePartChannelEnum;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use WordPress\AiClient\Tools\DTO\FunctionResponse;

final class ManagedConnectionsTest extends TestCase
{
    private array $original = [];
    private ?Closure $http = null;

    protected function setUp(): void
    {
        foreach (['oyiso', 'oyiso_agent_secrets', 'oyiso_agent_connections'] as $name) {
            $this->original[$name] = get_option($name, null);
        }
        update_option('oyiso', ['agent_enabled' => true, 'agent_selection' => ''], false);
        delete_option('oyiso_agent_secrets');
        delete_option('oyiso_agent_connections');
        wp_set_current_user(OYISO_AGENT_TEST_ADMIN);
    }

    protected function tearDown(): void
    {
        if ($this->http !== null) remove_filter('pre_http_request', $this->http, 10);
        Oyiso_Agent_Store::unlock('connections');
        foreach ($this->original as $name => $value) {
            if ($value === null) delete_option($name);
            else update_option($name, $value, false);
        }
        wp_set_current_user(OYISO_AGENT_TEST_ADMIN);
        Oyiso_Agent_Connectors::register();
    }

    private function input(array $extra = []): array
    {
        return array_merge(['name' => 'DeepSeek 主账号', 'preset' => 'deepseek', 'url' => 'https://api.example.com/v1', 'protocol' => 'openai_chat', 'models' => ['fixture-model', 'fixture-reasoner'], 'key' => 'fixture-first-key'], $extra);
    }

    private function request(string $method, string $path, mixed $input = null): WP_REST_Response
    {
        $request = new WP_REST_Request($method, '/oyiso-agent/v1/' . $path);
        if ($input !== null) {
            $request->set_header('content-type', 'application/json');
            $request->set_body(wp_json_encode($input));
        }
        return rest_get_server()->dispatch($request);
    }

    private function create(array $extra = []): array
    {
        $result = Oyiso_Agent_Connections::save($this->input($extra));
        self::assertIsArray($result, $result instanceof WP_Error ? $result->get_error_message() : '');
        return $result;
    }

    public function testMigrationPreservesLegacyCredentialsEndpointAndSelectedModelOnce(): void
    {
        $options = Oyiso_Agent_Config::saveOptions(['agent_enabled' => true, 'agent_provider' => 'oyiso-gemini', 'agent_gemini_key' => 'fixture-legacy-key', 'agent_gemini_url' => 'https://legacy.example.com/v1', 'agent_gemini_model' => 'legacy-model']);
        update_option('oyiso', $options, false);
        $rows = Oyiso_Agent_Connections::all();
        self::assertCount(1, $rows);
        self::assertSame('legacy-gemini', $rows[0]['id']);
        self::assertSame('https://legacy.example.com/v1', $rows[0]['url']);
        self::assertSame(['legacy-model'], $rows[0]['models']);
        self::assertSame('openai_chat', $rows[0]['protocol']);
        self::assertSame('fixture-legacy-key', Oyiso_Agent_Config::secret(Oyiso_Agent_Connections::secretName('legacy-gemini')));
        self::assertSame('fixture-legacy-key', Oyiso_Agent_Config::secret('gemini_key'));
        self::assertSame('legacy-gemini|legacy-model', Oyiso_Agent_Connections::selection());
        self::assertSame($rows, Oyiso_Agent_Connections::all());
        self::assertStringNotContainsString('fixture-legacy-key', wp_json_encode($this->request('GET', 'providers')->get_data()));
        update_option('oyiso', ['agent_provider' => 'other-wordpress-provider'], false);
        self::assertSame('wp:other-wordpress-provider', Oyiso_Agent_Connections::selection());
    }

    public function testTwoConnectionsFromSamePresetHaveSeparateKeysModelsAndSafeMarkup(): void
    {
        $first = $this->request('POST', 'providers', $this->input());
        self::assertSame(201, $first->get_status());
        $second = $this->request('POST', 'providers', $this->input(['name' => 'DeepSeek 备用账号', 'key' => 'fixture-second-key', 'models' => ['second-model']]));
        self::assertSame(201, $second->get_status());
        $rows = $second->get_data()['connections'];
        self::assertCount(2, $rows);
        self::assertNotSame($rows[0]['id'], $rows[1]['id']);
        foreach ($rows as $index => $row) {
            self::assertTrue($row['configured']);
            self::assertArrayNotHasKey('key', $row);
            self::assertSame($index ? 'fixture-second-key' : 'fixture-first-key', Oyiso_Agent_Config::secret(Oyiso_Agent_Connections::secretName($row['id'])));
        }
        self::assertStringNotContainsString('fixture-first-key', wp_json_encode(get_option('oyiso_agent_connections')));
        self::assertStringNotContainsString('fixture-first-key', wp_json_encode(get_option('oyiso_agent_secrets')));
        ob_start(); Oyiso_Agent_Model_Settings::selection(); Oyiso_Agent_Model_Settings::render(); $html = ob_get_clean();
        self::assertStringContainsString('DeepSeek 主账号 / fixture-model', $html);
        self::assertStringContainsString('DeepSeek 备用账号 / second-model', $html);
        self::assertStringContainsString('data-provider-dialog', $html);
        self::assertStringNotContainsString('fixture-first-key', $html);
        self::assertStringNotContainsString('fixture-second-key', $html);
        $saved = Oyiso_Agent_Config::saveOptions(['agent_selection' => $rows[1]['id'] . '|second-model']);
        self::assertStringNotContainsString('fixture-second-key', wp_json_encode($saved));
        self::assertNull(Oyiso_Agent_Connections::validateSelection($rows[0]['id'] . '|fixture-model'));
        self::assertNotNull(Oyiso_Agent_Connections::validateSelection($rows[0]['id'] . '|unknown-model'));
        self::assertNotNull(Oyiso_Agent_Connections::validateSelection([]));
        self::assertNotNull(Oyiso_Agent_Connections::validateSelection('wp:not-registered'));
        $exported = Oyiso_Agent_Config::saveOptions(['agent_connection_' . $rows[0]['id'] . '_key' => 'fixture-export-key']);
        self::assertStringNotContainsString('fixture-export-key', wp_json_encode($exported));
    }

    public function testAutomaticLegacySelectionMigratesAndNativeProviderTracksItsConnection(): void
    {
        $options = Oyiso_Agent_Config::saveOptions(['agent_enabled' => true, 'agent_provider' => '', 'agent_deepseek_key' => 'fixture-legacy-key']);
        update_option('oyiso', $options, false);
        self::assertSame('legacy-deepseek|deepseek-flash', Oyiso_Agent_Connections::selection());
        self::assertSame('legacy-deepseek|deepseek-flash', Oyiso_Agent_Config::text('selection'));
        self::assertTrue(Oyiso_Agent_DeepSeek_Provider::availability()->isConfigured());
        $saved = Oyiso_Agent_Connections::save($this->input(['name' => '迁移后改名', 'models' => ['deepseek-flash'], 'key' => 'fixture-rotated-key']), 'legacy-deepseek');
        self::assertIsArray($saved);
        $native = Oyiso_Agent_Connectors::load('deepseek');
        self::assertSame('迁移后改名', $native->name);
        self::assertSame('https://api.example.com/v1', $native->url);
        self::assertTrue($native->managed);
        update_option('oyiso', ['agent_selection' => ''], false);
        self::assertTrue(Oyiso_Agent_Connections::delete('legacy-deepseek'));
        self::assertFalse(Oyiso_Agent_DeepSeek_Provider::availability()->isConfigured());
        self::assertSame('fixture-legacy-key', Oyiso_Agent_Config::secret('deepseek_key'));
    }

    public function testConnectionWithoutModelsCanSaveItsKeyAndBeCompletedLater(): void
    {
        $this->mockHttp(static function () { self::fail('An incomplete connection must not call its API.'); });
        $created = $this->request('POST', 'providers', $this->input(['models' => []]));
        self::assertSame(201, $created->get_status());
        $row = $created->get_data()['connections'][0];
        self::assertSame([], $row['models']); self::assertTrue($row['configured']);
        self::assertArrayNotHasKey('key', $row);
        self::assertSame('fixture-first-key', Oyiso_Agent_Config::secret(Oyiso_Agent_Connections::secretName($row['id'])));
        self::assertStringNotContainsString('fixture-first-key', wp_json_encode($created->get_data()));
        self::assertStringNotContainsString('fixture-first-key', wp_json_encode(get_option('oyiso_agent_secrets')));
        foreach (['', 'fixture-model'] as $model) {
            self::assertInstanceOf(WP_Error::class, Oyiso_Agent_Connections::load($row['id'], $model));
            self::assertNotNull(Oyiso_Agent_Connections::validateSelection($row['id'] . '|' . $model));
        }
        self::assertSame(400, $this->request('POST', 'providers/' . $row['id'] . '/test', ['model' => 'fixture-model'])->get_status());
        ob_start(); Oyiso_Agent_Model_Settings::selection(); $html = ob_get_clean();
        self::assertStringNotContainsString($row['id'] . '|', $html);
        self::assertSame(200, $this->request('PUT', 'providers/' . $row['id'], $this->input(['models' => [], 'key' => '']))->get_status());
        $completed = $this->request('PUT', 'providers/' . $row['id'], $this->input(['models' => ['fixture-model'], 'key' => '']));
        self::assertSame(200, $completed->get_status());
        self::assertCount(1, $completed->get_data()['connections']);
        self::assertSame(['fixture-model'], $completed->get_data()['connections'][0]['models']);
        self::assertNull(Oyiso_Agent_Connections::validateSelection($row['id'] . '|fixture-model'));
        self::assertInstanceOf(Oyiso_Agent_Connection::class, Oyiso_Agent_Connections::load($row['id'], 'fixture-model'));
        self::assertSame('fixture-first-key', Oyiso_Agent_Config::secret(Oyiso_Agent_Connections::secretName($row['id'])));
        $withoutModels = $this->input(); unset($withoutModels['models']);
        self::assertSame(201, $this->request('POST', 'providers', $withoutModels)->get_status());
        self::assertSame([], Oyiso_Agent_Connections::all()[1]['models']);
    }

    public function testLegacyConnectionWithoutModelsStaysUnavailableAndCannotBeAutoSelected(): void
    {
        update_option('oyiso', Oyiso_Agent_Config::saveOptions(['agent_provider' => 'oyiso-deepseek', 'agent_deepseek_key' => 'fixture-legacy-key']), false);
        Oyiso_Agent_Connections::all();
        update_option('oyiso', ['agent_selection' => ''], false);
        self::assertIsArray(Oyiso_Agent_Connections::save($this->input(['models' => [], 'key' => '']), 'legacy-deepseek'));
        update_option('oyiso', ['agent_provider' => 'oyiso-deepseek'], false);
        self::assertSame('', Oyiso_Agent_Connections::selection());
        self::assertInstanceOf(WP_Error::class, Oyiso_Agent_Connectors::load('deepseek', false));
        self::assertFalse(Oyiso_Agent_DeepSeek_Provider::availability()->isConfigured());
        self::assertSame([], (new Oyiso_Agent_Model_Directory('deepseek'))->listModelMetadata());
        self::assertSame('fixture-legacy-key', Oyiso_Agent_Config::secret(Oyiso_Agent_Connections::secretName('legacy-deepseek')));
    }

    public function testEditsPreserveBlankKeyProtectDefaultModelAndDeleteOnlyOneConnection(): void
    {
        $first = $this->create(); $second = $this->create(['name' => '备用', 'key' => 'fixture-second-key']);
        $updated = $this->request('PUT', 'providers/' . $first['id'], $this->input(['id' => $second['id'], 'name' => '改名', 'key' => '']));
        self::assertSame(200, $updated->get_status());
        self::assertSame('改名', $updated->get_data()['connections'][0]['name']);
        self::assertSame('fixture-first-key', Oyiso_Agent_Config::secret(Oyiso_Agent_Connections::secretName($first['id'])));
        self::assertSame('备用', Oyiso_Agent_Connections::all()[1]['name']);
        update_option('oyiso', ['agent_selection' => $first['id'] . '|fixture-model'], false);
        self::assertSame(409, $this->request('DELETE', 'providers/' . $first['id'])->get_status());
        self::assertSame(409, $this->request('PUT', 'providers/' . $first['id'], $this->input(['models' => ['other-model']]))->get_status());
        self::assertSame(409, $this->request('PUT', 'providers/' . $first['id'], $this->input(['models' => []]))->get_status());
        self::assertSame(409, $this->request('PUT', 'providers/' . $first['id'], $this->input(['clear_key' => true]))->get_status());
        update_option('oyiso', ['agent_selection' => ''], false);
        self::assertSame(200, $this->request('PUT', 'providers/' . $first['id'], $this->input(['clear_key' => true]))->get_status());
        self::assertFalse(Oyiso_Agent_Connections::view()[0]['configured']);
        self::assertNotNull(Oyiso_Agent_Connections::validateSelection($first['id'] . '|fixture-model'));
        self::assertSame(200, $this->request('DELETE', 'providers/' . $first['id'])->get_status());
        self::assertCount(1, Oyiso_Agent_Connections::all());
        self::assertSame('fixture-second-key', Oyiso_Agent_Config::secret(Oyiso_Agent_Connections::secretName($second['id'])));
        self::assertSame(404, $this->request('DELETE', 'providers/' . $first['id'])->get_status());
        self::assertSame(404, $this->request('PUT', 'providers/missing', $this->input())->get_status());
        self::assertInstanceOf(WP_Error::class, Oyiso_Agent_Connections::model('missing', 'model'));
    }

    public function testValidationAndLocksPreventInvalidWrites(): void
    {
        foreach ([['name' => ''], ['name' => str_repeat('x', 81)], ['url' => 'http://localhost'], ['url' => ''], ['preset' => 'unknown'], ['protocol' => 'unsupported'], ['key' => "key\ninjection"], ['models' => 'not-an-array'], ['models' => array_fill(0, 31, 'model')], ['models' => [5]], ['models' => ['bad model']], ['models' => ['bad|model']], ['models' => [str_repeat('x', 201)]]] as $invalid) {
            self::assertSame(400, $this->request('POST', 'providers', $this->input($invalid))->get_status());
        }
        self::assertSame(400, $this->request('POST', 'providers', 'invalid')->get_status());
        self::assertSame([], Oyiso_Agent_Connections::all());
        $row = $this->create(['models' => ['model', 'model']]);
        self::assertSame(['model'], $row['models']);
        self::assertTrue(Oyiso_Agent_Store::lock('connections'));
        self::assertSame(409, $this->request('POST', 'providers', $this->input())->get_status());
        self::assertSame(409, $this->request('DELETE', 'providers/' . $row['id'])->get_status());
        Oyiso_Agent_Store::unlock('connections');
        update_option('oyiso_agent_connections', array_fill(0, 30, $row), false);
        self::assertSame(400, $this->request('POST', 'providers', $this->input())->get_status());
        update_option('oyiso_agent_connections', [$row + ['unused' => 'ignored']], false);
        self::assertSame(400, $this->request('POST', 'providers/' . $row['id'] . '/test', ['model' => 'unknown'])->get_status());
    }

    public function testRestPermissionsAndSettingsAssetsWorkWhileAssistantIsDisabled(): void
    {
        wp_set_current_user(0);
        foreach ([['GET', 'providers'], ['POST', 'providers'], ['PUT', 'providers/missing'], ['DELETE', 'providers/missing'], ['POST', 'providers/missing/test']] as [$method, $path]) {
            self::assertSame(401, $this->request($method, $path, $this->input(['model' => 'fixture-model']))->get_status());
        }
        wp_set_current_user(OYISO_AGENT_TEST_ADMIN);
        update_option('oyiso', ['agent_enabled' => false], false);
        foreach (['oyiso-agent', 'oyiso-agent-panel', 'oyiso-model-settings'] as $handle) wp_dequeue_script($handle);
        wp_dequeue_style('oyiso-agent');
        Oyiso_Agent_Admin::assets('plugins_page_oyiso');
        self::assertTrue(wp_script_is('oyiso-model-settings', 'enqueued'));
        self::assertTrue(wp_style_is('oyiso-model-settings', 'enqueued'));
        self::assertFalse(wp_script_is('oyiso-agent', 'enqueued'));
        self::assertFalse(wp_style_is('oyiso-agent', 'enqueued'));
        wp_dequeue_script('oyiso-model-settings'); wp_dequeue_style('oyiso-model-settings');
        self::assertSame(200, $this->request('GET', 'providers')->get_status());
    }

    public function testManagedModelsUseExactConnectionKeyAndEndpointAcrossToolRoundTrips(): void
    {
        $first = $this->create();
        $second = $this->create(['name' => '备用', 'url' => 'https://second.example.com/v1', 'key' => 'fixture-second-key', 'models' => ['second-model']]);
        $seen = [];
        $this->http = static function ($pre, $args, $url) use (&$seen) {
            $body = json_decode($args['body'], true); $seen[] = [$url, $args['headers']['Authorization'], $body['model']];
            $testing = isset($body['tools'][0]['function']['name']) && $body['tools'][0]['function']['name'] === 'oyiso_connection_probe';
            $message = $testing && count($body['messages']) === 1 ? ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'managed-probe', 'type' => 'function', 'function' => ['name' => 'oyiso_connection_probe', 'arguments' => '{"value":"ok"}']]]] : ['role' => 'assistant', 'content' => 'OK'];
            return ['headers' => [], 'body' => wp_json_encode(['id' => 'managed-result', 'choices' => [['message' => $message, 'finish_reason' => isset($message['tool_calls']) ? 'tool_calls' : 'stop']], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 2, 'total_tokens' => 3]]), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
        };
        add_filter('pre_http_request', $this->http, 10, 3);
        self::assertSame(200, $this->request('POST', 'providers/' . $first['id'] . '/test', ['model' => 'fixture-model'])->get_status());
        self::assertSame(200, $this->request('POST', 'providers/' . $second['id'] . '/test', ['model' => 'second-model'])->get_status());
        self::assertCount(4, $seen);
        self::assertSame(['https://api.example.com/v1/chat/completions', 'Bearer fixture-first-key', 'fixture-model'], $seen[0]);
        self::assertSame(['https://second.example.com/v1/chat/completions', 'Bearer fixture-second-key', 'second-model'], $seen[3]);
        update_option('oyiso', ['agent_selection' => $second['id'] . '|second-model', 'agent_provider' => 'oyiso-deepseek'], false);
        $turn = (new Oyiso_Agent_Model())->generate([(new UserMessage([new MessagePart('读取商品')]))->toArray()], ['oyiso/get-product']);
        self::assertInstanceOf(Oyiso_Agent_Turn::class, $turn, $turn instanceof WP_Error ? $turn->get_error_message() : '');
        self::assertSame('second-model', $turn->model);
        self::assertSame('Bearer fixture-second-key', $seen[4][1]);
        update_option('oyiso', ['agent_selection' => 'missing|missing'], false);
        self::assertInstanceOf(WP_Error::class, (new Oyiso_Agent_Model())->generate([], []));
    }

    public function testManagedErrorsRedactCredentialsAndAdvancedPreferencesRemainEffective(): void
    {
        $row = $this->create();
        $this->http = static fn() => new WP_Error('offline', 'fixture-first-key token=private');
        add_filter('pre_http_request', $this->http, 10, 3);
        $result = $this->request('POST', 'providers/' . $row['id'] . '/test', ['model' => 'fixture-model']);
        self::assertSame(400, $result->get_status());
        self::assertStringNotContainsString('fixture-first-key', wp_json_encode($result->get_data()));
        update_option('oyiso', ['agent_advanced' => ['max_steps' => '12', 'model' => 'wordpress-model']], false);
        self::assertSame(12, Oyiso_Agent_Config::maxSteps());
        self::assertSame('wordpress-model', Oyiso_Agent_Config::text('model'));
        update_option('oyiso', ['agent_selection' => 'missing|model'], false);
        ob_start(); Oyiso_Agent_Model_Settings::selection(); $html = ob_get_clean();
        self::assertStringContainsString('原默认模型已移除', $html);
    }

    public function testNativeCsfSavePersistsDefaultAndAdvancedFieldsWithoutTouchingProviderKeys(): void
    {
        $row = $this->create();
        $sections = array_values(array_filter(CSF::$args['sections']['oyiso'], static fn(array $section): bool => ($section['parent'] ?? '') === 'ai-agent'));
        $form = (new ReflectionClass(CSF_Options::class))->newInstanceWithoutConstructor();
        $form->unique = 'oyiso'; $form->options = Oyiso_Agent_Config::options(); $form->pre_fields = array_merge(...array_column($sections, 'fields'));
        $languageCatalogue = static fn(): array => ['cs_CZ' => ['native_name' => 'Čeština']];
        add_filter('pre_site_transient_available_translations', $languageCatalogue);
        $previousPost = $_POST;
        try {
            $payload = ['csf_options_nonceoyiso' => wp_create_nonce('csf_options_nonce'), 'oyiso' => ['agent_enabled' => '1', 'agent_selection' => $row['id'] . '|fixture-model', 'agent_language' => 'cs_CZ', 'agent_instructions' => '品牌写作要求', 'agent_custom_instructions' => '先说明结论。', 'agent_advanced' => ['max_steps' => '11', 'model' => 'native-preference']]];
            $_POST = ['data' => wp_slash(wp_json_encode($payload))];
            self::assertTrue($form->set_options(true));
            self::assertSame([], $form->errors);
            self::assertSame($row['id'] . '|fixture-model', Oyiso_Agent_Config::text('selection'));
            self::assertSame(11, Oyiso_Agent_Config::maxSteps());
            self::assertSame('cs_CZ', Oyiso_Agent_Config::text('language'));
            self::assertSame('品牌写作要求', Oyiso_Agent_Config::text('instructions'));
            self::assertSame('先说明结论。', Oyiso_Agent_Config::text('custom_instructions'));
            self::assertSame('fixture-first-key', Oyiso_Agent_Config::secret(Oyiso_Agent_Connections::secretName($row['id'])));
            $payload['oyiso']['agent_selection'] = 'missing|model';
            $_POST = ['data' => wp_slash(wp_json_encode($payload))];
            self::assertTrue($form->set_options(true));
            self::assertArrayHasKey('agent_selection', $form->errors);
            self::assertSame($row['id'] . '|fixture-model', Oyiso_Agent_Config::text('selection'));
            $payload['oyiso']['agent_selection'] = $row['id'] . '|fixture-model';
            $payload['oyiso']['agent_language'] = 'misspelled-language'; $form->errors = [];
            $_POST = ['data' => wp_slash(wp_json_encode($payload))];
            self::assertTrue($form->set_options(true));
            self::assertArrayHasKey('agent_language', $form->errors);
            self::assertSame('cs_CZ', Oyiso_Agent_Config::text('language'));
            $payload['oyiso']['agent_language'] = ''; $form->errors = [];
            $_POST = ['data' => wp_slash(wp_json_encode($payload))];
            self::assertTrue($form->set_options(true));
            self::assertSame([], $form->errors);
            self::assertSame('', Oyiso_Agent_Personalization::language());
            self::assertStringNotContainsString('默认内容语言：', Oyiso_Agent_Model::instructions());
            $payload['oyiso']['agent_language'] = 'site-default';
            $_POST = ['data' => wp_slash(wp_json_encode($payload))];
            self::assertTrue($form->set_options(true));
            self::assertSame('site-default', Oyiso_Agent_Config::text('language'));
            self::assertSame(Oyiso_Agent_Personalization::siteLanguage(), Oyiso_Agent_Personalization::language());
        } finally {
            $_POST = $previousPost;
            remove_filter('pre_site_transient_available_translations', $languageCatalogue);
        }
    }

    private function mockHttp(Closure $callback): void
    {
        if ($this->http !== null) remove_filter('pre_http_request', $this->http, 10);
        $this->http = $callback;
        add_filter('pre_http_request', $this->http, 10, 3);
    }

    private static function response(array|string $body, int $status = 200): array
    {
        return ['headers' => ['content-type' => 'application/json'], 'body' => is_array($body) ? wp_json_encode($body) : $body, 'response' => ['code' => $status, 'message' => 'fixture'], 'cookies' => [], 'filename' => null];
    }

    private static function nativeResponse(string $protocol, array $parts, string $reason = ''): array
    {
        if ($protocol === 'openai_responses') return self::responsesResponse($parts);
        return $protocol === 'anthropic_messages'
            ? ['id' => 'native-result', 'type' => 'message', 'role' => 'assistant', 'content' => $parts, 'stop_reason' => $reason ?: 'end_turn', 'usage' => ['input_tokens' => 2, 'output_tokens' => 3, 'cache_read_input_tokens' => 5]]
            : ['responseId' => 'native-result', 'candidates' => [['content' => ['role' => 'model', 'parts' => $parts], 'finishReason' => $reason ?: 'STOP']], 'usageMetadata' => ['promptTokenCount' => 2, 'candidatesTokenCount' => 3, 'thoughtsTokenCount' => 5, 'totalTokenCount' => 10]];
    }

    private static function responsesResponse(array $output, string $status = 'completed'): array
    {
        return ['id' => 'resp_fixture', 'status' => $status, 'output' => $output, 'usage' => ['input_tokens' => 2, 'output_tokens' => 8, 'total_tokens' => 10, 'output_tokens_details' => ['reasoning_tokens' => 5]]];
    }

    private static function responsesMessage(string $text): array
    {
        return ['id' => 'msg_fixture', 'type' => 'message', 'role' => 'assistant', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []]]];
    }

    public function testAllProtocolsPersistAndNewPresetDefaultsDoNotChangeLegacyConnections(): void
    {
        self::assertSame(['openai_responses', 'openai_chat', 'anthropic_messages', 'gemini'], array_keys(Oyiso_Agent_Connectors::protocols()));
        foreach (Oyiso_Agent_Connectors::protocols() as $protocol => $defaults) {
            $row = $this->create(['preset' => $protocol === 'openai_responses' ? 'openai' : 'custom', 'protocol' => $protocol, 'url' => $defaults['url'], 'key' => 'fixture-' . $protocol . '-key']);
            $connection = Oyiso_Agent_Connections::load($row['id'], 'fixture-model');
            self::assertSame($protocol, $connection->protocol);
            self::assertSame($protocol, $row['protocol']);
            self::assertSame('fixture-' . $protocol . '-key', Oyiso_Agent_Config::secret(Oyiso_Agent_Connections::secretName($row['id'])));
            self::assertNull(Oyiso_Agent_Connections::validateSelection($row['id'] . '|fixture-model'));
        }
        self::assertSame('openai_responses', Oyiso_Agent_Connectors::connectionPresets()['openai']['protocol']);
        self::assertSame('https://api.openai.com/v1', Oyiso_Agent_Connectors::connectionPresets()['openai']['url']);
        self::assertSame('openai_responses', Oyiso_Agent_Connectors::connectionPresets()['deepseek']['protocol']);
        self::assertSame('gemini', Oyiso_Agent_Connectors::connectionPresets()['gemini']['protocol']);
        self::assertSame('https://generativelanguage.googleapis.com/v1beta', Oyiso_Agent_Connectors::connectionPresets()['gemini']['url']);
        self::assertStringEndsWith('/openai', Oyiso_Agent_Connectors::presets()['gemini']['url']);
        self::assertSame(400, $this->request('POST', 'providers', $this->input(['protocol' => ['gemini']]))->get_status());
        ob_start(); Oyiso_Agent_Model_Settings::render(); $html = ob_get_clean();
        foreach (array_keys(Oyiso_Agent_Connectors::protocols()) as $protocol) self::assertStringContainsString('value="' . $protocol . '"', $html);
        self::assertStringNotContainsString('fixture-anthropic_messages-key', $html);
        self::assertStringNotContainsString('fixture-gemini-key', $html);
    }

    public function testAnthropicCompletesToolRoundTripWithNativeAuthenticationAndThinkingBlocks(): void
    {
        $row = $this->create(['preset' => 'custom', 'protocol' => 'anthropic_messages', 'url' => 'https://claude.example.com/v1']);
        $parts = [
            ['type' => 'thinking', 'thinking' => 'fixture-private-thought', 'signature' => 'fixture-signed-thought'],
            ['type' => 'redacted_thinking', 'data' => 'fixture-redacted-data'],
            ['type' => 'text', 'text' => 'Checking.'],
            ['type' => 'tool_use', 'id' => 'native-call', 'name' => 'oyiso_connection_probe', 'input' => ['value' => 'ok']],
        ];
        $seen = 0;
        $this->mockHttp(static function ($pre, $args, $url) use (&$seen, $parts) {
            self::assertSame('https://claude.example.com/v1/messages', $url);
            self::assertSame('fixture-first-key', $args['headers']['x-api-key']);
            self::assertSame('2023-06-01', $args['headers']['anthropic-version']);
            self::assertArrayNotHasKey('Authorization', $args['headers']);
            self::assertSame(0, $args['redirection']); self::assertTrue($args['reject_unsafe_urls']);
            $body = json_decode($args['body'], true);
            self::assertSame('fixture-model', $body['model']);
            self::assertSame(2048, $body['max_tokens']);
            self::assertSame('oyiso_connection_probe', $body['tools'][0]['name']);
            self::assertSame('object', $body['tools'][0]['input_schema']['type']);
            if (++$seen === 1) {
                self::assertSame('user', $body['messages'][0]['role']);
                return self::response(self::nativeResponse('anthropic_messages', $parts, 'tool_use'));
            }
            self::assertSame('assistant', $body['messages'][1]['role']);
            self::assertSame($parts, $body['messages'][1]['content']);
            self::assertSame('user', $body['messages'][2]['role']);
            self::assertSame(['type' => 'tool_result', 'tool_use_id' => 'native-call', 'content' => '{"value":"ok"}'], $body['messages'][2]['content'][0]);
            return self::response(self::nativeResponse('anthropic_messages', [['type' => 'text', 'text' => 'OK']]));
        });
        $result = $this->request('POST', 'providers/' . $row['id'] . '/test', ['model' => 'fixture-model']);
        self::assertSame(200, $result->get_status(), wp_json_encode($result->get_data()));
        self::assertSame(2, $seen);
    }

    public function testGeminiCompletesNativeRoundTripsWithSignaturesAndOptionalProviderCallIds(): void
    {
        $row = $this->create(['preset' => 'gemini', 'protocol' => 'gemini', 'url' => 'https://gemini.example.com/v1beta']);
        foreach ([null, 'provider-call'] as $id) {
            $call = ['name' => 'oyiso_connection_probe', 'args' => ['value' => 'ok']];
            if ($id !== null) $call['id'] = $id;
            $parts = [['text' => 'fixture-thought', 'thought' => true, 'thoughtSignature' => 'thought-signature'], ['functionCall' => $call, 'thoughtSignature' => 'call-signature'], ['text' => '', 'thoughtSignature' => 'empty-text-signature']];
            $seen = 0;
            $this->mockHttp(static function ($pre, $args, $url) use (&$seen, $parts, $id) {
                self::assertSame('https://gemini.example.com/v1beta/models/fixture-model:generateContent', $url);
                self::assertSame('fixture-first-key', $args['headers']['x-goog-api-key']);
                self::assertArrayNotHasKey('Authorization', $args['headers']);
                self::assertSame(0, $args['redirection']); self::assertTrue($args['reject_unsafe_urls']);
                $body = json_decode($args['body'], true);
                self::assertArrayNotHasKey('model', $body);
                self::assertSame(2048, $body['generationConfig']['maxOutputTokens']);
                self::assertSame('object', $body['tools'][0]['functionDeclarations'][0]['parametersJsonSchema']['type']);
                if (++$seen === 1) return self::response(self::nativeResponse('gemini', $parts));
                self::assertSame('model', $body['contents'][1]['role']);
                self::assertSame($parts, $body['contents'][1]['parts']);
                $reply = $body['contents'][2]['parts'][0]['functionResponse'];
                self::assertSame('oyiso_connection_probe', $reply['name']); self::assertSame(['value' => 'ok'], $reply['response']);
                if ($id === null) self::assertArrayNotHasKey('id', $reply);
                else self::assertSame($id, $reply['id']);
                return self::response(self::nativeResponse('gemini', [['text' => 'OK']]));
            });
            $result = $this->request('POST', 'providers/' . $row['id'] . '/test', ['model' => 'fixture-model']);
            self::assertSame(200, $result->get_status(), wp_json_encode($result->get_data()));
            self::assertSame(2, $seen);
        }
    }

    public function testNativeAgentUsesSystemInstructionsAbilitiesExactConnectionAndTokenUsage(): void
    {
        foreach (['anthropic_messages', 'gemini', 'openai_responses'] as $protocol) {
            $anthropic = $protocol === 'anthropic_messages';
            $responses = $protocol === 'openai_responses';
            $host = $anthropic ? 'claude' : ($responses ? 'openai' : 'gemini');
            $row = $this->create(['preset' => 'custom', 'protocol' => $protocol, 'url' => 'https://' . $host . '.example.com', 'models' => [$anthropic ? 'claude-fixture' : ($responses ? 'gpt-fixture' : 'models/gemini-fixture')], 'key' => 'fixture-' . $protocol . '-key']);
            $model = $row['models'][0];
            update_option('oyiso', ['agent_selection' => $row['id'] . '|' . $model, 'agent_model' => 'other-model'], false);
            $this->mockHttp(static function ($pre, $args, $url) use ($anthropic, $responses, $protocol) {
                self::assertSame($anthropic ? 'https://claude.example.com/v1/messages' : ($responses ? 'https://openai.example.com/v1/responses' : 'https://gemini.example.com/v1beta/models/gemini-fixture:generateContent'), $url);
                self::assertSame(($responses ? 'Bearer ' : '') . 'fixture-' . $protocol . '-key', $args['headers'][$anthropic ? 'x-api-key' : ($responses ? 'Authorization' : 'x-goog-api-key')]);
                $body = json_decode($args['body'], true);
                self::assertStringContainsString('当前 WordPress 商店后台', $anthropic ? $body['system'] : ($responses ? $body['instructions'] : $body['systemInstruction']['parts'][0]['text']));
                self::assertNotEmpty($body['tools']);
                self::assertSame(4096, $anthropic ? $body['max_tokens'] : ($responses ? $body['max_output_tokens'] : $body['generationConfig']['maxOutputTokens']));
                return self::response(self::nativeResponse($protocol, [$anthropic ? ['type' => 'text', 'text' => '已读取。'] : ($responses ? self::responsesMessage('已读取。') : ['text' => '已读取。'])]));
            });
            $turn = (new Oyiso_Agent_Model())->generate([(new UserMessage([new MessagePart('读取商品')]))->toArray()], ['oyiso/get-product']);
            self::assertInstanceOf(Oyiso_Agent_Turn::class, $turn, $turn instanceof WP_Error ? $turn->get_error_message() : '');
            self::assertSame($model, $turn->model); self::assertSame(10, $turn->tokens);
            self::assertSame('已读取。', $turn->message->getParts()[0]->getText());
        }
    }

    public function testParallelNativeCallsGroupToolResultsAndKeepEmptyArgumentsAsObjects(): void
    {
        foreach (['anthropic_messages', 'gemini'] as $protocol) {
            $anthropic = $protocol === 'anthropic_messages';
            $row = $this->create(['preset' => 'custom', 'protocol' => $protocol]);
            $seen = 0;
            $this->mockHttp(static function ($pre, $args) use (&$seen, $anthropic, $protocol) {
                $body = json_decode($args['body'], true);
                $tools = $anthropic ? $body['tools'] : $body['tools'][0]['functionDeclarations'];
                self::assertSame('object', $tools[0][$anthropic ? 'input_schema' : 'parametersJsonSchema']['type']);
                if (++$seen === 1) {
                    $parts = [];
                    foreach (['first', 'second'] as $id) $parts[] = $anthropic ? ['type' => 'tool_use', 'id' => $id, 'name' => 'fixture_tool', 'input' => (object) []] : ['functionCall' => ['id' => $id, 'name' => 'fixture_tool', 'args' => (object) []]];
                    return self::response(self::nativeResponse($protocol, $parts, $anthropic ? 'tool_use' : 'STOP'));
                }
                $history = $body[$anthropic ? 'messages' : 'contents'];
                self::assertCount(3, $history); self::assertSame('user', $history[2]['role']);
                $responses = $history[2][$anthropic ? 'content' : 'parts']; self::assertCount(2, $responses);
                if ($anthropic) {
                    self::assertSame('first', $responses[0]['tool_use_id']); self::assertSame('true', $responses[0]['content']);
                    self::assertSame('second', $responses[1]['tool_use_id']); self::assertSame('[1,2]', $responses[1]['content']);
                    self::assertInstanceOf(stdClass::class, json_decode($args['body'])->messages[1]->content[0]->input);
                } else {
                    self::assertSame(['result' => true], $responses[0]['functionResponse']['response']);
                    self::assertSame(['result' => [1, 2]], $responses[1]['functionResponse']['response']);
                    self::assertSame('first', $responses[0]['functionResponse']['id']); self::assertSame('second', $responses[1]['functionResponse']['id']);
                    self::assertInstanceOf(stdClass::class, json_decode($args['body'])->contents[1]->parts[0]->functionCall->args);
                }
                return self::response(self::nativeResponse($protocol, [$anthropic ? ['type' => 'text', 'text' => 'Done'] : ['text' => 'Done']]));
            });
            $history = [new UserMessage([new MessagePart('Use both tools.')])];
            $generate = static fn(array $messages) => wp_ai_client_prompt($messages)->using_model(Oyiso_Agent_Connections::model($row['id'], 'fixture-model'))->using_max_tokens(99)->using_function_declarations(new FunctionDeclaration('fixture_tool', 'Fixture tool.'))->generate_text_result();
            $first = $generate($history);
            self::assertNotInstanceOf(WP_Error::class, $first, $first instanceof WP_Error ? $first->get_error_message() : '');
            $history[] = $first->getCandidates()[0]->getMessage();
            $history[] = new UserMessage([new MessagePart(new FunctionResponse('first', 'fixture_tool', true))]);
            $history[] = new UserMessage([new MessagePart(new FunctionResponse('second', 'fixture_tool', [1, 2]))]);
            self::assertNotInstanceOf(WP_Error::class, $generate($history));
            self::assertSame(2, $seen);
        }
    }

    public function testNativeFailuresRejectMalformedOrTruncatedCallsAndNeverExposeKeys(): void
    {
        foreach (['anthropic_messages', 'gemini'] as $protocol) {
            $anthropic = $protocol === 'anthropic_messages';
            $row = $this->create(['preset' => 'custom', 'protocol' => $protocol]);
            $call = $anthropic ? ['type' => 'tool_use', 'id' => 'call', 'name' => 'oyiso_connection_probe', 'input' => ['value' => 'ok']] : ['functionCall' => ['id' => 'call', 'name' => 'oyiso_connection_probe', 'args' => ['value' => 'ok']]];
            $badCall = $anthropic ? ['type' => 'tool_use', 'name' => 'tool', 'input' => []] : ['functionCall' => ['id' => 'call', 'args' => []]];
            foreach ([new WP_Error('offline', 'fixture-first-key'), self::response(['error' => ['message' => 'fixture-first-key']], 401), self::response('not-json'), self::response(self::nativeResponse($protocol, [])), self::response(self::nativeResponse($protocol, [$badCall])), self::response(self::nativeResponse($protocol, [$call], $anthropic ? 'max_tokens' : 'MAX_TOKENS')), self::response(self::nativeResponse($protocol, [$anthropic ? ['type' => 'image'] : ['inlineData' => []]]))] as $response) {
                $this->mockHttp(static fn() => $response);
                $result = $this->request('POST', 'providers/' . $row['id'] . '/test', ['model' => 'fixture-model']);
                self::assertSame(400, $result->get_status(), wp_json_encode($result->get_data()));
                self::assertStringNotContainsString('fixture-first-key', wp_json_encode($result->get_data()));
            }
        }
    }

    public function testResponsesCompletesStatelessToolRoundTripWithExactReasoningAndCallIds(): void
    {
        $row = $this->create(['preset' => 'custom', 'protocol' => 'openai_responses', 'url' => 'https://openai.example.com/v1']);
        $reasoning = ['type' => 'reasoning', 'id' => 'rs_fixture', 'summary' => [['type' => 'summary_text', 'text' => 'fixture-private-thought']], 'encrypted_content' => 'opaque-encrypted-reasoning'];
        $call = ['type' => 'function_call', 'id' => 'fc_fixture', 'call_id' => 'call_fixture', 'name' => 'oyiso_connection_probe', 'arguments' => '{ "value": "ok" }', 'status' => 'completed'];
        $seen = 0;
        $this->mockHttp(static function ($pre, $args, $url) use (&$seen, $reasoning, $call) {
            self::assertSame('https://openai.example.com/v1/responses', $url);
            self::assertSame('Bearer fixture-first-key', $args['headers']['Authorization']);
            self::assertArrayNotHasKey('x-api-key', $args['headers']); self::assertArrayNotHasKey('x-goog-api-key', $args['headers']);
            self::assertSame(0, $args['redirection']); self::assertTrue($args['reject_unsafe_urls']);
            $body = json_decode($args['body'], true);
            self::assertSame('fixture-model', $body['model']); self::assertSame(2048, $body['max_output_tokens']);
            self::assertFalse($body['store']); self::assertContains('reasoning.encrypted_content', $body['include']);
            self::assertArrayNotHasKey('messages', $body); self::assertArrayNotHasKey('previous_response_id', $body);
            self::assertSame('function', $body['tools'][0]['type']); self::assertSame('oyiso_connection_probe', $body['tools'][0]['name']);
            self::assertSame('object', $body['tools'][0]['parameters']['type']); self::assertFalse($body['tools'][0]['strict']);
            if (++$seen === 1) return self::response(self::responsesResponse([$reasoning, $call]));
            self::assertCount(4, $body['input']);
            self::assertSame($reasoning, $body['input'][1]); self::assertSame($call, $body['input'][2]);
            self::assertSame(['type' => 'function_call_output', 'call_id' => 'call_fixture', 'output' => '{"value":"ok"}'], $body['input'][3]);
            return self::response(self::responsesResponse([self::responsesMessage('OK')]));
        });
        $result = $this->request('POST', 'providers/' . $row['id'] . '/test', ['model' => 'fixture-model']);
        self::assertSame(200, $result->get_status(), wp_json_encode($result->get_data())); self::assertSame(2, $seen);
    }

    public function testResponsesReplaysParallelOutputItemsUnchangedAcrossSerializedHistory(): void
    {
        $row = $this->create(['preset' => 'custom', 'protocol' => 'openai_responses']);
        $output = [
            ['type' => 'reasoning', 'id' => 'rs_fixture', 'summary' => [], 'encrypted_content' => 'encrypted-fixture'],
            ['type' => 'function_call', 'id' => 'fc_first', 'call_id' => 'first', 'name' => 'fixture_tool', 'arguments' => '{"filter":{}}'],
            self::responsesMessage('Checking.'),
            ['type' => 'function_call', 'id' => 'fc_second', 'call_id' => 'second', 'name' => 'fixture_tool', 'arguments' => '{}'],
        ];
        $seen = 0;
        $this->mockHttp(static function ($pre, $args) use (&$seen, $output) {
            if (++$seen === 1) return self::response(self::responsesResponse($output));
            $body = json_decode($args['body'], true);
            self::assertCount(7, $body['input']); self::assertSame($output, array_slice($body['input'], 1, 4));
            self::assertSame(['type' => 'function_call_output', 'call_id' => 'first', 'output' => 'true'], $body['input'][5]);
            self::assertSame(['type' => 'function_call_output', 'call_id' => 'second', 'output' => '[1,2]'], $body['input'][6]);
            return self::response(self::responsesResponse([self::responsesMessage('Done')]));
        });
        $generate = static fn(array $messages) => wp_ai_client_prompt($messages)->using_model(Oyiso_Agent_Connections::model($row['id'], 'fixture-model'))->using_function_declarations(new FunctionDeclaration('fixture_tool', 'Fixture tool.'))->generate_text_result();
        $history = [new UserMessage([new MessagePart('Use both tools.')])];
        $first = $generate($history);
        self::assertNotInstanceOf(WP_Error::class, $first, $first instanceof WP_Error ? $first->get_error_message() : '');
        $history[] = Message::fromArray(json_decode(wp_json_encode($first->getCandidates()[0]->getMessage()->toArray()), true));
        $history[] = new UserMessage([new MessagePart(new FunctionResponse('first', 'fixture_tool', true))]);
        $history[] = new UserMessage([new MessagePart(new FunctionResponse('second', 'fixture_tool', [1, 2]))]);
        self::assertNotInstanceOf(WP_Error::class, $generate($history)); self::assertSame(2, $seen);
    }

    public function testResponsesCanContinueExistingMessagesWithoutResponsesMetadata(): void
    {
        $row = $this->create(['preset' => 'custom', 'protocol' => 'openai_responses']);
        $history = [new UserMessage([new MessagePart('Read.')]), new ModelMessage([new MessagePart('Legacy answer.'), new MessagePart('Private legacy thought.', MessagePartChannelEnum::thought()), new MessagePart(new FunctionCall('legacy-call', 'fixture_tool', ['id' => 1]))]), new UserMessage([new MessagePart(new FunctionResponse('legacy-call', 'fixture_tool', ['ok' => true]))]), new UserMessage([new MessagePart(''), new MessagePart('Continue.')])];
        $this->mockHttp(static function ($pre, $args) {
            $body = json_decode($args['body'], true);
            self::assertCount(5, $body['input']);
            self::assertSame(['role' => 'assistant', 'content' => 'Legacy answer.'], $body['input'][1]);
            self::assertSame(['type' => 'function_call', 'call_id' => 'legacy-call', 'name' => 'fixture_tool', 'arguments' => '{"id":1}'], $body['input'][2]);
            self::assertSame('function_call_output', $body['input'][3]['type']);
            self::assertSame(['role' => 'user', 'content' => 'Continue.'], $body['input'][4]);
            self::assertStringNotContainsString('Private legacy thought.', $args['body']);
            $message = self::responsesMessage('Unused'); $message['content'] = [['type' => 'refusal', 'refusal' => 'Unable to help.']];
            return self::response(self::responsesResponse([$message]));
        });
        $result = wp_ai_client_prompt($history)->using_model(Oyiso_Agent_Connections::model($row['id'], 'fixture-model'))->generate_text_result();
        self::assertNotInstanceOf(WP_Error::class, $result, $result instanceof WP_Error ? $result->get_error_message() : '');
        self::assertSame('Unable to help.', $result->getCandidates()[0]->getMessage()->getParts()[0]->getText());
    }

    public function testResponsesRejectsErrorsIncompleteAndMalformedCallsWithoutExposingCredentials(): void
    {
        $row = $this->create(['preset' => 'custom', 'protocol' => 'openai_responses']);
        $call = ['type' => 'function_call', 'call_id' => 'call', 'name' => 'oyiso_connection_probe', 'arguments' => '{"value":"ok"}'];
        $partial = $call; $partial['status'] = 'incomplete';
        $badJson = $call; $badJson['arguments'] = '{bad json';
        $missingId = $call; unset($missingId['call_id']);
        foreach ([new WP_Error('offline', 'fixture-first-key'), self::response(['error' => ['message' => 'fixture-first-key']], 401), self::response('not-json'), self::response(self::responsesResponse([], 'failed')), self::response(self::responsesResponse([$call], 'incomplete')), self::response(self::responsesResponse([])), self::response(self::responsesResponse([['type' => 'reasoning', 'summary' => []]])), self::response(self::responsesResponse([$partial])), self::response(self::responsesResponse([$badJson])), self::response(self::responsesResponse([$missingId])), self::response(self::responsesResponse([$call, $call])), self::response(self::responsesResponse([['type' => 'web_search_call']]))] as $response) {
            $this->mockHttp(static fn() => $response);
            $result = $this->request('POST', 'providers/' . $row['id'] . '/test', ['model' => 'fixture-model']);
            self::assertSame(400, $result->get_status(), wp_json_encode($result->get_data()));
            self::assertStringNotContainsString('fixture-first-key', wp_json_encode($result->get_data()));
        }
    }
}
