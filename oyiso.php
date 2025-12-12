<?php
/*
Plugin Name: 橘子猫头
Description: 橘子猫头的多功能实用插件
Version:     1.0.2
Author:      橘子猫头
Text Domain: oyiso
Domain Path: /languages
*/

defined('ABSPATH') || exit;

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $settings_link = '<a href="plugins.php?page=oyiso">' . __('Settings') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
});

require_once plugin_dir_path(__FILE__) . 'classes/setup.class.php';
require_once plugin_dir_path(__FILE__) . 'src/_init.php';