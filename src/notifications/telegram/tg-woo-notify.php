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

if (!function_exists('oyiso_format_telegram_email_text')) {
function oyiso_format_telegram_email_text(string $email): string {
    $email = sanitize_email($email);

    if ($email === '') {
        return '';
    }

    $parts = preg_split('//u', $email, -1, PREG_SPLIT_NO_EMPTY);
    if (empty($parts)) {
        return $email;
    }

    return implode('&#8203;', $parts);
}
}

/**
 * 获取加购来源页面 URL
 */
if (!function_exists('oyiso_get_cart_source_page')) {
    function oyiso_get_cart_source_page(): string {
        $referer = wp_get_referer();
        if (empty($referer)) {
            $referer = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])) : '';
        }
        return $referer ?: '';
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

        $sourceLine = '';
        if (!empty($extra['source_page'])) {
            $sourceLine = sprintf("<b>来源：</b>%s\n", $extra['source_page']);
        }

        $message = sprintf(
            "<b>%s【%s】：</b>\n" .
            "<b>站点：</b>%s\n" .
            "<b>产品：</b>%s\n" .
            "%s" .
            "<b>单价：</b>%s\n" .
            "<b>小计：</b>%s\n" .
            "%s" .
            "<b>IP：</b>%s\n" .
            "<b>时间：</b>%s",
            $title,
            $siteName,
            $siteUrl,
            $productName,
            $quantityLine,
            oyiso_wc_price($product->get_price()),
            oyiso_wc_price($subtotal),
            $sourceLine,
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
            "<b>客户阶段：</b>%s\n" .
            "<b>历史有效下单：</b>%d 次\n" .
            "<b>历史有效消费：</b>%s\n" .
            "<b>客户评级：</b>%s",
            $customerProfile['customer_stage'],
            $customerProfile['historical_effective_order_count'],
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
            "<b>📦【产品明细】：</b>\n%s",
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
            oyiso_format_telegram_email_text((string) $order->get_billing_email()),
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

if (!function_exists('oyiso_get_customer_profile_effective_statuses')) {
    function oyiso_get_customer_profile_effective_statuses(): array {
        return array_unique(array_merge(wc_get_is_paid_statuses(), ['on-hold']));
    }
}

if (!function_exists('oyiso_get_order_created_timestamp')) {
    function oyiso_get_order_created_timestamp(WC_Order $order): ?int {
        $dateCreated = $order->get_date_created();

        if (!$dateCreated) {
            return null;
        }

        return (int) $dateCreated->getTimestamp();
    }
}

if (!function_exists('oyiso_resolve_customer_stage')) {
    /**
     * 基于历史有效订单次数和时间跨度生成更贴近业务语义的客户阶段。
     */
    function oyiso_resolve_customer_stage(
        int $historicalEffectiveOrderCount,
        ?int $firstEffectiveOrderTimestamp,
        ?int $latestEffectiveOrderTimestamp,
        ?int $currentOrderTimestamp
    ): string {
        if ($historicalEffectiveOrderCount <= 0) {
            return '首购客户';
        }

        if ($historicalEffectiveOrderCount <= 2) {
            return '回购客户';
        }

        if ($firstEffectiveOrderTimestamp === null || $currentOrderTimestamp === null) {
            return '回购客户';
        }

        $firstOrderAgeInDays = (int) floor(max(0, $currentOrderTimestamp - $firstEffectiveOrderTimestamp) / DAY_IN_SECONDS);
        if ($firstOrderAgeInDays < 30) {
            return '回购客户';
        }

        if ($latestEffectiveOrderTimestamp !== null) {
            $lastOrderGapInDays = (int) floor(max(0, $currentOrderTimestamp - $latestEffectiveOrderTimestamp) / DAY_IN_SECONDS);

            if ($lastOrderGapInDays >= 90) {
                return '回流客户';
            }
        }

        return '老客户';
    }
}

if (!function_exists('oyiso_get_customer_profile')) {
    /**
     * 汇总当前客户的历史订单表现，用于新订单通知里的客户概览。
     *
     * @param WC_Order $order
     * @return array{customer_stage:string,historical_effective_order_count:int,historical_spend_text:string,customer_rating:string}
     */
    function oyiso_get_customer_profile(WC_Order $order): array {
        $defaultProfile = [
            'customer_stage' => '首购客户',
            'historical_effective_order_count' => 0,
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
        $historicalEffectiveOrderCount = 0;
        $historicalSpend = 0.0;
        $firstEffectiveOrderTimestamp = null;
        $latestEffectiveOrderTimestamp = null;
        $effectiveStatuses = oyiso_get_customer_profile_effective_statuses();

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
                $historicalEffectiveOrderCount++;
                $historicalSpend += max(0, (float) $historicalOrder->get_total() - (float) $historicalOrder->get_total_refunded());

                $createdTimestamp = oyiso_get_order_created_timestamp($historicalOrder);
                if ($createdTimestamp !== null) {
                    if ($firstEffectiveOrderTimestamp === null || $createdTimestamp < $firstEffectiveOrderTimestamp) {
                        $firstEffectiveOrderTimestamp = $createdTimestamp;
                    }

                    if ($latestEffectiveOrderTimestamp === null || $createdTimestamp > $latestEffectiveOrderTimestamp) {
                        $latestEffectiveOrderTimestamp = $createdTimestamp;
                    }
                }
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

        $currentOrderTimestamp = oyiso_get_order_created_timestamp($order);

        return [
            'customer_stage' => oyiso_resolve_customer_stage(
                $historicalEffectiveOrderCount,
                $firstEffectiveOrderTimestamp,
                $latestEffectiveOrderTimestamp,
                $currentOrderTimestamp
            ),
            'historical_effective_order_count' => $historicalEffectiveOrderCount,
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

if (!function_exists('oyiso_get_new_order_notification_target_statuses')) {
    function oyiso_get_new_order_notification_target_statuses(): array {
        return ['processing', 'on-hold', 'completed'];
    }
}

if (!function_exists('oyiso_should_send_new_order_notification_for_status_change')) {
    function oyiso_should_send_new_order_notification_for_status_change(string $old_status, string $new_status, WC_Order $order): bool {
        if ($order->get_meta(OYISO_TG_ORDER_NOTIFIED_META_KEY, true)) {
            return false;
        }

        $targetStatuses = oyiso_get_new_order_notification_target_statuses();

        if (!in_array($new_status, $targetStatuses, true)) {
            return false;
        }

        if (in_array($old_status, $targetStatuses, true)) {
            return false;
        }

        return true;
    }
}

if (!function_exists('oyiso_should_skip_order_status_change_notification')) {
    function oyiso_should_skip_order_status_change_notification(
        string $old_status,
        string $new_status,
        bool $newOrderNotificationHandled
    ): bool {
        if ($newOrderNotificationHandled) {
            return true;
        }

        return $old_status === 'checkout-draft' && $new_status === 'pending';
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

        $message = oyiso_wc_cart('add', $product, $cartItem['variation'] ?? $variation, (int) $quantity, [
            'source_page' => oyiso_get_cart_source_page(),
        ]);

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
 * WooCommerce 购物车加量通知
 */
if ($notify_options['woo_cart_quantity_increase'] ?? false) {
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
}

/**
 * WooCommerce 购物车减量通知
 */
if ($notify_options['woo_cart_quantity_decrease'] ?? false) {
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
 * WooCommerce 订单状态变更通知
 */
if (($notify_options['woo_new_order'] ?? false) || ($notify_options['woo_order_shipped'] ?? false) || ($notify_options['woo_order_status_change'] ?? false)) {
    add_action('woocommerce_order_status_changed', function ($order_id, $old_status, $new_status, $order) use ($notify_options) {
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order_id);
        }

        if (!$order instanceof WC_Order) {
            return;
        }

        $isNewOrderNotificationEnabled = (bool) ($notify_options['woo_new_order'] ?? false);
        $isShippedNotificationEnabled = (bool) ($notify_options['woo_order_shipped'] ?? false);
        $isOrderStatusChangeNotificationEnabled = (bool) ($notify_options['woo_order_status_change'] ?? false);
        $newOrderNotificationHandled = false;
        $shippedNotificationHandled = false;

        if (
            $isNewOrderNotificationEnabled
            && oyiso_should_send_new_order_notification_for_status_change($old_status, $new_status, $order)
        ) {
            oyiso_send_new_order_notification((int) $order_id);
            $newOrderNotificationHandled = true;
        }

        $shippedChannel = $notify_options['woo_order_shipped_channel'] ?? 'status';
        $shippedStatuses = $notify_options['woo_order_shipped_status'] ?? ['completed'];
        if (!is_array($shippedStatuses)) {
            $shippedStatuses = [$shippedStatuses];
        }
        $registeredStatuses = function_exists('wc_get_order_statuses') ? array_keys(wc_get_order_statuses()) : [];
        $validShippedStatuses = array_filter($shippedStatuses, function ($s) use ($registeredStatuses) {
            return in_array('wc-' . $s, $registeredStatuses, true);
        });
        $isShippedStatusChange = in_array($new_status, $validShippedStatuses, true);
        $canShippedNotificationTrigger = $isShippedNotificationEnabled && (
            ($shippedChannel === 'status' && $isShippedStatusChange)
            || ($shippedChannel === 'ast' && (class_exists('AST_Pro_Actions') || function_exists('wc_advanced_shipment_tracking')))
        );
        if ($isShippedNotificationEnabled && $shippedChannel === 'status' && $isShippedStatusChange) {
            $bot = oyiso_get_tg_bot();
            if ($bot) {
                $siteName = get_bloginfo('name');
                $siteUrl = get_bloginfo('url');

                $items = [];
                foreach ($order->get_items() as $item) {
                    $items[] = sprintf('- %s × %d', $item->get_name(), $item->get_quantity());
                }
                $productsText = implode("\n", $items);

                $message = sprintf(
                    "<b>🚚 订单已发货【%s】：</b>\n" .
                    "<b>站点：</b>%s\n" .
                    "<b>订单号：</b>#%d\n" .
                    "<b>客户：</b>%s\n" .
                    "<b>邮箱：</b>%s\n" .
                    "%s" .
                    "<b>地址：</b>%s\n\n" .
                    "<b>📦【产品明细】：</b>\n%s\n\n" .
                    "<b>金额：</b>%s\n" .
                    "<b>运费：</b>%s\n" .
                    "<b>总金额：</b>%s\n\n" .
                    "<b>操作者：</b>%s\n" .
                    "<b>时间：</b>%s",
                    $siteName,
                    $siteUrl,
                    $order_id,
                    $order->get_formatted_billing_full_name(),
                    oyiso_format_telegram_email_text((string) $order->get_billing_email()),
                    !empty($order->get_billing_phone()) ? sprintf("<b>电话：</b>%s\n", $order->get_billing_phone()) : '',
                    oyiso_get_order_shipping_address_text($order),
                    $productsText,
                    oyiso_wc_price($order->get_subtotal()),
                    oyiso_wc_price($order->get_shipping_total()),
                    oyiso_wc_price($order->get_total()),
                    oyiso_get_order_status_operator_name($order_id),
                    date_i18n('Y-m-d H:i:s')
                );

                $bot->sendMessage($message);
                $shippedNotificationHandled = true;
            }
        }

        if (
            !$isOrderStatusChangeNotificationEnabled
            || oyiso_should_skip_order_status_change_notification($old_status, $new_status, $newOrderNotificationHandled)
            || $shippedNotificationHandled
            || ($canShippedNotificationTrigger && $isShippedStatusChange)
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

/**
 * WooCommerce 物流追踪通知 (AST)
 * 监听 _wc_shipment_tracking_items meta 变化，每新增一条追踪号发送一次通知
 * 兼容传统 post meta 和 HPOS 模式
 */
if (!function_exists('oyiso_check_and_send_tracking_notification')) {
    function oyiso_check_and_send_tracking_notification($order_id, $tracking_items_override = null): void {
        static $processed = [];
        if (isset($processed[$order_id])) {
            return;
        }

        $options = get_option('oyiso', []);
        $notifyOpts = $options['woo_notify_options'] ?? [];
        if (empty($notifyOpts['woo_order_shipped']) || ($notifyOpts['woo_order_shipped_channel'] ?? 'status') !== 'ast') {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            return;
        }

        // 优先使用 hook 传入的最新值，否则从 order 对象读取（兼容 HPOS）
        $trackingItems = is_array($tracking_items_override) ? $tracking_items_override : $order->get_meta('_wc_shipment_tracking_items', true);
        if (!is_array($trackingItems) || empty($trackingItems)) {
            return;
        }

        // 获取当前所有追踪号
        $currentNumbers = [];
        foreach ($trackingItems as $item) {
            if (!empty($item['tracking_number'])) {
                $currentNumbers[] = $item['tracking_number'];
            }
        }
        if (empty($currentNumbers)) {
            return;
        }

        // 获取上次已知的追踪号集合，对比找出新增的
        $knownNumbers = $order->get_meta('_oyiso_tg_known_tracking_numbers', true);
        if (!is_array($knownNumbers)) {
            $knownNumbers = [];
        }
        $newNumbers = array_diff($currentNumbers, $knownNumbers);

        // 即使没有新增，也同步已知集合（处理删除的情况）
        if ($currentNumbers !== $knownNumbers && empty($newNumbers)) {
            $order->update_meta_data('_oyiso_tg_known_tracking_numbers', $currentNumbers);
            $order->save_meta_data();
            return;
        }
        if (empty($newNumbers)) {
            return;
        }

        $bot = oyiso_get_tg_bot();
        if (!$bot) {
            return;
        }

        $processed[$order_id] = true;

        $siteName = get_bloginfo('name');
        $siteUrl = get_bloginfo('url');
        $currentCount = count($trackingItems);

        // 构建订单商品信息映射（item_id => [name, qty]）
        $orderItems = [];
        foreach ($order->get_items() as $itemId => $item) {
            $orderItems[$itemId] = [
                'name' => $item->get_name(),
                'qty' => $item->get_quantity(),
                'product_id' => $item->get_variation_id() ?: $item->get_product_id(),
            ];
        }

        // 辅助函数：从 products_list 提取 item_id => qty 映射
        $extractQty = function ($productsList) use ($orderItems) {
            $map = [];
            if (empty($productsList) || !is_array($productsList)) {
                return $map;
            }
            foreach ($productsList as $pl) {
                $pl = (object) $pl;
                $itemId = !empty($pl->item_id) ? (int) $pl->item_id : 0;
                $productId = (int) ($pl->product ?? 0);
                $qty = (int) $pl->qty;
                if ($itemId && isset($orderItems[$itemId])) {
                    $map[$itemId] = ($map[$itemId] ?? 0) + $qty;
                } else {
                    foreach ($orderItems as $oId => $oItem) {
                        if ($oItem['product_id'] == $productId) {
                            $map[$oId] = ($map[$oId] ?? 0) + $qty;
                            break;
                        }
                    }
                }
            }
            return $map;
        };

        // 汇总所有追踪号已发货的商品数量
        $allShippedQty = [];
        foreach ($trackingItems as $tItem) {
            foreach ($extractQty($tItem['products_list'] ?? '') as $k => $v) {
                $allShippedQty[$k] = ($allShippedQty[$k] ?? 0) + $v;
            }
        }

        $packageNumber = 0;
        foreach ($trackingItems as $idx => $trackingItem) {
            $trackingNumber = $trackingItem['tracking_number'] ?? '';
            if (empty($trackingNumber) || !in_array($trackingNumber, $newNumbers, true)) {
                continue;
            }

            $packageNumber = $idx + 1;

            $productsList = $trackingItem['products_list'] ?? '';
            $currentLines = [];
            $prevLines = [];
            $unshippedLines = [];

            if (!empty($productsList) && is_array($productsList)) {
                // 本次发货的商品数量
                $currentQty = $extractQty($productsList);

                // 之前发货的 = 全部已发 - 本次
                $prevShippedQty = [];
                foreach ($allShippedQty as $oId => $totalQty) {
                    $thisQty = $currentQty[$oId] ?? 0;
                    $prev = $totalQty - $thisQty;
                    if ($prev > 0) {
                        $prevShippedQty[$oId] = $prev;
                    }
                }

                // 当前发货
                foreach ($currentQty as $oId => $qty) {
                    if (isset($orderItems[$oId]) && $qty > 0) {
                        $currentLines[] = sprintf('- %s × %d', $orderItems[$oId]['name'], $qty);
                    }
                }

                // 已发货（之前的包裹）
                foreach ($prevShippedQty as $oId => $qty) {
                    if (isset($orderItems[$oId])) {
                        $prevLines[] = sprintf('- %s × %d', $orderItems[$oId]['name'], $qty);
                    }
                }

                // 未发货
                foreach ($orderItems as $oId => $oItem) {
                    $totalShipped = $allShippedQty[$oId] ?? 0;
                    if ($totalShipped === 0) {
                        $totalShipped = $allShippedQty[$oItem['product_id']] ?? 0;
                    }
                    $remaining = $oItem['qty'] - $totalShipped;
                    if ($remaining > 0) {
                        $unshippedLines[] = sprintf('- %s × %d', $oItem['name'], $remaining);
                    }
                }
            } else {
                // 无 products_list：整单发货
                foreach ($orderItems as $oItem) {
                    $currentLines[] = sprintf('- %s × %d', $oItem['name'], $oItem['qty']);
                }
            }

            // 拼接产品区域
            $productsText = implode("\n", $currentLines);
            if (!empty($prevLines)) {
                $productsText .= "\n\n<b>✅【已发货】：</b>\n" . implode("\n", $prevLines);
            }
            if (!empty($unshippedLines)) {
                $productsText .= "\n\n<b>⏳【未发货】：</b>\n" . implode("\n", $unshippedLines);
            }

            $provider = $trackingItem['tracking_provider'] ?? ($trackingItem['custom_tracking_provider'] ?? '');
            $dateShipped = !empty($trackingItem['date_shipped'])
                ? date_i18n('Y-m-d', (int) $trackingItem['date_shipped'])
                : date_i18n('Y-m-d');

            $trackingLink = '';
            $formatted = null;
            if (!empty($trackingItem['custom_tracking_link'])) {
                $trackingLink = $trackingItem['custom_tracking_link'];
            } else {
                // AST Pro
                if (class_exists('AST_Pro_Actions')) {
                    $formatted = AST_Pro_Actions::get_instance()->get_formatted_tracking_item($order->get_id(), $trackingItem);
                    if (!empty($formatted['formatted_tracking_link'])) {
                        $trackingLink = $formatted['formatted_tracking_link'];
                    }
                }
                // AST 免费版
                if (empty($trackingLink) && function_exists('wc_advanced_shipment_tracking') && method_exists(wc_advanced_shipment_tracking(), 'get_formatted_tracking_item')) {
                    $formatted = wc_advanced_shipment_tracking()->get_formatted_tracking_item($order->get_id(), $trackingItem);
                    if (!empty($formatted['formatted_tracking_link'])) {
                        $trackingLink = $formatted['formatted_tracking_link'];
                    }
                }
            }

            // 优先使用物流商显示名称（标签），而非 slug
            if (!empty($formatted['formatted_tracking_provider'])) {
                $provider = $formatted['formatted_tracking_provider'];
            }

            $trackingLinkLine = '';
            if (!empty($trackingLink)) {
                $trackingLinkLine = sprintf("<b>查询物流：</b>%s\n", $trackingLink);
            }

            // 有未发货或有之前已发货的记录 = 分批发货，显示包裹编号
            $isPartialShipment = !empty($unshippedLines) || !empty($prevLines);
            $packageLine = $isPartialShipment ? sprintf("\n<b>包裹：</b>第 %d 件", $packageNumber) : '';

            $shippingNote = !empty($trackingItem['shipping_note']) ? sprintf("\n<b>备注：</b>%s", $trackingItem['shipping_note']) : '';

            $trackingSection = sprintf(
                "\n<b>🚛【物流信息】：</b>\n" .
                "<b>物流商：</b>%s\n" .
                "<b>运单号：</b>%s\n" .
                "%s" .
                "<b>发货日期：</b>%s" .
                "%s" .
                "%s",
                $provider,
                $trackingNumber,
                $trackingLinkLine,
                $dateShipped,
                $packageLine,
                $shippingNote
            );

            // 动态标题：部分发货 / 全部发货 / 已发货
            if (!empty($unshippedLines)) {
                $shippedTitle = '订单已部分发货';
            } elseif (!empty($prevLines)) {
                $shippedTitle = '订单已全部发货';
            } else {
                $shippedTitle = '订单已发货';
            }

            $message = sprintf(
                "<b>🚚 %s【%s】：</b>\n" .
                "<b>站点：</b>%s\n" .
                "<b>订单号：</b>#%d\n" .
                "<b>客户：</b>%s\n" .
                "<b>邮箱：</b>%s\n" .
                "%s" .
                "<b>地址：</b>%s\n\n" .
                "<b>📦【发货产品】：</b>\n%s\n\n" .
                "<b>金额：</b>%s\n" .
                "<b>运费：</b>%s\n" .
                "<b>总金额：</b>%s\n" .
                "%s\n\n" .
                "<b>操作者：</b>%s\n" .
                "<b>时间：</b>%s",
                $shippedTitle,
                $siteName,
                $siteUrl,
                $order->get_id(),
                $order->get_formatted_billing_full_name(),
                oyiso_format_telegram_email_text((string) $order->get_billing_email()),
                !empty($order->get_billing_phone()) ? sprintf("<b>电话：</b>%s\n", $order->get_billing_phone()) : '',
                oyiso_get_order_shipping_address_text($order),
                $productsText,
                oyiso_wc_price($order->get_subtotal()),
                oyiso_wc_price($order->get_shipping_total()),
                oyiso_wc_price($order->get_total()),
                $trackingSection,
                ($currentUser = wp_get_current_user()) && $currentUser->ID ? $currentUser->display_name : '系统',
                date_i18n('Y-m-d H:i:s')
            );

            $bot->sendMessage($message);
        }

        // 保存当前追踪号集合（删除后集合会更新，重新添加就能再次触发）
        $order->update_meta_data('_oyiso_tg_known_tracking_numbers', $currentNumbers);
        $order->save_meta_data();
    }
}

// 方式1: 传统 post meta hooks（兼容 legacy + HPOS 兼容模式）
add_action('updated_post_meta', function ($meta_id, $object_id, $meta_key, $meta_value) {
    if ($meta_key !== '_wc_shipment_tracking_items') return;
    $items = is_string($meta_value) ? maybe_unserialize($meta_value) : $meta_value;
    oyiso_check_and_send_tracking_notification($object_id, is_array($items) ? $items : null);
}, 10, 4);
add_action('added_post_meta', function ($meta_id, $object_id, $meta_key, $meta_value) {
    if ($meta_key !== '_wc_shipment_tracking_items') return;
    $items = is_string($meta_value) ? maybe_unserialize($meta_value) : $meta_value;
    oyiso_check_and_send_tracking_notification($object_id, is_array($items) ? $items : null);
}, 10, 4);

// 方式2: WooCommerce order status changed 时也检查一次（兜底）
add_action('woocommerce_order_status_changed', function ($order_id) {
    oyiso_check_and_send_tracking_notification($order_id);
}, 20, 1);

// 方式3: 在 admin AJAX 请求结束时检查（兼容 HPOS 无兼容模式）
add_action('wp_ajax_wc_shipment_tracking_save_form', function () {
    if (!empty($_POST['order_id'])) {
        oyiso_check_and_send_tracking_notification((int) $_POST['order_id']);
    }
}, 999);
add_action('shutdown', function () {
    if (!wp_doing_ajax() || empty($_POST['order_id'])) return;
    $action = $_REQUEST['action'] ?? '';
    if (strpos($action, 'tracking') === false && strpos($action, 'shipment') === false) return;
    oyiso_check_and_send_tracking_notification((int) $_POST['order_id']);
});
