<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Tools\DTO\FunctionResponse;

/** @phpstan-import-type Run from Oyiso_Agent_Store
 * @phpstan-import-type Attachment from Oyiso_Agent_Attachments
 * @phpstan-import-type PageContext from Oyiso_Agent_Page_Context
 * @phpstan-import-type Plan from Oyiso_Agent_Compaction
 */
final class Oyiso_Agent_Runner
{
    public const HOOK = 'oyiso_agent_run_step';

    public function __construct(private readonly Oyiso_Agent_Model_Gateway $model)
    {
    }

    public static function enqueue(int $id): void
    {
        if (function_exists('as_enqueue_async_action') && did_action('action_scheduler_init') > 0) {
            if (as_enqueue_async_action(self::HOOK, [$id], 'oyiso-agent') > 0) {
                return;
            }
        }
        if (!wp_next_scheduled(self::HOOK, [$id])) {
            wp_schedule_single_event(time() + 1, self::HOOK, [$id]);
        }
    }

    /** @param Run $run
     * @param list<Attachment> $attachments
     * @param PageContext|null $context
     */
    public static function message(array &$run, string $text, array $attachments = [], ?array $context = null): void
    {
        Oyiso_Agent_Store::beginExecution($run);
        // Read-before-write snapshots belong to one user turn, not the whole conversation.
        unset($run['partial'], $run['post_snapshots'], $run['product_snapshots'], $run['context_retry']);
        $run['snapshots'] = [];
        foreach ($run['messages'] as &$previous) unset($previous['oyiso_page_context']);
        unset($previous, $run['page_context']);
        $message = (new UserMessage([new MessagePart($text)]))->toArray();
        if ($context !== null) { $run['page_context'] = $context; $message['oyiso_page_context'] = $context; }
        if ($attachments !== []) $message['oyiso_attachments'] = array_column($attachments, 'id');
        $run['messages'][] = $message;
        $entry = ['role' => 'user', 'text' => $text];
        if ($attachments !== []) $entry['attachments'] = $attachments;
        $run['conversation'][] = $entry;
        $run['step'] = 0;
        $run['status'] = 'queued';
        $run['error'] = '';
        Oyiso_Agent_Store::event($run, 'info', '已接收任务');
        Oyiso_Agent_Store::save($run);
    }

