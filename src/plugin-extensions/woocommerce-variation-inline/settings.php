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
                'id' => 'oyiso_wc_variation_inline_enabled',
                'type' => 'switcher',
                'title' => '启用变体内联编辑',
                'label' => '在变体列表每行直接显示常规价、销售价、库存状态、启用开关，无需展开即可编辑，并与展开面板双向同步。',
                'default' => false,
            ],
        ];
    }
}
