<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Tools\DTO\FunctionResponse;

/** @phpstan-import-type Run from Oyiso_Agent_Store */
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

    /** @param Run $run */
    public static function message(array &$run, string $text): void
    {
        unset($run['partial']);
        $run['messages'][] = (new UserMessage([new MessagePart($text)]))->toArray();
        $run['conversation'][] = ['role' => 'user', 'text' => $text];
        $run['step'] = 0;
        $run['status'] = 'queued';
        $run['error'] = '';
        Oyiso_Agent_Store::event($run, 'info', '已接收任务');
        Oyiso_Agent_Store::save($run);
    }

    public function step(int $id): void
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
            if ($run['step'] >= Oyiso_Agent_Config::maxSteps() || $run['tokens'] >= 60000) {
                $this->fail($run, '已达到执行轮次或对话用量上限。请缩小范围，或新建对话继续。');
                return;
            }
            $run['status'] = 'running';
            unset($run['partial']);
            $run['step']++;
            Oyiso_Agent_Store::event($run, 'info', '正在分析任务', '第 ' . $run['step'] . ' 轮');
            Oyiso_Agent_Store::save($run);
            $checkpoint = $run;
            $allowed = Oyiso_Agent_Abilities::allowed();
            $savedAt = 0.0;
            $run['partial'] = ['text' => '', 'model' => explode('|', $run['selection'] ?? '|AI', 2)[1] ?? 'AI'];
            $turn = $this->model->generate($run['messages'], $allowed, $run['selection'] ?? null, static function (string $text) use (&$run, &$savedAt, $id): void {
                if (Oyiso_Agent_Store::cancelled($id)) throw new RuntimeException('任务已取消。');
                if ($text === '') return;
                $run['partial']['text'] .= $text;
                if (microtime(true) - $savedAt >= 0.25) { Oyiso_Agent_Store::save($run); $savedAt = microtime(true); }
            });
            if (Oyiso_Agent_Store::cancelled($id)) {
                Oyiso_Agent_Store::save($run);
                return;
            }
            if (is_wp_error($turn)) {
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
            unset($run['partial']);
            $run['messages'][] = $turn->message->toArray();
            if (trim($text) !== '') {
                $run['conversation'][] = ['role' => 'assistant', 'text' => $text, 'model' => $turn->model];
            }
            Oyiso_Agent_Store::event($run, 'info', '模型已响应', $turn->model);
            $tools = new Oyiso_Agent_Tools($run);
            Oyiso_Agent_Abilities::bind($tools);
            $responses = [];
            $proposalsBefore = count($run['changes']);
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
            if ($responses !== []) {
                $run['messages'][] = (new UserMessage($responses))->toArray();
            }
            $pending = count(array_filter($run['changes'], static fn(array $change): bool => $change['status'] === 'pending')) > 0;
            if (count($run['changes']) > $proposalsBefore) {
                $run['status'] = 'waiting';
                $run['conversation'][] = ['role' => 'assistant', 'text' => '修改方案已生成。请查看前后对照，再选择是否应用。', 'model' => $turn->model];
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
            if ($run['status'] === 'queued' && !Oyiso_Agent_Store::cancelled($id)) {
                self::enqueue($id);
            }
        } catch (Throwable $error) {
            if (isset($run) && is_array($run)) {
                if (Oyiso_Agent_Store::cancelled($id)) { Oyiso_Agent_Store::save($run); return; }
                if (isset($checkpoint)) {
                    $checkpoint['events'] = $run['events'];
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

    /** @param Run $run */
    private function fail(array $run, string $message): void
    {
        $run['status'] = 'failed';
        $run['error'] = Oyiso_Agent_Config::error($message);
        Oyiso_Agent_Store::event($run, 'error', '任务已停止', $run['error']);
        Oyiso_Agent_Store::save($run);
    }
}
