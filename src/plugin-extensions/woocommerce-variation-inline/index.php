<?php

defined('ABSPATH') || exit;

if (!class_exists('Oyiso_WC_Variation_Inline')) {
    final class Oyiso_WC_Variation_Inline
    {
        private const OPTION_ENABLED = 'oyiso_wc_variation_inline_enabled';
        private const OPTION_UNLIMITED_PAGINATION = 'oyiso_wc_variation_inline_unlimited_pagination';
        private const AJAX_ACTION = 'oyiso_wc_variation_inline_save';
        private const NONCE_ACTION = 'oyiso_wc_variation_inline_nonce';

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
            ]);

            wp_enqueue_style(
                'oyiso-wc-variation-inline',
                plugins_url('assets/variation-inline.css', __FILE__),
                [],
                filemtime(__DIR__ . '/assets/variation-inline.css')
            );
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
    }
}

Oyiso_WC_Variation_Inline::init();
