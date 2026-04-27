<?php

defined('ABSPATH') || exit;

if (!function_exists('oyiso_get_plugin_backup_option_name')) {
    function oyiso_get_plugin_backup_option_name(): string {
        return 'oyiso';
    }
}

if (!function_exists('oyiso_get_plugin_backup_storage')) {
    function oyiso_get_plugin_backup_storage(): array {
        $uploads = wp_upload_dir();

        if (!empty($uploads['error'])) {
            return [
                'error' => (string) $uploads['error'],
            ];
        }

        return [
            'dir' => trailingslashit($uploads['basedir']) . 'oyiso-backups',
            'url' => trailingslashit($uploads['baseurl']) . 'oyiso-backups',
        ];
    }
}

if (!function_exists('oyiso_ensure_plugin_backup_dir')) {
    function oyiso_ensure_plugin_backup_dir(): array {
        $storage = oyiso_get_plugin_backup_storage();

        if (!empty($storage['error'])) {
            return $storage;
        }

        if (!is_dir($storage['dir']) && !wp_mkdir_p($storage['dir'])) {
            return [
                'error' => '无法创建本地备份目录，请检查站点写入权限。',
            ];
        }

        $indexFile = trailingslashit($storage['dir']) . 'index.php';

        if (!is_file($indexFile)) {
            file_put_contents($indexFile, "<?php\n");
        }

        return $storage;
    }
}

if (!function_exists('oyiso_get_plugin_backup_payload')) {
    function oyiso_get_plugin_backup_payload(): array {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $pluginFile = dirname(dirname(dirname(__DIR__))) . '/oyiso.php';
        $pluginData = get_plugin_data($pluginFile, false, false);

        return [
            'plugin'      => 'oyiso',
            'option_name' => oyiso_get_plugin_backup_option_name(),
            'version'     => (string) ($pluginData['Version'] ?? ''),
            'exported_at' => current_time('mysql'),
            'data'        => get_option(oyiso_get_plugin_backup_option_name(), []),
        ];
    }
}

if (!function_exists('oyiso_get_plugin_backup_file_path')) {
    function oyiso_get_plugin_backup_file_path(string $file): string {
        $storage = oyiso_ensure_plugin_backup_dir();

        if (!empty($storage['error'])) {
            return '';
        }

        $file = sanitize_file_name(wp_basename($file));

        if ($file === '' || !preg_match('/\.json$/i', $file)) {
            return '';
        }

        $path = trailingslashit($storage['dir']) . $file;

        return is_file($path) ? $path : '';
    }
}

if (!function_exists('oyiso_get_local_plugin_backups')) {
    function oyiso_get_local_plugin_backups(): array {
        $storage = oyiso_ensure_plugin_backup_dir();

        if (!empty($storage['error'])) {
            return [];
        }

        $items = [];
        $files = glob(trailingslashit($storage['dir']) . '*.json') ?: [];

        foreach ($files as $path) {
            if (!is_file($path)) {
                continue;
            }

            $contents = file_get_contents($path);
            $payload = is_string($contents) ? json_decode($contents, true) : null;
            $modifiedAt = filemtime($path) ?: time();

            $items[] = [
                'file'        => basename($path),
                'size'        => filesize($path) ?: 0,
                'modified_at' => $modifiedAt,
                'exported_at' => is_array($payload) ? (string) ($payload['exported_at'] ?? '') : '',
                'version'     => is_array($payload) ? (string) ($payload['version'] ?? '') : '',
            ];
        }

        usort($items, static function (array $left, array $right): int {
            return $right['modified_at'] <=> $left['modified_at'];
        });

        return $items;
    }
}

