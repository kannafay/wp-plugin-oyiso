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
        "<b>订单号：</b>#%s\n\n" .
        "%s\n\n" .
        "%s\n\n" .
        "%s\n\n" .
        "%s\n\n" .
        "%s",
        $siteName,
        $siteUrl,
        $order->get_order_number(),
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
        $lines = [
            '<b>🚚【支付与配送】：</b>',
            sprintf('<b>支付方式：</b>%s', $order->get_payment_method_title()),
            sprintf('<b>配送方式：</b>%s', $order->get_shipping_method()),
            sprintf('<b>金额：</b>%s', oyiso_wc_price($order->get_subtotal())),
        ];

        $discountTotal = (float) $order->get_discount_total();
        if ($discountTotal > 0) {
            $lines[] = sprintf('<b>折扣：</b>-%s', oyiso_wc_price($discountTotal));
        }

        $lines[] = sprintf('<b>运费：</b>%s', oyiso_wc_price($order->get_shipping_total()));
        $lines[] = sprintf('<b>总金额：</b>%s', oyiso_wc_price($order->get_total()));

        return implode("\n", $lines);
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
 * 通知互斥锁管理器（内存级）
 * 确保同一次请求中，高优先级通知触发后，低优先级通知强制静默
 */
if (!class_exists('Oyiso_TG_Notification_Lock')) {
    class Oyiso_TG_Notification_Lock {
        private static array $handled_new_order = [];
        private static array $handled_shipped = [];

        public static function mark_new_order_handled(int $order_id): void {
            self::$handled_new_order[$order_id] = true;
        }

        public static function is_new_order_handled(int $order_id): bool {
            return !empty(self::$handled_new_order[$order_id]);
        }

        public static function mark_shipped_handled(int $order_id): void {
            self::$handled_shipped[$order_id] = true;
        }

        public static function is_shipped_handled(int $order_id): bool {
            return !empty(self::$handled_shipped[$order_id]);
        }
    }
}

/**
 * 钩子 1：跟随 WooCommerce 新订单邮件发送流程触发 Telegram 新订单通知。
 * 与订单邮件 HTML 截图使用相同入口，由 WooCommerce 判断何时属于新订单。
 */
add_filter('woocommerce_mail_callback_params', function (array $params, object $email) use ($notify_options): array {
    if (empty($notify_options['woo_new_order'])) {
        return $params;
    }

    if (!($email instanceof WC_Email) || 'new_order' !== (string) $email->id) {
        return $params;
    }

    if (!($email->object instanceof WC_Order)) {
        return $params;
    }

    $orderId = (int) $email->object->get_id();
    if (!Oyiso_TG_Notification_Lock::is_new_order_handled($orderId)) {
        oyiso_send_new_order_notification($orderId);
        Oyiso_TG_Notification_Lock::mark_new_order_handled($orderId);
    }

    return $params;
}, 10, 2);

/**
 * 钩子 2：处理 WooCommerce 状态流转（发货 -> 状态变更）。
 * 采用责任链模式，高优先级触发后直接 return。
 */
