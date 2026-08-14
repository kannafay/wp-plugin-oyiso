<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

if (!class_exists('Oyiso_New_Order_Email_Archive_Manager', false)) {
    final class Oyiso_New_Order_Email_Archive_Manager {
        private const NONCE_ACTION = 'oyiso_order_email_archive_manager';

        private const MAX_RECORDS = 200;

        private const MAX_HTML_BYTES = 10485760;

        private static bool $shouldRenderModal = false;

        public static function register(): void {
            if (!class_exists('WooCommerce')) {
                return;
            }

            add_action('admin_enqueue_scripts', [self::class, 'enqueueAdminAssets']);
            add_action('admin_footer', [self::class, 'renderModal']);
            add_action(
                'wp_ajax_oyiso_list_order_email_archives',
                [self::class, 'handleListArchives']
            );
            add_action(
                'wp_ajax_oyiso_get_order_email_archive_html',
                [self::class, 'handleGetHtml']
            );
            add_action(
                'wp_ajax_oyiso_get_order_email_archive_image',
                [self::class, 'handleGetImage']
            );
            add_action(
                'wp_ajax_oyiso_delete_order_email_archive',
                [self::class, 'handleDeleteRecord']
            );
        }

        public static function enqueueAdminAssets(string $hook): void {
            if (!oyiso_is_settings_page_hook($hook)) {
                return;
            }

            self::$shouldRenderModal = true;
            $stylePath = __DIR__ . '/assets/archive-manager.css';
            $scriptPath = __DIR__ . '/assets/archive-manager.js';

            wp_enqueue_style(
                'oyiso-order-email-archive-manager',
                plugins_url('assets/archive-manager.css', __FILE__),
                [],
                is_file($stylePath) ? (string) filemtime($stylePath) : null
            );
            wp_enqueue_script(
                'oyiso-order-email-archive-manager',
                plugins_url('assets/archive-manager.js', __FILE__),
                ['jquery'],
                is_file($scriptPath) ? (string) filemtime($scriptPath) : null,
                true
            );
            wp_localize_script(
                'oyiso-order-email-archive-manager',
                'oyisoOrderEmailArchiveManager',
                [
                    'ajaxUrl'        => admin_url('admin-ajax.php'),
                    'nonce'          => wp_create_nonce(self::NONCE_ACTION),
                    'cleanupNonce'   => wp_create_nonce('oyiso_order_email_cleanup_now'),
                    'clearNonce'     => wp_create_nonce('oyiso_order_email_clear_all'),
                    'siteDomain'     => Oyiso_New_Order_Email_Html_Archive::getSiteDomain(),
                    'savedRetention' => (string) Oyiso_New_Order_Email_File_Cleaner::getRetentionHours(),
                    'labels'         => [
                        'loading'        => '正在加载预览…',
                        'listLoading'    => '正在读取归档文件…',
                        'empty'          => '当前站点还没有邮件归档文件。',
                        'listError'      => '无法读取归档文件。',
                        'previewError'   => '无法加载文件预览。',
                        'unsaved'        => '保留时间尚未保存，请先保存设置。',
                        'confirmCleanup' => '将按当前保留时间删除过期的邮件HTML和截图，是否继续？',
                        'cleaning'       => '正在清理…',
                        'cleanupError'   => '清理失败，请查看WooCommerce日志。',
                        'disabled'       => '永久保留模式下无需清理。',
                        'confirmClear'   => '将永久删除当前站点的全部订单邮件HTML和截图，且无法恢复。是否继续？',
                        'clearing'       => '正在清空…',
                        'clearError'     => '清空失败，请查看WooCommerce日志。',
                        'confirmDelete'  => '确定删除订单 #%s 的邮件HTML和全部截图吗？此操作无法恢复。',
                        'deleting'       => '正在删除订单文件…',
                        'deleteError'    => '删除失败，请查看WooCommerce日志。',
                        'copying'        => '正在复制截图…',
                        'copySuccess'    => '截图已复制到剪贴板。',
                        'copyError'      => '无法复制截图，请使用下载按钮。',
                    ],
                ]
            );
        }

        public static function renderModal(): void {
            if (!self::$shouldRenderModal) {
                return;
            }
            ?>
            <div id="oyiso-order-email-archive-modal" class="oyiso-archive-modal" hidden aria-hidden="true">
                <div class="oyiso-archive-backdrop" data-oyiso-archive-close></div>
                <div class="oyiso-archive-dialog" role="dialog" aria-modal="true" aria-labelledby="oyiso-archive-title" tabindex="-1">
                    <header class="oyiso-archive-header">
                        <div>
                            <h2 id="oyiso-archive-title">订单邮件文件管理</h2>
                            <p><?php echo esc_html(Oyiso_New_Order_Email_Html_Archive::getSiteDomain()); ?></p>
                        </div>
                        <div class="oyiso-archive-header-actions">
                            <button type="button" class="oyiso-archive-header-button" id="oyiso-archive-fullscreen" aria-label="全屏" aria-pressed="false" title="全屏">
                                <span class="dashicons dashicons-editor-expand" aria-hidden="true"></span>
                            </button>
                            <button type="button" class="oyiso-archive-header-button" data-oyiso-archive-close aria-label="关闭文件管理" title="关闭">
                                <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                            </button>
                        </div>
                    </header>
                    <div class="oyiso-archive-body">
                        <aside class="oyiso-archive-sidebar" aria-label="订单邮件归档列表">
                            <div class="oyiso-archive-sidebar-header">
                                <strong>归档文件</strong>
                                <button type="button" class="button button-small" id="oyiso-archive-refresh">刷新</button>
                            </div>
                            <div id="oyiso-archive-list" class="oyiso-archive-list"></div>
                        </aside>
                        <section class="oyiso-archive-preview">
                            <div class="oyiso-archive-preview-toolbar">
                                <div id="oyiso-archive-record-meta" class="oyiso-archive-record-meta">请选择一条归档记录</div>
                                <div class="oyiso-archive-preview-actions" aria-label="截图操作">
                                    <button type="button" class="button button-small" id="oyiso-archive-copy-image" disabled>
                                        <span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
                                        <span>复制</span>
                                    </button>
                                    <button type="button" class="button button-small" id="oyiso-archive-download-image" disabled>
                                        <span class="dashicons dashicons-download" aria-hidden="true"></span>
                                        <span>下载</span>
                                    </button>
                                </div>
                                <div class="oyiso-archive-tabs" role="tablist" aria-label="预览类型">
                                    <button type="button" id="oyiso-archive-image-tab" role="tab" aria-selected="false">截图</button>
                                    <button type="button" id="oyiso-archive-html-tab" role="tab" aria-selected="false">HTML</button>
                                </div>
                            </div>
                            <div id="oyiso-archive-preview-stage" class="oyiso-archive-preview-stage">
                                <div id="oyiso-archive-preview-message" class="oyiso-archive-preview-message">
                                    <span class="oyiso-archive-preview-spinner" aria-hidden="true" hidden></span>
                                    <span id="oyiso-archive-preview-message-text">请选择左侧订单查看文件</span>
                                </div>
                                <div id="oyiso-archive-image-preview" class="oyiso-archive-image-preview" hidden>
                                    <img alt="订单邮件截图" referrerpolicy="no-referrer">
                                </div>
                                <iframe id="oyiso-archive-html-preview" title="订单邮件HTML预览" sandbox="" referrerpolicy="no-referrer" hidden></iframe>
                            </div>
                        </section>
                    </div>
                    <footer class="oyiso-archive-footer">
                        <div class="oyiso-archive-footer-left">
                            <button type="button" class="button oyiso-archive-danger" id="oyiso-archive-clear">清空所有文件</button>
                            <span id="oyiso-archive-cleanup-status" role="status" aria-live="polite"></span>
                        </div>
                        <div class="oyiso-archive-footer-actions">
                            <button type="button" class="button" id="oyiso-archive-cleanup">清理过期文件</button>
                            <button type="button" class="button button-primary" data-oyiso-archive-close>关闭</button>
                        </div>
                    </footer>
                </div>
            </div>
            <?php
        }

        public static function handleListArchives(): void {
            self::verifyAjaxRequest();

            try {
                wp_send_json_success([
                    'records' => self::scanDirectory(
                        Oyiso_New_Order_Email_Html_Archive::getStorageDirectory()
                    ),
                ]);
            } catch (Throwable $exception) {
                wp_send_json_error(['message' => '无法读取归档文件。'], 500);
            }
        }

        public static function handleGetHtml(): void {
            self::verifyAjaxRequest();

            try {
                $path = self::resolveRequestedFile(['html']);
                $size = filesize($path);

                if (false === $size || $size > self::MAX_HTML_BYTES) {
                    throw new RuntimeException('HTML文件过大或无法读取。');
                }

                $html = file_get_contents($path);

                if (false === $html) {
                    throw new RuntimeException('无法读取HTML文件。');
                }

                wp_send_json_success([
                    'filename' => basename($path),
                    'html'     => $html,
                ]);
            } catch (Throwable $exception) {
                wp_send_json_error(['message' => '无法加载HTML预览。'], 404);
            }
        }

        public static function handleGetImage(): void {
            self::verifyAjaxRequest();

            try {
                $path = self::resolveRequestedFile(['png', 'jpeg', 'jpg']);
                $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
                $contentTypes = [
                    'png'  => 'image/png',
                    'jpeg' => 'image/jpeg',
                    'jpg'  => 'image/jpeg',
                ];
                $size = filesize($path);

                if (false === $size) {
                    throw new RuntimeException('无法读取图片文件。');
                }

                $handle = fopen($path, 'rb');

                if (false === $handle) {
                    throw new RuntimeException('无法打开图片文件。');
                }

                nocache_headers();
                header('Content-Type: ' . $contentTypes[$extension]);
                header('Content-Length: ' . (string) $size);
                header('Content-Disposition: inline; filename*=UTF-8\'\'' . rawurlencode(basename($path)));
                header('X-Content-Type-Options: nosniff');

                fpassthru($handle);
                fclose($handle);
                exit;
            } catch (Throwable $exception) {
                wp_send_json_error(['message' => '无法加载图片预览。'], 404);
            }
        }

        public static function handleDeleteRecord(): void {
            self::verifyAjaxRequest();

            try {
                $value = $_POST['record'] ?? '';
                $recordId = is_string($value) ? wp_unslash($value) : '';
                $deleted = self::deleteRecordFiles(
                    Oyiso_New_Order_Email_Html_Archive::getStorageDirectory(),
                    $recordId
                );

                wp_send_json_success([
                    'message' => sprintf('订单文件已删除，共删除 %d 个文件。', $deleted),
                    'deleted' => $deleted,
                ]);
            } catch (Throwable $exception) {
                wp_send_json_error(['message' => '删除订单文件失败。'], 500);
            }
        }

        /**
         * @return array<int, array<string, mixed>>
         */
        public static function scanDirectory(string $directory): array {
            $realDirectory = realpath($directory);

            if (false === $realDirectory || !is_dir($realDirectory)) {
                return [];
            }

            $pattern = self::getFilenamePattern();
            $records = [];

            foreach (new DirectoryIterator($realDirectory) as $file) {
                if ($file->isDot() || $file->isLink() || !$file->isFile()) {
                    continue;
                }

                $filename = $file->getFilename();

                if (1 !== preg_match($pattern, $filename, $matches)) {
                    continue;
                }

                $stem      = (string) pathinfo($filename, PATHINFO_FILENAME);
                $extension = strtolower($matches[3]);

                if (!isset($records[$stem])) {
                    $records[$stem] = [
                        'id'          => $stem,
                        'orderNumber' => $matches[1],
                        'createdAt'   => self::formatArchiveTimestamp($matches[2]),
                        'sortTime'    => $matches[2],
                        'html'        => null,
                        'images'      => [],
                    ];
                }

                $metadata = [
                    'filename' => $filename,
                    'size'     => $file->getSize(),
                    'modified' => $file->getMTime(),
                ];

                if ('html' === $extension) {
                    $records[$stem]['html'] = $metadata;
                    continue;
                }

                $metadata['format'] = 'jpg' === $extension
                    ? 'JPEG'
                    : strtoupper($extension);
                $records[$stem]['images'][] = $metadata;
            }

            $records = array_values($records);

            foreach ($records as &$record) {
                usort(
                    $record['images'],
                    static fn(array $left, array $right): int => $right['modified'] <=> $left['modified']
                );
            }
            unset($record);

            usort(
                $records,
                static fn(array $left, array $right): int => strcmp($right['sortTime'], $left['sortTime'])
            );

            $records = array_slice($records, 0, self::MAX_RECORDS);

            foreach ($records as &$record) {
                unset($record['sortTime']);
            }
            unset($record);

            return $records;
        }

        public static function deleteRecordFiles(
            string $directory,
            string $recordId
        ): int {
            if (1 !== preg_match(self::getRecordIdPattern(), $recordId)) {
                throw new RuntimeException('订单归档记录名无效。');
            }

            $realDirectory = realpath($directory);

            if (false === $realDirectory || !is_dir($realDirectory)) {
                return 0;
            }

            $normalizedDirectory = trailingslashit(wp_normalize_path($realDirectory));
            $deleted = 0;

            foreach (['html', 'png', 'jpeg', 'jpg'] as $extension) {
                $candidate = $realDirectory
                    . DIRECTORY_SEPARATOR
                    . $recordId
                    . '.'
                    . $extension;

                if (!file_exists($candidate) && !is_link($candidate)) {
                    continue;
                }

                if (is_link($candidate)) {
                    throw new RuntimeException('不允许删除链接文件。');
                }

                $path = realpath($candidate);

                if (
                    false === $path
                    || !is_file($path)
                    || !str_starts_with(wp_normalize_path($path), $normalizedDirectory)
                ) {
                    throw new RuntimeException('订单归档文件路径无效。');
                }

                wp_delete_file($path);
                clearstatcache(true, $path);

                if (is_file($path)) {
                    throw new RuntimeException('无法删除订单归档文件。');
                }

                ++$deleted;
            }

            return $deleted;
        }

        private static function verifyAjaxRequest(): void {
            check_ajax_referer(self::NONCE_ACTION, 'nonce');

            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => '无权限查看订单邮件归档。'], 403);
            }
        }

        /**
         * @param array<int, string> $allowedExtensions
         */
        private static function resolveRequestedFile(array $allowedExtensions): string {
            $value = $_REQUEST['file'] ?? '';
            $filename = is_string($value) ? wp_unslash($value) : '';

            if ('' === $filename || basename($filename) !== $filename) {
                throw new RuntimeException('文件名无效。');
            }

            $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));

            if (!in_array($extension, $allowedExtensions, true)) {
                throw new RuntimeException('文件格式不受支持。');
            }

            if (
                1 !== preg_match(
                    self::getFilenamePattern(),
                    $filename
                )
            ) {
                throw new RuntimeException('文件名不属于订单邮件归档。');
            }

            $directory = realpath(Oyiso_New_Order_Email_Html_Archive::getStorageDirectory());

            if (false === $directory) {
                throw new RuntimeException('归档目录不存在。');
            }

            $candidate = $directory . DIRECTORY_SEPARATOR . $filename;

            if (is_link($candidate)) {
                throw new RuntimeException('不允许读取链接文件。');
            }

            $path = realpath($candidate);
            $normalizedDirectory = trailingslashit(wp_normalize_path($directory));

            if (
                false === $path
                || !is_file($path)
                || !is_readable($path)
                || !str_starts_with(wp_normalize_path($path), $normalizedDirectory)
            ) {
                throw new RuntimeException('归档文件不存在或不可读。');
            }

            return $path;
        }

        private static function getFilenamePattern(): string {
            return '/^[a-z0-9][a-z0-9._-]*_#'
                . '([a-z0-9._-]+)_(\d{8}-\d{6})-[a-z0-9]{6}\.(html|png|jpe?g)$/i';
        }

        private static function getRecordIdPattern(): string {
            return '/^[a-z0-9][a-z0-9._-]*_#'
                . '[a-z0-9._-]+_\d{8}-\d{6}-[a-z0-9]{6}$/i';
        }

        private static function formatArchiveTimestamp(string $timestamp): string {
            return sprintf(
                '%s-%s-%s %s:%s:%s',
                substr($timestamp, 0, 4),
                substr($timestamp, 4, 2),
                substr($timestamp, 6, 2),
                substr($timestamp, 9, 2),
                substr($timestamp, 11, 2),
                substr($timestamp, 13, 2)
            );
        }
    }
}

add_action('plugins_loaded', [Oyiso_New_Order_Email_Archive_Manager::class, 'register'], 20);
