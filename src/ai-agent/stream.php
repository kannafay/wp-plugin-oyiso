<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;

/** Reassembles SSE into the existing adapters' response shapes. Tool calls run only after a terminal event. */
final class Oyiso_Agent_Stream_Decoder
{
    private string $buffer = '';
    private int $bytes = 0;
    private bool $complete = false;
    /** @var array<string, mixed> */
    private array $data = [];
    /** @var array<int, array<string, mixed>> */
    private array $parts = [];
    /** @var array<int, string> */
    private array $arguments = [];
    /** @var array<int, bool> */
    private array $closed = [];

    /** @param Closure(string):void $onText */
    public function __construct(private readonly string $protocol, private readonly Closure $onText) {}

    public function feed(string $chunk): void
    {
        $this->bytes += strlen($chunk);
        if ($this->bytes > 2097152) throw new RuntimeException('模型流式响应过大，请缩小任务范围。');
        $this->buffer .= $chunk;
        while (preg_match('/\r\n\r\n|\n\n|\r\r/', $this->buffer, $match, PREG_OFFSET_CAPTURE)) {
            $offset = $match[0][1];
            $frame = substr($this->buffer, 0, $offset);
            $this->buffer = substr($this->buffer, $offset + strlen($match[0][0]));
            $this->frame($frame);
        }
    }

    /** @return array<string, mixed> */
    public function finish(): array
    {
        if (trim($this->buffer) !== '') $this->frame($this->buffer);
        $this->buffer = '';
        if (!$this->complete) throw new RuntimeException('模型连接中断，回复尚未完成。请刷新查看状态，或重新发送。');
        if ($this->protocol === 'openai_chat') {
            if ($this->parts !== [] && ($this->data['finish_reason'] ?? '') !== 'tool_calls') throw new RuntimeException('模型的工具调用响应未完整结束。');
            ksort($this->parts);
            $message = self::object($this->data['message'] ?? []);
            if ($this->parts !== []) $message['tool_calls'] = array_values($this->parts);
            return ['id' => $this->data['id'] ?? '', 'choices' => [['message' => $message, 'finish_reason' => $this->data['finish_reason'] ?? 'stop']], 'usage' => $this->data['usage'] ?? []];
        }
        if ($this->protocol === 'anthropic_messages') {
            foreach ($this->arguments as $index => $_) if (empty($this->closed[$index])) throw new RuntimeException('模型的工具调用参数尚未接收完整。');
            ksort($this->parts);
            $this->data['content'] = array_values($this->parts);
        }
        return $this->data;
    }

