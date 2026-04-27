<?php

defined('ABSPATH') || exit;

if (!function_exists('oyiso_render_plugin_backup_panel')) {
    function oyiso_render_plugin_backup_panel(): void {
        echo '
            <div class="oyiso-plugin-backup-panel">
                <p>备份功能即将上线。</p>
            </div>
        ';
    }
}

if (class_exists('CSF')) {
    CSF::createSection($prefix, [
        'parent'   => 'oyiso-update-backup',
        'id'       => 'oyiso-backup',
        'tab_id'   => 'oyiso-backup',
        'title'    => '备份',
        'icon'     => 'fas fa-database',
        'priority' => 20,
        'fields'   => [
            [
                'type'    => 'heading',
                'content' => '备份',
            ],
            [
                'type'     => 'callback',
                'function' => 'oyiso_render_plugin_backup_panel',
            ],
        ],
    ]);
}
