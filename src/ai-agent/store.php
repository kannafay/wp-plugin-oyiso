<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * @phpstan-type Fields array<string, string>
 * @phpstan-type Change array{id:string, product_id:int, name:string, before:Fields, after:Fields, status:string, type?:string, sku?:string, product_type?:string, reason?:string, previous_status?:string, variation_count?:int, variation_statuses?:array<int,string>, fingerprint?:string,editor_before?:Fields}
 * @phpstan-import-type PageContext from Oyiso_Agent_Page_Context
 * @phpstan-import-type ContentChange from Oyiso_Agent_Content
 * @phpstan-type RunEvent array{time:string, type:string, label:string, detail:string}
 * @phpstan-type Execution array{index:int,status:string,step:int,tokens:int,token_start:int,events:list<RunEvent>,model_label?:string,legacy?:bool,truncated?:bool}
 * @phpstan-type MCPAction array{id:string,name:string,label:string,input_json:string,status:string,result:string}
 * @phpstan-import-type Attachment from Oyiso_Agent_Attachments
 * @phpstan-type Run array{id:int, selection?:string, page_context?:PageContext,content_changes?:list<ContentChange>,post_snapshots?:array<int,array{fields:Fields,saved:Fields}>,partial?:array{text:string,model:string,model_label?:string}, mcp_actions?:list<MCPAction>, product_snapshots?:array<int,string>,executions?:list<Execution>, status:string, step:int, tokens:int, messages:list<array<string, mixed>>, conversation:list<array{role:string, text:string, model?:string,model_label?:string, attachments?:list<Attachment>}>, events:list<RunEvent>, changes:list<Change>, snapshots:array<int, Fields>, error:string}
 */
final class Oyiso_Agent_Store
{
    public const TYPE = 'oyiso_agent_task';
    private const META = '_oyiso_agent_run';
    /** @var array<string, string> */
    private static array $locks = [];

    public static function register(): void
    {
        register_post_type(self::TYPE, ['public' => false, 'show_ui' => false, 'show_in_rest' => false, 'exclude_from_search' => true, 'rewrite' => false, 'supports' => []]);
    }

    /** @return Run|WP_Error */
    public static function create(string $message, ?string $selection = null): array|WP_Error
    {
        $id = wp_insert_post(['post_type' => self::TYPE, 'post_status' => 'private', 'post_author' => get_current_user_id(), 'post_title' => mb_substr(wp_strip_all_tags($message), 0, 70)], true);
        if (is_wp_error($id)) {
            return $id;
        }
        $run = ['id' => $id, 'status' => 'queued', 'step' => 0, 'tokens' => 0, 'messages' => [], 'conversation' => [], 'events' => [], 'changes' => [], 'snapshots' => [], 'error' => ''];
        if ($selection !== null) $run['selection'] = $selection;
        self::save($run);
        return $run;
    }

    /** @return Run|WP_Error */
    public static function get(int $id): array|WP_Error
    {
        $post = get_post($id);
        $run = get_post_meta($id, self::META, true);
        if (!$post instanceof WP_Post || $post->post_type !== self::TYPE || !is_array($run) || !isset($run['status'], $run['messages'], $run['changes'])) {
            return new WP_Error('agent_task_missing', '任务不存在。', ['status' => 404]);
        }
        /** @var Run $run */
        return $run;
    }

    public static function owned(int $id): bool
    {
        $post = get_post($id);
        return $post instanceof WP_Post && $post->post_type === self::TYPE && $post->post_status === 'private' && (int) $post->post_author === get_current_user_id();
    }

