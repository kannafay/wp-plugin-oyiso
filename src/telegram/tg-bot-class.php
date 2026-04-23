<?php if ( ! defined( 'ABSPATH' ) ) { die; }

class OyisoTGBot {
    protected static array $deferredQueue = [];
    protected static bool $shutdownRegistered = false;
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
     * 将 Telegram 消息延后到请求结束时发送。
     * 如果服务器支持 fastcgi_finish_request()，会先把响应返回给用户，再继续发送。
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
        ]);

        if (empty($payload['token']) || empty($payload['chat_ids']) || $payload['content'] === '') {
            self::logError('Invalid Telegram payload');
            return false;
        }

        self::$deferredQueue[] = $payload;
        self::registerDeferredSender();

        return true;
    }

    /**
     * 立即同步发送，供后台测试按钮使用。
     *
     * @param string $content
     * @return array{success:bool,results:array<int,array<string,mixed>>}
     */
    public function sendMessageNow(string $content): array {
        $payload = self::sanitizePayload([
            'token'    => $this->token,
            'chat_ids' => $this->chatIds,
            'content'  => $content,
            'context'  => [
                'blog_id' => function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0,
            ],
        ]);

        return self::runInBlogContext($payload['context'], static function () use ($payload): array {
            $results = [];
            $allSucceeded = true;

            foreach ($payload['chat_ids'] as $chatId) {
                $result = self::sendToChatDetailed($payload['token'], $chatId, $payload['content']);
                $results[] = $result;

                if (empty($result['success'])) {
                    $allSucceeded = false;
                }
            }

            return [
                'success' => $allSucceeded,
                'results' => $results,
            ];
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
        ];
    }

    protected static function sendToChat(string $token, string $chatId, string $content): bool {
        $result = self::sendToChatDetailed($token, $chatId, $content);

        if (!$result['success']) {
            self::logError(sprintf('Send failed for chat_id %s: %s', $chatId, $result['message']));
        }

        return $result['success'];
    }

    /**
     * 发送并返回详细结果，便于后台测试按钮展示。
     *
     * @param string $token
     * @param string $chatId
     * @param string $content
     * @return array{success:bool,chat_id:string,message:string,status_code:int}
     */
    protected static function sendToChatDetailed(string $token, string $chatId, string $content): array {
        $url = "https://api.telegram.org/bot{$token}/sendMessage";

        $response = wp_remote_post($url, [
            'timeout' => 10,
            'body'    => [
                'chat_id'                  => $chatId,
                'text'                     => $content,
                'parse_mode'               => 'HTML',
                'disable_web_page_preview' => true,
            ],
        ]);

        if (is_wp_error($response)) {
            return [
                'success'     => false,
                'chat_id'     => $chatId,
                'message'     => $response->get_error_message(),
                'status_code' => 0,
            ];
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($statusCode < 200 || $statusCode >= 300) {
            return [
                'success'     => false,
                'chat_id'     => $chatId,
                'message'     => sprintf('HTTP %d %s', $statusCode, $body),
                'status_code' => $statusCode,
            ];
        }

        $decoded = json_decode($body, true);

        if (is_array($decoded) && array_key_exists('ok', $decoded) && !$decoded['ok']) {
            $description = isset($decoded['description']) ? (string) $decoded['description'] : 'Unknown Telegram API error';
            return [
                'success'     => false,
                'chat_id'     => $chatId,
                'message'     => $description,
                'status_code' => $statusCode,
            ];
        }

        return [
            'success'     => true,
            'chat_id'     => $chatId,
            'message'     => 'OK',
            'status_code' => $statusCode,
        ];
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
        $failureMetaKey = isset($context['failure_meta_key']) ? (string) $context['failure_meta_key'] : '';

        if ($successMetaKey !== '') {
            $order->update_meta_data($successMetaKey, 1);
        }

        if ($failureMetaKey !== '') {
            $order->delete_meta_data($failureMetaKey);
        }

        $order->save();
    }

    protected static function handleFailure(array $payload): void {
        $context = $payload['context'];

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

    protected static function registerDeferredSender(): void {
        if (self::$shutdownRegistered) {
            return;
        }

        self::$shutdownRegistered = true;
        register_shutdown_function([self::class, 'flushDeferredQueue']);
    }

    public static function flushDeferredQueue(): void {
        if (empty(self::$deferredQueue)) {
            return;
        }

        $queue = self::$deferredQueue;
        self::$deferredQueue = [];
        self::$shutdownRegistered = false;

        ignore_user_abort(true);

        if (function_exists('session_write_close')) {
            @session_write_close();
        }

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        foreach ($queue as $payload) {
            self::deliverPayload($payload);
        }
    }

    protected static function deliverPayload(array $payload): bool {
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

            self::handleFailure($payload);
            return false;
        });
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
