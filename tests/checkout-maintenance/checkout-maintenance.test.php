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
$originalUserId = get_current_user_id();
$server = rest_get_server();

// Exercise WooCommerce's actual AJAX response in a child process, since wp_send_json() exits.
if (($argv[1] ?? '') === '--classic-ajax') {
    $options = [
        'oyiso_wc_checkout_maintenance_enabled' => true,
        'oyiso_wc_checkout_maintenance_options' => [
            'accent' => '#2563eb',
            'message' => '<p><strong>AJAX &amp; HTML</strong></p><p>&lt;img src=x onerror=alert(1)&gt;</p>',
        ],
    ];
    define('DOING_AJAX', true);
    wc_clear_notices();
    $_REQUEST['woocommerce-process-checkout-nonce'] = wp_create_nonce('woocommerce-process_checkout');
    WC_AJAX::checkout();
    exit;
}

try {
    $check(!Oyiso_WC_Checkout_Maintenance::isEnabled(), 'Maintenance must be disabled by default.');
    $defaults = Oyiso_WC_Checkout_Maintenance::getDefaults();
    $check(Oyiso_WC_Checkout_Maintenance::getSettings() === $defaults, 'Missing settings must use the English defaults.');
    $check(Oyiso_WC_Checkout_Maintenance::sanitizeSettings(null) === $defaults, 'Malformed settings must use defaults.');
    $check(Oyiso_WC_Checkout_Maintenance::sanitizeSettings(['message' => '  ']) === $defaults, 'An empty message must retain a readable fallback.');
    $check(Oyiso_WC_Checkout_Maintenance::sanitizeSettings(['message' => '<p>&nbsp;<br></p>']) === $defaults, 'An emptied visual editor must use the readable default.');
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
    $payload = Oyiso_WC_Checkout_Maintenance::prepareSettings($settings);
    $check($settings['title'] === 'Temporarily closed', 'Customer-facing copy must be sanitized.');
    $check($settings['message'] === $options['oyiso_wc_checkout_maintenance_options']['message'], 'Custom text and line breaks must survive.');
    $check($settings['template'] === 'default', 'An unknown template must fall back to the default.');
    $check($settings['accent'] === $defaults['accent'], 'Existing settings without a color must use the preset default.');
    foreach (['#2563EB' => '#2563eb', ' #ABC ' => '#aabbcc', '#000000' => '#000000', '#ffffff' => '#ffffff'] as $color => $expected) {
        $check(Oyiso_WC_Checkout_Maintenance::sanitizeSettings(['accent' => $color])['accent'] === $expected, 'Valid custom colors must survive.');
    }
    foreach (['red', 'transparent', 'rgba(0,0,0,0)', '#123456; color:red', '#fff;background:url(x)', [], 123] as $color) {
        $check(Oyiso_WC_Checkout_Maintenance::sanitizeSettings(['accent' => $color])['accent'] === $defaults['accent'], 'Invalid colors must fall back without injecting CSS.');
    }
    ob_start();
    Oyiso_WC_Checkout_Maintenance::renderAccentField();
    $colorMarkup = ob_get_clean();
    $check(str_contains($colorMarkup, 'type="color"') && !str_contains($colorMarkup, 'wp-color-picker'), 'The accent field must use the native HTML color input.');
    $check(substr_count($colorMarkup, 'data-oyiso-maintenance-color=') === 6, 'All six presets must remain available beside the native picker.');
    $check(substr_count($colorMarkup, 'name="oyiso[oyiso_wc_checkout_maintenance_options][accent]"') === 1, 'Only one accent value may be saved.');
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
    $check($status->get_data() === ['enabled' => true, 'settings' => $payload], 'The status route must return current PHP settings without submitting an order.');

    foreach (['/wc/store/v1/checkout', '/wc/store/v1/checkout/', '/wc/store/checkout', '/wc/store/v2/checkout', '/wc/store/v1/checkout/123'] as $route) {
        $response = rest_do_request(new WP_REST_Request('POST', $route));
        $body = $response->get_data();
        $check($response->get_status() === 503, 'Direct Store API submission must be blocked: ' . $route);
        $check(($body['code'] ?? '') === 'oyiso_checkout_maintenance', 'Store API must return a recognizable maintenance error.');
        $check(($body['data']['oyiso_checkout_maintenance'] ?? null) === $payload, 'The popup must receive the current server settings.');
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

    $html = '<p><strong>Custom maintenance message.</strong></p><ul><li>Zażółć &amp; 中文</li></ul><p><a href="https://example.com/help">Contact us</a></p>';
    $unsafe = $html . '<img src="https://example.com/image.png" onerror="alert(1)"><script>alert(2)</script><a href="javascript:alert(3)">Unsafe link</a><iframe src="https://example.com"></iframe>';
    $sanitized = Oyiso_WC_Checkout_Maintenance::sanitizeSettings(['message' => $unsafe, 'accent' => '#2563eb']);
    $check(str_contains($sanitized['message'], '<strong>Custom maintenance message.</strong>') && str_contains($sanitized['message'], '<ul>'), 'Rich HTML must survive sanitization.');
    $check(str_contains($sanitized['message'], 'href="https://example.com/help"'), 'Normal HTML links must survive.');
    foreach (['onerror', '<script', '<iframe', 'javascript:'] as $unsafePart) {
        $check(!str_contains($sanitized['message'], $unsafePart), 'Unsafe HTML must be removed: ' . $unsafePart);
    }
    $check(str_contains($payload['messageHtml'], "<br />\nPlease come back later."), 'Existing plain-text messages must retain their line breaks.');

    $previewRequest = new WP_REST_Request('POST', '/oyiso/v1/checkout-maintenance/preview');
    $previewRequest->set_header('Content-Type', 'application/json');
    $previewRequest->set_body((string) wp_json_encode(['message' => $unsafe, 'accent' => '#2563eb']));
    wp_set_current_user(0);
    $check(rest_do_request($previewRequest)->get_status() === 401, 'Anonymous visitors cannot use the administrator preview.');
    $administrators = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
    $check(!empty($administrators), 'Integration tests require an existing local administrator.');
    wp_set_current_user((int) $administrators[0]);
    $previewResponse = rest_do_request($previewRequest);
    $check($previewResponse->get_status() === 200, 'An administrator can preview unsaved HTML.');
    $check($previewResponse->get_data() === Oyiso_WC_Checkout_Maintenance::prepareSettings($sanitized), 'Preview must use the exact same sanitization and formatting as checkout.');
    $check(Oyiso_WC_Checkout_Maintenance::getSettings() === $settings, 'Preview must not save settings.');
    wp_set_current_user($originalUserId);

    $options['oyiso_wc_checkout_maintenance_options']['message'] = $html;
    $options['oyiso_wc_checkout_maintenance_options']['accent'] = '#2563eb';
    $htmlSettings = Oyiso_WC_Checkout_Maintenance::prepareSettings(Oyiso_WC_Checkout_Maintenance::getSettings());
    $htmlResponse = rest_do_request(new WP_REST_Request('POST', '/wc/store/v1/checkout'))->get_data();
    $check($htmlResponse['data']['oyiso_checkout_maintenance'] === $htmlSettings, 'Blocks must receive safe formatted HTML and the selected color.');
    $check(!str_contains($htmlResponse['message'], '<') && str_contains($htmlResponse['message'], 'Contact us'), 'Blocks must also receive a readable plain error fallback.');

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
    preg_match('/data-oyiso-checkout-maintenance="([^"]+)"/', $notice, $noticeMatch);
    $noticeSettings = json_decode(html_entity_decode($noticeMatch[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
    $check($noticeSettings === $htmlSettings, 'WooCommerce notice filtering must preserve the complete HTML/color payload.');
    $check(!str_contains(wp_strip_all_tags($notice), '<strong>'), 'Classic fallback must not expose literal HTML source.');

    $guardedCreate = false;
    try {
        do_action('woocommerce_checkout_create_order', new WC_Order(), []);
    } catch (Exception $error) {
        $guardedCreate = str_contains($error->getMessage(), 'data-oyiso-checkout-maintenance');
    }
    $check($guardedCreate, 'Direct classic order creation must also be guarded.');

    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__, '--classic-ajax'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $check(is_resource($process), 'The classic AJAX integration process must start.');
    $ajaxOutput = stream_get_contents($pipes[1]);
    $ajaxErrors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $check($exitCode === 0 && $ajaxErrors === '', 'Classic AJAX must finish without PHP errors: ' . $ajaxErrors);
    $ajax = json_decode($ajaxOutput, true);
    $check(($ajax['result'] ?? '') === 'failure', 'Real classic AJAX submission must return a checkout failure.');
    preg_match('/data-oyiso-checkout-maintenance="([^"]+)"/', $ajax['messages'] ?? '', $ajaxMatch);
    $ajaxSettings = json_decode(html_entity_decode($ajaxMatch[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
    $check(($ajaxSettings['accent'] ?? '') === '#2563eb', 'The real AJAX notice must carry the selected color.');
    $check(str_contains($ajaxSettings['messageHtml'] ?? '', 'AJAX &amp; HTML') && str_contains($ajaxSettings['messageHtml'] ?? '', '&lt;img'), 'Classic AJAX must preserve entities as text through JSON, notice filtering and HTML attributes.');
    $check($countOrders() === $ordersBefore, 'Maintenance tests must not create any orders.');

    echo "PASS: {$assertions} checkout maintenance assertions; order count unchanged.\n";
} finally {
    $_REQUEST = $originalRequest;
    wp_set_current_user($originalUserId);
    wc_set_notices($originalNotices);
    remove_filter('pre_option_oyiso', $overrideOptions);
}
