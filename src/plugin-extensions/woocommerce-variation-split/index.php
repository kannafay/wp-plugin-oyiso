<?php

defined('ABSPATH') || exit;

if (!class_exists('Oyiso_WC_Variation_Split')) {
    final class Oyiso_WC_Variation_Split
    {
        private const OPTION_ENABLED = 'oyiso_wc_variation_split_enabled';
        private const OPTION_CONFIG = 'oyiso_wc_variation_split_options';
        private const AJAX_ACTION = 'oyiso_wc_variation_split';
        private const NONCE_ACTION = 'oyiso_wc_variation_split_nonce';
        private const BULK_ACTION_KEY = 'oyiso_split_variations';

        private static ?array $settings_cache = null;

        public static function init(): void
        {
            if (!self::isEnabled()) {
                return;
            }

            add_filter('bulk_actions-edit-product', [__CLASS__, 'registerBulkAction']);
            add_action('admin_enqueue_scripts', [__CLASS__, 'enqueueAssets']);
            add_action('wp_ajax_' . self::AJAX_ACTION, [__CLASS__, 'ajaxSplitProduct']);
        }

        public static function isEnabled(): bool
        {
            $options = get_option('oyiso', []);
            return !empty($options[self::OPTION_ENABLED]);
        }

        public static function getConfig(): array
        {
            if (self::$settings_cache !== null) {
                return self::$settings_cache;
            }

            $options = get_option('oyiso', []);
            $config = $options[self::OPTION_CONFIG] ?? [];

            self::$settings_cache = [
                'naming_rule' => $config['naming_rule'] ?? '{parent} {attr}',
                'new_product_status' => $config['new_product_status'] ?? 'draft',
                'original_product_action' => $config['original_product_action'] ?? 'keep',
                'copy_fields' => $config['copy_fields'] ?? ['image', 'price', 'sku', 'gtin', 'stock', 'weight_dimensions', 'shipping_class', 'long_description', 'short_description', 'categories', 'tags', 'attributes', 'brand'],
            ];

            return self::$settings_cache;
        }

        public static function registerBulkAction(array $actions): array
        {
            $actions[self::BULK_ACTION_KEY] = '拆分变体为单品';
            return $actions;
        }


        public static function enqueueAssets(string $hook): void
        {
            if ($hook !== 'edit.php') {
                return;
            }

            $screen = get_current_screen();
            if (!$screen || $screen->post_type !== 'product') {
                return;
            }

            wp_enqueue_script(
                'oyiso-woocommerce-variation-split',
                plugins_url('assets/split.js', __FILE__),
                ['jquery'],
                '1.0.1',
                true
            );

            wp_localize_script('oyiso-woocommerce-variation-split', 'oyisoSplitConfig', [
                'nonce' => wp_create_nonce(self::AJAX_ACTION),
                'action' => self::AJAX_ACTION,
                'ajaxurl' => admin_url('admin-ajax.php'),
                'bulkActionKey' => self::BULK_ACTION_KEY,
            ]);
        }

        public static function ajaxSplitProduct(): void
        {
            check_ajax_referer(self::AJAX_ACTION, 'nonce');

            if (!current_user_can('edit_products')) {
                wp_send_json_error(['message' => '权限不足']);
            }

            $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;

            if (!$product_id) {
                wp_send_json_error(['message' => '产品 ID 无效']);
            }

            $product = wc_get_product($product_id);

            if (!$product || !$product->is_type('variable')) {
                wp_send_json_error(['message' => '产品 #' . $product_id . ' 不是可变产品']);
            }

            $config = self::getConfig();
            $variations = array_reverse($product->get_children());

            if (empty($variations)) {
                wp_send_json_error(['message' => '产品 #' . $product_id . ' 没有变体']);
            }

            $created = [];
            $errors = [];

            $skipped = 0;

            foreach ($variations as $variation_id) {
                try {
                    // 检查是否已拆分过该变体
                    $existing_posts = get_posts([
                        'post_type' => 'product',
                        'meta_key' => '_oyiso_split_from_variation',
                        'meta_value' => $variation_id,
                        'post_status' => ['publish', 'draft', 'pending', 'private'],
                        'fields' => 'ids',
                        'numberposts' => 1,
                    ]);

                    if (!empty($existing_posts)) {
                        $skipped++;
                        continue;
                    }

                    $variation = wc_get_product($variation_id);
                    if (!$variation) {
                        $errors[] = '变体 #' . $variation_id . ' 无法加载';
                        continue;
                    }

                    $result = self::createSimpleFromVariation($product, $variation, $config);
                    if (is_wp_error($result)) {
                        $errors[] = '变体 #' . $variation_id . ': ' . $result->get_error_message();
                    } else {
                        $created[] = $result;
                    }
                } catch (\Throwable $e) {
                    $errors[] = '变体 #' . $variation_id . ': ' . $e->getMessage();
                }
            }

            // 处理原产品
            self::handleOriginalProduct($product, $config['original_product_action']);

            wp_send_json_success([
                'message' => sprintf(
                    '产品「%s」拆分完成：成功 %d 个，跳过 %d 个，失败 %d 个',
                    $product->get_name(),
                    count($created),
                    $skipped,
                    count($errors)
                ),
                'created_ids' => $created,
                'errors' => $errors,
            ]);
        }

        private static function createSimpleFromVariation(
            WC_Product_Variable $parent,
            WC_Product_Variation $variation,
            array $config
        ): int|WP_Error {
            $copy_fields = $config['copy_fields'];
            $new_product = new WC_Product_Simple();

            // 命名
            $new_product->set_name(self::generateName($parent, $variation, $config['naming_rule']));

            // 状态（变体禁用时强制草稿，优先于设置）
            if ($variation->get_status() !== 'publish') {
                $new_product->set_status('draft');
            } else {
                $new_product->set_status($config['new_product_status']);
            }

            // 价格
            if (in_array('price', $copy_fields, true)) {
                $new_product->set_regular_price($variation->get_regular_price());
                $new_product->set_sale_price($variation->get_sale_price());
                if ($variation->get_date_on_sale_from()) {
                    $new_product->set_date_on_sale_from($variation->get_date_on_sale_from());
                }
                if ($variation->get_date_on_sale_to()) {
                    $new_product->set_date_on_sale_to($variation->get_date_on_sale_to());
                }
            }

            // 延迟写入变量
            $pending_sku = '';
            $pending_gtin = '';

            // SKU（延迟到保存后通过 post_meta 写入，绕过 WC 唯一性验证）
            if (in_array('sku', $copy_fields, true)) {
                $parent_sku = get_post_meta($parent->get_id(), '_sku', true);
                $child_sku = get_post_meta($variation->get_id(), '_sku', true);

                if (!$parent_sku && !$child_sku) {
                    $pending_sku = '';
                } elseif ($parent_sku && !$child_sku) {
                    $attr_slugs = [];
                    foreach ($variation->get_attributes() as $attr_value) {
                        if ($attr_value) {
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
            }

            // GTIN
            if (in_array('gtin', $copy_fields, true)) {
                if (method_exists($variation, 'get_global_unique_id')) {
                    $gtin = $variation->get_global_unique_id();
                } else {
                    $gtin = get_post_meta($variation->get_id(), '_gtin', true);
                }
                if ($gtin) {
                    if (method_exists($new_product, 'set_global_unique_id')) {
                        $new_product->set_global_unique_id($gtin);
                    } else {
                        // 延迟到保存后写 meta
                        $pending_gtin = $gtin;
                    }
                }
            }

            // 库存
            if (in_array('stock', $copy_fields, true)) {
                $new_product->set_manage_stock($variation->get_manage_stock());
                $new_product->set_stock_quantity($variation->get_stock_quantity());
                $new_product->set_stock_status($variation->get_stock_status());
                $new_product->set_backorders($variation->get_backorders());
            }

            // 重量与尺寸（变体优先，无则取父产品）
            if (in_array('weight_dimensions', $copy_fields, true)) {
                $new_product->set_weight($variation->get_weight() ?: $parent->get_weight());
                $new_product->set_length($variation->get_length() ?: $parent->get_length());
                $new_product->set_width($variation->get_width() ?: $parent->get_width());
                $new_product->set_height($variation->get_height() ?: $parent->get_height());
            }

            // 变体图片
            if (in_array('image', $copy_fields, true)) {
                $image_id = $variation->get_image_id();
                if ($image_id) {
                    $new_product->set_image_id($image_id);
                } else {
                    // 回退到父产品主图
                    $parent_image = $parent->get_image_id();
                    if ($parent_image) {
                        $new_product->set_image_id($parent_image);
                    }
                }
            }

            // 父产品图库
            if (in_array('gallery', $copy_fields, true)) {
                $gallery_ids = $parent->get_gallery_image_ids();
                if (!empty($gallery_ids)) {
                    $new_product->set_gallery_image_ids($gallery_ids);
                }
            }

            // 产品长描述
            if (in_array('long_description', $copy_fields, true)) {
                $new_product->set_description($parent->get_description());
            }

            // 产品短描述
            if (in_array('short_description', $copy_fields, true)) {
                $new_product->set_short_description($parent->get_short_description());
            }

            // 分类
            if (in_array('categories', $copy_fields, true)) {
                $new_product->set_category_ids($parent->get_category_ids());
            }

            // 标签
            if (in_array('tags', $copy_fields, true)) {
                $new_product->set_tag_ids($parent->get_tag_ids());
            }

            // 属性（复制父产品所有属性，用于变体的属性只保留当前值）
            if (in_array('attributes', $copy_fields, true)) {
                $variation_attrs = $variation->get_attributes();
                $parent_attrs = $parent->get_attributes();
                $new_attrs = [];

                foreach ($parent_attrs as $attr_name => $parent_attr) {
                    $new_attr = new WC_Product_Attribute();
                    $new_attr->set_id($parent_attr->get_id());
                    $new_attr->set_name($parent_attr->get_name());
                    $new_attr->set_visible($parent_attr->get_visible());
                    $new_attr->set_variation(false);

                    if ($parent_attr->get_variation() && isset($variation_attrs[$attr_name]) && $variation_attrs[$attr_name]) {
                        // 用于变体的属性 → 只保留当前变体的值
                        $attr_value = $variation_attrs[$attr_name];
                        if ($parent_attr->get_id() > 0) {
                            // 分类法属性：slug → term ID
                            $term = get_term_by('slug', $attr_value, $parent_attr->get_name());
                            $new_attr->set_options($term ? [$term->term_id] : [$attr_value]);
                        } else {
                            // 自定义属性：直接使用原始值
                            $new_attr->set_options([$attr_value]);
                        }
                    } else {
                        // 非变体属性 → 保留全部值
                        $new_attr->set_options($parent_attr->get_options());
                    }

                    $new_attrs[] = $new_attr;
                }

                if (!empty($new_attrs)) {
                    $new_product->set_attributes($new_attrs);
                }
            }

            // 运费类
            if (in_array('shipping_class', $copy_fields, true)) {
                $shipping_class_id = $variation->get_shipping_class_id();
                if (!$shipping_class_id) {
                    $shipping_class_id = $parent->get_shipping_class_id();
                }
                if ($shipping_class_id) {
                    $new_product->set_shipping_class_id($shipping_class_id);
                }
            }

            // ---- 以下为默认复制（不受选项控制） ----

            // 税务（变体优先）
            $tax_class = $variation->get_tax_class();
            $new_product->set_tax_class($tax_class !== '' ? $tax_class : $parent->get_tax_class());
            $new_product->set_tax_status($parent->get_tax_status());

            // 目录可见性
            $new_product->set_catalog_visibility($parent->get_catalog_visibility());

            // 允许评论
            $new_product->set_reviews_allowed($parent->get_reviews_allowed());

            // 购买备注
            $purchase_note = $parent->get_purchase_note();
            if ($purchase_note) {
                $new_product->set_purchase_note($purchase_note);
            }

            // 单独销售
            $new_product->set_sold_individually($parent->get_sold_individually());

            // 虚拟 / 可下载
            $new_product->set_virtual($variation->get_virtual());
            if ($variation->get_downloadable()) {
                $new_product->set_downloadable(true);
                $new_product->set_downloads($variation->get_downloads());
                $new_product->set_download_limit($variation->get_download_limit());
                $new_product->set_download_expiry($variation->get_download_expiry());
            }

            // 菜单排序
            $new_product->set_menu_order($parent->get_menu_order());

            // 保存（不含 SKU）
            $new_id = $new_product->save();

            if (!$new_id) {
                return new WP_Error('save_failed', '保存产品失败');
            }

            // 写入 SKU，冲突则追加随机后缀
            if ($pending_sku) {
                $sku_to_set = $pending_sku;
                if (wc_get_product_id_by_sku($sku_to_set)) {
                    $sku_to_set .= '-' . strtoupper(wp_generate_password(4, false));
                }
                update_post_meta($new_id, '_sku', $sku_to_set);
                if (function_exists('wc_update_product_lookup_tables_column')) {
                    wc_update_product_lookup_tables_column('sku', [$new_id]);
                }
            }

            // GTIN 回退写入（旧版 WC 无 set_global_unique_id 方法时）
            if (!empty($pending_gtin)) {
                update_post_meta($new_id, '_global_unique_id', $pending_gtin);
            }

            // 品牌（兼容常见品牌分类法）
            if (in_array('brand', $copy_fields, true)) {
                $brand_taxonomies = ['product_brand', 'pwb-brand', 'yith_product_brand'];
                foreach ($brand_taxonomies as $tax) {
                    if (taxonomy_exists($tax)) {
                        $terms = wp_get_object_terms($parent->get_id(), $tax, ['fields' => 'ids']);
                        if (!empty($terms) && !is_wp_error($terms)) {
                            wp_set_object_terms($new_id, $terms, $tax);
                        }
                        break;
                    }
                }
            }

            // 适配 Woodmart 主题：复制父产品的主题附加设置
            if (in_array('woodmart', $copy_fields, true)) {
                global $wpdb;
                $parent_id = $parent->get_id();
                $woodmart_metas = $wpdb->get_results($wpdb->prepare(
                    "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s",
                    $parent_id,
                    '_woodmart_%'
                ));
                if ($woodmart_metas) {
                    foreach ($woodmart_metas as $meta) {
                        update_post_meta($new_id, $meta->meta_key, maybe_unserialize($meta->meta_value));
                    }
                }
            }

            // 标记来源
            update_post_meta($new_id, '_oyiso_split_from_parent', $parent->get_id());
            update_post_meta($new_id, '_oyiso_split_from_variation', $variation->get_id());

            return $new_id;
        }

        private static function generateName(
            WC_Product_Variable $parent,
            WC_Product_Variation $variation,
            string $template
        ): string {
            $parent_name = $parent->get_name();
            $attrs = $variation->get_attributes();
            $attr_labels = [];

            foreach ($attrs as $attr_name => $attr_value) {
                if (!$attr_value) {
                    continue;
                }
                if (taxonomy_exists($attr_name)) {
                    $term = get_term_by('slug', $attr_value, $attr_name);
                    $attr_labels[] = $term ? $term->name : $attr_value;
                } else {
                    $attr_labels[] = $attr_value;
                }
            }

            $attr_string = implode(' / ', $attr_labels);
            $sku = $variation->get_sku() ?: '';
            $variation_id = (string) $variation->get_id();

            $name = str_replace(
                ['{parent}', '{attr}', '{sku}', '{id}'],
                [$parent_name, $attr_string, $sku, $variation_id],
                $template
            );

            // 清理多余的分隔符（当占位符为空时）
            $name = preg_replace('/\s*-\s*-\s*/', ' - ', $name);
            $name = preg_replace('/\s*-\s*$/', '', $name);
            $name = preg_replace('/^\s*-\s*/', '', $name);

            return trim($name) ?: $parent_name;
        }

        private static function handleOriginalProduct(WC_Product_Variable $product, string $action): void
        {
            switch ($action) {
                case 'draft':
                    $product->set_status('draft');
                    $product->save();
                    break;
                case 'trash':
                    wp_trash_post($product->get_id());
                    break;
                case 'keep':
                default:
                    break;
            }
        }
    }
}

Oyiso_WC_Variation_Split::init();
