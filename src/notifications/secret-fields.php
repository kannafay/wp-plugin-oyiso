<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

if (!function_exists('oyiso_enqueue_secret_field_assets')) {
    function oyiso_enqueue_secret_field_assets(string $hook): void {
        if (!oyiso_is_settings_page_hook($hook)) {
            return;
        }

        $stylePath = __DIR__ . '/assets/secret-field.css';
        $scriptPath = __DIR__ . '/assets/secret-field.js';

        wp_enqueue_style('dashicons');
        wp_enqueue_style(
            'oyiso-secret-field',
            plugins_url('assets/secret-field.css', __FILE__),
            ['dashicons'],
            is_file($stylePath) ? (string) filemtime($stylePath) : null
        );
        wp_enqueue_script(
            'oyiso-secret-field',
            plugins_url('assets/secret-field.js', __FILE__),
            [],
            is_file($scriptPath) ? (string) filemtime($scriptPath) : null,
            true
        );
    }
}

add_action('admin_enqueue_scripts', 'oyiso_enqueue_secret_field_assets');
