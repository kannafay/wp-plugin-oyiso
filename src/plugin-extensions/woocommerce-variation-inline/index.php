<?php

defined('ABSPATH') || exit;

if (!class_exists('Oyiso_WC_Variation_Inline')) {
    final class Oyiso_WC_Variation_Inline
    {
        private const OPTION_ENABLED = 'oyiso_wc_variation_inline_enabled';
        private const OPTION_UNLIMITED_PAGINATION = 'oyiso_wc_variation_inline_unlimited_pagination';
        private const AJAX_ACTION = 'oyiso_wc_variation_inline_save';
        private const NONCE_ACTION = 'oyiso_wc_variation_inline_nonce';
        private const AJAX_GENERATE_SKU = 'oyiso_wc_variation_inline_generate_sku';

        public static function init(): void
        {
            if (!self::isEnabled()) {
                return;
            }

            if (self::isUnlimitedPagination()) {
                add_filter('woocommerce_admin_meta_boxes_variations_per_page', fn() => 9999);
            }
            add_action('admin_enqueue_scripts', [__CLASS__, 'enqueueAssets']);
            add_action('wp_ajax_' . self::AJAX_ACTION, [__CLASS__, 'ajaxSave']);
            add_action('woocommerce_variable_product_bulk_edit_actions', [__CLASS__, 'addSkuBulkActions']);
            add_action('wp_ajax_' . self::AJAX_GENERATE_SKU, [__CLASS__, 'ajaxGenerateSku']);
        }

        public static function isEnabled(): bool
        {
            $options = get_option('oyiso', []);
            return !empty($options[self::OPTION_ENABLED]);
        }

        public static function isUnlimitedPagination(): bool
        {
            $options = get_option('oyiso', []);
            return !empty($options[self::OPTION_UNLIMITED_PAGINATION]);
        }

        public static function enqueueAssets(string $hook): void
        {
            if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
                return;
            }

            $screen = get_current_screen();
            if (!$screen || $screen->post_type !== 'product') {
                return;
            }

            wp_enqueue_media();
            wp_enqueue_script(
                'oyiso-wc-variation-inline',
                plugins_url('assets/variation-inline.js', __FILE__),
                ['jquery', 'wp-mediaelement'],
                filemtime(__DIR__ . '/assets/variation-inline.js'),
                true
            );

            wp_localize_script('oyiso-wc-variation-inline', 'oyisoVIConfig', [
                'nonce' => wp_create_nonce(self::AJAX_ACTION),
                'ajaxurl' => admin_url('admin-ajax.php'),
                'action' => self::AJAX_ACTION,
                'wc_decimal_sep' => wc_get_price_decimal_separator(),
                'wc_thousand_sep' => wc_get_price_thousand_separator(),
                'placeholder_img_src' => wc_placeholder_img_src(),
                'generate_sku_action' => self::AJAX_GENERATE_SKU,
                'product_id' => get_the_ID() ?: 0,
            ]);

            wp_enqueue_style(
                'oyiso-wc-variation-inline',
                plugins_url('assets/variation-inline.css', __FILE__),
                [],
                filemtime(__DIR__ . '/assets/variation-inline.css')
            );
        }

        public static function addSkuBulkActions(): void
        {
            ?>
            <optgroup label="SKU">
                <option value="oyiso_regenerate_sku">全部重新生成</option>
                <option value="oyiso_generate_missing_sku">仅补全缺失</option>
            </optgroup>
            <?php
        }

        public static function ajaxSave(): void
        {
            check_ajax_referer(self::AJAX_ACTION, 'nonce');

            if (!current_user_can('edit_products')) {
                wp_send_json_error(['message' => '权限不足']);
            }

            $variation_id = isset($_POST['variation_id']) ? absint($_POST['variation_id']) : 0;
            $field = isset($_POST['field']) ? sanitize_text_field(wp_unslash($_POST['field'])) : '';
            $raw_value = isset($_POST['value']) ? wp_unslash($_POST['value']) : '';

            $allowed = ['regular_price', 'sale_price', 'stock_status', 'enabled', 'image_id'];
            if (!$variation_id || !in_array($field, $allowed, true)) {
                wp_send_json_error(['message' => '参数无效']);
            }

            $variation = wc_get_product($variation_id);
            if (!$variation || !$variation->is_type('variation')) {
                wp_send_json_error(['message' => '变体不存在']);
            }

            switch ($field) {
                case 'regular_price':
                    $variation->set_regular_price($raw_value);
                    break;
                case 'sale_price':
                    $variation->set_sale_price($raw_value);
                    break;
                case 'stock_status':
                    $allowed_statuses = ['instock', 'outofstock', 'onbackorder'];
                    if (!in_array($raw_value, $allowed_statuses, true)) {
                        wp_send_json_error(['message' => '无效的库存状态']);
                    }
                    $variation->set_stock_status($raw_value);
                    break;
                case 'enabled':
                    $variation->set_status($raw_value ? 'publish' : 'private');
                    break;
                case 'image_id':
                    $variation->set_image_id(absint($raw_value));
                    break;
            }

            $variation->save();
            wp_send_json_success(['message' => '已保存']);
        }

        public static function ajaxGenerateSku(): void
        {
            check_ajax_referer(self::NONCE_ACTION, 'nonce');

            if (!current_user_can('edit_products')) {
                wp_send_json_error(['message' => '权限不足']);
            }

            $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
            $mode = isset($_POST['mode']) ? sanitize_text_field(wp_unslash($_POST['mode'])) : 'missing';

            if (!$product_id) {
                wp_send_json_error(['message' => '参数无效']);
            }

            $product = wc_get_product($product_id);
            if (!$product || !$product->is_type('variable')) {
                wp_send_json_error(['message' => '不是可变产品']);
            }

            $parent_sku = get_post_meta($product_id, '_sku', true);
            $variation_ids = $product->get_children();
            $results = [];
            $skipped = 0;

            foreach ($variation_ids as $variation_id) {
                $variation = wc_get_product($variation_id);
                if (!$variation) continue;

                $child_sku = get_post_meta($variation_id, '_sku', true);

                if ($mode === 'missing' && !empty($child_sku)) {
                    $skipped++;
                    continue;
                }

                if (!$parent_sku && !$child_sku) {
                    continue;
                }

                $pending_sku = '';

                if ($parent_sku && !$child_sku) {
                    $attr_slugs = [];
                    $attributes = $variation->get_attributes();
                    foreach ($attributes as $attr_value) {
                        if ($attr_value && '' !== $attr_value) {
                            $attr_slugs[] = sanitize_title($attr_value);
                        }
                    }
                    $pending_sku = $parent_sku . ($attr_slugs ? '-' . implode('-', $attr_slugs) : '');
                } elseif ($parent_sku && $child_sku) {
                    $p = strtoupper($parent_sku);
                    $c = strtoupper($child_sku);
                    $pending_sku = str_starts_with($c, $p) ? $c : $p . '-' . $c;
                } else {
                    $pending_sku = $child_sku;
                }

                $pending_sku = strtoupper(trim($pending_sku, '- '));

                if (!$pending_sku) continue;

                $sku_to_set = $pending_sku;
                if (wc_get_product_id_by_sku($sku_to_set)) {
                    $sku_to_set .= '-' . strtoupper(wp_generate_password(4, false));
                }

                update_post_meta($variation_id, '_sku', $sku_to_set);
                if (function_exists('wc_update_product_lookup_tables_column')) {
                    wc_update_product_lookup_tables_column('sku', [$variation_id]);
                }

                $results[] = [
                    'variation_id' => $variation_id,
                    'sku' => $sku_to_set,
                ];
            }

            wp_send_json_success([
                'message' => sprintf('SKU 生成完成：成功 %d 个，跳过 %d 个', count($results), $skipped),
                'results' => $results,
            ]);
        }
    }
}

Oyiso_WC_Variation_Inline::init();