    /** @param array<mixed> $ids
     * @return list<int>|WP_Error
     */
    public static function remove(array $ids): array|WP_Error
    {
        if (!current_user_can('manage_options') || $ids === [] || count($ids) > 100) return new WP_Error('agent_delete_invalid', '请选择 1–100 个对话。', ['status' => 400]);
        $valid = [];
        foreach ($ids as $id) {
            if (!is_int($id) || !self::owned($id)) return new WP_Error('agent_delete_forbidden', '部分对话不存在或不属于当前账号，未执行删除。', ['status' => 404]);
            $valid[] = $id;
        }
        $valid = array_values(array_unique($valid));
        foreach ($valid as $id) {
            self::cancel($id);
            $files = Oyiso_Agent_Attachments::eraseRun($id);
            if (is_wp_error($files)) return $files;
            // Keep records recoverable even on sites with EMPTY_TRASH_DAYS set to zero.
            update_post_meta($id, '_wp_trash_meta_status', 'private');
            update_post_meta($id, '_wp_trash_meta_time', time());
            $result = wp_update_post(['ID' => $id, 'post_status' => 'trash'], true);
            if (is_wp_error($result)) return $result;
        }
        return $valid;
    }

    /** @param Run $run */
    public static function save(array $run): void
    {
        if (self::cancelled($run['id'])) {
            $run['status'] = 'cancelled';
            foreach ($run['changes'] as &$change) {
                if ($change['status'] === 'pending') {
                    $change['status'] = 'cancelled';
                }
            }
            unset($change);
            foreach ($run['content_changes'] ?? [] as $index => $change) if ($change['status'] === 'pending') $run['content_changes'][$index]['status'] = 'cancelled';
            foreach ($run['mcp_actions'] ?? [] as $index => $action) {
                if ($action['status'] === 'pending') $run['mcp_actions'][$index]['status'] = 'cancelled';
            }
        }
        if (isset($run['executions'])) $run['executions'] = self::updateExecutions($run['executions'], $run['status'], $run['step'], $run['tokens']);
        update_post_meta($run['id'], self::META, wp_slash($run));
    }

    /** Called before appending a new user message, so the previous turn keeps its final values.
     * @param Run $run
     */
    public static function beginExecution(array &$run): void
    {
        $executions = self::updateExecutions(($run['executions'] ?? []) ?: self::legacyExecutions($run), $run['status'], $run['step'], $run['tokens']);
        $executions[] = ['index' => count($run['conversation']), 'status' => 'queued', 'step' => 0, 'tokens' => 0, 'token_start' => $run['tokens'], 'events' => [], 'model_label' => Oyiso_Agent_Connections::label($run['selection'] ?? null)];
        $run['executions'] = $executions;
    }

    /** @param list<Execution> $executions
     * @return list<Execution>
     */
    private static function updateExecutions(array $executions, string $status, int $step, int $tokens): array
    {
        $index = array_key_last($executions);
        if ($index === null) return $executions;
        $executions[$index]['status'] = $status;
        $executions[$index]['step'] = $step;
        $executions[$index]['tokens'] = max(0, $tokens - $executions[$index]['token_start']);
        return $executions;
    }

    /** Older events were not associated with individual user messages. Do not invent that history.
     * @param Run $run
     * @return list<Execution>
     */
    private static function legacyExecutions(array $run): array
    {
        if ($run['conversation'] === [] && $run['events'] === []) return [];
        $index = -1;
        foreach ($run['conversation'] as $key => $entry) if ($entry['role'] === 'user') $index = $key;
        return [['index' => $index, 'status' => $run['status'], 'step' => $run['step'], 'tokens' => $run['tokens'], 'token_start' => 0, 'events' => $run['events'], 'legacy' => true]];
    }

    /** @param Run $run */
    public static function event(array &$run, string $type, string $label, string $detail = ''): void
    {
        $event = ['time' => gmdate('c'), 'type' => $type, 'label' => $label, 'detail' => mb_substr($detail, 0, 2000)];
        $run['events'][] = $event;
        $run['events'] = array_slice($run['events'], -100);
        $executions = $run['executions'] ?? [];
        $index = array_key_last($executions);
        if ($index !== null) {
            $execution = $executions[$index];
            if (mb_strlen($event['label']) > 150 || mb_strlen($event['detail']) > 300) $execution['truncated'] = true;
            $event['label'] = mb_substr($event['label'], 0, 150);
            $event['detail'] = mb_substr($event['detail'], 0, 300);
            $execution['events'][] = $event;
            if (count($execution['events']) > 50) {
                $execution['events'] = array_slice($execution['events'], -50);
                $execution['truncated'] = true;
            }
            $executions[$index] = $execution;
            $run['executions'] = $executions;
        }
    }

