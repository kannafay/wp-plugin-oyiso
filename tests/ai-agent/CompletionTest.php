<?php

declare(strict_types=1);

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionResponse;

final class CompletionTest extends TestCase
{
    private array $options;
    private array $tasks = [];

    protected function setUp(): void
    {
        wp_set_current_user(OYISO_AGENT_TEST_ADMIN);
        $this->options = Oyiso_Agent_Config::options();
        update_option('oyiso', array_merge($this->options, ['agent_enabled' => true, 'agent_max_steps' => '8', 'agent_plugin_tools_version' => '2', 'agent_mcp_namespaces' => ['oyiso-wordpress', Oyiso_Agent_MCP::BUILTIN_NAMESPACE], 'agent_write_tools' => []]));
    }

    protected function tearDown(): void
    {
        wp_set_current_user(OYISO_AGENT_TEST_ADMIN); Oyiso_Agent_Abilities::bind(null);
        foreach ($this->tasks as $id) {
            wp_clear_scheduled_hook(Oyiso_Agent_Runner::HOOK, [$id]);
            as_unschedule_all_actions(Oyiso_Agent_Runner::HOOK, [$id], 'oyiso-agent');
            wp_delete_post($id, true);
        }
        update_option('oyiso', $this->options);
    }

    private function task(string $text = '统计处理中'): array
    {
        $run = Oyiso_Agent_Store::create('Completion fixture'); self::assertIsArray($run); $this->tasks[] = $run['id'];
        Oyiso_Agent_Runner::message($run, $text);
        return $run;
    }

    private function turn(string $text = '', ?string $tool = null, array $args = []): Oyiso_Agent_Turn
    {
        $parts = $text !== '' ? [new MessagePart($text)] : [];
        if ($tool !== null) $parts[] = new MessagePart(new FunctionCall(wp_generate_uuid4(), WP_AI_Client_Ability_Function_Resolver::ability_name_to_function_name('oyiso/' . $tool), $args));
        return new Oyiso_Agent_Turn(new ModelMessage($parts), 40, 'fixture-model');
    }

    public function testPromiseOnlyResponseContinuesThroughDiscoveryAndQueryToARealAnswer(): void
    {
        $run = $this->task();
        $promise = '我先确认一下可用的订单数据源，然后再给你统计“处理中”的订单数量。';
        $count = wc_orders_count('processing'); $answer = '当前有 ' . $count . ' 个处理中订单。';
        $model = new OyisoAgentScriptedModel([
            $this->turn($promise), $this->turn('', 'list-data-sources'), $this->turn('', 'describe-data-source', ['source' => 'orders']),
            $this->turn('', 'query-data', ['source' => 'orders', 'where' => ['field' => 'status', 'op' => 'eq', 'value' => 'processing'], 'metrics' => [['op' => 'count', 'as' => 'orders']]]),
            $this->turn($answer),
        ], [], [new Oyiso_Agent_Completion('continue', '只有查询计划，尚未取得统计。', 7), new Oyiso_Agent_Completion('complete', '已有完整查询结果。', 9)]);
        $runner = new Oyiso_Agent_Runner($model);
        $runner->step($run['id']); $first = Oyiso_Agent_Store::get($run['id']);
        self::assertSame('queued', $first['status']); self::assertCount(1, $first['conversation']); self::assertSame(1, $first['completion_retries']);
        self::assertStringContainsString($promise, wp_json_encode($first['messages'], JSON_UNESCAPED_UNICODE));
        for ($i = 0; $i < 4; $i++) $runner->step($run['id']);
        $final = Oyiso_Agent_Store::get($run['id']);
        self::assertSame('completed', $final['status']); self::assertCount(2, $final['conversation']);
        self::assertSame($answer, $final['conversation'][1]['text']);
        self::assertSame(216, $final['tokens']); self::assertSame(2, $final['executions'][0]['review_calls']);
        $evidence = json_decode($model->reviewSources[1], true);
        self::assertSame(['wpab__oyiso__list-data-sources', 'wpab__oyiso__describe-data-source', 'wpab__oyiso__query-data'], array_column($evidence['current_turn_results'], 'name'));
        self::assertTrue(json_decode($evidence['current_turn_results'][2]['result'], true)['complete']);
        self::assertSame($count, json_decode($evidence['current_turn_results'][2]['result'], true)['rows'][0]['orders']);
        self::assertArrayNotHasKey('completion_retries', $final);
    }

