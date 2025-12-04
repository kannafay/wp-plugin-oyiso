<?php

defined('ABSPATH') || exit;


if (class_exists('CSF')) {
    /**
     * WordPress设置
     */
    CSF::createSection($prefix, array(
        'title' => 'WordPress设置',
        'fields' => array(
            array(
                'id' => 'opt-ban-wp-core-auto-update',
                'type' => 'switcher',
                'title' => '禁用WordPress核心自动更新',
                'label' => '开启后将禁用WordPress核心自动更新功能',
                'default' => false
            ),
            array(
                'id' => 'opt-ban-wp-plugin-auto-update',
                'type' => 'switcher',
                'title' => '禁用WordPress插件自动更新',
                'label' => '开启后将禁用WordPress插件自动更新功能',
                'default' => false
            ),
            array(
                'id' => 'opt-ban-wp-theme-auto-update',
                'type' => 'switcher',
                'title' => '禁用WordPress主题自动更新',
                'label' => '开启后将禁用WordPress主题自动更新功能',
                'default' => false
            ),
        )
    ));

    $options = get_option('oyiso');
    if (!is_array($options)) {
        return;
    }

    // 禁用WordPress核心自动更新
    if ($options['opt-ban-wp-core-auto-update'] == true) {
        add_filter('auto_update_core', '__return_false');
        add_filter('pre_site_transient_update_core', 'dwp_clear_update_data');
    }

    // 禁用WordPress插件自动更新
    if ($options['opt-ban-wp-plugin-auto-update'] == true) {
        add_filter('auto_update_plugin', '__return_false');
        add_filter('pre_site_transient_update_plugins', 'dwp_clear_update_data');
    }

    // 禁用WordPress主题自动更新
    if ($options['opt-ban-wp-theme-auto-update'] == true) {
        add_filter('auto_update_theme', '__return_false');
        add_filter('pre_site_transient_update_themes', 'dwp_clear_update_data');
    }

    function dwp_clear_update_data() {
        global $wp_version;
        return (object) array(
            'last_checked' => time(),
            'version_checked' => $wp_version,
            'updates' => array()
        );
    }
}