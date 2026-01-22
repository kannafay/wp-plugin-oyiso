<?php

defined('ABSPATH') || exit;

if (class_exists('CSF')) {
    /**
     * 古腾堡编辑器
     */
    CSF::createSection($prefix, [
        'title' => '古腾堡编辑器',
        'fields' => [
            [
                'type' => 'heading',
                'content' => '古腾堡编辑器设置',
            ],
            [
                'id' => 'opt-gutenberg-editor',
                'type' => 'switcher',
                'title' => '禁用古腾堡编辑器',
                'label' => '开启后将禁用古腾堡编辑器，使用经典编辑器',
                'default' => false
            ],
            [
                'id' => 'opt-gutenberg-styles',
                'type' => 'switcher',
                'title' => '禁用古腾堡相关样式',
                'label' => '开启后将禁用古腾堡相关样式的加载',
                'default' => false
            ],
        ]
    ]);

    $options = get_option('oyiso');
    if (!is_array($options)) {
        return;
    }

    // 禁用古腾堡编辑器
    if (isset($options['opt-gutenberg-editor']) && $options['opt-gutenberg-editor'] == true) {

        add_filter('use_block_editor_for_post', '__return_false', 10);
    }

    // 移除古腾堡相关样式
    if (isset($options['opt-gutenberg-styles']) && $options['opt-gutenberg-styles'] == true) {
        // remove_action('wp_enqueue_scripts', 'wp_common_block_scripts_and_styles');

        add_action('wp_enqueue_scripts', function () {
            wp_dequeue_style('wp-block-library');
            wp_dequeue_style('wp-block-library-theme');
            wp_dequeue_style('wc-block-style'); // WooCommerce 古腾堡样式
        }, 100);
    }
}
