<?php

defined('ABSPATH') || exit;

if (!class_exists('CSF')) {
    return;
}

if (!function_exists('oyiso_wc_variation_inline_get_fields')) {
    function oyiso_wc_variation_inline_get_fields(): array
    {
        return [
            [
                'id' => 'oyiso_wc_variation_quick_ops',
                'type' => 'tabbed',
                'title' => '快捷操作',
                'tabs' => [
                    [
                        'title' => '属性',
                        'icon' => 'fas fa-tags',
                        'fields' => [
                            [
                                'id' => 'oyiso_wc_quick_attributes_enabled',
                                'type' => 'switcher',
                                'title' => '启用快速属性',
                                'label' => '在产品编辑页属性面板中增加「快速添加」按钮，粘贴属性值列表即可批量创建并分配。',
                                'default' => false,
                            ],
                        ],
                    ],
                    [
                        'title' => '变量',
                        'icon' => 'fas fa-cubes',
                        'fields' => [
                            [
                                'id' => 'oyiso_wc_variation_inline_enabled',
                                'type' => 'switcher',
                                'title' => '启用变体快速编辑',
                                'label' => '在变体列表每行直接显示常规价、销售价、库存状态、启用开关，无需展开即可编辑，并与展开面板双向同步。',
                                'default' => false,
                            ],
                            [
                                'id' => 'oyiso_wc_variation_sku_batch_enabled',
                                'type' => 'switcher',
                                'title' => '启用批量SKU操作',
                                'label' => '在变量批量操作下拉框中增加生成全部SKU、补全缺失SKU、清除全部SKU三项功能，支持自定义前缀。',
                                'default' => false,
                            ],
                            [
                                'id' => 'oyiso_wc_variation_inline_unlimited_pagination',
                                'type' => 'switcher',
                                'title' => '解除变体分页',
                                'label' => 'WooCommerce 默认每页显示 15 个变体，开启后一次性加载全部变体。',
                                'default' => false,
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
