<?php

defined('ABSPATH') || exit;

/**
 * Pingback 防护
 */
if (class_exists('CSF')) {
CSF::createSection($prefix, [
    'parent'   => 'wp-optimize',
    'id'       => 'pingback',
    'title'    => 'Pingback 防护',
    'icon'     => 'fas fa-ban',
    'priority' => 20,
    'fields' => [
        [
            'type' => 'heading',
            'content' => 'Pingback 防护',
        ],
        [
            'id' => 'opt-disable-pingback',
            'type' => 'switcher',
            'title' => '关闭 Pingback',
            'label' => '开启后关闭网站的 Pingback 和 Trackback，防止垃圾引用通知',
            'default' => false,
        ],
    ]
]);
}

if (!empty($options['opt-disable-pingback'])) {
    add_filter('pings_open', '__return_false');

    // 移除 WP 核心输出的 pingback 相关 head 标签
    remove_action('wp_head', 'rsd_link');
    remove_action('wp_head', 'wlwmanifest_link');

    // 移除 X-Pingback 响应头
    add_action('send_headers', function () {
        header_remove('X-Pingback');
    });

    // 兜底：用输出缓冲移除主题硬编码的 <link rel="pingback">
    add_action('template_redirect', function () {
        ob_start(function ($html) {
            return preg_replace('/\s*<link\s+rel=[\'"]pingback[\'"][^>]*>\s*/i', '', $html);
        });
    }, 1);

    // 移除 XML-RPC pingback 接口
    add_filter('xmlrpc_methods', function ($methods) {
        unset($methods['pingback.ping']);
        unset($methods['pingback.extensions.getPingbacks']);
        return $methods;
    });
}