    /** @param (Closure(string,int,string):void)|null $onText
     * @param (Closure(array<string,mixed>):void)|null $onState
     */
    public function step(int $id, ?Closure $onText = null, ?Closure $onState = null): void
    {
        if (!Oyiso_Agent_Store::lock('run_' . $id)) {
            return;
        }
        $previousUser = get_current_user_id();
        $previousAbort = ignore_user_abort(true);
        try {
            if (function_exists('set_time_limit')) {
                set_time_limit(150);
            }
            $post = get_post($id);
            if (!$post instanceof WP_Post || $post->post_type !== Oyiso_Agent_Store::TYPE) {
                return;
            }
            wp_set_current_user((int) $post->post_author);
            $run = Oyiso_Agent_Store::get($id);
            if (is_wp_error($run) || !in_array($run['status'], ['queued', 'running'], true) || Oyiso_Agent_Store::cancelled($id)) {
                return;
            }
            if (!Oyiso_Agent_Config::enabled() || !current_user_can('manage_options') || !Oyiso_Agent_Config::supported()) {
                $this->fail($run, 'Agent 未启用、运行环境不支持或任务发起人已无权限。');
                return;
            }
            if ($run['step'] >= Oyiso_Agent_Config::maxSteps()) {
                $this->fail($run, '本次任务已达到执行轮次上限。请缩小任务范围后重试。');
                return;
            }
            $allowed = Oyiso_Agent_Abilities::allowed();
            $plan = Oyiso_Agent_Compaction::plan($run, $allowed, ($run['context_retry'] ?? '') === 'pending');
            if (is_wp_error($plan)) { $this->fail($run, $plan->get_error_message()); return; }
            if ($plan !== null) {
                $this->compact($run, $plan, $allowed, $onState);
                if ($onText === null && $run['status'] === 'queued' && !Oyiso_Agent_Store::cancelled($id)) self::enqueue($id);
                return;
            }
            $run['status'] = 'running';
            unset($run['partial']);
            $run['step']++;
            Oyiso_Agent_Store::event($run, 'info', '正在分析任务', '第 ' . $run['step'] . ' 轮');
            Oyiso_Agent_Store::save($run);
            $checkpoint = $run;
            $savedAt = 0.0;
            $run['partial'] = ['text' => '', 'model' => explode('|', $run['selection'] ?? '|AI', 2)[1] ?? 'AI', 'model_label' => Oyiso_Agent_Connections::label($run['selection'] ?? null)];
            $requestedModel = $run['partial']['model'];
            $modelLabel = $run['partial']['model_label'];
            $turn = $this->model->generate(Oyiso_Agent_Compaction::history($run), $allowed, $run['selection'] ?? null, static function (string $text) use (&$run, &$savedAt, $id, $onText): void {
                if (Oyiso_Agent_Store::cancelled($id)) throw new RuntimeException('任务已取消。');
                if ($text === '') return;
                $run['partial']['text'] .= $text;
                if (microtime(true) - $savedAt >= 0.25) { Oyiso_Agent_Store::save($run); $savedAt = microtime(true); }
                if ($onText !== null) $onText($text, $run['step'], $run['partial']['model']);
            });
            if (Oyiso_Agent_Store::cancelled($id)) {
                Oyiso_Agent_Store::save($run);
                return;
            }
            if (is_wp_error($turn)) {
                if (($run['context_retry'] ?? '') !== 'attempted' && Oyiso_Agent_Compaction::overflow($turn)) {
                    $retry = Oyiso_Agent_Compaction::plan($run, $allowed, true);
                    if (is_array($retry)) {
                        $run['context_retry'] = 'pending'; $run['status'] = 'queued';
                        $run['context_state'] = ['phase' => 'compacting', 'count' => $run['context_memory']['count'] ?? 0];
                        unset($run['partial']);
                        Oyiso_Agent_Store::event($run, 'info', '上下文过长，准备自动整理'); Oyiso_Agent_Store::save($run);
                        if ($onText === null) self::enqueue($id);
                        return;
                    }
                }
                $this->fail($run, $turn->get_error_message());
                return;
            }
            $calls = [];
            $text = '';
            foreach ($turn->message->getParts() as $part) {
                if ($part->getChannel()->value === 'content' && $part->getText() !== null) {
                    $text .= $part->getText();
                }
                $functionCall = $part->getFunctionCall();
                if ($functionCall !== null) {
                    $calls[] = $functionCall;
                }
            }
            if (count($calls) > 4) {
                $this->fail($run, '单轮工具请求超过上限，请缩小任务范围后重试。');
                return;
            }
            $run['tokens'] += $turn->tokens;
            unset($run['context_retry']);
            // Context occupancy comes from one request's reported input, never cumulative usage.
            if ($turn->inputTokens !== null && $turn->inputTokens > 0) {
                $run['context_usage'] = ['selection' => $run['selection'] ?? '', 'input' => $turn->inputTokens, 'output' => max(0, $turn->outputTokens ?? 0), 'cursor' => $run['context_memory']['cursor'] ?? 0];
                $checkpoint['context_usage'] = $run['context_usage'];
            } else {
                unset($run['context_usage'], $checkpoint['context_usage']);
            }
            unset($run['partial']);
            $run['messages'][] = $turn->message->toArray();
            if (trim($text) !== '') {
                $label = str_ends_with($modelLabel, ' / ' . $requestedModel) ? substr($modelLabel, 0, -strlen($requestedModel)) . $turn->model : Oyiso_Agent_Connections::label($run['selection'] ?? null, $turn->model);
                $run['conversation'][] = ['role' => 'assistant', 'text' => $text, 'model' => $turn->model, 'model_label' => $label];
            }
            Oyiso_Agent_Store::event($run, 'info', '模型已响应', $turn->model);
            $tools = new Oyiso_Agent_Tools($run);
            Oyiso_Agent_Abilities::bind($tools);
            $responses = [];
            $proposalsBefore = count($run['changes']) + count($run['content_changes'] ?? []);
            $mcpBefore = count($run['mcp_actions'] ?? []);
            $proposalStarts = ['changes' => count($run['changes']), 'content_changes' => count($run['content_changes'] ?? []), 'mcp_actions' => $mcpBefore];
            foreach ($calls as $call) {
                if (Oyiso_Agent_Store::cancelled($id)) {
                    break;
                }
                $name = WP_AI_Client_Ability_Function_Resolver::function_name_to_ability_name($call->getName() ?? '');
                $ability = in_array($name, $allowed, true) ? wp_get_ability($name) : null;
                $args = $call->getArgs();
                $args = is_object($args) ? (array) $args : $args;
                Oyiso_Agent_Store::event($run, 'info', '调用工具', $ability?->get_label() ?? $name);
                // A killed worker must leave a complete model conversation to retry.
                $checkpoint['events'] = $run['events'];
                $checkpoint['executions'] = $run['executions'] ?? [];
                $checkpoint['tokens'] = $run['tokens'];
                Oyiso_Agent_Store::save($checkpoint);
                try {
                    $result = $ability !== null && is_array($args) ? $ability->execute($args) : new WP_Error('agent_tool_denied', '该工具或参数不在允许范围。');
                } catch (Throwable $error) {
                    $result = new WP_Error('agent_tool_error', Oyiso_Agent_Config::error($error->getMessage()));
                }
                if (is_wp_error($result)) {
                    $error = Oyiso_Agent_Config::error($result->get_error_message());
                    Oyiso_Agent_Store::event($run, 'error', $ability?->get_label() ?? '工具调用失败', $error);
                    $result = ['error' => $error, 'code' => $result->get_error_code()];
                } else {
                    Oyiso_Agent_Store::event($run, 'success', $ability?->get_label() ?? '工具执行完成');
                    if (is_array($result)) {
                        $sources = isset($result['url']) ? [$result] : ($result['results'] ?? []);
                        foreach (is_array($sources) ? $sources : [] as $source) {
                            if (is_array($source) && is_string($source['url'] ?? null)) {
                                $title = is_string($source['title'] ?? null) ? $source['title'] : '';
                                Oyiso_Agent_Store::event($run, 'info', '资料来源', $title . ' ' . $source['url']);
                            }
                        }
                    }
                }
                $responses[] = new MessagePart(new FunctionResponse($call->getId(), $call->getName(), $result));
            }
            foreach ($responses as $response) $run['messages'][] = (new UserMessage([$response]))->toArray();
            $pending = count(array_filter($run['changes'], static fn(array $change): bool => $change['status'] === 'pending')) > 0 || Oyiso_Agent_Content::pending($run) || Oyiso_Agent_MCP::pending($run);
            if (count($run['changes']) + count($run['content_changes'] ?? []) > $proposalsBefore || count($run['mcp_actions'] ?? []) > $mcpBefore) {
                $run['status'] = 'waiting';
                $trash = count(array_filter($run['changes'], static fn(array $change): bool => ($change['type'] ?? '') === 'trash' && $change['status'] === 'pending')) > 0;
                $review = $trash ? '商品操作方案已生成，尚未执行。请核对卡片中的商品，再确认是否移入回收站。' : '修改方案已生成。请查看前后对照，再选择是否应用。';
                $owner = count($run['conversation']);
                foreach ($run['changes'] as $index => $proposal) if ($index >= $proposalStarts['changes']) { $proposal['message_index'] = $owner; $run['changes'][$index] = $proposal; }
                foreach ($run['content_changes'] ?? [] as $index => $proposal) if ($index >= $proposalStarts['content_changes']) { $proposal['message_index'] = $owner; $run['content_changes'][$index] = $proposal; }
                foreach ($run['mcp_actions'] ?? [] as $index => $proposal) if ($index >= $proposalStarts['mcp_actions']) { $proposal['message_index'] = $owner; $run['mcp_actions'][$index] = $proposal; }
                $run['conversation'][] = ['role' => 'assistant', 'text' => count($run['mcp_actions'] ?? []) > $mcpBefore ? 'MCP 操作尚未执行。请查看工具和参数，再选择是否执行。' : $review, 'model' => $turn->model];
            } elseif ($calls === []) {
                $run['status'] = $pending ? 'waiting' : 'completed';
                if (trim($text) === '') {
                    $this->fail($run, '模型没有返回内容，请调整指令后重试。');
                    return;
                }
            } else {
                $run['status'] = 'queued';
            }
            Oyiso_Agent_Store::save($run);
            if ($onText === null && $run['status'] === 'queued' && !Oyiso_Agent_Store::cancelled($id)) {
                self::enqueue($id);
            }
        } catch (Throwable $error) {
            if (isset($run) && is_array($run)) {
                if (Oyiso_Agent_Store::cancelled($id)) { Oyiso_Agent_Store::save($run); return; }
                if (isset($checkpoint)) {
                    $checkpoint['events'] = $run['events'];
                    $checkpoint['executions'] = $run['executions'] ?? [];
                    $checkpoint['tokens'] = $run['tokens'];
                    if (isset($run['partial'])) $checkpoint['partial'] = $run['partial'];
                    $run = $checkpoint;
                }
                $this->fail($run, Oyiso_Agent_Config::error($error->getMessage()));
            }
        } finally {
            ignore_user_abort($previousAbort !== 0);
            Oyiso_Agent_Abilities::bind(null);
            wp_set_current_user($previousUser);
            Oyiso_Agent_Store::unlock('run_' . $id);
        }
    }

