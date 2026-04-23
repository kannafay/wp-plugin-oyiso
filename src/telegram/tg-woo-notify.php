<?php

defined('ABSPATH') || exit;

const OYISO_TG_ORDER_NOTIFIED_META_KEY = '_oyiso_tg_notified';
const OYISO_TG_ORDER_PENDING_META_KEY = '_oyiso_tg_notify_pending';
const OYISO_TG_ORDER_FAILED_META_KEY = '_oyiso_tg_notify_failed_at';

$notify_options = $options['woo_notify_options'] ?? [];
$_oyiso_tg_token = $options['bot_token'] ?? '';
$_oyiso_tg_chatids_raw = $options['tg_chatids'] ?? '';
$enableWooNotify = $options['woo_notify'] ?? false;

if (empty($_oyiso_tg_token) || empty($_oyiso_tg_chatids_raw) || !$enableWooNotify) {
    return;
}

/**
 * 延迟实例化 TGBot，仅在首次调用时创建
 */
if (!function_exists('oyiso_get_tg_bot')) {
    function oyiso_get_tg_bot(): ?OyisoTGBot {
        static $bots = [];
        $blogId = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;

        if (!array_key_exists($blogId, $bots)) {
            require_once __DIR__ . '/tg-bot-class.php';
            $options = get_option('oyiso', []);
            $token   = $options['bot_token'] ?? '';
            $chatIds = OyisoTGBot::parseChatIds($options['tg_chatids'] ?? '');
            if (empty($token) || empty($chatIds)) {
                $bots[$blogId] = null;
                return null;
            }
            $bots[$blogId] = new OyisoTGBot($token, $chatIds);
        }

        return $bots[$blogId];
    }
}

/**
 * WooCommerce 获取纯文本价格（无 HTML / 无实体）
 */
if (!function_exists('oyiso_wc_price')) {
    function oyiso_wc_price($amount) {
        $price = wc_price($amount, ['html_format' => false]);

        // 去标签 + 解码 HTML 实体
        $price = wp_strip_all_tags($price);
        $price = html_entity_decode($price, ENT_QUOTES, 'UTF-8');

        return $price;
    }
}

/**
 * 获取客户端真实IP地址
 */
if (!function_exists('oyiso_get_client_ip')) {
function oyiso_get_client_ip(): string {
    $keys = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_REAL_IP',
        'HTTP_X_FORWARDED_FOR',
        'REMOTE_ADDR',
    ];

    foreach ($keys as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER[$key]));

            // X-Forwarded-For 可能是多个 IP
            if (strpos($ip, ',') !== false) {
                $ip = trim(explode(',', $ip)[0]);
            }

            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }

    return 'unknown';
}
}

/**
 * 获取 WooCommerce 订单收货地址（纯文本，适合 TG / 日志）
 *
 * @param WC_Order $order
 * @return string
 */
if (!function_exists('oyiso_get_order_shipping_address_text')) {
function oyiso_get_order_shipping_address_text(WC_Order $order): string {
    // 优先收货地址，兜底账单地址
    $address = $order->get_formatted_shipping_address();

    if (empty($address)) {
        $address = $order->get_formatted_billing_address();
    }

    if (empty($address)) {
        return '';
    }

    // <br> 转成逗号
    $address = str_replace(
        ['<br>', '<br/>', '<br />'],
        ', ',
        $address
    );

    // 去 HTML + 解码实体
    $address = wp_strip_all_tags($address);
    $address = html_entity_decode($address, ENT_QUOTES, 'UTF-8');

    // 清理多余空白
    $address = preg_replace('/\s+/', ' ', $address);

    return trim($address);
}
}

/**
 * 购物车消息格式
 */