    public function testRepeatedPlansStopAsFailedInsteadOfClaimingCompletion(): void
    {
        $run = $this->task();
        $model = new OyisoAgentScriptedModel(array_fill(0, 3, $this->turn('接下来查询。')), [], array_fill(0, 3, new Oyiso_Agent_Completion('continue', '仍未实际执行。', 5)));
        $runner = new Oyiso_Agent_Runner($model);
        for ($i = 0; $i < 3; $i++) $runner->step($run['id']);
        $fresh = Oyiso_Agent_Store::get($run['id']);
        self::assertSame('failed', $fresh['status']); self::assertCount(1, $fresh['conversation']);
        self::assertSame(3, $fresh['step']); self::assertSame(135, $fresh['tokens']); self::assertStringContainsString('连续未完成', $fresh['error']);
        Oyiso_Agent_Runner::message($fresh, '换个问题');
        self::assertArrayNotHasKey('completion_retries', $fresh); self::assertArrayNotHasKey('completion_state', $fresh);
    }

    public function testRetiredToolRecoversEvenWhenReviewerMistakesItForAPermissionBlock(): void
    {
        $run = $this->task(); $count = wc_orders_count('processing');
        $falseAnswer = '当前订单统计工具已被拒绝，请管理员开放查询权限。';
        $model = new OyisoAgentScriptedModel([
            $this->turn('', 'order-summary', ['start_date' => '2026-01-01', 'end_date' => '2026-11-05']),
            $this->turn($falseAnswer), $this->turn('', 'list-data-sources'),
            $this->turn('', 'describe-data-source', ['source' => 'orders']),
            $this->turn('', 'query-data', ['source' => 'orders', 'where' => ['field' => 'status', 'op' => 'eq', 'value' => 'processing'], 'metrics' => [['op' => 'count', 'as' => 'orders']]]),
            $this->turn('当前有 ' . $count . ' 个处理中订单。'),
        ], [], [new Oyiso_Agent_Completion('blocked', 'Fixture reproduces the incorrect permission conclusion.', 7)]);
        $runner = new Oyiso_Agent_Runner($model); $runner->step($run['id']);
        $rejected = Oyiso_Agent_Store::get($run['id']);
        $response = $rejected['messages'][2]['parts'][0]['functionResponse']['response'];
        self::assertSame('agent_tool_replaced', $response['code']);
        self::assertSame('select_replacement', $response['recovery']['action']);
        self::assertSame(['wpab__oyiso__list-data-sources', 'wpab__oyiso__describe-data-source', 'wpab__oyiso__query-data'], $response['recovery']['tools']);
        self::assertArrayNotHasKey('total_orders', $response); self::assertSame('oyiso/order-summary', $rejected['tool_recovery']);
        $runner->step($run['id']); $continued = Oyiso_Agent_Store::get($run['id']);
        self::assertSame('queued', $continued['status']); self::assertCount(1, $continued['conversation']);
        self::assertSame(87, $continued['tokens']); self::assertTrue($continued['messages'][4]['oyiso_internal']);
        for ($step = 0; $step < 4; $step++) $runner->step($run['id']);
        $final = Oyiso_Agent_Store::get($run['id']);
        self::assertSame('completed', $final['status']); self::assertCount(2, $final['conversation']);
        self::assertSame('当前有 ' . $count . ' 个处理中订单。', $final['conversation'][1]['text']);
        self::assertArrayNotHasKey('tool_recovery', $final); self::assertSame(247, $final['tokens']);
        self::assertNotContains('oyiso/order-summary', $model->allowed);
        $results = json_decode($model->reviewSources[1], true)['current_turn_results'];
        self::assertTrue(json_decode($results[3]['result'], true)['complete']);
        self::assertSame($count, json_decode($results[3]['result'], true)['rows'][0]['orders']);
    }

