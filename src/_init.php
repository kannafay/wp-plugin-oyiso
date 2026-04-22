<?php

// Control core classes for avoid errors
if (class_exists('CSF')) {

    // Set a unique slug-like ID
    $prefix = 'oyiso';

    /**
     *
     * @menu_parent argument examples.
     *
     * For Dashboard: 'index.php'
     * For Posts: 'edit.php'
     * For Media: 'upload.php'
     * For Pages: 'edit.php?post_type=page'
     * For Comments: 'edit-comments.php'
     * For Custom Post Types: 'edit.php?post_type=your_post_type'
     * For Appearance: 'themes.php'
     * For Plugins: 'plugins.php'
     * For Users: 'users.php'
     * For Tools: 'tools.php'
     * For Settings: 'options-general.php'
     *
     */
    CSF::createOptions($prefix, [
        'menu_title' => '橘子猫头',
        'menu_slug' => 'oyiso',
        'menu_type' => 'submenu',
        'menu_parent' => 'plugins.php',
        'theme' => 'light',
    ]);

    // 父级分类（仅导航，无字段）
    CSF::createSection($prefix, [
        'id'       => 'wp-optimize',
        'title'    => 'WordPress 优化',
        'icon'     => 'fab fa-wordpress',
        'priority' => 10,
    ]);

    CSF::createSection($prefix, [
        'id'       => 'seo-analytics',
        'title'    => 'SEO 与统计',
        'icon'     => 'fas fa-chart-bar',
        'priority' => 20,
    ]);

    CSF::createSection($prefix, [
        'id'       => 'notifications',
        'title'    => '通知与集成',
        'icon'     => 'fas fa-bell',
        'priority' => 30,
    ]);

    // 统一获取选项，子模块共享此变量
    $options = get_option('oyiso', []);

    // 加载模块
    $dir = plugin_dir_path(__FILE__);
    require_once $dir . 'gutenberg-editor/index.php';
    require_once $dir . 'wp-update/index.php';
    require_once $dir . '51la-analytics/index.php';
    require_once $dir . 'telegram/index.php';
}
