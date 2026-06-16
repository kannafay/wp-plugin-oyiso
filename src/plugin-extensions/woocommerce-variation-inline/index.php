<?php

defined('ABSPATH') || exit;

if (!class_exists('Oyiso_WC_Variation_Inline')) {
    final class Oyiso_WC_Variation_Inline
    {
        private const OPTION_ENABLED = 'oyiso_wc_variation_inline_enabled';
        private const AJAX_ACTION = 'oyiso_wc_variation_inline_save';
        private const NONCE_ACTION = 'oyiso_wc_variation_inline_nonce';

        public static function init(): void
        {
            if (!self::isEnabled()) {
                return;
            }

            add_action('admin_enqueue_scripts', [__CLASS__, 'enqueueAssets']);
            add_action('wp_ajax_' . self::AJAX_ACTION, [__CLASS__, 'ajaxSave']);
        }

        public static function isEnabled(): bool
        {
            $options = get_option('oyiso', []);
            return !empty($options[self::OPTION_ENABLED]);
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

            wp_enqueue_script(
                'oyiso-wc-variation-inline',
                plugins_url('assets/variation-inline.js', __FILE__),
                ['jquery'],
                '1.0.0',
                true
            );

            wp_localize_script('oyiso-wc-variation-inline', 'oyisoVIConfig', [
                'nonce' => wp_create_nonce(self::AJAX_ACTION),
                'ajaxurl' => admin_url('admin-ajax.php'),
                'action' => self::AJAX_ACTION,
            ]);

            wp_enqueue_style(
                'oyiso-wc-variation-inline',
                plugins_url('assets/variation-inline.css', __FILE__),
                [],
                '1.0.0'
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

            $allowed = ['regular_price', 'sale_price', 'stock_status', 'enabled'];
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
            }

            $variation->save();
            wp_send_json_success(['message' => '已保存']);
        }
    }
}

Oyiso_WC_Variation_Inline::init();
