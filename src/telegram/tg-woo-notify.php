<?php

defined('ABSPATH') || exit;

const OYISO_TG_ORDER_NOTIFIED_META_KEY = '_oyiso_tg_notified';
const OYISO_TG_ORDER_FAILED_META_KEY = '_oyiso_tg_notify_failed_at';
const OYISO_TG_ORDER_PENDING_LOCK_TTL = 300;

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
    function oyiso_wc_cart($type, $product, $variation, $quantity, array $extra = []): string {
        $productName = '';
        if (!empty($variation)) {
            $variation_text = [];
            foreach ($variation as $attr => $value) {
                $taxonomy = str_replace('attribute_', '', $attr);
                $term = get_term_by('slug', $value, $taxonomy);
                $variation_text[] = $term ? $term->name : $value;
            }
            $baseProductName = $product->get_name();

            if ($product instanceof WC_Product_Variation) {
                $parentProduct = wc_get_product($product->get_parent_id());
                if ($parentProduct instanceof WC_Product) {
                    $baseProductName = $parentProduct->get_name();
                }
            }

            $productName = $baseProductName . ' - ' . implode(', ', $variation_text);
        } else {
            $productName = $product->get_name();
        }

        $siteName = get_bloginfo('name');
        $siteUrl = get_bloginfo('url');

        $title = '🛒购物车变更';
        $quantityLine = sprintf("<b>数量：</b>%d\n", $quantity);
        $subtotal = $product->get_price() * $quantity;

        if ($type === 'add') {
            $title = '✨加入购物车';
        } elseif ($type === 'remove') {
            $title = '😭移出购物车';
        } elseif ($type === 'increase') {
            $title = '🚀购物车加量';
            $oldQuantity = isset($extra['old_quantity']) ? (int) $extra['old_quantity'] : max(0, $quantity - 1);
            $quantityLine = sprintf("<b>数量：</b>%d → %d\n", $oldQuantity, $quantity);
        } elseif ($type === 'decrease') {
            $title = '🪫购物车减量';
            $oldQuantity = isset($extra['old_quantity']) ? (int) $extra['old_quantity'] : $quantity;
            $newQuantity = isset($extra['new_quantity']) ? (int) $extra['new_quantity'] : max(0, $oldQuantity - 1);
            $quantity = $newQuantity;
            $subtotal = $product->get_price() * $quantity;
            $quantityLine = sprintf("<b>数量：</b>%d → %d\n", $oldQuantity, $newQuantity);
        }

        $message = sprintf(
            "<b>%s【%s】：</b>\n" .
            "<b>站点：</b>%s\n" .
            "<b>产品：</b>%s\n" .
            "%s" .
            "<b>单价：</b>%s\n" .
            "<b>小计：</b>%s\n" .
            "<b>IP：</b>%s\n" .
            "<b>时间：</b>%s",
            $title,
            $siteName,
            $siteUrl,
            $productName,
            $quantityLine,
            oyiso_wc_price($product->get_price()),
            oyiso_wc_price($subtotal),
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
    $customerOverviewSection = oyiso_build_customer_overview_section($order);
    $productsSection = oyiso_build_order_products_section($order);
    $paymentShippingSection = oyiso_build_order_payment_shipping_section($order);
    $billingSection = oyiso_build_order_billing_section($order);
    $footerSection = oyiso_build_order_footer_section($order);

    return sprintf(
        "<b>🎉您有一个新订单【%s】：</b>\n" .
        "<b>站点：</b>%s\n" .
        "<b>订单号：</b>#%d\n\n" .
        "%s\n\n" .
        "%s\n\n" .
        "%s\n\n" .
        "%s\n\n" .
        "%s",
        $siteName,
        $siteUrl,
        $order->get_id(),
        $customerOverviewSection,
        $productsSection,
        $paymentShippingSection,
        $billingSection,
        $footerSection
    );
}
}

if (!function_exists('oyiso_build_customer_overview_section')) {
    function oyiso_build_customer_overview_section(WC_Order $order): string {
        $customerProfile = oyiso_get_customer_profile($order);

        return sprintf(
            "<b>📊【客户概览】：</b>\n" .
            "<b>客户类型：</b>%s\n" .
            "<b>历史下单：</b>%d 次\n" .
            "<b>历史消费：</b>%s\n" .
            "<b>客户评级：</b>%s",
            $customerProfile['customer_type'],
            $customerProfile['historical_order_count'],
            $customerProfile['historical_spend_text'],
            $customerProfile['customer_rating']
        );
    }
}

