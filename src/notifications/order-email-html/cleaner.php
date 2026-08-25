<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

if (!class_exists('Oyiso_New_Order_Email_File_Cleaner', false)) {
    final class Oyiso_New_Order_Email_File_Cleaner {
        private const OPTION_KEY = 'woo_new_order_email_file_retention';

        private const CLEANUP_HOOK = 'oyiso_cleanup_order_email_files';

        private const CLEANUP_INTERVAL = 3600;

        private const FALLBACK_INTERVAL = 7200;

        private const LAST_CLEANUP_OPTION = 'oyiso_order_email_last_cleanup';

        private const LAST_CRON_RUN_OPTION = 'oyiso_order_email_last_cron_run';

        private const LAST_CLEANUP_ERROR_OPTION = 'oyiso_order_email_last_cleanup_error';

        private const CLEANUP_LOCK_OPTION = 'oyiso_order_email_cleanup_lock';

        private const CLEANUP_LOCK_TTL = 300;

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

            add_action('init', [self::class, 'ensureScheduled'], 20);
            add_action(self::CLEANUP_HOOK, [self::class, 'runScheduledCleanup']);
            add_action('shutdown', [self::class, 'maybeCleanupExpiredFiles'], 20);
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

        public static function ensureScheduled(): void {
            if (0 === self::getRetentionHours()) {
                self::unschedule();
                return;
            }

            if (!wp_next_scheduled(self::CLEANUP_HOOK)) {
                $scheduled = wp_schedule_event(
                    time() + self::CLEANUP_INTERVAL,
                    'hourly',
                    self::CLEANUP_HOOK,
                    [],
                    true
                );

                if (is_wp_error($scheduled)) {
                    self::logError('无法注册订单邮件文件清理定时任务：' . $scheduled->get_error_message());
                }
            }
        }

        public static function runScheduledCleanup(): void {
            $now = time();
            update_option(self::LAST_CRON_RUN_OPTION, $now, false);

            if (0 === self::getRetentionHours()) {
                return;
            }

            self::runCleanup($now, 'cron');
        }

        public static function maybeCleanupExpiredFiles(): void {
            if (0 === self::getRetentionHours()) {
                return;
            }

            $now         = time();
            $lastCleanup = (int) get_option(self::LAST_CLEANUP_OPTION, 0);

            if ($lastCleanup > $now - self::FALLBACK_INTERVAL) {
                return;
            }

            $lastError = get_option(self::LAST_CLEANUP_ERROR_OPTION, []);
            $errorTime = is_array($lastError) ? (int) ($lastError['time'] ?? 0) : 0;

            if ($errorTime > $now - self::CLEANUP_INTERVAL) {
                return;
            }

            self::runCleanup($now, 'fallback');
        }

        private static function runCleanup(int $now, string $source): void {
            if (!self::acquireCleanupLock($now)) {
                return;
            }

            try {
                $result = self::cleanupNow();
                update_option(self::LAST_CLEANUP_OPTION, $now, false);
                delete_option(self::LAST_CLEANUP_ERROR_OPTION);

                if ($result['deleted'] > 0) {
                    self::logInfo(
                        sprintf('已清理 %d 个过期订单邮件归档文件。', $result['deleted'])
                    );
                }
            } catch (Throwable $exception) {
                update_option(
                    self::LAST_CLEANUP_ERROR_OPTION,
                    [
                        'time'    => $now,
                        'message' => $exception->getMessage(),
                        'source'  => $source,
                    ],
                    false
                );
                self::logError('清理过期订单邮件归档失败：' . $exception->getMessage());
            } finally {
                self::releaseCleanupLock();
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
                update_option(self::LAST_CLEANUP_OPTION, time(), false);
                delete_option(self::LAST_CLEANUP_ERROR_OPTION);

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

        /**
         * @return array{status: 'healthy'|'warning'|'error', message: string}
         */
        public static function getHealthStatus(): array {
            if (0 === self::getRetentionHours()) {
                return [
                    'status'  => 'healthy',
                    'message' => '',
                ];
            }

            $lastCleanup = (int) get_option(self::LAST_CLEANUP_OPTION, 0);
            $lastError   = get_option(self::LAST_CLEANUP_ERROR_OPTION, []);

            if (is_array($lastError)) {
                $errorTime    = (int) ($lastError['time'] ?? 0);
                $errorMessage = $lastError['message'] ?? '';

                if (
                    $errorTime > 0
                    && $errorTime >= $lastCleanup
                    && is_scalar($errorMessage)
                    && '' !== trim((string) $errorMessage)
                ) {
                    return [
                        'status'  => 'error',
                        'message' => sprintf(
                            '自动清理失败：%s（%s）',
                            trim((string) $errorMessage),
                            wp_date('Y-m-d H:i:s', $errorTime)
                        ),
                    ];
                }
            }

            $now           = time();
            $lastCronRun   = (int) get_option(self::LAST_CRON_RUN_OPTION, 0);
            $nextScheduled = wp_next_scheduled(self::CLEANUP_HOOK);

            if (false === $nextScheduled) {
                return [
                    'status'  => 'warning',
                    'message' => '自动清理任务未注册，站点访问兜底仍会运行。',
                ];
            }

            $scheduledRunMissed = $nextScheduled <= $now - self::CLEANUP_INTERVAL;
            $cronOverdue = $scheduledRunMissed && (
                0 === $lastCronRun
                || $lastCronRun <= $now - self::FALLBACK_INTERVAL
            );

            if ($cronOverdue) {
                if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
                    return [
                        'status'  => 'warning',
                        'message' => '内置 WP-Cron 已禁用，且超过2小时未检测到服务器 Cron 运行；站点访问兜底仍会清理。',
                    ];
                }

                return [
                    'status'  => 'warning',
                    'message' => '自动清理定时任务超过2小时未运行，当前由站点访问兜底清理。',
                ];
            }

            return [
                'status'  => 'healthy',
                'message' => '',
            ];
        }

        public static function unschedule(): void {
            wp_clear_scheduled_hook(self::CLEANUP_HOOK);
        }

        private static function acquireCleanupLock(int $now): bool {
            $lockedAt = (int) get_option(self::CLEANUP_LOCK_OPTION, 0);

            if ($lockedAt > 0 && $lockedAt > $now - self::CLEANUP_LOCK_TTL) {
                return false;
            }

            if ($lockedAt > 0) {
                delete_option(self::CLEANUP_LOCK_OPTION);
            }

            return add_option(
                self::CLEANUP_LOCK_OPTION,
                (string) $now,
                '',
                false
            );
        }

        private static function releaseCleanupLock(): void {
            delete_option(self::CLEANUP_LOCK_OPTION);
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
register_deactivation_hook(
    dirname(__DIR__, 3) . '/oyiso.php',
    [Oyiso_New_Order_Email_File_Cleaner::class, 'unschedule']
);
