<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

if (!class_exists('CSF')) {
    return;
}

function oyiso_wc_checkout_form_migrate_settings(): void
{
    $options = get_option('oyiso', []);
    $legacy = 'oyiso_wc_poland_checkout_enabled';
    $root = 'oyiso_wc_checkout_form_options';

    if (!is_array($options) || !array_key_exists($legacy, $options)) {
        return;
    }

    $countries = is_array($options[$root] ?? null) ? $options[$root] : [];
    if (array_key_exists($legacy, $countries)) {
        return;
    }

    $countries[$legacy] = !empty($options[$legacy]);
    $options[$root] = $countries;
    update_option('oyiso', $options);
}

/** @return list<array<string, mixed>> */
function oyiso_wc_checkout_form_get_fields(): array
{
    return [[
        'id' => 'oyiso_wc_checkout_form_options',
        'type' => 'tabbed',
        'title' => '结账表单定制',
        'desc' => '按客户选择的国家应用已启用的规则，其他国家沿用 WooCommerce 默认表单。支持经典及 Blocks 结账。',
        'default' => ['oyiso_wc_poland_checkout_enabled' => false],
        'tabs' => [
            [
                'title' => '波兰',
                'fields' => oyiso_wc_poland_checkout_get_fields(),
            ],
        ],
    ]];
}

oyiso_wc_checkout_form_migrate_settings();
