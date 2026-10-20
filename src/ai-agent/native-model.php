<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\Enums\MessagePartChannelEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;

/** Native text/tool protocols, using the same WordPress transporter and message history. */
final class Oyiso_Agent_Native_Model extends AbstractApiBasedModel implements TextGenerationModelInterface
{
    private const LOCAL_CALL = 'oyiso-gemini-local-';
    private const REDACTED_THOUGHT = 'oyiso-anthropic-redacted:';
    private const RESPONSES_ITEM = 'oyiso-openai-item:';

    public function __construct(ModelMetadata $metadata, ProviderMetadata $provider, private readonly string $connectionId)
    {
        parent::__construct($metadata, $provider);
    }

    public function generateTextResult(array $prompt): GenerativeAiResult
    {
        $connection = Oyiso_Agent_Connections::load($this->connectionId, $this->metadata()->getId());
        if (is_wp_error($connection)) {
            throw new InvalidArgumentException($connection->get_error_message());
        }
        $anthropic = $connection->protocol === 'anthropic_messages';
        $responses = $connection->protocol === 'openai_responses';
        if (!$anthropic && !$responses && $connection->protocol !== 'gemini') {
            throw new InvalidArgumentException('此连接的接口协议已更改，请重新发送任务。');
        }
        $key = Oyiso_Agent_Config::secret(Oyiso_Agent_Connections::secretName($connection->id));
        $headers = ['Content-Type' => 'application/json'];
        $headers += match ($connection->protocol) {
            'anthropic_messages' => ['x-api-key' => $key, 'anthropic-version' => '2023-06-01'],
            'openai_responses' => ['Authorization' => 'Bearer ' . $key],
            default => ['x-goog-api-key' => $key],
        };
        $base = rtrim($connection->url, '/');
        if (empty(wp_parse_url($base, PHP_URL_PATH))) {
            $base .= $connection->protocol === 'gemini' ? '/v1beta' : '/v1';
        }
        $path = $responses ? '/responses' : ($anthropic ? '/messages' : '/models/' . rawurlencode(preg_replace('/^models\//', '', $connection->model) ?? $connection->model) . ':generateContent');
        $configured = $this->getRequestOptions();
        $options = $configured !== null ? clone $configured : new RequestOptions();
        $options->setTimeout($options->getTimeout() ?? 45.0);
        $options->setMaxRedirects(0);
        $body = $responses ? $this->responsesParameters($prompt, $connection->model) : $this->parameters($prompt, $connection->model, $anthropic);
        $response = $this->getHttpTransporter()->send(new Request(HttpMethodEnum::POST(), $base . $path, $headers, $body, $options));
        ResponseUtil::throwIfNotSuccessful($response);
        $data = self::object($response->getData());
        return $responses ? $this->responsesResult($data) : $this->result($data, $anthropic);
    }

    /** @param list<Message> $prompt
     * @return array<string, mixed>
     */
    private function responsesParameters(array $prompt, string $model): array
    {
        $input = [];
        foreach ($prompt as $message) {
            foreach ($message->getParts() as $part) {
                $signature = $part->getThoughtSignature();
                if ($message->getRole()->isModel() && $signature !== null && str_starts_with($signature, self::RESPONSES_ITEM)) {
                    // Replay complete output items, including opaque reasoning and the original argument JSON.
                    $input[] = self::object(json_decode(substr($signature, strlen(self::RESPONSES_ITEM)), true, 512, JSON_THROW_ON_ERROR));
                } elseif (($call = $part->getFunctionCall()) !== null) {
                    $input[] = ['type' => 'function_call', 'call_id' => $call->getId(), 'name' => $call->getName(), 'arguments' => json_encode((object) self::object($call->getArgs() ?? []), JSON_THROW_ON_ERROR)];
                } elseif (($reply = $part->getFunctionResponse()) !== null) {
                    $input[] = ['type' => 'function_call_output', 'call_id' => $reply->getId(), 'output' => json_encode($reply->getResponse(), JSON_THROW_ON_ERROR)];
                } elseif ($part->getType()->isText()) {
                    $text = $part->getText() ?? '';
                    if (!$part->getChannel()->isThought() && $text !== '') {
                        $input[] = ['role' => $message->getRole()->isModel() ? 'assistant' : 'user', 'content' => $text];
                    }
                } else {
                    throw new InvalidArgumentException('Oyiso 模型预设当前仅支持文本和工具调用。');
                }
            }
        }
        $config = $this->getConfig();
        $params = ['model' => $model, 'input' => $input, 'max_output_tokens' => $config->getMaxTokens() ?? 4096, 'store' => false, 'include' => ['reasoning.encrypted_content']];
        if (($system = $config->getSystemInstruction()) !== null && $system !== '') {
            $params['instructions'] = $system;
        }
        $tools = [];
        foreach ($config->getFunctionDeclarations() ?? [] as $declaration) {
            // Abilities have optional fields; keep their existing non-strict JSON schema semantics.
            $tools[] = ['type' => 'function', 'name' => $declaration->getName(), 'description' => $declaration->getDescription(), 'parameters' => self::schema($declaration), 'strict' => false];
        }
        if ($tools !== []) {
            $params['tools'] = $tools;
        }
        return $params;
    }

