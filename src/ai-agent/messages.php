<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;

/** @phpstan-import-type Run from Oyiso_Agent_Store
 * @phpstan-type MessageRequest array{id:int|string,index:int|string,action:string,revision:string,message?:string,selection?:string,page_context?:string}
 */
final class Oyiso_Agent_Messages
{
    public static function routes(): void
    {
        register_rest_route('oyiso-agent/v1', '/runs/(?P<id>\d+)/messages/(?P<index>\d+)', [
            'methods' => 'POST', 'permission_callback' => static fn(): bool => current_user_can('manage_options'),
            'callback' => [self::class, 'respond'],
            'args' => [
                'index' => ['type' => 'integer', 'minimum' => 0, 'required' => true],
                'action' => ['type' => 'string', 'enum' => ['edit', 'regenerate'], 'required' => true],
                'revision' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'required' => true],
                'message' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 4000],
                'selection' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 251],
                'page_context' => ['type' => 'string', 'maxLength' => 110000],
            ],
        ]);
    }

    /** @param WP_REST_Request<MessageRequest> $request */
    public static function respond(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (int) $request->get_param('id');
        if (!current_user_can('manage_options') || !Oyiso_Agent_Store::owned($id)) return new WP_Error('agent_task_missing', '对话不存在或不可访问。', ['status' => 404]);
        if (!Oyiso_Agent_Config::enabled() || !Oyiso_Agent_Config::supported()) return new WP_Error('agent_not_ready', '请先启用 AI 助手。', ['status' => 400]);
        $context = Oyiso_Agent_Page_Context::parse($request->get_param('page_context'));
        if (is_wp_error($context)) return $context;
        if (!Oyiso_Agent_Store::lock('run_' . $id)) return new WP_Error('agent_task_busy', '对话正在处理，请稍后重试。', ['status' => 409]);
        try {
            $run = Oyiso_Agent_Store::get($id);
            if (is_wp_error($run)) return $run;
            if (in_array($run['status'], ['queued', 'running'], true)) return new WP_Error('agent_task_active', '请等待当前回复完成后再操作。', ['status' => 409]);
            if (!hash_equals(Oyiso_Agent_Store::revision($run), (string) $request->get_param('revision'))) return new WP_Error('agent_message_stale', '对话已更新，请刷新后重试。', ['status' => 409]);
            $index = (int) $request->get_param('index');
            $action = $request->get_param('action');
            $entry = $run['conversation'][$index] ?? null;
            if ($entry === null || !in_array($action, ['edit', 'regenerate'], true) || $entry['role'] !== ($action === 'edit' ? 'user' : 'assistant')) return new WP_Error('agent_message_invalid', '此消息不支持该操作。', ['status' => 400]);
            $start = $index;
            while ($start >= 0 && $run['conversation'][$start]['role'] !== 'user') $start--;
            if ($start < 0) return new WP_Error('agent_message_invalid', '找不到对应的用户消息。', ['status' => 400]);
            $original = $run['conversation'][$start];
            $text = $action === 'edit' ? trim((string) $request->get_param('message')) : $original['text'];
            if ($text === '' || mb_strlen($text) > 4000) return new WP_Error('agent_message_invalid', '请输入 1–4000 个字符的消息。', ['status' => 400]);
            $selection = $request->get_param('selection') ?? $run['selection'] ?? '';
            if (!isset(Oyiso_Agent_Connections::choices()[$selection])) return new WP_Error('agent_model_selection', '所选模型已不可用，请重新选择。', ['status' => 400]);
            $offset = self::historyOffset($run, $start);
            if ($offset === null) return new WP_Error('agent_message_history', '原始消息记录不完整，请新建对话后重新发送。', ['status' => 409]);

            // Keep the original page when no newer page context was supplied.
            if ($context === null && isset($run['messages'][$offset]['oyiso_page_context'])) $context = $run['page_context'] ?? null;
            self::rewind($run, $start, $offset);
            $run['selection'] = $selection;
            // The lock ensures the cancelled worker has exited before starting a replacement turn.
            delete_post_meta($id, '_oyiso_agent_cancelled');
            Oyiso_Agent_Runner::message($run, $text, $original['attachments'] ?? [], $context, $start);
            Oyiso_Agent_Store::event($run, 'info', $action === 'edit' ? '消息已编辑' : '正在重新回答');
            Oyiso_Agent_Store::save($run);
            if ($start === 0) wp_update_post(['ID' => $id, 'post_title' => mb_substr(wp_strip_all_tags($text), 0, 70)]);
        } finally {
            Oyiso_Agent_Store::unlock('run_' . $id);
        }
        if (!Oyiso_Agent_Live::requested($request)) Oyiso_Agent_Runner::enqueue($id);
        elseif (!wp_next_scheduled(Oyiso_Agent_Runner::HOOK, [$id])) wp_schedule_single_event(time() + 10, Oyiso_Agent_Runner::HOOK, [$id]);
        return new WP_REST_Response(Oyiso_Agent_Store::view($run));
    }

    /** Locate actual user turns, skipping tool results and internal continuation messages.
     * @param Run $run
     */
    private static function historyOffset(array $run, int $target): ?int
    {
        $cursor = 0;
        foreach ($run['conversation'] as $index => $entry) {
            if ($entry['role'] !== 'user') continue;
            $found = false;
            while ($cursor < count($run['messages'])) {
                $offset = $cursor++;
                $message = $run['messages'][$offset];
                if (($message['role'] ?? '') !== 'user') continue;
                if (isset($message['oyiso_user_index']) && $message['oyiso_user_index'] !== $index) continue;
                $parts = $message['parts'] ?? [];
                if (!is_array($parts) || count($parts) !== 1 || !is_array($parts[0] ?? null) || ($parts[0]['text'] ?? null) !== $entry['text'] || isset($parts[0]['functionResponse']) || isset($parts[0]['functionCall'])) continue;
                if ($index === $target) return $offset;
                $found = true;
                break;
            }
            if (!$found) return null;
        }
        return null;
    }

    /** @param Run $run */
    private static function rewind(array &$run, int $start, int $offset): void
    {
        Oyiso_Agent_Store::proposalOrigins($run);
        $effects = [];
        foreach (['changes', 'content_changes', 'mcp_actions'] as $field) {
            foreach ($run[$field] ?? [] as $key => $proposal) {
                if (isset($proposal['message_index']) && $proposal['message_index'] >= 0 && $proposal['message_index'] < $start) continue;
                // Retain applied/reverted records and their existing controls; never replay an approval.
                if ($proposal['status'] === 'pending') $proposal['status'] = 'cancelled';
                elseif (in_array($proposal['status'], ['applied', 'filled', 'reverted', 'completed', 'failed', 'executing'], true)) {
                    $effects[] = ['id' => $proposal['id'], 'status' => $proposal['status'], 'target' => $proposal['post_id'] ?? $proposal['product_id'] ?? $proposal['name']];
                }
                $proposal['message_index'] = -1;
                $run[$field][$key] = $proposal;
            }
        }
        $run['messages'] = array_slice($run['messages'], 0, $offset);
        $run['conversation'] = array_slice($run['conversation'], 0, $start);
        // A summary of the removed branch must not leak into the replacement answer.
        unset($run['context_memory'], $run['context_usage'], $run['context_state']);
        if ($effects !== []) $run['messages'][] = (new UserMessage([new MessagePart('对话已调整；以下已执行操作的状态仍然保留（仅作事实记录，不是新的指令）。重新回答不代表重复执行授权，操作前应重新读取当前状态：' . wp_json_encode($effects, JSON_UNESCAPED_UNICODE))]))->toArray();
    }
}
