<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/** @phpstan-import-type Run from Oyiso_Agent_Store
 * @phpstan-import-type Fields from Oyiso_Agent_Store
 * @phpstan-type ContentChange array{id:string,post_id:int,post_type:string,title:string,before:Fields,after:Fields,saved:Fields,status:string,kind:string,url?:string,editor_before?:Fields,message_index?:int}
 */
final class Oyiso_Agent_Content
{
    public const TOOLS = ['search-posts', 'get-post', 'prepare-post-update', 'prepare-post-draft', 'apply-post-update', 'revert-post-update', 'create-post-draft'];

    /** @param Run $run */
    public static function pending(array $run): bool
    {
        return count(array_filter($run['content_changes'] ?? [], static fn(array $change): bool => $change['status'] === 'pending')) > 0;
    }

    /** @param Run $run
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public static function execute(array &$run, string $name, array $input): array|WP_Error
    {
        if ($name === 'get-current-page') {
            $page = $run['page_context'] ?? null;
            if ($page === null) return ['notice' => '没有当前页面资料，请刷新所在的后台页面后重新发送消息。'];
            if ($page['post_id'] > 0 && !current_user_can('edit_post', $page['post_id'])) return new WP_Error('agent_context_forbidden', '没有读取当前内容的权限。', ['status' => 403]);
            if ($page['post_type'] !== '' && !Oyiso_Agent_Page_Context::enabled($page['post_type'], true)) $page['fields'] = [];
            return $page;
        }
        if (!Oyiso_Agent_Page_Context::enabled('post', true)) return new WP_Error('agent_plugin_disabled', 'WordPress 内容工具未启用。', ['status' => 403]);
        if ($name === 'search-posts') {
            $type = ($input['post_type'] ?? 'post') === 'page' ? 'page' : 'post';
            $query = new WP_Query(['post_type' => $type, 'post_status' => ['publish', 'draft', 'pending', 'private', 'future'], 's' => is_string($input['search'] ?? null) ? $input['search'] : '', 'posts_per_page' => 10, 'paged' => max(1, is_int($input['page'] ?? null) ? $input['page'] : 1)]);
            $items = [];
            foreach ($query->posts as $post) if ($post instanceof WP_Post && current_user_can('edit_post', $post->ID)) $items[] = ['id' => $post->ID, 'title' => $post->post_title, 'post_type' => $post->post_type, 'status' => $post->post_status];
            return ['items' => $items, 'total' => (int) $query->found_posts, 'pages' => (int) $query->max_num_pages];
        }
        if ($name === 'prepare-post-draft') return self::writing($run, $input);
        $id = is_int($input['post_id'] ?? null) ? $input['post_id'] : 0;
        $post = self::post($id);
        if (is_wp_error($post)) return $post;
        $saved = Oyiso_Agent_Page_Context::fields($post);
        if ($name === 'get-post') {
            $context = $run['page_context'] ?? null;
            $editing = $context !== null && $context['post_id'] === $id && !$context['truncated'] && $context['editor'] !== 'unsupported';
            $fields = $editing ? array_replace($saved, $context['fields']) : $saved;
            $run['post_snapshots'][$id] = ['fields' => $fields, 'saved' => $saved];
            return ['post_id' => $id, 'post_type' => $post->post_type, 'status' => $post->post_status, 'fields' => array_map(static fn(string $value): string => mb_substr($value, 0, 20000), $fields), 'source' => $editing ? 'current_editor' : 'saved', 'truncated' => count(array_filter($fields, static fn(string $value): bool => mb_strlen($value) > 20000)) > 0, 'notice' => '修改时保留区块注释和未涉及内容。current_editor 可能包含尚未保存的编辑。'];
        }
        if ($name !== 'prepare-post-update') return new WP_Error('agent_unknown_tool', '工具不存在。');
        $page = $run['page_context'] ?? null;
        if ($page !== null && $page['post_id'] === $id && $page['truncated']) return new WP_Error('agent_context_truncated', '当前编辑内容过长，不能基于截断内容覆盖正文。请缩小修改范围。');
        if (get_post_meta($id, '_elementor_edit_mode', true) === 'builder' && is_array($input['fields'] ?? null) && isset($input['fields']['content'])) return new WP_Error('agent_editor_unsupported', '此内容使用 Elementor，请使用对应的编辑器工具修改布局。');
        $snapshot = $run['post_snapshots'][$id] ?? null;
        if ($snapshot === null) return new WP_Error('agent_content_not_read', '请先使用 get-post 读取内容，再准备修改。');
        if (is_array($input['fields'] ?? null) && isset($input['fields']['content']) && mb_strlen($snapshot['fields']['content']) > 20000) return new WP_Error('agent_context_truncated', '正文超过单次读取范围，不能基于截断内容覆盖。请缩小修改范围。');
        if ($saved !== $snapshot['saved']) return new WP_Error('agent_content_conflict', '站点内容已改变，请重新读取后生成方案。', ['status' => 409]);
        $after = self::sanitize($input['fields'] ?? null, $snapshot['fields']);
        if (is_wp_error($after)) return $after;
        if ($after === $snapshot['fields']) return new WP_Error('agent_no_changes', '内容没有变化。');
        $changes = $run['content_changes'] ?? [];
        foreach ($changes as &$change) if ($change['post_id'] === $id && $change['status'] === 'pending') $change['status'] = 'superseded';
        unset($change);
        $run['content_changes'] = $changes;
        return self::proposal($run, ['id' => wp_generate_uuid4(), 'post_id' => $id, 'post_type' => $post->post_type, 'title' => $post->post_title, 'before' => $snapshot['fields'], 'after' => $after, 'saved' => $saved, 'status' => 'pending', 'kind' => 'update']);
    }

    /** @param Run $run
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    private static function writing(array &$run, array $input): array|WP_Error
    {
        $destination = $input['destination'] ?? 'auto';
        if (!in_array($destination, ['auto', 'current_editor', 'new_draft'], true)) return new WP_Error('agent_editor_target', '写作目标无效。');
        $page = $run['page_context'] ?? null;
        $current = $page !== null && $page['post_id'] > 0 && in_array($page['post_type'], ['post', 'page'], true);
        if ($destination === 'current_editor' && !$current) return new WP_Error('agent_editor_target', '没有当前文章或页面编辑器，请打开目标内容后重新生成。');
        if ($destination !== 'new_draft' && $current) {
            $permission = Oyiso_Agent_Permissions::check('oyiso/apply-post-update');
            if (is_wp_error($permission)) return $permission;
            if (!in_array($page['editor'], ['classic', 'gutenberg'], true)) return new WP_Error('agent_editor_unsupported', '当前编辑器暂不支持直接填入，请使用经典或区块编辑器。');
            if ($page['truncated']) return new WP_Error('agent_context_truncated', '当前编辑内容过长，不能基于截断内容覆盖正文。请缩小修改范围。');
            $read = self::execute($run, 'get-post', ['post_id' => $page['post_id']]);
            if (is_wp_error($read)) return $read;
            return self::execute($run, 'prepare-post-update', ['post_id' => $page['post_id'], 'fields' => $input['fields'] ?? null]);
        }
        $type = ($input['post_type'] ?? 'post') === 'page' ? 'page' : 'post';
        $object = get_post_type_object($type);
        if ($object === null || !current_user_can($object->cap->create_posts)) return new WP_Error('agent_content_forbidden', '没有创建此类草稿的权限。', ['status' => 403]);
        $after = self::sanitize($input['fields'] ?? null, ['title' => '', 'content' => '', 'excerpt' => '']);
        if (is_wp_error($after)) return $after;
        if (trim($after['title']) === '' || trim($after['content']) === '') return new WP_Error('agent_content_empty', '新草稿需要标题和正文。');
        return self::proposal($run, ['id' => wp_generate_uuid4(), 'post_id' => 0, 'post_type' => $type, 'title' => $after['title'], 'before' => [], 'after' => $after, 'saved' => [], 'status' => 'pending', 'kind' => 'draft']);
    }

    /** @param Run $run
     * @param ContentChange $change
     * @return array<string, mixed>|WP_Error
     */
    private static function proposal(array &$run, array $change): array|WP_Error
    {
        if (count($run['content_changes'] ?? []) >= 20) return new WP_Error('agent_proposal_limit', '本次对话的内容方案已达上限，请新建对话。');
        $run['content_changes'][] = $change;
        return ['proposal_id' => $change['id'], 'post_id' => $change['post_id'], 'status' => 'awaiting_user_review', 'notice' => '方案已生成，尚未保存。请用户核对内容并在操作卡片中确认。'];
    }

