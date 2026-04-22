<?php

defined('ABSPATH') || exit;

if (class_exists('CSF')) {
    /**
     * Telegram Bot通知设置
     */
    CSF::createSection($prefix, [
        'parent'   => 'notifications',
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
                            ]
                        ]
                    ],
                ]
            ],
        ]
    ]);

    require_once 'tg-woo-notify.php';
}