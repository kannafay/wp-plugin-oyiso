<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/** @phpstan-import-type Run from Oyiso_Agent_Store
 * @phpstan-import-type MCPAction from Oyiso_Agent_Store
 * @phpstan-type OrderRow array{id:int,number:string,status:string,status_label:string,total:string,currency:string}
 * @phpstan-type OrderItem array{id:int,number:string,before:string,before_label:string,after:string,after_label:string,result?:string,actual_status?:string}
 * @phpstan-type OrderPreview array{items:list<OrderItem>,status:string,label:string}
 */
final class Oyiso_Agent_Order_Update
{
    public const TOOL = 'oyiso/prepare-order-update';
    public const LIMIT = 100;

    /** @return array<string,array{string,string,array<string,mixed>,list<string>}> */
    public static function definitions(): array
    {
        $ids = ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1], 'minItems' => 1, 'maxItems' => self::LIMIT, 'uniqueItems' => true];
        return [
            'get-orders' => ['核对订单', '按明确的订单 ID 批量读取最新状态、金额和可用状态选项，不包含客户信息。修改前必须在本轮读取。最多 100 单；历史列表中的全部指已列出的 ID，不扩大为新的查询结果。', ['order_ids' => $ids], ['order_ids']],
            'prepare-order-update' => ['准备修改订单状态', '对本轮 get-orders 已读取的明确订单 ID 生成一张批量状态修改确认卡片。status 从读取结果的 statuses 选择；支持单个或多个订单。不立即修改，也不执行收款或退款。用户在卡片确认后，按 WooCommerce 正常状态变更流程执行。', ['order_ids' => $ids, 'status' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64]], ['order_ids', 'status']],
        ];
    }

    /** @param Run $run
     * @param array<string,mixed> $input
     * @return array<string,mixed>|WP_Error
     */
    public static function call(array &$run, string $name, array $input): array|WP_Error
    {
        $check = self::access($run); if (is_wp_error($check)) return $check;
        $ids = $input['order_ids'] ?? null;
        if (!is_array($ids) || !array_is_list($ids) || $ids === [] || count($ids) > self::LIMIT) return new WP_Error('agent_order_ids', '请提供 1–100 个明确的订单 ID。');
        foreach ($ids as $id) if (!is_int($id) || $id < 1) return new WP_Error('agent_order_ids', '订单 ID 必须是正整数。');
        if (count(array_unique($ids)) !== count($ids)) return new WP_Error('agent_order_ids', '订单 ID 不能重复。');
        sort($ids, SORT_NUMERIC);
        $rows = []; $fingerprints = [];
        foreach ($ids as $id) {
            $order = self::read($id); if (is_wp_error($order)) return $order;
            $rows[] = self::row($order); $fingerprints[$id] = self::fingerprint($order);
        }
        $statuses = self::statuses();
        if ($name === 'get-orders') {
            $run['order_snapshots'] = array_replace($run['order_snapshots'] ?? [], $fingerprints);
            return ['orders' => $rows, 'total' => count($rows), 'statuses' => $statuses, 'can_update' => Oyiso_Agent_Permissions::allows(self::TOOL), 'notice' => '以上为明确 ID 的最新订单信息。修改状态需要 WooCommerce 写入权限及确认卡片；不需要打开订单编辑页。'];
        }
        if ($name !== 'prepare-order-update') return new WP_Error('agent_unknown_tool', '订单工具不存在。');
        $permission = Oyiso_Agent_Permissions::check(self::TOOL); if (is_wp_error($permission)) return $permission;
        $status = is_string($input['status'] ?? null) ? (preg_replace('/^wc-/', '', $input['status']) ?? '') : '';
        if (!isset($statuses[$status])) return new WP_Error('agent_order_status', '目标状态未注册，请使用 get-orders 返回的 statuses。');
        foreach ($fingerprints as $id => $fingerprint) if (($run['order_snapshots'][$id] ?? '') !== $fingerprint) return new WP_Error('agent_order_read_required', '订单尚未在本轮核对或读取后发生变化，请重新 get-orders 后生成方案。');
        $json = (string) wp_json_encode(['order_ids' => $ids, 'status' => $status]);
        foreach ($run['mcp_actions'] ?? [] as $action) if ($action['name'] === self::TOOL && $action['input_json'] === $json && ($action['order_fingerprints'] ?? []) === $fingerprints && in_array($action['status'], ['pending', 'executing'], true)) return ['status' => $action['status'], 'action_id' => $action['id'], 'notice' => '该批订单已有待确认或正在执行的方案。'];
        if (count($run['mcp_actions'] ?? []) >= 20) return new WP_Error('agent_order_limit', '本次对话的操作方案已达上限，请新建对话。');
        $items = [];
        foreach ($rows as $row) $items[] = ['id' => $row['id'], 'number' => $row['number'], 'before' => $row['status'], 'before_label' => $row['status_label'], 'after' => $status, 'after_label' => $statuses[$status]];
        $id = wp_generate_uuid4();
        $run['mcp_actions'][] = ['id' => $id, 'name' => self::TOOL, 'label' => '修改 ' . count($items) . ' 个订单的状态', 'input_json' => $json, 'status' => 'pending', 'result' => '', 'orders' => ['items' => $items, 'status' => $status, 'label' => $statuses[$status]], 'order_fingerprints' => $fingerprints];
        return ['status' => 'pending', 'action_id' => $id, 'order_ids' => $ids, 'target_status' => $status, 'notice' => '订单状态尚未改变。请核对卡片中的全部订单和前后状态，再确认执行。'];
    }

    /** Only the authenticated confirmation endpoint may claim and execute this batch.
     * @param Run $run
     * @return array<string,mixed>|WP_Error
     */
    public static function apply(array &$run, string $id): array|WP_Error
    {
        $check = self::access($run); if (is_wp_error($check)) return $check;
        $permission = Oyiso_Agent_Permissions::check(self::TOOL); if (is_wp_error($permission)) return $permission;
        $stored = Oyiso_Agent_Store::get($run['id']); if (is_wp_error($stored)) return $stored;
        $index = null;
        foreach ($stored['mcp_actions'] ?? [] as $key => $action) if ($action['id'] === $id && $action['name'] === self::TOOL && $action['status'] === 'executing' && isset($action['orders'], $action['order_fingerprints'])) $index = $key;
        if ($index === null) return new WP_Error('agent_order_confirmation', '订单修改尚未得到有效确认。', ['status' => 403]);
        $action = $stored['mcp_actions'][$index];
        $preview = $action['orders'] ?? null; $fingerprints = $action['order_fingerprints'] ?? [];
        if ($preview === null || $preview['items'] === [] || count($preview['items']) > self::LIMIT || !isset(self::statuses()[$preview['status']])) return new WP_Error('agent_order_plan', '订单方案无效，请重新生成。');
        $locks = []; $applied = 0; $unchanged = 0;
        try {
            // Lock and preflight the entire fixed set before the first write.
            foreach ($preview['items'] as $item) {
                $lock = 'order_' . $item['id'];
                if (!Oyiso_Agent_Store::lock($lock)) return new WP_Error('agent_order_busy', '订单 #' . $item['id'] . ' 正在处理，本批尚未执行。');
                $locks[] = $lock;
                $order = self::read($item['id']); if (is_wp_error($order)) return $order;
                if (($fingerprints[$item['id']] ?? '') !== self::fingerprint($order)) return new WP_Error('agent_order_changed', '订单 #' . $item['id'] . ' 已变化，本批尚未执行。请重新核对并生成方案。');
            }
            foreach ($preview['items'] as $position => $item) {
                $check = self::access($run); $permission = Oyiso_Agent_Permissions::check(self::TOOL);
                $order = self::read($item['id']);
                if (is_wp_error($check) || is_wp_error($permission) || is_wp_error($order) || ($fingerprints[$item['id']] ?? '') !== self::fingerprint($order)) return self::partial($applied, '后续订单权限或数据已变化，已停止。');
                $preview['items'][$position] = array_replace($item, ['result' => 'executing']);
                if (!self::record($run, $index, $action, $preview)) return self::partial($applied, '无法保存执行记录，已停止。');
                if (is_wp_error(self::access($run)) || is_wp_error(Oyiso_Agent_Permissions::check(self::TOOL))) return self::partial($applied, '执行权限已变化，已停止。');
                try {
                    if ($order->get_status() === $preview['status']) { $unchanged++; $outcome = 'unchanged'; }
                    else {
                        $updated = $order->update_status($preview['status'], 'Oyiso AI：用户确认批量修改订单状态。', true);
                        $actual = self::read($item['id']);
                        if (!$updated || is_wp_error($actual) || $actual->get_status() !== $preview['status']) throw new RuntimeException('订单未确认达到目标状态。');
                        $applied++; $outcome = 'applied';
                    }
                    $preview['items'][$position] = array_replace($item, ['result' => $outcome, 'actual_status' => $preview['status']]);
                } catch (Throwable $error) {
                    $receipt = array_replace($item, ['result' => 'needs_review']);
                    $actual = self::read($item['id']);
                    if (!is_wp_error($actual)) $receipt['actual_status'] = $actual->get_status();
                    $preview['items'][$position] = $receipt;
                    self::record($run, $index, $action, $preview);
                    return self::partial($applied, '订单 #' . $item['id'] . ' 需要核对实际结果。' . Oyiso_Agent_Config::error($error->getMessage()));
                }
                if (!self::record($run, $index, $action, $preview)) return self::partial($applied, '执行记录保存失败，请核对实际订单，未自动重试。');
            }
            return ['updated' => $applied, 'unchanged' => $unchanged, 'total' => count($preview['items']), 'status' => $preview['status'], 'notice' => '仅处理已确认的订单 ID，已按 WooCommerce 正常状态变更流程执行。'];
        } finally { foreach ($locks as $lock) Oyiso_Agent_Store::unlock($lock); }
    }

    private static function partial(int $applied, string $reason): WP_Error
    {
        return new WP_Error('agent_order_incomplete', '本批已确认修改 ' . $applied . ' 单；' . $reason . ' 请查看逐单结果，不会回滚或重复执行已提交的状态变更。');
    }

    /** @param Run $run
     * @param MCPAction $action
     * @param OrderPreview $preview
     */
    private static function record(array &$run, int $index, array $action, array $preview): bool
    {
        $actions = $run['mcp_actions'] ?? [];
        if (!isset($actions[$index]) || $actions[$index]['id'] !== $action['id']) return false;
        $action['orders'] = $preview; $actions[$index] = $action; $run['mcp_actions'] = $actions;
        return Oyiso_Agent_Store::save($run, true);
    }

    /** @param Run $run */
    private static function access(array $run): true|WP_Error
    {
        return Oyiso_Agent_Config::enabled() && Oyiso_Agent_MCP::builtinEnabled(true) && current_user_can('manage_options') && current_user_can('manage_woocommerce') && Oyiso_Agent_Store::owned($run['id']) && !Oyiso_Agent_Store::cancelled($run['id']) && function_exists('wc_get_order') ? true : new WP_Error('agent_order_forbidden', '当前任务无权读取或修改 WooCommerce 订单。', ['status' => 403]);
    }

    private static function read(int $id): WC_Order|WP_Error
    {
        $order = wc_get_order($id);
        if (!$order instanceof WC_Order || $order->get_type() !== 'shop_order' || !current_user_can('edit_shop_order', $id)) return new WP_Error('agent_order_missing', '订单 #' . $id . ' 不存在或无权编辑。');
        // Refresh the CRUD object instead of relying on a previous in-request object.
        $store = $order->get_data_store();
        if (!$store instanceof WC_Data_Store) return new WP_Error('agent_order_store', '订单存储不可用。');
        $store->read($order);
        if (!$order instanceof WC_Order) return new WP_Error('agent_order_store', '订单存储返回了无效对象。');
        return isset(self::statuses()[$order->get_status()]) ? $order : new WP_Error('agent_order_state', '订单 #' . $id . ' 处于回收站或未注册状态，不能通过普通状态修改处理。');
    }

    /** @return OrderRow */
    private static function row(WC_Order $order): array
    {
        return ['id' => $order->get_id(), 'number' => (string) $order->get_order_number(), 'status' => $order->get_status(), 'status_label' => wc_get_order_status_name($order->get_status()), 'total' => (string) $order->get_total(), 'currency' => $order->get_currency()];
    }

    private static function fingerprint(WC_Order $order): string
    {
        return hash('sha256', (string) wp_json_encode([self::row($order), $order->get_date_modified()?->getTimestamp()]));
    }

    /** @return array<string,string> */
    private static function statuses(): array
    {
        $statuses = [];
        foreach (wc_get_order_statuses() as $status => $label) $statuses[preg_replace('/^wc-/', '', $status) ?? $status] = $label;
        return $statuses;
    }
}
