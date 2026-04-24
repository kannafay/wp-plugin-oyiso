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
                'label'   => '开启后在 Elementor 中注册橘子猫头小部件分类和示例卡片',
                'default' => true,
            ],
        ],
    ]);
}

$oyiso_elementor_widgets_enabled = $options['opt-elementor-widgets'] ?? true;

if (!$oyiso_elementor_widgets_enabled) {
    return;
}

add_action('elementor/frontend/after_register_styles', function () {
    wp_register_style(
        'oyiso-elementor-widgets',
        plugins_url('assets/css/elementor-widgets.css', __FILE__),
        [],
        '1.0.0'
    );
});

add_action('elementor/elements/categories_registered', function ($elements_manager) {
    $elements_manager->add_category('oyiso', [
        'title' => __('橘子猫头', 'oyiso'),
        'icon'  => 'fa fa-plug',
    ]);

    try {
        $reflection = new ReflectionClass($elements_manager);
        $property = $reflection->getProperty('categories');
        $property->setAccessible(true);

        $categories = $property->getValue($elements_manager);

        if (isset($categories['oyiso'])) {
            $oyiso_category = $categories['oyiso'];
            unset($categories['oyiso']);

            $property->setValue($elements_manager, ['oyiso' => $oyiso_category] + $categories);
        }
    } catch (ReflectionException $exception) {
        return;
    }
});

add_action('elementor/widgets/register', function ($widgets_manager) {
    if (!did_action('elementor/loaded')) {
        return;
    }

    require_once __DIR__ . '/widgets/info-card.php';

    $widgets_manager->register(new \Oyiso\ElementorWidgets\Info_Card());
});
