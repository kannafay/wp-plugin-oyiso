<?php

class OyisoTGBot {
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
     * 给所有 chat_id 发送消息（HTML）
     */
    public function sendMessage(string $content): array {
        $results = [];

        foreach ($this->chatIds as $chatId) {
            $results[$chatId] = $this->sendOne($chatId, $content);
        }

        return $results;
    }

    /**
     * 发送给单个 chat_id
     */
    protected function sendOne(string|int $chatId, string $content): bool {
        $url = "https://api.telegram.org/bot{$this->token}/sendMessage";

        $data = [
            'chat_id' => $chatId,
            'text' => $content,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);

        curl_close($ch);

        if ($error) {
            error_log('[TelegramBot] ' . $error . PHP_EOL);
            return false;
        }

        $result = json_decode($response, true);

        return isset($result['ok']) && $result['ok'] === true;
    }
}