if (!function_exists('oyiso_wc_cart')) {
    function oyiso_wc_cart($type, $product, $variation, $quantity): string {
        $productName = '';
        if (!empty($variation) && $type === 'add') {
            $variation_text = [];
            foreach ($variation as $attr => $value) {
                $taxonomy = str_replace('attribute_', '', $attr);
                $term = get_term_by('slug', $value, $taxonomy);
                $variation_text[] = $term ? $term->name : $value;
            }
            $productName = $product->get_name() . ' - ' . implode(', ', $variation_text);
        } else {
            $productName = $product->get_name();
        }

        $siteName = get_bloginfo('name');
        $siteUrl = get_bloginfo('url');

        $title = $type === 'add' ? '✨加入购物车' : '😭移出购物车';

        $message = sprintf(
            "<b>%s【%s】：</b>\n" .
            "<b>站点：</b>%s\n" .
            "<b>产品：</b>%s\n" .
            "<b>数量：</b>%d\n" .
            "<b>单价：</b>%s\n" .
            "<b>小计：</b>%s\n" .
            "<b>IP：</b>%s\n" .
            "<b>时间：</b>%s",
            $title,
            $siteName,
            $siteUrl,
            $productName,
            $quantity,
            oyiso_wc_price($product->get_price()),
            oyiso_wc_price($product->get_price() * $quantity),
            oyiso_get_client_ip(),
            date_i18n('Y-m-d H:i:s')
        );

        return $message;
    }
}

/**
 * 生成 Telegram 新订单通知文本（sprintf 版）
 *
 * @param WC_Order $order
 * @return string
 */
if (!function_exists('oyiso_build_order_message')) {
function oyiso_build_order_message(WC_Order $order) {
    $siteName = get_bloginfo('name');
    $siteUrl = get_bloginfo('url');

    // 产品列表
    $items = [];
    foreach ($order->get_items() as $item) {
        $items[] = sprintf(
            '- %s × %d',
            $item->get_name(),
            $item->get_quantity()
        );
    }
    $items_text = implode("\n", $items);

    // 支付 & 物流
    $payment_method = $order->get_payment_method_title();
    $shipping_method = $order->get_shipping_method();

    // 金额（纯文本）
    $subtotal = oyiso_wc_price($order->get_subtotal());
    $shipping = oyiso_wc_price($order->get_shipping_total());
    $total = oyiso_wc_price($order->get_total());

    // 地址
    $address = oyiso_get_order_shipping_address_text($order);

    // 客户备注
    $customer_note = $order->get_customer_note();
    if (empty($customer_note)) {
        $customer_note = '无';
    }

    // IP
    $ip = oyiso_get_client_ip();

    // 时间
    $time = $order->get_date_created()
        ? $order->get_date_created()->date('Y-m-d H:i:s')
        : current_time('Y-m-d H:i:s');

    return sprintf(
        "<b>🎉您有一个新订单【%s】：</b>\n" .
        "<b>站点：</b>%s\n" .
        "<b>订单号：</b>#%d\n\n" .
        "<b>📦【产品】：</b>\n%s\n\n" .
        "<b>🚚【支付与运送】：</b>\n" .
        "<b>支付方式：</b>%s\n" .
        "<b>运送方式：</b>%s\n" .
        "<b>金额：</b>%s\n" .
        "<b>运费：</b>%s\n" .
        "<b>总金额：</b>%s\n\n" .
        "<b>🧑‍💼【账单信息】：</b>\n" .
        "<b>客户：</b>%s\n" .
        "<b>邮箱：</b>%s\n" .
        "<b>电话：</b>%s\n" .
        "<b>地址：</b>%s\n" .
        "<b>备注：</b>%s\n\n" .
        "<b>IP：</b>%s\n" .
        "<b>时间：</b>%s",
        $siteName,
        $siteUrl,
        $order->get_id(),
        $items_text,
        $payment_method,
        $shipping_method,
        $subtotal,
        $shipping,
        $total,
        $order->get_formatted_billing_full_name(),
        $order->get_billing_email(),
        $order->get_billing_phone(),
        $address,
        $customer_note,
        $ip,
        $time
    );
}
}

if (!function_exists('oyiso_mark_order_notification_pending')) {
    function oyiso_mark_order_notification_pending(WC_Order $order): void {
        $order->update_meta_data(OYISO_TG_ORDER_PENDING_META_KEY, 1);
        $order->delete_meta_data(OYISO_TG_ORDER_FAILED_META_KEY);
        $order->save();
    }
}

