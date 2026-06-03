<?php

defined('ABSPATH') || exit;

if (class_exists('CSF')) {
    CSF::createSection($prefix, [
        'id'       => 'code-analytics',
        'title'    => '代码与统计',
        'icon'     => 'fas fa-chart-bar',
        'priority' => 45,
    ]);
}

require_once __DIR__ . '/51la-analytics/index.php';
require_once __DIR__ . '/custom-code/index.php';
