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
    // Container headers exercise MIME detection; browser playback is checked with full video fixtures.
    private const MP4 = 'AAAAIGZ0eXBtcDQyAAAAAG1wNDJtcDQxaXNvbWF2YzEAABCObW9vdgAAAGxtdmhkAAAAANbFs/HWxbPxAAACWAAAC9kAAQAAAQAAAAAAAAAAAAAAAAEAAAAAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAAA=';
    private const WEBM = 'GkXfowEAAAAAAAAfQoaBAUL3gQFC8oEEQvOBCEKChHdlYm1Ch4ECQoWBAhhTgGcBAAAAAAh0ExFNm3RALU27i1OrhBVJqWZTrIHlTbuMU6uEFlSua1OsggEjTbuNU6uEHFO7a1Osgwhz4+wBAAAAAAAAqQAAAAAAAAAAAAAAAAA=';

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
        foreach ([['x.exe', 'data'], ['x.svg', '<svg/>'], ['x.png', 'fake image'], ['x.pdf', 'not a PDF'], ['x.txt', "bad\0text"], ['x.txt', "\xff"], ['x.txt', ''], ['x.txt', str_repeat('x', Oyiso_Agent_Attachments::MAX_FILE + 1)], ['x.pdf', '%PDF-' . str_repeat('x', 4194304)]] as [$name, $content]) self::assertInstanceOf(WP_Error::class, Oyiso_Agent_Attachments::inspect($name, $content));
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
        update_post_meta($files[0]['id'], '_oyiso_agent_file_size', Oyiso_Agent_Attachments::MAX_CHAT_TOTAL);
        self::assertSame('agent_file_limit', Oyiso_Agent_Attachments::save($run['id'], [$upload])->get_error_code());
        update_post_meta($files[0]['id'], '_oyiso_agent_file_size', 20);
        self::assertIsArray(Oyiso_Agent_Attachments::save($run['id'], array_fill(0, 19, $upload)));
        self::assertSame('agent_file_limit', Oyiso_Agent_Attachments::save($run['id'], [$upload])->get_error_code());
        wp_set_current_user($this->anotherAdmin()); self::assertSame('agent_file_forbidden', Oyiso_Agent_Attachments::save($run['id'], [$upload])->get_error_code());
    }

    public function testVideosArePrivatePreviewableAndNotSubmittedToModelsAsUnsupportedBinary(): void
    {
        $run = $this->task();
        $uploads = [$this->upload('clip.MP4', base64_decode(self::MP4)), $this->upload('clip.webm', base64_decode(self::WEBM))];
        self::assertSame(['video/mp4', 'video/webm'], array_column($uploads, 'mime'));
        $files = Oyiso_Agent_Attachments::save($run['id'], $uploads); self::assertIsArray($files);
        foreach ($files as $index => $file) {
            self::assertSame('', get_post($file['id'])->post_content);
            $result = $this->request('GET', 'files/' . $file['id']);
            self::assertSame(200, $result->get_status()); self::assertSame($uploads[$index]['mime'], $result->get_data()['mime']);
            self::assertSame(base64_encode($uploads[$index]['content']), $result->get_data()['data']);
        }
        $filtered = $this->request('GET', 'files', ['type' => 'video', 'search' => 'clip']);
        self::assertSame(200, $filtered->get_status());
        self::assertEqualsCanonicalizing(array_column($files, 'id'), array_column($filtered->get_data()['items'], 'id'));
        $parts = Oyiso_Agent_Attachments::parts(array_column($files, 'id')); self::assertIsArray($parts);
        foreach ($parts as $part) self::assertNull($part->getFile());
        self::assertStringContainsString('尚未将视频内容提交给模型', $parts[1]->getText());
        wp_set_current_user($this->anotherAdmin());
        self::assertInstanceOf(WP_Error::class, Oyiso_Agent_Attachments::parts([$files[0]['id']]));
        self::assertSame(404, $this->request('GET', 'files/' . $files[0]['id'])->get_status());
    }

    public function testVideoInspectionRejectsSpoofedContainersAndUsesItsOwnSizeLimit(): void
    {
        foreach ([['fake.mp4', 'not a video'], ['fake.webm', base64_decode(self::MP4)], ['fake.mp4', base64_decode(self::WEBM)], ['fake.mp4', base64_decode(self::PNG)], ['fake.webm', "\x1a\x45\xdf\xa3not really webm"]] as [$name, $content]) {
            self::assertSame('agent_file_video', Oyiso_Agent_Attachments::inspect($name, $content)->get_error_code());
        }
        $large = base64_decode(self::MP4) . str_repeat("\0", 5 * 1048576);
        self::assertSame(strlen($large), $this->upload('large.mp4', $large)['size']);
        self::assertSame('agent_file_size', Oyiso_Agent_Attachments::inspect('too-large.mp4', str_repeat('x', Oyiso_Agent_Attachments::MAX_VIDEO_UPLOAD + 1))->get_error_code());
        self::assertSame('agent_file_type', Oyiso_Agent_Attachments::inspect('unavailable.mov', base64_decode(self::MP4))->get_error_code());
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

    public function testLargeCsvFilesUploadIntactButOnlyBoundedPreviewsEnterTheModelContext(): void
    {
        $csv = "ID,SKU,Description\n" . str_repeat("12,SKU-12,中文商品描述🙂\n", 9000) . "13,TAIL-SKU,Last record\n";
        self::assertGreaterThan(80000, strlen($csv));
        $upload = $this->upload('wc-product-export.csv', "\xef\xbb\xbf" . $csv);
        self::assertSame($csv, $upload['content']);
        self::assertSame('text/plain', $upload['mime']);
        self::assertSame(Oyiso_Agent_Attachments::MAX_FILE, $this->upload('limit.csv', str_repeat('a', Oyiso_Agent_Attachments::MAX_FILE))['size']);
        $run = $this->task();
        $files = Oyiso_Agent_Attachments::save($run['id'], [$upload, $upload, $upload]); self::assertIsArray($files);
        Oyiso_Agent_Runner::message($run, 'Find TAIL-SKU', $files);
        $parts = Oyiso_Agent_Attachments::parts(array_column($files, 'id')); self::assertIsArray($parts);
        self::assertSame($csv, base64_decode(Oyiso_Agent_Attachments::read($files[0]['id'])['data']));
        self::assertStringContainsString('附件 ID：' . $files[0]['id'], $parts[0]->getText());
        self::assertStringContainsString('附件尚未完整读取', $parts[1]->getText());
        self::assertStringContainsString('oyiso/read-chat-file', $parts[1]->getText());
        self::assertStringNotContainsString('TAIL-SKU', $parts[1]->getText());
        self::assertTrue(mb_check_encoding($parts[1]->getText(), 'UTF-8'));
        $captured = [];
        $gateway = new Oyiso_Agent_Model(static function (array $messages) use (&$captured): WP_AI_Client_Prompt_Builder {
            $captured = $messages;
            throw new RuntimeException('bounded-preview-fixture');
        });
        $result = $gateway->generate($run['messages'], []);
        self::assertInstanceOf(WP_Error::class, $result); self::assertStringContainsString('bounded-preview-fixture', $result->get_error_message());
        self::assertCount(1, $captured);
        $size = 0;
        foreach ($captured[0]->getParts() as $part) $size += strlen($part->getText() ?? '');
        self::assertLessThan(45000, $size);
    }

    public function testTextFileToolReadsAndSearchesOnlyCurrentConversationFilesWithUtf8SafeOffsets(): void
    {
        $run = $this->task();
        $csv = "ID,SKU\n" . str_repeat("12,中文商品🙂\n", 6000) . "13,TAIL-SKU\n";
        $files = Oyiso_Agent_Attachments::save($run['id'], [$this->upload('catalog.csv', $csv), $this->upload('unicode.txt', 'A你好🙂B')]); self::assertIsArray($files);
        $id = $files[0]['id']; $tools = new Oyiso_Agent_Tools($run);
        self::assertContains('oyiso/read-chat-file', Oyiso_Agent_Abilities::allowed());
        $ability = wp_get_ability('oyiso/read-chat-file'); self::assertNotNull($ability);
        self::assertTrue($ability->get_meta_item('annotations')['readonly']);
        Oyiso_Agent_Abilities::bind($tools);
        try {
            $first = $ability->execute(['file_id' => $id, 'length' => 128]); self::assertIsArray($first);
            self::assertLessThanOrEqual(128, strlen($first['content'])); self::assertTrue($first['truncated']);
            $second = $tools->execute('read-chat-file', ['file_id' => $id, 'offset' => $first['next_offset'], 'length' => 128]); self::assertIsArray($second);
            self::assertSame(substr($csv, 0, $second['next_offset']), $first['content'] . $second['content']);
            self::assertTrue(mb_check_encoding($first['content'] . $second['content'], 'UTF-8'));
            $found = $tools->execute('read-chat-file', ['file_id' => $id, 'search' => 'TAIL-SKU', 'length' => 128]); self::assertIsArray($found);
            self::assertTrue($found['matched']); self::assertStringContainsString('TAIL-SKU', $found['content']); self::assertNull($found['next_offset']);
            $missing = $tools->execute('read-chat-file', ['file_id' => $id, 'search' => 'NOT-IN-FILE']); self::assertIsArray($missing);
            self::assertFalse($missing['matched']); self::assertSame('', $missing['content']);
            $unicode = Oyiso_Agent_Attachments::text($run['id'], $files[1]['id'], 2, 4); self::assertIsArray($unicode);
            self::assertSame(4, $unicode['offset']); self::assertSame('好', $unicode['content']); self::assertSame(7, $unicode['next_offset']);
            $complete = Oyiso_Agent_Attachments::text($run['id'], $files[1]['id']); self::assertIsArray($complete);
            self::assertFalse($complete['truncated']); self::assertNull($complete['next_offset']);
            $end = Oyiso_Agent_Attachments::text($run['id'], $id, strlen($csv)); self::assertIsArray($end);
            self::assertSame('', $end['content']); self::assertNull($end['next_offset']);
            foreach ([[-1, 4, ''], [strlen($csv) + 1, 4, ''], [0, 3, ''], [0, 16001, ''], [0, 128, str_repeat('a', 201)], [0, 128, "\xff"]] as [$offset, $length, $search]) {
                self::assertSame('agent_file_range', Oyiso_Agent_Attachments::text($run['id'], $id, $offset, $length, $search)->get_error_code());
            }
            $otherRun = $this->task();
            self::assertSame('agent_file_missing', Oyiso_Agent_Attachments::text($otherRun['id'], $id)->get_error_code());
            wp_set_current_user($this->anotherAdmin());
            self::assertSame('agent_file_missing', Oyiso_Agent_Attachments::text($run['id'], $id)->get_error_code());
            wp_set_current_user(OYISO_AGENT_TEST_ADMIN);
            $images = Oyiso_Agent_Attachments::save($run['id'], [$this->upload('photo.png', base64_decode(self::PNG))]); self::assertIsArray($images);
            self::assertSame('agent_file_text_type', Oyiso_Agent_Attachments::text($run['id'], $images[0]['id'])->get_error_code());
            self::assertTrue(Oyiso_Agent_Attachments::erase($id));
            self::assertSame('agent_file_deleted', Oyiso_Agent_Attachments::text($run['id'], $id)->get_error_code());
            update_option('oyiso', ['agent_enabled' => false], false);
            self::assertSame('agent_tool_forbidden', $tools->execute('read-chat-file', ['file_id' => $files[1]['id']])->get_error_code());
        } finally { Oyiso_Agent_Abilities::bind(null); }
    }

    public function testMissingAttachmentsAndOversizedConversationsReturnActionableErrors(): void
    {
        $run = $this->task();
        Oyiso_Agent_Runner::message($run, 'fixture');
        $run['messages'][0]['oyiso_attachments'] = ['invalid'];
        self::assertSame('agent_file_reference', (new Oyiso_Agent_Model())->generate($run['messages'], [])->get_error_code());
        $run['messages'][0]['oyiso_attachments'] = [999999999];
        self::assertSame('agent_file_missing', (new Oyiso_Agent_Model())->generate($run['messages'], [])->get_error_code());
        unset($run['messages'][0]['oyiso_attachments']);
        Oyiso_Agent_Runner::message($run, str_repeat('a', 180001));
        self::assertSame('agent_context_limit', (new Oyiso_Agent_Model())->generate($run['messages'], [])->get_error_code());
    }
}
