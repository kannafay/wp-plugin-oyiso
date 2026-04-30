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

/**
 * Elementor widgets
 */
if (class_exists('CSF')) {
    CSF::createSection($prefix, [
        'parent'   => 'plugin-extensions',
        'id'       => 'elementor-widgets',
        'title'    => oyiso_editor_t('Elementor Widgets'),
        'icon'     => 'fab fa-elementor',
        'priority' => 10,
        'fields'   => [
            [
                'type'    => 'heading',
                'content' => oyiso_editor_t('Elementor Widget Settings'),
            ],
            [
                'id'      => 'opt-elementor-widgets',
                'type'    => 'switcher',
                'title'   => oyiso_editor_t('Enable Widgets'),
                'label'   => oyiso_editor_t('Enable the Oyiso widget category and related components in the Elementor editor.'),
                'default' => true,
            ],
        ],
    ]);
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

add_action('elementor/frontend/after_register_styles', function () {
    $style_path = __DIR__ . '/assets/css/elementor-widgets.css';

    wp_register_style(
        'oyiso-elementor-widgets',
        plugins_url('assets/css/elementor-widgets.css', __FILE__),
        [],
        file_exists($style_path) ? filemtime($style_path) : '1.0.0'
    );
});

add_action('elementor/frontend/after_register_scripts', function () {
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
        'ajaxUrl'         => admin_url('admin-ajax.php'),
        'nonce'           => wp_create_nonce('oyiso_coupon_lottery_nonce'),
        'loading'         => oyiso_t('Processing, please wait...'),
        'drawLoading'     => oyiso_t('Drawing...'),
        'loadFailed'      => oyiso_t('Action failed. Please try again later.'),
        'availableNow'    => oyiso_t('You can join the draw now.'),
        'totalRemaining'  => oyiso_t('Total left: %d'),
        'dailyRemaining'  => oyiso_t('Today left: %d'),
        'prizePoolRemaining' => oyiso_t('Prize pool remaining: %d'),
        'copy'            => oyiso_t('Copy Coupon Code'),
        'copied'          => oyiso_t('Copied'),
        'myRecords'       => oyiso_t('My Records'),
        'allRecords'      => oyiso_t('All Records'),
        'emptyRecords'    => oyiso_t('No records yet.'),
        'loadMore'        => oyiso_t('Load More'),
        'loadingMore'     => oyiso_t('Loading...'),
        'claimButton'     => oyiso_t('Claim Coupon'),
        'editButton'      => oyiso_t('Edit'),
        'scopeButton'     => oyiso_t('Coupon Details'),
        'recordScopeButton' => oyiso_t('Details'),
        'recordCopy'      => oyiso_t('Copy'),
        'drawAgain'       => oyiso_t('Draw Again'),
        'userLabel'       => oyiso_t('User'),
        'timeLabel'       => oyiso_t('Time'),
        'couponLabel'     => oyiso_t('Coupon Code'),
        'statusLabel'     => oyiso_t('Status'),
        'resultLabel'     => oyiso_t('Result'),
        'claimedLabel'    => oyiso_t('Claimed'),
    ]);
});

add_action('elementor/editor/after_enqueue_scripts', function () {
    $editor_script_path = __DIR__ . '/assets/js/coupon-lottery-editor.js';

    wp_enqueue_script(
        'oyiso-coupon-lottery-editor',
        plugins_url('assets/js/coupon-lottery-editor.js', __FILE__),
        ['elementor-editor'],
        file_exists($editor_script_path) ? filemtime($editor_script_path) : '1.0.0',
        true
    );
});

add_filter('elementor/document/save/data', function ($data) {
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
}, 10, 2);

add_action('elementor/elements/categories_registered', function ($elements_manager) {
    $elements_manager->add_category('oyiso', [
        'title' => oyiso_editor_t('Oyiso'),
        'icon'  => 'fa fa-plug',
    ]);
}, 0);

add_action('elementor/elements/categories_registered', function ($elements_manager) {
    oyiso_promote_elementor_category($elements_manager);
}, PHP_INT_MAX);

add_action('elementor/widgets/register', function ($widgets_manager) {
    if (!did_action('elementor/loaded')) {
        return;
    }

    require_once __DIR__ . '/widgets/info-card.php';
    require_once __DIR__ . '/widgets/coupon-display.php';
    require_once __DIR__ . '/widgets/coupon-lottery.php';

    $widgets_manager->register(new \Oyiso\ElementorWidgets\Info_Card());
    $widgets_manager->register(new \Oyiso\ElementorWidgets\Coupons());
    $widgets_manager->register(new \Oyiso\ElementorWidgets\Coupon_Lottery());
});
