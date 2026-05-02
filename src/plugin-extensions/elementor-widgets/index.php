<?php

defined('ABSPATH') || exit;

if (!function_exists('oyiso_get_site_locale')) {
    function oyiso_get_site_locale(): string
    {
        $site_locale = function_exists('get_locale') ? (string) get_locale() : '';

        if (!$site_locale && function_exists('get_bloginfo')) {
            $site_language = (string) get_bloginfo('language');

            if ($site_language !== '') {
                $site_locale = str_replace('-', '_', $site_language);
            }
        }

        return $site_locale ?: 'en_US';
    }
}

if (!function_exists('oyiso_translate_for_locale')) {
    function oyiso_translate_for_locale(string $text, string $locale): string
    {
        static $catalogs = [];
        $mofile = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'languages' . DIRECTORY_SEPARATOR . 'oyiso-' . $locale . '.mo';

        if (!is_readable($mofile)) {
            return $text;
        }

        if (!array_key_exists($locale, $catalogs)) {
            if (!class_exists('\MO')) {
                require_once ABSPATH . WPINC . '/pomo/mo.php';
            }

            $mo = new \MO();
            $catalogs[$locale] = $mo->import_from_file($mofile) ? $mo : false;
        }

        if (!$catalogs[$locale]) {
            return $text;
        }

        $translated = $catalogs[$locale]->translate($text);

        return is_string($translated) && $translated !== '' ? $translated : $text;
    }
}

if (!function_exists('oyiso_translate_for_site_locale')) {
    function oyiso_translate_for_site_locale(string $text): string
    {
        return oyiso_translate_for_locale($text, oyiso_get_site_locale());
    }
}

if (!function_exists('oyiso_t')) {
    function oyiso_t(string $text): string
    {
        return oyiso_translate_for_site_locale($text);
    }
}

if (!function_exists('oyiso_t_sprintf')) {
    function oyiso_t_sprintf(string $text, ...$args): string
    {
        return sprintf(oyiso_t($text), ...$args);
    }
}

if (!function_exists('oyiso_get_editor_locale')) {
    function oyiso_get_editor_locale(): string
    {
        if (function_exists('get_user_locale')) {
            $locale = (string) get_user_locale();

            if ($locale !== '') {
                return $locale;
            }
        }

        if (function_exists('determine_locale')) {
            $locale = (string) determine_locale();

            if ($locale !== '') {
                return $locale;
            }
        }

        return function_exists('get_locale') ? (string) get_locale() : 'en_US';
    }
}

if (!function_exists('oyiso_editor_t')) {
    function oyiso_editor_t(string $english): string
    {
        return oyiso_translate_for_locale($english, oyiso_get_editor_locale());
    }
}

if (!function_exists('oyiso_is_plugin_enabled_for_current_site')) {
    function oyiso_is_plugin_enabled_for_current_site(string $plugin_file): bool
    {
        if ($plugin_file === '') {
            return false;
        }

        if (function_exists('is_plugin_active') && is_plugin_active($plugin_file)) {
            return true;
        }

        $active_plugins = (array) get_option('active_plugins', []);

        if (in_array($plugin_file, $active_plugins, true)) {
            return true;
        }

        if (!is_multisite()) {
            return false;
        }

        $network_active_plugins = get_site_option('active_sitewide_plugins', []);

        return is_array($network_active_plugins) && array_key_exists($plugin_file, $network_active_plugins);
    }
}

if (!function_exists('oyiso_is_elementor_enabled')) {
    function oyiso_is_elementor_enabled(): bool
    {
        if (did_action('elementor/loaded') || class_exists('\Elementor\Plugin', false) || defined('ELEMENTOR_VERSION')) {
            return true;
        }

        return oyiso_is_plugin_enabled_for_current_site('elementor/elementor.php');
    }
}

if (!function_exists('oyiso_get_elementor_widget_root_classes')) {
    function oyiso_get_elementor_widget_root_classes(): array
    {
        return [
            'oyiso-info-card',
            'oyiso-coupons',
            'oyiso-coupon-lottery',
        ];
    }
}

if (!function_exists('oyiso_get_elementor_widget_type_prefix')) {
    function oyiso_get_elementor_widget_type_prefix(): string
    {
        return 'oyiso_';
    }
}

if (!function_exists('oyiso_get_elementor_widget_data_attribute_prefix')) {
    function oyiso_get_elementor_widget_data_attribute_prefix(): string
    {
        return 'data-oyiso-';
    }
}

