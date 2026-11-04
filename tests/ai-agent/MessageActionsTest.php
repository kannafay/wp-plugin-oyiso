<?php

declare(strict_types=1);

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Tools\DTO\FunctionCall;

final class MessageActionsTest extends TestCase
{
    private array $saved = [];
    private array $tasks = [];
    private string $selection;

    protected function setUp(): void
    {
        wp_set_current_user(OYISO_AGENT_TEST_ADMIN);
        foreach (['oyiso', 'oyiso_agent_connections', 'oyiso_agent_secrets'] as $key) $this->saved[$key] = get_option($key, false);
        update_option('oyiso', array_merge(Oyiso_Agent_Config::options(), ['agent_enabled' => true, 'agent_max_steps' => '8', 'agent_write_tools' => []]));
        delete_option('oyiso_agent_connections');
        $connection = Oyiso_Agent_Connections::save(['name' => 'Message actions fixture', 'preset' => 'custom', 'url' => 'https://api.example.com/v1', 'protocol' => 'openai_responses', 'models' => ['fixture-model'], 'key' => 'fixture-key']);
        self::assertIsArray($connection);
        $this->selection = $connection['id'] . '|fixture-model';
    }

    protected function tearDown(): void
    {
        wp_set_current_user(OYISO_AGENT_TEST_ADMIN);
        foreach ($this->tasks as $id) {
            wp_clear_scheduled_hook(Oyiso_Agent_Runner::HOOK, [$id]);
            as_unschedule_all_actions(Oyiso_Agent_Runner::HOOK, [$id], 'oyiso-agent');
            Oyiso_Agent_Store::unlock('run_' . $id);
            wp_delete_post($id, true);
        }
        foreach ($this->saved as $key => $value) { if ($value === false) delete_option($key); else update_option($key, $value); }
    }

    private function history(): array
    {
        $run = Oyiso_Agent_Store::create('First request', $this->selection);
        self::assertIsArray($run); $this->tasks[] = $run['id'];
        $model = new OyisoAgentScriptedModel([
            new Oyiso_Agent_Turn(new ModelMessage([new MessagePart(new FunctionCall('fixture-call', WP_AI_Client_Ability_Function_Resolver::ability_name_to_function_name('oyiso/get-current-page'), []))]), 10, 'fixture-model'),
            new Oyiso_Agent_Turn(new ModelMessage([new MessagePart('First answer')]), 20, 'fixture-model'),
            new Oyiso_Agent_Turn(new ModelMessage([new MessagePart('Second answer')]), 30, 'fixture-model'),
            new Oyiso_Agent_Turn(new ModelMessage([new MessagePart('Third answer')]), 40, 'fixture-model'),
        ]);
        $runner = new Oyiso_Agent_Runner($model);
        foreach (['First request', 'Second request', 'Third request'] as $index => $message) {
            Oyiso_Agent_Runner::message($run, $message); $runner->step($run['id']);
            if ($index === 0) $runner->step($run['id']);
            $run = Oyiso_Agent_Store::get($run['id']);
        }
        self::assertSame('completed', $run['status']);
        return $run;
    }

