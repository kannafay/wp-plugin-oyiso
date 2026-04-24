<?php

defined('ABSPATH') || exit;

/**
 * WordPress设置
 */
if (class_exists('CSF')) {
CSF::createSection($prefix, [
    'parent'   => 'wp-optimize',
    'title'    => '自动更新管理',
    'icon'     => 'fas fa-sync-alt',
    'priority' => 1,
    'fields' => [
        [
            'type' => 'heading',
            'content' => '自动更新管理',
        ],
        [
            'id' => 'opt-ban-wp-core-auto-update',
            'type' => 'switcher',
            'title' => '禁用WordPress核心自动更新',
            'label' => '开启后将禁用WordPress核心自动更新功能',
            'default' => false
        ],
        [
            'id' => 'opt-ban-wp-plugin-auto-update',
            'type' => 'switcher',
            'title' => '禁用WordPress插件自动更新',
            'label' => '开启后将禁用WordPress插件自动更新功能',
            'default' => false
        ],
        [
            'id' => 'opt-ban-wp-theme-auto-update',
            'type' => 'switcher',
            'title' => '禁用WordPress主题自动更新',
            'label' => '开启后将禁用WordPress主题自动更新功能',
            'default' => false
        ],
    ]
]);
}

// 禁用WordPress核心自动更新
if (!empty($options['opt-ban-wp-core-auto-update'])) {
    add_filter('auto_update_core', '__return_false');
    add_filter('pre_site_transient_update_core', 'oyiso_clear_core_update_data');
}

// 禁用WordPress插件自动更新
if (!empty($options['opt-ban-wp-plugin-auto-update'])) {
    add_filter('auto_update_plugin', '__return_false');
    add_filter('pre_site_transient_update_plugins', 'oyiso_clear_plugin_update_data');
}

// 禁用WordPress主题自动更新
if (!empty($options['opt-ban-wp-theme-auto-update'])) {
    add_filter('auto_update_theme', '__return_false');
    add_filter('pre_site_transient_update_themes', 'oyiso_clear_theme_update_data');
}

if (!function_exists('oyiso_clear_core_update_data')) {
    function oyiso_clear_core_update_data() {
        static $cache = null;
        if ($cache === null) {
            global $wp_version;
            $cache = (object) [
                'last_checked'    => time(),
                'version_checked' => $wp_version,
                'updates'         => [],
            ];
        }
        return $cache;
    }
}

if (!function_exists('oyiso_clear_plugin_update_data')) {
    function oyiso_clear_plugin_update_data() {
        static $cache = null;
        if ($cache === null) {
            $cache = (object) [
                'last_checked'    => time(),
                'checked'         => [],
                'response'        => [],
                'translations'    => [],
                'no_update'       => [],
            ];
        }
        return $cache;
    }
}

if (!function_exists('oyiso_clear_theme_update_data')) {
    function oyiso_clear_theme_update_data() {
        static $cache = null;
        if ($cache === null) {
            $cache = (object) [
                'last_checked'    => time(),
                'checked'         => [],
                'response'        => [],
                'translations'    => [],
                'no_update'       => [],
            ];
        }
        return $cache;
    }
}
