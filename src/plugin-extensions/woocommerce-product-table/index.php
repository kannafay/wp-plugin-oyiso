<?php

defined('ABSPATH') || exit;

if (!function_exists('oyiso_wc_product_table_sanitize_slug')) {
    function oyiso_wc_product_table_sanitize_slug($value): string
    {
        return Oyiso_WC_Product_Table::sanitizeSlug($value);
    }
}

if (!function_exists('oyiso_wc_product_table_sanitize_extra_fields')) {
    function oyiso_wc_product_table_sanitize_extra_fields($value): array
    {
        return Oyiso_WC_Product_Table::sanitizeExtraFields($value);
    }
}

if (!function_exists('oyiso_wc_product_table_render_route_preview')) {
    function oyiso_wc_product_table_render_route_preview($args = null): void
    {
        $settings = Oyiso_WC_Product_Table::getSettings();
        $pretty_url = Oyiso_WC_Product_Table::getRouteUrl();
        $fallback_url = Oyiso_WC_Product_Table::getFallbackUrl();

        $view_icon = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-1px;margin-right:2px;"><path d="M7 17L17 7"/><path d="M7 7h10v10"/></svg>';

        echo '<p>';
        echo '<strong>常规访问链接</strong><br>';
        echo '<input type="text" value="' . esc_attr($pretty_url) . '" readonly style="width:400px;font-family:monospace;" onclick="this.select()"> ';
        echo '<a href="' . esc_url($pretty_url) . '" target="_blank" rel="noopener noreferrer" class="button">' . $view_icon . '查看</a>';
        echo '</p>';

        echo '<p>';
        echo '<strong>备用查询参数</strong><br>';
        echo '<input type="text" value="' . esc_attr($fallback_url) . '" readonly style="width:400px;font-family:monospace;" onclick="this.select()"> ';
        echo '<a href="' . esc_url($fallback_url) . '" target="_blank" rel="noopener noreferrer" class="button">' . $view_icon . '查看</a>';
        echo '<br><span class="description">当伪静态（Rewrite）失效导致当前路径 404 时，可使用此备用链接强行访问。</span>';
        echo '</p>';
    }
}

