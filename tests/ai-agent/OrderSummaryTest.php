<?php

declare(strict_types=1);

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
    http_response_code(404);
    exit;
}

use PHPUnit\Framework\TestCase;

final class OrderSummaryTest extends TestCase
{
    private array $options;
    private array $run;
    private array $orders = [];

    protected function setUp(): void
    {
        wp_set_current_user(OYISO_AGENT_TEST_ADMIN);
        $this->options = Oyiso_Agent_Config::options();
        update_option('oyiso', array_merge($this->options, ['agent_enabled' => true, 'agent_plugin_tools_version' => '2', 'agent_mcp_namespaces' => [Oyiso_Agent_MCP::BUILTIN_NAMESPACE]]), false);
        $run = Oyiso_Agent_Store::create('Order summary integration fixture');
        self::assertIsArray($run);
        $this->run = $run;
        Oyiso_Agent_Abilities::bind(new Oyiso_Agent_Tools($this->run));
    }

    protected function tearDown(): void
    {
        Oyiso_Agent_Abilities::bind(null);
        foreach ($this->orders as $order) $order->delete(true);
        wp_clear_scheduled_hook(Oyiso_Agent_Runner::HOOK, [$this->run['id']]);
        as_unschedule_all_actions(Oyiso_Agent_Runner::HOOK, [$this->run['id']], 'oyiso-agent');
        wp_delete_post($this->run['id'], true);
        update_option('oyiso', $this->options, false);
    }

    private function order(string $status, string $total, string $currency = 'USD', string $created = '2088-05-15 12:00:00'): void
    {
        $order = new WC_Order();
        $order->set_status($status);
        $order->set_currency($currency);
        $order->set_total($total);
        $order->set_date_created($created);
        // Persist fixtures without running payment/status-transition side effects.
        $this->orders[] = $order;
        $order->get_data_store()->create($order);
        self::assertGreaterThan(0, $order->get_id());
    }

    private function summary(array $input): array
    {
        $result = wp_get_ability('oyiso/order-summary')->execute($input);
        self::assertIsArray($result, is_wp_error($result) ? $result->get_error_message() : 'Expected an order summary.');
        return $result;
    }

    private function range(): array
    {
        return ['start_date' => '2088-05-01', 'end_date' => '2088-05-31'];
    }

    public function testEachRequestedStatusExcludesOtherOrdersAndKeepsCurrenciesSeparate(): void
    {
        $this->order('processing', '10.00');
        $this->order('processing', '15.00', 'PLN');
        $this->order('completed', '40.00');
        $this->order('pending', '90.00');
        $this->order('on-hold', '75.00');
        foreach (['processing' => [2, ['USD' => 10.0, 'PLN' => 15.0]], 'completed' => [1, ['USD' => 40.0]], 'pending' => [1, ['USD' => 90.0]], 'on-hold' => [1, ['USD' => 75.0]]] as $status => [$count, $totals]) {
            $summary = $this->summary($this->range() + ['statuses' => [$status]]);
            self::assertSame([$status], $summary['statuses']);
            self::assertSame($count, $summary['total_orders']);
            self::assertSame($count, $summary['sampled_orders']);
            self::assertSame($totals, $summary['totals_by_currency']);
            self::assertFalse($summary['truncated']);
        }
    }

    public function testMultipleAndPrefixedStatusesAreNormalizedWithoutDoubleCounting(): void
    {
        $this->order('processing', '10.00');
        $this->order('pending', '20.00');
        $this->order('completed', '99.00');
        $summary = $this->summary($this->range() + ['statuses' => ['wc-processing', 'processing', 'wc-pending']]);
        self::assertSame(['processing', 'pending'], $summary['statuses']);
        self::assertSame(2, $summary['total_orders']);
        self::assertSame(['USD' => 30.0], $summary['totals_by_currency']);
    }

    public function testOmittingStatusesPreservesThePreviousCombinedSummary(): void
    {
        $this->order('processing', '10.00');
        $this->order('completed', '20.00');
        $this->order('pending', '99.00');
        $summary = $this->summary($this->range());
        self::assertSame(['processing', 'completed'], $summary['statuses']);
        self::assertSame(2, $summary['total_orders']);
        self::assertSame(['USD' => 30.0], $summary['totals_by_currency']);
    }

