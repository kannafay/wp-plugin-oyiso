<?php

defined('ABSPATH') || exit;

/**
 * 51LA统计代码
 */
if (class_exists('CSF')) {
CSF::createSection($prefix, [
    'parent'   => 'seo-analytics',
    'title'    => '51LA 统计代码',
    'icon'     => 'fas fa-code',
    'priority' => 10,
    'fields' => [
        [
            'type' => 'heading',
            'content' => '51LA统计代码设置',
        ],
        [
            'id' => 'opt-51la-code',
            'type' => 'code_editor',
            'title' => 'HTML代码',
            'sanitize' => false,
        ],
    ]
]);
}

// 统计代码仅需在前端输出
$code51la = $options['opt-51la-code'] ?? '';
if (!is_admin() && !empty($code51la)) {
    add_action('wp_head', function () use ($code51la) {
        echo $code51la;
    });
}
