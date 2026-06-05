<?php

defined('ABSPATH') || exit;

if (!class_exists('CSF')) {
    return;
}

$wc_section_fields = [
    [
        'type' => 'heading',
        'content' => 'WooCommerce',
    ],
    [
        'id' => 'oyiso_wc_product_table_enabled',
        'type' => 'switcher',
        'title' => '启用产品信息表',
        'label' => '开启后会生成一个可直接访问的产品信息表页面，用于查看、复制或导出当前站点商品数据。',
        'default' => false,
    ],
    [
        'id' => 'oyiso_wc_product_table_options',
        'type' => 'tabbed',
        'title' => '信息表配置',
        'dependency' => ['oyiso_wc_product_table_enabled', '==', true],
        'tabs' => [
            [
                'title' => '访问预览',
                'icon' => 'fas fa-link',
                'fields' => [
                    [
                        'id' => 'slug',
                        'type' => 'text',
                        'title' => '页面 slug',
                        'desc' => '默认是 product-table。保存后会自动刷新路由。',
                        'default' => 'product-table',
                        'sanitize' => 'oyiso_wc_product_table_sanitize_slug',
                    ],
                    [
                        'type' => 'callback',
                        'title' => '访问地址',
                        'function' => 'oyiso_wc_product_table_render_route_preview',
                    ],
                ],
            ],
            [
                'title' => '额外字段',
                'icon' => 'fas fa-columns',
                'fields' => [
                    [
                        'id' => 'extra_fields',
                        'type' => 'checkbox',
                        'title' => '显示字段',
                        'desc' => '以下字段可自由显示或隐藏。产品名称会固定保留。',
                        'options' => Oyiso_WC_Product_Table::getOptionalFieldOptions(),
                        'default' => [
                            'cover',
                            'link',
                            'brand',
                            'type',
                            'regular_price',
                            'sale_price',
                            'specs',
                            'stock_status',
                        ],
                        'sanitize' => 'oyiso_wc_product_table_sanitize_extra_fields',
                    ],
                ],
            ],
        ],
    ],
];

if (function_exists('oyiso_wc_variation_split_get_fields')) {
    $wc_section_fields = array_merge($wc_section_fields, oyiso_wc_variation_split_get_fields());
}

CSF::createSection('oyiso', [
    'parent' => 'plugin-extensions',
    'id' => 'woocommerce-product-table',
    'title' => 'WooCommerce',
    'icon' => 'fas fa-table',
    'priority' => 10,
    'fields' => $wc_section_fields,
]);
