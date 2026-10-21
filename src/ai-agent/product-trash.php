<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;

/**
 * @phpstan-import-type Run from Oyiso_Agent_Store
 * @phpstan-import-type Change from Oyiso_Agent_Store
 * @phpstan-type ProductSnapshot array{hash:string,variations:array<int,string>}
 */
final class Oyiso_Agent_Product_Trash
{
    private const META = '_oyiso_agent_product_trash';

    public static function trashEnabled(): bool
    {
        $days = defined('EMPTY_TRASH_DAYS') ? constant('EMPTY_TRASH_DAYS') : 0;
        return is_numeric($days) && $days > 0;
    }

    /** Invalidate an old approval after a manual trash/restore cycle. */
    public static function statusChanged(string $new, string $old, WP_Post $post): void
    {
        if ($new !== $old && $post->post_type === 'product') delete_post_meta($post->ID, self::META);
    }

    /** @return ProductSnapshot|WP_Error */
    public static function snapshot(int $id): array|WP_Error
    {
        clean_post_cache($id);
        $post = get_post($id);
        if (!$post instanceof WP_Post) return new WP_Error('agent_product_missing', '商品不存在。');
        $state = self::state($post);
        if (is_wp_error($state)) return $state;
        $variations = [];
        $children = [];
        foreach (get_posts(['post_type' => 'product_variation', 'post_parent' => $id, 'post_status' => array_keys(get_post_stati()), 'numberposts' => -1, 'orderby' => 'ID', 'order' => 'ASC']) as $child) {
            clean_post_cache($child->ID);
            $child = get_post($child->ID);
            if (!$child instanceof WP_Post) return new WP_Error('agent_product_changed', '商品变体已发生变化，请重新读取。');
            $childState = self::state($child);
            if (is_wp_error($childState)) return $childState;
            $children[$child->ID] = $childState;
            $variations[$child->ID] = $child->post_status;
        }
        return ['hash' => hash('sha256', serialize([$state, $children])), 'variations' => $variations];
    }

    /** @return array<string,mixed>|WP_Error */
    private static function state(WP_Post $post): array|WP_Error
    {
        $meta = get_post_meta($post->ID);
        if (!is_array($meta)) return new WP_Error('agent_product_metadata', '无法读取商品资料，请稍后重试。');
        unset($meta['_edit_lock'], $meta['_edit_last'], $meta[self::META]);
        ksort($meta);
        $taxonomies = get_object_taxonomies($post->post_type);
        $terms = $taxonomies === [] ? [] : wp_get_object_terms($post->ID, $taxonomies, ['fields' => 'tt_ids']);
        if (is_wp_error($terms)) return $terms;
        sort($terms);
        return ['post' => $post->to_array(), 'meta' => $meta, 'terms' => $terms];
    }

    /** @param Run $run
     * @return array<string,mixed>|WP_Error
     */
    public static function prepare(array &$run, int $id, string $reason): array|WP_Error
    {
        $product = self::product($id);
        if (is_wp_error($product)) return $product;
        if (!self::trashEnabled()) return self::trashDisabled();
        if ($product->get_status() === 'trash') return new WP_Error('agent_product_trashed', '该商品已在回收站中。');
        $reason = sanitize_textarea_field($reason);
        if (trim($reason) === '' || mb_strlen($reason) > 500) return new WP_Error('agent_trash_reason', '请提供不超过 500 字的删除原因。');
        $snapshot = self::snapshot($id);
        if (is_wp_error($snapshot)) return $snapshot;
        if (($run['product_snapshots'][$id] ?? '') !== $snapshot['hash']) return new WP_Error('agent_product_not_read', '请先调用 get-product 读取最新商品及其变体，再准备删除方案。');
        if (count($run['changes']) >= 20) return new WP_Error('agent_proposal_limit', '本次对话的修改方案已达上限，请新建对话。');
        foreach ($run['changes'] as &$change) {
            if ($change['product_id'] === $id && $change['status'] === 'pending') $change['status'] = 'superseded';
        }
        unset($change);
        $proposal = wp_generate_uuid4();
        $run['changes'][] = [
            'id' => $proposal, 'type' => 'trash', 'product_id' => $id, 'name' => $product->get_name(), 'sku' => $product->get_sku(),
            'product_type' => $product->get_type(), 'reason' => $reason, 'previous_status' => $product->get_status(),
            'variation_count' => count(array_filter($snapshot['variations'], static fn(string $status): bool => $status !== 'trash')),
            'variation_statuses' => $snapshot['variations'], 'fingerprint' => $snapshot['hash'],
            'before' => [], 'after' => [], 'status' => 'pending',
        ];
        return ['proposal_id' => $proposal, 'product_id' => $id, 'status' => 'awaiting_user_review', 'notice' => '删除方案已生成，商品尚未移动。请用户核对卡片中的商品，再确认移入回收站；可以恢复，不会永久删除商品或删除图片。'];
    }

