<?php

declare(strict_types=1);

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;

final class QueryTest extends TestCase
{
    private array $options;
    private array $run;
    private array $records = [];
    private array $orders = [];
    private array $products = [];
    private array $posts = [];
    private array $terms = [];
    private Closure $register;
    private bool $readable = true;
    private int $reads = 0;
    private ?Closure $onRead = null;

    protected function setUp(): void
    {
        wp_set_current_user(OYISO_AGENT_TEST_ADMIN);
        $this->options = Oyiso_Agent_Config::options();
        update_option('oyiso', array_merge($this->options, ['agent_enabled' => true, 'agent_plugin_tools_version' => '2', 'agent_mcp_namespaces' => ['oyiso-wordpress', Oyiso_Agent_MCP::BUILTIN_NAMESPACE, 'fixture-query']]), false);
        $run = Oyiso_Agent_Store::create('Composable query fixture'); self::assertIsArray($run); $this->run = $run;
        Oyiso_Agent_Abilities::bind(new Oyiso_Agent_Tools($this->run));
        $this->register = function (array $sources): array {
            $sources[] = new Oyiso_Agent_Query_Source('fixture_inventory', 'Fixture inventory', 'fixture-query', 'In-memory adapter exercising the same query engine.', [
                'id' => ['type' => 'integer', 'label' => 'ID'], 'name' => ['type' => 'string', 'label' => 'Name'], 'department' => ['type' => 'string', 'label' => 'Department', 'values' => ['a' => 'A', 'b' => 'B']],
                'quantity' => ['type' => 'number', 'label' => 'Quantity'], 'amount' => ['type' => 'number', 'label' => 'Amount', 'currency_field' => 'currency'], 'currency' => ['type' => 'string', 'label' => 'Currency'],
                'created' => ['type' => 'date', 'label' => 'Created'], 'enabled' => ['type' => 'boolean', 'label' => 'Enabled'], 'tags' => ['type' => 'list', 'label' => 'Tags'],
            ], ['id', 'name', 'quantity'], function (int $offset, int $limit, Oyiso_Agent_Query_Filter $filter, array $fields): Oyiso_Agent_Query_Page {
                $this->reads++;
                if ($this->onRead !== null) ($this->onRead)();
                $rows = array_slice($this->records, $offset, $limit);
                return new Oyiso_Agent_Query_Page($rows, count($rows), $offset + count($rows) < count($this->records));
            }, fn(): bool => $this->readable);
            return $sources;
        };
        add_filter('oyiso_agent_query_sources', $this->register);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(OYISO_AGENT_TEST_ADMIN); Oyiso_Agent_Abilities::bind(null);
        remove_filter('oyiso_agent_query_sources', $this->register);
        foreach ($this->orders as $order) $order->delete(true);
        foreach ($this->products as $product) $product->delete(true);
        foreach ($this->posts as $id) wp_delete_post($id, true);
        foreach ($this->terms as [$id, $taxonomy]) wp_delete_term($id, $taxonomy);
        wp_clear_scheduled_hook(Oyiso_Agent_Runner::HOOK, [$this->run['id']]);
        as_unschedule_all_actions(Oyiso_Agent_Runner::HOOK, [$this->run['id']], 'oyiso-agent');
        wp_delete_post($this->run['id'], true);
        update_option('oyiso', $this->options, false);
    }

    private function call(string $name, array $input = []): array|WP_Error
    {
        return wp_get_ability('oyiso/' . $name)->execute($input);
    }

    private function query(array $input = [], string $source = 'fixture_inventory'): array
    {
        $result = $this->call('query-data', ['source' => $source] + $input);
        self::assertIsArray($result, is_wp_error($result) ? $result->get_error_message() : 'Expected a query result.');
        self::assertTrue($result['complete']);
        return $result;
    }