if (!function_exists('oyiso_render_plugin_backup_list_html')) {
    function oyiso_render_plugin_backup_list_html(): string {
        $storage = oyiso_ensure_plugin_backup_dir();

        if (!empty($storage['error'])) {
            return '<p style="margin:0;color:#b91c1c;">' . esc_html($storage['error']) . '</p>';
        }

        $items = oyiso_get_local_plugin_backups();

        if (empty($items)) {
            return '<p style="margin:0;color:#6b7280;">当前还没有本地备份记录。</p>';
        }

        $html = '<div class="oyiso-backup-list">';

        foreach ($items as $item) {
            $downloadUrl = wp_nonce_url(
                admin_url('admin-ajax.php?action=oyiso_plugin_backup_download&file=' . rawurlencode($item['file'])),
                'oyiso_plugin_backup_download_' . $item['file'],
                'oyiso_backup_nonce'
            );

            $displayTime = $item['exported_at'] !== ''
                ? $item['exported_at']
                : wp_date('Y-m-d H:i:s', (int) $item['modified_at']);

            $version = $item['version'] !== '' ? $item['version'] : '-';
            $size = size_format((int) $item['size']);

            $html .= '<div class="oyiso-backup-item">';
            $html .= '<div class="oyiso-backup-item__main">';
            $html .= '<div class="oyiso-backup-item__meta">';
            $html .= '<span class="oyiso-backup-badge oyiso-backup-badge--time">' . esc_html($displayTime) . '</span>';
            $html .= '<span class="oyiso-backup-badge">v' . esc_html($version) . '</span>';
            $html .= '<span class="oyiso-backup-badge">' . esc_html($size) . '</span>';
            $html .= '</div>';
            $html .= '<div class="oyiso-backup-item__file"><code>' . esc_html($item['file']) . '</code></div>';
            $html .= '</div>';
            $html .= '<div class="oyiso-backup-item__actions">';
            $html .= '<button type="button" class="button button-primary oyiso-plugin-backup-restore-local" data-file="' . esc_attr($item['file']) . '">恢复</button>';
            $html .= '<a class="button button-secondary oyiso-plugin-backup-download" href="' . esc_url($downloadUrl) . '">下载</a>';
            $html .= '<button type="button" class="button oyiso-plugin-backup-delete-local oyiso-button-danger" data-file="' . esc_attr($item['file']) . '">删除</button>';
            $html .= '</div>';
            $html .= '</div>';
        }

        $html .= '</div>';

        return $html;
    }
}

if (!function_exists('oyiso_import_plugin_backup_payload')) {
    function oyiso_import_plugin_backup_payload(array $payload) {
        if (($payload['plugin'] ?? '') !== 'oyiso' || ($payload['option_name'] ?? '') !== oyiso_get_plugin_backup_option_name()) {
            return new WP_Error('oyiso_plugin_backup_invalid_plugin', '该备份文件不属于当前插件，无法恢复。');
        }

        if (!array_key_exists('data', $payload) || !is_array($payload['data'])) {
            return new WP_Error('oyiso_plugin_backup_invalid_data', '备份文件中未找到有效的配置数据。');
        }

        update_option(oyiso_get_plugin_backup_option_name(), $payload['data']);

        return true;
    }
}