    /** @param Run $run
     * @return array<string,mixed>|WP_Error
     */
    public static function change(array &$run, string $proposal, string $action, string $approval): array|WP_Error
    {
        if ($approval !== $action || !in_array($action, ['apply', 'revert', 'dismiss'], true)) return new WP_Error('agent_approval_required', '请通过商品操作卡片确认。');
        foreach ($run['changes'] as &$change) {
            if ($change['id'] !== $proposal || ($change['type'] ?? '') !== 'trash') continue;
            $done = match ($action) { 'apply' => 'applied', 'revert' => 'reverted', default => 'dismissed' };
            if ($change['status'] === $done) return ['status' => $done, 'product_id' => $change['product_id']];
            if ($change['status'] !== ($action === 'revert' ? 'applied' : 'pending')) return new WP_Error('agent_proposal_state', '此商品操作当前无法执行。');
            if ($action === 'dismiss') {
                $change['status'] = $done;
                Oyiso_Agent_Store::event($run, 'info', '已取消商品删除', $change['name']);
                self::record($run, $change, $done);
                return ['status' => $done, 'product_id' => $change['product_id']];
            }
            $resource = 'product_' . $change['product_id'];
            if (!Oyiso_Agent_Store::lock($resource)) return new WP_Error('agent_product_busy', '该商品正在由其他任务处理，请稍后重试。');
            try {
                $result = self::move($run['id'], $change, $action);
                if (is_wp_error($result)) return $result;
                $change['status'] = $done;
                Oyiso_Agent_Store::event($run, 'success', $action === 'revert' ? '已恢复商品' : '商品已移入回收站', $change['name']);
                self::record($run, $change, $done);
                return ['status' => $done, 'product_id' => $change['product_id']];
            } finally {
                Oyiso_Agent_Store::unlock($resource);
            }
        }
        return new WP_Error('agent_proposal_missing', '商品删除方案不存在。');
    }

    /** @param Run $run
     * @param Change $change
     */
    private static function record(array &$run, array $change, string $status): void
    {
        $detail = match ($status) {
            'applied' => '已由用户确认并移入回收站，可在保留期间恢复。',
            'reverted' => '已由用户恢复商品及本次操作涉及的变体。',
            default => '用户已取消删除，商品未变更；除非用户再次明确要求，不要重新提交。',
        };
        $run['messages'][] = (new UserMessage([new MessagePart('后台商品操作结果（事实记录）：product_id=' . $change['product_id'] . '; proposal_id=' . $change['id'] . '; status=' . $status . '。' . $detail)]))->toArray();
    }

