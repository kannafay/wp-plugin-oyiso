<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

final class Oyiso_WC_Checkout_Maintenance
{
    private const OPTION_ENABLED = 'oyiso_wc_checkout_maintenance_enabled';
    private const OPTION_SETTINGS = 'oyiso_wc_checkout_maintenance_options';
    private const ERROR_CODE = 'oyiso_checkout_maintenance';
    private const ASSET_HANDLE = 'oyiso-wc-checkout-maintenance';

    public static function init(): void
    {
        add_action('woocommerce_before_checkout_process', [self::class, 'validateClassic'], 0);
        add_action('woocommerce_checkout_create_order', [self::class, 'validateClassic'], 0, 0);
        add_filter('rest_pre_dispatch', [self::class, 'validateStoreApi'], 0, 3);
        add_action('rest_api_init', [self::class, 'registerStatusRoute']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueueFrontendAssets']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAdminAssets']);
        add_action('wp_footer', [self::class, 'renderFrontendDialog']);
        add_action('admin_footer', [self::class, 'renderAdminDialog']);
    }

    /** @return array{template: string, title: string, message: string, button: string} */
    public static function getDefaults(): array
    {
        return [
            'template' => 'default',
            'title' => 'Checkout is under maintenance',
            'message' => 'Checkout is currently under maintenance. Please try placing your order again later.',
            'button' => 'Got it',
        ];
    }

    public static function isEnabled(): bool
    {
        $options = get_option('oyiso', []);
        return is_array($options) && in_array($options[self::OPTION_ENABLED] ?? false, [true, 1, '1', 'true', 'yes'], true);
    }

    /** @return array{template: string, title: string, message: string, button: string} */
    public static function getSettings(): array
    {
        $options = get_option('oyiso', []);
        return self::sanitizeSettings(is_array($options) ? ($options[self::OPTION_SETTINGS] ?? []) : []);
    }

    /** @return array{template: string, title: string, message: string, button: string} */
    public static function sanitizeSettings(mixed $value): array
    {
        $settings = self::getDefaults();
        if (!is_array($value)) {
            return $settings;
        }

        foreach (['title', 'message', 'button'] as $field) {
            if (!is_string($value[$field] ?? null)) {
                continue;
            }
            $text = trim($field === 'message' ? sanitize_textarea_field($value[$field]) : sanitize_text_field($value[$field]));
            if ($text !== '') {
                $settings[$field] = $text;
            }
        }

        return $settings;
    }

    public static function validateClassic(): void
    {
        if (!self::isEnabled()) {
            return;
        }

        $settings = self::getSettings();
        // WooCommerce catches this before creating an order; the notice still works without JavaScript.
        throw new Exception('<span data-oyiso-checkout-maintenance="' . esc_attr((string) wp_json_encode($settings)) . '">'
            . nl2br(esc_html($settings['message'])) . '</span>');
    }

    /** @param WP_REST_Request<array<string, mixed>> $request */
    public static function validateStoreApi(mixed $result, WP_REST_Server $server, WP_REST_Request $request): mixed
    {
        if (!empty($result) || !self::isEnabled() || $request->get_method() !== 'POST') {
            return $result;
        }

        // Reject order submission before Store API can create a draft, reserve stock or process payment.
        // GET/PUT/PATCH checkout requests and all cart requests remain available during maintenance.
        if (!preg_match('~^/wc/store(?:/v\d+)?/checkout(?:/\d+)?/?$~i', $request->get_route())) {
            return $result;
        }

        $settings = self::getSettings();
        return rest_convert_error_to_response(new WP_Error(self::ERROR_CODE, $settings['message'], [
            'status' => 503,
            self::ERROR_CODE => $settings,
        ]));
    }

    public static function registerStatusRoute(): void
    {
        register_rest_route('oyiso/v1', '/checkout-maintenance', [
            'methods' => WP_REST_Server::READABLE,
            'permission_callback' => '__return_true',
            'callback' => [self::class, 'getStatus'],
        ]);
    }

    public static function getStatus(): WP_REST_Response
    {
        $enabled = self::isEnabled();
        return new WP_REST_Response([
            'enabled' => $enabled,
            'settings' => $enabled ? self::getSettings() : null,
        ], 200, ['Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0']);
    }

    private static function isCheckoutPage(): bool
    {
        return !is_order_received_page() && (is_checkout() || has_block('woocommerce/checkout'));
    }

    public static function enqueueFrontendAssets(): void
    {
        if (self::isCheckoutPage()) {
            // Also listen on an already-open checkout if maintenance is enabled after the page was loaded.
            self::enqueueAssets(['jquery', 'wp-api-fetch']);
        }
    }

    public static function enqueueAdminAssets(): void
    {
        if (($_GET['page'] ?? '') === 'oyiso') {
            self::enqueueAssets(['jquery']);
        }
    }

    /** @param list<string> $dependencies */
    private static function enqueueAssets(array $dependencies): void
    {
        wp_enqueue_style(self::ASSET_HANDLE, plugins_url('assets/checkout-maintenance.css', __FILE__), [], (string) filemtime(__DIR__ . '/assets/checkout-maintenance.css'));
        wp_enqueue_script(self::ASSET_HANDLE, plugins_url('assets/checkout-maintenance.js', __FILE__), $dependencies, (string) filemtime(__DIR__ . '/assets/checkout-maintenance.js'), true);
        wp_localize_script(self::ASSET_HANDLE, 'oyisoCheckoutMaintenance', [
            'defaults' => self::getDefaults(),
            'settings' => self::getSettings(),
            'settingsRoot' => 'oyiso[' . self::OPTION_SETTINGS . ']',
            'statusUrl' => rest_url('oyiso/v1/checkout-maintenance'),
        ]);
    }

    public static function renderPreview(): void
    {
        echo '<button type="button" class="button button-secondary" data-oyiso-checkout-maintenance-preview>预览弹窗</button>';
        echo '<p class="description">预览当前填写的内容，无需保存或开启维护。</p>';
    }

    public static function renderFrontendDialog(): void
    {
        if (self::isCheckoutPage()) {
            self::renderDialog();
        }
    }

    public static function renderAdminDialog(): void
    {
        if (($_GET['page'] ?? '') === 'oyiso') {
            self::renderDialog();
        }
    }

    private static function renderDialog(): void
    {
        ?>
        <dialog id="oyiso-checkout-maintenance-dialog" class="oyiso-checkout-maintenance-dialog" aria-labelledby="oyiso-checkout-maintenance-title" aria-describedby="oyiso-checkout-maintenance-message">
            <div class="oyiso-checkout-maintenance-dialog__content">
                <button type="button" class="oyiso-checkout-maintenance-dialog__close" aria-label="<?php echo esc_attr__('Close', 'woocommerce'); ?>" data-oyiso-maintenance-close>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18" /></svg>
                </button>
                <div class="oyiso-checkout-maintenance-dialog__icon" aria-hidden="true">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.7-3.7a6 6 0 0 1-7.9 7.9l-7.1 7.1a2.1 2.1 0 0 1-3-3l7.1-7.1a6 6 0 0 1 7.9-7.9z" /></svg>
                </div>
                <h2 id="oyiso-checkout-maintenance-title" class="oyiso-checkout-maintenance-dialog__title"></h2>
                <p id="oyiso-checkout-maintenance-message" class="oyiso-checkout-maintenance-dialog__message"></p>
                <button type="button" class="oyiso-checkout-maintenance-dialog__button" data-oyiso-maintenance-close></button>
            </div>
        </dialog>
        <?php
    }
}

Oyiso_WC_Checkout_Maintenance::init();