if (!function_exists('oyiso_render_plugin_backup_panel')) {
    function oyiso_render_plugin_backup_panel(): void {
        $storage = oyiso_get_plugin_backup_storage();
        $backupLocation = !empty($storage['dir'])
            ? '<code>' . esc_html($storage['dir']) . '</code>'
            : 'WordPress 上传目录下的 <code>oyiso-backups</code> 文件夹';

        echo '
            <div class="oyiso-plugin-backup-panel">
                <style>
                    .oyiso-plugin-backup-panel {
                        --oyiso-backup-border: #e5e7eb;
                        --oyiso-backup-muted: #6b7280;
                        --oyiso-backup-surface: #ffffff;
                        --oyiso-backup-soft-surface: #f8fafc;
                        --oyiso-backup-accent-soft: #eff6ff;
                    }
                    .oyiso-plugin-backup-panel .oyiso-panel-block {
                        margin-top: 16px;
                        padding: 16px 18px;
                        background: linear-gradient(180deg, #ffffff 0%, #fbfcfe 100%);
                        border: 1px solid var(--oyiso-backup-border);
                        border-radius: 12px;
                        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
                    }
                    .oyiso-plugin-backup-panel .oyiso-panel-title {
                        margin: 0 0 8px;
                        font-size: 14px;
                    }
                    .oyiso-plugin-backup-panel .oyiso-panel-text {
                        margin: 0;
                        color: var(--oyiso-backup-muted);
                    }
                    .oyiso-plugin-backup-panel .oyiso-intro {
                        margin: 0;
                    }
                    .oyiso-plugin-backup-panel .oyiso-intro + .oyiso-intro {
                        margin-top: 12px;
                    }
                    .oyiso-plugin-backup-panel .oyiso-backup-list {
                        display: grid;
                        gap: 12px;
                    }
                    .oyiso-plugin-backup-panel .oyiso-backup-item {
                        display: flex;
                        align-items: center;
                        justify-content: space-between;
                        gap: 18px;
                        padding: 14px 16px;
                        background: var(--oyiso-backup-surface);
                        border: 1px solid #e6edf5;
                        border-radius: 12px;
                        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
                    }
                    .oyiso-plugin-backup-panel .oyiso-backup-item__main {
                        display: flex;
                        align-items: center;
                        gap: 14px;
                        min-width: 0;
                        flex: 1;
                    }
                    .oyiso-plugin-backup-panel .oyiso-backup-item__meta {
                        display: flex;
                        flex-wrap: nowrap;
                        gap: 8px;
                        margin-bottom: 0;
                        flex-shrink: 0;
                    }
                    .oyiso-plugin-backup-panel .oyiso-backup-badge {
                        display: inline-flex;
                        align-items: center;
                        min-height: 24px;
                        padding: 0 10px;
                        border-radius: 999px;
                        background: var(--oyiso-backup-soft-surface);
                        border: 1px solid #e2e8f0;
                        color: #475569;
                        font-size: 12px;
                        line-height: 1;
                    }
                    .oyiso-plugin-backup-panel .oyiso-backup-badge--time {
                        background: var(--oyiso-backup-accent-soft);
                        border-color: #bfdbfe;
                        color: #1d4ed8;
                    }
                    .oyiso-plugin-backup-panel .oyiso-backup-item__file {
                        min-width: 0;
                        color: #111827;
                        line-height: 1.4;
                        max-width: 100%;
                    }
                    .oyiso-plugin-backup-panel .oyiso-backup-item__file code {
                        display: inline-block;
                        max-width: 100%;
                        padding: 3px 8px;
                        border-radius: 8px;
                        background: #f3f4f6;
                        color: #374151;
                        font-size: 12px;
                        overflow: hidden;
                        text-overflow: ellipsis;
                        white-space: nowrap;
                    }
                    .oyiso-plugin-backup-panel .oyiso-backup-item__actions {
                        display: flex;
                        flex-wrap: wrap;
                        justify-content: flex-end;
                        gap: 8px;
                        margin-left: auto;
                        flex-shrink: 0;
                    }
                    .oyiso-plugin-backup-panel .oyiso-upload-inline {
                        display: grid;
                        grid-template-columns: auto minmax(0, 1fr);
                        align-items: center;
                        gap: 10px;
                    }
                    .oyiso-plugin-backup-panel .oyiso-upload-inline input[type="file"] {
                        display: none;
                    }
                    .oyiso-plugin-backup-panel .oyiso-upload-inline .button {
                        flex-shrink: 0;
                    }
                    .oyiso-plugin-backup-panel .oyiso-upload-file {
                        display: none;
                        align-items: center;
                        gap: 10px;
                        min-width: 0;
                        max-width: 100%;
                        justify-self: start;
                        color: #64748b;
                        font-size: 12px;
                        line-height: 1.4;
                    }
                    .oyiso-plugin-backup-panel .oyiso-upload-file.is-visible {
                        display: inline-flex;
                    }
                    .oyiso-plugin-backup-panel .oyiso-upload-file__name {
                        min-width: 0;
                        overflow: hidden;
                        text-overflow: ellipsis;
                        white-space: nowrap;
                    }
                    .oyiso-plugin-backup-panel .oyiso-upload-file__remove {
                        flex-shrink: 0;
                        padding: 0;
                        min-height: auto;
                        border: 0;
                        background: transparent;
                        color: #b32d2e;
                        cursor: pointer;
                        line-height: 1.4;
                        text-decoration: none;
                        box-shadow: none;
                    }
                    .oyiso-plugin-backup-panel .oyiso-upload-file__remove:hover,
                    .oyiso-plugin-backup-panel .oyiso-upload-file__remove:focus {
                        color: #8a2424;
                        background: transparent;
                        border: 0;
                        box-shadow: none;
                        outline: none;
                    }
                    .oyiso-plugin-backup-panel .oyiso-button-danger {
                        border-color: #b32d2e;
                        color: #b32d2e;
                    }
                    .oyiso-plugin-backup-panel .oyiso-button-danger:hover,
                    .oyiso-plugin-backup-panel .oyiso-button-danger:focus {
                        border-color: #8a2424;
                        color: #8a2424;
                        box-shadow: none;
                        outline: none;
                    }
                    .oyiso-plugin-backup-panel .oyiso-backup-item__actions .is-disabled {
                        pointer-events: none;
                        opacity: 0.55;
                    }
                    @media only screen and (max-width: 960px) {
                        .oyiso-plugin-backup-panel .oyiso-backup-item {
                            align-items: stretch;
                            flex-direction: column;
                        }
                        .oyiso-plugin-backup-panel .oyiso-backup-item__main {
                            display: block;
                        }
                        .oyiso-plugin-backup-panel .oyiso-backup-item__meta {
                            flex-wrap: wrap;
                            margin-bottom: 10px;
                        }
                        .oyiso-plugin-backup-panel .oyiso-backup-item__file,
                        .oyiso-plugin-backup-panel .oyiso-backup-item__file code {
                            white-space: normal;
                            overflow: visible;
                            text-overflow: clip;
                            word-break: break-all;
                        }
                        .oyiso-plugin-backup-panel .oyiso-backup-item__actions {
                            justify-content: flex-start;
                        }
                    }
                    @media only screen and (max-width: 640px) {
                        .oyiso-plugin-backup-panel .oyiso-upload-inline {
                            grid-template-columns: 1fr;
                            align-items: stretch;
                        }
                    }
                </style>
                <p class="oyiso-intro">您可以在此备份或恢复插件配置。</p>
                <p class="oyiso-intro">备份文件将以 JSON 格式保存在站点本地，可创建多次并按需下载或恢复。</p>

                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;margin-top:16px;">
                    <div class="oyiso-panel-block">
                        <h3 style="margin:0 0 8px;font-size:14px;">创建本地备份</h3>
                        <p style="margin:0 0 12px;color:#6b7280;">将当前插件配置保存为一个新的本地备份节点，便于后续下载或恢复。</p>
                        <button type="button" class="button button-secondary" id="oyiso-plugin-backup-create">创建备份</button>
                    </div>

                    <div class="oyiso-panel-block">
                        <h3 style="margin:0 0 8px;font-size:14px;">上传恢复</h3>
                        <p style="margin:0 0 12px;color:#6b7280;">如您已在本地保存过 JSON 备份文件，也可直接上传并恢复当前插件配置。</p>
                        <div class="oyiso-upload-inline">
                            <input type="file" id="oyiso-plugin-backup-file" accept=".json,application/json">
                            <button type="button" class="button button-primary" id="oyiso-plugin-backup-import">选择文件</button>
                            <div class="oyiso-upload-file" id="oyiso-plugin-backup-file-meta">
                                <div class="oyiso-upload-file__name" id="oyiso-plugin-backup-file-name"></div>
                                <button type="button" class="oyiso-upload-file__remove" id="oyiso-plugin-backup-file-remove">删除</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="oyiso-plugin-backup-status" style="margin-top:16px;"></div>

                <div class="oyiso-panel-block">
                    <h3 class="oyiso-panel-title">本地备份记录</h3>
                    <div id="oyiso-plugin-backup-list">' . oyiso_render_plugin_backup_list_html() . '</div>
                </div>

                <div class="oyiso-panel-block">
                    <h3 class="oyiso-panel-title">说明</h3>
                    <p class="oyiso-panel-text">恢复配置将覆盖当前插件设置，建议在恢复前先创建一个新的本地备份。</p>
                    <p style="margin:8px 0 0;color:#6b7280;">本地备份保存位置：' . $backupLocation . '</p>
                </div>
            </div>
        ';
    }
}

if (!function_exists('oyiso_handle_plugin_backup_create')) {
    function oyiso_handle_plugin_backup_create(): void {
        check_ajax_referer('oyiso_plugin_backup_create', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => '无权限执行该操作',
            ], 403);
        }

        $storage = oyiso_ensure_plugin_backup_dir();

        if (!empty($storage['error'])) {
            wp_send_json_error([
                'message' => $storage['error'],
            ], 500);
        }

        $payload = oyiso_get_plugin_backup_payload();
        $encodedPayload = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        if (!is_string($encodedPayload) || $encodedPayload === '') {
            wp_send_json_error([
                'message' => '备份数据生成失败，请稍后重试。',
            ], 500);
        }

        $filename = sprintf(
            'oyiso-config-backup-%s-%s.json',
            wp_date('Ymd-His'),
            strtolower(wp_generate_password(6, false, false))
        );
        $path = trailingslashit($storage['dir']) . $filename;

        $saved = file_put_contents($path, $encodedPayload);

        if ($saved === false) {
            wp_send_json_error([
                'message' => '本地备份创建失败，请检查目录写入权限。',
            ], 500);
        }

        wp_send_json_success([
            'message'  => '本地备份已创建。',
            'listHtml' => oyiso_render_plugin_backup_list_html(),
        ]);
    }
}

