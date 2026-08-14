<?php

defined('ABSPATH') || exit;

if (class_exists('CSF')) {
    CSF::createSection($prefix, [
        'id'       => 'notifications',
        'title'    => '通知与集成',
        'icon'     => 'fas fa-bell',
        'priority' => 30,
    ]);
}

require_once __DIR__ . '/secret-fields.php';
require_once __DIR__ . '/telegram/index.php';
require_once __DIR__ . '/order-email-html/index.php';