if (!function_exists('oyiso_build_order_products_section')) {
    function oyiso_build_order_products_section(WC_Order $order): string {
        $items = [];

        foreach ($order->get_items() as $item) {
            $items[] = sprintf(
                '- %s × %d',
                $item->get_name(),
                $item->get_quantity()
            );
        }

        return sprintf(
            "<b>📦【订单明细】：</b>\n%s",
            implode("\n", $items)
        );
    }
}

if (!function_exists('oyiso_build_order_payment_shipping_section')) {
    function oyiso_build_order_payment_shipping_section(WC_Order $order): string {
        return sprintf(
            "<b>🚚【支付与配送】：</b>\n" .
            "<b>支付方式：</b>%s\n" .
            "<b>配送方式：</b>%s\n" .
            "<b>金额：</b>%s\n" .
            "<b>运费：</b>%s\n" .
            "<b>总金额：</b>%s",
            $order->get_payment_method_title(),
            $order->get_shipping_method(),
            oyiso_wc_price($order->get_subtotal()),
            oyiso_wc_price($order->get_shipping_total()),
            oyiso_wc_price($order->get_total())
        );
    }
}

if (!function_exists('oyiso_build_order_billing_section')) {
    function oyiso_build_order_billing_section(WC_Order $order): string {
        $customerNote = $order->get_customer_note();

        if (empty($customerNote)) {
            $customerNote = '无';
        }

        return sprintf(
            "<b>📬【收货与联系信息】：</b>\n" .
            "<b>客户：</b>%s\n" .
            "<b>邮箱：</b>%s\n" .
            "<b>电话：</b>%s\n" .
            "<b>地址：</b>%s\n" .
            "<b>备注：</b>%s",
            $order->get_formatted_billing_full_name(),
            $order->get_billing_email(),
            $order->get_billing_phone(),
            oyiso_get_order_shipping_address_text($order),
            $customerNote
        );
    }
}

if (!function_exists('oyiso_build_order_footer_section')) {
    function oyiso_build_order_footer_section(WC_Order $order): string {
        $time = $order->get_date_created()
            ? $order->get_date_created()->date('Y-m-d H:i:s')
            : current_time('Y-m-d H:i:s');

        return sprintf(
            "<b>IP：</b>%s\n" .
            "<b>时间：</b>%s",
            oyiso_get_client_ip(),
            $time
        );
    }
}