if (!function_exists('oyiso_handle_plugin_backup_download')) {
    function oyiso_handle_plugin_backup_download(): void {
        if (!current_user_can('manage_options')) {
            wp_die('无权限下载该备份文件。');
        }

        $file = isset($_GET['file']) ? sanitize_file_name(wp_unslash((string) $_GET['file'])) : '';

        check_admin_referer('oyiso_plugin_backup_download_' . $file, 'oyiso_backup_nonce');

        $path = oyiso_get_plugin_backup_file_path($file);

        if ($path === '') {
            wp_die('未找到对应的备份文件。');
        }

        nocache_headers();
        header('Content-Type: application/json; charset=' . get_option('blog_charset'));
        header('Content-Disposition: attachment; filename=' . basename($path));
        readfile($path);
        exit;
    }
}

if (!function_exists('oyiso_handle_plugin_backup_restore_local')) {
    function oyiso_handle_plugin_backup_restore_local(): void {
        check_ajax_referer('oyiso_plugin_backup_restore_local', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => '无权限执行该操作',
            ], 403);
        }

        $file = isset($_POST['file']) ? sanitize_file_name(wp_unslash((string) $_POST['file'])) : '';
        $path = oyiso_get_plugin_backup_file_path($file);

        if ($path === '') {
            wp_send_json_error([
                'message' => '未找到所选的本地备份文件。',
            ], 404);
        }

        $contents = file_get_contents($path);
        $payload = is_string($contents) ? json_decode($contents, true) : null;

        if (!is_array($payload)) {
            wp_send_json_error([
                'message' => '本地备份文件内容无效，无法恢复。',
            ], 400);
        }

        $result = oyiso_import_plugin_backup_payload($payload);

        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => $result->get_error_message(),
            ], 400);
        }

        wp_send_json_success([
            'message' => '已从本地备份成功恢复插件配置。',
        ]);
    }
}

