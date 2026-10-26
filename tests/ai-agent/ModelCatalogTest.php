<?php

declare(strict_types=1);

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;

final class ModelCatalogTest extends TestCase
{
    private array $original = [];
    private ?Closure $http = null;

    protected function setUp(): void
    {
        foreach (['oyiso', 'oyiso_agent_connections', 'oyiso_agent_secrets'] as $name) $this->original[$name] = get_option($name, null);
        update_option('oyiso', ['agent_enabled' => false, 'agent_selection' => ''], false);
        update_option('oyiso_agent_connections', [], false);
        delete_option('oyiso_agent_secrets');
        wp_set_current_user(OYISO_AGENT_TEST_ADMIN);
    }

    protected function tearDown(): void
    {
        if ($this->http !== null) remove_filter('pre_http_request', $this->http, 10);
        foreach ($this->original as $name => $value) {
            if ($value === null) delete_option($name); else update_option($name, $value, false);
        }
        wp_set_current_user(OYISO_AGENT_TEST_ADMIN);
    }

    private function input(array $extra = []): array
    {
        return array_merge(['id' => '', 'url' => 'https://models.example.com/v1', 'protocol' => 'openai_responses', 'key' => 'fixture-catalog-key', 'clear_key' => false], $extra);
    }

    private function request(array|string $input): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/oyiso-agent/v1/providers/models');
        $request->set_header('content-type', 'application/json');
        $request->set_body(is_string($input) ? $input : wp_json_encode($input));
        return rest_get_server()->dispatch($request);
    }

    private function mock(Closure $callback): void
    {
        if ($this->http !== null) remove_filter('pre_http_request', $this->http, 10);
        $this->http = $callback; add_filter('pre_http_request', $callback, 10, 3);
    }

    private static function response(array|string $data, int $status = 200): array
    {
        return ['headers' => [], 'body' => is_string($data) ? $data : wp_json_encode($data), 'response' => ['code' => $status, 'message' => 'Fixture'], 'cookies' => [], 'filename' => null];
    }

    public function testModelContextMetadataIsReadWithoutGuessingFromModelNames(): void
    {
        foreach (['openai_chat', 'openai_responses', 'anthropic_messages', 'gemini'] as $protocol) {
            $gemini = $protocol === 'gemini';
            $this->mock(static fn() => self::response([$gemini ? 'models' : 'data' => [
                [$gemini ? 'name' : 'id' => $gemini ? 'models/known' : 'known', $gemini ? 'inputTokenLimit' : 'context_window' => 524288],
                [$gemini ? 'name' : 'id' => 'unknown'],
                [$gemini ? 'name' : 'id' => 'invalid', $gemini ? 'inputTokenLimit' : 'context_window' => -1],
            ]]));
            $response = $this->request($this->input(['protocol' => $protocol]));
            self::assertSame(200, $response->get_status());
            $models = array_column($response->get_data()['models'], null, 'id');
            self::assertSame(524288, $models['known']['context_window']);
            self::assertArrayNotHasKey('context_window', $models['unknown']); self::assertArrayNotHasKey('context_window', $models['invalid']);
        }
    }

    public function testEachProtocolUsesItsModelsEndpointHeadersAndDraftWithoutSaving(): void
    {
        foreach (Oyiso_Agent_Connectors::protocols() as $protocol => $defaults) {
            $gemini = $protocol === 'gemini'; $anthropic = $protocol === 'anthropic_messages';
            $base = $protocol === 'openai_chat' ? 'https://models.example.com/custom/v2' : $defaults['url'];
            $seen = 0;
            $this->mock(static function ($pre, $args, $url) use ($protocol, $base, $gemini, $anthropic, &$seen) {
                $seen++;
                self::assertSame($base . '/models' . ($gemini ? '?pageSize=1000' : ($anthropic ? '?limit=1000' : '')), $url);
                self::assertSame('GET', $args['method']); self::assertSame(0, $args['redirection']); self::assertTrue($args['reject_unsafe_urls']);
                self::assertLessThanOrEqual(10.0, $args['timeout']); self::assertSame(2097152, $args['limit_response_size']);
                self::assertSame(($gemini || $anthropic ? '' : 'Bearer ') . 'fixture-catalog-key', $args['headers'][$gemini ? 'x-goog-api-key' : ($anthropic ? 'x-api-key' : 'Authorization')]);
                if ($anthropic) self::assertSame('2023-06-01', $args['headers']['anthropic-version']);
                self::assertStringNotContainsString('fixture-catalog-key', $url);
                return self::response($gemini ? ['models' => [['name' => 'models/model-b', 'displayName' => 'Model B', 'supportedGenerationMethods' => ['generateContent']]]] : ['data' => [['id' => 'model-b', 'display_name' => 'Model B']]]);
            });
            $result = $this->request($this->input(['protocol' => $protocol, 'url' => $base]));
            self::assertSame(200, $result->get_status()); self::assertSame(1, $seen);
            self::assertSame(['models' => [['id' => 'model-b', 'name' => 'Model B']], 'truncated' => false], $result->get_data());
            self::assertSame([], Oyiso_Agent_Connections::all()); self::assertSame([], get_option('oyiso_agent_secrets', []));
        }
    }

    public function testSavedKeyIsReusedOnlyForItsConnectionAndUnchangedEndpoint(): void
    {
        $rows = [];
        foreach (['first', 'second'] as $name) $rows[] = Oyiso_Agent_Connections::save(['name' => $name, 'preset' => 'custom', 'url' => 'https://' . $name . '.example.com/v1', 'protocol' => 'openai_chat', 'models' => ['existing-model'], 'key' => 'fixture-' . $name . '-key']);
        $seen = [];
        $this->mock(static function ($pre, $args, $url) use (&$seen) { $seen[] = [$url, $args['headers']['Authorization']]; return self::response(['data' => [['id' => 'new-model']]]); });
        foreach ($rows as $index => $row) {
            self::assertIsArray($row);
            $result = $this->request($this->input(['id' => $row['id'], 'url' => $row['url'] . '/', 'key' => '']));
            self::assertSame(200, $result->get_status()); self::assertSame('Bearer fixture-' . ($index === 0 ? 'first' : 'second') . '-key', $seen[$index][1]);
        }
        self::assertSame(400, $this->request($this->input(['id' => $rows[0]['id'], 'url' => $rows[1]['url'], 'key' => '']))->get_status());
        self::assertSame(400, $this->request($this->input(['id' => $rows[0]['id'], 'url' => $rows[0]['url'], 'key' => '', 'clear_key' => true]))->get_status());
        self::assertCount(2, $seen);
        self::assertSame(200, $this->request($this->input(['id' => $rows[0]['id'], 'url' => $rows[1]['url'], 'key' => 'fixture-new-draft-key']))->get_status());
        self::assertSame('Bearer fixture-new-draft-key', $seen[2][1]);
        self::assertSame('fixture-first-key', Oyiso_Agent_Config::secret(Oyiso_Agent_Connections::secretName($rows[0]['id'])));
        self::assertSame(['existing-model'], Oyiso_Agent_Connections::all()[0]['models']);
    }

    public function testInvalidInputAndPermissionsPreventAllOutboundRequests(): void
    {
        $seen = 0; $this->mock(static function () use (&$seen) { $seen++; return self::response(['data' => []]); });
        foreach ([['url' => ''], ['url' => 'http://models.example.com'], ['url' => 'https://127.0.0.1'], ['url' => 'https://models.example.com/v1?key=hidden'], ['protocol' => 'unsupported'], ['protocol' => []], ['id' => []], ['id' => 'invalid/id'], ['key' => ''], ['key' => "invalid\nkey"], ['clear_key' => 'false'], ['clear_key' => true]] as $invalid) self::assertSame(400, $this->request($this->input($invalid))->get_status());
        self::assertSame(404, $this->request($this->input(['id' => 'missing']))->get_status());
        self::assertSame(400, $this->request('invalid JSON')->get_status());
        wp_set_current_user(0); self::assertSame(401, $this->request($this->input())->get_status());
        self::assertSame(0, $seen);
    }

    public function testCatalogDeduplicatesSortsAndIgnoresInvalidIdsAndCredentialEchoes(): void
    {
        $this->mock(static fn() => self::response(['data' => [['id' => 'model-10'], ['id' => 'model-2', 'display_name' => '<b>Second</b>'], ['id' => 'model-2', 'display_name' => 'Second'], ['id' => 'bad model'], ['id' => 'bad|model'], ['id' => str_repeat('x', 201)], ['id' => 5], 'invalid', ['id' => 'fixture-catalog-key'], ['id' => 'model-3', 'display_name' => 'fixture-catalog-key']]]));
        $result = $this->request($this->input()); self::assertSame(200, $result->get_status());
        self::assertSame([['id' => 'model-2', 'name' => 'Second'], ['id' => 'model-3', 'name' => 'model-3'], ['id' => 'model-10', 'name' => 'model-10']], $result->get_data()['models']);
        self::assertStringNotContainsString('fixture-catalog-key', wp_json_encode($result->get_data()));
    }

    public function testGeminiPaginatesAndFiltersEmbeddingOnlyModels(): void
    {
        $seen = 0;
        $this->mock(static function ($pre, $args, $url) use (&$seen) {
            $seen++; self::assertStringStartsWith('https://gemini.example.com/v1beta/models?pageSize=1000', $url);
            if ($seen === 1) return self::response(['models' => [['name' => 'models/gemini-a', 'displayName' => 'Gemini A', 'supportedGenerationMethods' => ['generateContent']], ['name' => 'models/embedding', 'supportedGenerationMethods' => ['embedContent']]], 'nextPageToken' => 'next/page+2']);
            self::assertStringEndsWith('&pageToken=next%2Fpage%2B2', $url);
            return self::response(['models' => [['name' => 'models/gemini-b', 'supportedGenerationMethods' => ['generateContent']]]]);
        });
        $result = $this->request($this->input(['url' => 'https://gemini.example.com', 'protocol' => 'gemini']));
        self::assertSame(200, $result->get_status()); self::assertSame(2, $seen);
        self::assertSame(['gemini-a', 'gemini-b'], array_column($result->get_data()['models'], 'id')); self::assertFalse($result->get_data()['truncated']);
    }

    public function testAnthropicPaginatesByLastIdAndStopsRepeatedCursors(): void
    {
        $seen = 0;
        $this->mock(static function ($pre, $args, $url) use (&$seen) {
            $seen++; self::assertStringStartsWith('https://claude.example.com/v1/models?limit=1000', $url);
            if ($seen > 1) self::assertStringEndsWith('&after_id=claude-a', $url);
            return self::response(['data' => [['id' => 'claude-a', 'display_name' => 'Claude A']], 'has_more' => true, 'last_id' => 'claude-a']);
        });
        $result = $this->request($this->input(['url' => 'https://claude.example.com', 'protocol' => 'anthropic_messages']));
        self::assertSame(200, $result->get_status()); self::assertSame(2, $seen); self::assertTrue($result->get_data()['truncated']);
    }

    public function testPageAndCatalogLimitsReturnAnExplicitPartialList(): void
    {
        $seen = 0;
        $this->mock(static function () use (&$seen) { $seen++; return self::response(['data' => [['id' => 'model-' . $seen]], 'has_more' => true, 'last_id' => 'model-' . $seen]); });
        $result = $this->request($this->input(['protocol' => 'anthropic_messages']));
        self::assertSame(5, $seen); self::assertTrue($result->get_data()['truncated']);
        $this->mock(static fn() => self::response(['data' => array_map(static fn(int $id): array => ['id' => 'model-' . $id], range(0, 5000))]));
        $result = $this->request($this->input()); self::assertCount(5000, $result->get_data()['models']); self::assertTrue($result->get_data()['truncated']);
    }

    public function testErrorsNeverExposeUpstreamBodiesOrDraftCredentials(): void
    {
        foreach ([401, 403, 404, 405, 429, 500, 501] as $status) {
            $this->mock(static fn() => self::response(['error' => ['message' => 'fixture-catalog-key token=private']], $status));
            $result = $this->request($this->input()); self::assertSame(502, $result->get_status());
            self::assertStringNotContainsString('fixture-catalog-key', wp_json_encode($result->get_data())); self::assertStringNotContainsString('token=private', wp_json_encode($result->get_data()));
        }
        $this->mock(static fn() => new WP_Error('offline', 'fixture-catalog-key token=private'));
        self::assertStringNotContainsString('fixture-catalog-key', wp_json_encode($this->request($this->input())->get_data()));
        foreach (['<html>fixture-catalog-key</html>', ['unexpected' => []], ['data' => 'invalid']] as $invalid) {
            $this->mock(static fn() => self::response($invalid)); self::assertSame(502, $this->request($this->input())->get_status());
        }
        $this->mock(static fn() => self::response(['data' => []])); self::assertSame([], $this->request($this->input())->get_data()['models']);
        $this->mock(static fn() => self::response([])); self::assertSame([], $this->request($this->input(['protocol' => 'gemini']))->get_data()['models']);
    }
}