    /** @param array<string, mixed> $data */
    private function responsesResult(array $data): GenerativeAiResult
    {
        if (($data['status'] ?? null) !== 'completed' || !empty($data['error'])) {
            throw new RuntimeException('模型响应未完整结束，请重试或更换模型。');
        }
        $parts = [];
        $calls = [];
        $visible = false;
        foreach (self::objects($data['output'] ?? null) as $item) {
            $signature = self::RESPONSES_ITEM . json_encode($item, JSON_THROW_ON_ERROR);
            if (($item['status'] ?? 'completed') !== 'completed') {
                throw new RuntimeException('模型响应未完整结束，请重试或更换模型。');
            }
            switch ($item['type'] ?? '') {
                case 'reasoning':
                    $summary = '';
                    foreach (self::objects($item['summary'] ?? []) as $block) {
                        $summary .= self::text($block['text'] ?? null);
                    }
                    $parts[] = new MessagePart($summary, MessagePartChannelEnum::thought(), $signature);
                    break;
                case 'function_call':
                    $id = self::text($item['call_id'] ?? null);
                    $name = self::text($item['name'] ?? null);
                    $args = self::object(json_decode(self::text($item['arguments'] ?? null), true, 512, JSON_THROW_ON_ERROR));
                    if ($id === '' || $name === '' || isset($calls[$id])) {
                        throw new RuntimeException('模型返回的工具调用缺少 ID 或名称。');
                    }
                    $calls[$id] = true;
                    $parts[] = new MessagePart(new FunctionCall($id, $name, $args), null, $signature);
                    break;
                case 'message':
                    $text = '';
                    foreach (self::objects($item['content'] ?? null) as $block) {
                        $text .= match ($block['type'] ?? '') {
                            'output_text' => self::text($block['text'] ?? null),
                            'refusal' => self::text($block['refusal'] ?? null),
                            default => throw new RuntimeException('模型返回了不支持的内容，请检查接口协议和模型。'),
                        };
                    }
                    $visible = $visible || $text !== '';
                    $parts[] = new MessagePart($text, null, $signature);
                    break;
                default:
                    throw new RuntimeException('模型返回了不支持的内容，请检查接口协议和模型。');
            }
        }
        if (!$visible && $calls === []) {
            throw new RuntimeException('模型未返回可用文本或工具调用，请检查接口协议和模型。');
        }
        $usage = self::object($data['usage'] ?? []);
        $details = self::object($usage['output_tokens_details'] ?? []);
        $input = self::count($usage, 'input_tokens');
        $output = self::count($usage, 'output_tokens');
        return new GenerativeAiResult(is_string($data['id'] ?? null) ? $data['id'] : '', [new Candidate(new ModelMessage($parts), $calls !== [] ? FinishReasonEnum::toolCalls() : FinishReasonEnum::stop())], new TokenUsage($input, $output, self::count($usage, 'total_tokens') ?: $input + $output, self::count($details, 'reasoning_tokens')), $this->providerMetadata(), $this->metadata());
    }

