<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

if (!class_exists('Oyiso_WC_Poland_Checkout')) {
    final class Oyiso_WC_Poland_Checkout
    {
        public const STREET = 'oyiso-checkout/street';
        public const HOUSE = 'oyiso-checkout/house-number';
        private const OPTION = 'oyiso_wc_poland_checkout_enabled';

        public static function init(): void
        {
            add_filter('woocommerce_get_country_locale', [__CLASS__, 'countryLocale'], 30);
            add_filter('woocommerce_checkout_fields', [__CLASS__, 'checkoutFields'], 30);
            add_filter('woocommerce_checkout_get_value', [__CLASS__, 'checkoutValue'], 30, 2);
            add_filter('woocommerce_checkout_posted_data', [__CLASS__, 'postedData'], 30);
            add_action('woocommerce_after_checkout_validation', [__CLASS__, 'validateClassic'], 30, 2);
            add_action('woocommerce_checkout_create_order', [__CLASS__, 'saveClassic'], 5, 2);
            add_action('woocommerce_checkout_update_customer', [__CLASS__, 'saveClassic'], 5, 2);
            add_action('woocommerce_init', [__CLASS__, 'registerBlockFields']);
            add_filter('woocommerce_get_default_value_for_' . self::STREET, [__CLASS__, 'defaultStreet'], 10, 3);
            add_filter('woocommerce_get_default_value_for_' . self::HOUSE, [__CLASS__, 'defaultHouse'], 10, 3);
            add_action('woocommerce_store_api_cart_update_customer_from_request', [__CLASS__, 'saveStoreCustomer'], 5, 2);
            add_action('woocommerce_store_api_checkout_update_customer_from_request', [__CLASS__, 'saveStoreCustomer'], 5, 2);
            add_action('woocommerce_store_api_checkout_update_order_from_request', [__CLASS__, 'saveStoreOrder'], 5, 2);
            add_action('wp_enqueue_scripts', [__CLASS__, 'enqueueAssets']);
        }

        public static function enabled(): bool
        {
            $options = get_option('oyiso', []);
            if (!is_array($options)) {
                return false;
            }

            if (array_key_exists('oyiso_wc_checkout_form_countries', $options)) {
                $selected = $options['oyiso_wc_checkout_form_countries'];
                return is_array($selected) && in_array('PL', $selected, true);
            }

            $countries = $options['oyiso_wc_checkout_form_options'] ?? [];
            if (is_array($countries) && array_key_exists(self::OPTION, $countries)) {
                return !empty($countries[self::OPTION]);
            }

            return !empty($options[self::OPTION]);
        }

        /**
         * @param array<string, mixed> $locales
         * @return array<string, mixed>
         */
        public static function countryLocale(array $locales): array
        {
            if (!self::enabled()) {
                return $locales;
            }
            $poland = is_array($locales['PL'] ?? null) ? $locales['PL'] : [];
            $locales['PL'] = array_replace_recursive($poland, [
                'address_1' => ['priority' => 46],
                'address_2' => ['label' => 'Numer mieszkania / lokalu', 'placeholder' => 'np. 5', 'required' => false, 'hidden' => false],
                'postcode' => ['label' => 'Kod pocztowy', 'placeholder' => '00-000', 'priority' => 65],
                'city' => ['label' => 'Miasto', 'priority' => 70],
                'phone' => ['label' => 'Telefon komórkowy', 'placeholder' => '+48 512 345 678', 'required' => true, 'hidden' => false, 'priority' => 25],
                self::STREET => ['priority' => 40],
                self::HOUSE => ['priority' => 45],
            ]);
            return $locales;
        }

        /**
         * @param array<string, array<string, array<string, mixed>>> $fields
         * @return array<string, array<string, array<string, mixed>>>
         */
        public static function checkoutFields(array $fields): array
        {
            if (!self::enabled()) {
                return $fields;
            }
            foreach (['billing', 'shipping'] as $group) {
                if (!isset($fields[$group])) {
                    continue;
                }
                $getter = 'get_' . $group . '_country';
                $country = self::text($_POST[$group . '_country'] ?? (WC()->customer ? WC()->customer->$getter() : ''));
                $poland = $country === 'PL';
                $fields[$group][$group . '_oyiso_house_number'] = [
                    'type' => 'text', 'label' => 'Numer domu', 'placeholder' => 'np. 11A',
                    'required' => $poland, 'class' => ['form-row-first', 'oyiso-pl-house-number'],
                    'priority' => 55, 'autocomplete' => 'off', 'custom_attributes' => ['maxlength' => '20'],
                ];
                if (!$poland) {
                    continue;
                }
                $changes = [
                    'address_1' => ['label' => 'Ulica', 'placeholder' => 'np. Marszałkowska'],
                    'address_2' => ['label' => 'Numer mieszkania / lokalu', 'label_class' => [], 'placeholder' => 'np. 5', 'required' => false, 'class' => ['form-row-last', 'address-field'], 'priority' => 56],
                    'postcode' => ['placeholder' => '00-000', 'class' => ['form-row-first', 'address-field'], 'priority' => 65],
                    'city' => ['class' => ['form-row-last', 'address-field'], 'priority' => 70],
                    'phone' => ['label' => 'Telefon komórkowy', 'placeholder' => '+48 512 345 678', 'required' => $group === 'billing', 'priority' => 25],
                    'email' => ['priority' => 30],
                    'country' => ['priority' => 40],
                ];
                foreach ($changes as $key => $change) {
                    if (isset($fields[$group][$group . '_' . $key])) {
                        $fields[$group][$group . '_' . $key] = array_replace($fields[$group][$group . '_' . $key], $change);
                    }
                }
            }
            return $fields;
        }

        /** @param scalar|null $value */
        public static function checkoutValue($value, string $input): string|int|float|bool|null
        {
            if (!self::enabled() || $value !== null || !WC()->customer) {
                return $value;
            }
            foreach (['billing', 'shipping'] as $group) {
                $country = 'get_' . $group . '_country';
                if (WC()->customer->$country() !== 'PL') {
                    continue;
                }
                $parts = self::addressParts(WC()->customer, $group);
                if ($input === $group . '_address_1') {
                    return $parts['street'];
                }
                if ($input === $group . '_oyiso_house_number') {
                    return $parts['house'];
                }
            }
            return $value;
        }

        /**
         * @param array<string, mixed> $data
         * @return array<string, mixed>
         */
        public static function postedData(array $data): array
        {
            if (!self::enabled()) {
                return $data;
            }
            foreach (['billing', 'shipping'] as $group) {
                if (($data[$group . '_country'] ?? '') !== 'PL') {
                    continue;
                }
                $street = self::text($data[$group . '_address_1'] ?? '');
                $house = self::text($data[$group . '_oyiso_house_number'] ?? '');
                $data[$group . '_oyiso_street'] = $street;
                $data[$group . '_address_1'] = self::joinAddress($street, $house);
                $data[$group . '_postcode'] = self::postcode(self::text($data[$group . '_postcode'] ?? ''));
                $data[$group . '_phone'] = self::phone(self::text($data[$group . '_phone'] ?? ''));
            }
            return $data;
        }

        /** @param array<string, mixed> $data */
        public static function validateClassic(array $data, WP_Error $errors): void
        {
            if (!self::enabled()) {
                return;
            }
            foreach (['billing', 'shipping'] as $group) {
                if (($data[$group . '_country'] ?? '') !== 'PL' || ($group === 'shipping' && empty($data['ship_to_different_address']))) {
                    continue;
                }
                $address = [
                    'address_1' => $data[$group . '_address_1'] ?? '',
                    self::STREET => $data[$group . '_oyiso_street'] ?? '',
                    self::HOUSE => $data[$group . '_oyiso_house_number'] ?? '',
                    'postcode' => $data[$group . '_postcode'] ?? '', 'phone' => $data[$group . '_phone'] ?? '',
                ];
                self::validateAddress($address, $group, $errors, $group === 'billing');
            }
        }

        /** @param array<string, mixed> $data */
        public static function saveClassic(WC_Order|WC_Customer $object, array $data): void
        {
            if (!self::enabled()) {
                return;
            }
            foreach (['billing', 'shipping'] as $group) {
                if (($data[$group . '_country'] ?? '') === 'PL') {
                    $object->update_meta_data('_wc_' . $group . '/' . self::STREET, self::text($data[$group . '_oyiso_street'] ?? ''));
                    $object->update_meta_data('_wc_' . $group . '/' . self::HOUSE, self::text($data[$group . '_oyiso_house_number'] ?? ''));
                }
            }
        }

        public static function registerBlockFields(): void
        {
            if (!self::enabled() || !function_exists('woocommerce_register_additional_checkout_field')) {
                return;
            }
            $country = ['type' => 'object', 'properties' => ['customer' => ['type' => 'object', 'properties' => [
                'address' => ['type' => 'object', 'properties' => ['country' => ['const' => 'PL']]],
            ]]]];
            foreach ([self::STREET => ['Ulica', 40], self::HOUSE => ['Numer domu', 45]] as $id => [$label, $index]) {
                woocommerce_register_additional_checkout_field([
                    'id' => $id, 'label' => $label, 'location' => 'address', 'type' => 'text',
                    'required' => $country, 'hidden' => ['not' => $country], 'index' => $index,
                    'show_in_order_confirmation' => false,
                    'attributes' => ['autocomplete' => $id === self::STREET ? 'address-line1' : 'off', 'data-oyiso-poland-field' => $id],
                    'sanitize_callback' => [__CLASS__, 'text'],
                    'validate_callback' => $id === self::HOUSE ? [__CLASS__, 'validateHouse'] : null,
                ]);
            }
        }

        /** @param scalar|null $value */
        public static function defaultStreet($value, string $group, WC_Data $object): string
        {
            return $value !== null ? self::text($value) : self::addressParts($object, $group)['street'];
        }

        /** @param scalar|null $value */
        public static function defaultHouse($value, string $group, WC_Data $object): string
        {
            return $value !== null ? self::text($value) : self::addressParts($object, $group)['house'];
        }

        /** @return array{street: string, house: string} */
        public static function addressParts(WC_Data $object, string $group): array
        {
            if (!in_array($group, ['billing', 'shipping'], true) || !($object instanceof WC_Customer || $object instanceof WC_Order)) {
                return ['street' => '', 'house' => ''];
            }
            $getter = 'get_' . $group . '_address_1';
            $full = self::text($object->$getter());
            $house = self::text($object->get_meta('_wc_' . $group . '/' . self::HOUSE, true));
            $street = self::text($object->get_meta('_wc_' . $group . '/' . self::STREET, true));
            if ($street !== '' && self::joinAddress($street, $house) === $full) {
                return ['street' => $street, 'house' => $house];
            }
            if (preg_match('/^(.+?)\s+(\d[\p{L}\p{N}\/.-]*)$/u', $full, $matches)) {
                return ['street' => $matches[1], 'house' => $matches[2]];
            }
            return ['street' => $full, 'house' => ''];
        }

        /** @param WP_REST_Request<array<string, mixed>> $request */
        public static function saveStoreCustomer(WC_Customer $customer, WP_REST_Request $request): void
        {
            self::saveStoreAddress($customer, $request);
        }

        /** @param WP_REST_Request<array<string, mixed>> $request */
        public static function saveStoreOrder(WC_Order $order, WP_REST_Request $request): void
        {
            self::saveStoreAddress($order, $request);
        }

        /** @param WP_REST_Request<array<string, mixed>> $request */
        private static function saveStoreAddress(WC_Order|WC_Customer $object, WP_REST_Request $request): void
        {
            if (!self::enabled()) {
                return;
            }
            $errors = new WP_Error();
            if ($request->get_method() === 'POST' && str_ends_with($request->get_route(), '/checkout')) {
                foreach (['billing', 'shipping'] as $group) {
                    $address = $request->get_param($group . '_address');
                    if (is_array($address) && ($address['country'] ?? '') === 'PL') {
                        self::validateAddress($address, $group, $errors, $group === 'billing');
                    }
                }
                if ($errors->has_errors()) {
                    throw new Automattic\WooCommerce\StoreApi\Exceptions\RouteException('oyiso_invalid_polish_address', implode(' ', $errors->get_error_messages()), 400);
                }
            }
            foreach (['billing', 'shipping'] as $group) {
                $address = $request->get_param($group . '_address');
                if (!is_array($address) || ($address['country'] ?? '') !== 'PL') {
                    continue;
                }
                if (array_key_exists(self::STREET, $address) || array_key_exists(self::HOUSE, $address)) {
                    $street = self::text($address[self::STREET] ?? '');
                    $house = self::text($address[self::HOUSE] ?? '');
                    $setter = 'set_' . $group . '_address_1';
                    $object->$setter(self::joinAddress($street, $house));
                    $object->update_meta_data('_wc_' . $group . '/' . self::STREET, $street);
                    $object->update_meta_data('_wc_' . $group . '/' . self::HOUSE, $house);
                }
                if (isset($address['postcode'])) {
                    $setter = 'set_' . $group . '_postcode';
                    $object->$setter(self::postcode(self::text($address['postcode'])));
                }
                if (isset($address['phone'])) {
                    $setter = 'set_' . $group . '_phone';
                    $object->$setter(self::phone(self::text($address['phone'])));
                }
            }
        }

        /** @param array<string, mixed> $address */
        private static function validateAddress(array $address, string $group, WP_Error $errors, bool $requirePhone): void
        {
            $street = self::text($address[self::STREET] ?? '');
            $house = self::text($address[self::HOUSE] ?? '');
            if ($street === '') {
                $errors->add($group . '_address_1_required', 'Podaj nazwę ulicy.', ['id' => $group . '_address_1']);
            }
            if ($house === '' || self::validateHouse($house) instanceof WP_Error) {
                $errors->add($group . '_oyiso_house_number_validation', 'Podaj prawidłowy numer domu, np. 11 lub 11A.', ['id' => $group . '_oyiso_house_number']);
            }
            $postcode = self::postcode(self::text($address['postcode'] ?? ''));
            if (!preg_match('/^\d{2}-\d{3}$/D', $postcode)) {
                $errors->add($group . '_postcode_validation', 'Podaj kod pocztowy w formacie XX-XXX.', ['id' => $group . '_postcode']);
            }
            $phone = self::phone(self::text($address['phone'] ?? ''));
            if (($requirePhone || $phone !== '') && !self::validPhone($phone)) {
                $errors->add($group . '_phone_validation', 'Podaj prawidłowy numer telefonu z numerem kierunkowym, np. +48 512 345 678.', ['id' => $group . '_phone']);
            }
        }

        public static function validateHouse(string $value): WP_Error|bool
        {
            return $value === '' || preg_match('/^\d[\p{L}\p{N} \/.-]{0,19}$/uD', $value)
                ? true : new WP_Error('oyiso_invalid_house_number', 'Podaj prawidłowy numer domu, np. 11 lub 11A.');
        }

        public static function joinAddress(string $street, string $house): string
        {
            return trim($street . ' ' . $house);
        }

        public static function postcode(string $value): string
        {
            $compact = preg_replace('/\s+/u', '', $value) ?? $value;
            return preg_match('/^\d{5}$/D', $compact) ? substr($compact, 0, 2) . '-' . substr($compact, 2) : $compact;
        }

        public static function phone(string $value): string
        {
            $compact = preg_replace('/[\s().-]+/u', '', $value) ?? $value;
            if (str_starts_with($compact, '00')) {
                $compact = '+' . substr($compact, 2);
            }
            if (preg_match('/^\d{9}$/D', $compact)) {
                return '+48' . $compact;
            }
            if (preg_match('/^48\d{9}$/D', $compact)) {
                return '+' . $compact;
            }
            return $compact;
        }

        public static function validPhone(string $value): bool
        {
            return str_starts_with($value, '+48')
                ? (bool) preg_match('/^\+48\d{9}$/D', $value)
                : (bool) preg_match('/^\+[1-9]\d{6,14}$/D', $value);
        }

        /** @param mixed $value */
        public static function text($value): string
        {
            return is_scalar($value) ? trim(sanitize_text_field((string) $value)) : '';
        }

        public static function enqueueAssets(): void
        {
            if (!self::enabled() || !is_checkout() || is_order_received_page() || is_wc_endpoint_url('order-pay')) {
                return;
            }
            wp_enqueue_style('oyiso-poland-checkout', plugins_url('assets/checkout.css', __FILE__), [], (string) filemtime(__DIR__ . '/assets/checkout.css'));
            wp_enqueue_script('oyiso-poland-checkout', plugins_url('assets/checkout.js', __FILE__), ['jquery', 'wp-data', 'wc-blocks-data-store'], (string) filemtime(__DIR__ . '/assets/checkout.js'), true);
            wp_localize_script('oyiso-poland-checkout', 'oyisoPolandCheckout', ['otherPhoneRequired' => get_option('woocommerce_checkout_phone_field') === 'required']);
        }
    }

    Oyiso_WC_Poland_Checkout::init();
}
