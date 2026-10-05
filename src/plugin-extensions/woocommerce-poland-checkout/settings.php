<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/** @return list<array<string, mixed>> */
function oyiso_wc_poland_checkout_get_fields(): array
{
    return [[
        'id' => 'oyiso_wc_poland_checkout_enabled',
        'type' => 'switcher',
        'title' => '启用波兰定制',
        'label' => '波兰地址拆分街道、门牌号和房间号，校验电话与邮编。',
        'default' => false,
    ]];
}