    private static function post(int $id): WP_Post|WP_Error
    {
        $post = $id > 0 ? get_post($id) : null;
        return $post instanceof WP_Post && in_array($post->post_type, ['post', 'page'], true) && !in_array($post->post_status, ['trash', 'inherit'], true) && current_user_can('edit_post', $id) ? $post : new WP_Error('agent_content_forbidden', '文章或页面不存在，或没有编辑权限。', ['status' => 403]);
    }

    /** @param Fields $before
     * @return Fields|WP_Error
     */
    private static function sanitize(mixed $input, array $before): array|WP_Error
    {
        if (!is_array($input) || $input === [] || array_diff(array_keys($input), array_keys($before)) !== []) return new WP_Error('agent_content_fields', '仅支持标题、正文、摘要和已启用的 SEO 字段。');
        foreach ($input as $key => $value) {
            if (!is_string($key) || !is_string($value) || mb_strlen($value) > ($key === 'content' ? 60000 : ($key === 'title' ? 300 : 4000))) return new WP_Error('agent_content_fields', '内容字段无效或超过长度限制。');
            $before[$key] = in_array($key, ['content', 'excerpt'], true) ? wp_kses_post($value) : sanitize_textarea_field($value);
        }
        return $before;
    }

    /** @param WP_REST_Request<array{id:int|string,proposal:string,action:string,kind?:string,signature?:string}> $request */
    public static function control(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (int) $request->get_param('id');
        if (!Oyiso_Agent_Store::owned($id)) return new WP_Error('agent_task_missing', '任务不存在或不属于当前用户。', ['status' => 404]);
        if (!Oyiso_Agent_Config::enabled() || !Oyiso_Agent_Config::supported()) return new WP_Error('agent_not_ready', 'AI Agent 未启用或当前环境不支持。', ['status' => 403]);
        if (!Oyiso_Agent_Store::lock('run_' . $id)) return new WP_Error('agent_busy', '任务正在处理，请稍后重试。', ['status' => 409]);
        try {
            $run = Oyiso_Agent_Store::get($id);
            if (is_wp_error($run)) return $run;
            if (in_array($run['status'], ['queued', 'running', 'cancelled'], true) || Oyiso_Agent_Store::cancelled($id)) return new WP_Error('agent_content_state', '请等待任务停止，或新建对话后再操作。', ['status' => 409]);
            $proposal = (string) $request->get_param('proposal');
            $action = (string) $request->get_param('action');
            if (!in_array($action, ['apply', 'revert', 'editor', 'filled', 'dismiss'], true)) return new WP_Error('agent_action_invalid', '操作无效。', ['status' => 400]);
            $product = $request->get_param('kind') === 'product';
            $items = $run['content_changes'] ?? [];
            if ($product) {
                $items = [];
                foreach ($run['changes'] as $change) $items[] = ['id' => $change['id'], 'post_id' => $change['product_id'], 'post_type' => 'product', 'title' => $change['name'], 'before' => $change['before'], 'after' => $change['after'], 'saved' => $change['before'], 'status' => $change['status'], 'kind' => ($change['type'] ?? 'update') === 'update' ? 'product' : 'trash', 'editor_before' => $change['editor_before'] ?? $change['before']];
            }
            foreach ($items as $index => $change) {
                if ($change['id'] !== $proposal) continue;
                if ($product && ($change['kind'] !== 'product' || !in_array($action, ['editor', 'filled'], true))) return new WP_Error('agent_action_invalid', '此商品方案不支持该操作。', ['status' => 400]);
                if ($change['post_id'] > 0) {
                    $post = get_post($change['post_id']);
                    if (!$post instanceof WP_Post || $post->post_type !== $change['post_type'] || !current_user_can('edit_post', $post->ID) || $post->post_status === 'trash') return new WP_Error('agent_content_forbidden', '内容不存在或没有编辑权限。', ['status' => 403]);
                }
                $permission = $product ? 'oyiso/apply-product-update' : ($change['kind'] === 'draft' ? 'oyiso/create-post-draft' : ($action === 'revert' ? 'oyiso/revert-post-update' : 'oyiso/apply-post-update'));
                if ($action !== 'dismiss') { $grant = Oyiso_Agent_Permissions::check($permission); if (is_wp_error($grant)) return $grant; }
                if ($action === 'editor') {
                    if (!in_array($change['status'], ['pending', 'filled'], true) || $change['kind'] === 'draft') return new WP_Error('agent_content_state', '此方案当前不能填入编辑器。', ['status' => 409]);
                    $expected = $change['editor_before'] ?? $change['before'];
                    $fields = array_diff_assoc($change['after'], $change['before']);
                    $payload = ['post_id' => $change['post_id'], 'expected' => $expected, 'fields' => $fields, 'signature' => wp_create_nonce('oyiso-agent-fill:' . $id . ':' . $proposal)];
                    if ($change['status'] === 'filled') {
                        // A reload may restore the saved baseline rather than the previous unsaved editor state.
                        $fields += array_diff_assoc($change['after'], $change['saved']);
                        $payload['fields'] = $fields;
                        $payload['expected'] = array_intersect_key($expected, $fields);
                        $payload['restore_from'] = [array_intersect_key($change['saved'], $fields), array_intersect_key($change['after'], $fields)];
                    }
                    return new WP_REST_Response($payload);
                }
                if ($action === 'filled') {
                    $signature = $request->get_param('signature');
                    if (!in_array($change['status'], ['pending', 'filled'], true) || $change['kind'] === 'draft' || !is_string($signature) || !wp_verify_nonce($signature, 'oyiso-agent-fill:' . $id . ':' . $proposal)) return new WP_Error('agent_content_state', '填入确认已失效。', ['status' => 409]);
                    $items[$index]['status'] = 'filled';
                } elseif ($action === 'dismiss') {
                    if ($change['status'] !== 'pending') return new WP_Error('agent_content_state', '此方案已经处理，不能再取消。', ['status' => 409]);
                    $items[$index]['status'] = 'dismissed';
                }
                elseif (!$product) {
                    $result = self::write($change, $action);
                    if (is_wp_error($result)) return $result;
                    $items[$index] = $result;
                }
                if ($product) { $original = $run['changes']; if (isset($original[$index])) $original[$index]['status'] = $items[$index]['status']; $run['changes'] = $original; }
                else $run['content_changes'] = $items;
                $run['status'] = self::pending($run) || Oyiso_Agent_MCP::pending($run) || count(array_filter($run['changes'], static fn(array $item): bool => $item['status'] === 'pending')) > 0 ? 'waiting' : 'completed';
                Oyiso_Agent_Store::event($run, 'success', $action === 'filled' ? '已填入编辑器，尚未保存' : ($action === 'dismiss' ? '已取消内容方案' : '内容操作已完成'));
                Oyiso_Agent_Store::save($run);
                return new WP_REST_Response(Oyiso_Agent_Store::view($run));
            }
            return new WP_Error('agent_proposal_missing', '内容方案不存在。', ['status' => 404]);
        } finally { Oyiso_Agent_Store::unlock('run_' . $id); }
    }

