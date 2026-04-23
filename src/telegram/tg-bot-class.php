<?php if ( ! defined( 'ABSPATH' ) ) { die; }

class OyisoTGBot {
    protected const ASYNC_HOOK = 'oyiso_tg_send_message';
    protected const ASYNC_GROUP = 'oyiso-tg';
    protected const MAX_RETRIES = 3;
    protected string $token;
    protected array $chatIds = [];

    /**
     * @param string $token
     * @param array  $chatIds
     */
    public function __construct(string $token, array $chatIds) {
        if (empty($token)) {
            throw new InvalidArgumentException('Telegram bot token is required');
        }

        $this->token = $token;
        $this->chatIds = array_values(array_unique($chatIds));
    }

    /**
     * textarea 一行一个 chat_id 转数组
     * - 去首尾空格
     * - 去空行
     * - 支持 \n / \r\n
     */
    public static function parseChatIds(string $input): array {
        $lines = preg_split('/\r\n|\r|\n/', $input);

        $chatIds = [];

        foreach ($lines as $line) {
            $id = trim($line);

            if ($id !== '') {
                $chatIds[] = $id;
            }
        }

        return array_values(array_unique($chatIds));
    }

    /**
     * 统一整理后台任务参数，兼容旧版 wp-cron 的 3 参结构。
     */
    public static function normalizePayloadFromHookArgs($arg1 = null, $arg2 = null, $arg3 = null, $arg4 = null): array {
        if (is_array($arg1) && isset($arg1['token'])) {
            return self::sanitizePayload($arg1);
        }

        if (is_string($arg1) && is_array($arg2) && is_string($arg3)) {
            return self::sanitizePayload([
                'token'   => $arg1,
                'chat_ids'=> $arg2,
                'content' => $arg3,
                'context' => is_array($arg4) ? $arg4 : [],
            ]);
        }

        return [];
    }

    /**
     * 投入后台队列，优先使用 Action Scheduler，失败时兜底到 WP Cron，再不行就同步发送。
     */
    public function sendMessage(string $content, array $context = []): bool {
        if (!isset($context['blog_id']) && function_exists('get_current_blog_id')) {
            $context['blog_id'] = (int) get_current_blog_id();
        }

        $payload = self::sanitizePayload([
            'token'      => $this->token,
            'chat_ids'   => $this->chatIds,
            'content'    => $content,
            'context'    => $context,
            'attempt'    => 0,
            'created_at' => time(),
        ]);

        if (self::queueAsyncSend($payload)) {
            return true;
        }

        return self::processQueuedSend($payload);
    }

    /**
     * 后台任务实际执行入口。
     */
    public static function processQueuedSend(array $payload): bool {
        $payload = self::sanitizePayload($payload);

        if (empty($payload['token']) || empty($payload['chat_ids']) || $payload['content'] === '') {
            self::logError('Invalid Telegram payload');
            return false;
        }

        return self::runInBlogContext($payload['context'], static function () use ($payload): bool {
            $failedChatIds = [];

            foreach ($payload['chat_ids'] as $chatId) {
                if (!self::sendToChat($payload['token'], $chatId, $payload['content'])) {
                    $failedChatIds[] = $chatId;
                }
            }

            if (empty($failedChatIds)) {
                self::handleSuccess($payload);
                return true;
            }

            self::queueRetry($payload, $failedChatIds);
            return false;
        });
    }

    protected static function sanitizePayload(array $payload): array {
        return [
            'token'      => isset($payload['token']) ? (string) $payload['token'] : '',
            'chat_ids'   => array_values(array_unique(array_filter(array_map('strval', $payload['chat_ids'] ?? []), static function ($chatId) {
                return $chatId !== '';
            }))),
            'content'    => isset($payload['content']) ? (string) $payload['content'] : '',
            'context'    => is_array($payload['context'] ?? null) ? $payload['context'] : [],
            'attempt'    => max(0, (int) ($payload['attempt'] ?? 0)),
            'created_at' => (int) ($payload['created_at'] ?? time()),
        ];
    }

    protected static function queueAsyncSend(array $payload): bool {
        if (function_exists('as_enqueue_async_action')) {
            try {
                $actionId = as_enqueue_async_action(self::ASYNC_HOOK, [$payload], self::ASYNC_GROUP);
                if (!empty($actionId)) {
                    return true;
                }
            } catch (Throwable $e) {
                self::logError('Action Scheduler enqueue failed: ' . $e->getMessage());
            }
        }

        return self::scheduleWpCronSend($payload, time());
    }

    protected static function scheduleWpCronSend(array $payload, int $timestamp): bool {
        $scheduled = wp_schedule_single_event($timestamp, self::ASYNC_HOOK, [$payload]);

        if (is_wp_error($scheduled) || false === $scheduled) {
            self::logError('WP Cron enqueue failed');
            return false;
        }

        self::triggerWpCronRunner();
        return true;
    }

    protected static function triggerWpCronRunner(): void {
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            $doing_wp_cron = sprintf('%.22F', microtime(true));
            set_transient('doing_cron', $doing_wp_cron);

            wp_remote_post(
                add_query_arg('doing_wp_cron', $doing_wp_cron, site_url('wp-cron.php')),
                [
                    'timeout'   => 1,
                    'blocking'  => false,
                    'sslverify' => apply_filters('https_local_ssl_verify', false),
                ]
            );

            return;
        }