    private function frame(string $frame): void
    {
        $lines = [];
        foreach (preg_split('/\r\n|\n|\r/', $frame) ?: [] as $line) {
            if (str_starts_with($line, 'data:')) $lines[] = ltrim(substr($line, 5), ' ');
        }
        if ($lines === []) return;
        $json = implode("\n", $lines);
        if ($json === '[DONE]') {
            if ($this->protocol === 'openai_chat' && isset($this->data['finish_reason'])) $this->complete = true;
            return;
        }
        $event = self::object(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
        if (!empty($event['error']) || ($event['type'] ?? '') === 'error') {
            $error = self::object($event['error'] ?? []);
            throw new RuntimeException('模型流式请求失败：' . self::text($error['message'] ?? '请检查连接或稍后重试。'));
        }
        match ($this->protocol) {
            'openai_responses' => $this->responses($event),
            'openai_chat' => $this->chat($event),
            'anthropic_messages' => $this->anthropic($event),
            'gemini' => $this->gemini($event),
            default => throw new RuntimeException('不支持此流式协议。'),
        };
    }

    /** @param array<string, mixed> $event */
    private function responses(array $event): void
    {
        $type = $event['type'] ?? '';
        if (in_array($type, ['response.output_text.delta', 'response.refusal.delta'], true)) ($this->onText)(self::text($event['delta'] ?? ''));
        if ($type === 'response.output_item.done') $this->parts[self::index($event['output_index'] ?? 0)] = self::object($event['item'] ?? []);
        if (in_array($type, ['response.failed', 'response.incomplete'], true)) throw new RuntimeException('模型响应未完整结束，请重试或更换模型。');
        if ($type === 'response.completed') {
            $this->data = self::object($event['response'] ?? []);
            if (empty($this->data['output']) && $this->parts !== []) { ksort($this->parts); $this->data['output'] = array_values($this->parts); }
            $this->complete = ($this->data['status'] ?? '') === 'completed';
        }
    }

    /** @param array<string, mixed> $event */
    private function chat(array $event): void
    {
        if (isset($event['id'])) $this->data['id'] = $event['id'];
        if (isset($event['usage'])) $this->data['usage'] = $event['usage'];
        $choice = self::object(self::object($event['choices'] ?? [])[0] ?? []);
        $delta = self::object($choice['delta'] ?? []);
        $message = self::object($this->data['message'] ?? ['role' => 'assistant', 'content' => '']);
        foreach (['content', 'reasoning_content'] as $key) {
            if (is_string($delta[$key] ?? null)) {
                $message[$key] = self::text($message[$key] ?? '') . $delta[$key];
                if ($key === 'content') ($this->onText)($delta[$key]);
            }
        }
        $this->data['message'] = $message;
        foreach (self::object($delta['tool_calls'] ?? []) as $raw) {
            $call = self::object($raw); $index = self::index($call['index'] ?? 0);
            $part = $this->parts[$index] ?? ['type' => 'function'];
            foreach (['id', 'type', 'extra_content'] as $key) if (isset($call[$key])) $part[$key] = $call[$key];
            $function = self::object($part['function'] ?? []);
            foreach (self::object($call['function'] ?? []) as $key => $value) $function[$key] = self::text($function[$key] ?? '') . self::text($value);
            $part['function'] = $function; $this->parts[$index] = $part;
        }
        if (is_string($choice['finish_reason'] ?? null)) $this->data['finish_reason'] = $choice['finish_reason'];
    }

    /** @param array<string, mixed> $event */
    private function anthropic(array $event): void
    {
        $type = $event['type'] ?? ''; $index = self::index($event['index'] ?? 0);
        if ($type === 'message_start') $this->data = self::object($event['message'] ?? []);
        if ($type === 'content_block_start') {
            $this->parts[$index] = self::object($event['content_block'] ?? []);
            if (($this->parts[$index]['type'] ?? '') === 'text') ($this->onText)(self::text($this->parts[$index]['text'] ?? ''));
        }
        if ($type === 'content_block_delta') {
            $delta = self::object($event['delta'] ?? []);
            $key = match ($delta['type'] ?? '') { 'text_delta' => 'text', 'thinking_delta' => 'thinking', 'signature_delta' => 'signature', default => '' };
            if ($key !== '') {
                $this->parts[$index][$key] = self::text($this->parts[$index][$key] ?? '') . self::text($delta[$key] ?? '');
                if ($key === 'text') ($this->onText)(self::text($delta[$key] ?? ''));
            }
            if (($delta['type'] ?? '') === 'input_json_delta') $this->arguments[$index] = ($this->arguments[$index] ?? '') . self::text($delta['partial_json'] ?? '');
        }
        if ($type === 'content_block_stop') {
            $this->closed[$index] = true;
            if (isset($this->arguments[$index])) $this->parts[$index]['input'] = self::object(json_decode($this->arguments[$index], true, 512, JSON_THROW_ON_ERROR));
        }
        if ($type === 'message_delta') {
            $this->data = array_replace($this->data, self::object($event['delta'] ?? []));
            $this->data['usage'] = array_replace(self::object($this->data['usage'] ?? []), self::object($event['usage'] ?? []));
        }
        if ($type === 'message_stop') $this->complete = true;
    }

    /** @param array<string, mixed> $event */
    private function gemini(array $event): void
    {
        $candidate = self::object(self::object($event['candidates'] ?? [])[0] ?? []);
        $content = self::object($candidate['content'] ?? []);
        foreach (self::object($content['parts'] ?? []) as $raw) {
            $part = self::object($raw); $this->parts[] = $part;
            if (isset($part['text']) && empty($part['thought'])) ($this->onText)(self::text($part['text']));
        }
        $old = self::object(self::object($this->data['candidates'] ?? [])[0] ?? []);
        $candidate = array_replace($old, $candidate);
        $candidate['content'] = ['role' => 'model', 'parts' => $this->parts];
        $this->data = array_replace($this->data, $event, ['candidates' => [$candidate]]);
        if (is_string($candidate['finishReason'] ?? null)) $this->complete = true;
    }

    /** @return array<array-key, mixed> */
    private static function object(mixed $value): array { return is_array($value) ? $value : []; }
    private static function text(mixed $value): string { return is_string($value) ? $value : ''; }
    private static function index(mixed $value): int
    {
        if (!is_int($value) || $value < 0 || $value > 63) throw new RuntimeException('模型返回了无效的流式内容索引。');
        return $value;
    }
}

/** Keeps WordPress HTTPS validation/authentication and existing adapter parsing intact. */
final readonly class Oyiso_Agent_Stream_Transport implements HttpTransporterInterface
{
    /** @param Closure(string):void $onText */
    public function __construct(private HttpTransporterInterface $transport, private string $protocol, private Closure $onText) {}

    public function send(Request $request, ?RequestOptions $options = null): Response
    {
        $data = $request->getData() ?? [];
        $uri = $request->getUri();
        if ($this->protocol === 'gemini') $uri = str_replace(':generateContent', ':streamGenerateContent', $uri) . '?alt=sse';
        else $data['stream'] = true;
        if ($this->protocol === 'openai_chat') $data['stream_options'] = ['include_usage' => true];
        $headers = $request->getHeaders(); $headers['Accept'] = ['text/event-stream'];
        $request = new Request($request->getMethod(), $uri, $headers, $data, $request->getOptions());
        $decoder = new Oyiso_Agent_Stream_Decoder($this->protocol, $this->onText);
        $raw = ''; $received = false;
        $hook = static function (CurlHandle $handle, array $args, string $url) use ($uri, $decoder, &$raw, &$received): void {
            if ($url !== $uri) return;
            curl_setopt($handle, CURLOPT_WRITEFUNCTION, static function (CurlHandle $curl, string $chunk) use ($decoder, &$raw, &$received): int {
                $received = true; $raw .= $chunk;
                if (strlen($raw) > 2097152) throw new RuntimeException('模型流式响应过大，请缩小任务范围。');
                if ((int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE) < 300 && !str_starts_with(ltrim($raw), '{')) $decoder->feed($chunk);
                return strlen($chunk);
            });
        };
        add_action('http_api_curl', $hook, PHP_INT_MAX, 3);
        try { $response = $this->transport->send($request, $options); }
        finally { remove_action('http_api_curl', $hook, PHP_INT_MAX); }
        $body = $received ? $raw : ($response->getBody() ?? '');
        if ($response->getStatusCode() >= 300 || str_starts_with(ltrim($body), '{')) return new Response($response->getStatusCode(), $response->getHeaders(), $body);
        if (!$received) $decoder->feed($body);
        return new Response($response->getStatusCode(), ['Content-Type' => 'application/json'], json_encode($decoder->finish(), JSON_THROW_ON_ERROR));
    }
}
