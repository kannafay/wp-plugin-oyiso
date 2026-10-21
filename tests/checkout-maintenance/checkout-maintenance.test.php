<?php

declare(strict_types=1);

// Run with PHP CLI against a local WordPress installation. Options are overridden only in this process.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$pluginRoot = dirname(__DIR__, 2);
$wordpressRoot = getenv('OYISO_WP_ROOT') ?: dirname($pluginRoot, 3);
require $wordpressRoot . '/wp-load.php';
require_once $pluginRoot . '/src/plugin-extensions/woocommerce-checkout-maintenance/index.php';

if (!WC()->session) {
    WC()->initialize_session();
}
if (!WC()->cart) {
    WC()->initialize_cart();
}

$options = [];
$overrideOptions = static function () use (&$options): array { return $options; };
add_filter('pre_option_oyiso', $overrideOptions);
$assertions = 0;
$check = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$countOrders = static function (): int {
    global $wpdb;
    $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('shop_order', 'shop_order_refund')");
    $orderTable = $wpdb->prefix . 'wc_orders';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($orderTable))) === $orderTable) {
        $count += (int) $wpdb->get_var("SELECT COUNT(*) FROM {$orderTable}");
    }
    return $count;
};

$ordersBefore = $countOrders();
$originalNotices = wc_get_notices();
$originalRequest = $_REQUEST;
$server = rest_get_server();

