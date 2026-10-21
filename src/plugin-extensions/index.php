<?php

defined('ABSPATH') || exit;

if (class_exists('CSF')) {
    CSF::createSection($prefix, [
        'id'       => 'plugin-extensions',
        'title'    => '插件扩展',
        'icon'     => 'fas fa-puzzle-piece',
        'priority' => 20,
    ]);
}

require_once __DIR__ . '/elementor-widgets/settings.php';
require_once __DIR__ . '/elementor-widgets/index.php';
require_once __DIR__ . '/seopress/index.php';
require_once __DIR__ . '/seopress/settings.php';

// WooCommerce 插件扩展
add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        return;
    }
    require_once __DIR__ . '/woocommerce-checkout-form/checkout-protection.php';
    $securityOptions = get_option('oyiso', []);
    Oyiso_WC_Checkout_Protection::register(is_array($securityOptions) && !empty($securityOptions['opt-checkout-input-protection']));
    require_once __DIR__ . '/woocommerce-product-table/index.php';
    require_once __DIR__ . '/woocommerce-quick-attributes/settings.php';
    require_once __DIR__ . '/woocommerce-variation-split/settings.php';
    require_once __DIR__ . '/woocommerce-variation-split/index.php';
    require_once __DIR__ . '/woocommerce-variation-inline/settings.php';
    require_once __DIR__ . '/woocommerce-variation-inline/index.php';
    require_once __DIR__ . '/woocommerce-shipping-maintenance/index.php';
    require_once __DIR__ . '/woocommerce-shipping-maintenance/settings.php';
    require_once __DIR__ . '/woocommerce-checkout-maintenance/index.php';
    require_once __DIR__ . '/woocommerce-checkout-maintenance/settings.php';
    require_once __DIR__ . '/woocommerce-poland-checkout/index.php';
    require_once __DIR__ . '/woocommerce-checkout-form/settings.php';
    require_once __DIR__ . '/woocommerce-product-table/settings.php';
    require_once __DIR__ . '/woocommerce-quick-attributes/index.php';
});
