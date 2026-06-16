<?php

defined('ABSPATH') || exit;

if (!class_exists('CSF')) {
    return;
}

if (!function_exists('oyiso_wc_quick_attributes_get_fields')) {
    function oyiso_wc_quick_attributes_get_fields(): array
    {
        return [
            [
                'id' => 'oyiso_wc_quick_attributes_enabled',
                'type' => 'switcher',
                'title' => '启用快速属性',
                'label' => '在产品编辑页属性面板中增加「快速添加」按钮，粘贴属性值列表即可批量创建并分配。',
                'default' => false,
            ],
        ];
    }
}
