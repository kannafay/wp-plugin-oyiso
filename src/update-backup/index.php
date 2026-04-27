<?php

defined('ABSPATH') || exit;

if (class_exists('CSF')) {
    CSF::createSection($prefix, [
        'id'       => 'oyiso-update-backup',
        'title'    => '更新与备份',
        'icon'     => 'fas fa-hdd',
        'priority' => 50,
    ]);
}

require_once __DIR__ . '/update/index.php';
require_once __DIR__ . '/backup/index.php';
