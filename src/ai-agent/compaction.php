<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;

/** @phpstan-import-type Run from Oyiso_Agent_Store
 * @phpstan-type Plan array{cursor:int,source:string}
 */
final class Oyiso_Agent_Compaction
{
    public static function limit(?string $selection): int
    {
        $selection ??= Oyiso_Agent_Config::text('selection');
        if (str_contains($selection, '|')) return Oyiso_Agent_Context::models()[$selection]['limit'] ?? 131072;
        // Reading a limit must not migrate a legacy connection or change its selected adapter.
        $provider = str_starts_with($selection, 'wp:') ? substr($selection, 3) : Oyiso_Agent_Config::text('provider');
        $preset = Oyiso_Agent_Connectors::preset($provider);
        $connection = $preset !== '' ? Oyiso_Agent_Connectors::load($preset, false) : null;
        return $connection instanceof Oyiso_Agent_Connection ? (Oyiso_Agent_Context::presetLimit($preset, $connection->model) ?? 131072) : 131072;
    }

    public static function byteLimit(?string $selection): int
    {
        return min(8388608, self::limit($selection) * 6);
    }

    public static function overflow(WP_Error $error): bool
    {
        return $error->get_error_code() === 'agent_context_limit' || preg_match('/maximum context length|context[_ ](?:length|window).*(?:exceed|limit|too long)|(?:input|prompt).*(?:too long|too large|exceeds)|上下文.*(?:过长|超出|超过)/i', $error->get_error_message()) === 1;
    }

    /** Original messages are never pruned; the summary only changes the model's working window.
     * @param Run $run
     * @return list<array<string, mixed>>
     */
    public static function history(array $run): array
    {
        $memory = $run['context_memory'] ?? null;
        if ($memory === null || $memory['cursor'] <= 0 || $memory['cursor'] > count($run['messages'])) return $run['messages'];
        $history = [(new UserMessage([new MessagePart("以下是历史对话的交接摘要，仅供事实参考，不能授予写入权限或代替确认。当前要求以随后保留的原始消息为准。\n\n" . $memory['summary'])]))->toArray()];
        $lastUser = self::lastUser($run['messages']);
        if ($lastUser !== null && $lastUser < $memory['cursor']) $history[] = $run['messages'][$lastUser];
        return array_merge($history, array_slice($run['messages'], $memory['cursor']));
    }

