<?php

defined('ABSPATH') || exit;

if (!function_exists('oyiso_wc_variation_split_get_fields')) {
    function oyiso_wc_variation_split_get_fields(): array
    {
        return [
            [
                'type' => 'subheading',
                'content' => '变体拆分',
            ],
            [
                'id' => 'oyiso_wc_variation_split_enabled',
                'type' => 'switcher',
                'title' => '启用变体拆分',
                'label' => '开启后可在产品列表页批量选择可变产品，将其变体拆分为独立的简单产品。',
                'default' => false,
            ],
            [
                'id' => 'oyiso_wc_variation_split_options',
                'type' => 'tabbed',
                'title' => '拆分配置',
                'dependency' => ['oyiso_wc_variation_split_enabled', '==', true],
                'tabs' => [
                    [
                        'title' => '基本设置',
                        'icon' => 'fas fa-cog',
                        'fields' => [
                            [
                                'id' => 'naming_rule',
                                'type' => 'text',
                                'title' => '命名规则',
                                'desc' => '支持占位符：<code>{parent}</code> 父产品名、<code>{attr}</code> 属性值、<code>{sku}</code> 变体SKU、<code>{id}</code> 变体ID',
                                'default' => '{parent} {attr}',
                                'placeholder' => '{parent} {attr}',
                            ],
                            [
                                'id' => 'new_product_status',
                                'type' => 'select',
                                'title' => '新产品状态',
                                'desc' => '拆分后生成的简单产品的发布状态。',
                                'options' => [
                                    'publish' => '发布',
                                    'draft' => '草稿',
                                    'pending' => '待审核',
                                ],
                                'default' => 'draft',
                            ],
                            [
                                'id' => 'original_product_action',
                                'type' => 'select',
                                'title' => '原产品处理',
                                'desc' => '拆分完成后如何处理原可变产品。',
                                'options' => [
                                    'keep' => '保留不变',
                                    'draft' => '转为草稿',
                                    'trash' => '移入回收站',
                                ],
                                'default' => 'keep',
                            ],
                        ],
                    ],
                    [
                        'title' => '数据复制',
                        'icon' => 'fas fa-copy',
                        'fields' => [
                            [
                                'id' => 'copy_fields',
                                'type' => 'checkbox',
                                'title' => '复制数据',
                                'desc' => '选择拆分时要从变体复制到新简单产品的数据字段。',
                                'options' => [
                                    'image' => '封面图片',
                                    'gallery' => '父产品图库',
                                    'price' => '价格（常规价 + 促销价）',
                                    'sku' => 'SKU',
                                    'gtin' => 'GTIN',
                                    'stock' => '库存数据',
                                    'weight_dimensions' => '重量与尺寸',
                                    'shipping_class' => '运费类',
                                    'long_description' => '父产品长描述',
                                    'short_description' => '父产品短描述',
                                    'categories' => '产品分类',
                                    'brand' => '产品品牌',
                                    'tags' => '产品标签',
                                    'attributes' => '属性（变体属性取当前值，其他属性全部保留）',
                                ],
                                'default' => [
                                    'image',
                                    'price',
                                    'sku',
                                    'gtin',
                                    'stock',
                                    'weight_dimensions',
                                    'shipping_class',
                                    'long_description',
                                    'short_description',
                                    'categories',
                                    'tags',
                                    'attributes',
                                    'brand',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
