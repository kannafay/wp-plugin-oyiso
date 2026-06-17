<?php

defined('ABSPATH') || exit;

if (!class_exists('CSF')) {
    return;
}

if (!function_exists('oyiso_wc_quick_attributes_get_fields')) {
    function oyiso_wc_quick_attributes_get_fields(): array
    {
        return [];
    }
}