if (!function_exists('oyiso_get_current_filtered_post_id')) {
    function oyiso_get_current_filtered_post_id(): int
    {
        $post = get_post();

        return $post instanceof \WP_Post ? (int) $post->ID : 0;
    }
}

if (!function_exists('oyiso_get_post_elementor_data')) {
    function oyiso_get_post_elementor_data(int $post_id): array
    {
        static $cache = [];

        if ($post_id <= 0) {
            return [];
        }

        if (array_key_exists($post_id, $cache)) {
            return $cache[$post_id];
        }

        $elementor_data = get_post_meta($post_id, '_elementor_data', true);

        if (!is_string($elementor_data) || $elementor_data === '') {
            return $cache[$post_id] = [];
        }

        $decoded = json_decode($elementor_data, true);

        return $cache[$post_id] = is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('oyiso_elementor_tree_has_widget_prefix')) {
    function oyiso_elementor_tree_has_widget_prefix(array $elements, string $widget_type_prefix): bool
    {
        if ($widget_type_prefix === '') {
            return false;
        }

        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }

            $widget_type = (string) ($element['widgetType'] ?? '');

            if (($element['elType'] ?? '') === 'widget' && strpos($widget_type, $widget_type_prefix) === 0) {
                return true;
            }

            if (!empty($element['elements']) && is_array($element['elements'])) {
                if (oyiso_elementor_tree_has_widget_prefix($element['elements'], $widget_type_prefix)) {
                    return true;
                }
            }
        }

        return false;
    }
}

if (!function_exists('oyiso_collect_elementor_widget_settings')) {
    function oyiso_collect_elementor_widget_settings(array $elements, string $widget_type): array
    {
        $matched_settings = [];

        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }

            if (
                ($element['elType'] ?? '') === 'widget'
                && ($element['widgetType'] ?? '') === $widget_type
                && is_array($element['settings'] ?? null)
            ) {
                $matched_settings[] = $element['settings'];
            }

            if (!empty($element['elements']) && is_array($element['elements'])) {
                $matched_settings = array_merge(
                    $matched_settings,
                    oyiso_collect_elementor_widget_settings($element['elements'], $widget_type)
                );
            }
        }

        return $matched_settings;
    }
}

if (!function_exists('oyiso_get_post_elementor_widget_settings')) {
    function oyiso_get_post_elementor_widget_settings(int $post_id, string $widget_type): array
    {
        static $cache = [];

        if ($post_id <= 0 || $widget_type === '') {
            return [];
        }

        $cache_key = $post_id . ':' . $widget_type;

        if (array_key_exists($cache_key, $cache)) {
            return $cache[$cache_key];
        }

        $decoded = oyiso_get_post_elementor_data($post_id);

        if (empty($decoded)) {
            return $cache[$cache_key] = [];
        }

        return $cache[$cache_key] = oyiso_collect_elementor_widget_settings($decoded, $widget_type);
    }
}

if (!function_exists('oyiso_post_has_prefixed_elementor_widget')) {
    function oyiso_post_has_prefixed_elementor_widget(int $post_id, ?string $widget_type_prefix = null): bool
    {
        $widget_type_prefix = $widget_type_prefix ?? oyiso_get_elementor_widget_type_prefix();
        $decoded = oyiso_get_post_elementor_data($post_id);

        if ($post_id <= 0 || $widget_type_prefix === '' || empty($decoded)) {
            return false;
        }

        return oyiso_elementor_tree_has_widget_prefix($decoded, $widget_type_prefix);
    }
}

if (!function_exists('oyiso_strip_legacy_info_card_markup')) {
    function oyiso_strip_legacy_info_card_markup(string $content): string
    {
        $post_id = oyiso_get_current_filtered_post_id();

        if ($post_id <= 0) {
            return $content;
        }

        $widget_settings_list = oyiso_get_post_elementor_widget_settings($post_id, 'oyiso_info_card');

        if (empty($widget_settings_list)) {
            return $content;
        }

        foreach ($widget_settings_list as $settings) {
            if (!is_array($settings)) {
                continue;
            }

            $title = trim(wp_strip_all_tags((string) ($settings['title'] ?? '')));
            $description = trim(wp_strip_all_tags((string) ($settings['description'] ?? '')));
            $button_text = trim(wp_strip_all_tags((string) ($settings['button_text'] ?? '')));

            if ($title === '') {
                continue;
            }

            $pattern = '/<h[1-6][^>]*>\s*' . preg_quote($title, '/') . '\s*<\/h[1-6]>\s*';

            if ($description !== '') {
                $pattern .= preg_quote($description, '/');
                $pattern .= '\s*';
            }

            if ($button_text !== '') {
                $pattern .= '(?:<a\b[^>]*>\s*' . preg_quote($button_text, '/') . '\s*<\/a>\s*)?';
            }

            $pattern .= '/u';

            $content = preg_replace($pattern, '', $content, 1) ?? $content;
        }

        return $content;
    }
}

