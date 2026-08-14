<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

if (!class_exists('Oyiso_WeCom_Order_Image_Forwarder', false)) {
    final class Oyiso_WeCom_Order_Image_Forwarder {
        private const WEBHOOK_URL = 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=';

        private const ENABLED_OPTION = 'wecom_order_image_forward';

        private const KEY_OPTION = 'wecom_webhook_key';

        private const OPTION_GROUP = 'woo_new_order_email_forward_options';

        private const LOG_SOURCE = 'oyiso-wecom';

        private const MAX_IMAGE_BYTES = 2097152;

        private const SENT_META_KEY = '_oyiso_wecom_order_image_sent';

        public static function register(): void {
            if (!class_exists('WooCommerce')) {
                return;
            }

            add_action(
                'oyiso_new_order_email_image_rendered',
                [self::class, 'forward'],
                10,
                3
            );
        }

        public static function forward(string $imagePath, string $htmlPath, int $orderId): void {
            unset($htmlPath);

            if (!self::isEnabled()) {
                return;
            }

            try {
                $key = self::getWebhookKey();

                if ('' === $key) {
                    throw new RuntimeException('未配置有效的企业微信 Webhook Key。');
                }

                $imagePath = self::validateImagePath($imagePath);
                $order     = wc_get_order($orderId);
                $fileHash  = md5_file($imagePath);

                if (false === $fileHash) {
                    throw new RuntimeException('无法计算订单截图校验值。');
                }

                if (
                    $order instanceof WC_Order
                    && hash_equals((string) $order->get_meta(self::SENT_META_KEY), $fileHash)
                ) {
                    return;
                }

                self::sendImage($imagePath, $key);

                if ($order instanceof WC_Order) {
                    $order->update_meta_data(self::SENT_META_KEY, $fileHash);
                    $order->save();
                }

                self::logInfo(
                    sprintf('订单 %d 的邮件截图已发送到企业微信。', $orderId)
                );
            } catch (Throwable $exception) {
                self::logError(
                    sprintf(
                        '订单 %d 的邮件截图发送失败：%s',
                        $orderId,
                        $exception->getMessage()
                    )
                );
            }
        }

        private static function isEnabled(): bool {
            $options = self::getOptions();

            return !empty($options[self::ENABLED_OPTION]);
        }

        private static function getWebhookKey(): string {
            $options = self::getOptions();
            $key     = $options[self::KEY_OPTION] ?? '';

            if (!is_string($key)) {
                return '';
            }

            $key = trim($key);

            return strlen($key) <= 128 && preg_match('/^[A-Za-z0-9_-]+$/', $key)
                ? $key
                : '';
        }

        /**
         * @return array<string, mixed>
         */
        private static function getOptions(): array {
            $options = get_option('oyiso', []);

            if (!is_array($options)) {
                return [];
            }

            $group = $options[self::OPTION_GROUP] ?? [];

            return is_array($group) ? $group : [];
        }

        private static function validateImagePath(string $imagePath): string {
            $realPath    = realpath($imagePath);
            $storagePath = realpath(Oyiso_New_Order_Email_Html_Archive::getStorageDirectory());
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
                || !is_readable($normalizedPath)
            ) {
                throw new RuntimeException('订单截图不存在、不可读或不属于当前站点目录。');
            }

            $mime = wp_get_image_mime($normalizedPath);

            if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
                throw new RuntimeException('企业微信图片消息仅支持PNG或JPEG，请修改渲染图片格式。');
            }

            $size = filesize($normalizedPath);

            if (false === $size || $size <= 0 || $size > self::MAX_IMAGE_BYTES) {
                throw new RuntimeException('订单截图为空或超过企业微信2MB限制。');
            }

            return $normalizedPath;
        }

        private static function sendImage(string $imagePath, string $key): void {
            $contents = file_get_contents($imagePath);

            if (false === $contents || '' === $contents) {
                throw new RuntimeException('无法读取待发送的订单截图。');
            }

            $payload = [
                'msgtype' => 'image',
                'image'   => [
                    'base64' => base64_encode($contents),
                    'md5'    => md5($contents),
                ],
            ];
            $response = wp_remote_post(self::WEBHOOK_URL . rawurlencode($key), [
                'timeout'     => 30,
                'redirection' => 0,
                'headers'     => [
                    'Accept'       => 'application/json',
                    'Content-Type' => 'application/json; charset=UTF-8',
                ],
                'body'        => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
                'data_format' => 'body',
            ]);

            if (is_wp_error($response)) {
                throw new RuntimeException('调用企业微信接口失败：' . $response->get_error_message());
            }

            $statusCode = wp_remote_retrieve_response_code($response);
            $body       = wp_remote_retrieve_body($response);

            if ($statusCode < 200 || $statusCode >= 300) {
                throw new RuntimeException(
                    sprintf('企业微信接口返回HTTP %d。', $statusCode)
                );
            }

            $result = json_decode($body, true);

            if (!is_array($result)) {
                throw new RuntimeException('企业微信接口返回了无效JSON。');
            }

            $errorCode = isset($result['errcode']) ? (int) $result['errcode'] : -1;

            if (0 !== $errorCode) {
                $errorMessage = isset($result['errmsg'])
                    ? sanitize_text_field((string) $result['errmsg'])
                    : '未知错误';

                throw new RuntimeException(
                    sprintf('企业微信接口错误 %d：%s', $errorCode, $errorMessage)
                );
            }
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

            error_log('[Oyiso WeCom] ' . $message);
        }
    }
}

add_action('plugins_loaded', [Oyiso_WeCom_Order_Image_Forwarder::class, 'register'], 20);
