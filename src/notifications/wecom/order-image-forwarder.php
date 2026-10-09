<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

require_once __DIR__ . '/order-image-delivery.php';

if (!class_exists('Oyiso_WeCom_Retryable_Exception', false)) {
    final class Oyiso_WeCom_Retryable_Exception extends RuntimeException {}
}

if (!class_exists('Oyiso_WeCom_Order_Image_Forwarder', false)) {
    final class Oyiso_WeCom_Order_Image_Forwarder {
        private const WEBHOOK_URL = 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=';

        private const LOG_SOURCE = 'oyiso-wecom';

        private const TEST_NONCE_ACTION = 'oyiso_test_wecom_webhook';

        private const MAX_IMAGE_BYTES = 2097152;

        private const SENT_META_KEY = '_oyiso_wecom_order_image_sent_channels';

        private const LEGACY_SENT_META_KEY = '_oyiso_wecom_order_image_sent';

        private const DELIVERY_META_KEY = '_oyiso_wecom_order_image_delivery';

        /** @var array{sent: int, skipped: int, failed: int, errors: list<string>, retryable: bool}|null */
        private static ?array $lastResult = null;

        /** @return array{sent: int, skipped: int, failed: int, errors: list<string>, retryable: bool}|null */
        public static function getLastResult(): ?array {
            return self::$lastResult;
        }

        public static function register(): void {
            add_action('admin_enqueue_scripts', [self::class, 'enqueueAdminAssets']);
            add_action(
                'wp_ajax_oyiso_test_wecom_webhook',
                [self::class, 'handleWebhookTest']
            );

            if (!class_exists('WooCommerce')) {
                return;
            }

            Oyiso_WeCom_Order_Image_Delivery::register();
        }

        public static function enqueueAdminAssets(string $hook): void {
            if (!oyiso_is_settings_page_hook($hook)) {
                return;
            }

            $scriptPath = __DIR__ . '/assets/wecom-test.js';

            wp_enqueue_script(
                'oyiso-wecom-test',
                plugins_url('assets/wecom-test.js', __FILE__),
                ['jquery'],
                is_file($scriptPath) ? (string) filemtime($scriptPath) : null,
                true
            );
            wp_localize_script(
                'oyiso-wecom-test',
                'oyisoWeComTest',
                [
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'nonce'   => wp_create_nonce(self::TEST_NONCE_ACTION),
                ]
            );
        }

        public static function handleWebhookTest(): void {
            check_ajax_referer(self::TEST_NONCE_ACTION, 'nonce');

            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => '无权限执行该操作。'], 403);
            }

            $value = $_POST['key'] ?? '';
            $key   = is_string($value) ? trim(wp_unslash($value)) : '';

            if ('' === $key) {
                wp_send_json_error(['message' => '请先填写 Webhook Key。'], 400);
            }

            if (!oyiso_is_valid_wecom_webhook_key($key)) {
                wp_send_json_error(['message' => 'Webhook Key 格式无效。'], 400);
            }

            try {
                self::sendText(
                    sprintf(
                        "Oyiso 企业微信测试消息\n站点：%s\n地址：%s\n时间：%s",
                        wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES),
                        home_url('/'),
                        current_time('Y-m-d H:i:s')
                    ),
                    $key
                );

                wp_send_json_success(['message' => '测试消息已发送。']);
            } catch (Throwable $exception) {
                wp_send_json_error([
                    'message' => '测试发送失败：' . $exception->getMessage(),
                ], 502);
            }
        }

        public static function forward(
            string $imagePath,
            string $htmlPath,
            int $orderId,
            bool $force = false,
            string $onlyChannel = '',
            string $expectedHash = ''
        ): void {
            unset($htmlPath);

            $keys = array_filter(
                oyiso_get_enabled_wecom_webhook_keys(),
                static fn(string $key): bool => '' === $onlyChannel || hash_equals($onlyChannel, self::getChannelId($key))
            );
            $result = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => [], 'retryable' => false];
            self::$lastResult = $result;

            if ([] === $keys) {
                return;
            }

            $lock = false;
            $fileHash = $expectedHash;
            try {
                $imagePath = self::validateImagePath($imagePath);
                $fileHash = md5_file($imagePath) ?: '';
                $lock = fopen(dirname($imagePath) . '/.wecom-forward.lock', 'c');
                if (false === $lock) {
                    throw new RuntimeException('无法创建企业微信发送锁，请检查归档目录写入权限。');
                }
                if (!flock($lock, LOCK_EX | LOCK_NB)) {
                    throw new Oyiso_WeCom_Retryable_Exception('当前站点有截图正在发送，请稍后补发。');
                }

                $order = wc_get_order($orderId);
                if (!$order instanceof WC_Order) {
                    throw new RuntimeException('订单已不存在，取消截图发送。');
                }
                $contents = file_get_contents($imagePath);
                if (false === $contents || '' === $contents) {
                    throw new RuntimeException('无法读取待发送的订单截图。');
                }
                $fileHash = md5($contents);
                if ('' !== $expectedHash && !hash_equals($expectedHash, $fileHash)) {
                    ++$result['skipped'];
                    self::logInfo(sprintf('订单 %d 的截图已更新，跳过旧图片发送任务。', $orderId));
                    return;
                }
                $sentHashes = self::getSentHashes($order, $fileHash);

                foreach ($keys as $index => $key) {
                    $channelId = self::getChannelId($key);

                    if (
                        !$force
                        && isset($sentHashes[$channelId])
                        && hash_equals($sentHashes[$channelId], $fileHash)
                    ) {
                        ++$result['skipped'];
                        continue;
                    }

                    try {
                        self::recordDeliveryState($orderId, basename($imagePath), $fileHash, $channelId, 'sending');
                        self::sendImage($contents, $key);
                        ++$result['sent'];
                        $sentHashes[$channelId] = $fileHash;
                        // Persist each successful channel before another request can be interrupted.
                        try {
                            $order->update_meta_data(self::SENT_META_KEY, $sentHashes);
                            $order->save();
                        } catch (Throwable $exception) {
                            $result['errors'][] = '发送状态保存失败，下次重试可能重复发送。';
                            self::logError(sprintf('订单 %d 的企业微信发送状态保存失败：%s', $orderId, $exception->getMessage()));
                        }
                        self::recordDeliveryState($orderId, basename($imagePath), $fileHash, $channelId, 'sent');

                        self::logInfo(
                            sprintf(
                                '订单 %d 的邮件截图已发送到企业微信渠道 %d。',
                                $orderId,
                                $index + 1
                            )
                        );
                    } catch (Throwable $exception) {
                        self::recordDeliveryState($orderId, basename($imagePath), $fileHash, $channelId, 'failed', $exception->getMessage());
                        ++$result['failed'];
                        $result['retryable'] = $result['retryable'] || $exception instanceof Oyiso_WeCom_Retryable_Exception;
                        $result['errors'][] = sprintf('渠道 %d：%s', $index + 1, $exception->getMessage());
                        self::logError(
                            sprintf(
                                '订单 %d 的邮件截图发送到企业微信渠道 %d 失败：%s',
                                $orderId,
                                $index + 1,
                                $exception->getMessage()
                            )
                        );
                    }
                }
            } catch (Throwable $exception) {
                foreach ($keys as $key) {
                    self::recordDeliveryState($orderId, basename($imagePath), $fileHash, self::getChannelId($key), 'failed', $exception->getMessage());
                }
                $result['failed'] = count($keys);
                $result['retryable'] = $exception instanceof Oyiso_WeCom_Retryable_Exception;
                $result['errors'][] = $exception->getMessage();
                self::logError(sprintf('订单 %d 的企业微信转发准备失败：%s', $orderId, $exception->getMessage()));
            } finally {
                self::$lastResult = $result;
                if (is_resource($lock)) {
                    fclose($lock);
                }
            }
        }

        /**
         * @return array<string, string>
         */
        private static function getSentHashes(WC_Order $order, string $fileHash): array {
            $stored = $order->get_meta(self::SENT_META_KEY, true);
            $hashes = [];

            if (is_array($stored)) {
                foreach ($stored as $channelId => $sentHash) {
                    if (is_string($channelId) && is_string($sentHash)) {
                        $hashes[$channelId] = $sentHash;
                    }
                }
            }

            $legacyHash = $order->get_meta(self::LEGACY_SENT_META_KEY, true);
            $legacyKey  = oyiso_get_legacy_wecom_webhook_key();

            if (
                is_string($legacyHash)
                && '' !== $legacyHash
                && '' !== $legacyKey
                && hash_equals($legacyHash, $fileHash)
            ) {
                $hashes[self::getChannelId($legacyKey)] = $fileHash;
            }

            return $hashes;
        }

        public static function getChannelId(string $key): string {
            return hash_hmac('sha256', $key, wp_salt('auth'));
        }

        public static function recordDeliveryState(
            int $orderId, string $filename, string $hash, string $channelId, string $state, string $message = ''
        ): void {
            if (1 !== preg_match('/^[a-f0-9]{32}$/', $hash) || 1 !== preg_match('/^[a-f0-9]{64}$/', $channelId)) {
                return;
            }
            try {
                $order = wc_get_order($orderId);
                if (!$order instanceof WC_Order) {
                    return;
                }
                $order->read_meta_data(true);
                $stored = $order->get_meta(self::DELIVERY_META_KEY, true);
                $stored = is_array($stored) ? $stored : [];
                $filename = basename($filename);
                $channels = $stored[$filename] ?? [];
                $channels = is_array($channels) ? $channels : [];
                $channels[$channelId] = [
                    'hash' => $hash, 'state' => $state, 'updated' => time(),
                    'message' => sanitize_text_field(str_replace(oyiso_get_enabled_wecom_webhook_keys(), '[已隐藏]', $message)),
                ];
                $stored[$filename] = $channels;
                $order->update_meta_data(self::DELIVERY_META_KEY, $stored);
                $order->save_meta_data();
            } catch (Throwable $exception) {
                // Status display must never prevent delivery or its recovery task.
                self::logError(sprintf('订单 %d 的通知状态保存失败：%s', $orderId, $exception->getMessage()));
            }
        }

        /** @return array{status: string, label: string, detail: string} */
        public static function getArchiveNotificationStatus(WC_Order $order, string $imagePath): array {
            if ('' === $imagePath) {
                return ['status' => 'not_sent', 'label' => '未通知', 'detail' => '尚无归档截图，未发送截图通知。'];
            }
            $hash = is_readable($imagePath) ? md5_file($imagePath) : false;
            if (false === $hash) {
                return ['status' => 'unknown', 'label' => '状态未知', 'detail' => '无法读取截图以核对通知回执。'];
            }
            $sentHashes = self::getSentHashes($order, $hash);
            $stored = $order->get_meta(self::DELIVERY_META_KEY, true);
            $stored = is_array($stored) ? $stored : [];
            $stored = $stored[basename($imagePath)] ?? [];
            $stored = is_array($stored) ? $stored : [];
            $channels = array_map([self::class, 'getChannelId'], oyiso_get_enabled_wecom_webhook_keys());
            if ([] === $channels) {
                // Retain confirmed historical delivery after a channel is removed.
                $channels = array_keys(array_filter($sentHashes, static fn(string $value): bool => hash_equals($hash, $value)));
                foreach ($stored as $channelId => $delivery) {
                    if (is_string($channelId) && is_array($delivery) && ($delivery['hash'] ?? '') === $hash && ($delivery['state'] ?? '') === 'sent') {
                        $channels[] = $channelId;
                    }
                }
                $channels = array_values(array_unique($channels));
            }
            $counts = ['sent' => 0, 'failed' => 0, 'queued' => 0, 'sending' => 0, 'retrying' => 0, 'unknown' => 0];
            $details = [];
            $labels = ['sent' => '已通知', 'failed' => '通知失败', 'queued' => '待通知', 'sending' => '通知中', 'retrying' => '重试中', 'unknown' => '状态未知'];
            foreach ($channels as $index => $channelId) {
                $delivery = $stored[$channelId] ?? null;
                $delivery = is_array($delivery) ? $delivery : [];
                $matches = ($delivery['hash'] ?? '') === $hash;
                $state = $matches && is_string($delivery['state'] ?? null) ? $delivery['state'] : 'unknown';
                // An in-flight request is not a receipt, including a worker that stopped.
                if ('sending' === $state && (!is_int($delivery['updated'] ?? null) || $delivery['updated'] < time() - 300)) {
                    $state = 'unknown';
                }
                if (isset($sentHashes[$channelId]) && hash_equals($hash, $sentHashes[$channelId])) {
                    $state = 'sent';
                } elseif (!isset($counts[$state])) {
                    $state = 'unknown';
                }
                ++$counts[$state];
                $error = $matches && is_string($delivery['message'] ?? null) ? $delivery['message'] : '';
                $details[] = sprintf('企业微信渠道 %d：%s%s', $index + 1, $labels[$state], '' !== $error ? '（' . $error . '）' : '');
            }
            $total = count($channels);
            $enabled = oyiso_is_wc_order_screenshot_forwarding_enabled();
            if ($total > 0 && $counts['sent'] === $total) {
                $status = 'sent';
            } elseif ($counts['sent'] > 0) {
                $status = 'partial';
            } elseif (!$enabled) {
                $status = 'disabled';
            } elseif ($counts['sending'] > 0) {
                $status = 'sending';
            } elseif ($counts['retrying'] > 0) {
                $status = 'retrying';
            } elseif ($counts['queued'] > 0) {
                $status = 'queued';
            } elseif ($counts['failed'] > 0) {
                $status = 'failed';
            } else {
                $status = 'unknown';
            }
            $label = 'partial' === $status ? sprintf('部分通知 %d/%d', $counts['sent'], $total)
                : ($labels[$status] ?? '未启用');
            $summary = $total > 0 ? sprintf('该截图已获 %d/%d 个企业微信渠道的成功回执。', $counts['sent'], $total) : '没有已启用的企业微信渠道。';
            if ('unknown' === $status) {
                $summary .= '没有该截图的成功回执，无法确认是否曾通知。';
            }
            if (!$enabled) {
                $summary .= '截图转发当前未启用。';
            }
            return ['status' => $status, 'label' => $label, 'detail' => $summary . implode('；', $details)];
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

        private static function sendImage(string $contents, string $key): void {
            $payload = [
                'msgtype' => 'image',
                'image'   => [
                    'base64' => base64_encode($contents),
                    'md5'    => md5($contents),
                ],
            ];

            self::sendPayload($payload, $key);
        }

        private static function sendText(string $message, string $key): void {
            self::sendPayload([
                'msgtype' => 'text',
                'text'    => [
                    'content' => $message,
                ],
            ], $key);
        }

        /**
         * @param array<string, mixed> $payload
         */
        private static function sendPayload(array $payload, string $key): void {
            $body = wp_json_encode($payload, JSON_UNESCAPED_UNICODE);

            if (!is_string($body)) {
                throw new RuntimeException('无法生成企业微信请求内容。');
            }

            $response = wp_remote_post(self::WEBHOOK_URL . rawurlencode($key), [
                'timeout'     => 30,
                'redirection' => 0,
                'headers'     => [
                    'Accept'       => 'application/json',
                    'Content-Type' => 'application/json; charset=UTF-8',
                ],
                'body'        => $body,
                'data_format' => 'body',
            ]);

            if (is_wp_error($response)) {
                throw new Oyiso_WeCom_Retryable_Exception('调用企业微信接口失败：' . $response->get_error_message());
            }

            $statusCode = wp_remote_retrieve_response_code($response);
            $body       = wp_remote_retrieve_body($response);

            if ($statusCode < 200 || $statusCode >= 300) {
                if (429 === $statusCode || $statusCode >= 500) {
                    throw new Oyiso_WeCom_Retryable_Exception(sprintf('企业微信接口返回HTTP %d。', $statusCode));
                }
                throw new RuntimeException(
                    sprintf('企业微信接口返回HTTP %d。', $statusCode)
                );
            }

            $result = json_decode($body, true);

            if (!is_array($result)) {
                throw new Oyiso_WeCom_Retryable_Exception('企业微信接口返回了无效JSON。');
            }

            if (!isset($result['errcode']) || !is_int($result['errcode'])) {
                throw new Oyiso_WeCom_Retryable_Exception('企业微信接口未返回有效的发送状态。');
            }
            $errorCode = $result['errcode'];

            if (0 !== $errorCode) {
                $errorMessage = isset($result['errmsg']) && is_string($result['errmsg'])
                    ? sanitize_text_field($result['errmsg'])
                    : '未知错误';

                if (in_array($errorCode, [-1, 45009], true)) {
                    throw new Oyiso_WeCom_Retryable_Exception(sprintf('企业微信接口错误 %d：%s', $errorCode, $errorMessage));
                }
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
