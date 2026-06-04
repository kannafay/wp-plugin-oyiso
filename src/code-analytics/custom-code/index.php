<?php

defined('ABSPATH') || exit;

/**
 * 自定义代码 - 头部与底部
 */
if (class_exists('CSF')) {
    CSF::createSection($prefix, [
        'parent'   => 'code-analytics',
        'id'       => 'custom-code',
        'title'    => '自定义代码',
        'icon'     => 'fas fa-file-code',
        'priority' => 20,
        'fields' => [
            [
                'type' => 'heading',
                'content' => '自定义代码',
            ],
            [
                'id' => 'oyiso_custom_code_head',
                'type' => 'code_editor',
                'title' => '头部代码（Head）',
                'desc' => '输出到 &lt;head&gt; 标签内，适合放置 meta 标签、CSS、统计代码等。',
                'sanitize' => false,
                'settings' => [
                    'mode' => 'htmlmixed',
                    'theme' => 'default',
                ],
            ],
            [
                'id' => 'oyiso_custom_code_footer',
                'type' => 'code_editor',
                'title' => '底部代码（Footer）',
                'desc' => '输出到 &lt;/body&gt; 标签前，适合放置 JavaScript 脚本等。',
                'sanitize' => false,
                'settings' => [
                    'mode' => 'htmlmixed',
                    'theme' => 'default',
                ],
            ],
        ]
    ]);
}

// 自定义代码仅在前端输出
$custom_head = $options['oyiso_custom_code_head'] ?? '';
if (!is_admin() && !empty($custom_head)) {
    add_action('wp_head', function () use ($custom_head) {
        echo $custom_head;
    }, 99);
}

$custom_footer = $options['oyiso_custom_code_footer'] ?? '';
if (!is_admin() && !empty($custom_footer)) {
    add_action('wp_footer', function () use ($custom_footer) {
        echo $custom_footer;
    }, 99);
}
