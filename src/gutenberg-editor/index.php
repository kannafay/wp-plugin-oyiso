<?php

defined('ABSPATH') || exit;

/**
 * 古腾堡编辑器
 */
CSF::createSection($prefix, [
    'parent'   => 'wp-optimize',
    'title'    => '古腾堡编辑器',
    'icon'     => 'fas fa-edit',
    'priority' => 10,
    'fields' => [
        [
            'type' => 'heading',
            'content' => '古腾堡编辑器设置',
        ],
        [
            'type' => 'notice',
            'style' => 'info',
            'content' => '开启后将禁用对应类型的古腾堡编辑器并移除前端相关样式，恢复为经典编辑器。',
        ],
        [
            'id' => 'opt-gutenberg-post',
            'type' => 'switcher',
            'title' => '文章 (post)',
            'default' => false,
        ],
        [
            'id' => 'opt-gutenberg-page',
            'type' => 'switcher',
            'title' => '页面 (page)',
            'default' => false,
        ],
    ]
]);

// 收集需要禁用古腾堡的文章类型
$disabled_types = [];
if (!empty($options['opt-gutenberg-post'])) {
    $disabled_types[] = 'post';
}
if (!empty($options['opt-gutenberg-page'])) {
    $disabled_types[] = 'page';
}

if (!empty($disabled_types)) {
    // 禁用编辑器
    add_filter('use_block_editor_for_post_type', function ($use, $post_type) use ($disabled_types) {
        if (in_array($post_type, $disabled_types, true)) {
            return false;
        }
        return $use;
    }, 10, 2);

    // 全部禁用时移除前端样式
    if (count($disabled_types) === 2) {
        add_action('wp_enqueue_scripts', function () {
            wp_dequeue_style('wp-block-library');
            wp_dequeue_style('wp-block-library-theme');
            wp_dequeue_style('wc-block-style');
        }, 100);
    }
}