        spawn_cron();
    }

    protected static function sendToChat(string $token, string $chatId, string $content): bool {
        $url = "https://api.telegram.org/bot{$token}/sendMessage";

        $response = wp_remote_post($url, [
            'timeout' => 15,
            'body'    => [
                'chat_id'                  => $chatId,
                'text'                     => $content,
                'parse_mode'               => 'HTML',
                'disable_web_page_preview' => true,
            ],
        ]);

        if (is_wp_error($response)) {
            self::logError(sprintf('Send failed for chat_id %s: %s', $chatId, $response->get_error_message()));
            return false;
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($statusCode < 200 || $statusCode >= 300) {
            self::logError(sprintf('Send failed for chat_id %s: HTTP %d %s', $chatId, $statusCode, $body));
            return false;
        }

        $decoded = json_decode($body, true);

        if (is_array($decoded) && array_key_exists('ok', $decoded) && !$decoded['ok']) {
            $description = isset($decoded['description']) ? (string) $decoded['description'] : 'Unknown Telegram API error';
            self::logError(sprintf('Send failed for chat_id %s: %s', $chatId, $description));
            return false;
        }

        return true;
    }

    protected static function queueRetry(array $payload, array $failedChatIds): void {
        $attempt = (int) $payload['attempt'];
        $context = $payload['context'];

        if ($attempt >= self::MAX_RETRIES) {
            self::clearPendingOrderFlag($context);
            self::markOrderFailure($context);
            self::logError(sprintf('Telegram send failed permanently after %d retries', $attempt));
            return;
        }

        $retryPayload = $payload;
        $retryPayload['chat_ids'] = $failedChatIds;
        $retryPayload['attempt'] = $attempt + 1;
        $timestamp = time() + self::getRetryDelay($retryPayload['attempt']);

        if (function_exists('as_schedule_single_action')) {
            try {
                $actionId = as_schedule_single_action($timestamp, self::ASYNC_HOOK, [$retryPayload], self::ASYNC_GROUP);
                if (!empty($actionId)) {
                    return;
                }
            } catch (Throwable $e) {
                self::logError('Action Scheduler retry enqueue failed: ' . $e->getMessage());
            }
        }

        if (!self::scheduleWpCronSend($retryPayload, $timestamp)) {
            self::clearPendingOrderFlag($context);
            self::markOrderFailure($context);
        }
    }

    protected static function getRetryDelay(int $attempt): int {
        $delays = [
            1 => 60,
            2 => 300,
            3 => 900,
        ];

        return $delays[$attempt] ?? 1800;
    }

    protected static function handleSuccess(array $payload): void {
        $context = $payload['context'];

        if (!function_exists('wc_get_order')) {
            return;
        }

        $orderId = (int) ($context['order_id'] ?? 0);
        if ($orderId <= 0) {
            return;
        }

        $order = wc_get_order($orderId);
        if (!$order) {
            return;
        }

        $successMetaKey = isset($context['success_meta_key']) ? (string) $context['success_meta_key'] : '';
        $pendingMetaKey = isset($context['pending_meta_key']) ? (string) $context['pending_meta_key'] : '';
        $failureMetaKey = isset($context['failure_meta_key']) ? (string) $context['failure_meta_key'] : '';

        if ($successMetaKey !== '') {
            $order->update_meta_data($successMetaKey, 1);
        }

        if ($pendingMetaKey !== '') {
            $order->delete_meta_data($pendingMetaKey);
        }

        if ($failureMetaKey !== '') {
            $order->delete_meta_data($failureMetaKey);
        }

        $order->save();
    }

    protected static function clearPendingOrderFlag(array $context): void {
        if (!function_exists('wc_get_order')) {
            return;
        }

        $orderId = (int) ($context['order_id'] ?? 0);
        $pendingMetaKey = isset($context['pending_meta_key']) ? (string) $context['pending_meta_key'] : '';

        if ($orderId <= 0 || $pendingMetaKey === '') {
            return;
        }

        $order = wc_get_order($orderId);
        if (!$order) {
            return;
        }

        $order->delete_meta_data($pendingMetaKey);
        $order->save();
    }

    protected static function markOrderFailure(array $context): void {
        if (!function_exists('wc_get_order')) {
            return;
        }

        $orderId = (int) ($context['order_id'] ?? 0);
        $failureMetaKey = isset($context['failure_meta_key']) ? (string) $context['failure_meta_key'] : '';

        if ($orderId <= 0 || $failureMetaKey === '') {
            return;
        }

        $order = wc_get_order($orderId);
        if (!$order) {
            return;
        }

        $order->update_meta_data($failureMetaKey, current_time('mysql'));
        $order->save();
    }

    protected static function logError(string $message): void {
        error_log('[TelegramBot] ' . $message . PHP_EOL);
    }

    /**
     * 多站点下显式切回原始子站，避免异步任务运行在错误 blog 上下文。
     *
     * @param array    $context
     * @param callable $callback
     * @return mixed
     */
    protected static function runInBlogContext(array $context, callable $callback) {
        $blogId = (int) ($context['blog_id'] ?? 0);

        if (!is_multisite() || $blogId <= 0 || !function_exists('get_current_blog_id') || !function_exists('switch_to_blog')) {
            return $callback();
        }

        $currentBlogId = (int) get_current_blog_id();

        if ($currentBlogId === $blogId) {
            return $callback();
        }

        switch_to_blog($blogId);

        try {
            return $callback();
        } finally {
            restore_current_blog();
        }
    }
}