if (!function_exists('oyiso_filter_inactive_elementor_widget_markup')) {
    function oyiso_filter_inactive_elementor_widget_markup(string $content): string
    {
        if (
            $content === ''
            || (
                strpos($content, 'oyiso-') === false
                && strpos($content, 'oyiso_') === false
                && strpos($content, 'data-oyiso-') === false
            )
            || is_admin()
            || wp_doing_ajax()
            || is_feed()
            || is_embed()
            || (defined('REST_REQUEST') && REST_REQUEST)
            || oyiso_is_elementor_enabled()
        ) {
            return $content;
        }

        if (!class_exists('\DOMDocument') || !class_exists('\DOMXPath')) {
            return $content;
        }

        $post_id = oyiso_get_current_filtered_post_id();

        if (!oyiso_post_has_prefixed_elementor_widget($post_id)) {
            return $content;
        }

        $widget_type_prefix = oyiso_get_elementor_widget_type_prefix();

        $widget_class_conditions = array_map(static function (string $widget_class): string {
            return sprintf(
                "contains(concat(' ', normalize-space(@class), ' '), ' %s ')",
                $widget_class
            );
        }, oyiso_get_elementor_widget_root_classes());

        $query_parts = [];

        if ($widget_type_prefix !== '') {
            $query_parts[] = sprintf(
                "(@data-widget_type and starts-with(@data-widget_type, '%s'))",
                $widget_type_prefix
            );
        }

        if (!empty($widget_class_conditions)) {
            $query_parts[] = '(' . implode(' or ', $widget_class_conditions) . ')';
        }

        if (oyiso_get_elementor_widget_data_attribute_prefix() !== '') {
            $query_parts[] = sprintf(
                "(@*[starts-with(name(), '%s')])",
                oyiso_get_elementor_widget_data_attribute_prefix()
            );
        }

        if (empty($query_parts)) {
            return $content;
        }

        $internal_errors = libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $load_flags = 0;

        if (defined('LIBXML_HTML_NOIMPLIED')) {
            $load_flags |= LIBXML_HTML_NOIMPLIED;
        }

        if (defined('LIBXML_HTML_NODEFDTD')) {
            $load_flags |= LIBXML_HTML_NODEFDTD;
        }

        $wrapper_id = 'oyiso-elementor-widget-filter-root';
        $wrapped_content = '<?xml encoding="utf-8" ?><div id="' . $wrapper_id . '">' . $content . '</div>';
        $loaded = $dom->loadHTML($wrapped_content, $load_flags);

        if (!$loaded) {
            libxml_clear_errors();
            libxml_use_internal_errors($internal_errors);

            return $content;
        }

        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query('//*[' . implode(' or ', $query_parts) . ']');

        if ($nodes instanceof \DOMNodeList) {
            $removable_nodes = [];

            foreach ($nodes as $node) {
                $removable_nodes[] = $node;
            }

            foreach ($removable_nodes as $node) {
                if ($node->parentNode !== null) {
                    $node->parentNode->removeChild($node);
                }
            }
        }

        $root = $dom->getElementById($wrapper_id);

        if (!$root instanceof \DOMElement) {
            libxml_clear_errors();
            libxml_use_internal_errors($internal_errors);

            return $content;
        }

        $filtered_content = '';

        foreach ($root->childNodes as $child) {
            $filtered_content .= $dom->saveHTML($child);
        }

        libxml_clear_errors();
        libxml_use_internal_errors($internal_errors);

        return oyiso_strip_legacy_info_card_markup($filtered_content);
    }
}

if (!function_exists('oyiso_register_inactive_elementor_widget_filter')) {
    function oyiso_register_inactive_elementor_widget_filter(): void
    {
        if (oyiso_is_elementor_enabled()) {
            return;
        }

        if (has_filter('the_content', 'oyiso_filter_inactive_elementor_widget_markup')) {
            return;
        }

        add_filter('the_content', 'oyiso_filter_inactive_elementor_widget_markup', 1);
    }
}

