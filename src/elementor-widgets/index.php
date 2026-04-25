<?php

defined('ABSPATH') || exit;

/**
 * Elementor 小部件
 */
if (class_exists('CSF')) {
    CSF::createSection($prefix, [
        'parent'   => 'plugin-extensions',
        'title'    => 'Elementor 小部件',
        'icon'     => 'fab fa-elementor',
        'priority' => 10,
        'fields'   => [
            [
                'type'    => 'heading',
                'content' => 'Elementor 小部件设置',
            ],
            [
                'id'      => 'opt-elementor-widgets',
                'type'    => 'switcher',
                'title'   => '启用小部件',
                'label'   => '开启后将在 Elementor 编辑器中启用橘子猫头小部件分类及相关组件。',
                'default' => true,
            ],
        ],
    ]);
}

$oyiso_elementor_widgets_enabled = $options['opt-elementor-widgets'] ?? true;

if (!$oyiso_elementor_widgets_enabled) {
    return;
}

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

    wp_register_script(
        'oyiso-coupon-tabs',
        plugins_url('assets/js/coupon-tabs.js', __FILE__),
        [],
        file_exists($script_path) ? filemtime($script_path) : '1.0.0',
        true
    );

    wp_localize_script('oyiso-coupon-tabs', 'oyisoCouponTabsI18n', [
        'expand'             => __('Show More', 'oyiso'),
        'collapse'           => __('Show Less', 'oyiso'),
        'copied'             => __('Copied', 'oyiso'),
        'scopeTitle'         => __('Offer Details', 'oyiso'),
        'scopeTitleWithCode' => __('%1$s - Offer Details', 'oyiso'),
        'closeLabel'         => __('Close', 'oyiso'),
    ]);
});

add_action('elementor/elements/categories_registered', function ($elements_manager) {
    $elements_manager->add_category('oyiso', [
        'title' => __('Oyiso', 'oyiso'),
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
    require_once __DIR__ . '/widgets/coupons.php';

    $widgets_manager->register(new \Oyiso\ElementorWidgets\Info_Card());
    $widgets_manager->register(new \Oyiso\ElementorWidgets\Coupons());
});
