<?php

defined('ABSPATH') || exit;

if (class_exists('CSF')) {
    CSF::createSection($prefix, [
        'id'       => 'plugin-extensions',
        'title'    => '插件扩展',
        'icon'     => 'fas fa-puzzle-piece',
        'priority' => 40,
    ]);
}

require_once __DIR__ . '/elementor-widgets/settings.php';
require_once __DIR__ . '/elementor-widgets/index.php';