    private function records(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) $this->records[] = ['id' => $i, 'name' => 'Item ' . $i, 'department' => $i % 2 === 0 ? 'a' : 'b', 'quantity' => $i, 'amount' => (float) $i, 'currency' => $i % 2 === 0 ? 'USD' : 'PLN', 'created' => '2026-01-15 12:00:00', 'enabled' => $i % 2 === 0, 'tags' => $i % 2 === 0 ? ['sale'] : [], 'secret_token' => 'must-not-leak'];
    }

    private function order(string $status, string $total, string $currency = 'USD', string $created = '2089-05-15 12:00:00'): void
    {
        $order = new WC_Order(); $order->set_status($status); $order->set_total($total); $order->set_currency($currency); $order->set_date_created($created);
        $order->set_billing_email('private-fixture@example.invalid'); $order->set_billing_first_name('Private fixture');
        $this->orders[] = $order; $order->get_data_store()->create($order);
        self::assertGreaterThan(0, $order->get_id());
    }

    public function testDiscoveryExposesCurrentMetadataAndReplacesLegacyModelTools(): void
    {
        $catalog = $this->call('list-data-sources');
        self::assertSame(['posts', 'orders', 'products', 'fixture_inventory'], array_column($catalog['sources'], 'source'));
        $description = $this->call('describe-data-source', ['source' => 'orders']);
        self::assertArrayHasKey('processing', $description['fields']['status']['values']);
        self::assertContains('between', $description['fields']['date_created']['operators']);
        self::assertSame('currency', $description['fields']['total']['currency_field']);
        foreach (['billing_email', 'billing_first_name', 'customer_ip_address', 'order_key'] as $private) self::assertArrayNotHasKey($private, $description['fields']);
        foreach (Oyiso_Agent_Query::TOOLS as $name) {
            self::assertContains('oyiso/' . $name, Oyiso_Agent_Abilities::allowed());
            self::assertTrue(wp_get_ability('oyiso/' . $name)->get_meta()['annotations']['readonly']);
        }
        foreach (Oyiso_Agent_Query::LEGACY as $name) self::assertNotContains('oyiso/' . $name, Oyiso_Agent_Abilities::allowed());
        $custom = static fn(array $statuses): array => $statuses + ['wc-query-custom' => 'Query custom'];
        add_filter('wc_order_statuses', $custom);
        try { self::assertSame('Query custom', $this->call('describe-data-source', ['source' => 'orders'])['fields']['status']['values']['query-custom']); }
        finally { remove_filter('wc_order_statuses', $custom); }
    }

    public function testNestedFiltersSortAndPaginationOperateOnTheWholeResult(): void
    {
        $this->records(12);
        $query = ['where' => ['any' => [
            ['all' => [['field' => 'enabled', 'op' => 'eq', 'value' => true], ['field' => 'tags', 'op' => 'contains', 'value' => 'sale']]],
            ['field' => 'id', 'op' => 'between', 'value' => [3, 5]],
        ]], 'select' => ['id', 'name'], 'order_by' => [['field' => 'quantity', 'direction' => 'desc']], 'limit' => 3, 'offset' => 1];
        $result = $this->query($query);
        self::assertSame(8, $result['matched_records']);
        self::assertSame([10, 8, 6], array_column($result['rows'], 'id'));
        self::assertSame(4, $result['next_offset']); self::assertTrue($result['has_more']);
        self::assertSame(['id', 'name'], array_keys($result['rows'][0]));
        self::assertStringNotContainsString('must-not-leak', wp_json_encode($result));
        $next = $this->query(array_replace($query, ['offset' => $result['next_offset']]));
        self::assertSame([5, 4, 3], array_column($next['rows'], 'id'));
    }

    public function testAggregatesReadEveryPageAndKeepCurrenciesSeparate(): void
    {
        $this->records(1203);
        $result = $this->query(['metrics' => [['op' => 'count'], ['op' => 'sum', 'field' => 'amount', 'as' => 'total'], ['op' => 'avg', 'field' => 'quantity', 'as' => 'average']]]);
        self::assertSame(1203, $result['matched_records']); self::assertSame(7, $this->reads);
        self::assertSame(['currency'], $result['automatic_group_by']);
        $rows = array_column($result['rows'], null, 'currency');
        self::assertSame(602, $rows['PLN']['count']); self::assertSame(362404.0, $rows['PLN']['total']); self::assertSame(602.0, $rows['PLN']['average']);
        self::assertSame(601, $rows['USD']['count']); self::assertSame(361802.0, $rows['USD']['total']);
    }

    public function testAggregationSortingAndPaginationDoNotChangeTheTotals(): void
    {
        $this->records(501);
        $result = $this->query(['group_by' => [['field' => 'department']], 'metrics' => [['op' => 'count', 'as' => 'items'], ['op' => 'max', 'field' => 'quantity', 'as' => 'largest'], ['op' => 'min', 'field' => 'quantity', 'as' => 'smallest']], 'order_by' => [['field' => 'items', 'direction' => 'desc']], 'limit' => 1]);
        self::assertSame(501, $result['matched_records']); self::assertSame(2, $result['total_rows']); self::assertTrue($result['has_more']);
        self::assertSame(['department' => 'b', 'items' => 251, 'largest' => 501.0, 'smallest' => 1.0], $result['rows'][0]);
    }

    public function testDateFiltersUseWholeDaysAndCalendarBuckets(): void
    {
        $this->records(4);
        foreach (['2026-01-31 23:59:59', '2026-02-01 00:00:00', '2026-02-28 23:59:59', '2026-03-01 00:00:00'] as $index => $date) $this->records[$index]['created'] = $date;
        $result = $this->query(['where' => ['field' => 'created', 'op' => 'between', 'value' => ['2026-02-01', '2026-02-28']], 'group_by' => [['field' => 'created', 'interval' => 'month']], 'metrics' => [['op' => 'count']]]);
        self::assertSame([['created_month' => '2026-02', 'count' => 2]], $result['rows']);
        $day = $this->query(['where' => ['field' => 'created', 'op' => 'eq', 'value' => '2026-02-28']]);
        self::assertSame([3], array_column($day['rows'], 'id'));
        $before = $this->query(['where' => ['field' => 'created', 'op' => 'lt', 'value' => '2026-02-01']]);
        self::assertSame([1], array_column($before['rows'], 'id'));
    }

    public function testNullsAndEmptyResultsAreNotMistakenForNumericZero(): void
    {
        $this->records(3); $this->records[0]['quantity'] = null; $this->records[1]['quantity'] = 0;
        $empty = $this->query(['where' => ['field' => 'quantity', 'op' => 'is_empty']]); self::assertSame([1], array_column($empty['rows'], 'id'));
        $nonzero = $this->query(['where' => ['field' => 'quantity', 'op' => 'ne', 'value' => 0]]); self::assertSame([3], array_column($nonzero['rows'], 'id'));
        $result = $this->query(['where' => ['field' => 'id', 'op' => 'gt', 'value' => 100], 'metrics' => [['op' => 'count'], ['op' => 'sum', 'field' => 'quantity'], ['op' => 'avg', 'field' => 'quantity'], ['op' => 'min', 'field' => 'quantity']]]);
        self::assertSame([['count' => 0, 'sum_quantity' => 0.0, 'avg_quantity' => null, 'min_quantity' => null]], $result['rows']);
        $unknown = $this->query(['where' => ['field' => 'quantity', 'op' => 'is_empty'], 'metrics' => [['op' => 'count'], ['op' => 'sum', 'field' => 'quantity']]]);
        self::assertSame([['count' => 1, 'sum_quantity' => null]], $unknown['rows']);
    }

    public function testInvalidQueriesAreRejectedBeforeReadingAnyData(): void
    {
        $this->records(1); $tools = new Oyiso_Agent_Tools($this->run);
        foreach ([
            ['select' => ['secret_token']], ['where' => null], ['limit' => '2'], ['offset' => -1], ['sql' => 'SELECT * FROM wp_options'],
            ['where' => ['field' => 'id', 'op' => 'eq', 'value' => '1']], ['where' => ['field' => 'quantity', 'op' => 'contains', 'value' => '1']],
            ['where' => ['field' => 'department', 'op' => 'eq', 'value' => 'unknown']], ['where' => ['any' => []]], ['where' => ['any' => [[]]]],
            ['where' => ['field' => 'created', 'op' => 'eq', 'value' => '2026-02-30']], ['where' => ['field' => 'created', 'op' => 'between', 'value' => ['2026-02-02', '2026-02-01']]],
            ['where' => ['field' => 'id', 'op' => 'in', 'value' => []]], ['where' => ['field' => 'name', 'op' => 'is_empty', 'value' => '']],
            ['metrics' => [['op' => 'sum', 'field' => 'name']]], ['metrics' => [['op' => 'count', 'field' => 'id']]],
            ['group_by' => [['field' => 'tags']], 'metrics' => [['op' => 'count']]], ['group_by' => [['field' => 'created', 'interval' => 'quarter']]],
            ['metrics' => [['op' => 'count', 'as' => 'same'], ['op' => 'count', 'as' => 'same']]], ['metrics' => [['op' => 'count']], 'select' => ['id']],
            ['order_by' => [['field' => 'secret_token']]], ['order_by' => [['field' => 'tags']]], ['metrics' => [['op' => 'count']], 'order_by' => [['field' => 'id']]],
        ] as $input) {
            $result = $tools->execute('query-data', ['source' => 'fixture_inventory'] + $input);
            self::assertInstanceOf(WP_Error::class, $result, wp_json_encode($input)); self::assertSame('agent_query_invalid', $result->get_error_code());
        }
        self::assertSame(0, $this->reads);
        self::assertInstanceOf(WP_Error::class, $this->call('query-data', ['source' => 'orders', 'select' => ['billing_email']]));
    }

    public function testBudgetExhaustionNeverReturnsPartialAggregates(): void
    {
        $this->records(5);
        foreach ([['max_records' => 3], ['max_groups' => 1]] as $override) {
            $limits = static fn(array $limits): array => array_replace($limits, $override);
            add_filter('oyiso_agent_query_limits', $limits);
            try {
                $result = $this->call('query-data', ['source' => 'fixture_inventory', 'group_by' => [['field' => 'department']], 'metrics' => [['op' => 'count']]]);
                self::assertInstanceOf(WP_Error::class, $result); self::assertSame('agent_query_limit', $result->get_error_code());
                self::assertFalse($result->get_error_data()['complete']); self::assertArrayNotHasKey('rows', $result->get_error_data());
            } finally { remove_filter('oyiso_agent_query_limits', $limits); }
        }
    }

    public function testPermissionsAreRecheckedAfterReadingAndHiddenFromDiscovery(): void
    {
        $this->records(1); $this->onRead = function (): void { $this->readable = false; };
        $result = $this->call('query-data', ['source' => 'fixture_inventory', 'metrics' => [['op' => 'count']]]);
        self::assertInstanceOf(WP_Error::class, $result); self::assertSame('agent_query_forbidden', $result->get_error_code());
        self::assertNotContains('fixture_inventory', array_column($this->call('list-data-sources')['sources'], 'source'));
        self::assertInstanceOf(WP_Error::class, $this->call('describe-data-source', ['source' => 'fixture_inventory']));
    }

    public function testNamespaceRevocationUsesFreshSettingsAndDoesNotDisableOtherSources(): void
    {
        global $wpdb;
        $cached = Oyiso_Agent_Config::options();
        self::assertArrayHasKey('orders', Oyiso_Agent_Query_Catalog::available());
        $revoked = array_replace($cached, ['agent_mcp_namespaces' => ['oyiso-wordpress']]);
        $wpdb->update($wpdb->options, ['option_value' => maybe_serialize($revoked)], ['option_name' => 'oyiso']);
        try {
            self::assertSame($cached, get_option('oyiso'));
            self::assertSame(['posts'], array_column($this->call('list-data-sources')['sources'], 'source'));
            self::assertInstanceOf(WP_Error::class, $this->call('query-data', ['source' => 'orders']));
        } finally { $wpdb->update($wpdb->options, ['option_value' => maybe_serialize($cached)], ['option_name' => 'oyiso']); }
    }

    public function testMissingTaskOwnershipAndCancelledTasksCannotQuery(): void
    {
        $this->records(1);
        wp_set_current_user(0);
        try { self::assertInstanceOf(WP_Error::class, $this->call('query-data', ['source' => 'fixture_inventory'])); }
        finally { wp_set_current_user(OYISO_AGENT_TEST_ADMIN); }
        Oyiso_Agent_Store::cancel($this->run['id']);
        self::assertInstanceOf(WP_Error::class, $this->call('query-data', ['source' => 'fixture_inventory']));
        self::assertSame(0, $this->reads);
    }

    public function testSharedToolsKeepPluginSwitchesIndependentAndExtensionsCanBeEnabled(): void
    {
        ob_start(); Oyiso_Agent_MCP::render(); $html = ob_get_clean();
        $dom = new DOMDocument(); $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING); $xpath = new DOMXPath($dom);
        self::assertSame(0, $xpath->query('//tr[@data-agent-mcp-plugin="wordpress"]//input[@data-agent-plugin-member][@value="oyiso-woocommerce"]')->length);
        self::assertSame(0, $xpath->query('//tr[@data-agent-mcp-plugin="woocommerce"]//input[@data-agent-plugin-member][@value="oyiso-wordpress"]')->length);
        self::assertSame(1, $xpath->query('//tr[@data-agent-mcp-plugin="fixture-query"]//input[@data-agent-plugin-member][@value="fixture-query"]')->length);
        self::assertSame(['fixture-query'], Oyiso_Agent_MCP::sanitizeNamespaces(['fixture-query']));
        self::assertSame(0, $xpath->query('//tr[@data-agent-mcp-plugin="fixture-query"]//input[@data-agent-write-grant]')->length);
    }

    public function testBadAdapterDataDoesNotProduceSuccessfulCounts(): void
    {
        $this->records(1); unset($this->records[0]['quantity']);
        $result = $this->call('query-data', ['source' => 'fixture_inventory']);
        self::assertInstanceOf(WP_Error::class, $result); self::assertSame('agent_query_read', $result->get_error_code());
        $this->records[0]['quantity'] = 'not numeric';
        self::assertInstanceOf(WP_Error::class, $this->call('query-data', ['source' => 'fixture_inventory']));
    }

    public function testSmallNumericValuesAndLargeExtremaAreNotRoundedAwayOrOverflowed(): void
    {
        $this->records(2); $this->records[0]['quantity'] = 0.0000000001; $this->records[1]['quantity'] = 0.0000000002;
        $result = $this->query(['metrics' => [['op' => 'sum', 'field' => 'quantity']]]);
        self::assertEqualsWithDelta(0.0000000003, $result['rows'][0]['sum_quantity'], 1.0e-25);
        foreach ($this->records as &$record) $record['quantity'] = 1.0e308;
        unset($record);
        $result = $this->query(['metrics' => [['op' => 'min', 'field' => 'quantity']]]);
        self::assertSame(1.0e308, $result['rows'][0]['min_quantity']);
        self::assertInstanceOf(WP_Error::class, $this->call('query-data', ['source' => 'fixture_inventory', 'metrics' => [['op' => 'sum', 'field' => 'quantity']]]));
    }

    public function testNativeOrdersSupportComposedStatusDateAndCurrencyAnalysis(): void
    {
        $this->order('processing', '10.00'); $this->order('processing', '15.00', 'PLN'); $this->order('completed', '30.00'); $this->order('pending', '99.00');
        $where = ['all' => [['field' => 'date_created', 'op' => 'between', 'value' => ['2089-05-01', '2089-05-31']], ['field' => 'status', 'op' => 'in', 'value' => ['processing', 'completed']]]];
        $result = $this->query(['where' => $where, 'group_by' => [['field' => 'date_created', 'interval' => 'month']], 'metrics' => [['op' => 'count'], ['op' => 'sum', 'field' => 'total']]], 'orders');
        self::assertSame(3, $result['matched_records']); $rows = array_column($result['rows'], null, 'currency');
        self::assertSame(2, $rows['USD']['count']); self::assertSame(40.0, $rows['USD']['sum_total']); self::assertSame(15.0, $rows['PLN']['sum_total']);
        $records = $this->query(['where' => $where], 'orders');
        self::assertStringNotContainsString('private-fixture@example.invalid', wp_json_encode($records));
        self::assertStringNotContainsString('Private fixture', wp_json_encode($records));
        $current = $this->query(['where' => ['field' => 'status', 'op' => 'eq', 'value' => 'processing'], 'metrics' => [['op' => 'count']]], 'orders');
        self::assertSame(wc_orders_count('processing'), $current['rows'][0]['count']);
    }

    public function testNativeDateRangesAcceptMixedDayAndTimestampBoundaries(): void
    {
        $this->order('processing', '10.00', 'USD', '2089-06-01 11:00:00');
        $this->order('processing', '20.00', 'USD', '2089-06-01 12:00:00');
        $result = $this->query(['where' => ['field' => 'date_created', 'op' => 'between', 'value' => ['2089-06-01 11:30:00', '2089-06-01']], 'metrics' => [['op' => 'count']]], 'orders');
        self::assertSame(1, $result['matched_records']); self::assertSame([['count' => 1]], $result['rows']);
    }

    public function testNativeProductsCanCombineTaxonomyPriceAndStockFilters(): void
    {
        $term = wp_insert_term('Query category ' . wp_generate_uuid4(), 'product_cat'); self::assertIsArray($term); $this->terms[] = [(int) $term['term_id'], 'product_cat'];
        $category = get_term($term['term_id'], 'product_cat');
        foreach ([['7.00', 'instock'], ['12.00', 'outofstock']] as [$price, $stock]) {
            $product = new WC_Product_Simple(); $product->set_name('Query product ' . wp_generate_uuid4()); $product->set_status('draft'); $product->set_regular_price($price); $product->set_stock_status($stock); $product->set_category_ids([$term['term_id']]); $product->set_description('<p>Hello</p>'); $product->save(); $this->products[] = $product;
        }
        $result = $this->query(['where' => ['all' => [['field' => 'category_slugs', 'op' => 'contains', 'value' => $category->slug], ['field' => 'stock_status', 'op' => 'eq', 'value' => 'instock'], ['field' => 'price', 'op' => 'lt', 'value' => 10]]], 'select' => ['id', 'price', 'description_length']], 'products');
        self::assertSame(1, $result['matched_records']); self::assertSame($this->products[0]->get_id(), $result['rows'][0]['id']); self::assertSame(7.0, $result['rows'][0]['price']); self::assertSame(5, $result['rows'][0]['description_length']);
    }

    public function testNativeContentRespectsPerRecordPermissions(): void
    {
        foreach (['post', 'page'] as $type) $this->posts[] = wp_insert_post(['post_type' => $type, 'post_status' => 'draft', 'post_title' => 'Composable fixture', 'post_content' => 'Hello', 'post_author' => OYISO_AGENT_TEST_ADMIN]);
        $query = ['where' => ['field' => 'id', 'op' => 'in', 'value' => $this->posts], 'group_by' => [['field' => 'post_type']], 'metrics' => [['op' => 'count']]];
        self::assertSame(2, $this->query($query, 'posts')['matched_records']);
        wp_update_post(['ID' => $this->posts[0], 'post_content' => '<p>' . str_repeat('prefix ', 100) . 'Body-only marker</p>']);
        $body = $this->query(['where' => ['all' => [['field' => 'id', 'op' => 'in', 'value' => $this->posts], ['field' => 'content_text', 'op' => 'contains', 'value' => 'Body-only marker']]], 'select' => ['id', 'content_text']], 'posts');
        self::assertSame(1, $body['matched_records']); self::assertSame($this->posts[0], $body['rows'][0]['id']);
        self::assertSame([['content_text']], $body['truncated_fields']);
        self::assertStringNotContainsString('Body-only marker', $body['rows'][0]['content_text']);
        $deny = function (array $caps, string $cap, int $userId, array $args): array { return $cap === 'edit_post' && ($args[0] ?? 0) === $this->posts[1] ? ['do_not_allow'] : $caps; };
        add_filter('map_meta_cap', $deny, 10, 4);
        try {
            $result = $this->query($query, 'posts');
            self::assertSame(1, $result['matched_records']); self::assertSame([['post_type' => 'post', 'count' => 1]], $result['rows']);
        } finally { remove_filter('map_meta_cap', $deny, 10); }
    }
}