    /** @param Run $run
     * @param Plan $plan
     * @param list<string> $abilities
     * @param (Closure(array<string,mixed>):void)|null $onState
     */
    private function compact(array &$run, array $plan, array $abilities, ?Closure $onState): void
    {
        $count = $run['context_memory']['count'] ?? 0;
        $run['status'] = 'running'; $run['context_state'] = ['phase' => 'compacting', 'count' => $count]; unset($run['partial']);
        Oyiso_Agent_Store::event($run, 'info', '正在整理较早的对话'); Oyiso_Agent_Store::save($run);
        if ($onState !== null) $onState(Oyiso_Agent_Store::view($run));
        if (!$this->model instanceof Oyiso_Agent_Context_Summarizer) { $this->fail($run, '当前模型适配器不支持上下文整理，原记录已保留。'); return; }
        $executions = $run['executions'] ?? []; $execution = array_key_last($executions);
        if ($execution !== null) { $record = $executions[$execution]; $record['summary_calls'] = ($record['summary_calls'] ?? 0) + 1; $executions[$execution] = $record; $run['executions'] = $executions; }
        Oyiso_Agent_Store::save($run);
        $result = $this->model->summarize($plan['source'], $run['selection'] ?? null);
        if (Oyiso_Agent_Store::cancelled($run['id'])) { $run['context_state'] = ['phase' => 'cancelled', 'count' => $count]; Oyiso_Agent_Store::save($run); return; }
        $run['context_state'] = ['phase' => 'failed', 'count' => $count];
        if (is_wp_error($result)) { $this->fail($run, '自动整理未完成，原记录和消息已保留。' . $result->get_error_message()); return; }
        $run['tokens'] += max(0, $result->tokens);
        $summary = ''; $calls = false;
        foreach ($result->message->getParts() as $part) {
            if ($part->getFunctionCall() !== null) $calls = true;
            if ($part->getChannel()->value === 'content') $summary .= $part->getText() ?? '';
        }
        $summary = trim($summary);
        if ($summary === '' || $calls || mb_strlen($summary) > 12000) { $this->fail($run, '模型未返回有效的历史摘要，原记录和消息已保留，可重试。'); return; }
        $candidate = $run; $candidate['context_memory'] = ['cursor' => $plan['cursor'], 'summary' => $summary, 'count' => $count + 1];
        if (Oyiso_Agent_Compaction::estimate(Oyiso_Agent_Compaction::history($candidate), $abilities) >= Oyiso_Agent_Compaction::estimate(Oyiso_Agent_Compaction::history($run), $abilities)) { $this->fail($run, '整理后的资料未缩小，已保留原记录，请缩小本次任务范围。'); return; }
        $run['context_memory'] = $candidate['context_memory'];
        $run['context_state'] = ['phase' => 'completed', 'count' => $count + 1]; $run['status'] = 'queued';
        if (($run['context_retry'] ?? '') === 'pending') $run['context_retry'] = 'attempted';
        unset($run['context_usage']);
        Oyiso_Agent_Store::event($run, 'success', '上下文已自动压缩', '第 ' . ($count + 1) . ' 次整理；原始对话、附件和方案保留。');
        Oyiso_Agent_Store::save($run);
        if ($onState !== null) $onState(Oyiso_Agent_Store::view($run));
    }

    /** @param Run $run */
    private function fail(array $run, string $message): void
    {
        if (($run['context_state']['phase'] ?? '') === 'compacting') $run['context_state'] = ['phase' => 'failed', 'count' => $run['context_state']['count']];
        $run['status'] = 'failed';
        $run['error'] = Oyiso_Agent_Config::error($message);
        Oyiso_Agent_Store::event($run, 'error', '任务已停止', $run['error']);
        Oyiso_Agent_Store::save($run);
    }
}
