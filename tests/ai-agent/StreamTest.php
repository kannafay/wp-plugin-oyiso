<?php

declare(strict_types=1);

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;

final class StreamTest extends TestCase
{
    private array $original = [];
    private ?Closure $http = null;

    protected function setUp(): void
    {
        foreach (['oyiso', 'oyiso_agent_secrets', 'oyiso_agent_connections'] as $name) $this->original[$name] = get_option($name, null);
        update_option('oyiso', ['agent_enabled' => true], false); update_option('oyiso_agent_connections', [], false); delete_option('oyiso_agent_secrets');
        wp_set_current_user(OYISO_AGENT_TEST_ADMIN);
    }

    protected function tearDown(): void
    {
        if ($this->http !== null) remove_filter('pre_http_request', $this->http, 10);
        foreach ($this->original as $name => $value) { if ($value === null) delete_option($name); else update_option($name, $value, false); }
    }

    private static function wire(array $events): string
    {
        return ": heartbeat\r\n\r\n" . implode('', array_map(static fn($event): string => 'data: ' . (is_array($event) ? wp_json_encode($event, JSON_UNESCAPED_UNICODE) : $event) . "\r\n\r\n", $events));
    }

    private static function events(string $protocol): array
    {
        $call = ['id' => 'call-1', 'type' => 'function', 'function' => ['name' => 'oyiso_get_product', 'arguments' => '{"product_id":123}']];
        $choice = static fn(array $delta, ?string $finish = null): array => ['choices' => [['delta' => $delta, 'finish_reason' => $finish]]];
        return match ($protocol) {
            'openai_chat' => [
                ['id' => 'stream-1'] + $choice(['role' => 'assistant', 'reasoning_content' => 'private-thought']),
                $choice(['content' => '你']), $choice(['content' => '好']),
                $choice(['tool_calls' => [['index' => 0, 'id' => 'call-1', 'type' => 'function', 'function' => ['name' => 'oyiso_get_product', 'arguments' => '{"product_']]]]),
                $choice(['tool_calls' => [['index' => 0, 'function' => ['arguments' => 'id":123}'], 'extra_content' => ['google' => ['thought_signature' => 'signature']]]]], 'tool_calls'),
                ['choices' => [], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 2, 'total_tokens' => 3]], '[DONE]',
            ],
            'openai_responses' => [
                ['type' => 'response.reasoning_text.delta', 'delta' => 'private-thought'],
                ['type' => 'response.output_text.delta', 'delta' => '你'], ['type' => 'response.output_text.delta', 'delta' => '好'],
                ['type' => 'response.function_call_arguments.delta', 'delta' => '{"product_'],
                ['type' => 'response.output_item.done', 'output_index' => 0, 'item' => ['type' => 'reasoning', 'id' => 'reason-1', 'encrypted_content' => 'encrypted', 'summary' => []]],
                ['type' => 'response.output_item.done', 'output_index' => 1, 'item' => ['type' => 'message', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => '你好']]]],
                ['type' => 'response.output_item.done', 'output_index' => 2, 'item' => ['type' => 'function_call', 'status' => 'completed', 'call_id' => $call['id'], 'name' => $call['function']['name'], 'arguments' => $call['function']['arguments']]],
                ['type' => 'response.completed', 'response' => ['id' => 'stream-1', 'status' => 'completed', 'output' => [], 'usage' => ['input_tokens' => 1, 'output_tokens' => 2, 'total_tokens' => 3]]],
            ],
            'anthropic_messages' => [
                ['type' => 'message_start', 'message' => ['id' => 'stream-1', 'usage' => ['input_tokens' => 1]]],
                ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '']],
                ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'private-thought']],
                ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'signature_delta', 'signature' => 'signature']],
                ['type' => 'content_block_stop', 'index' => 0],
                ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'text', 'text' => '']],
                ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'text_delta', 'text' => '你']],
                ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'text_delta', 'text' => '好']],
                ['type' => 'content_block_stop', 'index' => 1],
                ['type' => 'content_block_start', 'index' => 2, 'content_block' => ['type' => 'tool_use', 'id' => 'call-1', 'name' => 'oyiso_get_product', 'input' => new stdClass()]],
                ['type' => 'content_block_delta', 'index' => 2, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"product_']],
                ['type' => 'content_block_delta', 'index' => 2, 'delta' => ['type' => 'input_json_delta', 'partial_json' => 'id":123}']],
                ['type' => 'content_block_stop', 'index' => 2],
                ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 2]], ['type' => 'message_stop'],
            ],
            'gemini' => [
                ['responseId' => 'stream-1', 'candidates' => [['content' => ['parts' => [['text' => 'private-thought', 'thought' => true, 'thoughtSignature' => 'signature']]]]]],
                ['candidates' => [['content' => ['parts' => [['text' => '你']]]]]], ['candidates' => [['content' => ['parts' => [['text' => '好']]]]]],
                ['candidates' => [['content' => ['parts' => [['functionCall' => ['id' => 'call-1', 'name' => 'oyiso_get_product', 'args' => ['product_id' => 123]], 'thoughtSignature' => 'signature']]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 2, 'totalTokenCount' => 3]],
            ],
        };
    }

    public function testEveryProtocolHandlesByteFragmentsWithoutExposingThinkingAndKeepsToolsAndUsage(): void
    {
        foreach (['openai_chat', 'openai_responses', 'anthropic_messages', 'gemini'] as $protocol) {
            $text = ''; $updates = [];
            $decoder = new Oyiso_Agent_Stream_Decoder($protocol, static function (string $delta) use (&$text, &$updates): void { $text .= $delta; $updates[] = $text; });
            foreach (str_split(self::wire(self::events($protocol))) as $byte) $decoder->feed($byte);
            $result = $decoder->finish();
            self::assertSame('你好', $text, $protocol); self::assertContains('你', $updates);
            self::assertStringNotContainsString('private-thought', $text);
            self::assertStringContainsString('product_id', wp_json_encode($result));
            self::assertStringContainsString('123', wp_json_encode($result));
            self::assertStringContainsString($protocol === 'openai_responses' ? 'encrypted' : 'signature', wp_json_encode($result));
        }
    }

    public function testSelectedModelsUseStreamingEndpointsAndExistingAdaptersReceiveCompleteTools(): void
    {
        foreach (['openai_chat', 'openai_responses', 'anthropic_messages', 'gemini'] as $protocol) {
            $row = Oyiso_Agent_Connections::save(['name' => $protocol, 'preset' => 'custom', 'url' => 'https://api.example.com/v1', 'protocol' => $protocol, 'models' => ['fixture-model'], 'key' => 'fixture-stream-key']);
            self::assertIsArray($row);
            if ($this->http !== null) remove_filter('pre_http_request', $this->http, 10);
            $this->http = static function ($pre, $args, $url) use ($protocol) {
                $body = json_decode($args['body'], true);
                if ($protocol === 'gemini') self::assertStringEndsWith(':streamGenerateContent?alt=sse', $url);
                else self::assertTrue($body['stream']);
                self::assertSame('text/event-stream', $args['headers']['Accept']);
                self::assertSame($protocol === 'anthropic_messages' || $protocol === 'gemini' ? 'fixture-stream-key' : 'Bearer fixture-stream-key', $args['headers'][$protocol === 'anthropic_messages' ? 'x-api-key' : ($protocol === 'gemini' ? 'x-goog-api-key' : 'Authorization')]);
                return ['headers' => ['content-type' => 'text/event-stream'], 'body' => self::wire(self::events($protocol)), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
            };
            add_filter('pre_http_request', $this->http, 10, 3);
            $text = '';
            $turn = (new Oyiso_Agent_Model())->generate([(new UserMessage([new MessagePart('Hello')]))->toArray()], ['oyiso/get-product'], $row['id'] . '|fixture-model', static function (string $delta) use (&$text): void { $text .= $delta; });
            self::assertInstanceOf(Oyiso_Agent_Turn::class, $turn, $turn instanceof WP_Error ? $turn->get_error_message() : '');
            self::assertSame('你好', $text); self::assertSame(3, $turn->tokens);
            $calls = array_values(array_filter($turn->message->getParts(), static fn($part) => $part->getFunctionCall() !== null));
            self::assertCount(1, $calls); self::assertSame(['product_id' => 123], $calls[0]->getFunctionCall()->getArgs());
        }
    }

    public function testIncompleteAndInvalidStreamsNeverBecomeCompleteRepliesOrExecutableToolCalls(): void
    {
        $cases = [
            ['openai_chat', [['choices' => [['delta' => ['content' => 'partial']]]]]],
            ['openai_responses', [['type' => 'response.incomplete']]],
            ['openai_responses', [['type' => 'response.failed']]],
            ['openai_responses', [['type' => 'response.output_item.done', 'output_index' => -1]]],
            ['openai_chat', [['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '{}']]]], 'finish_reason' => 'length']]], '[DONE]']],
            ['anthropic_messages', [['type' => 'content_block_delta', 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{}']], ['type' => 'message_stop']]],
            ['gemini', [['error' => ['message' => 'upstream failure']]]],
            ['unknown', [['type' => 'event']]],
        ];
        foreach ($cases as [$protocol, $events]) {
            $decoder = new Oyiso_Agent_Stream_Decoder($protocol, static function (string $text): void {});
            try { $decoder->feed(self::wire($events)); $decoder->finish(); self::fail('Incomplete stream was accepted.'); }
            catch (RuntimeException $error) { self::assertNotSame('', $error->getMessage()); }
        }
        $decoder = new Oyiso_Agent_Stream_Decoder('openai_chat', static function (string $text): void {});
        $this->expectException(RuntimeException::class); $decoder->feed(str_repeat('x', 2097153));
    }

    public function testMalformedJsonIsRejected(): void
    {
        $decoder = new Oyiso_Agent_Stream_Decoder('openai_chat', static function (string $text): void {});
        $this->expectException(JsonException::class); $decoder->feed("data: {broken}\n\n");
    }
}
