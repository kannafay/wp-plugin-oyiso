<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

if (!class_exists('CSF')) {
    return;
}

/** @return list<array<string, mixed>> */
function oyiso_wc_checkout_maintenance_get_fields(): array
{
    $defaults = Oyiso_WC_Checkout_Maintenance::getDefaults();

    return [
        [
            'id' => 'oyiso_wc_checkout_maintenance_enabled',
            'type' => 'switcher',
            'title' => '启用下单维护',
            'label' => '暂停接收新订单，客户提交订单时显示维护弹窗。支持经典及 Blocks 结账。',
            'default' => false,
        ],
        [
            'id' => 'oyiso_wc_checkout_maintenance_options',
            'type' => 'fieldset',
            'title' => '下单维护设置',
            'dependency' => ['oyiso_wc_checkout_maintenance_enabled', '==', true],
            'sanitize' => [Oyiso_WC_Checkout_Maintenance::class, 'sanitizeSettings'],
            'fields' => [
                [
                    'id' => 'template',
                    'type' => 'select',
                    'title' => '弹窗模板',
                    'options' => Oyiso_WC_Checkout_Maintenance::getTemplateOptions(),
                    'default' => $defaults['template'],
                    'desc' => '选择喜欢的风格，再点击下方“预览弹窗”查看效果。',
                ],
                [
                    'id' => 'accent',
                    'type' => 'callback',
                    'title' => '强调色',
                    'default' => $defaults['accent'],
                    'function' => [Oyiso_WC_Checkout_Maintenance::class, 'renderAccentField'],
                ],
                [
                    'id' => 'title',
                    'type' => 'text',
                    'title' => '弹窗标题',
                    'default' => $defaults['title'],
                ],
                [
                    'id' => 'message',
                    'type' => 'wp_editor',
                    'title' => '客户提示',
                    'default' => $defaults['message'],
                    'media_buttons' => false,
                    'wpautop' => true,
                    'height' => '200px',
                    'desc' => '支持可视化编辑，也可切换到“代码”输入 HTML。留空时使用默认提示。',
                ],
                [
                    'id' => 'button',
                    'type' => 'text',
                    'title' => '按钮文字',
                    'default' => $defaults['button'],
                ],
                [
                    'type' => 'callback',
                    'title' => '弹窗预览',
                    'function' => [Oyiso_WC_Checkout_Maintenance::class, 'renderPreview'],
                ],
            ],
        ],
    ];
}