    public function testRetiredReadsShareMigrationFeedbackWithoutGrantingDisabledAccess(): void
    {
        $allowed = Oyiso_Agent_Abilities::allowed();
        foreach (Oyiso_Agent_Query::LEGACY as $tool) {
            $name = 'oyiso/' . $tool;
            $error = Oyiso_Agent_Tool_Feedback::unavailable($name, $allowed);
            self::assertSame('agent_tool_replaced', $error->get_error_code());
            self::assertCount(3, Oyiso_Agent_Tool_Feedback::result($error, $name, $allowed)['recovery']['tools']);
            self::assertSame([], Oyiso_Agent_Tool_Feedback::replacements($name, ['oyiso/get-current-page']));
        }
        update_option('oyiso', array_merge(Oyiso_Agent_Config::options(), ['agent_mcp_namespaces' => ['oyiso-wordpress']]));
        $allowed = Oyiso_Agent_Abilities::allowed();
        self::assertContains('oyiso/query-data', $allowed, 'WordPress content queries remain available.');
        $error = Oyiso_Agent_Tool_Feedback::unavailable('oyiso/order-summary', $allowed);
        self::assertSame('agent_tool_unavailable', $error->get_error_code());
        self::assertSame([], Oyiso_Agent_Tool_Feedback::replacements('oyiso/order-summary', $allowed));
    }

    public function testIgnoringReplacementFeedbackIsBoundedAndCannotClaimCompletion(): void
    {
        foreach (['complete', 'needs_input', 'blocked'] as $decision) {
            $run = $this->task();
            $model = new OyisoAgentScriptedModel([
                $this->turn('', 'order-summary'), $this->turn('请开放权限。'), $this->turn('请开放权限。'), $this->turn('请开放权限。'),
            ], [], array_fill(0, 3, new Oyiso_Agent_Completion($decision, 'Incorrect fixture conclusion.')));
            $runner = new Oyiso_Agent_Runner($model);
            for ($step = 0; $step < 4; $step++) $runner->step($run['id']);
            $final = Oyiso_Agent_Store::get($run['id']);
            self::assertSame('failed', $final['status']); self::assertCount(1, $final['conversation']);
            self::assertStringContainsString('旧工具已替换', $final['error']);
            Oyiso_Agent_Runner::message($final, '新的请求'); self::assertArrayNotHasKey('tool_recovery', $final);
        }
    }

    public function testRecoveryRechecksPermissionsAndDoesNotOverrideActualDenial(): void
    {
        $run = $this->task();
        $model = new OyisoAgentScriptedModel([$this->turn('', 'order-summary'), $this->turn('订单查询已被禁用。')], [], [new Oyiso_Agent_Completion('blocked', 'WooCommerce access really was revoked.')]);
        $runner = new Oyiso_Agent_Runner($model); $runner->step($run['id']);
        update_option('oyiso', array_merge(Oyiso_Agent_Config::options(), ['agent_mcp_namespaces' => ['oyiso-wordpress']]));
        $runner->step($run['id']); $final = Oyiso_Agent_Store::get($run['id']);
        self::assertSame('blocked', $final['status']); self::assertCount(2, $final['conversation']);
        $error = new WP_Error('ability_invalid_permissions', 'Access denied', ['private' => 'must-not-leak']);
        self::assertSame(['error' => 'Access denied', 'code' => 'ability_invalid_permissions'], Oyiso_Agent_Tool_Feedback::result($error, 'oyiso/query-data', Oyiso_Agent_Abilities::allowed()));
    }

