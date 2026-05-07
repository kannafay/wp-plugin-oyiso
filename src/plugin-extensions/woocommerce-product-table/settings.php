<?php

defined('ABSPATH') || exit;

if (!class_exists('CSF')) {
    return;
}

CSF::createSection($prefix, [
    'parent' => 'plugin-extensions',
    'id' => 'woocommerce-product-table',
    'title' => 'WooCommerce',
    'icon' => 'fas fa-table',
    'priority' => 20,
    'fields' => [
        [
            'type' => 'heading',
            'content' => 'WooCommerce',
        ],
        [
            'type' => 'subheading',
            'content' => '产品信息表',
        ],
        [
            'id' => 'oyiso_wc_product_table_enabled',
            'type' => 'switcher',
            'title' => '启用产品信息表',
            'label' => '开启后会生成一个可直接访问的产品信息表页面，用于查看、复制或导出当前站点商品数据。',
            'default' => false,
        ],
        [
            'id' => 'oyiso_wc_product_table_slug',
            'type' => 'text',
            'title' => '页面 slug',
            'desc' => '默认是 pro_info。保存后会自动刷新路由。',
            'default' => 'pro_info',
            'sanitize' => 'oyiso_wc_product_table_sanitize_slug',
            'dependency' => ['oyiso_wc_product_table_enabled', '==', true],
        ],
        [
            'type' => 'callback',
            'title' => '访问预览',
            'function' => 'oyiso_wc_product_table_render_route_preview',
            'dependency' => ['oyiso_wc_product_table_enabled', '==', true],
        ],
        [
            'id' => 'oyiso_wc_product_table_extra_fields',
            'type' => 'checkbox',
            'title' => '额外字段',
            'desc' => '以下字段可自由显示或隐藏。产品名称 / 产品链接 / 产品规格会固定保留。',
            'options' => Oyiso_WC_Product_Table::getOptionalFieldOptions(),
            'default' => [
                'cover',
                'type',
                'regular_price',
                'sale_price',
                'stock_status',
            ],
            'sanitize' => 'oyiso_wc_product_table_sanitize_extra_fields',
            'dependency' => ['oyiso_wc_product_table_enabled', '==', true],
        ],
    ],
]);