try {
    $check(!Oyiso_WC_Checkout_Maintenance::isEnabled(), 'Maintenance must be disabled by default.');
    $defaults = Oyiso_WC_Checkout_Maintenance::getDefaults();
    $check(Oyiso_WC_Checkout_Maintenance::getSettings() === $defaults, 'Missing settings must use the English defaults.');
    $check(Oyiso_WC_Checkout_Maintenance::sanitizeSettings(null) === $defaults, 'Malformed settings must use defaults.');
    $check(Oyiso_WC_Checkout_Maintenance::sanitizeSettings(['message' => '  ']) === $defaults, 'An empty message must retain a readable fallback.');
    $status = rest_do_request(new WP_REST_Request('GET', '/oyiso/v1/checkout-maintenance'));
    $check($status->get_data() === ['enabled' => false, 'settings' => null], 'The public status route must report disabled maintenance.');
    $check(str_contains($status->get_headers()['Cache-Control'] ?? '', 'no-store'), 'Maintenance status must not be cached.');

    foreach ([false, 0, '0', 'false', [], null] as $disabled) {
        $options = ['oyiso_wc_checkout_maintenance_enabled' => $disabled];
        $check(!Oyiso_WC_Checkout_Maintenance::isEnabled(), 'A false setting must not enable maintenance.');
        $request = new WP_REST_Request('POST', '/wc/store/v1/checkout');
        $check(Oyiso_WC_Checkout_Maintenance::validateStoreApi(null, $server, $request) === null, 'Disabled maintenance must allow checkout.');
        Oyiso_WC_Checkout_Maintenance::validateClassic();
    }

    $options = [
        'oyiso_wc_checkout_maintenance_enabled' => true,
        'oyiso_wc_checkout_maintenance_options' => [
            'template' => 'unknown',
            'title' => '<b>Temporarily closed</b>',
            'message' => "Custom maintenance message.\nPlease come back later.",
            'button' => 'Understood',
        ],
    ];
    $settings = Oyiso_WC_Checkout_Maintenance::getSettings();
    $check($settings['title'] === 'Temporarily closed', 'Customer-facing copy must be sanitized.');
    $check($settings['message'] === $options['oyiso_wc_checkout_maintenance_options']['message'], 'Custom text and line breaks must survive.');
    $check($settings['template'] === 'default', 'An unknown template must fall back to the default.');
    $check(array_keys(Oyiso_WC_Checkout_Maintenance::getTemplateOptions()) === ['default', 'minimal', 'soft', 'dark'], 'All four templates must be available in settings.');
    foreach ([[], null, 42, 'missing', '__proto__'] as $invalidTemplate) {
        $check(Oyiso_WC_Checkout_Maintenance::sanitizeSettings(['template' => $invalidTemplate])['template'] === 'default', 'Invalid template values must safely fall back.');
    }
    foreach (['default', 'minimal', 'soft', 'dark'] as $template) {
        $options['oyiso_wc_checkout_maintenance_options']['template'] = $template;
        $check(Oyiso_WC_Checkout_Maintenance::getSettings()['template'] === $template, 'A valid template must survive option sanitization.');
        $templateStatus = rest_do_request(new WP_REST_Request('GET', '/oyiso/v1/checkout-maintenance'))->get_data();
        $check(($templateStatus['settings']['template'] ?? '') === $template, 'The status route must return the selected template.');
        $templateResponse = rest_do_request(new WP_REST_Request('POST', '/wc/store/v1/checkout'));
        $templateBody = $templateResponse->get_data();
        $check($templateResponse->get_status() === 503 && ($templateBody['data']['oyiso_checkout_maintenance']['template'] ?? '') === $template, 'Every template must keep Store API submission blocked.');
        $noticeTemplate = null;
        try {
            Oyiso_WC_Checkout_Maintenance::validateClassic();
        } catch (Exception $error) {
            if (preg_match('/data-oyiso-checkout-maintenance="([^"]+)"/', $error->getMessage(), $match)) {
                $noticeSettings = json_decode(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
                $noticeTemplate = $noticeSettings['template'] ?? null;
            }
        }
        $check($noticeTemplate === $template, 'The classic rejection notice must carry the selected template.');
    }
    $options['oyiso_wc_checkout_maintenance_options']['template'] = 'unknown';
    $status = rest_do_request(new WP_REST_Request('GET', '/oyiso/v1/checkout-maintenance'));
    $check($status->get_data() === ['enabled' => true, 'settings' => $settings], 'The status route must return current PHP settings without submitting an order.');

    foreach (['/wc/store/v1/checkout', '/wc/store/v1/checkout/', '/wc/store/checkout', '/wc/store/v2/checkout', '/wc/store/v1/checkout/123'] as $route) {
        $response = rest_do_request(new WP_REST_Request('POST', $route));
        $body = $response->get_data();
        $check($response->get_status() === 503, 'Direct Store API submission must be blocked: ' . $route);
        $check(($body['code'] ?? '') === 'oyiso_checkout_maintenance', 'Store API must return a recognizable maintenance error.');
        $check(($body['data']['oyiso_checkout_maintenance'] ?? null) === $settings, 'The popup must receive the current server settings.');
    }

    foreach (['GET', 'PUT', 'PATCH', 'OPTIONS', 'DELETE'] as $method) {
        $request = new WP_REST_Request($method, '/wc/store/v1/checkout');
        $check(Oyiso_WC_Checkout_Maintenance::validateStoreApi(null, $server, $request) === null, 'Non-submission requests must remain available: ' . $method);
    }
    foreach (['/wc/store/v1/cart/add-item', '/wc/store/v1/cart/update-customer', '/wc/store/v1/cart/select-shipping-rate', '/wc/v3/orders', '/wc/store/v1/checkout-extra'] as $route) {
        $request = new WP_REST_Request('POST', $route);
        $check(Oyiso_WC_Checkout_Maintenance::validateStoreApi(null, $server, $request) === null, 'Unrelated routes must remain available: ' . $route);
    }

    $request = new WP_REST_Request('POST', '/wc/store/v1/checkout');
    $earlierError = new WP_Error('earlier_error', 'Keep this error.', ['status' => 403]);
    $check(Oyiso_WC_Checkout_Maintenance::validateStoreApi($earlierError, $server, $request) === $earlierError, 'Existing REST errors must remain unchanged.');
    $check(Oyiso_WC_Checkout_Maintenance::validateStoreApi(false, $server, $request)->get_status() === 503, 'An empty earlier filter result must not bypass maintenance.');

    $batch = new WP_REST_Request('POST', '/wc/store/v1/batch');
    $batch->set_body_params(['requests' => [['method' => 'POST', 'path' => '/wc/store/v1/checkout', 'body' => []]]]);
    $batchBody = rest_do_request($batch)->get_data();
    $check(($batchBody['responses'][0]['body']['code'] ?? '') === 'oyiso_checkout_maintenance', 'Batched checkout must also be blocked.');

    wc_clear_notices();
    $_REQUEST['woocommerce-process-checkout-nonce'] = wp_create_nonce('woocommerce-process_checkout');
    ob_start();
    WC()->checkout()->process_checkout();
    ob_end_clean();
    $notices = wc_get_notices('error');
    $check(count($notices) === 1, 'Classic checkout must stop with one maintenance notice before validating the cart.');
    $notice = wc_kses_notice($notices[0]['notice']);
    $check(str_contains($notice, 'data-oyiso-checkout-maintenance='), 'WooCommerce notice sanitization must retain the popup marker.');
    $check(str_contains($notice, 'Custom maintenance message.'), 'Classic checkout must show a readable message without JavaScript.');

    $guardedCreate = false;
    try {
        do_action('woocommerce_checkout_create_order', new WC_Order(), []);
    } catch (Exception $error) {
        $guardedCreate = str_contains($error->getMessage(), 'data-oyiso-checkout-maintenance');
    }
    $check($guardedCreate, 'Direct classic order creation must also be guarded.');
    $check($countOrders() === $ordersBefore, 'Maintenance tests must not create any orders.');

    echo "PASS: {$assertions} checkout maintenance assertions; order count unchanged.\n";
} finally {
    $_REQUEST = $originalRequest;
    wc_set_notices($originalNotices);
    remove_filter('pre_option_oyiso', $overrideOptions);
}
