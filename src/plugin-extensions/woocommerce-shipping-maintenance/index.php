<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

if (!class_exists('Oyiso_WC_Shipping_Maintenance')) {
    final class Oyiso_WC_Shipping_Maintenance
    {
        private const OPTION_ENABLED = 'oyiso_wc_shipping_maintenance_enabled';
        private const OPTION_RULES = 'oyiso_wc_shipping_maintenance_rules';
        private const API_NAMESPACE = 'oyiso-shipping-maintenance';

        public static function init(): void
        {
            add_filter('woocommerce_cart_shipping_packages', [__CLASS__, 'addCacheSignature'], 100);
            add_filter('woocommerce_package_rates', [__CLASS__, 'filterRates'], PHP_INT_MAX);
            add_filter('woocommerce_shipping_packages', [__CLASS__, 'filterPackages'], PHP_INT_MAX);
            add_action('woocommerce_cart_totals_after_shipping', [__CLASS__, 'renderDisabledMethods']);
            add_action('woocommerce_review_order_after_shipping', [__CLASS__, 'renderDisabledMethods']);
            add_action('wp_enqueue_scripts', [__CLASS__, 'enqueueAssets']);

            foreach (array_keys(self::getActiveRules()) as $method) {
                $method_id = explode(':', $method, 2)[0];
                add_filter('woocommerce_shipping_' . $method_id . '_is_available', [__CLASS__, 'filterAvailability'], PHP_INT_MAX, 3);
            }

            if (did_action('woocommerce_blocks_loaded')) {
                self::registerStoreApi();
            } else {
                add_action('woocommerce_blocks_loaded', [__CLASS__, 'registerStoreApi']);
            }
        }

        /** @return array<string, string> */
        public static function getMethodOptions(): array
        {
            $options = [];
            $zones = WC_Shipping_Zones::get_zones();
            $other_zone = new WC_Shipping_Zone(0);
            $zones[0] = [
                'zone_name' => $other_zone->get_zone_name(),
                'shipping_methods' => $other_zone->get_shipping_methods(true),
            ];

            foreach ($zones as $zone) {
                foreach ($zone['shipping_methods'] as $method) {
                    if (!$method->is_enabled()) {
                        continue;
                    }

                    $key = $method->id . ':' . $method->get_instance_id();
                    $options[$key] = $zone['zone_name'] . ' — ' . wp_strip_all_tags($method->get_title()) . ' (' . $key . ')';
                }
            }

            return $options;
        }

        /**
         * @param array<array-key, mixed>|string|null $value CSF group field value.
         * @return list<array{method: string, enabled: bool, mode: 'hide'|'disabled', message: string}>
         */
        public static function sanitizeRules(array|string|null $value): array
        {
            if (!is_array($value)) {
                return [];
            }

            $rules = [];
            foreach ($value as $row) {
                if (!is_array($row) || !is_string($row['method'] ?? null) || !preg_match('/^[a-z0-9_-]+:[1-9][0-9]*$/D', $row['method'])) {
                    continue;
                }

                $mode = $row['mode'] ?? 'hide';
                if ($mode !== 'hide' && $mode !== 'disabled') {
                    continue;
                }

                $rules[] = [
                    'method' => $row['method'],
                    'enabled' => in_array($row['enabled'] ?? false, [true, 1, '1', 'true', 'yes'], true),
                    'mode' => $mode,
                    'message' => is_string($row['message'] ?? null) ? trim(sanitize_textarea_field($row['message'])) : '',
                ];
            }

            return $rules;
        }

        /** @param array<array-key, mixed>|string|null $value */
        public static function validateRules(array|string|null $value): string
        {
            if (!is_array($value)) {
                return '';
            }

            $seen = [];
            foreach ($value as $row) {
                if (!is_array($row) || !in_array($row['enabled'] ?? false, [true, 1, '1', 'true', 'yes'], true)) {
                    continue;
                }
                if (!is_string($row['method'] ?? null) || !preg_match('/^[a-z0-9_-]+:[1-9][0-9]*$/D', $row['method'])) {
                    return '请选择需要维护的运输方式。';
                }
                if (isset($seen[$row['method']])) {
                    return '同一运输方式只能添加一条启用的维护规则。';
                }
                $seen[$row['method']] = true;

                if (($row['mode'] ?? 'hide') === 'disabled' && (!is_string($row['message'] ?? null) || trim(sanitize_textarea_field($row['message'])) === '')) {
                    return '运输方式设为置灰时，必须填写客户提示。';
                }
            }

            return '';
        }

        /** @return array<string, array{mode: 'hide'|'disabled', message: string}> */
        public static function getActiveRules(): array
        {
            $options = get_option('oyiso', []);
            if (!is_array($options) || empty($options[self::OPTION_ENABLED]) || !is_array($options[self::OPTION_RULES] ?? null)) {
                return [];
            }

            $rules = [];
            foreach (self::sanitizeRules($options[self::OPTION_RULES]) as $row) {
                if (!$row['enabled'] || ($row['mode'] === 'disabled' && $row['message'] === '')) {
                    continue;
                }
                $rules[$row['method']] = ['mode' => $row['mode'], 'message' => $row['message']];
            }

            return $rules;
        }

        /**
         * Include maintenance state in WC's package hash so existing customer sessions refresh their rates.
         * @param array<array-key, array<string, mixed>> $packages
         * @return array<array-key, array<string, mixed>>
         */
        public static function addCacheSignature(array $packages): array
        {
            $rules = self::getActiveRules();
            ksort($rules);
            $signature = md5((string) wp_json_encode($rules));
            foreach ($packages as &$package) {
                $package['oyiso_shipping_maintenance'] = $signature;
            }
            unset($package);

            return $packages;
        }

        /** @param array<string, mixed> $package */
        public static function filterAvailability(bool $available, array $package, WC_Shipping_Method $method): bool
        {
            return $available && !isset(self::getActiveRules()[$method->id . ':' . $method->get_instance_id()]);
        }

        /**
         * Both hidden and greyed-out methods are excluded from real shipping rates, including Store API selection.
         * @param array<array-key, mixed> $rates
         * @return array<array-key, mixed>
         */
        public static function filterRates(array $rates): array
        {
            $rules = self::getActiveRules();
            foreach ($rates as $key => $rate) {
                if ($rate instanceof WC_Shipping_Rate && isset($rules[$rate->get_method_id() . ':' . $rate->get_instance_id()])) {
                    unset($rates[$key]);
                }
            }

            return $rates;
        }

        /**
         * Also check cached packages and rates supplied by other extensions.
         * @param array<array-key, array<string, mixed>> $packages
         * @return array<array-key, array<string, mixed>>
         */
        public static function filterPackages(array $packages): array
        {
            foreach ($packages as &$package) {
                if (is_array($package['rates'] ?? null)) {
                    $package['rates'] = self::filterRates($package['rates']);
                }
            }
            unset($package);

            return $packages;
        }

        /** @return list<array{package_id: string, options: list<array{id: string, label: string, message: string}>}> */
        public static function getDisabledPackages(): array
        {
            $rules = self::getActiveRules();
            $cart = WC()->cart;
            if (!$rules || !$cart || !$cart->needs_shipping()) {
                return [];
            }

            $groups = [];
            $packages = WC()->shipping()->get_packages() ?: $cart->get_shipping_packages();
            foreach ($packages as $package_id => $package) {
                $destination = $package['destination'] ?? [];
                if (!is_array($destination) || empty($destination['country'])) {
                    continue;
                }

                $options = [];
                $zone = WC_Shipping_Zones::get_zone_matching_package($package);
                foreach ($zone->get_shipping_methods(true) as $method) {
                    $key = $method->id . ':' . $method->get_instance_id();
                    if (!isset($rules[$key]) || $rules[$key]['mode'] !== 'disabled') {
                        continue;
                    }
                    $options[] = ['id' => $key, 'label' => wp_strip_all_tags($method->get_title()), 'message' => $rules[$key]['message']];
                }
                if ($options) {
                    $groups[] = ['package_id' => (string) $package_id, 'options' => $options];
                }
            }

            return $groups;
        }

        public static function renderDisabledMethods(): void
        {
            $groups = self::getDisabledPackages();
            if (!$groups) {
                return;
            }

            echo '<tr class="oyiso-shipping-maintenance"><td colspan="2"><ul class="woocommerce-shipping-methods oyiso-shipping-maintenance-options">';
            foreach ($groups as $group) {
                foreach ($group['options'] as $option) {
                    $id = 'oyiso-shipping-maintenance-' . $group['package_id'] . '-' . sanitize_title($option['id']);
                    echo '<li><label class="oyiso-shipping-maintenance-label"><input type="radio" disabled aria-describedby="' . esc_attr($id) . '" /> ' . esc_html($option['label']) . '</label>';
                    echo '<small id="' . esc_attr($id) . '" class="oyiso-shipping-maintenance-message">' . nl2br(esc_html($option['message'])) . '</small></li>';
                }
            }
            echo '</ul></td></tr>';
        }

        public static function registerStoreApi(): void
        {
            if (!function_exists('woocommerce_store_api_register_endpoint_data')) {
                return;
            }

            woocommerce_store_api_register_endpoint_data([
                'endpoint' => Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
                'namespace' => self::API_NAMESPACE,
                'data_callback' => static fn(): array => ['packages' => self::getDisabledPackages()],
                'schema_callback' => static fn(): array => [
                    'packages' => [
                        'type' => 'array',
                        'readonly' => true,
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'package_id' => ['type' => 'string'],
                                'options' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'id' => ['type' => 'string'],
                                            'label' => ['type' => 'string'],
                                            'message' => ['type' => 'string'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'schema_type' => ARRAY_A,
            ]);
        }

        public static function enqueueAssets(): void
        {
            if ((!is_cart() && !is_checkout()) || is_order_received_page()) {
                return;
            }
            $has_disabled = false;
            foreach (self::getActiveRules() as $rule) {
                if ($rule['mode'] === 'disabled') {
                    $has_disabled = true;
                    break;
                }
            }
            if (!$has_disabled) {
                return;
            }

            wp_enqueue_style('oyiso-wc-shipping-maintenance', plugins_url('assets/shipping-maintenance.css', __FILE__), [], (string) filemtime(__DIR__ . '/assets/shipping-maintenance.css'));
            if (has_block('woocommerce/cart') || has_block('woocommerce/checkout')) {
                wp_enqueue_script('oyiso-wc-shipping-maintenance', plugins_url('assets/shipping-maintenance.js', __FILE__), ['wp-element', 'wp-plugins', 'wc-blocks-checkout', 'wc-blocks-components'], (string) filemtime(__DIR__ . '/assets/shipping-maintenance.js'), true);
            }
        }
    }

    Oyiso_WC_Shipping_Maintenance::init();
}