add_action('woocommerce_order_status_changed', function ($order_id, $old_status, $new_status, $order) use ($notify_options) {
    if (!$order instanceof WC_Order) {
        $order = wc_get_order($order_id);
    }
    if (!$order instanceof WC_Order) {
        return;
    }

    $order_id = (int) $order->get_id();

    // 1. 发货与状态变更拦截：过滤掉由 AST 自动推移的发货状态，交给下方的物流适配器去发货
    $isShippedEnabled = !empty($notify_options['woo_order_shipped']);
    $shippedChannel = $notify_options['woo_order_shipped_channel'] ?? 'status';
    
    if ($isShippedEnabled && $shippedChannel === 'ast') {
        $astShippedStatuses = ['shipped', 'partial-shipped', 'completed'];
        if (in_array($new_status, $astShippedStatuses, true)) {
            return; // 直接跳出，静默等待 AST hook 发力
        }
    }

    // 2. 优先级最低：订单状态变更兜底通知
    $isStatusChangeEnabled = !empty($notify_options['woo_order_status_change']);
    if ($isStatusChangeEnabled) {
        // 如果能走到这里，说明上面 1 和 2 都没有拦截
        // 但还需要防备其他地方 (如 HPOS 的 update 钩子或 AST 的追踪钩子) 刚刚发了发货通知
        if (Oyiso_TG_Notification_Lock::is_shipped_handled($order_id) || Oyiso_TG_Notification_Lock::is_new_order_handled($order_id)) {
            return;
        }

        // 屏蔽无意义的过渡状态
        if ($old_status === 'checkout-draft' && $new_status === 'pending') {
            return;
        }

        $siteName = get_bloginfo('name');
        $siteUrl = get_bloginfo('url');
        $operatorName = oyiso_get_order_status_operator_name($order_id);
        $operatorIp = oyiso_get_client_ip();

        $message = sprintf(
            "<b>📢订单状态已改变【%s】：</b>\n" .
            "<b>站点：</b>%s\n" .
            "<b>订单号：</b>#%s\n" .
            "<b>状态：</b>%s (%s) → %s (%s)\n" .
            "<b>操作者：</b>%s\n" .
            "<b>IP：</b>%s\n" .
            "<b>时间：</b>%s",
            $siteName,
            $siteUrl,
            $order->get_order_number(),
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
    }
}, 10, 4);

/**
 * 物流追踪适配器接口
 */
interface Oyiso_Shipping_Tracking_Adapter {
    public function get_id(): string;
    public function is_active(): bool;
    public function register_triggers(callable $callback): void;
    public function get_tracking_items(WC_Order $order): array;
}

/**
 * AST (Advanced Shipment Tracking) 适配器
 */
class Oyiso_AST_Tracking_Adapter implements Oyiso_Shipping_Tracking_Adapter {
    public function get_id(): string {
        return 'ast';
    }

    public function is_active(): bool {
        return class_exists('AST_Pro_Actions') || function_exists('wc_advanced_shipment_tracking');
    }

    public function register_triggers(callable $callback): void {
        // AST 原生 Hooks
        add_action('ast_save_tracking_details_end', function ($order_id) use ($callback) {
            $callback((int) $order_id);
        }, 10, 1);
        add_action('ast_shipment_tracking_end', function ($order_id) use ($callback) {
            $callback((int) $order_id);
        }, 10, 1);

        // 传统 post meta 和 HPOS 兼容
        add_action('updated_post_meta', function ($meta_id, $object_id, $meta_key) use ($callback) {
            if ($meta_key === '_wc_shipment_tracking_items') $callback($object_id);
        }, 10, 3);
        add_action('added_post_meta', function ($meta_id, $object_id, $meta_key) use ($callback) {
            if ($meta_key === '_wc_shipment_tracking_items') $callback($object_id);
        }, 10, 3);
        
        // 兼容不同的 AJAX 保存
        add_action('wp_ajax_wc_shipment_tracking_save_form', function () use ($callback) {
            if (!empty($_POST['order_id'])) $callback((int) $_POST['order_id']);
        }, 999);
        add_action('shutdown', function () use ($callback) {
            if (!wp_doing_ajax() || empty($_POST['order_id'])) return;
            $action = $_REQUEST['action'] ?? '';
            if (strpos($action, 'tracking') !== false || strpos($action, 'shipment') !== false) {
                $callback((int) $_POST['order_id']);
            }
        });
        
        // HPOS 兜底
        add_action('woocommerce_after_order_object_save', function ($order) use ($callback) {
            if ($order instanceof WC_Order) $callback($order->get_id());
        }, 10, 1);
    }

    public function get_tracking_items(WC_Order $order): array {
        $rawItems = $order->get_meta('_wc_shipment_tracking_items', true);
        if (!is_array($rawItems)) return [];

        $items = [];
        foreach ($rawItems as $item) {
            if (empty($item['tracking_number'])) continue;

            $provider = $item['tracking_provider'] ?? ($item['custom_tracking_provider'] ?? '');
            $trackingLink = $item['custom_tracking_link'] ?? '';
            
            if (empty($trackingLink)) {
                if (class_exists('AST_Pro_Actions')) {
                    $formatted = \AST_Pro_Actions::get_instance()->get_formatted_tracking_item($order->get_id(), $item);
                    if (!empty($formatted['formatted_tracking_link'])) $trackingLink = $formatted['formatted_tracking_link'];
                    if (!empty($formatted['formatted_tracking_provider'])) $provider = $formatted['formatted_tracking_provider'];
                } elseif (function_exists('wc_advanced_shipment_tracking') && method_exists(wc_advanced_shipment_tracking(), 'get_formatted_tracking_item')) {
                    $formatted = wc_advanced_shipment_tracking()->get_formatted_tracking_item($order->get_id(), $item);
                    if (!empty($formatted['formatted_tracking_link'])) $trackingLink = $formatted['formatted_tracking_link'];
                    if (!empty($formatted['formatted_tracking_provider'])) $provider = $formatted['formatted_tracking_provider'];
                }
            }

            $dateShipped = !empty($item['date_shipped']) ? date_i18n('Y-m-d', (int) $item['date_shipped']) : date_i18n('Y-m-d');

            $productsList = [];
            if (!empty($item['products_list']) && is_array($item['products_list'])) {
                foreach ($item['products_list'] as $pl) {
                    $pl = (object) $pl;
                    $productsList[] = [
                        'item_id' => !empty($pl->item_id) ? (int) $pl->item_id : 0,
                        'product_id' => (int) ($pl->product ?? 0),
                        'qty' => (int) ($pl->qty ?? 0),
                    ];
                }
            }

            $items[] = [
                'tracking_number' => $item['tracking_number'],
                'provider' => $provider,
                'tracking_link' => $trackingLink,
                'date_shipped' => $dateShipped,
                'shipping_note' => $item['shipping_note'] ?? '',
                'products_list' => $productsList,
            ];
        }
        return $items;
    }
}

/**
 * 官方状态 (Status) 发货适配器
 * 依靠订单状态变更来模拟一次“全发货”
 */
class Oyiso_Status_Tracking_Adapter implements Oyiso_Shipping_Tracking_Adapter {
    public function get_id(): string {
        return 'status';
    }

    public function is_active(): bool {
        return true; // 原生状态机制永远可用
    }

    public function register_triggers(callable $callback): void {
        // 对于状态模式，我们监听订单状态的改变
        add_action('woocommerce_order_status_changed', function ($order_id, $old_status, $new_status, $order) use ($callback) {
            $options = get_option('oyiso', []);
            $notifyOpts = $options['woo_notify_options'] ?? [];
            
            // 防御性判断：只有在后台选择的是 status 模式才执行
            if (($notifyOpts['woo_order_shipped_channel'] ?? 'status') !== 'status') {
                return;
            }

            $shippedStatuses = (array) ($notifyOpts['woo_order_shipped_status'] ?? ['completed']);
            $cleanShippedStatuses = array_map(function ($s) { return str_replace('wc-', '', $s); }, $shippedStatuses);

            if (in_array($new_status, $cleanShippedStatuses, true)) {
                // 如果内存锁还没打上，说明没发过发货通知
                if (!Oyiso_TG_Notification_Lock::is_shipped_handled((int) $order_id)) {
                    $callback((int) $order_id);
                }
            }
        }, 9, 4); // 优先级 9，赶在状态改变兜底通知之前
    }

    public function get_tracking_items(WC_Order $order): array {
        // 状态模式下，没有物理追踪号。我们“伪造”一条全发货记录。
        // 为了防重（基于追踪号），我们生成一个虚拟的单号
        $virtual_tracking_number = 'STATUS-' . current_time('U');
        
        $productsList = [];
        foreach ($order->get_items() as $itemId => $item) {
            $productsList[] = [
                'item_id' => (int) $itemId,
                'product_id' => (int) ($item->get_variation_id() ?: $item->get_product_id()),
                'qty' => (int) $item->get_quantity(),
            ];
        }

        return [[
            'tracking_number' => $virtual_tracking_number,
            'provider' => '系统状态',
            'tracking_link' => '',
            'date_shipped' => date_i18n('Y-m-d'),
            'shipping_note' => '基于订单状态自动触发发货',
            'products_list' => $productsList,
        ]];
    }
}

/**
 * 物流适配器工厂
 */
class Oyiso_Shipping_Adapter_Factory {
    public static function get_adapter(string $channel): ?Oyiso_Shipping_Tracking_Adapter {
        if ($channel === 'ast') {
            return new Oyiso_AST_Tracking_Adapter();
        } elseif ($channel === 'status') {
            return new Oyiso_Status_Tracking_Adapter();
        }
        return null;
    }
}

/**
 * WooCommerce 物流追踪通知
 * 监听物流适配器抛出的事件，通过标准化接口获取数据
 */
if (!function_exists('oyiso_check_and_send_tracking_notification')) {
    function oyiso_check_and_send_tracking_notification(int $order_id, Oyiso_Shipping_Tracking_Adapter $adapter): void {
        static $processed = [];
        if (isset($processed[$order_id])) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            return;
        }

        // 调用适配器的标准化接口获取追踪数据
        $trackingItems = $adapter->get_tracking_items($order);
        if (empty($trackingItems)) {
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
        Oyiso_TG_Notification_Lock::mark_shipped_handled($order_id);

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

            $provider = $trackingItem['provider'] ?? '';
            $dateShipped = $trackingItem['date_shipped'] ?? '';
            $trackingLink = $trackingItem['tracking_link'] ?? '';
            $trackingLinkLine = !empty($trackingLink) ? sprintf("<b>查询物流：</b>%s\n", $trackingLink) : '';

            // 有未发货或有之前已发货的记录 = 分批发货，显示包裹编号
            $isPartialShipment = !empty($unshippedLines) || !empty($prevLines);
            $packageLine = $isPartialShipment ? sprintf("\n<b>包裹：</b>第 %d 件", $packageNumber) : '';

            $shippingNote = !empty($trackingItem['shipping_note']) ? sprintf("\n<b>备注：</b>%s", $trackingItem['shipping_note']) : '';

            // 如果是官方状态模式，或者根本没有物流商信息，则隐藏物流区块
            $isStatusAdapter = ($adapter->get_id() === 'status');
            $trackingSection = '';
            
            if (!$isStatusAdapter && (!empty($provider) || !empty($trackingNumber))) {
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
            }

            // 动态标题逻辑 (基于发货轮次与是否收尾)
            $isFirstShipment = empty($prevLines);   // 是不是第一次发货（之前没有发过的包裹）
            $isFullyShipped = empty($unshippedLines); // 本次发货后，是不是已经全部发完了

            if ($isFirstShipment && $isFullyShipped) {
                // 第一次发，且全部发完
                $shippedTitle = '订单已发货';
            } elseif ($isFirstShipment && !$isFullyShipped) {
                // 第一次发，但只发了一部分
                $shippedTitle = '订单已部分发货';
            } elseif (!$isFirstShipment && !$isFullyShipped) {
                // 第 N 次追加发货，但依然还有没发完的
                $shippedTitle = '追加部分发货包裹';
            } else { // (!$isFirstShipment && $isFullyShipped)
                // 第 N 次追加发货，且这次终于全发完了（或者像重新发货一样多次全发）
                $shippedTitle = '追加包裹 (已全部发货)';
            }

            $message = sprintf(
                "<b>🚚 %s【%s】：</b>\n" .
                "<b>站点：</b>%s\n" .
                "<b>订单号：</b>#%s\n" .
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
                $order->get_order_number(),
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

// 初始化并注册所有启用的物流适配器
$options = get_option('oyiso', []);
$notifyOpts = $options['woo_notify_options'] ?? [];
if (!empty($notifyOpts['woo_order_shipped'])) {
    $shippedChannel = $notifyOpts['woo_order_shipped_channel'] ?? 'status';
    if ($shippedChannel !== 'status') {
        $adapter = Oyiso_Shipping_Adapter_Factory::get_adapter($shippedChannel);
        if ($adapter && $adapter->is_active()) {
            $adapter->register_triggers(function($order_id) use ($adapter) {
                oyiso_check_and_send_tracking_notification((int) $order_id, $adapter);
            });
        }
    }
}
