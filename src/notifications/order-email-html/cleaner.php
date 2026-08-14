<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

if (!class_exists('Oyiso_New_Order_Email_File_Cleaner', false)) {
    final class Oyiso_New_Order_Email_File_Cleaner {
        private const OPTION_KEY = 'woo_new_order_email_file_retention';

        private const LEGACY_CLEANUP_HOOK = 'oyiso_cleanup_order_email_files';

        private const CLEANUP_INTERVAL = 3600;

        private const LAST_CLEANUP_OPTION = 'oyiso_order_email_last_cleanup';

        private const LEGACY_SCHEDULE_REMOVED_OPTION = 'oyiso_order_email_cleanup_schedule_removed';

        private const LOG_SOURCE = 'oyiso-order-email-cleanup';

        /**
         * @var array<int, true>
         */
        private const ALLOWED_RETENTION_HOURS = [
            0   => true,
            24  => true,
            72  => true,
            168 => true,
            720 => true,
        ];

        public static function register(): void {
            if (!class_exists('WooCommerce')) {
                return;
            }

            add_action('init', [self::class, 'removeLegacySchedule'], 20);
            add_action(
                'oyiso_new_order_email_html_archived',
                [self::class, 'maybeCleanupExpiredFiles'],
                20,
                0
            );
            add_action(
                'wp_ajax_oyiso_cleanup_order_email_files_now',
                [self::class, 'handleAjaxCleanup']
            );
            add_action(
                'wp_ajax_oyiso_clear_order_email_files_now',
                [self::class, 'handleAjaxClear']
            );
        }

        public static function maybeCleanupExpiredFiles(): void {
            if (0 === self::getRetentionHours()) {
                return;
            }

            $now         = time();
            $lastCleanup = (int) get_option(self::LAST_CLEANUP_OPTION, 0);

            if ($lastCleanup > $now - self::CLEANUP_INTERVAL) {
                return;
            }

            update_option(self::LAST_CLEANUP_OPTION, $now, false);
            self::cleanupExpiredFiles();
        }

        public static function cleanupExpiredFiles(): void {
            try {
                $result = self::cleanupNow();

                if ($result['deleted'] > 0) {
                    self::logInfo(
                        sprintf('已清理 %d 个过期订单邮件归档文件。', $result['deleted'])
                    );
                }
            } catch (Throwable $exception) {
                self::logError('清理过期订单邮件归档失败：' . $exception->getMessage());
            }
        }

        /**
         * @return array{deleted: int, retention_hours: int}
         */
        public static function cleanupNow(): array {
            $retentionHours = self::getRetentionHours();

            if (0 === $retentionHours) {
                return [
                    'deleted'         => 0,
                    'retention_hours' => 0,
                ];
            }

            return [
                'deleted' => self::cleanupDirectory(
                    Oyiso_New_Order_Email_Html_Archive::getStorageDirectory(),
                    time() - ($retentionHours * HOUR_IN_SECONDS)
                ),
                'retention_hours' => $retentionHours,
            ];
        }

        public static function handleAjaxCleanup(): void {
            check_ajax_referer('oyiso_order_email_cleanup_now', 'nonce');

            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => '无权限执行清理操作。'], 403);
            }

            try {
                $result = self::cleanupNow();

                if (0 === $result['retention_hours']) {
                    wp_send_json_success([
                        'message' => '当前设置为永久保留，没有执行清理。',
                        'deleted' => 0,
                    ]);
                }

                self::logInfo(
                    sprintf('手动清理完成，共删除 %d 个过期订单邮件归档文件。', $result['deleted'])
                );

                wp_send_json_success([
                    'message' => sprintf('清理完成，共删除 %d 个过期文件。', $result['deleted']),
                    'deleted' => $result['deleted'],
                ]);
            } catch (Throwable $exception) {
                self::logError('手动清理订单邮件归档失败：' . $exception->getMessage());
                wp_send_json_error(['message' => '清理失败，请查看WooCommerce日志。'], 500);
            }
        }

        public static function handleAjaxClear(): void {
            check_ajax_referer('oyiso_order_email_clear_all', 'nonce');

            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => '无权限执行清空操作。'], 403);
            }

            try {
                $deleted = self::cleanupDirectory(
                    Oyiso_New_Order_Email_Html_Archive::getStorageDirectory(),
                    PHP_INT_MAX
                );

                self::logInfo(
                    sprintf('手动清空完成，共删除 %d 个订单邮件归档文件。', $deleted)
                );

                wp_send_json_success([
                    'message' => sprintf('已清空，共删除 %d 个文件。', $deleted),
                    'deleted' => $deleted,
                ]);
            } catch (Throwable $exception) {
                self::logError('手动清空订单邮件归档失败：' . $exception->getMessage());
                wp_send_json_error(['message' => '清空失败，请查看WooCommerce日志。'], 500);
            }
        }

        public static function cleanupDirectory(
            string $directory,
            int $cutoffTimestamp
        ): int {
            $realDirectory = realpath($directory);

            if (false === $realDirectory || !is_dir($realDirectory)) {
                return 0;
            }

            $pattern = '/^[a-z0-9][a-z0-9._-]*_#'
                . '[a-z0-9._-]+_\d{8}-\d{6}-[a-z0-9]{6}\.(html|png|jpe?g)$/i';
            $groups = [];

            foreach (new DirectoryIterator($realDirectory) as $file) {
                if ($file->isDot() || $file->isLink() || !$file->isFile()) {
                    continue;
                }

                $filename = $file->getFilename();

                if (1 !== preg_match($pattern, $filename, $matches)) {
                    continue;
                }

                $stem      = (string) pathinfo($filename, PATHINFO_FILENAME);
                $extension = strtolower($matches[1]);
                $modified  = $file->getMTime();

                if (!isset($groups[$stem])) {
                    $groups[$stem] = [
                        'files'        => [],
                        'html_mtime'   => null,
                        'latest_mtime' => 0,
                    ];
                }

                $groups[$stem]['files'][] = $file->getPathname();
                $groups[$stem]['latest_mtime'] = max(
                    $groups[$stem]['latest_mtime'],
                    $modified
                );

                if ('html' === $extension) {
                    $groups[$stem]['html_mtime'] = $modified;
                }
            }

            $normalizedDirectory = trailingslashit(wp_normalize_path($realDirectory));
            $deletedCount = 0;

            foreach ($groups as $group) {
                $referenceTime = is_int($group['html_mtime'])
                    ? $group['html_mtime']
                    : $group['latest_mtime'];

                if ($referenceTime > $cutoffTimestamp) {
                    continue;
                }

                foreach ($group['files'] as $path) {
                    $realPath = realpath($path);

                    if (
                        false === $realPath
                        || !str_starts_with(
                            wp_normalize_path($realPath),
                            $normalizedDirectory
                        )
                    ) {
                        continue;
                    }

                    wp_delete_file($realPath);
                    clearstatcache(true, $realPath);

                    if (is_file($realPath)) {
                        throw new RuntimeException(
                            '无法删除文件：' . basename($realPath)
                        );
                    }

                    ++$deletedCount;
                }
            }

            return $deletedCount;
        }

        public static function getRetentionHours(): int {
            $options = get_option('oyiso', []);
            $value   = is_array($options)
                ? ($options[self::OPTION_KEY] ?? '24')
                : '24';
            $hours   = is_scalar($value) ? (int) $value : 24;

            return isset(self::ALLOWED_RETENTION_HOURS[$hours]) ? $hours : 24;
        }

        public static function removeLegacySchedule(): void {
            if ('1' === get_option(self::LEGACY_SCHEDULE_REMOVED_OPTION, '0')) {
                return;
            }

            if (function_exists('as_unschedule_all_actions')) {
                as_unschedule_all_actions(self::LEGACY_CLEANUP_HOOK);
            }

            wp_clear_scheduled_hook(self::LEGACY_CLEANUP_HOOK);
            update_option(self::LEGACY_SCHEDULE_REMOVED_OPTION, '1', false);
        }

        private static function logInfo(string $message): void {
            if (function_exists('wc_get_logger')) {
                wc_get_logger()->info($message, ['source' => self::LOG_SOURCE]);
            }
        }

        private static function logError(string $message): void {
            if (function_exists('wc_get_logger')) {
                wc_get_logger()->error($message, ['source' => self::LOG_SOURCE]);
                return;
            }

            error_log('[Oyiso Order Email Cleanup] ' . $message);
        }
    }
}

add_action('plugins_loaded', [Oyiso_New_Order_Email_File_Cleaner::class, 'register'], 20);