if (did_action('plugins_loaded')) {
    oyiso_register_inactive_elementor_widget_filter();
} else {
    add_action('plugins_loaded', 'oyiso_register_inactive_elementor_widget_filter', 20);
}

$oyiso_elementor_widgets_enabled = $options['opt-elementor-widgets'] ?? true;

if (!$oyiso_elementor_widgets_enabled) {
    return;
}

if (!function_exists('oyiso_coupon_lottery_normalize_slider_setting')) {
    function oyiso_coupon_lottery_normalize_slider_setting($value, int $min, int $max = 100): array
    {
        $normalized = is_array($value) ? $value : [];
        $raw_size = is_array($value) && array_key_exists('size', $value) ? $value['size'] : $value;
        $size = is_numeric($raw_size) ? (float) $raw_size : (float) $min;
        $size = max((float) $min, min((float) $max, $size));

        $normalized['unit'] = !empty($normalized['unit']) ? (string) $normalized['unit'] : '%';
        $normalized['size'] = (int) round($size);

        return $normalized;
    }
}

if (!function_exists('oyiso_coupon_lottery_normalize_number_setting')) {
    function oyiso_coupon_lottery_normalize_number_setting($value, float $min): float
    {
        $numeric = is_numeric($value) ? (float) $value : $min;
        $numeric = max($min, $numeric);

        return round($numeric, 1);
    }
}

if (!function_exists('oyiso_coupon_lottery_sanitize_rule')) {
    function oyiso_coupon_lottery_sanitize_rule(array $rule, string $range_type): array
    {
        $mode = ($rule['mode'] ?? 'range') === 'single' ? 'single' : 'range';
        $rule['mode'] = $mode;
        $rule['probability'] = oyiso_coupon_lottery_normalize_slider_setting($rule['probability'] ?? null, 1, 100);

        if ($range_type === 'amount') {
            if ($mode === 'range') {
                $rule['start_amount'] = oyiso_coupon_lottery_normalize_number_setting($rule['start_amount'] ?? null, 0.1);
                $rule['end_amount'] = oyiso_coupon_lottery_normalize_number_setting($rule['end_amount'] ?? null, 0.1);
            } else {
                $rule['value_amount'] = oyiso_coupon_lottery_normalize_number_setting($rule['value_amount'] ?? null, 0.1);
            }

            return $rule;
        }

        if ($mode === 'range') {
            $rule['start_percent'] = oyiso_coupon_lottery_normalize_slider_setting($rule['start_percent'] ?? null, 1, 100);
            $rule['end_percent'] = oyiso_coupon_lottery_normalize_slider_setting($rule['end_percent'] ?? null, 1, 100);
        } else {
            $rule['value_percent'] = oyiso_coupon_lottery_normalize_slider_setting($rule['value_percent'] ?? null, 1, 100);
        }

        return $rule;
    }
}

if (!function_exists('oyiso_coupon_lottery_sanitize_widget_settings')) {
    function oyiso_coupon_lottery_sanitize_widget_settings(array $settings): array
    {
        if (isset($settings['percent_rules']) && is_array($settings['percent_rules'])) {
            foreach ($settings['percent_rules'] as $index => $rule) {
                if (!is_array($rule)) {
                    continue;
                }

                $settings['percent_rules'][$index] = oyiso_coupon_lottery_sanitize_rule($rule, 'percent');
            }
        }

        if (isset($settings['amount_rules']) && is_array($settings['amount_rules'])) {
            foreach ($settings['amount_rules'] as $index => $rule) {
                if (!is_array($rule)) {
                    continue;
                }

                $settings['amount_rules'][$index] = oyiso_coupon_lottery_sanitize_rule($rule, 'amount');
            }
        }

        if (function_exists('wp_generate_uuid4')) {
            $settings['config_revision'] = wp_generate_uuid4();
        } else {
            $settings['config_revision'] = uniqid('oyiso_lottery_', true);
        }

        return $settings;
    }
}

if (!function_exists('oyiso_coupon_lottery_sanitize_element_data')) {
    function oyiso_coupon_lottery_sanitize_element_data(array $element): array
    {
        if (
            ($element['elType'] ?? '') === 'widget'
            && ($element['widgetType'] ?? '') === 'oyiso_coupon_lottery'
            && is_array($element['settings'] ?? null)
        ) {
            $element['settings'] = oyiso_coupon_lottery_sanitize_widget_settings($element['settings']);
        }

        if (!empty($element['elements']) && is_array($element['elements'])) {
            foreach ($element['elements'] as $index => $child) {
                if (!is_array($child)) {
                    continue;
                }

                $element['elements'][$index] = oyiso_coupon_lottery_sanitize_element_data($child);
            }
        }

        return $element;
    }
}

