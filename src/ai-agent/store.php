<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * @phpstan-type Fields array<string, string>
 * @phpstan-type Change array{id:string, product_id:int, name:string, before:Fields, after:Fields, status:string}
 * @phpstan-type RunEvent array{time:string, type:string, label:string, detail:string}
 * @phpstan-type Run array{id:int, status:string, step:int, tokens:int, messages:list<array<string, mixed>>, conversation:list<array{role:string, text:string}>, events:list<RunEvent>, changes:list<Change>, snapshots:array<int, Fields>, error:string}
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
    public static function create(string $message): array|WP_Error
    {
        $id = wp_insert_post(['post_type' => self::TYPE, 'post_status' => 'private', 'post_author' => get_current_user_id(), 'post_title' => mb_substr(wp_strip_all_tags($message), 0, 70)], true);
        if (is_wp_error($id)) {
            return $id;
        }
        $run = ['id' => $id, 'status' => 'queued', 'step' => 0, 'tokens' => 0, 'messages' => [], 'conversation' => [], 'events' => [], 'changes' => [], 'snapshots' => [], 'error' => ''];
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
        return $post instanceof WP_Post && $post->post_type === self::TYPE && (int) $post->post_author === get_current_user_id();
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
        }
        update_post_meta($run['id'], self::META, wp_slash($run));
    }

    /** @param Run $run */
    public static function event(array &$run, string $type, string $label, string $detail = ''): void
    {
        $run['events'][] = ['time' => gmdate('c'), 'type' => $type, 'label' => $label, 'detail' => mb_substr($detail, 0, 2000)];
        $run['events'] = array_slice($run['events'], -100);
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
        $view = array_intersect_key($run, array_flip(['id', 'status', 'step', 'tokens', 'conversation', 'events', 'changes', 'error']));
        if (self::cancelled($run['id'])) {
            $view['status'] = 'cancelled';
        }
        $lock = get_option('oyiso_agent_lock_run_' . $run['id'], '');
        $view['recoverable'] = $view['status'] === 'running' && (!is_string($lock) || (int) $lock <= time() - 180);
        return $view;
    }
}
