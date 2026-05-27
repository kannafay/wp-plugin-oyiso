<?php

defined('ABSPATH') || exit;

if (class_exists('CSF')) {
    CSF::createSection($prefix, [
        'id'       => 'plugin-extensions',
        'title'    => '插件扩展',
        'icon'     => 'fas fa-puzzle-piece',
        'priority' => 40,
    ]);
}

require_once __DIR__ . '/elementor-widgets/settings.php';
require_once __DIR__ . '/elementor-widgets/index.php';

// WooCommerce 插件扩展
add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        return;
    }
    require_once __DIR__ . '/woocommerce-product-table/index.php';
    require_once __DIR__ . '/woocommerce-variation-split/settings.php';
    require_once __DIR__ . '/woocommerce-variation-split/index.php';
    require_once __DIR__ . '/woocommerce-product-table/settings.php';
});
