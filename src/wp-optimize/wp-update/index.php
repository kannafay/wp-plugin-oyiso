<?php

defined('ABSPATH') || exit;

/**
 * WordPress设置
 */
if (class_exists('CSF')) {
CSF::createSection($prefix, [
    'parent'   => 'wp-optimize',
    'id'       => 'wp-update',
    'title'    => '后台自动更新屏蔽',
    'icon'     => 'fas fa-sync-alt',
    'priority' => 1,
    'fields' => [
        [
            'type' => 'heading',
            'content' => '后台自动更新屏蔽',
        ],
        [
            'id' => 'opt-ban-wp-core-auto-update',
            'type' => 'switcher',
            'title' => '屏蔽WordPress核心自动更新',
            'label' => '开启后将屏蔽WordPress核心自动更新及相关更新通知',
            'default' => false
        ],
        [
            'id' => 'opt-ban-wp-plugin-auto-update',
            'type' => 'switcher',
            'title' => '屏蔽WordPress插件自动更新',
            'label' => '开启后将屏蔽WordPress插件自动更新及相关更新通知',
            'default' => false
        ],
        [
            'id' => 'opt-ban-wp-theme-auto-update',
            'type' => 'switcher',
            'title' => '屏蔽WordPress主题自动更新',
            'label' => '开启后将屏蔽WordPress主题自动更新及相关更新通知',
            'default' => false
        ],
    ]
]);
}

// 屏蔽WordPress核心自动更新及相关更新通知
if (!empty($options['opt-ban-wp-core-auto-update'])) {
    add_filter('auto_update_core', '__return_false');
    add_filter('pre_site_transient_update_core', 'oyiso_clear_core_update_data');
}

// 屏蔽WordPress插件自动更新及相关更新通知
if (!empty($options['opt-ban-wp-plugin-auto-update'])) {
    add_filter('auto_update_plugin', '__return_false');
    add_filter('pre_site_transient_update_plugins', 'oyiso_clear_plugin_update_data');
}

// 屏蔽WordPress主题自动更新及相关更新通知
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
