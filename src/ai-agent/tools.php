<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * @phpstan-import-type Run from Oyiso_Agent_Store
 * @phpstan-import-type Fields from Oyiso_Agent_Store
 */
final class Oyiso_Agent_Tools
{
    public const SEO = ['seo_title' => '_seopress_titles_title', 'seo_description' => '_seopress_titles_desc', 'seo_keywords' => '_seopress_analysis_target_kw'];
    /** @var Run */
    private array $run;

    /** @param Run $run */
    public function __construct(array &$run, private readonly string $approval = '')
    {
        $this->run =& $run;
    }

    public function approved(string $action): bool
    {
        return $this->approval === $action && ($action === 'revert' || !Oyiso_Agent_Store::cancelled($this->run['id']));
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function execute(string $name, array $input): array|WP_Error
    {
        if (!Oyiso_Agent_Config::enabled() || !current_user_can('manage_options') || !Oyiso_Agent_Store::owned($this->run['id']) || (!in_array($name, ['revert-product-update', 'revert-product-trash'], true) && Oyiso_Agent_Store::cancelled($this->run['id']))) {
            return new WP_Error('agent_tool_forbidden', '当前任务已取消或没有执行权限。');
        }
        if ($name !== 'dismiss-product-trash' && in_array($name, Oyiso_Agent_Abilities::WOOCOMMERCE, true) && !Oyiso_Agent_MCP::builtinEnabled(true)) {
            return new WP_Error('agent_plugin_disabled', 'WooCommerce 工具未启用，请在工具权限中启用并保存。', ['status' => 403]);
        }
        if (Oyiso_Agent_Permissions::required('oyiso/' . $name) !== null) {
            $permission = Oyiso_Agent_Permissions::check('oyiso/' . $name);
            if (is_wp_error($permission)) return $permission;
        }
        return match ($name) {
            'read-chat-file' => Oyiso_Agent_Attachments::text($this->run['id'], self::integer($input, 'file_id'), self::integer($input, 'offset'), array_key_exists('length', $input) ? self::integer($input, 'length') : 8000, self::text($input, 'search')),
            'get-current-page', 'search-posts', 'get-post', 'prepare-post-update', 'prepare-post-draft' => Oyiso_Agent_Content::execute($this->run, $name, $input),
            'list-plugins', 'activate-plugin', 'deactivate-plugin' => Oyiso_Agent_Plugins::call($this->run, $name, $input),
            'search-products' => $this->search($input),
            'get-product' => $this->product(self::integer($input, 'product_id')),
            'order-summary' => $this->orders($input),
            'prepare-product-update' => $this->prepare($input),
            'prepare-product-trash' => Oyiso_Agent_Product_Trash::prepare($this->run, self::integer($input, 'product_id'), self::text($input, 'reason')),
            'apply-product-trash', 'revert-product-trash', 'dismiss-product-trash' => Oyiso_Agent_Product_Trash::change($this->run, self::text($input, 'proposal_id'), explode('-', $name)[0], $this->approval),
            'apply-product-update' => $this->change(self::text($input, 'proposal_id'), false),
            'revert-product-update' => $this->change(self::text($input, 'proposal_id'), true),
            'mcp-list-tools', 'mcp-describe-tool', 'mcp-call-tool' => Oyiso_Agent_MCP::call($name, $input, $this->run),
            default => new WP_Error('agent_unknown_tool', '工具不存在。'),
        };
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    private function search(array $input): array|WP_Error
    {
        if (!function_exists('wc_get_products') || !current_user_can('edit_products')) {
            return new WP_Error('agent_wc_unavailable', '请先启用 WooCommerce，并确认商品编辑权限。');
        }
        $page = max(1, self::integer($input, 'page'));
        $args = ['limit' => 10, 'page' => $page, 'paginate' => true, 'status' => ['publish', 'private', 'draft', 'pending'], 'orderby' => 'ID', 'order' => 'DESC'];
        if (!empty($input['search'])) {
            $args['s'] = sanitize_text_field(self::text($input, 'search'));
        }
        if (!empty($input['category'])) {
            $args['category'] = [sanitize_title(self::text($input, 'category'))];
        }
        $result = wc_get_products($args);
        if (!$result instanceof stdClass || !isset($result->products) || !is_array($result->products)) {
            return new WP_Error('agent_product_query', '商品查询失败。');
        }
        $items = [];
        foreach ($result->products as $product) {
            if (!$product instanceof WC_Product || !current_user_can('edit_post', $product->get_id())) {
                continue;
            }
            $fields = self::fields($product);
            $items[] = ['id' => $product->get_id(), 'name' => $product->get_name(), 'sku' => $product->get_sku(), 'status' => $product->get_status(), 'missing_fields' => array_keys(array_filter($fields, static fn(string $value): bool => trim(wp_strip_all_tags($value)) === ''))];
        }
        return ['products' => $items, 'page' => $page, 'total' => (int) ($result->total ?? 0), 'pages' => (int) ($result->max_num_pages ?? 0), 'notice' => '此结果为分页数据，未读取的页面不计入分析结论。SEO 字段为空表示未设置独立值，页面仍可能使用全局 SEO 模板。'];
    }

    /** @return array<string, mixed>|WP_Error */
    private function product(int $id): array|WP_Error
    {
        $product = $this->editable($id);
        if (is_wp_error($product)) {
            return $product;
        }
        $fields = self::fields($product);
        $this->run['snapshots'][$id] = $fields;
        $snapshot = Oyiso_Agent_Product_Trash::snapshot($id);
        if (is_wp_error($snapshot)) return $snapshot;
        $this->run['product_snapshots'][$id] = $snapshot['hash'];
        $attributes = [];
        foreach ($product->get_attributes() as $key => $attribute) {
            if ($attribute instanceof WC_Product_Attribute) {
                $attributes[wc_attribute_label($attribute->get_name())] = $product->get_attribute($attribute->get_name());
            } elseif (is_string($key) && is_string($attribute)) {
                $attributes[wc_attribute_label($key)] = $product->get_attribute($key);
            }
        }
        return ['id' => $id, 'parent_id' => $product->get_parent_id(), 'name' => $product->get_name(), 'sku' => $product->get_sku(), 'type' => $product->get_type(), 'status' => $product->get_status(), 'variation_count' => count(array_filter($snapshot['variations'], static fn(string $status): bool => $status !== 'trash')), 'can_trash' => Oyiso_Agent_Permissions::allows('oyiso/apply-product-trash') && get_post_type($id) === 'product' && current_user_can('delete_products') && current_user_can('delete_post', $id) && Oyiso_Agent_Product_Trash::trashEnabled(), 'url' => $product->get_permalink(), 'price' => $product->get_price(), 'currency' => get_woocommerce_currency(), 'stock_status' => $product->get_stock_status(), 'attributes' => $attributes, 'fields' => array_map(static fn(string $value): string => mb_substr($value, 0, 20000), $fields), 'truncated_fields' => array_keys(array_filter($fields, static fn(string $value): bool => mb_strlen($value) > 20000)), 'seopress' => self::seoEnabled(), 'notice' => '描述单字段最多返回 20000 字符，truncated_fields 中的字段未完整读取，不能据此覆盖。价格、库存和规格以本站资料为准；与用户提供的资料冲突时需说明。SEO 字段为空表示未单独设置，页面可能使用全局模板。'];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    private function orders(array $input): array|WP_Error
    {
        if (!function_exists('wc_get_orders') || !current_user_can('manage_woocommerce')) {
            return new WP_Error('agent_orders_forbidden', '没有查询订单统计的权限。');
        }
        $start = self::text($input, 'start_date');
        $end = self::text($input, 'end_date');
        if (!self::date($start) || !self::date($end) || $start > $end) {
            return new WP_Error('agent_order_dates', '请提供有效的起止日期，格式为 YYYY-MM-DD。');
        }
        $result = wc_get_orders(['date_created' => $start . '...' . $end, 'status' => ['wc-processing', 'wc-completed'], 'limit' => 500, 'paginate' => true, 'orderby' => 'ID', 'order' => 'ASC']);
        if (!$result instanceof stdClass || !is_array($result->orders ?? null)) {
            return new WP_Error('agent_order_query', '订单查询失败。');
        }
        $totals = [];
        foreach ($result->orders as $order) {
            if ($order instanceof WC_Order) {
                $currency = $order->get_currency();
                $totals[$currency] = ($totals[$currency] ?? 0.0) + (float) $order->get_total();
            }
        }
        return ['start_date' => $start, 'end_date' => $end, 'timezone' => wp_timezone_string(), 'statuses' => ['processing', 'completed'], 'total_orders' => (int) ($result->total ?? 0), 'sampled_orders' => count($result->orders), 'totals_by_currency' => $totals, 'truncated' => (int) ($result->total ?? 0) > 500, 'notice' => '金额为所取订单的含税含运费订单总额，未扣退款。超过 500 单时请缩小日期范围；结果不含客户个人信息。'];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    private function prepare(array $input): array|WP_Error
    {
        $id = self::integer($input, 'product_id');
        $product = $this->editable($id);
        if (is_wp_error($product)) {
            return $product;
        }
        $before = $this->run['snapshots'][$id] ?? null;
        if ($before === null) {
            return new WP_Error('agent_product_not_read', '请先调用 get-product 读取该商品，再生成修改方案。');
        }
        if ($before !== self::fields($product)) {
            return new WP_Error('agent_product_changed', '商品内容已发生变化，请重新读取后再生成方案。');
        }
        $fields = $input['fields'] ?? null;
        if (!is_array($fields) || $fields === [] || array_diff(array_keys($fields), array_keys($before)) !== []) {
            return new WP_Error('agent_fields_invalid', '仅支持修改长描述、短描述及已启用的 SEOPress 字段。');
        }
        $after = $before;
        foreach ($fields as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                return new WP_Error('agent_field_type', '商品字段必须是文本。');
            }
            if (mb_strlen($before[$key]) > 20000) return new WP_Error('agent_product_truncated', '商品字段 ' . $key . ' 超过单次读取范围，不能基于截断内容覆盖。请缩小修改范围。');
            $limit = match ($key) { 'description' => 60000, 'short_description' => 12000, 'seo_title' => 300, default => 1000 };
            if (mb_strlen($value) > $limit) {
                return new WP_Error('agent_field_length', sprintf('字段 %s 超过长度限制。', $key));
            }
            $after[$key] = in_array($key, ['description', 'short_description'], true) ? wp_kses_post($value) : sanitize_textarea_field($value);
        }
        if ($after === $before) {
            return new WP_Error('agent_no_changes', '生成的内容与当前内容相同，无需保存。');
        }
        if (count($this->run['changes']) >= 20) {
            return new WP_Error('agent_proposal_limit', '本次对话的修改方案已达上限，请新建对话。');
        }
        foreach ($this->run['changes'] as &$change) {
            if ($change['product_id'] === $id && $change['status'] === 'pending') {
                $change['status'] = 'superseded';
            }
        }
        unset($change);
        $proposal = wp_generate_uuid4();
        $this->run['changes'][] = ['id' => $proposal, 'product_id' => $id, 'name' => $product->get_name(), 'before' => $before, 'after' => $after, 'status' => 'pending'];
        $page = $this->run['page_context'] ?? null;
        if ($page !== null && $page['post_id'] === $id && !$page['truncated']) $this->run['changes'][array_key_last($this->run['changes'])]['editor_before'] = array_intersect_key($page['fields'], $before);
        return ['proposal_id' => $proposal, 'product_id' => $id, 'status' => 'awaiting_user_review', 'notice' => '修改方案已生成，尚未写入商品。请用户查看前后对照并点击应用修改。'];
    }

    /** @return array<string, mixed>|WP_Error */
    private function change(string $proposal, bool $revert): array|WP_Error
    {
        $action = $revert ? 'revert' : 'apply';
        if (!$this->approved($action)) {
            return new WP_Error('agent_approval_required', '请通过修改预览中的操作按钮执行。');
        }
        foreach ($this->run['changes'] as &$change) {
            if ($change['id'] !== $proposal || ($change['type'] ?? 'update') !== 'update') {
                continue;
            }
            $done = $revert ? 'reverted' : 'applied';
            if ($change['status'] === $done) {
                return ['status' => $done, 'product_id' => $change['product_id']];
            }
            if ($change['status'] !== ($revert ? 'applied' : 'pending')) {
                return new WP_Error('agent_proposal_state', '此修改方案当前无法执行该操作。');
            }
            $resource = 'product_' . $change['product_id'];
            if (!Oyiso_Agent_Store::lock($resource)) {
                return new WP_Error('agent_product_busy', '该商品正在由其他任务处理，请稍后重试。');
            }
            try {
                $product = $this->editable($change['product_id']);
                if (is_wp_error($product)) {
                    return $product;
                }
                $expected = $revert ? $change['after'] : $change['before'];
                $target = $revert ? $change['before'] : $change['after'];
                $result = $this->write($product, $target, $expected);
                if (is_wp_error($result)) {
                    return $result;
                }
                $change['status'] = $done;
                Oyiso_Agent_Store::event($this->run, 'success', $revert ? '已撤回商品修改' : '已保存商品修改', $change['name']);
                return ['status' => $done, 'product_id' => $change['product_id']];
            } finally {
                Oyiso_Agent_Store::unlock($resource);
            }
        }
        return new WP_Error('agent_proposal_missing', '修改方案不存在。');
    }

    /** @param Fields $fields
     * @param Fields $expected
     */
    private function write(WC_Product $product, array $fields, array $expected): true|WP_Error
    {
        global $wpdb;
        if ($wpdb->query('START TRANSACTION') === false) return new WP_Error('agent_product_save', '无法开始商品保存事务，此次未执行修改。', ['status' => 500]);
        try {
            $id = $product->get_id();
            $lockedId = $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID = %d FOR UPDATE", $id));
            if (!is_scalar($lockedId) || (int) $lockedId !== $id) {
                throw new RuntimeException('商品已不存在，无法保存。');
            }
            $wpdb->get_col($wpdb->prepare("SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d FOR UPDATE", $id));
            if ($wpdb->last_error !== '') throw new RuntimeException('无法锁定商品资料，此次未执行修改。');
            clean_post_cache($id);
            $fresh = wc_get_product($id);
            if (!$fresh) {
                throw new RuntimeException('商品读取失败。');
            }
            $current = self::fields($fresh);
            if ($current !== $fields && $current !== $expected) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('agent_update_conflict', '商品已被其他操作修改，请重新读取并生成方案；此次未覆盖。', ['status' => 409]);
            }
            if ($current === $fields) {
                if ($wpdb->query('COMMIT') === false) throw new RuntimeException('无法提交商品保存事务。');
                return true;
            }
            $product = $fresh;
            $product->set_description($fields['description']);
            $product->set_short_description($fields['short_description']);
            if ($product->save() <= 0) {
                throw new RuntimeException('商品保存失败。');
            }
            foreach (self::SEO as $field => $meta) {
                if (isset($fields[$field])) {
                    if ($fields[$field] === '') {
                        delete_post_meta($product->get_id(), $meta);
                    } else {
                        update_post_meta($product->get_id(), $meta, wp_slash($fields[$field]));
                    }
                }
            }
            clean_post_cache($product->get_id());
            $fresh = wc_get_product($product->get_id());
            if (!$fresh || self::fields($fresh) !== $fields) {
                throw new RuntimeException('商品保存后的内容校验失败。');
            }
            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('无法提交商品保存事务。');
            return true;
        } catch (Throwable $error) {
            $wpdb->query('ROLLBACK');
            clean_post_cache($product->get_id());
            wc_delete_product_transients($product->get_id());
            return new WP_Error('agent_product_save', Oyiso_Agent_Config::error($error->getMessage()), ['status' => 500]);
        }
    }

    private function editable(int $id): WC_Product|WP_Error
    {
        if (!function_exists('wc_get_product') || !current_user_can('edit_products') || !current_user_can('edit_post', $id)) {
            return new WP_Error('agent_product_forbidden', '没有编辑该商品的权限。');
        }
        $product = wc_get_product($id);
        return $product instanceof WC_Product ? $product : new WP_Error('agent_product_missing', '商品不存在。');
    }

    public static function seoEnabled(): bool
    {
        return defined('SEOPRESS_VERSION');
    }

    /** @return Fields */
    public static function fields(WC_Product $product): array
    {
        $fields = ['description' => $product->get_description('edit'), 'short_description' => $product->get_short_description('edit')];
        if (self::seoEnabled()) {
            foreach (self::SEO as $field => $meta) {
                $value = get_post_meta($product->get_id(), $meta, true);
                $fields[$field] = is_string($value) ? $value : '';
            }
        }
        return $fields;
    }

    private static function date(string $date): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches) === 1 && checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]);
    }

    /** @param array<string, mixed> $input */
    private static function text(array $input, string $key): string
    {
        return is_string($input[$key] ?? null) ? $input[$key] : '';
    }

    /** @param array<string, mixed> $input */
    private static function integer(array $input, string $key): int
    {
        $value = $input[$key] ?? 0;
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : 0;
    }
}