    private function request(array $run, int $index, string $action, array $extra = []): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/oyiso-agent/v1/runs/' . $run['id'] . '/messages/' . $index);
        $request->set_header('Accept', 'text/event-stream');
        foreach ($extra + ['action' => $action, 'revision' => Oyiso_Agent_Store::view($run)['revision'], 'selection' => $this->selection] as $key => $value) $request->set_param($key, $value);
        return rest_get_server()->dispatch($request);
    }

    public function testEditingRewindsAtTheUserBoundaryAndClearsRemovedCompaction(): void
    {
        $run = $this->history();
        $first = $run['executions'][0]; $prefix = array_slice($run['messages'], 0, 4);
        $run['context_memory'] = ['cursor' => count($run['messages']), 'summary' => 'Removed branch summary', 'count' => 2];
        $run['context_usage'] = ['selection' => $this->selection, 'input' => 5000, 'output' => 200];
        $run['context_state'] = ['phase' => 'completed', 'count' => 2];
        Oyiso_Agent_Store::save($run);
        $response = $this->request($run, 2, 'edit', ['message' => 'Edited second request']);
        self::assertSame(200, $response->get_status());
        $fresh = Oyiso_Agent_Store::get($run['id']);
        self::assertSame(['First request', 'First answer', 'Edited second request'], array_column($fresh['conversation'], 'text'));
        self::assertSame($prefix, array_slice($fresh['messages'], 0, 4));
        self::assertSame($first, $fresh['executions'][0]);
        self::assertSame([0, 2], array_column($fresh['executions'], 'index'));
        self::assertSame(0, $fresh['executions'][1]['tokens']); self::assertSame($run['tokens'], $fresh['tokens']);
        foreach (['context_memory', 'context_usage', 'context_state', 'partial'] as $key) self::assertArrayNotHasKey($key, $fresh);
        self::assertArrayNotHasKey('messages', $response->get_data());
        $model = new OyisoAgentScriptedModel([new Oyiso_Agent_Turn(new ModelMessage([new MessagePart('New answer')]), 15, 'fixture-model')]);
        (new Oyiso_Agent_Runner($model))->step($run['id']);
        self::assertStringNotContainsString('Second answer', wp_json_encode($model->histories));
        self::assertStringNotContainsString('Third request', wp_json_encode($model->histories));
        $completed = Oyiso_Agent_Store::get($run['id']);
        self::assertSame($first, $completed['executions'][0]); self::assertSame(15, $completed['executions'][1]['tokens']);
    }

    public function testRegenerationRetainsAttachmentsAndUsesLatestPageContext(): void
    {
        $run = $this->history();
        $files = Oyiso_Agent_Attachments::save($run['id'], [['name' => 'fixture.txt', 'mime' => 'text/plain', 'size' => 9, 'content' => 'Reference']]);
        self::assertIsArray($files); $run['conversation'][2]['attachments'] = $files; Oyiso_Agent_Store::save($run);
        $context = ['screen' => 'edit-shop_order', 'post_id' => 0, 'label' => 'Orders', 'signature' => wp_create_nonce('oyiso-agent-context:edit-shop_order:0')];
        self::assertSame(200, $this->request($run, 3, 'regenerate', ['page_context' => wp_json_encode($context)])->get_status());
        $fresh = Oyiso_Agent_Store::get($run['id']);
        self::assertSame($files, $fresh['conversation'][2]['attachments']);
        self::assertSame(array_column($files, 'id'), $fresh['messages'][4]['oyiso_attachments']);
        self::assertSame('edit-shop_order', $fresh['page_context']['screen']);
        self::assertSame('Second request', $fresh['conversation'][2]['text']);
        self::assertCount(3, $fresh['conversation']);
    }

    public function testRemovedApprovalsAreCancelledAndAppliedRecordsRemain(): void
    {
        $run = $this->history();
        $change = ['id' => 'pending-old', 'product_id' => 123, 'name' => 'Fixture', 'before' => [], 'after' => [], 'status' => 'pending', 'message_index' => 3];
        $run['changes'] = [$change, array_replace($change, ['id' => 'applied-old', 'status' => 'applied', 'message_index' => 5]), array_replace($change, ['id' => 'earlier-pending', 'message_index' => 1])];
        $run['mcp_actions'] = [['id' => 'pending-tool', 'name' => 'fixture/write', 'label' => 'Fixture', 'input_json' => '{}', 'status' => 'pending', 'result' => '', 'message_index' => 3]];
        Oyiso_Agent_Store::save($run);
        self::assertSame(200, $this->request($run, 3, 'regenerate')->get_status());
        $fresh = Oyiso_Agent_Store::get($run['id']);
        self::assertSame(['cancelled', 'applied', 'pending'], array_column($fresh['changes'], 'status'));
        self::assertSame([-1, -1, 1], array_column($fresh['changes'], 'message_index'));
        self::assertSame('cancelled', $fresh['mcp_actions'][0]['status']);
        self::assertStringContainsString('applied-old', $fresh['messages'][4]['parts'][0]['text']);
        self::assertStringNotContainsString('pending-old', $fresh['messages'][4]['parts'][0]['text']);
        self::assertSame('Second request', $fresh['messages'][5]['parts'][0]['text']);
    }

    public function testLegacyHistorySkipsInternalUserMessagesAndHandlesRepeatedPrompts(): void
    {
        $run = $this->history();
        foreach ($run['messages'] as &$message) unset($message['oyiso_user_index']); unset($message);
        array_splice($run['messages'], 4, 0, [(new UserMessage([new MessagePart('Internal tool result continuation')]))->toArray()]);
        $run['conversation'][4]['text'] = 'Second request'; $run['messages'][7]['parts'][0]['text'] = 'Second request';
        Oyiso_Agent_Store::save($run);
        self::assertSame(200, $this->request($run, 5, 'regenerate')->get_status());
        $fresh = Oyiso_Agent_Store::get($run['id']);
        self::assertCount(5, $fresh['conversation']); self::assertCount(8, $fresh['messages']);
        self::assertSame('Internal tool result continuation', $fresh['messages'][4]['parts'][0]['text']);
        self::assertSame('Second answer', $fresh['messages'][6]['parts'][0]['text']);
    }

    public function testMessageActionsRejectStaleBusyInvalidAndUnauthorizedRequests(): void
    {
        $run = $this->history();
        foreach ([[1, 'edit', ['message' => 'Edit assistant']], [0, 'regenerate', []], [50, 'edit', ['message' => 'Missing']], [0, 'edit', ['message' => '   ']], [0, 'edit', ['message' => str_repeat('x', 4001)]]] as [$index, $action, $extra]) {
            self::assertSame(400, $this->request($run, $index, $action, $extra)->get_status());
        }
        self::assertSame(409, $this->request($run, 1, 'regenerate', ['revision' => str_repeat('0', 64)])->get_status());
        Oyiso_Agent_Store::lock('run_' . $run['id']);
        self::assertSame(409, $this->request($run, 1, 'regenerate')->get_status()); Oyiso_Agent_Store::unlock('run_' . $run['id']);
        $run['status'] = 'running'; Oyiso_Agent_Store::save($run);
        self::assertSame(409, $this->request($run, 1, 'regenerate')->get_status());
        $run['status'] = 'completed'; Oyiso_Agent_Store::save($run);
        $original = get_post($run['id'])->post_author;
        wp_update_post(['ID' => $run['id'], 'post_author' => 0]);
        self::assertSame(404, $this->request($run, 1, 'regenerate')->get_status());
        wp_update_post(['ID' => $run['id'], 'post_author' => $original]);
        wp_set_current_user(0); self::assertSame(401, $this->request($run, 1, 'regenerate')->get_status()); wp_set_current_user(OYISO_AGENT_TEST_ADMIN);
        self::assertSame($run['conversation'], Oyiso_Agent_Store::get($run['id'])['conversation']);
        self::assertSame(200, $this->request($run, 1, 'regenerate')->get_status());
        self::assertSame(409, $this->request($run, 1, 'regenerate')->get_status());
    }

    public function testStoppedConversationCanRestartAndFirstMessageUpdatesItsTitle(): void
    {
        $run = $this->history(); Oyiso_Agent_Store::cancel($run['id']); $run = Oyiso_Agent_Store::get($run['id']);
        self::assertSame(200, $this->request($run, 0, 'edit', ['message' => 'Changed first request'])->get_status());
        self::assertFalse(Oyiso_Agent_Store::cancelled($run['id']));
        $fresh = Oyiso_Agent_Store::get($run['id']);
        self::assertSame('queued', $fresh['status']); self::assertCount(1, $fresh['conversation']); self::assertCount(1, $fresh['executions']);
        self::assertSame('Changed first request', get_post($run['id'])->post_title);
    }
}
