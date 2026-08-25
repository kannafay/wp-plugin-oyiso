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
                'message'     => self::formatErrorMessage($response->get_error_message()),
                'status_code' => 0,
            ];
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);
        $description = is_array($decoded) && isset($decoded['description'])
            ? (string) $decoded['description']
            : '';

        if ($statusCode < 200 || $statusCode >= 300) {
            return [
                'success'     => false,
                'chat_id'     => $chatId,
                'message'     => self::formatErrorMessage(
                    $description !== '' ? $description : sprintf('HTTP %d', $statusCode),
                    $statusCode
                ),
                'status_code' => $statusCode,
            ];
        }

        if (is_array($decoded) && array_key_exists('ok', $decoded) && !$decoded['ok']) {
            return [
                'success'     => false,
                'chat_id'     => $chatId,
                'message'     => self::formatErrorMessage(
                    $description !== '' ? $description : 'Unknown Telegram API error',
                    $statusCode
                ),
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

    protected static function formatErrorMessage(string $message, int $statusCode = 0): string {
        $normalized = strtolower($message);

        if (str_contains($normalized, 'curl error 28')) {
            if (preg_match('/after\s+(\d+)\s+milliseconds/i', $message, $matches)) {
                $seconds = max(1, (int) round(((int) $matches[1]) / 1000));
                return sprintf(
                    '连接 Telegram API 超时（cURL 28，约 %d 秒；请检查服务器网络、代理或防火墙）',
                    $seconds
                );
            }

            return '连接 Telegram API 超时（cURL 28，请检查服务器网络、代理或防火墙）';
        }

        $curlMessages = [
            'curl error 6' => '无法解析 Telegram API 域名（cURL 6，请检查服务器 DNS）',
            'curl error 7' => '无法连接 Telegram API（cURL 7，请检查服务器网络、代理或防火墙）',
            'curl error 35' => 'Telegram API 的 SSL/TLS 连接失败（cURL 35）',
            'curl error 60' => 'Telegram API 的 SSL 证书校验失败（cURL 60，请检查服务器证书环境）',
        ];

        foreach ($curlMessages as $needle => $translatedMessage) {
            if (str_contains($normalized, $needle)) {
                return $translatedMessage;
            }
        }

        $telegramMessages = [
            'chat not found' => '找不到该 Chat ID，请确认 ID 正确且机器人已加入对应会话',
            'bot was blocked by the user' => '机器人已被该用户阻止，请先解除阻止或更换接收者',
            'bot was kicked' => '机器人已被移出该群组，请重新加入后再测试',
            'bot is not a member' => '机器人不在该群组中，请先将机器人加入群组',
            'not enough rights' => '机器人权限不足，请检查其群组或频道权限',
            'too many requests' => 'Telegram 请求过于频繁，请稍后再试',
            'unauthorized' => '机器人 Token 无效或已失效，请重新检查并保存 Token',
        ];

        foreach ($telegramMessages as $needle => $translatedMessage) {
            if (str_contains($normalized, $needle)) {
                return $translatedMessage;
            }
        }

        if ($statusCode === 401) {
            return '机器人 Token 无效或已失效，请重新检查并保存 Token';
        }

        if ($statusCode === 403) {
            return 'Telegram 拒绝发送，请检查机器人是否被阻止以及群组或频道权限';
        }

        if ($statusCode === 429) {
            return 'Telegram 请求过于频繁，请稍后再试';
        }

        if ($statusCode >= 500) {
            return sprintf('Telegram 服务暂时不可用（HTTP %d），请稍后再试', $statusCode);
        }

        if ($statusCode > 0) {
            return sprintf('Telegram API 请求失败（HTTP %d）：%s', $statusCode, $message);
        }

        return $message;
    }

    protected static function handleSuccess(array $payload): void {
        $context = $payload['context'];
        $pendingLockKey = isset($context['pending_lock_key']) ? (string) $context['pending_lock_key'] : '';

        try {
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
        } finally {
            if ($pendingLockKey !== '') {
                delete_option($pendingLockKey);
            }
        }
    }

    protected static function handleFailure(array $payload): void {
        $context = $payload['context'];
        $pendingLockKey = isset($context['pending_lock_key']) ? (string) $context['pending_lock_key'] : '';

        try {
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
        } finally {
            if ($pendingLockKey !== '') {
                delete_option($pendingLockKey);
            }
        }
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
