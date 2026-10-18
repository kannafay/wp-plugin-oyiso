<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

final class Oyiso_WC_Checkout_Protection
{
    private const ADDRESS_FIELDS = [
        'first_name', 'last_name', 'company', 'address_1', 'address_2',
        'city', 'state', 'postcode', 'country', 'email', 'phone',
        'oyiso_street', 'oyiso_house_number', 'oyiso_postcode',
        'oyiso-checkout/street', 'oyiso-checkout/house-number',
    ];
    private const FIELD_MAX_BYTES = 1024;
    private const NOTE_MAX_BYTES = 16384;
    private const ERROR_CODE = 'oyiso_invalid_checkout_input';

    public static function register(bool $enabled): void
    {
        if (!$enabled) {
            return;
        }

        add_action('woocommerce_before_checkout_process', [self::class, 'validateClassicRequest'], 5);
        add_action('woocommerce_after_checkout_validation', [self::class, 'validateClassic'], 5, 2);
        add_action('woocommerce_checkout_create_order', [self::class, 'validateOrder'], PHP_INT_MAX);
        add_action('woocommerce_checkout_validate_order_before_payment', [self::class, 'validateOrder'], PHP_INT_MAX, 2);
        add_filter('rest_pre_dispatch', [self::class, 'validateStoreApi'], 5, 3);
    }

    public static function validateClassicRequest(): void
    {
        // Inspect the original values before WooCommerce strips encoded/control bytes.
        if (self::invalidClassic(wp_unslash($_POST))) {
            throw new Exception(self::message());
        }
    }

    /** @param array<string, mixed> $data */
    public static function validateClassic(array $data, WP_Error $errors): void
    {
        if (self::invalidClassic($data)) {
            $errors->add(self::ERROR_CODE, self::message());
        }
    }

    public static function validateOrder(WC_Order $order, ?WP_Error $errors = null): void
    {
        // Recheck saved customer/session values when checkout omits an address.
        $data = ['order_comments' => $order->get_customer_note()];
        foreach (['billing', 'shipping'] as $group) {
            foreach ($order->get_address($group) as $field => $value) {
                $data[$group . '_' . $field] = $value;
            }
        }
        if (!self::invalidClassic($data)) {
            return;
        }
        if ($errors !== null) {
            $errors->add(self::ERROR_CODE, self::message());
            return;
        }
        throw new Exception(self::message());
    }

    /** @param WP_REST_Request<array<string, mixed>> $request */
    public static function validateStoreApi(mixed $result, WP_REST_Server $server, WP_REST_Request $request): mixed
    {
        if (!empty($result) || !in_array($request->get_method(), ['POST', 'PUT', 'PATCH'], true)) {
            return $result;
        }

        // Run before schema sanitization, order creation, and customer/session updates.
        if (!preg_match('~^/wc/store(?:/v\d+)?/(?:checkout(?:/\d+)?|cart/update-customer)/?$~i', $request->get_route())) {
            return $result;
        }

        foreach (['billing_address', 'shipping_address'] as $group) {
            $address = $request->get_param($group);
            if ($address === null) {
                continue;
            }
            if (!is_array($address)) {
                return self::error();
            }
            foreach (self::ADDRESS_FIELDS as $field) {
                if (self::invalidValue($address[$field] ?? null, self::FIELD_MAX_BYTES)) {
                    return self::error();
                }
            }
        }

        $additional = $request->get_param('additional_fields');
        if (is_array($additional)) {
            foreach (['oyiso-checkout/street', 'oyiso-checkout/house-number'] as $field) {
                if (self::invalidValue($additional[$field] ?? null, self::FIELD_MAX_BYTES)) {
                    return self::error();
                }
            }
        }

        if (self::invalidValue($request->get_param('order_notes'), self::NOTE_MAX_BYTES)) {
            return self::error();
        }

        return $result;
    }

    /** @param array<string, mixed> $data */
    private static function invalidClassic(array $data): bool
    {
        foreach (['billing', 'shipping'] as $group) {
            foreach (self::ADDRESS_FIELDS as $field) {
                if (self::invalidValue($data[$group . '_' . $field] ?? null, self::FIELD_MAX_BYTES)) {
                    return true;
                }
            }
        }
        return self::invalidValue($data['order_comments'] ?? null, self::NOTE_MAX_BYTES);
    }

    private static function invalidValue(mixed $value, int $maxBytes): bool
    {
        if ($value === null) {
            return false;
        }
        if (!is_scalar($value)) {
            return true;
        }
        $value = (string) $value;
        if (strlen($value) > $maxBytes) {
            return true;
        }

        // Normalize only a copy for detection; leave valid customer data unchanged.
        for ($round = 0; $round <= 4; ++$round) {
            if (preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)
                || preg_match('~(?:^|[/\s])\.\./~', str_replace('\\', '/', $value))
                || self::containsMarkup($value)) {
                return true;
            }
            $decoded = rawurldecode(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($decoded === $value) {
                break;
            }
            $value = $decoded;
        }

        return false;
    }

    private static function containsMarkup(string $value): bool
    {
        // These checkout fields are plain text. Reject markup, including split tags.
        if (preg_match('~<\s*/?\s*[a-z][^<>]*>|<\s*(?:script|iframe|object|embed|svg|math|img|style|link|meta)\b|<\s*(?:!--|!doctype\b|\?)~i', $value)) {
            return true;
        }

        // URL parsers ignore tabs/newlines within a scheme; inspect a copy only.
        $compact = str_replace(["\t", "\n", "\r"], '', $value);
        $event = '~(?:^|[\s"\'`<>/])on[a-z][a-z0-9:_-]*\s*=~i';
        return preg_match($event, $value) === 1
            || preg_match($event, $compact) === 1
            || preg_match('~(?:javascript|vbscript)\s*:|data\s*:\s*(?:text/(?:html|xml)|application/(?:xhtml\+xml|xml)|image/svg\+xml)~i', $compact) === 1;
    }

    private static function message(): string
    {
        return __('Please check your contact details, address and order notes, then try again.', 'oyiso');
    }

    private static function error(): WP_Error
    {
        return new WP_Error(self::ERROR_CODE, self::message(), ['status' => 400]);
    }
}
