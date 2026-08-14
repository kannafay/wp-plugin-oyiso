<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

if (!class_exists('Oyiso_New_Order_Email_Image_Renderer', false)) {
    final class Oyiso_New_Order_Email_Image_Renderer {
        private const API_URL = 'https://render.neogopay.com/api/convert/file?responseMode=json';

        private const API_ORIGIN = 'https://render.neogopay.com';

        private const ASYNC_HOOK = 'oyiso_render_new_order_email_image';

        private const ASYNC_GROUP = 'oyiso-order-email-render';

        private const API_KEY_OPTION = 'woo_new_order_email_render_api_key';

        private const FORMAT_OPTION = 'woo_new_order_email_image_format';

        private const LOG_SOURCE = 'oyiso-order-email-render';

        private const CHECK_NONCE_ACTION = 'oyiso_check_render_service';

        private const MAX_ATTEMPTS = 3;

        private const MAX_IMAGE_BYTES = 52428800;

        public static function register(): void {
            add_action('admin_enqueue_scripts', [self::class, 'enqueueAdminAssets']);
            add_action(
                'wp_ajax_oyiso_check_render_service',
                [self::class, 'handleServiceAvailabilityCheck']
            );
            add_action(
                'wp_ajax_oyiso_check_render_api_key',
                [self::class, 'handleApiKeyCheck']
            );

            if (!class_exists('WooCommerce')) {
                return;
            }

            add_action(
                'oyiso_new_order_email_html_archived',
                [self::class, 'queue'],
                10,
                2
            );
            add_action(self::ASYNC_HOOK, [self::class, 'handle'], 10, 3);
        }

        public static function enqueueAdminAssets(string $hook): void {
            if (!oyiso_is_settings_page_hook($hook)) {
                return;
            }

            $stylePath  = __DIR__ . '/assets/render-service-check.css';
            $scriptPath = __DIR__ . '/assets/render-service-check.js';

            wp_enqueue_style(
                'oyiso-render-service-check',
                plugins_url('assets/render-service-check.css', __FILE__),
                [],
                is_file($stylePath) ? (string) filemtime($stylePath) : null
            );
            wp_enqueue_script(
                'oyiso-render-service-check',
                plugins_url('assets/render-service-check.js', __FILE__),
                ['jquery'],
                is_file($scriptPath) ? (string) filemtime($scriptPath) : null,
                true
            );
            wp_localize_script(
                'oyiso-render-service-check',
                'oyisoRenderServiceCheck',
                [
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'nonce'   => wp_create_nonce(self::CHECK_NONCE_ACTION),
                    'labels'  => [
                        'checking'   => '检测中…',
                        'error'      => '检测失败，请稍后重试。',
                        'keyMissing' => '请先填写渲染服务 Key。',
                    ],
                ]
            );
        }

        public static function handleServiceAvailabilityCheck(): void {
            self::verifyAdminCheckRequest();

            try {
                $result = self::requestAuthenticationProbe(null);

                if (401 !== $result['status']) {
                    throw new RuntimeException(
                        sprintf('服务认证响应异常（HTTP %d）。', $result['status'])
                    );
                }

                wp_send_json_success([
                    'message' => sprintf('渲染服务可用，响应时间 %d ms。', $result['duration_ms']),
                ]);
            } catch (Throwable $exception) {
                wp_send_json_error([
                    'message' => '渲染服务不可用：' . $exception->getMessage(),
                ], 502);
            }
        }

        public static function handleApiKeyCheck(): void {
            self::verifyAdminCheckRequest();

            $value  = $_POST['apiKey'] ?? '';
            $apiKey = is_string($value) ? trim(wp_unslash($value)) : '';

            if ('' === $apiKey) {
                wp_send_json_error(['message' => '请先填写渲染服务 Key。'], 400);
            }

            if (strlen($apiKey) > 512 || preg_match('/[\r\n]/', $apiKey)) {
                wp_send_json_error(['message' => '渲染服务 Key 格式无效。'], 400);
            }

            try {
                $result = self::requestAuthenticationProbe($apiKey);

                if (401 === $result['status']) {
                    wp_send_json_error(['message' => '渲染服务 Key 不正确。'], 400);
                }

                if (400 !== $result['status']) {
                    throw new RuntimeException(
                        sprintf('服务认证响应异常（HTTP %d）。', $result['status'])
                    );
                }

                wp_send_json_success([
                    'message' => sprintf('密钥正确，认证已通过，响应时间 %d ms。', $result['duration_ms']),
                ]);
            } catch (Throwable $exception) {
                wp_send_json_error([
                    'message' => '密钥检测失败：' . $exception->getMessage(),
                ], 502);
            }
        }

        public static function queue(string $htmlPath, WC_Order $order): void {
            if (!self::isEnabled()) {
                return;
            }

            if ('' === self::getApiKey()) {
                self::logError(
                    sprintf(
                        '未配置渲染API Key，订单 %d 的图片任务未创建。',
                        $order->get_id()
                    )
                );
                return;
            }

            $args = [$htmlPath, $order->get_id(), 1];

            if (function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action(
                    self::ASYNC_HOOK,
                    $args,
                    self::ASYNC_GROUP,
                    true
                );
                return;
            }

            if (!wp_next_scheduled(self::ASYNC_HOOK, $args)) {
                wp_schedule_single_event(time() + 1, self::ASYNC_HOOK, $args);
            }
        }

        public static function handle(string $htmlPath, int $orderId, int $attempt = 1): void {
            if (!self::isEnabled()) {
                return;
            }

            try {
                $apiKey = self::getApiKey();

                if ('' === $apiKey) {
                    throw new RuntimeException('未配置渲染API Key。');
                }

                $htmlPath = self::validateHtmlPath($htmlPath);
                $format   = self::getFormat();
                $result   = self::requestRender($htmlPath, $format, $apiKey);
                $imageUrl = self::buildImageUrl($result['url'], $result['filename']);
                $imagePath = trailingslashit(dirname($htmlPath)) . $result['filename'];

                self::downloadImage($imageUrl, $imagePath, $format, $apiKey);

                self::logInfo(
                    sprintf(
                        '订单 %d 的邮件图片已保存：%s',
                        $orderId,
                        $imagePath
                    )
                );

                /**
                 * Fires after a new-order email image has been rendered and saved.
                 *
                 * @param string $imagePath Absolute image file path.
                 * @param string $htmlPath  Absolute source HTML file path.
                 * @param int    $orderId   WooCommerce order ID.
                 */
                do_action(
                    'oyiso_new_order_email_image_rendered',
                    $imagePath,
                    $htmlPath,
                    $orderId
                );
            } catch (Throwable $exception) {
                self::logError(
                    sprintf(
                        '订单 %d 的邮件图片渲染失败（第 %d 次）：%s',
                        $orderId,
                        $attempt,
                        $exception->getMessage()
                    )
                );

                if ($attempt < self::MAX_ATTEMPTS) {
                    self::scheduleRetry($htmlPath, $orderId, $attempt + 1);
                }
            }
        }

        private static function isEnabled(): bool {
            return oyiso_is_wc_order_screenshot_forwarding_enabled();
        }

        private static function getApiKey(): string {
            $options = self::getOptions();
            $apiKey  = $options[self::API_KEY_OPTION] ?? '';

            return is_string($apiKey) ? trim($apiKey) : '';
        }

        private static function getFormat(): string {
            $options = self::getOptions();
            $format  = $options[self::FORMAT_OPTION] ?? 'png';

            return is_string($format) && in_array($format, ['png', 'jpeg'], true)
                ? $format
                : 'png';
        }

        /**
         * @return array<string, mixed>
         */
        private static function getOptions(): array {
            $options = get_option('oyiso', []);

            return is_array($options) ? $options : [];
        }

        private static function verifyAdminCheckRequest(): void {
            check_ajax_referer(self::CHECK_NONCE_ACTION, 'nonce');

            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => '无权限执行该操作。'], 403);
            }
        }

        /**
         * Probe the real API route without submitting an HTML file.
         *
         * A missing key must return 401. An accepted key reaches request validation
         * and returns 400 because the probe intentionally contains no files.
         *
         * @return array{status: int, duration_ms: int}
         */
        private static function requestAuthenticationProbe(?string $apiKey): array {
            $boundary = '----OyisoProbe' . wp_generate_password(16, false, false);
            $headers  = [
                'Accept'       => 'application/json',
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            ];

            if (is_string($apiKey) && '' !== $apiKey) {
                $headers['Authorization'] = 'Bearer ' . $apiKey;
            }

            $started  = microtime(true);
            $response = wp_remote_post(self::API_URL, [
                'timeout'             => 15,
                'redirection'         => 0,
                'headers'             => $headers,
                'body'                => '--' . $boundary . "--\r\n",
                'data_format'         => 'body',
                'limit_response_size' => 4096,
            ]);
            $duration = max(0, (int) round((microtime(true) - $started) * 1000));

            if (is_wp_error($response)) {
                throw new RuntimeException($response->get_error_message());
            }

            return [
                'status'      => (int) wp_remote_retrieve_response_code($response),
                'duration_ms' => $duration,
            ];
        }

        private static function validateHtmlPath(string $htmlPath): string {
            $realPath       = realpath($htmlPath);
            $storagePath    = realpath(Oyiso_New_Order_Email_Html_Archive::getStorageDirectory());
            $normalizedPath = false !== $realPath
                ? wp_normalize_path($realPath)
                : '';
            $normalizedStorage = false !== $storagePath
                ? trailingslashit(wp_normalize_path($storagePath))
                : '';

            if (
                '' === $normalizedPath
                || '' === $normalizedStorage
                || !str_starts_with($normalizedPath, $normalizedStorage)
                || 'html' !== strtolower((string) pathinfo($normalizedPath, PATHINFO_EXTENSION))
                || !is_readable($normalizedPath)
            ) {
                throw new RuntimeException('HTML归档文件不存在、不可读或不属于当前站点目录。');
            }

            return $normalizedPath;
        }

        /**
         * @return array{filename: string, url: string}
         */
        private static function requestRender(
            string $htmlPath,
            string $format,
            string $apiKey
        ): array {
            $boundary = '----OyisoBoundary' . wp_generate_password(24, false, false);
            $response = wp_remote_post(self::API_URL, [
                'timeout'     => 120,
                'redirection' => 0,
                'headers'     => [
                    'Accept'        => 'application/json',
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
                ],
                'body'        => self::buildMultipartBody($htmlPath, $format, $boundary),
                'data_format' => 'body',
            ]);

            if (is_wp_error($response)) {
                throw new RuntimeException('调用渲染API失败：' . $response->get_error_message());
            }

            $statusCode = wp_remote_retrieve_response_code($response);
            $body       = wp_remote_retrieve_body($response);

            if ($statusCode < 200 || $statusCode >= 300) {
                throw new RuntimeException(
                    sprintf('渲染API返回HTTP %d：%s', $statusCode, self::summarizeResponse($body))
                );
            }

            $payload = json_decode($body, true);

            if (!is_array($payload)) {
                throw new RuntimeException('渲染API返回了无效JSON。');
            }

            return self::extractResult($payload, basename($htmlPath), $format);
        }

        private static function buildMultipartBody(
            string $htmlPath,
            string $format,
            string $boundary
        ): string {
            $html = file_get_contents($htmlPath);

            if (false === $html) {
                throw new RuntimeException('无法读取HTML归档文件。');
            }

            $eol      = "\r\n";
            $filename = str_replace(['\\', '"'], '', basename($htmlPath));
            $parts    = [];
            $parts[]  = '--' . $boundary;
            $parts[]  = 'Content-Disposition: form-data; name="files"; filename="' . $filename . '"';
            $parts[]  = 'Content-Type: text/html; charset=UTF-8';
            $parts[]  = '';
            $parts[]  = $html;

            foreach (
                [
                    'format'   => $format,
                    'viewport' => '{"width":720,"height":800}',
                    'fullPage' => 'true',
                ] as $name => $value
            ) {
                $parts[] = '--' . $boundary;
                $parts[] = 'Content-Disposition: form-data; name="' . $name . '"';
                $parts[] = '';
                $parts[] = $value;
            }

            $parts[] = '--' . $boundary . '--';
            $parts[] = '';

            return implode($eol, $parts);
        }

        /**
         * @param array<string, mixed> $payload
         * @return array{filename: string, url: string}
         */
        private static function extractResult(
            array $payload,
            string $sourceFilename,
            string $format
        ): array {
            if ('done' !== ($payload['type'] ?? null)) {
                $message = is_string($payload['message'] ?? null)
                    ? $payload['message']
                    : '未知错误';

                throw new RuntimeException('渲染API未完成任务：' . $message);
            }

            $results = $payload['data']['results'] ?? null;

            if (!is_array($results)) {
                throw new RuntimeException('渲染API响应中缺少图片结果。');
            }

            foreach ($results as $result) {
                if (!is_array($result) || $sourceFilename !== ($result['sourceFilename'] ?? null)) {
                    continue;
                }

                $filename = is_string($result['filename'] ?? null)
                    ? basename($result['filename'])
                    : '';
                $url = is_string($result['url'] ?? null)
                    ? $result['url']
                    : '';

                self::validateResultFilename($filename, $sourceFilename, $format);

                if ('' === $url) {
                    throw new RuntimeException('渲染API响应中缺少图片下载地址。');
                }

                return [
                    'filename' => $filename,
                    'url'      => $url,
                ];
            }

            throw new RuntimeException('渲染API未返回当前HTML文件对应的图片。');
        }

        private static function validateResultFilename(
            string $filename,
            string $sourceFilename,
            string $format
        ): void {
            $sourceStem = (string) pathinfo($sourceFilename, PATHINFO_FILENAME);
            $imageStem  = (string) pathinfo($filename, PATHINFO_FILENAME);
            $extension  = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
            $extensions = 'jpeg' === $format ? ['jpeg', 'jpg'] : [$format];

            if (
                '' === $filename
                || $sourceStem !== $imageStem
                || !in_array($extension, $extensions, true)
            ) {
                throw new RuntimeException('渲染API返回的图片文件名或格式不匹配。');
            }
        }

        private static function buildImageUrl(string $relativeUrl, string $filename): string {
            if (
                !str_starts_with($relativeUrl, '/api/image/')
                || str_starts_with($relativeUrl, '//')
                || null !== wp_parse_url($relativeUrl, PHP_URL_HOST)
            ) {
                throw new RuntimeException('渲染API返回了不受信任的图片地址。');
            }

            $path = wp_parse_url($relativeUrl, PHP_URL_PATH);

            if (
                !is_string($path)
                || basename(rawurldecode($path)) !== $filename
            ) {
                throw new RuntimeException('图片下载地址与返回文件名不匹配。');
            }

            return self::API_ORIGIN . $relativeUrl;
        }

        private static function downloadImage(
            string $imageUrl,
            string $imagePath,
            string $format,
            string $apiKey
        ): void {
            $temporaryPath = $imagePath . '.tmp-' . wp_generate_password(12, false, false);

            try {
                $response = wp_remote_get($imageUrl, [
                    'timeout'             => 120,
                    'redirection'         => 0,
                    'headers'             => [
                        'Accept'        => 'image/' . $format,
                        'Authorization' => 'Bearer ' . $apiKey,
                    ],
                    'stream'              => true,
                    'filename'            => $temporaryPath,
                    'limit_response_size' => self::MAX_IMAGE_BYTES,
                ]);

                if (is_wp_error($response)) {
                    throw new RuntimeException('下载渲染图片失败：' . $response->get_error_message());
                }

                $statusCode = wp_remote_retrieve_response_code($response);

                if ($statusCode < 200 || $statusCode >= 300) {
                    throw new RuntimeException(
                        sprintf('图片下载接口返回HTTP %d。', $statusCode)
                    );
                }

                self::validateDownloadedImage($temporaryPath, $format);
                self::replaceFile($temporaryPath, $imagePath);
            } finally {
                if (is_file($temporaryPath)) {
                    wp_delete_file($temporaryPath);
                }
            }
        }

        private static function validateDownloadedImage(string $path, string $format): void {
            $size = is_file($path) ? filesize($path) : false;

            if (false === $size || 0 === $size || $size >= self::MAX_IMAGE_BYTES) {
                throw new RuntimeException('下载的图片为空或超过50MB限制。');
            }

            $handle = fopen($path, 'rb');

            if (false === $handle) {
                throw new RuntimeException('无法检查下载的图片文件。');
            }

            try {
                $header = fread($handle, 12);
            } finally {
                fclose($handle);
            }

            $isValid = match ($format) {
                'png'   => str_starts_with($header, "\x89PNG\r\n\x1a\n"),
                'jpeg'  => str_starts_with($header, "\xff\xd8\xff"),
                default => false,
            };

            if (!$isValid) {
                throw new RuntimeException('下载内容不是所选格式的有效图片。');
            }
        }

        private static function replaceFile(string $temporaryPath, string $imagePath): void {
            $backupPath = $imagePath . '.previous';
            $hasBackup  = false;

            if (is_file($imagePath)) {
                if (is_file($backupPath)) {
                    wp_delete_file($backupPath);
                }

                if (!rename($imagePath, $backupPath)) {
                    throw new RuntimeException('无法替换已有图片文件。');
                }

                $hasBackup = true;
            }

            if (!rename($temporaryPath, $imagePath)) {
                if ($hasBackup) {
                    rename($backupPath, $imagePath);
                }

                throw new RuntimeException('无法将图片保存到HTML归档目录。');
            }

            if ($hasBackup && is_file($backupPath)) {
                wp_delete_file($backupPath);
            }
        }

        private static function scheduleRetry(string $htmlPath, int $orderId, int $attempt): void {
            $delays = [2 => 60, 3 => 300];
            $delay  = $delays[$attempt] ?? 300;
            $args   = [$htmlPath, $orderId, $attempt];

            if (function_exists('as_schedule_single_action')) {
                as_schedule_single_action(
                    time() + $delay,
                    self::ASYNC_HOOK,
                    $args,
                    self::ASYNC_GROUP,
                    true
                );
                return;
            }

            if (!wp_next_scheduled(self::ASYNC_HOOK, $args)) {
                wp_schedule_single_event(time() + $delay, self::ASYNC_HOOK, $args);
            }
        }

        private static function summarizeResponse(string $body): string {
            $body = trim(wp_strip_all_tags($body));

            if ('' === $body) {
                return '空响应';
            }

            return function_exists('mb_substr')
                ? mb_substr($body, 0, 200)
                : substr($body, 0, 200);
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

            error_log('[Oyiso Order Email Render] ' . $message);
        }
    }
}

add_action('plugins_loaded', [Oyiso_New_Order_Email_Image_Renderer::class, 'register'], 20);