if (!function_exists('oyiso_get_customer_profile')) {
    /**
     * 汇总当前客户的历史订单表现，用于新订单通知里的客户概览。
     *
     * @param WC_Order $order
     * @return array{customer_type:string,historical_order_count:int,historical_spend_text:string,customer_rating:string}
     */
    function oyiso_get_customer_profile(WC_Order $order): array {
        $defaultProfile = [
            'customer_type' => '新客户',
            'historical_order_count' => 0,
            'historical_spend_text' => oyiso_wc_price(0),
            'customer_rating' => '★★★☆☆ 普通',
        ];

        $queryArgs = [
            'limit' => -1,
            'return' => 'objects',
            'exclude' => [$order->get_id()],
        ];

        $customerId = (int) $order->get_customer_id();
        $billingEmail = sanitize_email((string) $order->get_billing_email());

        if ($customerId > 0) {
            $queryArgs['customer_id'] = $customerId;
        } elseif ($billingEmail !== '') {
            $queryArgs['billing_email'] = $billingEmail;
        } else {
            return $defaultProfile;
        }

        $historicalOrders = wc_get_orders($queryArgs);
        if (empty($historicalOrders)) {
            return $defaultProfile;
        }

        $historicalOrderCount = 0;
        $historicalCancelledCount = 0;
        $historicalCompletedCount = 0;
        $historicalSpend = 0.0;
        $effectiveStatuses = array_unique(array_merge(wc_get_is_paid_statuses(), ['on-hold']));

        foreach ($historicalOrders as $historicalOrder) {
            if (!$historicalOrder instanceof WC_Order) {
                continue;
            }

            $status = $historicalOrder->get_status();
            if (in_array($status, ['auto-draft', 'checkout-draft'], true)) {
                continue;
            }

            $historicalOrderCount++;

            if ($status === 'cancelled') {
                $historicalCancelledCount++;
                continue;
            }

            if (in_array($status, $effectiveStatuses, true)) {
                $historicalCompletedCount++;
                $historicalSpend += max(0, (float) $historicalOrder->get_total() - (float) $historicalOrder->get_total_refunded());
            }
        }

        $cancelRate = $historicalOrderCount > 0 ? ($historicalCancelledCount / $historicalOrderCount) : 0.0;
        $customerRating = '★★★☆☆ 普通';

        if ($historicalCompletedCount >= 5 && $cancelRate < 0.05) {
            $customerRating = '★★★★★ 优质';
        } elseif ($historicalCompletedCount >= 3 && $cancelRate < 0.1) {
            $customerRating = '★★★★☆ 良好';
        } elseif ($cancelRate < 0.3) {
            $customerRating = '★★★☆☆ 普通';
        } elseif ($cancelRate < 0.5) {
            $customerRating = '★★☆☆☆ 注意';
        } else {
            $customerRating = '★☆☆☆☆ 风险';
        }

        return [
            'customer_type' => $historicalOrderCount > 0 ? '老客户' : '新客户',
            'historical_order_count' => $historicalOrderCount,
            'historical_spend_text' => oyiso_wc_price($historicalSpend),
            'customer_rating' => $customerRating,
        ];
    }
}

if (!function_exists('oyiso_get_order_status_operator_name')) {
    function oyiso_get_order_status_operator_name(int $order_id): string {
        if (is_user_logged_in() && current_user_can('edit_shop_orders', $order_id)) {
            $user = get_user_by('id', get_current_user_id());

            if ($user && !empty($user->display_name)) {
                return $user->display_name;
            }
        }

        return __('WooCommerce', 'woocommerce');
    }
}

if (!function_exists('oyiso_get_new_order_notification_lock_key')) {
    function oyiso_get_new_order_notification_lock_key(int $order_id): string {
        return 'oyiso_tg_new_order_lock_' . $order_id;
    }
}

if (!function_exists('oyiso_acquire_new_order_notification_lock')) {
    function oyiso_acquire_new_order_notification_lock(int $order_id): ?string {
        if ($order_id <= 0) {
            return null;
        }

        $lockKey = oyiso_get_new_order_notification_lock_key($order_id);
        $now = time();
        $existing = get_option($lockKey, false);

        if ($existing !== false) {
            $lockedAt = (int) $existing;

            if ($lockedAt > 0 && ($now - $lockedAt) < OYISO_TG_ORDER_PENDING_LOCK_TTL) {
                return null;
            }

            delete_option($lockKey);
        }

        if (!add_option($lockKey, (string) $now, '', false)) {
            return null;
        }

        return $lockKey;
    }
}

if (!function_exists('oyiso_release_new_order_notification_lock')) {
    function oyiso_release_new_order_notification_lock(?string $lockKey): void {
        if (!is_string($lockKey) || $lockKey === '') {
            return;
        }

        delete_option($lockKey);
    }
}

if (!function_exists('oyiso_send_new_order_notification')) {
    function oyiso_send_new_order_notification(int $order_id): void {
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

        $pendingLockKey = oyiso_acquire_new_order_notification_lock($order_id);
        if (!$pendingLockKey) {
            return;
        }

        $message = oyiso_build_order_message($order);
        $sent = $bot->sendMessage($message, [
            'order_id'         => $order->get_id(),
            'blog_id'          => function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0,
            'success_meta_key' => OYISO_TG_ORDER_NOTIFIED_META_KEY,
            'failure_meta_key' => OYISO_TG_ORDER_FAILED_META_KEY,
            'pending_lock_key' => $pendingLockKey,
        ]);

        if (!$sent) {
            oyiso_release_new_order_notification_lock($pendingLockKey);
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
        if (!$product) return;

        $cartItem = WC()->cart ? WC()->cart->get_cart_item($cart_item_key) : [];
        $currentQuantity = (int) ($cartItem['quantity'] ?? $quantity);
        if ($currentQuantity > (int) $quantity) {
            return;
        }

        $message = oyiso_wc_cart('add', $product, $cartItem['variation'] ?? $variation, (int) $quantity);

        $bot->sendMessage($message);
    }, 10, 6);
}