require_once __DIR__ . '/coupon-lottery-module.php';

if (!function_exists('oyiso_promote_elementor_category')) {
    function oyiso_promote_elementor_category($elements_manager) {
        if (!is_object($elements_manager)) {
            return;
        }

        try {
            $reflection = new ReflectionObject($elements_manager);

            while ($reflection && !$reflection->hasProperty('categories')) {
                $reflection = $reflection->getParentClass();
            }

            if (!$reflection) {
                return;
            }

            $property = $reflection->getProperty('categories');
            $property->setAccessible(true);

            $categories = $property->getValue($elements_manager);

            if (!is_array($categories) || !isset($categories['oyiso'])) {
                return;
            }

            $oyiso_category = $categories['oyiso'];
            unset($categories['oyiso']);

            $property->setValue($elements_manager, ['oyiso' => $oyiso_category] + $categories);
        } catch (ReflectionException $exception) {
            return;
        }
    }
}

if (!class_exists('Oyiso_Elementor_Widgets_Bootstrap')) {
    final class Oyiso_Elementor_Widgets_Bootstrap
    {
        private static bool $booted = false;
        private static bool $noticeHooked = false;

        public static function init(): void
        {
            if (self::$booted) {
                return;
            }

            if (!self::isCompatible()) {
                self::hookMissingElementorNotice();

                return;
            }

            self::$booted = true;

            add_action('elementor/frontend/after_register_styles', [self::class, 'registerStyles']);
            add_action('elementor/frontend/after_register_scripts', [self::class, 'registerScripts']);
            add_action('elementor/editor/after_enqueue_scripts', [self::class, 'enqueueEditorScripts']);
            add_filter('elementor/document/save/data', [self::class, 'sanitizeDocumentData'], 10, 2);
            add_action('elementor/elements/categories_registered', [self::class, 'registerCategory'], 0);
            add_action('elementor/elements/categories_registered', [self::class, 'promoteCategory'], PHP_INT_MAX);
            add_action('elementor/widgets/register', [self::class, 'registerWidgets']);
        }

        private static function isCompatible(): bool
        {
            return oyiso_is_elementor_enabled();
        }

        private static function hookMissingElementorNotice(): void
        {
            if (self::$noticeHooked || !is_admin()) {
                return;
            }

            self::$noticeHooked = true;

            add_action('admin_notices', [self::class, 'renderMissingElementorNotice']);
        }

        public static function renderMissingElementorNotice(): void
        {
            if (
                !current_user_can('activate_plugins')
                && !current_user_can('manage_options')
                && !current_user_can('manage_network_plugins')
            ) {
                return;
            }

            printf(
                '<div class="notice notice-warning"><p>%s</p></div>',
                esc_html__('Oyiso Elementor widgets are enabled, but Elementor is not installed or activated. The widget module has not been initialized, and previously inserted Oyiso Elementor widgets will stay hidden on the frontend until Elementor is available again.', 'oyiso')
            );
        }

        public static function registerStyles(): void
        {
            $style_path = __DIR__ . '/assets/css/elementor-widgets.css';

            wp_register_style(
                'oyiso-elementor-widgets',
                plugins_url('assets/css/elementor-widgets.css', __FILE__),
                [],
                file_exists($style_path) ? filemtime($style_path) : '1.0.0'
            );
        }

        public static function registerScripts(): void
        {
            $script_path = __DIR__ . '/assets/js/coupon-tabs.js';
            $lottery_script_path = __DIR__ . '/assets/js/coupon-lottery.js';

            wp_register_script(
                'oyiso-coupon-tabs',
                plugins_url('assets/js/coupon-tabs.js', __FILE__),
                [],
                file_exists($script_path) ? filemtime($script_path) : '1.0.0',
                true
            );

            wp_localize_script('oyiso-coupon-tabs', 'oyisoCouponTabsI18n', [
                'expand'             => oyiso_t('Show More'),
                'collapse'           => oyiso_t('Show Less'),
                'copied'             => oyiso_t('Copied'),
                'couponCodeLabel'    => oyiso_t('Coupon Code'),
                'scopeTitle'         => oyiso_t('Offer Details'),
                'scopeTitleWithCode' => oyiso_t('%1$s - Offer Details'),
                'closeLabel'         => oyiso_t('Close'),
            ]);

            wp_register_script(
                'oyiso-coupon-lottery',
                plugins_url('assets/js/coupon-lottery.js', __FILE__),
                [],
                file_exists($lottery_script_path) ? filemtime($lottery_script_path) : '1.0.0',
                true
            );

            wp_localize_script('oyiso-coupon-lottery', 'oyisoCouponLotteryI18n', [
                'ajaxUrl'            => admin_url('admin-ajax.php'),
                'nonce'              => wp_create_nonce('oyiso_coupon_lottery_nonce'),
                'loading'            => oyiso_t('Processing, please wait...'),
                'drawLoading'        => oyiso_t('Drawing...'),
                'loadFailed'         => oyiso_t('Action failed. Please try again later.'),
                'availableNow'       => oyiso_t('You can join the draw now.'),
                'totalRemaining'     => oyiso_t('Total left: %d'),
                'dailyRemaining'     => oyiso_t('Today left: %d'),
                'prizePoolRemaining' => oyiso_t('Prize pool remaining: %d'),
                'copy'               => oyiso_t('Copy Coupon Code'),
                'copied'             => oyiso_t('Copied'),
                'myRecords'          => oyiso_t('My Records'),
                'allRecords'         => oyiso_t('All Records'),
                'emptyRecords'       => oyiso_t('No records yet.'),
                'loadMore'           => oyiso_t('Load More'),
                'loadingMore'        => oyiso_t('Loading...'),
                'claimButton'        => oyiso_t('Claim Coupon'),
                'editButton'         => oyiso_t('Edit'),
                'scopeButton'        => oyiso_t('Coupon Details'),
                'recordScopeButton'  => oyiso_t('Details'),
                'recordCopy'         => oyiso_t('Copy'),
                'drawAgain'          => oyiso_t('Draw Again'),
                'userLabel'          => oyiso_t('User'),
                'timeLabel'          => oyiso_t('Time'),
                'couponLabel'        => oyiso_t('Coupon Code'),
                'statusLabel'        => oyiso_t('Status'),
                'resultLabel'        => oyiso_t('Result'),
                'claimedLabel'       => oyiso_t('Claimed'),
            ]);
        }

        public static function enqueueEditorScripts(): void
        {
            $editor_script_path = __DIR__ . '/assets/js/coupon-lottery-editor.js';

            wp_enqueue_script(
                'oyiso-coupon-lottery-editor',
                plugins_url('assets/js/coupon-lottery-editor.js', __FILE__),
                ['elementor-editor'],
                file_exists($editor_script_path) ? filemtime($editor_script_path) : '1.0.0',
                true
            );
        }

        public static function sanitizeDocumentData($data)
        {
            if (!is_array($data) || empty($data['elements']) || !is_array($data['elements'])) {
                return $data;
            }

            foreach ($data['elements'] as $index => $element) {
                if (!is_array($element)) {
                    continue;
                }

                $data['elements'][$index] = oyiso_coupon_lottery_sanitize_element_data($element);
            }

            return $data;
        }

        public static function registerCategory($elements_manager): void
        {
            $elements_manager->add_category('oyiso', [
                'title' => oyiso_editor_t('Oyiso'),
                'icon'  => 'fa fa-plug',
            ]);
        }

        public static function promoteCategory($elements_manager): void
        {
            oyiso_promote_elementor_category($elements_manager);
        }

        public static function registerWidgets($widgets_manager): void
        {
            if (!did_action('elementor/loaded')) {
                return;
            }

            require_once __DIR__ . '/widgets/info-card.php';
            require_once __DIR__ . '/widgets/coupon-display.php';
            require_once __DIR__ . '/widgets/coupon-lottery.php';

            $widgets_manager->register(new \Oyiso\ElementorWidgets\Info_Card());
            $widgets_manager->register(new \Oyiso\ElementorWidgets\Coupons());
            $widgets_manager->register(new \Oyiso\ElementorWidgets\Coupon_Lottery());
        }
    }
}

if (did_action('plugins_loaded')) {
    Oyiso_Elementor_Widgets_Bootstrap::init();
} else {
    add_action('plugins_loaded', [Oyiso_Elementor_Widgets_Bootstrap::class, 'init'], 20);
}
