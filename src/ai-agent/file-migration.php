<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

final class Oyiso_Agent_File_Migration
{
    public const HOOK = 'oyiso_agent_migrate_files';

    private static function pending(): string
    {
        global $wpdb;
        return $wpdb->prepare("(p.post_content <> '' OR (EXISTS (SELECT 1 FROM {$wpdb->postmeta} a WHERE a.post_id = p.ID AND a.meta_key = '_oyiso_agent_file_path' AND a.meta_value <> '') AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} s WHERE s.post_id = p.ID AND s.meta_key = '_oyiso_agent_file_storage' AND s.meta_value = %s)))", Oyiso_Agent_Private_Files::VERSION);
    }

    /** @return array{remaining:int,failed:int} */
    public static function status(): array
    {
        global $wpdb;
        $pending = self::pending();
        $where = $wpdb->prepare("p.post_type = %s AND {$pending}", Oyiso_Agent_Attachments::TYPE);
        $remaining = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$where}");
        $failed = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$where} AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} e WHERE e.post_id = p.ID AND e.meta_key = '_oyiso_agent_file_migration_error')");
        return ['remaining' => $remaining, 'failed' => $failed];
    }

    public static function schedule(): void
    {
        if (!current_user_can('manage_options') || wp_doing_ajax()) return;
        self::scheduleNext();
    }

    private static function scheduleNext(): void
    {
        $status = self::status();
        if ($status['remaining'] > $status['failed'] && !wp_next_scheduled(self::HOOK)) wp_schedule_single_event(time() + 2, self::HOOK);
    }

    /** @return array{migrated:int,remaining:int,failed:int} */
    public static function batch(bool $retry = false): array
    {
        global $wpdb;
        if ($retry) delete_post_meta_by_key('_oyiso_agent_file_migration_error');
        $pending = self::pending();
        $ids = $wpdb->get_col($wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type = %s AND {$pending} AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = %s) ORDER BY p.ID ASC LIMIT 10", Oyiso_Agent_Attachments::TYPE, '_oyiso_agent_file_migration_error'));
        $migrated = 0;
        foreach ($ids as $rawId) {
            $id = (int) $rawId; $post = get_post($id);
            $result = $post instanceof WP_Post && get_post_status($post->post_parent) === 'trash' ? Oyiso_Agent_Attachments::erase($id) : Oyiso_Agent_Attachments::migrate($id);
            if (is_wp_error($result)) update_post_meta($id, '_oyiso_agent_file_migration_error', $result->get_error_message());
            else { delete_post_meta($id, '_oyiso_agent_file_migration_error'); $migrated++; }
        }
        self::scheduleNext();
        return ['migrated' => $migrated] + self::status();
    }
}