/** 
 * WooCommerce 移出购物车通知
 */
if ($notify_options['woo_remove_from_cart'] ?? false) {
    add_action('woocommerce_remove_cart_item', function ($cart_item_key, $cart) {
        $bot = oyiso_get_tg_bot();
        if (!$bot) return;

        $cart_item = $cart instanceof WC_Cart ? $cart->get_cart_item($cart_item_key) : [];
        if (empty($cart_item['data']) || !($cart_item['data'] instanceof WC_Product)) {
            return;
        }

        $product   = $cart_item['data'];
        $variation = $cart_item['variation'] ?? [];
        $quantity  = (int) ($cart_item['quantity'] ?? 1);
        $message = oyiso_wc_cart('remove', $product, $variation, $quantity);
        $bot->sendMessage($message);
    }, 10, 2);
}

/**
 * WooCommerce 购物车数量调整通知
 */
if ($notify_options['woo_cart_quantity_change'] ?? false) {
    add_action('woocommerce_after_cart_item_quantity_update', function ($cart_item_key, $quantity, $old_quantity, $cart) {
        if ($quantity <= $old_quantity) {
            return;
        }

        $bot = oyiso_get_tg_bot();
        if (!$bot) return;

        $cart_item = $cart instanceof WC_Cart ? $cart->get_cart_item($cart_item_key) : [];
        if (empty($cart_item['data']) || !($cart_item['data'] instanceof WC_Product)) {
            return;
        }

        $message = oyiso_wc_cart('increase', $cart_item['data'], $cart_item['variation'] ?? [], (int) $quantity, [
            'old_quantity' => (int) $old_quantity,
        ]);
        $bot->sendMessage($message);
    }, 10, 4);

    add_action('woocommerce_after_cart_item_quantity_update', function ($cart_item_key, $quantity, $old_quantity, $cart) {
        if ($quantity >= $old_quantity || $quantity <= 0) {
            return;
        }

        $bot = oyiso_get_tg_bot();
        if (!$bot) return;

        $cart_item = $cart instanceof WC_Cart ? $cart->get_cart_item($cart_item_key) : [];
        if (empty($cart_item['data']) || !($cart_item['data'] instanceof WC_Product)) {
            return;
        }

        $message = oyiso_wc_cart('decrease', $cart_item['data'], $cart_item['variation'] ?? [], (int) $old_quantity, [
            'old_quantity' => (int) $old_quantity,
            'new_quantity' => (int) $quantity,
        ]);
        $bot->sendMessage($message);
    }, 10, 4);
}

/**
 * WooCommerce 新订单通知
 */
if ($notify_options['woo_new_order'] ?? false) {
    add_action('woocommerce_checkout_order_processed', function ($order_id) {
        oyiso_send_new_order_notification((int) $order_id);
    }, 10, 1);

    // 兼容部分支付流程仍走 thankyou，但已发送标记会防重复发送。
    add_action('woocommerce_thankyou', function ($order_id) {
        oyiso_send_new_order_notification((int) $order_id);
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
        $operatorName = oyiso_get_order_status_operator_name($order_id);
        $operatorIp = oyiso_get_client_ip();

        $message = sprintf(
            "<b>📢订单状态已改变【%s】：</b>\n" .
            "<b>站点：</b>%s\n" .
            "<b>订单号：</b>#%d\n" .
            "<b>状态：</b>%s (%s) → %s (%s)\n" .
            "<b>操作者：</b>%s\n" .
            "<b>IP：</b>%s\n" .
            "<b>时间：</b>%s",
            $siteName,
            $siteUrl,
            $order_id,
            wc_get_order_status_name($old_status),
            $old_status,
            wc_get_order_status_name($new_status),
            $new_status,
            $operatorName,
            $operatorIp,
            date_i18n('Y-m-d H:i:s')
        );

        $bot = oyiso_get_tg_bot();
        if ($bot) $bot->sendMessage($message);
    }, 10, 4);
}
