<?php
/*
Plugin Name: 橘子猫头
Description: 橘子猫头的多功能实用插件
Version:     1.3.5
Author:      橘子猫头
Update URI:  https://github.com/kannafay/wp-plugin-oyiso
Text Domain: oyiso
Domain Path: /languages
*/

defined('ABSPATH') || exit;

add_action('init', function () {
    load_plugin_textdomain('oyiso', false, dirname(plugin_basename(__FILE__)) . '/languages');

    $locale = determine_locale();
    $mofile = plugin_dir_path(__FILE__) . 'languages/oyiso-' . $locale . '.mo';

    if (is_readable($mofile)) {
        unload_textdomain('oyiso');
        load_textdomain('oyiso', $mofile);
    }
});

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $settings_link = '<a href="plugins.php?page=oyiso">' . __('Settings', 'oyiso') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
});

// CSF 框架仅后台加载，前端无需解析 7+ 个类文件
if (is_admin()) {
    require_once plugin_dir_path(__FILE__) . 'classes/setup.class.php';
}

require_once plugin_dir_path(__FILE__) . 'src/_init.php';
