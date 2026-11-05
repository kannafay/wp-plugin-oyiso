<?php

declare(strict_types=1);

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;

final class OrderUpdateTest extends TestCase
{
    private array $options;
    private array $tasks = [];
    private array $orders = [];

    protected function setUp(): void
    {
        wp_set_current_user(OYISO_AGENT_TEST_ADMIN);
        $this->options = Oyiso_Agent_Config::options();
        update_option('oyiso', array_merge($this->options, ['agent_enabled' => true, 'agent_plugin_tools_version' => '2', 'agent_mcp_namespaces' => ['oyiso-wordpress', Oyiso_Agent_MCP::BUILTIN_NAMESPACE], 'agent_write_tools' => [Oyiso_Agent_Order_Update::TOOL]]));
    }

    protected function tearDown(): void
    {
        wp_set_current_user(OYISO_AGENT_TEST_ADMIN); Oyiso_Agent_Abilities::bind(null);
        foreach ($this->tasks as $id) { wp_clear_scheduled_hook(Oyiso_Agent_Runner::HOOK, [$id]); as_unschedule_all_actions(Oyiso_Agent_Runner::HOOK, [$id], 'oyiso-agent'); wp_delete_post($id, true); }
        foreach ($this->orders as $id) { $order = wc_get_order($id); if ($order) $order->delete(true); }
        update_option('oyiso', $this->options);
    }

    private function task(): array
    {
        $run = Oyiso_Agent_Store::create('Order update fixture'); self::assertIsArray($run); $this->tasks[] = $run['id'];
        Oyiso_Agent_Runner::message($run, '把刚列出的订单改成已完成');
        return $run;
    }

    private function order(string $status = 'processing'): int
    {
        $order = wc_create_order(['status' => $status]); self::assertInstanceOf(WC_Order::class, $order);
        $order->set_total('10'); $order->set_created_via('oyiso-order-update-fixture'); $order->save();
        return $this->orders[] = $order->get_id();
    }

    private function tool(array &$run, string $name, array $input): mixed
    {
        Oyiso_Agent_Abilities::bind(new Oyiso_Agent_Tools($run));
        try { return wp_get_ability('oyiso/' . $name)->execute($input); }
        finally { Oyiso_Agent_Abilities::bind(null); }
    }

    private function plan(array &$run, array $ids, string $status = 'completed'): string
    {
        self::assertIsArray($this->tool($run, 'get-orders', ['order_ids' => $ids]));
        $plan = $this->tool($run, 'prepare-order-update', ['order_ids' => $ids, 'status' => $status]);
        self::assertIsArray($plan, is_wp_error($plan) ? $plan->get_error_message() : '');
        $run['status'] = 'waiting'; Oyiso_Agent_Store::save($run);
        return $plan['action_id'];
    }