if (!function_exists('oyiso_queue_new_order_notification')) {
    function oyiso_queue_new_order_notification(int $order_id): void {
        $bot = oyiso_get_tg_bot();
        if (!$bot) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        if ($order->get_meta(OYISO_TG_ORDER_NOTIFIED_META_KEY, true)) {
            return;
        }

        if ($order->get_meta(OYISO_TG_ORDER_PENDING_META_KEY, true)) {
            return;
        }

        oyiso_mark_order_notification_pending($order);

        $message = oyiso_build_order_message($order);
        $queued = $bot->sendMessage($message, [
            'order_id'         => $order->get_id(),
            'blog_id'          => function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0,
            'success_meta_key' => OYISO_TG_ORDER_NOTIFIED_META_KEY,
            'pending_meta_key' => OYISO_TG_ORDER_PENDING_META_KEY,
            'failure_meta_key' => OYISO_TG_ORDER_FAILED_META_KEY,
        ]);

        if (!$queued) {
            $order->delete_meta_data(OYISO_TG_ORDER_PENDING_META_KEY);
            $order->update_meta_data(OYISO_TG_ORDER_FAILED_META_KEY, current_time('mysql'));
            $order->save();
        }
    }
}

/**
 * WooCommerce 加入购物车通知
 */
if ($notify_options['woo_add_to_cart'] ?? false) {
    add_action('woocommerce_add_to_cart', function ($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
        $bot = oyiso_get_tg_bot();
        if (!$bot) return;
        $product = wc_get_product($product_id);
        $message = oyiso_wc_cart('add', $product, $variation, $quantity);
        $bot->sendMessage($message);
    }, 10, 6);
}

/** 
 * WooCommerce 移出购物车通知
 */
if ($notify_options['woo_remove_from_cart'] ?? false) {
    add_action('woocommerce_remove_cart_item', function ($cart_item_key) {
        $bot = oyiso_get_tg_bot();
        if (!$bot) return;
        $cart_item = WC()->cart->get_cart_item($cart_item_key);
        if (empty($cart_item)) {
            return;
        }
        $product   = $cart_item['data'];
        $variation = $cart_item['variation'] ?? [];
        $quantity  = $cart_item['quantity'] ?? 1;
        $message = oyiso_wc_cart('remove', $product, $variation, $quantity);
        $bot->sendMessage($message);
    });
}

/**
 * WooCommerce 新订单通知
 */
if ($notify_options['woo_new_order'] ?? false) {
    add_action('woocommerce_checkout_order_processed', function ($order_id) {
        oyiso_queue_new_order_notification((int) $order_id);
    }, 10, 1);

    // 兼容部分支付流程仍走 thankyou，但 pending 标记会防重复入队。
    add_action('woocommerce_thankyou', function ($order_id) {
        oyiso_queue_new_order_notification((int) $order_id);
    }, 10, 1);
}

/**
 * WooCommerce 订单状态变更通知
 */
if ($notify_options['woo_order_status_change'] ?? false) {
    add_action('woocommerce_order_status_changed', function ($order_id, $old_status, $new_status, $order) {
        if (
            ($old_status === 'pending' && $new_status === 'processing')
            || ($old_status === 'checkout-draft' && $new_status === 'pending')
        ) {
            return;
        }
        $siteName = get_bloginfo('name');
        $siteUrl = get_bloginfo('url');

        $message = sprintf(
            "<b>📢订单状态已改变【%s】：</b>\n" .
            "<b>站点：</b>%s\n" .
            "<b>订单号：</b>#%d\n" .
            "<b>状态：</b>%s (%s) → %s (%s)\n" .
            "<b>时间：</b>%s",
            $siteName,
            $siteUrl,
            $order_id,
            wc_get_order_status_name($old_status),
            $old_status,
            wc_get_order_status_name($new_status),
            $new_status,
            date_i18n('Y-m-d H:i:s')
        );

        $bot = oyiso_get_tg_bot();
        if ($bot) $bot->sendMessage($message);
    }, 10, 4);
}