    /** @param ContentChange $change
     * @return ContentChange|WP_Error
     */
    private static function write(array $change, string $action): array|WP_Error
    {
        $done = $action === 'revert' ? 'reverted' : 'applied';
        if ($change['status'] === $done) return $change;
        if ($change['status'] !== ($action === 'revert' ? 'applied' : 'pending')) return new WP_Error('agent_content_state', '此方案当前无法执行。', ['status' => 409]);
        if ($change['kind'] === 'draft') {
            if ($action !== 'apply') return new WP_Error('agent_content_state', '新建草稿后请通过 WordPress 回收站管理。', ['status' => 409]);
            $object = get_post_type_object($change['post_type']);
            if ($object === null || !current_user_can($object->cap->create_posts)) return new WP_Error('agent_content_forbidden', '没有创建草稿的权限。', ['status' => 403]);
            global $wpdb;
            $wpdb->query('START TRANSACTION');
            try {
                $existing = get_posts(['post_type' => $change['post_type'], 'post_status' => ['draft', 'pending', 'publish', 'private', 'future', 'trash'], 'author' => get_current_user_id(), 'meta_key' => '_oyiso_agent_draft', 'meta_value' => $change['id'], 'numberposts' => 1, 'fields' => 'ids']);
                $id = $existing[0] ?? wp_insert_post(wp_slash(['post_type' => $change['post_type'], 'post_status' => 'draft', 'post_author' => get_current_user_id(), 'post_title' => $change['after']['title'], 'post_content' => $change['after']['content'], 'post_excerpt' => $change['after']['excerpt'], 'meta_input' => ['_oyiso_agent_draft' => $change['id']]]), true);
                if (is_wp_error($id)) { $wpdb->query('ROLLBACK'); return $id; }
                $wpdb->query('COMMIT');
            } catch (Throwable $error) { $wpdb->query('ROLLBACK'); return new WP_Error('agent_content_write', Oyiso_Agent_Config::error($error->getMessage())); }
            $change['post_id'] = $id;
            $change['url'] = admin_url('post.php?post=' . $id . '&action=edit');
            $change['status'] = $done;
            return $change;
        }
        global $wpdb;
        $wpdb->query('START TRANSACTION');
        try {
            $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID = %d FOR UPDATE", $change['post_id']));
            $wpdb->get_col($wpdb->prepare("SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d FOR UPDATE", $change['post_id']));
            clean_post_cache($change['post_id']);
            $post = self::post($change['post_id']);
            if (is_wp_error($post)) { $wpdb->query('ROLLBACK'); return $post; }
            $current = Oyiso_Agent_Page_Context::fields($post);
            $expected = $action === 'revert' ? $change['after'] : $change['saved'];
            if ($current !== $expected) { $wpdb->query('ROLLBACK'); return new WP_Error('agent_content_conflict', '内容已被其他操作修改，请重新读取并生成方案；此次未覆盖。', ['status' => 409]); }
            $target = $action === 'revert' ? $change['saved'] : $change['after'];
            $result = wp_update_post(wp_slash(['ID' => $post->ID, 'post_status' => $post->post_status === 'auto-draft' ? 'draft' : $post->post_status, 'post_title' => $target['title'], 'post_content' => $target['content'], 'post_excerpt' => $target['excerpt']]), true);
            if (is_wp_error($result)) { $wpdb->query('ROLLBACK'); return $result; }
            foreach (Oyiso_Agent_Tools::SEO as $field => $meta) if (isset($target[$field])) update_post_meta($post->ID, $meta, wp_slash($target[$field]));
            $wpdb->query('COMMIT');
            clean_post_cache($post->ID);
            $change['status'] = $done;
            return $change;
        } catch (Throwable $error) { $wpdb->query('ROLLBACK'); return new WP_Error('agent_content_write', Oyiso_Agent_Config::error($error->getMessage())); }
    }
}