    public function testOmittingDatesIncludesOldAndFutureOrdersOfTheRequestedStatus(): void
    {
        $before = $this->summary(['statuses' => ['processing']]);
        $this->order('processing', '10.00', 'USD', '2001-01-01 12:00:00');
        $this->order('processing', '20.00', 'USD', '2088-01-01 12:00:00');
        $this->order('completed', '99.00');
        $summary = $this->summary(['statuses' => ['processing']]);
        self::assertSame($before['total_orders'] + 2, $summary['total_orders']);
        self::assertSame('all_time', $summary['date_scope']);
        self::assertNull($summary['start_date']);
        self::assertNull($summary['end_date']);
    }

    public function testDateRangeIncludesWholeBoundaryDaysAndReturnsZeroForNoMatches(): void
    {
        $this->order('processing', '99.00', 'USD', '2088-04-30 23:59:59');
        $this->order('processing', '10.00', 'USD', '2088-05-01 00:00:00');
        $this->order('processing', '20.00', 'USD', '2088-05-31 23:59:59');
        $this->order('processing', '99.00', 'USD', '2088-06-01 00:00:00');
        $summary = $this->summary($this->range() + ['statuses' => ['processing']]);
        self::assertSame(2, $summary['total_orders']);
        self::assertSame(['USD' => 30.0], $summary['totals_by_currency']);
        self::assertSame('date_range', $summary['date_scope']);
        self::assertSame('2088-05-01', $summary['start_date']);
        self::assertSame('2088-05-31', $summary['end_date']);
        $empty = $this->summary($this->range() + ['statuses' => ['pending']]);
        self::assertSame(0, $empty['total_orders']);
        self::assertSame([], $empty['totals_by_currency']);
    }

    public function testNewlyRegisteredCustomStatusIsAcceptedByTheBackend(): void
    {
        $status = 'oi-' . substr(wp_generate_uuid4(), 0, 8);
        $filter = static fn(array $statuses): array => $statuses + ['wc-' . $status => 'Fixture shipped'];
        register_post_status('wc-' . $status, ['label' => 'Fixture shipped', 'public' => false]);
        add_filter('wc_order_statuses', $filter);
        try {
            $this->order($status, '12.00');
            $this->order('processing', '99.00');
            $summary = (new Oyiso_Agent_Tools($this->run))->execute('order-summary', $this->range() + ['statuses' => [$status]]);
            self::assertIsArray($summary);
            self::assertSame([$status], $summary['statuses']);
            self::assertSame(1, $summary['total_orders']);
            self::assertSame(['USD' => 12.0], $summary['totals_by_currency']);
        } finally {
            remove_filter('wc_order_statuses', $filter);
            unset($GLOBALS['wp_post_statuses']['wc-' . $status]);
        }
    }

    public function testMalformedFiltersCannotFallBackToCombinedOrUnfilteredQueries(): void
    {
        $tools = new Oyiso_Agent_Tools($this->run);
        $block = static function (): void { self::fail('Invalid filters must not query orders.'); };
        add_filter('woocommerce_order_query_args', $block);
        try {
            foreach ([[], null, 'processing', [''], [123], ['not-a-status'], ['processing', 'not-a-status'], ['status' => 'processing']] as $statuses) {
                $error = $tools->execute('order-summary', ['statuses' => $statuses]);
                self::assertInstanceOf(WP_Error::class, $error);
                self::assertSame('agent_order_statuses', $error->get_error_code());
            }
            foreach ([['start_date' => '2088-05-01'], ['end_date' => '2088-05-31'], ['start_date' => '2088-02-30', 'end_date' => '2088-05-31'], ['start_date' => '2088-06-01', 'end_date' => '2088-05-31'], ['start_date' => null, 'end_date' => null]] as $dates) {
                $error = $tools->execute('order-summary', $dates);
                self::assertInstanceOf(WP_Error::class, $error);
                self::assertSame('agent_order_dates', $error->get_error_code());
            }
        } finally { remove_filter('woocommerce_order_query_args', $block); }
        self::assertInstanceOf(WP_Error::class, wp_get_ability('oyiso/order-summary')->execute(['statuses' => []]));
        self::assertInstanceOf(WP_Error::class, wp_get_ability('oyiso/order-summary')->execute(['statuses' => ['not-a-status']]));
    }
}
