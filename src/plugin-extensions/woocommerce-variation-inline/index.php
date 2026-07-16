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
        private const OPTION_SKU_BATCH = 'oyiso_wc_variation_sku_batch_enabled';
        private const OPTION_QUICK_OPS = 'oyiso_wc_variation_quick_ops';

        public static function init(): void
        {
            if (self::isUnlimitedPagination()) {
                add_filter('woocommerce_admin_meta_boxes_variations_per_page', fn() => 9999);
            }

            if (self::isEnabled()) {
                add_action('admin_enqueue_scripts', [__CLASS__, 'enqueueAssets']);
            }

            if (self::isEnabled()) {
                add_action('wp_ajax_' . self::AJAX_ACTION, [__CLASS__, 'ajaxSave']);
            }

            if (self::isSkuBatchEnabled()) {
                add_action('woocommerce_variable_product_bulk_edit_actions', [__CLASS__, 'addSkuBulkActions']);
                if (!self::isEnabled()) {
                    add_action('admin_enqueue_scripts', [__CLASS__, 'enqueueSkuAssets']);
                }
            }

            // SKU 生成 AJAX 与确认弹窗：批量操作或变体快速编辑任一开启即可用
            if (self::isEnabled() || self::isSkuBatchEnabled()) {
                add_action('wp_ajax_' . self::AJAX_GENERATE_SKU, [__CLASS__, 'ajaxGenerateSku']);
                add_action('admin_footer', [__CLASS__, 'renderSkuConfirmTemplate']);
            }
        }

        public static function isEnabled(): bool
        {
            return self::getSwitchValue(self::OPTION_ENABLED);
        }

        public static function isUnlimitedPagination(): bool
        {
            return self::getSwitchValue(self::OPTION_UNLIMITED_PAGINATION);
        }

        public static function isSkuBatchEnabled(): bool
        {
            return self::getSwitchValue(self::OPTION_SKU_BATCH);
        }

        private static function getSwitchValue(string $key): bool
        {
            $options = get_option('oyiso', []);
            $quick_ops = $options[self::OPTION_QUICK_OPS] ?? [];

            return is_array($quick_ops) && !empty($quick_ops[$key]);
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
                plugins_url('assets/variation-inline.js?v=' . filemtime(__DIR__ . '/assets/variation-inline.js'), __FILE__),
                ['jquery', 'wp-mediaelement'],
                filemtime(__DIR__ . '/assets/variation-inline.js'),
                true
            );

            wp_localize_script('oyiso-wc-variation-inline', 'oyisoVIConfig', [
                'nonce' => wp_create_nonce(self::AJAX_ACTION),
                'ajaxurl' => admin_url('admin-ajax.php'),
                'action' => self::AJAX_ACTION,
                'variation_save_action' => 'woocommerce_save_variations',
                'variation_save_nonce' => wp_create_nonce('save-variations'),
                'wc_decimal_sep' => wc_get_price_decimal_separator(),
                'wc_thousand_sep' => wc_get_price_thousand_separator(),
                'placeholder_img_src' => wc_placeholder_img_src(),
                'generate_sku_action' => self::AJAX_GENERATE_SKU,
                'product_id' => isset($_GET['post']) ? absint($_GET['post']) : 0,
                'enable_inline' => self::isEnabled(),
                'enable_sku_batch' => self::isSkuBatchEnabled(),
            ]);

            wp_enqueue_style(
                'oyiso-wc-variation-inline',
                plugins_url('assets/variation-inline.css', __FILE__),
                [],
                filemtime(__DIR__ . '/assets/variation-inline.css')
            );
        }

        public static function enqueueSkuAssets(string $hook): void
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
                ['jquery', 'wp-mediaelement'],
                filemtime(__DIR__ . '/assets/variation-inline.js'),
                true
            );

            wp_localize_script('oyiso-wc-variation-inline', 'oyisoVIConfig', [
                'nonce' => wp_create_nonce(self::AJAX_ACTION),
                'ajaxurl' => admin_url('admin-ajax.php'),
                'action' => self::AJAX_ACTION,
                'variation_save_action' => 'woocommerce_save_variations',
                'variation_save_nonce' => wp_create_nonce('save-variations'),
                'generate_sku_action' => self::AJAX_GENERATE_SKU,
                'product_id' => isset($_GET['post']) ? absint($_GET['post']) : 0,
                'enable_inline' => self::isEnabled(),
                'enable_sku_batch' => self::isSkuBatchEnabled(),
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
                <option value="oyiso_regenerate_sku">生成全部SKU</option>
                <option value="oyiso_generate_missing_sku">补全缺失SKU</option>
                <option value="oyiso_clear_sku">清除全部SKU</option>
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
            check_ajax_referer(self::AJAX_ACTION, 'nonce');

            if (!current_user_can('edit_products')) {
                wp_send_json_error(['message' => '权限不足']);
            }

            $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
            $mode = isset($_POST['mode']) ? sanitize_text_field(wp_unslash($_POST['mode'])) : 'missing';

            if (!$product_id) {
                wp_send_json_error(['message' => '参数无效']);
            }

            if (!in_array($mode, ['clear', 'all', 'missing'], true)) {
                wp_send_json_error(['message' => '无效的 SKU 操作']);
            }

            $product = wc_get_product($product_id);
            if (!$product || !$product->is_type('variable')) {
                wp_send_json_error(['message' => '不是可变产品']);
            }

            $parent_sku = get_post_meta($product_id, '_sku', true);
            $prefix = isset($_POST['prefix']) ? sanitize_text_field(wp_unslash($_POST['prefix'])) : '';
            $base_sku = !empty($prefix) ? $prefix : $parent_sku;
            $use_abbr = !empty($_POST['use_abbr']);
            $preview_only = !empty($_POST['preview']);

            $single_variation_id = isset($_POST['variation_id']) ? absint($_POST['variation_id']) : 0;
            if ($single_variation_id) {
                // 仅处理单个变体，校验其归属
                $single = wc_get_product($single_variation_id);
                if (!$single || !$single->is_type('variation') || (int) $single->get_parent_id() !== $product_id) {
                    wp_send_json_error(['message' => '变体不存在']);
                }
                $variation_ids = [$single_variation_id];
            } else {
                $variation_ids = $product->get_children();
                if (empty($variation_ids)) {
                    // 降级：直接查数据库
                    global $wpdb;
                    $variation_ids = $wpdb->get_col($wpdb->prepare(
                        "SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation' AND post_status IN ('publish', 'private', 'draft')",
                        $product_id
                    ));
                }
            }
            $results = [];
            $skipped = 0;

            foreach ($variation_ids as $variation_id) {
                $variation = wc_get_product($variation_id);
                if (!$variation) continue;

                if ($mode === 'clear') {
                    $child_sku = get_post_meta($variation_id, '_sku', true);
                    if (empty($child_sku)) {
                        $skipped++;
                        continue;
                    }
                    if ($preview_only) {
                        $results[] = ['variation_id' => $variation_id, 'sku' => $child_sku];
                        continue;
                    }
                    delete_post_meta($variation_id, '_sku');
                    if (function_exists('wc_update_product_lookup_tables_column')) {
                        wc_update_product_lookup_tables_column('sku', [$variation_id]);
                    }
                    $results[] = ['variation_id' => $variation_id, 'sku' => ''];
                    continue;
                }

                if ($mode === 'missing' && !empty(get_post_meta($variation_id, '_sku', true))) {
                    $skipped++;
                    continue;
                }

                // 规则：base（前缀，留空用父产品 SKU）+ 各属性值拼接；base 不存在则仅用属性值
                $attr_labels = [];
                $attr_parts = [];
                foreach ($variation->get_attributes() as $attr_name => $attr_value) {
                    if ($attr_value && '' !== $attr_value) {
                        $attr_label = self::getVariationAttributeLabel($attr_name, $attr_value);
                        $attr_labels[] = $attr_label;
                        $attr_parts[] = $use_abbr
                            ? self::abbreviateSkuAttribute($attr_label)
                            : sanitize_title($attr_label);
                    }
                }
                $attr_part = implode('-', array_filter($attr_parts));

                if ($base_sku) {
                    $pending_sku = $attr_part ? $base_sku . '-' . $attr_part : $base_sku;
                } else {
                    $pending_sku = $attr_part;
                }

                $pending_sku = strtoupper(trim($pending_sku, '- '));

                if (!$pending_sku) continue;

                $sku_to_set = $pending_sku;
                $existing_id = (int) wc_get_product_id_by_sku($sku_to_set);
                $attempts = 0;
                while ($existing_id && $existing_id !== (int) $variation_id && $attempts < 5) {
                    $sku_to_set = $pending_sku . '-' . strtoupper(wp_generate_password(4, false));
                    $existing_id = (int) wc_get_product_id_by_sku($sku_to_set);
                    $attempts++;
                }

                if ($existing_id && $existing_id !== (int) $variation_id) {
                    $skipped++;
                    continue;
                }

                if (!$preview_only) {
                    update_post_meta($variation_id, '_sku', $sku_to_set);
                    if (function_exists('wc_update_product_lookup_tables_column')) {
                        wc_update_product_lookup_tables_column('sku', [$variation_id]);
                    }
                }

                $results[] = [
                    'variation_id' => $variation_id,
                    'sku' => $sku_to_set,
                    'attrs' => $preview_only ? $attr_labels : [],
                ];
            }

            if ($preview_only) {
                $message = ($mode === 'clear')
                    ? sprintf('将清除 %d 个，跳过 %d 个', count($results), $skipped)
                    : sprintf('将生成 %d 个，跳过 %d 个', count($results), $skipped);
            } elseif ($single_variation_id) {
                if ($mode === 'clear') {
                    $message = !empty($results) ? '已删除该变体 SKU' : '该变体没有 SKU';
                } elseif (!empty($results) && !empty($results[0]['sku'])) {
                    $message = sprintf('已生成 SKU：%s', $results[0]['sku']);
                } else {
                    $message = '生成失败：该变体可能缺少属性值或 SKU 已被占用';
                }
            } else {
                $message = ($mode === 'clear')
                    ? sprintf('SKU 清除完成：清除 %d 个，跳过 %d 个', count($results), $skipped)
                    : sprintf('SKU 生成完成：成功 %d 个，跳过 %d 个', count($results), $skipped);
            }

            wp_send_json_success([
                'message' => $message,
                'results' => $results,
            ]);
        }

        private static function getVariationAttributeLabel(string $attr_name, string $attr_value): string
        {
            if (taxonomy_exists($attr_name)) {
                $term = get_term_by('slug', $attr_value, $attr_name);
                if ($term && !is_wp_error($term)) {
                    return $term->name;
                }
            }

            return $attr_value;
        }

        private static function abbreviateSkuAttribute(string $value): string
        {
            $value = html_entity_decode($value, ENT_QUOTES, get_bloginfo('charset') ?: 'UTF-8');
            $parts = preg_split('/[&+\/|,，、;；]+/u', $value, -1, PREG_SPLIT_NO_EMPTY);
            $groups = [];

            foreach ($parts ?: [] as $part) {
                $words = preg_split('/[^\p{L}\p{N}]+/u', $part, -1, PREG_SPLIT_NO_EMPTY);
                $initials = [];

                foreach ($words ?: [] as $word) {
                    $initial = function_exists('mb_substr') ? mb_substr($word, 0, 1) : substr($word, 0, 1);
                    if ($initial !== '') {
                        $initials[] = sanitize_title($initial);
                    }
                }

                $group = implode('', array_filter($initials));
                if ($group !== '') {
                    $groups[] = $group;
                }
            }

            return implode('-', $groups);
        }

        public static function renderSkuConfirmTemplate(): void
        {
            $screen = get_current_screen();
            if (!$screen || $screen->post_type !== 'product' || !in_array($screen->base, ['post', 'edit'], true)) {
                return;
            }
            ?>
            <div id="oyiso-vi-sku-modal" class="oyiso-vi-sku-modal-backdrop" style="display:none;">
                <div class="oyiso-vi-sku-modal-content">
                    <div class="oyiso-vi-sku-modal-header">
                        <h2 id="oyiso-vi-sku-modal-title">确认操作</h2>
                        <button type="button" class="oyiso-vi-sku-modal-close">&times;</button>
                    </div>
                    <div class="oyiso-vi-sku-modal-body">
                        <p id="oyiso-vi-sku-modal-message"></p>
                        <div class="oyiso-vi-sku-prefix-field" style="display:none;">
                            <label for="oyiso-vi-sku-prefix">SKU 前缀（留空默认用父产品 SKU，父产品无 SKU 则无前缀）</label>
                            <input type="text" id="oyiso-vi-sku-prefix" class="oyiso-vi-sku-prefix-input" placeholder="例：VAPE-100" />
                        </div>
                        <label class="oyiso-vi-sku-abbr-field" style="display:none;">
                            <input type="checkbox" id="oyiso-vi-sku-use-abbr" />
                            <span>启用缩写：属性值按每个单词首字母生成，特殊字符作为分隔</span>
                        </label>
                        <div class="oyiso-vi-sku-preview" style="display:none;">
                            <span class="oyiso-vi-sku-preview-label">生成预览</span>
                            <code class="oyiso-vi-sku-preview-value"></code>
                        </div>
                    </div>
                    <div class="oyiso-vi-sku-modal-footer">
                        <button type="button" class="button oyiso-vi-sku-modal-delete" style="display:none;">删除 SKU</button>
                        <span class="spinner oyiso-vi-sku-modal-spinner" style="float:none;margin:0 6px 0 0;"></span>
                        <button type="button" class="button oyiso-vi-sku-modal-cancel">取消</button>
                        <button type="button" class="button button-primary oyiso-vi-sku-modal-do">确认</button>
                    </div>
                    <div class="oyiso-vi-sku-confirm" style="display:none;">
                        <div class="oyiso-vi-sku-confirm-box">
                            <h2>删除变体 SKU</h2>
                            <p>确认删除当前变体 SKU？此操作不可撤销。</p>
                            <div class="oyiso-vi-sku-confirm-footer">
                                <button type="button" class="button oyiso-vi-sku-confirm-cancel">取消</button>
                                <button type="button" class="button oyiso-vi-sku-confirm-ok">确认删除</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php
        }
    }
}

Oyiso_WC_Variation_Inline::init();
