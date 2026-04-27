<?php

defined('ABSPATH') || exit;

if (class_exists('CSF')) {
    CSF::createSection($prefix, [
        'id'       => 'seo-analytics',
        'title'    => 'SEO 与统计',
        'icon'     => 'fas fa-chart-bar',
        'priority' => 20,
    ]);
}

require_once __DIR__ . '/51la-analytics/index.php';
