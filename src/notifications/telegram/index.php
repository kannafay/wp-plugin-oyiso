<?php

defined('ABSPATH') || exit;

if (!function_exists('oyiso_render_tg_test_field')) {
function oyiso_render_tg_test_field(): void {
    echo '<div class="oyiso-tg-test-field"><button type="button" class="button button-secondary" id="oyiso-tg-test-button">发送测试消息</button><div id="oyiso-tg-test-status" style="margin-top:8px;"></div></div>';
}
}

/**
 * Telegram Bot通知设置
 */
if (class_exists('CSF')) {
CSF::createSection($prefix, [
    'parent'   => 'notifications',
    'id'       => 'telegram-bot',
    'title'    => 'Telegram Bot',
    'icon'     => 'fab fa-telegram-plane',
    'priority' => 10,
    'fields' => [
        [
            'type' => 'heading',
            'content' => 'Telegram Bot',
        ],
        [
            'id' => 'bot_token',
            'type' => 'text',
            'title' => '机器人Token',
            'desc' => '填写从BotFather获取的Telegram机器人Token <a href="https://core.telegram.org/bots#6-botfather" target="_blank">点击获取</a>',
        ],
        [
            'id' => 'tg_chatids',
            'type' => 'textarea',
            'title' => '接收者Chat ID',
            'desc' => '填写接收者Chat ID，一行一个 <a href="https://telegram.me/chatIDrobot" target="_blank">点击获取</a>',
        ],
        [
            'type' => 'callback',
            'title' => '测试通知',
            'desc' => '保存当前 Token 和 Chat ID 后，可发送一条测试消息验证连通性。',
            'function' => 'oyiso_render_tg_test_field',
        ],
        [
            'id' => 'woo_notify',
            'type' => 'switcher',
            'title' => 'WooCommerce通知',
            'label' => '开启后可在有新订单时通过Telegram Bot发送通知',
            'default' => false,
        ],
        [
            'id' => 'woo_notify_options',
            'type' => 'tabbed',
            'title' => 'WooCommerce通知设置',
            'dependency' => ['woo_notify', '==', true],
            'tabs' => [
                [
                    'title' => '订单',
                    'icon' => 'fa fa-bell',
                    'fields' => [
                        [
                            'id' => 'woo_new_order',
                            'type' => 'switcher',
                            'title' => '新订单通知',
                            'label' => '开启后可在有新订单时通过Telegram Bot发送通知',
                            'default' => true,
                        ],
                        [
                            'id' => 'woo_order_status_change',
                            'type' => 'switcher',
                            'title' => '订单状态变更通知',
                            'label' => '开启后可在订单状态变更时通过Telegram Bot发送通知',
                            'default' => false,
                        ]
                    ]
                ],
                [
                    'title' => '购物车',
                    'icon' => 'fa fa-shopping-cart',
                    'fields' => [
                        [
                            'id' => 'woo_add_to_cart',
                            'type' => 'switcher',
                            'title' => '加入购物车通知',
                            'label' => '开启后可在有用户将商品加入购物车时通过Telegram Bot发送通知',
                            'default' => false,
                        ],
                        [
                            'id' => 'woo_remove_from_cart',
                            'type' => 'switcher',
                            'title' => '移出购物车通知',
                            'label' => '开启后可在有用户将商品从购物车移出时通过Telegram Bot发送通知',
                            'default' => false,
                        ],
                        [
                            'id' => 'woo_cart_quantity_change',
                            'type' => 'switcher',
                            'title' => '数量调整通知',
                            'label' => '开启后可在用户调整购物车商品数量时通过Telegram Bot发送通知',
                            'default' => false,
                        ]
                    ]
                ],
            ]
        ],
    ]
]);
}

add_action('admin_enqueue_scripts', function ($hook) {
    if (!oyiso_is_settings_page_hook($hook)) {
        return;
    }

    wp_register_script('oyiso-tg-test', '', ['jquery'], null, true);
    wp_enqueue_script('oyiso-tg-test');
    wp_localize_script('oyiso-tg-test', 'oyisoTgTest', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('oyiso_tg_test_message'),
        'labels'  => [
            'sending' => '发送中...',
            'success' => '测试消息已发送',
            'error'   => '发送失败',
        ],
    ]);

    wp_add_inline_script('oyiso-tg-test', <<<'JS'
jQuery(function ($) {
    var $button = $('#oyiso-tg-test-button');
    var $status = $('#oyiso-tg-test-status');

    if (!$button.length) {
        return;
    }

    $button.on('click', function () {
        $button.prop('disabled', true);
        $status.css('color', '').text(oyisoTgTest.labels.sending);

        $.post(oyisoTgTest.ajaxUrl, {
            action: 'oyiso_tg_test_message',
            nonce: oyisoTgTest.nonce
        }).done(function (response) {
            if (response && response.success) {
                $status.css('color', '#15803d').text(response.data.message);
                return;
            }

            var message = response && response.data && response.data.message
                ? response.data.message
                : oyisoTgTest.labels.error;

            $status.css('color', '#b91c1c').text(message);
        }).fail(function (xhr) {
            var message = oyisoTgTest.labels.error;

            if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                message = xhr.responseJSON.data.message;
            }

            $status.css('color', '#b91c1c').text(message);
        }).always(function () {
            $button.prop('disabled', false);
        });
    });
});
JS);
});

add_action('wp_ajax_oyiso_tg_test_message', function () {
    check_ajax_referer('oyiso_tg_test_message', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error([
            'message' => '无权限执行该操作',
        ], 403);
    }

    require_once __DIR__ . '/tg-bot-class.php';

    $options = get_option('oyiso', []);
    $token   = trim((string) ($options['bot_token'] ?? ''));
    $chatIds = OyisoTGBot::parseChatIds((string) ($options['tg_chatids'] ?? ''));

    if ($token === '') {
        wp_send_json_error([
            'message' => '请先填写并保存机器人Token',
        ], 400);
    }

    if (empty($chatIds)) {
        wp_send_json_error([
            'message' => '请先填写并保存至少一个Chat ID',
        ], 400);
    }

    try {
        $bot = new OyisoTGBot($token, $chatIds);
    } catch (Throwable $e) {
        wp_send_json_error([
            'message' => $e->getMessage(),
        ], 400);
    }

    $siteName = get_bloginfo('name');
    $siteUrl = get_bloginfo('url');
    $blogId = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;
    $siteType = is_multisite() ? '多站点网络' : '独立站点';
    $message = sprintf(
        "<b>Telegram 测试消息</b>\n<b>站点：</b>%s\n<b>地址：</b>%s\n<b>类型：</b>%s\n<b>ID：</b>%d\n<b>时间：</b>%s",
        $siteName,
        $siteUrl,
        $siteType,
        $blogId,
        current_time('Y-m-d H:i:s')
    );

    $result = $bot->sendMessageNow($message);

    if (!empty($result['success'])) {
        wp_send_json_success([
            'message' => sprintf('测试消息已发送到 %d 个接收者', count($result['results'])),
            'results' => $result['results'],
        ]);
    }

    $errors = array_map(static function (array $item): string {
        return sprintf('%s: %s', $item['chat_id'] ?? '-', $item['message'] ?? '发送失败');
    }, $result['results']);

    wp_send_json_error([
        'message' => '测试发送失败，' . implode('；', $errors),
        'results' => $result['results'],
    ], 500);
});

// 仅在 WooCommerce 激活时加载通知模块
if (class_exists('WooCommerce')) {
    require_once __DIR__ . '/tg-woo-notify.php';
}
