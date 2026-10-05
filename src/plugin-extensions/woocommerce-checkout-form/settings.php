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
    $selection = 'oyiso_wc_checkout_form_countries';

    if (!is_array($options) || array_key_exists($selection, $options)) {
        return;
    }

    $countries = is_array($options[$root] ?? null) ? $options[$root] : [];
    if (!array_key_exists($legacy, $countries) && !array_key_exists($legacy, $options)) {
        return;
    }

    $polandEnabled = array_key_exists($legacy, $countries)
        ? !empty($countries[$legacy]) : !empty($options[$legacy]);
    $options[$selection] = $polandEnabled ? ['PL'] : [];
    update_option('oyiso', $options);
}

/** @return array<string, string> */
function oyiso_wc_checkout_form_get_country_options(): array
{
    return ['PL' => '波兰'];
}

/** @return list<string> */
function oyiso_wc_checkout_form_sanitize_countries(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $supported = oyiso_wc_checkout_form_get_country_options();
    $countries = [];
    foreach ($value as $country) {
        if (is_string($country) && isset($supported[$country]) && !in_array($country, $countries, true)) {
            $countries[] = $country;
        }
    }
    return $countries;
}

/** @return list<array<string, mixed>> */
function oyiso_wc_checkout_form_get_fields(): array
{
    return [[
        'id' => 'oyiso_wc_checkout_form_countries',
        'type' => 'checkbox',
        'title' => '结账表单定制',
        'options' => oyiso_wc_checkout_form_get_country_options(),
        'inline' => true,
        'desc' => '勾选国家以启用定制，客户结账时选择对应国家即生效。未勾选的国家沿用 WooCommerce 默认表单，支持经典及 Blocks 结账。',
        'default' => [],
        'sanitize' => 'oyiso_wc_checkout_form_sanitize_countries',
    ]];
}

oyiso_wc_checkout_form_migrate_settings();