if (!function_exists('oyiso_handle_plugin_backup_import')) {
    function oyiso_handle_plugin_backup_import(): void {
        check_ajax_referer('oyiso_plugin_backup_import', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => '无权限执行该操作',
            ], 403);
        }

        if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
            wp_send_json_error([
                'message' => '请先选择要上传的 JSON 备份文件。',
            ], 400);
        }

        $file = $_FILES['file'];

        if (!empty($file['error'])) {
            wp_send_json_error([
                'message' => '备份文件上传失败，请重试。',
            ], 400);
        }

        $contents = file_get_contents($file['tmp_name']);

        if ($contents === false || trim($contents) === '') {
            wp_send_json_error([
                'message' => '未读取到有效的备份文件内容。',
            ], 400);
        }

        $payload = json_decode($contents, true);

        if (!is_array($payload)) {
            wp_send_json_error([
                'message' => '备份文件格式无效，请上传正确的 JSON 文件。',
            ], 400);
        }

        $result = oyiso_import_plugin_backup_payload($payload);

        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => $result->get_error_message(),
            ], 400);
        }

        wp_send_json_success([
            'message' => '插件配置已成功恢复。',
        ]);
    }
}

if (!function_exists('oyiso_handle_plugin_backup_delete_local')) {
    function oyiso_handle_plugin_backup_delete_local(): void {
        check_ajax_referer('oyiso_plugin_backup_delete_local', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => '无权限执行该操作',
            ], 403);
        }

        $file = isset($_POST['file']) ? sanitize_file_name(wp_unslash((string) $_POST['file'])) : '';
        $path = oyiso_get_plugin_backup_file_path($file);

        if ($path === '') {
            wp_send_json_error([
                'message' => '未找到所选的本地备份文件。',
            ], 404);
        }

        if (!wp_delete_file($path) && is_file($path)) {
            wp_send_json_error([
                'message' => '备份文件删除失败，请检查目录写入权限。',
            ], 500);
        }

        wp_send_json_success([
            'message'  => '本地备份已删除。',
            'listHtml' => oyiso_render_plugin_backup_list_html(),
        ]);
    }
}