if (!class_exists('Oyiso_WC_Product_Table')) {
    final class Oyiso_WC_Product_Table
    {
        private const OPTION_ENABLED = 'oyiso_wc_product_table_enabled';
        private const OPTION_CONFIG = 'oyiso_wc_product_table_options';
        private const REWRITE_STATE_OPTION = 'oyiso_wc_product_table_rewrite_state';
        private const QUERY_VAR = 'oyiso_wc_product_table';
        private const DEFAULT_SLUG = 'product-table';
        private const DEFAULT_EXTRA_FIELDS = [
            'cover',
            'link',
            'brand',
            'type',
            'regular_price',
            'sale_price',
            'specs',
            'stock_status',
        ];
        private const LEGACY_DEFAULT_EXTRA_FIELDS = [
            'cover',
            'type',
            'regular_price',
            'sale_price',
            'stock_status',
        ];
        private const OPTIONAL_FIELD_DEFINITIONS = [
            'cover' => '产品封面',
            'link' => '产品链接',
            'brand' => '品牌',
            'type' => '产品类型',
            'regular_price' => '常规价',
            'sale_price' => '销售价',
            'specs' => '产品规格',
            'sku' => 'SKU',
            'categories' => '产品分类',
            'tags' => '产品标签',
            'stock_status' => '库存状态',
        ];
        private const BRAND_TAXONOMY_CANDIDATES = [
            'product_brand',
            'brand',
            'pwb-brand',
            'yith_product_brand',
            'berocket_brand',
        ];
        private const STOCK_STATUS_LABELS = [
            'instock' => '有货',
            'outofstock' => '缺货',
            'onbackorder' => '预售',
        ];
        private static ?array $rows = null;
        private static ?array $columns = null;

        public static function init(): void
        {
            add_action('init', [self::class, 'registerRewrite'], 20);
            add_action('init', [self::class, 'maybeSyncRewriteState'], 99);
            add_filter('query_vars', [self::class, 'registerQueryVar']);
            add_filter('redirect_canonical', [self::class, 'filterCanonicalRedirect'], 10, 2);
            add_action('template_redirect', [self::class, 'maybeRenderPage']);
            add_action('wp_enqueue_scripts', [self::class, 'enqueueAssets']);
            add_action('admin_enqueue_scripts', [self::class, 'enqueueAdminAssets']);
        }

        public static function enqueueAdminAssets(string $hook): void
        {
            if (!oyiso_is_settings_page_hook($hook)) {
                return;
            }
        }

        public static function registerRewrite(): void
        {
            add_rewrite_tag('%' . self::QUERY_VAR . '%', '([^&]+)');

            $settings = self::getSettings();

            if (!$settings['enabled']) {
                return;
            }

            add_rewrite_rule(
                '^' . preg_quote($settings['slug'], '/') . '/?$',
                'index.php?' . self::QUERY_VAR . '=1',
                'top'
            );
        }

        public static function maybeSyncRewriteState(): void
        {
            $settings = self::getSettings();
            $signature = self::getRewriteSignature($settings);
            $stored_signature = (string) get_option(self::REWRITE_STATE_OPTION, '');

            if ($signature === $stored_signature) {
                return;
            }

            flush_rewrite_rules(false);
            update_option(self::REWRITE_STATE_OPTION, $signature, false);
        }

        public static function registerQueryVar(array $vars): array
        {
            $vars[] = self::QUERY_VAR;

            return $vars;
        }

        public static function filterCanonicalRedirect($redirect_url, $requested_url)
        {
            if (!self::isCurrentRequest()) {
                return $redirect_url;
            }

            $canonical_url = self::getRouteUrl();
            $normalized_requested_url = self::normalizeUrlWithoutTrailingSlash($requested_url);

            if ($normalized_requested_url === $canonical_url) {
                return false;
            }

            return $canonical_url;
        }

        public static function enqueueAssets(): void
        {
            if (!self::isCurrentRequest()) {
                return;
            }

            $style_path = __DIR__ . '/assets/css/product-table.css';
            $script_path = __DIR__ . '/assets/js/product-table.js';

            wp_enqueue_style(
                'oyiso-wc-product-table',
                plugins_url('assets/css/product-table.css', __FILE__),
                [],
                file_exists($style_path) ? (string) filemtime($style_path) : '1.0.0'
            );

            wp_enqueue_script(
                'oyiso-wc-product-table',
                plugins_url('assets/js/product-table.js', __FILE__),
                [],
                file_exists($script_path) ? (string) filemtime($script_path) : '1.0.0',
                true
            );
        }

        public static function maybeRenderPage(): void
        {
            if (!self::isCurrentRequest()) {
                return;
            }

            $canonical_url = self::getRouteUrl();
            $requested_url = self::getCurrentRequestUrl();

            if ($requested_url !== '' && self::normalizeUrlWithoutTrailingSlash($requested_url) !== $requested_url) {
                wp_safe_redirect($canonical_url, 301);
                exit;
            }

            if (!self::getSettings()['enabled']) {
                global $wp_query;

                if ($wp_query instanceof WP_Query) {
                    $wp_query->set_404();
                }

                status_header(404);

                return;
            }

            global $wp_query;

            if ($wp_query instanceof WP_Query) {
                $wp_query->is_404 = false;
            }

            if (!headers_sent()) {
                header('X-Robots-Tag: noindex, nofollow', true);
            }

            status_header(200);
            nocache_headers();

            require __DIR__ . '/template.php';
            exit;
        }

        public static function isCurrentRequest(): bool
        {
            return (string) get_query_var(self::QUERY_VAR, '') === '1';
        }

        public static function isWooCommerceAvailable(): bool
        {
            return class_exists('WooCommerce') && function_exists('wc_get_product');
        }

        public static function sanitizeSlug($value): string
        {
            $slug = sanitize_title((string) $value);

            return $slug !== '' ? $slug : self::DEFAULT_SLUG;
        }

        public static function sanitizeExtraFields($value): array
        {
            if (!is_array($value)) {
                return [];
            }

            $sanitized = [];

            foreach (array_keys(self::OPTIONAL_FIELD_DEFINITIONS) as $field_key) {
                if (in_array($field_key, $value, true)) {
                    $sanitized[] = $field_key;
                }
            }

            return $sanitized;
        }

        public static function getOptionalFieldOptions(): array
        {
            return self::OPTIONAL_FIELD_DEFINITIONS;
        }

        public static function getSettings(): array
        {
            $options = get_option('oyiso', []);

            if (!is_array($options)) {
                $options = [];
            }

            $config = isset($options[self::OPTION_CONFIG]) && is_array($options[self::OPTION_CONFIG])
                ? $options[self::OPTION_CONFIG]
                : [];

            // 兼容旧版顶层 key
            if (empty($config) && isset($options['oyiso_wc_product_table_slug'])) {
                $config = [
                    'slug' => $options['oyiso_wc_product_table_slug'] ?? self::DEFAULT_SLUG,
                    'extra_fields' => $options['oyiso_wc_product_table_extra_fields'] ?? self::DEFAULT_EXTRA_FIELDS,
                ];
            }

            return [
                'enabled' => !empty($options[self::OPTION_ENABLED]),
                'slug' => self::sanitizeSlug($config['slug'] ?? self::DEFAULT_SLUG),
                'extra_fields' => self::resolveExtraFields($config),
            ];
        }

        public static function getRouteUrl(): string
        {
            return untrailingslashit(home_url('/' . ltrim(self::getSettings()['slug'], '/')));
        }

        public static function getFallbackUrl(): string
        {
            return add_query_arg(self::QUERY_VAR, '1', home_url('/'));
        }

        public static function getColumns(): array
        {
            if (self::$columns !== null) {
                return self::$columns;
            }

            $extra_fields = self::getSettings()['extra_fields'];
            $columns = [];

            if (in_array('cover', $extra_fields, true)) {
                $columns['cover'] = ['label' => '产品封面'];
            }

            $columns['name'] = ['label' => '产品名称'];

            if (in_array('link', $extra_fields, true)) {
                $columns['link'] = ['label' => '产品链接'];
            }

            foreach (['brand', 'type', 'regular_price', 'sale_price', 'specs', 'sku', 'categories', 'tags', 'stock_status'] as $field_key) {
                if (in_array($field_key, $extra_fields, true)) {
                    $columns[$field_key] = ['label' => self::OPTIONAL_FIELD_DEFINITIONS[$field_key]];
                }
            }

            self::$columns = $columns;

            return self::$columns;
        }

        public static function getRows(): array
        {
            if (self::$rows !== null) {
                return self::$rows;
            }

            if (!self::isWooCommerceAvailable()) {
                return self::$rows = [];
            }

            $query = new WP_Query([
                'post_type' => 'product',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'orderby' => 'ID',
                'order' => 'ASC',
                'no_found_rows' => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            ]);

            $rows = [];

            foreach ($query->posts as $product_id) {
                $product = wc_get_product($product_id);

                if (!$product || $product->get_type() === 'variation') {
                    continue;
                }

                $rows[] = self::buildRow($product);
            }

            usort($rows, static function (array $left, array $right): int {
                $compare = strcmp($left['sort_name'], $right['sort_name']);

                if ($compare !== 0) {
                    return $compare;
                }

                return $left['product_id'] <=> $right['product_id'];
            });

            return self::$rows = $rows;
        }

        public static function getSummary(): array
        {
            $settings = self::getSettings();

            return [
                'site_name' => get_bloginfo('name'),
                'site_url' => home_url('/'),
                'page_url' => self::getRouteUrl(),
                'slug' => $settings['slug'],
                'generated_at' => current_time('Y-m-d H:i:s'),
                'product_count' => count(self::getRows()),
                'column_count' => count(self::getColumns()),
                'has_woo' => self::isWooCommerceAvailable(),
            ];
        }

        public static function getRowSearchText(array $row): string
        {
            $fragments = [
                $row['name'],
                $row['link'],
                $row['brand'],
                $row['type'],
                $row['regular_price'],
                $row['sale_price'],
                $row['sku'],
                implode(' ', $row['specs_items']),
                $row['categories'],
                $row['tags'],
                $row['stock_status'],
            ];

            return strtolower(implode(' ', array_filter($fragments)));
        }

        public static function getCellDisplayHtml(string $column_key, array $row): string
        {
            switch ($column_key) {
                case 'cover':
                    if ($row['cover_url'] === '') {
                        return '<span class="oyiso-product-table__empty">-</span>';
                    }

                    return sprintf(
                        '<div class="oyiso-product-table__thumb"><img src="%1$s" alt="%2$s" loading="lazy" decoding="async"></div>',
                        esc_url($row['cover_url']),
                        esc_attr($row['name'])
                    );

                case 'name':
                    return sprintf(
                        '<div class="oyiso-product-table__name"><strong>%1$s</strong><span>#%2$d</span></div>',
                        esc_html($row['name']),
                        (int) $row['product_id']
                    );

                case 'link':
                    if ($row['link'] === '') {
                        return '<span class="oyiso-product-table__empty">-</span>';
                    }

                    return sprintf(
                        '<a class="oyiso-product-table__link" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
                        esc_url($row['link']),
                        esc_html($row['link'])
                    );

                case 'type':
                    return self::renderBadge($row['type'], 'neutral');

                case 'brand':
                    if ($row['brand'] === '') {
                        return '<span class="oyiso-product-table__empty">-</span>';
                    }

                    $items = array_map('trim', explode('；', $row['brand']));
                    $chips = array_map(static function (string $item): string {
                        return '<span class="oyiso-product-table__chip oyiso-product-table__chip--muted">' . esc_html($item) . '</span>';
                    }, array_filter($items));

                    return '<div class="oyiso-product-table__chips">' . implode('', $chips) . '</div>';

                case 'regular_price':
                case 'sale_price':
                    if ($row[$column_key] === '') {
                        return '<span class="oyiso-product-table__empty">-</span>';
                    }

                    return '<span class="oyiso-product-table__price">' . esc_html($row[$column_key]) . '</span>';

                case 'specs':
                    if (empty($row['specs_items'])) {
                        return '<span class="oyiso-product-table__empty">-</span>';
                    }

                    return self::renderSpecsBlock($row['specs_items']);

                case 'sku':
                    if ($row['sku'] === '') {
                        return '<span class="oyiso-product-table__empty">-</span>';
                    }

                    return '<code class="oyiso-product-table__code">' . esc_html($row['sku']) . '</code>';

                case 'categories':
                case 'tags':
                    if ($row[$column_key] === '') {
                        return '<span class="oyiso-product-table__empty">-</span>';
                    }

                    $items = array_map('trim', explode('；', $row[$column_key]));
                    $chips = array_map(static function (string $item): string {
                        return '<span class="oyiso-product-table__chip oyiso-product-table__chip--muted">' . esc_html($item) . '</span>';
                    }, array_filter($items));

                    return '<div class="oyiso-product-table__chips">' . implode('', $chips) . '</div>';

                case 'stock_status':
                    if ($row['stock_status'] === '') {
                        return '<span class="oyiso-product-table__empty">-</span>';
                    }

                    $tone = $row['stock_status_key'] === 'instock'
                        ? 'success'
                        : ($row['stock_status_key'] === 'outofstock' ? 'danger' : 'warning');

                    return self::renderBadge($row['stock_status'], $tone);
            }

            return '<span class="oyiso-product-table__empty">-</span>';
        }

        public static function getCellExportValue(string $column_key, array $row, string $format = 'csv'): string
        {
            switch ($column_key) {
                case 'cover':
                    if ($row['cover_url'] === '') {
                        return '';
                    }

                    return $format === 'markdown'
                        ? '![](' . $row['cover_url'] . ')'
                        : $row['cover_url'];

                case 'name':
                    return $row['name'];

                case 'link':
                    return $row['link'];

                case 'brand':
                case 'type':
                case 'regular_price':
                case 'sale_price':
                case 'sku':
                case 'categories':
                case 'tags':
                case 'stock_status':
                    return (string) $row[$column_key];

                case 'specs':
                    return implode('；', $row['specs_items']);
            }

            return '';
        }

        private static function getRewriteSignature(array $settings): string
        {
            return ($settings['enabled'] ? '1:' : '0:') . $settings['slug'];
        }

        private static function getCurrentRequestUrl(): string
        {
            $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';

            if ($request_uri === '') {
                return '';
            }

            return home_url($request_uri);
        }

        private static function normalizeUrlWithoutTrailingSlash(string $url): string
        {
            $parts = wp_parse_url($url);

            if (!is_array($parts) || empty($parts['host'])) {
                return untrailingslashit($url);
            }

            $scheme = $parts['scheme'] ?? (is_ssl() ? 'https' : 'http');
            $host = $parts['host'];
            $port = isset($parts['port']) ? ':' . $parts['port'] : '';
            $path = isset($parts['path']) ? untrailingslashit($parts['path']) : '';
            $query = isset($parts['query']) ? '?' . $parts['query'] : '';
            $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

            return $scheme . '://' . $host . $port . $path . $query . $fragment;
        }

        private static function buildRow($product): array
        {
            $product_id = (int) $product->get_id();
            $specs_items = self::getProductSpecsItems($product);
            $link = get_permalink($product_id);
            $cover_url = '';
            $image_id = (int) $product->get_image_id();

            if ($image_id > 0) {
                if (function_exists('wp_get_original_image_url')) {
                    $cover_url = (string) wp_get_original_image_url($image_id);
                }

                if ($cover_url === '') {
                    $cover_url = (string) wp_get_attachment_image_url($image_id, 'full');
                }
            }

            return [
                'product_id' => $product_id,
                'sort_name' => (string) $product->get_name(),
                'name' => (string) $product->get_name(),
                'link' => is_string($link) ? $link : '',
                'cover_url' => $cover_url,
                'brand' => self::getProductBrandText($product_id, $product),
                'type' => self::getProductTypeLabel($product),
                'regular_price' => self::getProductPriceText($product, 'regular'),
                'sale_price' => self::getProductPriceText($product, 'sale'),
                'specs_items' => $specs_items,
                'sku' => trim((string) $product->get_sku()),
                'categories' => self::getProductTermsText($product_id, 'product_cat'),
                'tags' => self::getProductTermsText($product_id, 'product_tag'),
                'stock_status' => self::getStockStatusLabel((string) $product->get_stock_status()),
                'stock_status_key' => (string) $product->get_stock_status(),
            ];
        }

        private static function getProductTypeLabel($product): string
        {
            $type = (string) $product->get_type();
            $labels = [
                'simple' => '简单产品',
                'variable' => '可变产品',
                'grouped' => '组合产品',
                'external' => '外部产品',
            ];

            if (isset($labels[$type])) {
                return $labels[$type];
            }

            if (function_exists('wc_get_product_types')) {
                $types = wc_get_product_types();

                if (!empty($types[$type])) {
                    return (string) $types[$type];
                }
            }

            return $type !== '' ? $type : '未知类型';
        }

        private static function resolveExtraFields(array $config): array
        {
            if (!array_key_exists('extra_fields', $config)) {
                return self::DEFAULT_EXTRA_FIELDS;
            }

            $extra_fields = self::sanitizeExtraFields($config['extra_fields']);

            if ($extra_fields === self::LEGACY_DEFAULT_EXTRA_FIELDS) {
                $extra_fields = self::DEFAULT_EXTRA_FIELDS;
            }

            return $extra_fields;
        }

        private static function getProductPriceText($product, string $price_type): string
        {
            if ($product->is_type('variable')) {
                $min = '';
                $max = '';

                if ($price_type === 'regular' && method_exists($product, 'get_variation_regular_price')) {
                    $min = (string) $product->get_variation_regular_price('min', false);
                    $max = (string) $product->get_variation_regular_price('max', false);
                }

                if ($price_type === 'sale' && method_exists($product, 'get_variation_sale_price')) {
                    $min = (string) $product->get_variation_sale_price('min', false);
                    $max = (string) $product->get_variation_sale_price('max', false);
                }

                if ($min === '' && $max === '') {
                    return '';
                }

                if ($min !== '' && $max !== '' && (float) $min !== (float) $max) {
                    return self::formatPriceAmount($min) . ' - ' . self::formatPriceAmount($max);
                }

                return self::formatPriceAmount($min !== '' ? $min : $max);
            }

            $value = $price_type === 'regular'
                ? (string) $product->get_regular_price()
                : (string) $product->get_sale_price();

            return $value !== '' ? self::formatPriceAmount($value) : '';
        }

        private static function formatPriceAmount($amount): string
        {
            if ($amount === '' || $amount === null) {
                return '';
            }

            if (function_exists('oyiso_wc_price')) {
                return (string) oyiso_wc_price($amount);
            }

            if (function_exists('wc_price')) {
                $price = wc_price($amount, ['html_format' => false]);
                $price = wp_strip_all_tags($price);

                return html_entity_decode($price, ENT_QUOTES, 'UTF-8');
            }

            return (string) $amount;
        }

        private static function getProductSpecsItems($product): array
        {
            $attributes = $product->get_attributes();

            if (empty($attributes) || !is_array($attributes)) {
                return [];
            }

            $specs = [];
            $product_id = (int) $product->get_id();

            foreach ($attributes as $attribute) {
                if (!is_object($attribute) || !method_exists($attribute, 'get_name')) {
                    continue;
                }

                $attribute_name = (string) $attribute->get_name();
                $attribute_label = function_exists('wc_attribute_label')
                    ? wc_attribute_label($attribute_name)
                    : $attribute_name;
                $values = self::getAttributeValues($product_id, $attribute);

                if (empty($values)) {
                    continue;
                }

                $specs[] = trim($attribute_label) . ': ' . implode('，', $values);
            }

            return $specs;
        }

        private static function getProductBrandText(int $product_id, $product): string
        {
            foreach (self::BRAND_TAXONOMY_CANDIDATES as $taxonomy) {
                $terms = self::getProductTermsText($product_id, $taxonomy);

                if ($terms !== '') {
                    return $terms;
                }
            }

            $attributes = method_exists($product, 'get_attributes')
                ? $product->get_attributes()
                : [];

            if (empty($attributes) || !is_array($attributes)) {
                return '';
            }

            foreach ($attributes as $attribute) {
                if (!is_object($attribute) || !method_exists($attribute, 'get_name')) {
                    continue;
                }

                $attribute_name = (string) $attribute->get_name();
                $attribute_label = function_exists('wc_attribute_label')
                    ? wc_attribute_label($attribute_name)
                    : $attribute_name;
                $normalized_name = strtolower($attribute_name);
                $normalized_label = strtolower((string) $attribute_label);

                if (
                    strpos($normalized_name, 'brand') === false
                    && strpos($normalized_label, 'brand') === false
                    && trim((string) $attribute_label) !== '品牌'
                ) {
                    continue;
                }

                $values = self::getAttributeValues($product_id, $attribute);

                if (!empty($values)) {
                    return implode('；', $values);
                }
            }

            return '';
        }

        private static function getAttributeValues(int $product_id, $attribute): array
        {
            if (method_exists($attribute, 'is_taxonomy') && $attribute->is_taxonomy()) {
                $terms = function_exists('wc_get_product_terms')
                    ? wc_get_product_terms($product_id, $attribute->get_name(), ['fields' => 'names'])
                    : [];

                return array_values(array_filter(array_map(static function ($term): string {
                    return trim(wp_strip_all_tags((string) $term));
                }, is_array($terms) ? $terms : [])));
            }

            $options = method_exists($attribute, 'get_options')
                ? (array) $attribute->get_options()
                : [];

            return array_values(array_filter(array_map(static function ($option): string {
                return trim(wp_strip_all_tags((string) $option));
            }, $options)));
        }

        private static function getProductTermsText(int $product_id, string $taxonomy): string
        {
            if (!taxonomy_exists($taxonomy)) {
                return '';
            }

            $terms = wp_get_post_terms($product_id, $taxonomy, ['fields' => 'names']);

            if (is_wp_error($terms) || empty($terms)) {
                return '';
            }

            $terms = array_map(static function ($term): string {
                return trim(wp_strip_all_tags((string) $term));
            }, $terms);

            return implode('；', array_filter($terms));
        }

        private static function getStockStatusLabel(string $stock_status): string
        {
            return self::STOCK_STATUS_LABELS[$stock_status] ?? ($stock_status !== '' ? $stock_status : '');
        }

        private static function renderBadge(string $label, string $tone): string
        {
            if ($label === '') {
                return '<span class="oyiso-product-table__empty">-</span>';
            }

            return sprintf(
                '<span class="oyiso-product-table__badge oyiso-product-table__badge--%1$s">%2$s</span>',
                esc_attr($tone),
                esc_html($label)
            );
        }

        private static function renderSpecChip(string $item): string
        {
            $item = trim($item);

            if ($item === '') {
                return '';
            }

            $parts = explode(':', $item, 2);

            if (count($parts) !== 2) {
                return '<span class="oyiso-product-table__chip oyiso-product-table__chip--spec">' . esc_html($item) . '</span>';
            }

            $label = trim($parts[0]);
            $value = trim($parts[1]);

            return sprintf(
                '<span class="oyiso-product-table__chip oyiso-product-table__chip--spec"><span class="oyiso-product-table__spec-label">%1$s</span><span class="oyiso-product-table__spec-value">%2$s</span></span>',
                esc_html($label),
                esc_html($value)
            );
        }

        private static function renderSpecsBlock(array $items): string
        {
            $chips = implode('', array_filter(array_map([self::class, 'renderSpecChip'], $items)));

            if ($chips === '') {
                return '<span class="oyiso-product-table__empty">-</span>';
            }

            if (!self::shouldCollapseSpecs($items)) {
                return '<div class="oyiso-product-table__specs-wrapper" style="display: flex; align-items: flex-start; gap: 8px;"><div class="oyiso-product-table__chips oyiso-product-table__chips--specs" style="flex: 1;">' . $chips . '</div><span class="oyiso-product-table__specs-copy" data-action="copy-specs" title="复制规格" style="margin-left: 0; margin-top: 2px;"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg></span></div>';
            }

            return sprintf(
                '<details class="oyiso-product-table__specs-details"><summary class="oyiso-product-table__specs-summary"><span class="oyiso-product-table__specs-summary-text">查看规格</span><span class="oyiso-product-table__specs-summary-count">%d 项</span><span class="oyiso-product-table__specs-copy" data-action="copy-specs" title="复制规格"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg></span></summary><div class="oyiso-product-table__chips oyiso-product-table__chips--specs">%s</div></details>',
                count($items),
                $chips
            );
        }

        private static function shouldCollapseSpecs(array $items): bool
        {
            if (count($items) >= 3) {
                return true;
            }

            $total_length = 0;

            foreach ($items as $item) {
                $total_length += function_exists('mb_strlen')
                    ? mb_strlen((string) $item)
                    : strlen((string) $item);
            }

            return $total_length > 90;
        }
    }
}

Oyiso_WC_Product_Table::init();