    private function confirm(array $run, string $id, string $decision = 'execute'): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/oyiso-agent/v1/runs/' . $run['id'] . '/mcp/' . $id);
        $request->set_param('action', $decision);
        return rest_get_server()->dispatch($request);
    }

    public function testExactBatchIsPreviewedWithoutWritesAndConfirmedOnce(): void
    {
        $run = $this->task(); $ids = [$this->order(), $this->order()];
        $plan = $this->plan($run, $ids); $outside = $this->order();
        foreach ($ids as $id) self::assertSame('processing', wc_get_order($id)->get_status());
        self::assertInstanceOf(WP_Error::class, Oyiso_Agent_Order_Update::apply($run, $plan));
        $view = Oyiso_Agent_Store::view($run);
        self::assertSame($ids, array_column($view['mcp_actions'][0]['orders']['items'], 'id'));
        self::assertArrayNotHasKey('order_fingerprints', $view['mcp_actions'][0]);
        self::assertArrayNotHasKey('order_snapshots', $view);
        self::assertFalse(wp_get_ability(Oyiso_Agent_Order_Update::TOOL)->get_meta_item('annotations')['readonly']);
        $response = $this->confirm($run, $plan); self::assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        self::assertSame('completed', $response->get_data()['mcp_actions'][0]['status']);
        self::assertSame(['applied', 'applied'], array_column($response->get_data()['mcp_actions'][0]['orders']['items'], 'result'));
        foreach ($ids as $id) self::assertSame('completed', wc_get_order($id)->get_status());
        self::assertSame('processing', wc_get_order($outside)->get_status());
        self::assertGreaterThanOrEqual(400, $this->confirm($run, $plan)->get_status());
    }

    public function testReadBeforeWriteValidationAndNewTurnIsolation(): void
    {
        $run = $this->task(); $id = $this->order();
        self::assertInstanceOf(WP_Error::class, $this->tool($run, 'prepare-order-update', ['order_ids' => [$id], 'status' => 'completed']));
        foreach ([[], [$id, $id], [0], ['1'], range(1, 101), [999999999]] as $ids) self::assertInstanceOf(WP_Error::class, $this->tool($run, 'get-orders', ['order_ids' => $ids]));
        $read = $this->tool($run, 'get-orders', ['order_ids' => [$id]]);
        self::assertSame('processing', $read['orders'][0]['status']); self::assertArrayHasKey('completed', $read['statuses']);
        self::assertArrayNotHasKey('billing', $read['orders'][0]); self::assertTrue($read['can_update']);
        self::assertInstanceOf(WP_Error::class, $this->tool($run, 'prepare-order-update', ['order_ids' => [$id], 'status' => 'invented']));
        $plan = $this->plan($run, [$id], 'wc-completed');
        self::assertSame($plan, $this->tool($run, 'prepare-order-update', ['order_ids' => [$id], 'status' => 'completed'])['action_id']);
        Oyiso_Agent_Runner::message($run, '另一项修改');
        self::assertArrayNotHasKey('order_snapshots', $run);
        self::assertInstanceOf(WP_Error::class, $this->tool($run, 'prepare-order-update', ['order_ids' => [$id], 'status' => 'completed']));
    }

    public function testChangedOrderInvalidatesWholePreviewBeforeAnyWrite(): void
    {
        $run = $this->task(); $ids = [$this->order(), $this->order()]; $plan = $this->plan($run, $ids);
        wc_get_order($ids[1])->update_status('on-hold');
        $response = $this->confirm($run, $plan); self::assertSame(200, $response->get_status());
        self::assertSame('failed', $response->get_data()['status']);
        self::assertSame('failed', $response->get_data()['mcp_actions'][0]['status']);
        self::assertStringContainsString('本批尚未执行', $response->get_data()['mcp_actions'][0]['result']);
        self::assertSame('processing', wc_get_order($ids[0])->get_status()); self::assertSame('on-hold', wc_get_order($ids[1])->get_status());
    }

    public function testNoOpAndCustomStatusesUseRegisteredWooCommerceTransitions(): void
    {
        $custom = static fn(array $statuses): array => $statuses + ['wc-fixture-shipped' => 'Fixture shipped'];
        add_filter('wc_order_statuses', $custom);
        try {
            $run = $this->task(); $id = $this->order(); $plan = $this->plan($run, [$id], 'fixture-shipped');
            self::assertSame(200, $this->confirm($run, $plan)->get_status()); self::assertSame('fixture-shipped', wc_get_order($id)->get_status());
            $next = $this->task(); $plan = $this->plan($next, [$id], 'fixture-shipped');
            $response = $this->confirm($next, $plan);
            self::assertSame('unchanged', $response->get_data()['mcp_actions'][0]['orders']['items'][0]['result']);
        } finally { remove_filter('wc_order_statuses', $custom); }
    }

    public function testDisabledRevokedAndDismissedActionsNeverWrite(): void
    {
        $run = $this->task(); $id = $this->order(); $plan = $this->plan($run, [$id]);
        update_option('oyiso', array_merge(Oyiso_Agent_Config::options(), ['agent_write_tools' => []]));
        self::assertNotContains(Oyiso_Agent_Order_Update::TOOL, Oyiso_Agent_Abilities::allowed());
        self::assertFalse($this->tool($run, 'get-orders', ['order_ids' => [$id]])['can_update']);
        self::assertSame(403, $this->confirm($run, $plan)->get_status());
        self::assertSame(200, $this->confirm($run, $plan, 'dismiss')->get_status());
        self::assertSame('processing', wc_get_order($id)->get_status());
        update_option('oyiso', array_merge(Oyiso_Agent_Config::options(), ['agent_mcp_namespaces' => []]));
        self::assertInstanceOf(WP_Error::class, $this->tool($run, 'get-orders', ['order_ids' => [$id]]));
    }

    public function testRevocationDuringBatchStopsRemainingOrdersAndKeepsIndividualReceipts(): void
    {
        $run = $this->task(); $ids = [$this->order(), $this->order(), $this->order()]; $plan = $this->plan($run, $ids);
        $revoke = static function (int $id) use ($ids): void { if ($id === $ids[0]) update_option('oyiso', array_merge(Oyiso_Agent_Config::options(), ['agent_write_tools' => []])); };
        add_action('woocommerce_order_status_changed', $revoke);
        try { $response = $this->confirm($run, $plan); }
        finally { remove_action('woocommerce_order_status_changed', $revoke); }
        self::assertSame(200, $response->get_status());
        self::assertSame('failed', $response->get_data()['status']);
        $action = $response->get_data()['mcp_actions'][0]; self::assertSame('failed', $action['status']);
        self::assertSame('applied', $action['orders']['items'][0]['result']);
        self::assertStringContainsString('已确认修改 1 单', $action['result']);
        self::assertSame(['completed', 'processing', 'processing'], array_map(static fn(int $id): string => wc_get_order($id)->get_status(), $ids));
    }

    public function testFailureDuringBatchIsNotReportedAsAllCompletedAndIsNotRetried(): void
    {
        $run = $this->task(); $ids = [$this->order(), $this->order(), $this->order()]; $plan = $this->plan($run, $ids);
        $fail = static function (WC_Order $order) use ($ids): void { if ($order->get_id() === $ids[1] && $order->get_status() === 'completed') throw new RuntimeException('Fixture write interrupted.'); };
        add_action('woocommerce_before_order_object_save', $fail);
        try { $response = $this->confirm($run, $plan); }
        finally { remove_action('woocommerce_before_order_object_save', $fail); }
        $action = $response->get_data()['mcp_actions'][0]; self::assertSame('failed', $action['status']);
        self::assertSame('applied', $action['orders']['items'][0]['result']); self::assertSame('needs_review', $action['orders']['items'][1]['result']);
        self::assertSame('processing', $action['orders']['items'][1]['actual_status']);
        self::assertSame('processing', wc_get_order($ids[2])->get_status());
        self::assertGreaterThanOrEqual(400, $this->confirm($run, $plan)->get_status());
    }

    public function testCancellationAndConcurrentOrderLockPreventExecution(): void
    {
        $run = $this->task(); $id = $this->order(); $plan = $this->plan($run, [$id]);
        self::assertTrue(Oyiso_Agent_Store::lock('order_' . $id));
        try { $response = $this->confirm($run, $plan); self::assertSame('failed', $response->get_data()['mcp_actions'][0]['status']); }
        finally { Oyiso_Agent_Store::unlock('order_' . $id); }
        $next = $this->task(); $plan = $this->plan($next, [$id]); Oyiso_Agent_Store::cancel($next['id']);
        self::assertSame(403, $this->confirm($next, $plan)->get_status()); self::assertSame('processing', wc_get_order($id)->get_status());
    }

    public function testTrashedOrdersCannotBeRestoredThroughOrdinaryStatusUpdates(): void
    {
        $run = $this->task(); $id = $this->order();
        wc_get_order($id)->delete(false);
        self::assertInstanceOf(WP_Error::class, $this->tool($run, 'get-orders', ['order_ids' => [$id]]));
        self::assertInstanceOf(WP_Error::class, $this->tool($run, 'prepare-order-update', ['order_ids' => [$id], 'status' => 'completed']));
        self::assertEmpty($run['mcp_actions'] ?? []);
    }
}
