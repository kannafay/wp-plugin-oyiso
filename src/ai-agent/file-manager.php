<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

final class Oyiso_Agent_File_Manager
{
    public static function assets(string $hook): void
    {
        if ($hook !== 'plugins_page_oyiso' || !current_user_can('manage_options') || is_network_admin() || is_user_admin()) return;
        wp_enqueue_style('oyiso-agent-files', plugins_url('assets/files.css', __FILE__), [], (string) filemtime(__DIR__ . '/assets/files.css'));
        wp_enqueue_script('oyiso-agent-files', plugins_url('assets/files.js', __FILE__), ['jquery'], (string) filemtime(__DIR__ . '/assets/files.js'), true);
        wp_localize_script('oyiso-agent-files', 'oyisoAgentFiles', ['url' => rest_url('oyiso-agent/v1/'), 'nonce' => wp_create_nonce('wp_rest')]);
    }

    public static function routes(): void
    {
        $permission = static fn(): bool => current_user_can('manage_options');
        register_rest_route('oyiso-agent/v1', '/files', [
            ['methods' => 'GET', 'permission_callback' => $permission, 'callback' => static fn(WP_REST_Request $request): WP_REST_Response => new WP_REST_Response(self::listing((int) $request->get_param('page'), (string) $request->get_param('search'), (string) $request->get_param('type')), 200, ['Cache-Control' => 'no-store, private']), 'args' => ['page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1], 'search' => ['type' => 'string', 'maxLength' => 180, 'default' => ''], 'type' => ['type' => 'string', 'enum' => ['', 'image', 'pdf', 'text'], 'default' => '']]],
            ['methods' => 'DELETE', 'permission_callback' => $permission, 'callback' => static function (WP_REST_Request $request): WP_REST_Response|WP_Error {
                $ids = $request->get_param('ids');
                if (!is_array($ids)) return new WP_Error('agent_file_selection', '请选择要删除的附件。', ['status' => 400]);
                $valid = []; foreach ($ids as $id) { if (!is_int($id)) return new WP_Error('agent_file_selection', '附件编号无效。', ['status' => 400]); $valid[] = $id; }
                $result = Oyiso_Agent_Attachments::remove($valid);
                return is_wp_error($result) ? $result : new WP_REST_Response(['deleted' => $result]);
            }, 'args' => ['ids' => ['type' => 'array', 'required' => true, 'minItems' => 1, 'maxItems' => 100, 'uniqueItems' => true, 'items' => ['type' => 'integer', 'minimum' => 1]]]],
        ]);
        register_rest_route('oyiso-agent/v1', '/files/migrate', ['methods' => 'POST', 'permission_callback' => $permission, 'callback' => static fn(): WP_REST_Response => new WP_REST_Response(Oyiso_Agent_File_Migration::batch(true))]);
    }

    /** @return array{items:list<array<string,mixed>>,pages:int,total:int,bytes:int,migration:array{remaining:int,failed:int},directory:string,error:string} */
    public static function listing(int $page, string $search, string $type): array
    {
        global $wpdb;
        $where = $wpdb->prepare("f.post_type = %s AND f.post_status = 'private' AND f.post_author = %d AND r.post_type = %s AND r.post_status = 'private' AND r.post_author = %d AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} d WHERE d.post_id = f.ID AND d.meta_key = %s)", Oyiso_Agent_Attachments::TYPE, get_current_user_id(), Oyiso_Agent_Store::TYPE, get_current_user_id(), '_oyiso_agent_file_deleted');
        if ($search !== '') $where .= $wpdb->prepare(' AND f.post_title LIKE %s', '%' . $wpdb->esc_like($search) . '%');
        if ($type !== '') $where .= $wpdb->prepare(' AND f.post_mime_type LIKE %s', match ($type) { 'image' => 'image/%', 'pdf' => 'application/pdf', default => 'text/plain' });
        $from = "FROM {$wpdb->posts} f INNER JOIN {$wpdb->posts} r ON r.ID = f.post_parent WHERE {$where}";
        $total = (int) $wpdb->get_var("SELECT COUNT(*) {$from}");
        $bytes = (int) $wpdb->get_var("SELECT COALESCE(SUM(CAST(s.meta_value AS UNSIGNED)), 0) FROM {$wpdb->posts} f INNER JOIN {$wpdb->posts} r ON r.ID = f.post_parent LEFT JOIN {$wpdb->postmeta} s ON s.post_id = f.ID AND s.meta_key = '_oyiso_agent_file_size' WHERE {$where}");
        $rows = $wpdb->get_results($wpdb->prepare("SELECT f.ID, f.post_parent, f.post_date, f.post_content <> '' AS legacy, r.post_title AS run_title {$from} ORDER BY f.ID DESC LIMIT 20 OFFSET %d", (max(1, $page) - 1) * 20), 'ARRAY_A');
        $items = [];
        foreach ($rows as $row) {
            $file = Oyiso_Agent_Attachments::metadata((int) $row['ID']);
            if ($file !== null) $items[] = $file + ['run_id' => (int) $row['post_parent'], 'run_title' => $row['run_title'], 'created' => $row['post_date'], 'legacy' => (bool) $row['legacy']];
        }
        $directory = Oyiso_Agent_Private_Files::directory();
        return ['items' => $items, 'pages' => (int) ceil($total / 20), 'total' => $total, 'bytes' => $bytes, 'migration' => Oyiso_Agent_File_Migration::status(), 'directory' => is_wp_error($directory) ? '' : $directory, 'error' => is_wp_error($directory) ? $directory->get_error_message() : ''];
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) return;
        ?>
        <div id="oyiso-agent-files" class="oyiso-agent-files">
            <div class="oyiso-agent-files-heading"><div><h2>文件管理</h2><p class="description">管理当前账号在 AI 对话中发送的图片、PDF 和文本附件。</p></div><button type="button" class="button" data-files-refresh>刷新</button></div>
            <div class="oyiso-agent-files-filters"><label class="screen-reader-text" for="oyiso-agent-files-search">文件名</label><input id="oyiso-agent-files-search" type="search" maxlength="180" placeholder="搜索文件名"><label class="screen-reader-text" for="oyiso-agent-files-type">文件类型</label><select id="oyiso-agent-files-type"><option value="">全部类型</option><option value="image">图片</option><option value="pdf">PDF</option><option value="text">文本与代码</option></select><button type="button" class="button" data-files-search>搜索</button><span data-files-summary></span><button type="button" class="button" data-files-delete-selected disabled>删除所选</button></div>
            <p data-files-notice role="status" aria-live="polite"></p><p data-files-migration hidden><span></span> <button type="button" class="button button-small" data-files-migrate>迁移旧附件</button></p>
            <div class="oyiso-agent-files-table"><table class="widefat striped"><thead><tr><td class="check-column"><input type="checkbox" data-files-all aria-label="选择本页全部文件"></td><th>文件名</th><th>类型</th><th>大小</th><th>所属对话</th><th>上传时间</th><th>操作</th></tr></thead><tbody data-files-body><tr><td colspan="7">正在加载文件…</td></tr></tbody></table></div>
            <div class="oyiso-agent-files-footer"><p class="description" data-files-directory></p><div><button type="button" class="button" data-files-prev>上一页</button><span data-files-page>1 / 1</span><button type="button" class="button" data-files-next>下一页</button></div></div>
            <dialog class="oyiso-agent-files-confirm" aria-labelledby="oyiso-agent-files-confirm-title"><h3 id="oyiso-agent-files-confirm-title">删除附件</h3><p data-files-delete-description></p><p data-files-delete-error role="alert" hidden></p><div><button type="button" class="button" data-files-delete-cancel>取消</button><button type="button" class="button" data-files-delete-confirm>删除</button></div></dialog>
        </div>
        <?php
    }
}