    /** @param list<Message> $prompt
     * @return array<string, mixed>
     */
    private function parameters(array $prompt, string $model, bool $anthropic): array
    {
        $config = $this->getConfig();
        $messages = [];
        foreach ($prompt as $message) {
            $parts = [];
            foreach ($message->getParts() as $part) {
                $prepared = $this->inputPart($part, $anthropic);
                if ($prepared !== null) {
                    $parts[] = $prepared;
                }
            }
            if ($parts === []) {
                continue;
            }
            $role = $message->getRole()->isModel() ? ($anthropic ? 'assistant' : 'model') : 'user';
            $field = $anthropic ? 'content' : 'parts';
            // Parallel tool results arrive as separate UserMessages; both APIs expect them together.
            $last = array_key_last($messages);
            if ($last !== null && $messages[$last]['role'] === $role) {
                $messages[$last][$field] = array_merge($messages[$last][$field] ?? [], $parts);
            } else {
                $messages[] = ['role' => $role, $field => $parts];
            }
        }
        $params = $anthropic
            ? ['model' => $model, 'messages' => $messages, 'max_tokens' => $config->getMaxTokens() ?? 4096]
            : ['contents' => $messages, 'generationConfig' => ['maxOutputTokens' => $config->getMaxTokens() ?? 4096]];
        $system = $config->getSystemInstruction();
        if ($system !== null && $system !== '') {
            $params[$anthropic ? 'system' : 'systemInstruction'] = $anthropic ? $system : ['parts' => [['text' => $system]]];
        }
        $tools = [];
        foreach ($config->getFunctionDeclarations() ?? [] as $declaration) {
            $tools[] = ['name' => $declaration->getName(), 'description' => $declaration->getDescription(), $anthropic ? 'input_schema' : 'parametersJsonSchema' => self::schema($declaration)];
        }
        if ($tools !== []) {
            $params['tools'] = $anthropic ? $tools : [['functionDeclarations' => $tools]];
        }
        return $params;
    }

    /** @return array<string, mixed>|null */
    private function inputPart(MessagePart $part, bool $anthropic): ?array
    {
        $signature = $part->getThoughtSignature();
        if ($part->getType()->isText()) {
            $text = $part->getText() ?? '';
            if ($anthropic && $part->getChannel()->isThought()) {
                if ($signature === null) {
                    return null;
                }
                return str_starts_with($signature, self::REDACTED_THOUGHT)
                    ? ['type' => 'redacted_thinking', 'data' => substr($signature, strlen(self::REDACTED_THOUGHT))]
                    : ['type' => 'thinking', 'thinking' => $text, 'signature' => $signature];
            }
            if ($text === '' && ($anthropic || $signature === null)) {
                return null;
            }
            $data = $anthropic ? ['type' => 'text', 'text' => $text] : ['text' => $text];
            if (!$anthropic && $part->getChannel()->isThought()) {
                $data['thought'] = true;
            }
        } elseif (($call = $part->getFunctionCall()) !== null) {
            $args = (object) self::object($call->getArgs() ?? []);
            if ($anthropic) {
                return ['type' => 'tool_use', 'id' => $call->getId(), 'name' => $call->getName(), 'input' => $args];
            }
            $data = ['functionCall' => ['name' => $call->getName(), 'args' => $args]];
            $id = $call->getId();
            if ($id !== null && !str_starts_with($id, self::LOCAL_CALL)) {
                $data['functionCall']['id'] = $id;
            }
        } elseif (($reply = $part->getFunctionResponse()) !== null) {
            if ($anthropic) {
                return ['type' => 'tool_result', 'tool_use_id' => $reply->getId(), 'content' => wp_json_encode($reply->getResponse(), JSON_THROW_ON_ERROR)];
            }
            $result = $reply->getResponse();
            $data = ['functionResponse' => ['name' => $reply->getName(), 'response' => is_array($result) && !array_is_list($result) ? $result : ['result' => $result]]];
            $id = $reply->getId();
            if ($id !== null && !str_starts_with($id, self::LOCAL_CALL)) {
                $data['functionResponse']['id'] = $id;
            }
        } else {
            throw new InvalidArgumentException('Oyiso 模型预设当前仅支持文本和工具调用。');
        }
        if (!$anthropic && $signature !== null) {
            $data['thoughtSignature'] = $signature;
        }
        return $data;
    }

