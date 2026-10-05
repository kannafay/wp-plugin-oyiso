<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

if (!class_exists('CSF')) {
    return;
}

if (!function_exists('oyiso_wc_shipping_maintenance_get_fields')) {
    /** @return list<array<string, mixed>> */
    function oyiso_wc_shipping_maintenance_get_fields(): array
    {
        return [
            [
                'id' => 'oyiso_wc_shipping_maintenance_enabled',
                'type' => 'switcher',
                'title' => '启用运输方式维护',
                'label' => '对 WC 已启用的运输方式设置临时维护状态。',
                'default' => false,
            ],
            [
                'id' => 'oyiso_wc_shipping_maintenance_rules',
                'type' => 'group',
                'title' => '运输方式维护',
                'button_title' => '新增维护规则',
                'accordion_title_prefix' => '维护规则',
                'accordion_title_number' => true,
                'accordion_title_auto' => false,
                'dependency' => ['oyiso_wc_shipping_maintenance_enabled', '==', true],
                'sanitize' => [Oyiso_WC_Shipping_Maintenance::class, 'sanitizeRules'],
                'validate' => [Oyiso_WC_Shipping_Maintenance::class, 'validateRules'],
                'fields' => [
                    [
                        'id' => 'method',
                        'type' => 'select',
                        'title' => '运输方式',
                        'placeholder' => '选择当前已启用的运输方式',
                        'options' => 'Oyiso_WC_Shipping_Maintenance::getMethodOptions',
                        'desc' => '按配送区域区分，同类运输方式可分别维护。',
                    ],
                    [
                        'id' => 'enabled',
                        'type' => 'switcher',
                        'title' => '进入维护',
                        'label' => '关闭后恢复该运输方式的正常使用。',
                        'default' => true,
                    ],
                    [
                        'id' => 'mode',
                        'type' => 'button_set',
                        'title' => '维护时展示',
                        'options' => ['hide' => '隐藏', 'disabled' => '置灰'],
                        'default' => 'hide',
                        'desc' => '隐藏：从配送选项中移除。置灰：保留名称与提示，客户无法选择。',
                    ],
                    [
                        'id' => 'message',
                        'type' => 'textarea',
                        'title' => '客户提示',
                        'default' => 'This shipping method is under maintenance. Please choose another shipping method.',
                        'desc' => '置灰时必填，显示在该运输方式下方。请使用面向客户的语言。',
                        'dependency' => ['mode', '==', 'disabled'],
                    ],
                ],
            ],
        ];
    }
}