    /** @phpstan-impure Cancellation may be updated by another request. */
    public static function cancelled(int $id): bool
    {
        global $wpdb;
        // Bypass this worker's metadata cache so another request can stop it.
        return $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1", $id, '_oyiso_agent_cancelled')) === '1';
    }

    public static function cancel(int $id): void
    {
        update_post_meta($id, '_oyiso_agent_cancelled', true);
        $run = self::get($id);
        if (!is_wp_error($run)) {
            self::save($run);
        }
        wp_clear_scheduled_hook(Oyiso_Agent_Runner::HOOK, [$id]);
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(Oyiso_Agent_Runner::HOOK, [$id], 'oyiso-agent');
        }
    }

    public static function lock(string $resource): bool
    {
        $key = 'oyiso_agent_lock_' . $resource;
        $stamp = time() . ':' . wp_generate_uuid4();
        if (add_option($key, $stamp, '', false)) {
            self::$locks[$resource] = $stamp;
            return true;
        }
        $old = get_option($key);
        if (!is_scalar($old) || (int) $old > time() - 180) {
            return false;
        }
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, (string) $old));
        wp_cache_delete($key, 'options');
        $locked = add_option($key, $stamp, '', false);
        if ($locked) {
            self::$locks[$resource] = $stamp;
        }
        return $locked;
    }

    public static function unlock(string $resource): void
    {
        if (!isset(self::$locks[$resource])) {
            return;
        }
        global $wpdb;
        $key = 'oyiso_agent_lock_' . $resource;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, self::$locks[$resource]));
        wp_cache_delete($key, 'options');
        unset(self::$locks[$resource]);
    }

    /** @return array{items:list<array{id:int,title:string,status:string,updated:string}>,pages:int} */
    public static function recent(int $page): array
    {
        $query = new WP_Query(['post_type' => self::TYPE, 'post_status' => 'private', 'author' => get_current_user_id(), 'posts_per_page' => 15, 'paged' => max(1, $page), 'orderby' => 'ID', 'order' => 'DESC']);
        $items = [];
        foreach ($query->posts as $post) {
            if (!$post instanceof WP_Post) {
                continue;
            }
            $run = self::get($post->ID);
            if (!is_wp_error($run)) {
                $items[] = ['id' => $post->ID, 'title' => $post->post_title, 'status' => $run['status'], 'updated' => $post->post_date];
            }
        }
        return ['items' => $items, 'pages' => (int) $query->max_num_pages];
    }

    /** @param Run $run
     * @return array<string, mixed>
     */
    public static function view(array $run): array
    {
        foreach ($run['conversation'] as &$entry) {
            if ($entry['role'] === 'assistant' && isset($entry['model']) && !isset($entry['model_label'])) {
                $entry['model_label'] = Oyiso_Agent_Connections::label('', $entry['model']);
            }
            if (isset($entry['attachments'])) foreach ($entry['attachments'] as &$file) $file = Oyiso_Agent_Attachments::metadata($file['id']) ?? ($file + ['deleted' => true]);
            unset($file);
        }
        unset($entry);
        if (self::cancelled($run['id'])) $run['status'] = 'cancelled';
        $executions = self::updateExecutions(($run['executions'] ?? []) ?: self::legacyExecutions($run), $run['status'], $run['step'], $run['tokens']);
        $view = array_intersect_key($run, array_flip(['id', 'selection', 'partial', 'mcp_actions', 'status', 'step', 'tokens', 'conversation', 'events', 'executions', 'changes', 'content_changes', 'page_context', 'error']));
        $view['executions'] = array_map(static function (array $execution): array { unset($execution['token_start']); return $execution; }, $executions);
        $lock = get_option('oyiso_agent_lock_run_' . $run['id'], '');
        $view['recoverable'] = $view['status'] === 'running' && (!is_string($lock) || (int) $lock <= time() - 180);
        return $view;
    }
}
