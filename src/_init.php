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
    ]);

    // 循环引入文件
    $plugin_dir = plugin_dir_path(__FILE__);
    // 扫描当前目录
    $items = scandir($plugin_dir);
    foreach ($items as $item) {
        // 跳过 . 和 ..，以及下划线开头的文件或文件夹
        if ($item === '.' || $item === '..' || strpos($item, '_') === 0)
            continue;
        $path = $plugin_dir . $item;
        if (is_file($path) && pathinfo($path, PATHINFO_EXTENSION) === 'php') {
            // 当前目录下的 PHP 文件直接 require_once
            require_once $path;
        } elseif (is_dir($path)) {
            // 如果是文件夹，寻找 index.php
            $folder_php = $path . '/index.php';
            if (file_exists($folder_php)) {
                require_once $folder_php;
            }
        }
    }
}
