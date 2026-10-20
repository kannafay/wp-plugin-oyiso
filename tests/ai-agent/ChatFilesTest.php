<?php

declare(strict_types=1);

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;

final class ChatFilesTest extends TestCase
{
    private array $original = [];
    private array $tasks = [];
    private array $users = [];
    private ?Closure $http = null;
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a1f8AAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        foreach (['oyiso', 'oyiso_agent_secrets', 'oyiso_agent_connections'] as $name) $this->original[$name] = get_option($name, null);
        update_option('oyiso', ['agent_enabled' => true, 'agent_mcp_namespaces' => []], false);
        update_option('oyiso_agent_connections', [], false);
        wp_set_current_user(OYISO_AGENT_TEST_ADMIN);
    }

    protected function tearDown(): void
    {
        if ($this->http !== null) remove_filter('pre_http_request', $this->http, 10);
        wp_set_current_user(OYISO_AGENT_TEST_ADMIN);
        foreach ($this->tasks as $id) {
            wp_clear_scheduled_hook(Oyiso_Agent_Runner::HOOK, [$id]);
            as_unschedule_all_actions(Oyiso_Agent_Runner::HOOK, [$id], 'oyiso-agent');
            Oyiso_Agent_Store::unlock('run_' . $id); delete_option('oyiso_agent_lock_run_' . $id); wp_delete_post($id, true);
        }
        foreach ($this->users as $id) wp_delete_user($id);
        foreach ($this->original as $name => $value) { if ($value === null) delete_option($name); else update_option($name, $value, false); }
    }

    private function task(): array
    {
        $run = Oyiso_Agent_Store::create('Chat file integration fixture'); self::assertIsArray($run); $this->tasks[] = $run['id']; return $run;
    }

    private function request(string $method, string $path, array $params = []): WP_REST_Response
    {
        $request = new WP_REST_Request($method, '/oyiso-agent/v1/' . $path);
        foreach ($params as $key => $value) $request->set_param($key, $value);
        return rest_do_request($request);
    }

    private function upload(string $name = 'source.md', string $content = 'Private fixture text'): array
    {
        $result = Oyiso_Agent_Attachments::inspect($name, $content); self::assertIsArray($result); return $result;
    }

    private function anotherAdmin(): int
    {
        $id = wp_insert_user(['user_login' => 'chat-fixture-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'administrator']);
        self::assertIsInt($id); $this->users[] = $id; return $id;
    }

    public function testSingleAndBatchDeletionOnlyTrashOwnedRunsAndStopWorkers(): void
    {
        $first = $this->task(); $second = $this->task();
        Oyiso_Agent_Runner::message($first, 'fixture');
        Oyiso_Agent_Runner::enqueue($first['id']);
        $result = $this->request('DELETE', 'runs', ['ids' => [$first['id'], $second['id']]]);
        self::assertSame(200, $result->get_status());
        self::assertSame([$first['id'], $second['id']], $result->get_data()['deleted']);
        foreach ([$first['id'], $second['id']] as $id) {
            self::assertSame('trash', get_post_status($id));
            self::assertTrue(Oyiso_Agent_Store::cancelled($id));
            self::assertFalse(Oyiso_Agent_Store::owned($id));
            self::assertSame(404, $this->request('GET', 'runs/' . $id)->get_status());
            self::assertFalse(wp_next_scheduled(Oyiso_Agent_Runner::HOOK, [$id]));
        }
        $visible = array_column(Oyiso_Agent_Store::recent(1)['items'], 'id');
        self::assertNotContains($first['id'], $visible); self::assertNotContains($second['id'], $visible);
        wp_untrash_post($second['id']); self::assertNotSame('trash', get_post_status($second['id']));
        $third = $this->task(); self::assertSame(200, $this->request('DELETE', 'runs', ['ids' => [$third['id']]])->get_status());
    }

    public function testBatchDeletionChecksTheWholeSelectionBeforeChangingAnyRecord(): void
    {
        $owned = $this->task();
        wp_set_current_user($this->anotherAdmin()); $other = $this->task(); wp_set_current_user(OYISO_AGENT_TEST_ADMIN);
        self::assertSame(404, $this->request('DELETE', 'runs', ['ids' => [$owned['id'], $other['id']]])->get_status());
        self::assertSame('private', get_post_status($owned['id'])); self::assertFalse(Oyiso_Agent_Store::cancelled($owned['id']));
        self::assertSame(400, $this->request('DELETE', 'runs', ['ids' => []])->get_status());
        self::assertSame(400, $this->request('DELETE', 'runs', ['ids' => [$owned['id'], $owned['id']]])->get_status());
        wp_set_current_user(0); self::assertSame(401, $this->request('DELETE', 'runs', ['ids' => [$owned['id']]])->get_status());
    }

    public function testUploadsRejectUntrustedPathsUnsupportedTypesInvalidImagesAndSizeLimits(): void
    {
        self::assertSame('image/png', $this->upload('picture.png', base64_decode(self::PNG))['mime']);
        self::assertSame('application/pdf', $this->upload('source.pdf', "%PDF-1.4\n%%EOF")['mime']);
        self::assertSame('text/plain', $this->upload('source.json', '{"value":"你好"}')['mime']);
        foreach ([['x.exe', 'data'], ['x.svg', '<svg/>'], ['x.png', 'fake image'], ['x.pdf', 'not a PDF'], ['x.txt', "bad\0text"], ['x.txt', "\xff"], ['x.txt', ''], ['x.txt', str_repeat('x', 80001)], ['x.pdf', '%PDF-' . str_repeat('x', 4194304)]] as [$name, $content]) self::assertInstanceOf(WP_Error::class, Oyiso_Agent_Attachments::inspect($name, $content));
        self::assertSame('hello', $this->upload('utf16.txt', "\xff\xfeh\0e\0l\0l\0o\0")['content']);
        $request = new WP_REST_Request('POST', '/oyiso-agent/v1/runs');
        self::assertSame([], Oyiso_Agent_Attachments::uploads($request));
        $request->set_file_params(['files' => ['name' => array_fill(0, 6, 'a.txt')]]);
        self::assertSame('agent_file_count', Oyiso_Agent_Attachments::uploads($request)->get_error_code());
        $request->set_file_params(['files' => ['name' => ['secret.txt'], 'tmp_name' => [ABSPATH . 'wp-config.php'], 'error' => [UPLOAD_ERR_OK]]]);
        self::assertSame('agent_file_upload', Oyiso_Agent_Attachments::uploads($request)->get_error_code());
        $request->set_file_params(['files' => 'invalid']);
        self::assertSame('agent_file_count', Oyiso_Agent_Attachments::uploads($request)->get_error_code());
    }

    public function testFilesArePrivateOwnerScopedAndNeverExposedAsPublicUrlsOrInlineHistoryData(): void
    {
        $run = $this->task();
        $files = Oyiso_Agent_Attachments::save($run['id'], [$this->upload(), $this->upload('picture.png', base64_decode(self::PNG))]);
        self::assertIsArray($files);
        self::assertFalse(get_post_type_object(Oyiso_Agent_Attachments::TYPE)->public);
        self::assertFalse(get_post_type_object(Oyiso_Agent_Attachments::TYPE)->show_in_rest);
        Oyiso_Agent_Runner::message($run, 'Read attached data', $files);
        $view = Oyiso_Agent_Store::view($run);
        self::assertSame($files, $view['conversation'][0]['attachments']);
        self::assertStringNotContainsString(base64_encode('Private fixture text'), wp_json_encode($view));
        self::assertStringNotContainsString('/uploads/', wp_json_encode($files));
        self::assertSame(array_column($files, 'id'), $run['messages'][0]['oyiso_attachments']);
        $parts = Oyiso_Agent_Attachments::parts(array_column($files, 'id'));
        self::assertCount(4, $parts); self::assertSame('Private fixture text', $parts[1]->getText());
        self::assertSame('image/png', $parts[3]->getFile()->getMimeType());
        $result = $this->request('GET', 'files/' . $files[0]['id']);
        self::assertSame(200, $result->get_status()); self::assertSame('no-store, private', $result->get_headers()['Cache-Control']);
        self::assertSame('Private fixture text', base64_decode($result->get_data()['data']));
        wp_set_current_user($this->anotherAdmin()); self::assertSame(404, $this->request('GET', 'files/' . $files[0]['id'])->get_status());
        wp_set_current_user(0); self::assertSame(401, $this->request('GET', 'files/' . $files[0]['id'])->get_status());
        wp_set_current_user(OYISO_AGENT_TEST_ADMIN); Oyiso_Agent_Store::remove([$run['id']]);
        self::assertSame(404, $this->request('GET', 'files/' . $files[0]['id'])->get_status());
        wp_delete_post($run['id'], true); self::assertNull(get_post($files[0]['id'])); self::assertNull(get_post($files[1]['id']));
    }

    public function testConversationFileLimitsAndOwnershipAreEnforced(): void
    {
        $run = $this->task(); $upload = $this->upload();
        $files = Oyiso_Agent_Attachments::save($run['id'], [$upload]);
        update_post_meta($files[0]['id'], '_oyiso_agent_file_size', 16777216);
        self::assertSame('agent_file_limit', Oyiso_Agent_Attachments::save($run['id'], [$upload])->get_error_code());
        update_post_meta($files[0]['id'], '_oyiso_agent_file_size', 20);
        self::assertIsArray(Oyiso_Agent_Attachments::save($run['id'], array_fill(0, 19, $upload)));
        self::assertSame('agent_file_limit', Oyiso_Agent_Attachments::save($run['id'], [$upload])->get_error_code());
        wp_set_current_user($this->anotherAdmin()); self::assertSame('agent_file_forbidden', Oyiso_Agent_Attachments::save($run['id'], [$upload])->get_error_code());
    }

    public function testPrivateAttachmentsReachEveryProtocolAsRealFileContent(): void
    {
        $run = $this->task();
        $files = Oyiso_Agent_Attachments::save($run['id'], [$this->upload('source.md', 'Text reference from attachment'), $this->upload('picture.png', base64_decode(self::PNG)), $this->upload('source.pdf', "%PDF-1.4\n%%EOF")]);
        Oyiso_Agent_Runner::message($run, 'Read attachments', $files);
        foreach (['openai_responses', 'openai_chat', 'anthropic_messages', 'gemini'] as $protocol) {
            $row = Oyiso_Agent_Connections::save(['name' => $protocol, 'preset' => 'custom', 'url' => 'https://api.example.com/v1', 'protocol' => $protocol, 'models' => ['fixture-model'], 'key' => 'fixture-file-key']);
            self::assertIsArray($row);
            $calls = 0;
            $this->http = static function ($pre, $args) use ($protocol, &$calls) {
                $calls++; $body = json_decode($args['body'], true);
                self::assertStringContainsString('Text reference from attachment', $args['body']);
                self::assertStringContainsString(self::PNG, wp_json_encode($body, JSON_UNESCAPED_SLASHES));
                self::assertStringContainsString('application/pdf', wp_json_encode($body, JSON_UNESCAPED_SLASHES));
                if ($protocol === 'openai_responses') {
                    $types = array_merge(...array_map(static fn(array $item): array => is_array($item['content']) ? array_column($item['content'], 'type') : [], $body['input']));
                    self::assertContains('input_image', $types); self::assertContains('input_file', $types);
                    $response = ['id' => 'fixture', 'status' => 'completed', 'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'OK']]]], 'usage' => []];
                } elseif ($protocol === 'openai_chat') {
                    self::assertContains('image_url', array_column($body['messages'][1]['content'], 'type'));
                    self::assertContains('file', array_column($body['messages'][1]['content'], 'type'));
                    $response = ['id' => 'fixture', 'choices' => [['message' => ['role' => 'assistant', 'content' => 'OK'], 'finish_reason' => 'stop']], 'usage' => []];
                } elseif ($protocol === 'anthropic_messages') {
                    self::assertContains('image', array_column($body['messages'][0]['content'], 'type'));
                    self::assertContains('document', array_column($body['messages'][0]['content'], 'type'));
                    $response = ['id' => 'fixture', 'content' => [['type' => 'text', 'text' => 'OK']], 'stop_reason' => 'end_turn', 'usage' => []];
                } else {
                    $media = array_values(array_filter($body['contents'][0]['parts'], static fn(array $part): bool => isset($part['inlineData'])));
                    self::assertSame(['image/png', 'application/pdf'], array_column(array_column($media, 'inlineData'), 'mimeType'));
                    $response = ['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'OK']]], 'finishReason' => 'STOP']], 'usageMetadata' => []];
                }
                return ['headers' => ['content-type' => 'application/json'], 'body' => wp_json_encode($response), 'response' => ['code' => 200, 'message' => 'fixture'], 'cookies' => [], 'filename' => null];
            };
            add_filter('pre_http_request', $this->http, 10, 3);
            $result = (new Oyiso_Agent_Model())->generate($run['messages'], [], $row['id'] . '|fixture-model');
            remove_filter('pre_http_request', $this->http, 10); $this->http = null;
            self::assertInstanceOf(Oyiso_Agent_Turn::class, $result, $result instanceof WP_Error ? $result->get_error_message() : '');
            self::assertSame(1, $calls);
            self::assertSame('OK', $result->message->getParts()[0]->getText());
        }
    }

    public function testMissingAndOversizedTextAttachmentsReturnActionableErrors(): void
    {
        $run = $this->task();
        Oyiso_Agent_Runner::message($run, 'fixture');
        $run['messages'][0]['oyiso_attachments'] = ['invalid'];
        self::assertSame('agent_file_reference', (new Oyiso_Agent_Model())->generate($run['messages'], [])->get_error_code());
        $run['messages'][0]['oyiso_attachments'] = [999999999];
        self::assertSame('agent_file_missing', (new Oyiso_Agent_Model())->generate($run['messages'], [])->get_error_code());
        unset($run['messages'][0]['oyiso_attachments']);
        $files = Oyiso_Agent_Attachments::save($run['id'], array_fill(0, 3, $this->upload('large.txt', str_repeat('a', 70000))));
        Oyiso_Agent_Runner::message($run, 'fixture', $files);
        self::assertSame('agent_context_limit', (new Oyiso_Agent_Model())->generate($run['messages'], [])->get_error_code());
    }
}
