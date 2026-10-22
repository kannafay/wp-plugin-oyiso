<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

final class Oyiso_Plugin_Backup
{
    public const MAX_BYTES = 2097152;

    /** @return string|WP_Error JSON attachment contents, without creating a file. */
    public static function document(): string|WP_Error
    {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugin = get_plugin_data(dirname(__DIR__, 3) . '/oyiso.php', false, false);
        $options = get_option('oyiso', []);
        if (!is_array($options)) {
            return new WP_Error('oyiso_backup_data', '当前插件配置格式无效，无法导出。', ['status' => 500]);
        }
        $json = wp_json_encode([
            'plugin' => 'oyiso',
            'option_name' => 'oyiso',
            'blog_id' => get_current_blog_id(),
            'version' => (string) $plugin['Version'],
            'exported_at' => current_time('mysql'),
            'site_url' => home_url('/'),
            'data' => $options,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT, 64);

        if (!is_string($json)) {
            return new WP_Error('oyiso_backup_encode', '备份数据生成失败，请稍后重试。', ['status' => 500]);
        }
        if (strlen($json) > self::MAX_BYTES) {
            return new WP_Error('oyiso_backup_size', '配置超过 2 MB，无法生成可恢复的备份。', ['status' => 413]);
        }
        return $json;
    }

    public static function restore(string $json): true|WP_Error
    {
        if (strlen($json) > self::MAX_BYTES) {
            return new WP_Error('oyiso_backup_size', '备份内容不得超过 2 MB。', ['status' => 413]);
        }
        // Accept JSON files saved with a UTF-8 byte order mark by desktop editors.
        if (str_starts_with($json, "\xEF\xBB\xBF")) {
            $json = substr($json, 3);
        }
        try {
            $payload = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new WP_Error('oyiso_backup_json', '备份内容不是有效的 JSON，或嵌套层级过深。', ['status' => 400]);
        }
        if (!is_array($payload) || ($payload['plugin'] ?? null) !== 'oyiso' || ($payload['option_name'] ?? null) !== 'oyiso') {
            return new WP_Error('oyiso_backup_plugin', '该备份不属于当前插件，无法恢复。', ['status' => 400]);
        }
        if (is_multisite() && ($payload['blog_id'] ?? null) !== get_current_blog_id()) {
            return new WP_Error('oyiso_backup_site', '备份的站点标识缺失或与当前站点不一致。', ['status' => 400]);
        }
        $data = $payload['data'] ?? null;
        if (!is_array($data)) {
            return new WP_Error('oyiso_backup_data', '备份中未找到有效的配置数据。', ['status' => 400]);
        }
        foreach (array_keys($data) as $key) {
            if (!is_string($key) || $key === '') {
                return new WP_Error('oyiso_backup_data', '备份配置字段格式无效。', ['status' => 400]);
            }
        }
        if (!update_option('oyiso', $data) && get_option('oyiso') !== $data) {
            return new WP_Error('oyiso_backup_save', '配置保存失败，请稍后重试。', ['status' => 500]);
        }
        return true;
    }

    public static function export(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('无权限下载配置备份。', '', ['response' => 403]);
        }
        check_admin_referer('oyiso_plugin_backup_export');
        $json = self::document();
        if (is_wp_error($json)) {
            wp_die(esc_html($json->get_error_message()), '', ['response' => $json->get_error_code() === 'oyiso_backup_size' ? 413 : 500]);
        }

        $host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        $site = is_string($host) ? sanitize_file_name($host) : '';
        $filename = ($site !== '' ? $site : 'site') . '-oyiso-backup-' . wp_date('Ymd-His') . '.json';
        nocache_headers();
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        header('X-Content-Type-Options: nosniff');
        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($json));
        echo $json;
        exit;
    }

    public static function import(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => '无权限恢复配置。'], 403);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_send_json_error(['message' => '请使用 POST 请求恢复配置。'], 405);
        }
        check_ajax_referer('oyiso_plugin_backup_import', 'nonce');
        $json = isset($_POST['backup']) && is_string($_POST['backup']) ? wp_unslash($_POST['backup']) : '';
        $result = self::restore($json);
        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            $status = is_array($data) && isset($data['status']) && is_int($data['status']) ? $data['status'] : 400;
            wp_send_json_error(['message' => $result->get_error_message()], $status);
        }
        wp_send_json_success(['message' => '插件配置已恢复，正在刷新页面。']);
    }

    public static function assets(string $hook): void
    {
        if (!oyiso_is_settings_page_hook($hook)) {
            return;
        }
        wp_enqueue_style('oyiso-plugin-backup', plugins_url('assets/backup.css', __FILE__), ['oyiso-admin-theme'], (string) filemtime(__DIR__ . '/assets/backup.css'));
        wp_enqueue_script('oyiso-plugin-backup', plugins_url('assets/backup.js', __FILE__), [], (string) filemtime(__DIR__ . '/assets/backup.js'), true);
        wp_localize_script('oyiso-plugin-backup', 'oyisoPluginBackup', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('oyiso_plugin_backup_import'),
            'maxBytes' => self::MAX_BYTES,
        ]);
    }

    public static function render(): void
    {
        $exportUrl = wp_nonce_url(admin_url('admin-post.php?action=oyiso_plugin_backup_export'), 'oyiso_plugin_backup_export');
        ?>
        <div class="oyiso-plugin-backup-panel">
            <p class="description">备份当前已保存的插件配置。文件直接下载到您的设备，站点不保留备份副本。</p>
            <div class="oyiso-backup-actions">
                <section class="oyiso-backup-card" aria-labelledby="oyiso-backup-export-title">
                    <h3 id="oyiso-backup-export-title">下载备份</h3>
                    <p class="description">将插件配置导出为 JSON 文件，恢复前可先下载一份备份。</p>
                    <a class="button button-secondary" href="<?php echo esc_url($exportUrl); ?>" id="oyiso-plugin-backup-export">下载配置备份</a>
                </section>
                <section class="oyiso-backup-card" aria-labelledby="oyiso-backup-import-title">
                    <h3 id="oyiso-backup-import-title">恢复配置</h3>
                    <p class="description">选择 JSON 配置备份，最大 2 MB。恢复将覆盖当前插件设置。</p>
                    <input type="file" id="oyiso-plugin-backup-file" accept=".json,application/json" hidden>
                    <div class="oyiso-backup-import-actions">
                        <button type="button" class="button" id="oyiso-plugin-backup-choose">选择 JSON 文件</button>
                        <button type="button" class="button button-primary" id="oyiso-plugin-backup-import" disabled>恢复配置</button>
                    </div>
                    <div class="oyiso-backup-selected" id="oyiso-plugin-backup-file-meta" hidden>
                        <span id="oyiso-plugin-backup-file-name"></span>
                        <button type="button" class="button-link" id="oyiso-plugin-backup-file-remove" aria-label="取消选择文件">取消选择</button>
                    </div>
                </section>
            </div>
            <?php if (is_multisite()): ?><p class="description">当前站点 ID：<?php echo esc_html((string) get_current_blog_id()); ?>。备份与恢复仅作用于当前站点。</p><?php endif; ?>
            <p id="oyiso-plugin-backup-status" role="status" aria-live="polite"></p>
        </div>
        <?php
    }
}

add_action('admin_enqueue_scripts', [Oyiso_Plugin_Backup::class, 'assets']);
add_action('admin_post_oyiso_plugin_backup_export', [Oyiso_Plugin_Backup::class, 'export']);
add_action('wp_ajax_oyiso_plugin_backup_import', [Oyiso_Plugin_Backup::class, 'import']);

if (class_exists('CSF')) {
    CSF::createSection('oyiso', [
        'parent' => 'oyiso-update-backup',
        'id' => 'oyiso-backup',
        'tab_id' => 'oyiso-backup',
        'title' => '备份与恢复',
        'icon' => 'fas fa-database',
        'priority' => 10,
        'fields' => [
            ['type' => 'heading', 'content' => '备份与恢复'],
            ['type' => 'callback', 'function' => [Oyiso_Plugin_Backup::class, 'render']],
        ],
    ]);
}
