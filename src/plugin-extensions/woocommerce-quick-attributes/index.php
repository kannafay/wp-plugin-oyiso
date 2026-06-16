<?php

defined('ABSPATH') || exit;

if (!class_exists('Oyiso_WC_Quick_Attributes')) {
    final class Oyiso_WC_Quick_Attributes
    {
        private const OPTION_ENABLED = 'oyiso_wc_quick_attributes_enabled';
        private const AJAX_ACTION = 'oyiso_wc_quick_attributes';
        private const AJAX_PREVIEW_ACTION = 'oyiso_wc_quick_attributes_preview';
        private const AJAX_GET_TERMS_ACTION = 'oyiso_wc_quick_attributes_get_terms';
        private const NONCE_ACTION = 'oyiso_wc_quick_attributes_nonce';

        public static function init(): void
        {
            if (!self::isEnabled()) {
                return;
            }

            add_action('woocommerce_product_option_terms', [__CLASS__, 'renderButton'], 10, 3);
            add_action('admin_footer', [__CLASS__, 'renderModal']);
            add_action('admin_enqueue_scripts', [__CLASS__, 'enqueueAssets']);
            add_action('wp_ajax_' . self::AJAX_ACTION, [__CLASS__, 'ajaxAddTerms']);
            add_action('wp_ajax_' . self::AJAX_PREVIEW_ACTION, [__CLASS__, 'ajaxPreview']);
            add_action('wp_ajax_' . self::AJAX_GET_TERMS_ACTION, [__CLASS__, 'ajaxGetTerms']);
        }

        public static function isEnabled(): bool
        {
            $options = get_option('oyiso', []);
            return !empty($options[self::OPTION_ENABLED]);
        }

        public static function renderButton($attribute_taxonomy, int $i, $attribute): void
        {
            $label = function_exists('wc_attribute_label') ? wc_attribute_label($attribute->get_taxonomy()) : $attribute->get_taxonomy();
            echo ' <button type="button" class="button oyiso-qa-btn" data-idx="' . esc_attr($i) . '" data-taxonomy="' . esc_attr($attribute->get_taxonomy()) . '" data-label="' . esc_attr($label) . '">快速添加</button>';
        }

        public static function renderModal(): void
        {
            $screen = get_current_screen();
            if (!$screen || $screen->post_type !== 'product') {
                return;
            }

            ?>
            <div id="oyiso-qa-modal" class="oyiso-qa-modal-backdrop" style="display:none;">
                <div class="oyiso-qa-modal-content">
                    <div class="oyiso-qa-modal-header">
                        <h2>快速添加属性值</h2>
                        <button type="button" class="oyiso-qa-modal-close">&times;</button>
                    </div>
                    <div class="oyiso-qa-modal-body">
                        <div class="oyiso-qa-tabs">
                            <button type="button" class="oyiso-qa-tab oyiso-qa-tab-active" data-tab="new">新增</button>
                            <button type="button" class="oyiso-qa-tab" data-tab="existing">选择已有</button>
                        </div>
                        <div class="oyiso-qa-tab-panel" data-panel="new">
                            <p class="oyiso-qa-modal-desc">粘贴属性值，支持逗号（含中文逗号）、竖线（|）或换行分隔。</p>
                            <textarea id="oyiso-qa-modal-textarea" rows="6" placeholder="例：&#10;红色,蓝色&#10;绿色|黑色&#10;白色"></textarea>
                            <div id="oyiso-qa-modal-preview">输入后此处将显示哪些值新建、哪些复用</div>
                        </div>
                        <div class="oyiso-qa-tab-panel" data-panel="existing" style="display:none;">
                            <p class="oyiso-qa-modal-desc">勾选已有的属性值，一次性批量添加到当前产品。</p>
                            <input type="text" class="oyiso-qa-term-search" placeholder="搜索属性值..." style="width:100%;margin-bottom:10px;">
                            <div class="oyiso-qa-term-actions">
                                <button type="button" class="button oyiso-qa-select-all">全选</button>
                                <button type="button" class="button oyiso-qa-deselect-all">取消全选</button>
                                <span class="oyiso-qa-term-count"></span>
                            </div>
                            <div class="oyiso-qa-term-list">
                                <span class="spinner oyiso-qa-term-spinner" style="float:none;margin:12px auto;display:block;"></span>
                            </div>
                        </div>
                    </div>
                    <div class="oyiso-qa-modal-footer">
                        <span class="spinner oyiso-qa-modal-spinner" style="float:none;margin:0 6px 0 0;"></span>
                        <button type="button" class="button oyiso-qa-modal-cancel">取消</button>
                        <button type="button" class="button button-primary oyiso-qa-modal-do">确定添加</button>
                    </div>
                </div>
            </div>
            <?php
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
                'oyiso-wc-quick-attributes',
                plugins_url('assets/quick-attributes.js', __FILE__),
                ['jquery', 'select2'],
                '1.0.0',
                true
            );

            wp_localize_script('oyiso-wc-quick-attributes', 'oyisoQAConfig', [
                'nonce' => wp_create_nonce(self::AJAX_ACTION),
                'ajaxurl' => admin_url('admin-ajax.php'),
                'action' => self::AJAX_ACTION,
                'previewAction' => self::AJAX_PREVIEW_ACTION,
                'getTermsAction' => self::AJAX_GET_TERMS_ACTION,
            ]);

            wp_enqueue_style(
                'oyiso-wc-quick-attributes',
                plugins_url('assets/quick-attributes.css', __FILE__),
                [],
                '1.0.0'
            );
        }

        public static function ajaxAddTerms(): void
        {
            check_ajax_referer(self::AJAX_ACTION, 'nonce');

            if (!current_user_can('edit_products')) {
                wp_send_json_error(['message' => '权限不足']);
            }

            $taxonomy = isset($_POST['taxonomy']) ? sanitize_text_field(wp_unslash($_POST['taxonomy'])) : '';
            $raw = isset($_POST['values']) ? sanitize_textarea_field(wp_unslash($_POST['values'])) : '';

            if (!$taxonomy || !$raw) {
                wp_send_json_error(['message' => '参数不完整']);
            }

            if (!taxonomy_exists($taxonomy)) {
                wp_send_json_error(['message' => '属性分类法不存在']);
            }

            $names = self::parseValues($raw);
            if (empty($names)) {
                wp_send_json_error(['message' => '未解析到有效的属性值']);
            }

            $result = self::syncTerms($taxonomy, $names);
            wp_send_json_success($result);
        }

        public static function ajaxPreview(): void
        {
            check_ajax_referer(self::AJAX_ACTION, 'nonce');

            if (!current_user_can('edit_products')) {
                wp_send_json_error(['message' => '权限不足']);
            }

            $taxonomy = isset($_POST['taxonomy']) ? sanitize_text_field(wp_unslash($_POST['taxonomy'])) : '';
            $raw = isset($_POST['values']) ? sanitize_textarea_field(wp_unslash($_POST['values'])) : '';

            if (!$taxonomy || !$raw) {
                wp_send_json_error(['message' => '参数不完整']);
            }

            if (!taxonomy_exists($taxonomy)) {
                wp_send_json_error(['message' => '属性分类法不存在']);
            }

            $names = self::parseValues($raw);
            if (empty($names)) {
                wp_send_json_error(['message' => '未解析到有效的属性值']);
            }

            $existing = [];
            $new_vals = [];

            foreach ($names as $name) {
                $term = get_term_by('name', $name, $taxonomy);
                if ($term && !is_wp_error($term)) {
                    $existing[] = ['id' => $term->term_id, 'name' => $term->name];
                } else {
                    $new_vals[] = $name;
                }
            }

            wp_send_json_success([
                'existing' => $existing,
                'new_vals' => $new_vals,
                'total' => count($existing) + count($new_vals),
            ]);
        }

        public static function ajaxGetTerms(): void
        {
            check_ajax_referer(self::AJAX_ACTION, 'nonce');

            if (!current_user_can('edit_products')) {
                wp_send_json_error(['message' => '权限不足']);
            }

            $taxonomy = isset($_POST['taxonomy']) ? sanitize_text_field(wp_unslash($_POST['taxonomy'])) : '';

            if (!$taxonomy || !taxonomy_exists($taxonomy)) {
                wp_send_json_error(['message' => '属性分类法不存在']);
            }

            $terms = get_terms([
                'taxonomy' => $taxonomy,
                'hide_empty' => false,
                'orderby' => 'name',
                'order' => 'ASC',
            ]);

            if (is_wp_error($terms)) {
                wp_send_json_error(['message' => '获取属性值失败']);
            }

            $data = array_map(fn($t) => ['id' => $t->term_id, 'name' => $t->name], $terms);
            wp_send_json_success(['terms' => $data]);
        }

        private static function parseValues(string $raw): array
        {
            $values = preg_split('/[,，\|\n\r]+/', $raw);
            $values = array_map('trim', $values);
            $values = array_filter($values, fn($v) => $v !== '');
            return array_values(array_unique($values));
        }

        private static function syncTerms(string $taxonomy, array $names): array
        {
            $created = 0;
            $terms = [];

            foreach ($names as $name) {
                $term = get_term_by('name', $name, $taxonomy);

                if ($term && !is_wp_error($term)) {
                    $terms[] = ['id' => $term->term_id, 'name' => $term->name, 'new' => false];
                } else {
                    $result = wp_insert_term($name, $taxonomy);
                    if (!is_wp_error($result)) {
                        $terms[] = ['id' => $result['term_id'], 'name' => $name, 'new' => true];
                        $created++;
                    }
                }
            }

            return [
                'terms' => $terms,
                'created' => $created,
                'reused' => count($terms) - $created,
                'message' => sprintf('新建 %d 个，复用 %d 个，共 %d 个属性值', $created, count($terms) - $created, count($terms)),
            ];
        }
    }
}

Oyiso_WC_Quick_Attributes::init();