    /** @param array<string, mixed> $data */
    private function result(array $data, bool $anthropic): GenerativeAiResult
    {
        $candidate = $anthropic ? $data : (self::objects($data['candidates'] ?? null)[0] ?? []);
        $content = $anthropic ? $candidate : self::object($candidate['content'] ?? null);
        $rawParts = self::objects($content[$anthropic ? 'content' : 'parts'] ?? null);
        $parts = [];
        $hasCalls = false;
        foreach ($rawParts as $rawPart) {
            $part = $this->outputPart($rawPart, $anthropic);
            $hasCalls = $hasCalls || $part->getType()->isFunctionCall();
            $parts[] = $part;
        }
        if ($parts === []) {
            throw new RuntimeException('模型未返回可用文本或工具调用，请检查接口协议和模型。');
        }
        $reason = $candidate[$anthropic ? 'stop_reason' : 'finishReason'] ?? '';
        $finish = match ($reason) {
            'max_tokens', 'MAX_TOKENS' => FinishReasonEnum::length(),
            'refusal', 'SAFETY', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'RECITATION' => FinishReasonEnum::contentFilter(),
            'end_turn', 'stop_sequence', 'tool_use', 'STOP' => $hasCalls ? FinishReasonEnum::toolCalls() : FinishReasonEnum::stop(),
            default => FinishReasonEnum::error(),
        };
        if ($hasCalls && !$finish->isToolCalls()) {
            throw new RuntimeException('模型的工具调用响应未完整结束，请重试或更换模型。');
        }
        $usage = self::object($data[$anthropic ? 'usage' : 'usageMetadata'] ?? []);
        $input = $anthropic ? self::count($usage, 'input_tokens') + self::count($usage, 'cache_read_input_tokens') + self::count($usage, 'cache_creation_input_tokens') : self::count($usage, 'promptTokenCount');
        $thought = $anthropic ? null : self::count($usage, 'thoughtsTokenCount');
        $output = $anthropic ? self::count($usage, 'output_tokens') : self::count($usage, 'candidatesTokenCount') + ($thought ?? 0);
        $total = $anthropic ? $input + $output : (self::count($usage, 'totalTokenCount') ?: $input + $output);
        return new GenerativeAiResult(is_string($data[$anthropic ? 'id' : 'responseId'] ?? null) ? $data[$anthropic ? 'id' : 'responseId'] : '', [new Candidate(new ModelMessage($parts), $finish)], new TokenUsage($input, $output, $total, $thought), $this->providerMetadata(), $this->metadata());
    }

    /** @param array<string, mixed> $data */
    private function outputPart(array $data, bool $anthropic): MessagePart
    {
        $signature = isset($data[$anthropic ? 'signature' : 'thoughtSignature']) ? self::text($data[$anthropic ? 'signature' : 'thoughtSignature']) : null;
        $type = $data['type'] ?? '';
        if ($anthropic && $type === 'redacted_thinking') {
            return new MessagePart('', MessagePartChannelEnum::thought(), self::REDACTED_THOUGHT . self::text($data['data'] ?? null));
        }
        if (($anthropic && in_array($type, ['text', 'thinking'], true)) || (!$anthropic && isset($data['text']))) {
            $thought = $anthropic ? $type === 'thinking' : !empty($data['thought']);
            return new MessagePart(self::text($data[$thought && $anthropic ? 'thinking' : 'text'] ?? null), $thought ? MessagePartChannelEnum::thought() : MessagePartChannelEnum::content(), $signature);
        }
        if (($anthropic && $type === 'tool_use') || (!$anthropic && isset($data['functionCall']))) {
            $call = $anthropic ? $data : self::object($data['functionCall']);
            $id = isset($call['id']) ? self::text($call['id']) : ($anthropic ? '' : self::LOCAL_CALL . wp_generate_uuid4());
            $name = self::text($call['name'] ?? null);
            if ($id === '' || $name === '') {
                throw new RuntimeException('模型返回的工具调用缺少 ID 或名称。');
            }
            return new MessagePart(new FunctionCall($id, $name, self::object($call[$anthropic ? 'input' : 'args'] ?? [])), null, $signature);
        }
        throw new RuntimeException('模型返回了不支持的内容，请检查接口协议和模型。');
    }

    /** @return array<string, mixed> */
    private static function schema(FunctionDeclaration $declaration): array
    {
        $schema = $declaration->getParameters() ?? ['type' => 'object'];
        if (($schema['type'] ?? null) === 'object' && empty($schema['properties'])) {
            $schema['properties'] = new stdClass();
        }
        return $schema;
    }

    /** @return array<string, mixed> */
    private static function object(mixed $value): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new RuntimeException('模型返回的响应格式无效，请检查接口协议和模型。');
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new RuntimeException('模型返回的响应格式无效，请检查接口协议和模型。');
            }
            $result[$key] = $item;
        }
        return $result;
    }

    /** @return list<array<string, mixed>> */
    private static function objects(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException('模型返回的响应格式无效，请检查接口协议和模型。');
        }
        return array_map(self::object(...), $value);
    }

    private static function text(mixed $value): string
    {
        if (!is_string($value)) {
            throw new RuntimeException('模型返回的响应格式无效，请检查接口协议和模型。');
        }
        return $value;
    }

    /** @param array<string, mixed> $values */
    private static function count(array $values, string $key): int
    {
        return isset($values[$key]) && is_int($values[$key]) ? max(0, $values[$key]) : 0;
    }
}
