<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

if (!class_exists('Oyiso_New_Order_Email_Archive_Manager', false)) {
    final class Oyiso_New_Order_Email_Archive_Manager {
        private const NONCE_ACTION = 'oyiso_order_email_archive_manager';

        private const MAX_RECORDS = 200;

        private const MAX_HTML_BYTES = 52428800;

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
            add_action(
                'wp_ajax_oyiso_retry_order_email_archive',
                [self::class, 'handleRetryRecord']
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
                        'retry'          => '重新发送',
                        'retrying'       => '正在重新发送…',
                        'rerender'       => '重新截图并发送',
                        'rerendering'    => '正在重新截图并发送…',
                        'retryError'     => '重新发送失败，请查看WooCommerce日志。',
                        'retryUnknown'   => '请求中断，截图或发送可能仍在进行，请稍后刷新查看文件和渠道消息。',
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
                                    <button type="button" class="button button-small" id="oyiso-archive-retry" disabled>
                                        <span class="dashicons dashicons-update" aria-hidden="true"></span>
                                        <span>重新发送</span>
                                    </button>
                                    <button type="button" class="button button-small" id="oyiso-archive-rerender" title="根据归档HTML重新生成截图并发送" disabled>
                                        <span class="dashicons dashicons-camera" aria-hidden="true"></span>
                                        <span>重新截图并发送</span>
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
                            <button type="button" class="button" id="oyiso-archive-cleanup">清理过期文件</button>
                            <span id="oyiso-archive-cleanup-status" role="status" aria-live="polite"></span>
                        </div>
                        <div class="oyiso-archive-footer-actions">
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

                $handle = fopen($path, 'rb');

                if (false === $handle) {
                    throw new RuntimeException('无法打开HTML文件。');
                }

                nocache_headers();
                header('Content-Type: text/html; charset=UTF-8');
                header('Content-Length: ' . (string) $size);
                header('Content-Disposition: inline; filename*=UTF-8\'\'' . rawurlencode(basename($path)));
                header('Content-Security-Policy: sandbox');
                header('Referrer-Policy: no-referrer');
                header('X-Content-Type-Options: nosniff');

                fpassthru($handle);
                fclose($handle);
                exit;
            } catch (Throwable $exception) {
                status_header(404);
                nocache_headers();
                header('Content-Type: text/html; charset=UTF-8');
                header('Content-Security-Policy: sandbox');
                header('Referrer-Policy: no-referrer');
                header('X-Content-Type-Options: nosniff');

                echo '<!doctype html><html lang="zh-CN"><meta charset="UTF-8">'
                    . '<title>无法加载HTML预览</title>'
                    . '<body style="margin:0;padding:32px;font:14px/1.6 sans-serif;color:#b91c1c;">'
                    . '无法加载HTML预览。'
                    . '</body></html>';
                exit;
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

        public static function handleRetryRecord(): void {
            self::verifyAjaxRequest();

            if ('POST' !== ($_SERVER['REQUEST_METHOD'] ?? '')) {
                wp_send_json_error(['message' => '请使用POST请求重试。'], 405);
            }

            $mode = $_POST['mode'] ?? 'resend';
            if (!is_string($mode) || !in_array($mode, ['resend', 'rerender'], true)) {
                wp_send_json_error(['message' => '发送操作无效。'], 400);
            }

            try {
                if (!oyiso_is_wc_order_screenshot_forwarding_enabled()) {
                    wp_send_json_error(['message' => '请先启用订单截图转发，并保存至少一个有效的发送渠道。'], 400);
                }

                $value = $_POST['file'] ?? '';
                $filename = is_string($value) ? $value : '';
                if ('rerender' === $mode && 'html' !== strtolower((string) pathinfo($filename, PATHINFO_EXTENSION))) {
                    wp_send_json_error(['message' => '重新截图需要归档HTML文件，请选择包含HTML的记录。'], 400);
                }
                $path = self::resolveRequestedFile(
                    'rerender' === $mode ? ['html'] : ['html', 'png', 'jpeg', 'jpg'],
                    $filename
                );
                $order = self::findArchiveOrder(basename($path));
                $isHtml = 'html' === strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

                // Rendering and downloading each allow 120 seconds; channels are sent serially.
                ignore_user_abort(true);
                if (function_exists('set_time_limit')) {
                    set_time_limit(900);
                }
                if (PHP_SESSION_ACTIVE === session_status()) {
                    session_write_close();
                }

                // One manual resend at a time per site, including records with only an image.
                $lock = fopen(dirname($path) . '/.resend.lock', 'c');
                if (false === $lock) {
                    throw new RuntimeException('无法锁定发送任务，请检查归档目录写入权限。');
                }
                if (!flock($lock, LOCK_EX | LOCK_NB)) {
                    fclose($lock);
                    wp_send_json_error(['message' => '当前站点正在重新发送截图，请稍后再试。'], 409);
                }

                try {
                    if ($isHtml) {
                        $imagePath = Oyiso_New_Order_Email_Image_Renderer::handle($path, $order->get_id(), true);
                    } else {
                        $imagePath = $path;
                        Oyiso_WeCom_Order_Image_Forwarder::forward($imagePath, '', $order->get_id(), true);
                    }
                } finally {
                    fclose($lock);
                }

                if (is_wp_error($imagePath)) {
                    wp_send_json_error([
                        'message' => $imagePath->get_error_message(),
                    ], 'render_busy' === $imagePath->get_error_code() ? 409 : 502);
                }

                $result = class_exists('Oyiso_WeCom_Order_Image_Forwarder', false)
                    ? Oyiso_WeCom_Order_Image_Forwarder::getLastResult()
                    : null;
                $status = 'success';
                $message = $isHtml ? '截图已重新生成。' : '已使用归档截图。';

                if (null === $result || 0 === $result['sent'] + $result['skipped'] + $result['failed']) {
                    $status = 'warning';
                    $message .= '没有已启用的发送渠道，请检查并保存转发配置。';
                } else {
                    $message .= sprintf(
                        '发送成功 %d 个渠道，失败 %d 个。',
                        $result['sent'],
                        $result['failed']
                    );
                    if ([] !== $result['errors']) {
                        $status = 'warning';
                        $message .= ' ' . implode(' ', $result['errors']);
                    }
                }

                wp_send_json_success(['message' => $message, 'status' => $status]);
            } catch (Throwable $exception) {
                wp_send_json_error(['message' => '重新发送失败：' . $exception->getMessage()], 500);
            }
        }

        private static function findArchiveOrder(string $filename): WC_Order {
            if (1 !== preg_match(self::getFilenamePattern(), $filename, $matches)) {
                throw new RuntimeException('订单归档文件名无效。');
            }

            $matchesOrder = static function (WC_Order $order) use ($matches): bool {
                $number = strtolower(trim(ltrim((string) $order->get_order_number(), '#')));
                $number = trim((string) preg_replace('/[^a-z0-9._-]+/i', '-', $number), '.-_');
                $number = '' !== $number ? $number : (string) $order->get_id();

                return $number === strtolower($matches[1])
                    && Oyiso_New_Order_Email_Html_Archive::getOrderCreatedTimestamp($order) === $matches[2];
            };

            // An order number may be customized and must not be treated as an order ID.
            $order = ctype_digit($matches[1]) ? wc_get_order((int) $matches[1]) : false;
            if ($order instanceof WC_Order && $matchesOrder($order)) {
                return $order;
            }

            $created = DateTimeImmutable::createFromFormat('!Ymd-His', $matches[2], wp_timezone());
            if (false !== $created && $created->format('Ymd-His') === $matches[2]) {
                $orders = wc_get_orders([
                    'type'         => 'shop_order',
                    'date_created' => (string) $created->getTimestamp(),
                    'limit'        => -1,
                ]);
                $matching = array_values(array_filter($orders, $matchesOrder));
                if (1 === count($matching)) {
                    return $matching[0];
                }
            }

            throw new RuntimeException('无法唯一匹配归档对应的订单，订单可能已删除或编号已更改。');
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
        private static function resolveRequestedFile(array $allowedExtensions, ?string $requestedFile = null): string {
            $value = $requestedFile ?? ($_REQUEST['file'] ?? '');
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