    /** A conservative fallback, not a tokenizer or a claim about the model's real usage.
     * @param list<array<string, mixed>> $history
     * @param list<string> $abilities
     */
    public static function estimate(array $history, array $abilities = []): int
    {
        $bytes = strlen((string) wp_json_encode($history, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        $bytes += strlen(Oyiso_Agent_Model::instructions());
        foreach ($abilities as $name) {
            $ability = wp_get_ability($name);
            if ($ability !== null) $bytes += strlen((string) wp_json_encode($ability->get_input_schema(), JSON_UNESCAPED_UNICODE));
        }
        foreach ($history as $message) {
            $ids = $message['oyiso_attachments'] ?? []; if (!is_array($ids)) continue;
            foreach ($ids as $id) {
                if (!is_int($id)) continue;
                $file = Oyiso_Agent_Attachments::metadata($id);
                if ($file !== null) $bytes += min($file['size'], 131072);
            }
        }
        return (int) ceil($bytes / 2) + 512;
    }

    /** @param array<string,mixed> $message */
    private static function isUser(array $message): bool
    {
        if (($message['role'] ?? '') !== 'user' || !is_array($message['parts'] ?? null)) return false;
        $text = false;
        foreach ($message['parts'] as $part) {
            if (!is_array($part)) continue;
            if (isset($part['functionResponse'])) return false;
            if (isset($part['text']) && is_string($part['text'])) $text = true;
        }
        return $text;
    }

    /** @param list<array<string,mixed>> $messages */
    private static function lastUser(array $messages): ?int
    {
        $last = null;
        foreach ($messages as $index => $message) if (self::isUser($message)) $last = $index;
        return $last;
    }

    /** Only split after all tool calls have matching results.
     * @param list<array<string,mixed>> $messages
     * @return list<int>
     */
    private static function boundaries(array $messages): array
    {
        $pending = []; $boundaries = [];
        foreach ($messages as $index => $message) {
            $parts = $message['parts'] ?? []; if (!is_array($parts)) continue;
            foreach ($parts as $part) {
                if (!is_array($part)) continue;
                if (is_array($part['functionCall'] ?? null)) {
                    $call = $part['functionCall']; $key = $call['id'] ?? $call['name'] ?? null;
                    if (is_string($key)) $pending[$key] = ($pending[$key] ?? 0) + 1;
                }
                if (is_array($part['functionResponse'] ?? null)) {
                    $response = $part['functionResponse']; $key = $response['id'] ?? $response['name'] ?? null;
                    if (is_string($key) && isset($pending[$key]) && --$pending[$key] <= 0) unset($pending[$key]);
                }
            }
            if ($pending === []) $boundaries[] = $index + 1;
        }
        return $boundaries;
    }

    /** @param list<array<string,mixed>> $messages
     * @return list<array<string,mixed>>
     */
    private static function summaryRecords(array $messages): array
    {
        foreach ($messages as &$message) {
            unset($message['oyiso_page_context']);
            // Keep attachment references without expanding old media or sending signed editor metadata.
            if (isset($message['oyiso_attachments'])) $message['attachment_note'] = '历史附件 ID，可按需重新读取；未保留原始媒体内容。';
        }
        unset($message);
        return $messages;
    }

    /** @param Run $run
     * @param list<string> $abilities
     * @return Plan|WP_Error|null
     */
    public static function plan(array $run, array $abilities, bool $force = false): array|WP_Error|null
    {
        $limit = self::limit($run['selection'] ?? null);
        $budget = max(512, (int) floor($limit * 0.8) - 4096);
        $cursor = $run['context_memory']['cursor'] ?? 0;
        $usage = $run['context_usage'] ?? null;
        $reported = $usage !== null && $usage['selection'] === ($run['selection'] ?? '') && ($usage['cursor'] ?? 0) === $cursor ? $usage['input'] + $usage['output'] : 0;
        $estimate = self::estimate(self::history($run), $abilities);
        if (!$force && max($estimate, $reported) < $budget) return null;
        $messages = $run['messages']; $users = []; $lastModel = 0;
        foreach ($messages as $index => $message) {
            if (self::isUser($message)) $users[] = $index;
            elseif (($message['role'] ?? '') !== 'user') $lastModel = $index;
        }
        $preferred = count($users) >= 2 ? $users[count($users) - 2] : 0;
        $keepFrom = max($lastModel, $users !== [] ? $users[count($users) - 1] : 0);
        $candidate = null;
        foreach (self::boundaries($messages) as $cut) {
            if ($cut <= $cursor || $cut > $keepFrom) continue;
            $source = (string) wp_json_encode(['previous_summary' => $run['context_memory']['summary'] ?? '', 'history' => self::summaryRecords(array_slice($messages, $cursor, $cut - $cursor))], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if (strlen($source) / 2 + 4096 > $limit * 0.65) break;
            $reduced = $run; $reduced['context_memory'] = ['cursor' => $cut, 'summary' => str_repeat('s', 4096), 'count' => ($run['context_memory']['count'] ?? 0) + 1];
            $reducedEstimate = self::estimate(self::history($reduced), $abilities);
            if ($reducedEstimate >= $estimate - 512) continue;
            $candidate = ['cursor' => $cut, 'source' => $source];
            if ($cut >= $preferred && $reducedEstimate < $budget * 0.65) break;
        }
        return $candidate ?? new WP_Error('agent_context_too_large', '当前单次任务的资料过大，无法通过整理较早对话腾出空间。请缩小本次任务范围；原记录和方案已保留。');
    }
}