    public function testParameterErrorsIncludeCurrentSchemaWithoutBeingReportedAsPermissionFailures(): void
    {
        $run = $this->task();
        $malformed = new Oyiso_Agent_Turn(new ModelMessage([new MessagePart(new FunctionCall('null-args', 'wpab__oyiso__describe-data-source', null))]), 40, 'fixture-model');
        $model = new OyisoAgentScriptedModel([
            $malformed, $this->turn('', 'describe-data-source'),
            $this->turn('', 'query-data', ['source' => 'orders', 'where' => ['field' => 'nonexistent', 'op' => 'eq', 'value' => 'x']]),
            $this->turn('', 'describe-data-source', ['source' => 'orders']),
        ]);
        $runner = new Oyiso_Agent_Runner($model);
        foreach (['agent_tool_arguments', 'ability_invalid_input', 'agent_query_invalid'] as $code) {
            $runner->step($run['id']); $fresh = Oyiso_Agent_Store::get($run['id']);
            $response = end($fresh['messages'])['parts'][0]['functionResponse']['response'];
            self::assertSame($code, $response['code']); self::assertSame('correct_arguments', $response['recovery']['action']);
            self::assertSame('object', $response['recovery']['input_schema']['type']);
            self::assertArrayNotHasKey('tool_recovery', $fresh);
        }
        $runner->step($run['id']); $fresh = Oyiso_Agent_Store::get($run['id']);
        self::assertArrayHasKey('fields', end($fresh['messages'])['parts'][0]['functionResponse']['response']);
        $invalid = new WP_Error('ability_invalid_input', 'Invalid input', ['private' => 'must-not-leak']);
        self::assertArrayNotHasKey('recovery', Oyiso_Agent_Tool_Feedback::result($invalid, 'oyiso/apply-product-update', Oyiso_Agent_Abilities::allowed()));
    }

    public function testInternalContinuationDoesNotReplaceTheUserRequestDuringCompaction(): void
    {
        $run = $this->task();
        $model = new OyisoAgentScriptedModel([$this->turn('稍后查询。')], [], [new Oyiso_Agent_Completion('continue', '缺少结果。')]);
        (new Oyiso_Agent_Runner($model))->step($run['id']); $fresh = Oyiso_Agent_Store::get($run['id']);
        self::assertTrue($fresh['messages'][2]['oyiso_internal']);
        $fresh['context_memory'] = ['cursor' => 2, 'summary' => 'History summary', 'count' => 1];
        $history = Oyiso_Agent_Compaction::history($fresh);
        self::assertSame('统计处理中', $history[1]['parts'][0]['text']);
    }

    public function testChatQuestionsAndBlockedTasksHaveExplicitTerminalStates(): void
    {
        foreach ([['你好', '你好。', 'complete', 'completed'], ['解释工具调用', '工具调用用于读取或处理外部数据。', 'complete', 'completed'], ['修改指定商品', '请提供要修改的商品 ID。', 'needs_input', 'awaiting_input'], ['查询外部服务', '当前没有该服务的访问权限。', 'blocked', 'blocked']] as [$request, $answer, $state, $expected]) {
            $run = $this->task($request);
            $model = new OyisoAgentScriptedModel([$this->turn($answer)], [], [new Oyiso_Agent_Completion($state, 'Fixture decision.')]);
            (new Oyiso_Agent_Runner($model))->step($run['id']); $fresh = Oyiso_Agent_Store::get($run['id']);
            self::assertSame($expected, $fresh['status']); self::assertSame($answer, $fresh['conversation'][1]['text']);
            self::assertSame(1, $model->calls); self::assertNotContains('调用工具', array_column($fresh['events'], 'label'));
        }
    }

    public function testCancelledOrInvalidReviewsNeverCompleteTheTask(): void
    {
        foreach (['cancel', 'error', 'unknown'] as $case) {
            $run = $this->task();
            $review = match ($case) {
                'cancel' => static function () use ($run): Oyiso_Agent_Completion { Oyiso_Agent_Store::cancel($run['id']); return new Oyiso_Agent_Completion('complete', 'Cancelled during check.'); },
                'error' => new WP_Error('review_failed', 'Invalid structured response.', ['tokens' => 12]),
                default => new Oyiso_Agent_Completion('unknown', 'Invalid state.'),
            };
            $model = new OyisoAgentScriptedModel([$this->turn('候选结果。')], [], [$review]);
            (new Oyiso_Agent_Runner($model))->step($run['id']); $fresh = Oyiso_Agent_Store::get($run['id']);
            self::assertSame($case === 'cancel' ? 'cancelled' : 'failed', $fresh['status']); self::assertCount(1, $fresh['conversation']);
            self::assertArrayNotHasKey('completion_state', $fresh);
            if ($case === 'error') self::assertSame(52, $fresh['tokens']);
        }
    }

