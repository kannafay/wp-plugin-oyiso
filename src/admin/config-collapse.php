<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

function oyiso_enqueue_config_collapse_assets(string $hook): void
{
    if (!oyiso_is_settings_page_hook($hook)) {
        return;
    }

    wp_enqueue_style(
        'oyiso-config-collapse',
        plugins_url('assets/config-collapse.css', __FILE__),
        ['csf', 'dashicons'],
        (string) filemtime(__DIR__ . '/assets/config-collapse.css')
    );
    wp_enqueue_script(
        'oyiso-config-collapse',
        plugins_url('assets/config-collapse.js', __FILE__),
        ['jquery', 'csf'],
        (string) filemtime(__DIR__ . '/assets/config-collapse.js'),
        true
    );
}

add_action('admin_enqueue_scripts', 'oyiso_enqueue_config_collapse_assets');