    /** @param Change $change */
    private static function move(int $runId, array &$change, string $action): true|WP_Error
    {
        global $wpdb;
        $id = $change['product_id'];
        $restore = $action === 'revert';
        $product = self::product($id);
        if (is_wp_error($product)) return $product;
        if (!$restore && !self::trashEnabled()) return self::trashDisabled();
        if ($restore && in_array($change['previous_status'] ?? '', ['publish', 'future'], true) && !current_user_can('publish_products')) return new WP_Error('agent_product_forbidden', '没有将该商品恢复到发布状态的权限。');
        $ids = [$id];
        $skipOldTrash = static function (mixed $pre, WP_Post $post) use ($change): mixed {
            return ($change['variation_statuses'][$post->ID] ?? '') === 'trash' ? false : $pre;
        };
        if ($wpdb->query('START TRANSACTION') === false) return new WP_Error('agent_product_transaction', '无法开始商品操作，请稍后重试。');
        try {
            $locked = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID = %d OR (post_parent = %d AND post_type = 'product_variation') ORDER BY ID FOR UPDATE", $id, $id));
            $ids = array_map(static function (mixed $value): int {
                if (!is_int($value) && !(is_string($value) && ctype_digit($value))) throw new RuntimeException('商品锁定结果无效。');
                return (int) $value;
            }, $locked);
            if (!in_array($id, $ids, true)) throw new RuntimeException('商品已不存在。');
            self::lockRows($wpdb->prepare("SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d OR post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation') FOR UPDATE", $id, $id));
            self::lockRows($wpdb->prepare("SELECT term_taxonomy_id FROM {$wpdb->term_relationships} WHERE object_id = %d OR object_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation') FOR UPDATE", $id, $id));
            foreach ($ids as $postId) clean_post_cache($postId);
            $snapshot = self::snapshot($id);
            if (is_wp_error($snapshot)) throw new RuntimeException($snapshot->get_error_message());
            foreach ($snapshot['variations'] as $childId => $status) {
                if ($status !== 'trash' && !current_user_can('delete_post', $childId)) throw new RuntimeException('没有操作部分商品变体的权限。');
            }
            $marker = get_post_meta($id, self::META, true);
            $owned = is_array($marker) && ($marker['proposal'] ?? '') === $change['id'] && ($marker['run'] ?? 0) === $runId;
            // A committed operation may have outlived the response/run save.
            if ($owned && ($marker['action'] ?? '') === $action && ($marker['fingerprint'] ?? '') === $snapshot['hash']) {
                $change['fingerprint'] = $snapshot['hash'];
                if ($wpdb->query('COMMIT') === false) throw new RuntimeException('无法提交商品操作。');
                return true;
            }
            if (($change['fingerprint'] ?? '') !== $snapshot['hash']) throw new RuntimeException('商品或变体已发生变化，请重新读取并生成操作方案；此次未执行。');
            if ($restore) {
                if (!$owned || ($marker['action'] ?? '') !== 'apply' || get_post_status($id) !== 'trash') throw new RuntimeException('该商品的回收站状态已变化，无法按此方案恢复。');
                foreach ($ids as $postId) {
                    if ($postId !== $id && ($change['variation_statuses'][$postId] ?? '') === 'trash') continue;
                    $item = wc_get_product($postId);
                    if ($item instanceof WC_Product && $item->get_sku() !== '' && !wc_product_has_unique_sku($postId, $item->get_sku())) throw new RuntimeException('原 SKU 已被其他商品使用，请先在商品后台处理，再恢复。');
                }
                add_filter('pre_untrash_post', $skipOldTrash, 10, 2);
                $result = wp_untrash_post($id);
            } else {
                if (get_post_status($id) === 'trash') throw new RuntimeException('该商品已在回收站中。');
                $result = wp_trash_post($id);
            }
            foreach ($ids as $postId) clean_post_cache($postId);
            $expected = $restore ? ($change['previous_status'] ?? '') : 'trash';
            if (!$result instanceof WP_Post || get_post_status($id) !== $expected) throw new RuntimeException('商品操作未完成，已停止本次操作。');
            foreach ($change['variation_statuses'] ?? [] as $childId => $status) {
                if (get_post_status($childId) !== ($restore ? $status : 'trash')) throw new RuntimeException('部分商品变体未完成操作，已停止本次操作。');
            }
            $after = self::snapshot($id);
            if (is_wp_error($after)) throw new RuntimeException($after->get_error_message());
            if (!update_post_meta($id, self::META, ['proposal' => $change['id'], 'run' => $runId, 'action' => $action, 'fingerprint' => $after['hash']])) throw new RuntimeException('无法保存商品操作记录。');
            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('无法提交商品操作。');
            $change['fingerprint'] = $after['hash'];
            wc_delete_product_transients($id);
            return true;
        } catch (Throwable $error) {
            $wpdb->query('ROLLBACK');
            foreach ($ids as $postId) clean_post_cache($postId);
            wc_delete_product_transients($id);
            return new WP_Error('agent_product_trash_conflict', Oyiso_Agent_Config::error($error->getMessage()), ['status' => 409]);
        } finally {
            remove_filter('pre_untrash_post', $skipOldTrash, 10);
        }
    }

    private static function lockRows(string $query): void
    {
        global $wpdb;
        $wpdb->get_col($query);
        if ($wpdb->last_error !== '') throw new RuntimeException('无法锁定商品资料，请稍后重试。');
    }

    private static function product(int $id): WC_Product|WP_Error
    {
        if (!function_exists('wc_get_product') || !current_user_can('edit_products') || !current_user_can('delete_products') || !current_user_can('edit_post', $id) || !current_user_can('delete_post', $id)) return new WP_Error('agent_product_forbidden', '没有删除或恢复该商品的权限。');
        if (get_post_type($id) !== 'product') return new WP_Error('agent_product_type', '此操作只支持主商品，不支持单独删除变体或其他内容。');
        $product = wc_get_product($id);
        return $product instanceof WC_Product ? $product : new WP_Error('agent_product_missing', '商品不存在。');
    }

    private static function trashDisabled(): WP_Error
    {
        return new WP_Error('agent_trash_disabled', '站点已禁用回收站，无法安全移入回收站；此次未删除商品。');
    }
}