if (!function_exists('oyiso_enqueue_plugin_backup_assets')) {
    function oyiso_enqueue_plugin_backup_assets(string $hook): void {
        if ($hook !== 'plugins_page_oyiso') {
            return;
        }

        wp_register_script('oyiso-plugin-backup', '', ['jquery'], null, true);
        wp_enqueue_script('oyiso-plugin-backup');
        wp_localize_script('oyiso-plugin-backup', 'oyisoPluginBackup', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonces'  => [
                'create'       => wp_create_nonce('oyiso_plugin_backup_create'),
                'restoreLocal' => wp_create_nonce('oyiso_plugin_backup_restore_local'),
                'import'       => wp_create_nonce('oyiso_plugin_backup_import'),
                'deleteLocal'  => wp_create_nonce('oyiso_plugin_backup_delete_local'),
            ],
            'labels'  => [
                'selectFile'     => '请先选择要上传的 JSON 备份文件。',
                'chooseFile'     => '选择文件',
                'importFile'     => '上传并恢复',
                'creating'       => '正在创建本地备份，请稍候...',
                'restoring'      => '正在恢复配置，请稍候...',
                'deleting'       => '正在删除本地备份，请稍候...',
                'error'          => '操作失败，请稍后重试。',
                'confirmRestore' => '恢复配置将覆盖当前插件设置，确定继续吗？',
                'confirmDelete'  => '删除后将无法恢复该备份文件，确定继续吗？',
            ],
        ]);

        wp_add_inline_script('oyiso-plugin-backup', <<<'JS'
jQuery(function ($) {
    var $create = $('#oyiso-plugin-backup-create');
    var $file = $('#oyiso-plugin-backup-file');
    var $import = $('#oyiso-plugin-backup-import');
    var $fileMeta = $('#oyiso-plugin-backup-file-meta');
    var $fileName = $('#oyiso-plugin-backup-file-name');
    var $fileRemove = $('#oyiso-plugin-backup-file-remove');
    var $status = $('#oyiso-plugin-backup-status');
    var $list = $('#oyiso-plugin-backup-list');
    var $panel = $('.oyiso-plugin-backup-panel');

    if (
        !$create.length ||
        !$file.length ||
        !$import.length ||
        !$fileMeta.length ||
        !$fileName.length ||
        !$fileRemove.length ||
        !$status.length ||
        !$list.length ||
        !$panel.length
    ) {
        return;
    }

    $panel.on('change keypress input', '#oyiso-plugin-backup-file', function (event) {
        event.stopPropagation();
    });

    function setLocalActionButtonsDisabled(disabled) {
        $('.oyiso-plugin-backup-restore-local, .oyiso-plugin-backup-delete-local').prop('disabled', disabled);
        $('.oyiso-plugin-backup-download')
            .toggleClass('is-disabled', disabled)
            .attr('aria-disabled', disabled ? 'true' : 'false')
            .attr('tabindex', disabled ? '-1' : '0');
    }

    function getSelectedImportFile() {
        return $file[0].files && $file[0].files[0] ? $file[0].files[0] : null;
    }

    function renderSelectedImportFile() {
        var selectedFile = getSelectedImportFile();

        if (!selectedFile) {
            $fileName.text('');
            $fileMeta.removeClass('is-visible');
            $import.text(oyisoPluginBackup.labels.chooseFile);
            return;
        }

        $fileName.text(selectedFile.name);
        $fileMeta.addClass('is-visible');
        $import.text(oyisoPluginBackup.labels.importFile);
    }

    $create.on('click', function () {
        $create.prop('disabled', true);
        $status.html('<p style="margin:0;color:#6b7280;">' + oyisoPluginBackup.labels.creating + '</p>');

        $.post(oyisoPluginBackup.ajaxUrl, {
            action: 'oyiso_plugin_backup_create',
            nonce: oyisoPluginBackup.nonces.create
        }).done(function (response) {
            if (response && response.success) {
                if (response.data && response.data.listHtml !== undefined) {
                    $list.html(response.data.listHtml);
                }

                var message = response.data && response.data.message ? response.data.message : '本地备份已创建。';
                $status.html('<p style="margin:0;color:#15803d;">' + $('<div/>').text(message).html() + '</p>');
                return;
            }

            var message = response && response.data && response.data.message
                ? response.data.message
                : oyisoPluginBackup.labels.error;

            $status.html('<p style="margin:0;color:#b91c1c;">' + $('<div/>').text(message).html() + '</p>');
        }).fail(function (xhr) {
            var message = oyisoPluginBackup.labels.error;

            if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                message = xhr.responseJSON.data.message;
            }

            $status.html('<p style="margin:0;color:#b91c1c;">' + $('<div/>').text(message).html() + '</p>');
        }).always(function () {
            $create.prop('disabled', false);
        });
    });

    $file.on('change', function () {
        renderSelectedImportFile();
    });

    $fileRemove.on('click', function () {
        $file.val('');
        renderSelectedImportFile();
    });

    $(document).on('click', '.oyiso-plugin-backup-restore-local', function () {
        var file = $(this).data('file');
        var keepDisabled = false;

        if (!file) {
            return;
        }

        if (!window.confirm(oyisoPluginBackup.labels.confirmRestore)) {
            return;
        }

        setLocalActionButtonsDisabled(true);
        $status.html('<p style="margin:0;color:#6b7280;">' + oyisoPluginBackup.labels.restoring + '</p>');

        $.post(oyisoPluginBackup.ajaxUrl, {
            action: 'oyiso_plugin_backup_restore_local',
            nonce: oyisoPluginBackup.nonces.restoreLocal,
            file: file
        }).done(function (response) {
            if (response && response.success && response.data && response.data.message) {
                keepDisabled = true;
                $status.html('<p style="margin:0;color:#15803d;">' + $('<div/>').text(response.data.message).html() + '</p>');
                window.setTimeout(function () {
                    window.location.reload();
                }, 800);
                return;
            }

            var message = response && response.data && response.data.message
                ? response.data.message
                : oyisoPluginBackup.labels.error;

            $status.html('<p style="margin:0;color:#b91c1c;">' + $('<div/>').text(message).html() + '</p>');
        }).fail(function (xhr) {
            var message = oyisoPluginBackup.labels.error;

            if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                message = xhr.responseJSON.data.message;
            }

            $status.html('<p style="margin:0;color:#b91c1c;">' + $('<div/>').text(message).html() + '</p>');
        }).always(function () {
            if (!keepDisabled) {
                setLocalActionButtonsDisabled(false);
            }
        });
    });

    $(document).on('click', '.oyiso-plugin-backup-delete-local', function () {
        var file = $(this).data('file');

        if (!file) {
            return;
        }

        if (!window.confirm(oyisoPluginBackup.labels.confirmDelete)) {
            return;
        }

        setLocalActionButtonsDisabled(true);
        $status.html('<p style="margin:0;color:#6b7280;">' + oyisoPluginBackup.labels.deleting + '</p>');

        $.post(oyisoPluginBackup.ajaxUrl, {
            action: 'oyiso_plugin_backup_delete_local',
            nonce: oyisoPluginBackup.nonces.deleteLocal,
            file: file
        }).done(function (response) {
            if (response && response.success) {
                if (response.data && response.data.listHtml !== undefined) {
                    $list.html(response.data.listHtml);
                }

                var message = response.data && response.data.message ? response.data.message : '本地备份已删除。';
                $status.html('<p style="margin:0;color:#15803d;">' + $('<div/>').text(message).html() + '</p>');
                return;
            }

            var message = response && response.data && response.data.message
                ? response.data.message
                : oyisoPluginBackup.labels.error;

            $status.html('<p style="margin:0;color:#b91c1c;">' + $('<div/>').text(message).html() + '</p>');
        }).fail(function (xhr) {
            var message = oyisoPluginBackup.labels.error;

            if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                message = xhr.responseJSON.data.message;
            }

            $status.html('<p style="margin:0;color:#b91c1c;">' + $('<div/>').text(message).html() + '</p>');
        }).always(function () {
            setLocalActionButtonsDisabled(false);
        });
    });

    $import.on('click', function () {
        var file = getSelectedImportFile();
        var keepDisabled = false;

        if (!file) {
            $file.trigger('click');
            return;
        }

        if (!window.confirm(oyisoPluginBackup.labels.confirmRestore)) {
            return;
        }

        var formData = new FormData();
        formData.append('action', 'oyiso_plugin_backup_import');
        formData.append('nonce', oyisoPluginBackup.nonces.import);
        formData.append('file', file);

        $import.prop('disabled', true);
        $status.html('<p style="margin:0;color:#6b7280;">' + oyisoPluginBackup.labels.restoring + '</p>');

        $.ajax({
            url: oyisoPluginBackup.ajaxUrl,
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false
        }).done(function (response) {
            if (response && response.success && response.data && response.data.message) {
                keepDisabled = true;
                $status.html('<p style="margin:0;color:#15803d;">' + $('<div/>').text(response.data.message).html() + '</p>');
                window.setTimeout(function () {
                    window.location.reload();
                }, 800);
                return;
            }

            var message = response && response.data && response.data.message
                ? response.data.message
                : oyisoPluginBackup.labels.error;

            $status.html('<p style="margin:0;color:#b91c1c;">' + $('<div/>').text(message).html() + '</p>');
        }).fail(function (xhr) {
            var message = oyisoPluginBackup.labels.error;

            if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                message = xhr.responseJSON.data.message;
            }

            $status.html('<p style="margin:0;color:#b91c1c;">' + $('<div/>').text(message).html() + '</p>');
        }).always(function () {
            if (!keepDisabled) {
                $import.prop('disabled', false);
            }
        });
    });

    renderSelectedImportFile();
});
JS);
    }
}

add_action('admin_enqueue_scripts', 'oyiso_enqueue_plugin_backup_assets');
add_action('wp_ajax_oyiso_plugin_backup_create', 'oyiso_handle_plugin_backup_create');
add_action('wp_ajax_oyiso_plugin_backup_download', 'oyiso_handle_plugin_backup_download');
add_action('wp_ajax_oyiso_plugin_backup_restore_local', 'oyiso_handle_plugin_backup_restore_local');
add_action('wp_ajax_oyiso_plugin_backup_import', 'oyiso_handle_plugin_backup_import');
add_action('wp_ajax_oyiso_plugin_backup_delete_local', 'oyiso_handle_plugin_backup_delete_local');

if (class_exists('CSF')) {
    CSF::createSection($prefix, [
        'parent'   => 'oyiso-update-backup',
        'id'       => 'oyiso-backup',
        'tab_id'   => 'oyiso-backup',
        'title'    => '备份与恢复',
        'icon'     => 'fas fa-database',
        'priority' => 10,
        'fields'   => [
            [
                'type'    => 'heading',
                'content' => '备份与恢复',
            ],
            [
                'type'     => 'callback',
                'function' => 'oyiso_render_plugin_backup_panel',
            ],
        ],
    ]);
}