    public function testReviewerIsRequiredAndItsStateIsVisibleDuringStreaming(): void
    {
        $run = $this->task();
        $gateway = new class($this->turn('Candidate')) implements Oyiso_Agent_Model_Gateway {
            public function __construct(private Oyiso_Agent_Turn $turn) {}
            public function generate(array $history, array $abilities, ?string $selection = null, ?Closure $onText = null): Oyiso_Agent_Turn|WP_Error { return $this->turn; }
        };
        (new Oyiso_Agent_Runner($gateway))->step($run['id']);
        self::assertSame('failed', Oyiso_Agent_Store::get($run['id'])['status']);
        $next = $this->task('你好'); $states = [];
        (new Oyiso_Agent_Runner(new OyisoAgentScriptedModel([$this->turn('你好。')])))->step($next['id'], static function (): void {}, static function (array $state) use (&$states): void { $states[] = $state; });
        self::assertSame('reviewing', $states[0]['completion_state']); self::assertSame('running', $states[0]['status']);
        self::assertSame('你好。', $states[0]['partial']['text']); self::assertCount(1, $states[0]['conversation']);
    }

    public function testStructuredDecisionsAreValidatedWithoutAcceptingToolCallsOrFreeText(): void
    {
        foreach (['complete', 'continue', 'needs_input', 'blocked'] as $state) {
            $result = Oyiso_Agent_Completion::parse($this->turn(wp_json_encode(['state' => $state, 'reason' => 'Checked'])));
            self::assertInstanceOf(Oyiso_Agent_Completion::class, $result); self::assertSame($state, $result->state); self::assertSame(40, $result->tokens);
        }
        self::assertInstanceOf(Oyiso_Agent_Completion::class, Oyiso_Agent_Completion::parse($this->turn("```json\n{\"state\":\"complete\",\"reason\":\"done\"}\n```")));
        foreach (['looks done', '{bad json', '{"state":"anything","reason":"ok"}', '{"state":"complete"}', '{"state":"complete","reason":123}', wp_json_encode(['state' => 'complete', 'reason' => str_repeat('x', 501)])] as $bad) {
            $result = Oyiso_Agent_Completion::parse($this->turn($bad)); self::assertWPError($result); self::assertSame(40, $result->get_error_data()['tokens']);
        }
        self::assertWPError(Oyiso_Agent_Completion::parse($this->turn('', 'get-current-page')));
    }

    public function testReviewEvidenceSeparatesCurrentResultsFromOldClaimsAndKeepsApprovalStates(): void
    {
        $run = $this->task('旧问题');
        $run['messages'][] = (new UserMessage([new MessagePart(new FunctionResponse('old', 'old_tool', ['old_evidence' => true]))]))->toArray();
        $run['conversation'][] = ['role' => 'assistant', 'text' => '以前的结论']; $run['status'] = 'completed';
        Oyiso_Agent_Runner::message($run, '当前问题');
        $run['messages'][] = (new UserMessage([new MessagePart(new FunctionResponse('current', 'current_tool', ['error' => 'Access denied', 'large' => str_repeat('x', 3500)]))]))->toArray();
        $run['changes'] = [['id' => 'old', 'product_id' => 1, 'name' => 'Old', 'before' => [], 'after' => [], 'status' => 'pending', 'message_index' => 1], ['id' => 'new', 'product_id' => 2, 'name' => 'New', 'before' => [], 'after' => [], 'status' => 'pending', 'message_index' => 3]];
        $source = json_decode(Oyiso_Agent_Completion::source($run, '回复候选', ['oyiso/get-current-page', 'nonexistent/tool']), true);
        self::assertSame('当前问题', $source['request']); self::assertCount(1, $source['current_turn_results']);
        self::assertSame('current_tool', $source['current_turn_results'][0]['name']); self::assertTrue($source['current_turn_results'][0]['truncated']);
        self::assertSame([['id' => 'new', 'status' => 'pending']], $source['operations']); self::assertCount(1, $source['available_tools']);
        self::assertStringNotContainsString('old_evidence', wp_json_encode($source));
    }

    private static function assertWPError(mixed $value): void { self::assertInstanceOf(WP_Error::class, $value); }
}
