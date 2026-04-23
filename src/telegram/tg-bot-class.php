<?php if ( ! defined( 'ABSPATH' ) ) { die; }

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
     * 投入 WP Cron 后台队列，立即返回，不阻塞当前请求
     */
    public function sendMessage(string $content): void {
        wp_schedule_single_event(time(), 'oyiso_tg_send_message', [
            $this->token,
            $this->chatIds,
            $content,
        ]);

        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            // spawn_cron() 被禁用，手动向本机 wp-cron.php 发非阻塞请求触发队列
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
        } else {
            spawn_cron();
        }
    }

    /**
     * WP Cron 回调 —— 在独立的后台请求中逐个发送
     */
    public static function processCronSend(string $token, array $chatIds, string $content): void {
        foreach ($chatIds as $chatId) {
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
                error_log('[TelegramBot] ' . $response->get_error_message() . PHP_EOL);
            }
        }
    }
}
