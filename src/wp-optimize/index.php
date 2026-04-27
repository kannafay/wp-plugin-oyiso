<?php

defined('ABSPATH') || exit;

if (class_exists('CSF')) {
    CSF::createSection($prefix, [
        'id'       => 'wp-optimize',
        'title'    => 'WordPress 优化',
        'icon'     => 'fab fa-wordpress',
        'priority' => 10,
    ]);
}

require_once __DIR__ . '/gutenberg-editor/index.php';
require_once __DIR__ . '/wp-update/index.php';
