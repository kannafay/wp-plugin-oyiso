<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

if (!function_exists('oyiso_sanitize_wecom_webhook_key')) {
    function oyiso_sanitize_wecom_webhook_key(mixed $value): string {
        if (!is_scalar($value)) {
            return '';
        }

        $key = trim((string) $value);

        return (string) preg_replace('/[^A-Za-z0-9_-]+/', '', $key);
    }
}

if (!function_exists('oyiso_is_valid_wecom_webhook_key')) {
    function oyiso_is_valid_wecom_webhook_key(string $key): bool {
        return strlen($key) <= 128
            && 1 === preg_match('/^[A-Za-z0-9_-]+$/', $key);
    }
}

if (!function_exists('oyiso_get_wecom_forward_options')) {
    /**
     * @return array<string, mixed>
     */
    function oyiso_get_wecom_forward_options(): array {
        $options = get_option('oyiso', []);

        if (!is_array($options)) {
            return [];
        }

        $forwardOptions = $options['woo_new_order_email_forward_options'] ?? [];

        return is_array($forwardOptions) ? $forwardOptions : [];
    }
}

if (!function_exists('oyiso_sanitize_order_image_forward_options')) {
    /**
     * @return array<string, mixed>
     */
    function oyiso_sanitize_order_image_forward_options(mixed $value): array {
        if (!is_array($value)) {
            return [];
        }

        $sanitized = wp_kses_post_deep($value);
        $webhooks  = $value['wecom_webhooks'] ?? [];
        $rows      = [];

        if (is_array($webhooks)) {
            foreach (array_slice($webhooks, 0, 20) as $webhook) {
                if (!is_array($webhook)) {
                    continue;
                }

                $name = $webhook['name'] ?? '';
                $name = is_scalar($name)
                    ? sanitize_text_field((string) $name)
                    : '';
                $name = '' !== trim($name) ? trim($name) : '企业微信群';
                $name = function_exists('mb_substr')
                    ? mb_substr($name, 0, 50)
                    : substr($name, 0, 150);

                $rows[] = [
                    'name'               => $name,
                    'enabled'            => !empty($webhook['enabled']),
                    'wecom_webhook_key'  => oyiso_sanitize_wecom_webhook_key(
                        $webhook['wecom_webhook_key'] ?? ''
                    ),
                ];
            }
        }

        $sanitized['wecom_webhooks'] = $rows;

        if (array_key_exists('wecom_webhook_key', $value)) {
            $sanitized['wecom_webhook_key'] = oyiso_sanitize_wecom_webhook_key(
                $value['wecom_webhook_key']
            );
        }

        if (array_key_exists('wecom_order_image_forward', $value)) {
            $sanitized['wecom_order_image_forward'] = !empty(
                $value['wecom_order_image_forward']
            );
        }

        return $sanitized;
    }
}

if (!function_exists('oyiso_get_legacy_wecom_webhook_key')) {
    function oyiso_get_legacy_wecom_webhook_key(): string {
        $options = oyiso_get_wecom_forward_options();
        $key     = $options['wecom_webhook_key'] ?? '';

        if (!is_string($key)) {
            return '';
        }

        $key = trim($key);

        return oyiso_is_valid_wecom_webhook_key($key) ? $key : '';
    }
}

if (!function_exists('oyiso_get_wecom_webhook_group_default')) {
    /**
     * Preserve the former single-key configuration until the settings are saved.
     *
     * @return array<int, array{name: string, enabled: bool, wecom_webhook_key: string}>
     */
    function oyiso_get_wecom_webhook_group_default(): array {
        $options = oyiso_get_wecom_forward_options();
        $key     = oyiso_get_legacy_wecom_webhook_key();

        if ('' !== $key) {
            return [[
                'name'               => '企业微信群',
                'enabled'            => !empty($options['wecom_order_image_forward']),
                'wecom_webhook_key'  => $key,
            ]];
        }

        return [];
    }
}

if (!function_exists('oyiso_get_enabled_wecom_webhook_keys')) {
    /**
     * @return array<int, string>
     */
    function oyiso_get_enabled_wecom_webhook_keys(): array {
        $options = oyiso_get_wecom_forward_options();
        $keys    = [];

        if (array_key_exists('wecom_webhooks', $options)) {
            $webhooks = $options['wecom_webhooks'];

            if (!is_array($webhooks)) {
                return [];
            }

            foreach ($webhooks as $webhook) {
                if (!is_array($webhook) || empty($webhook['enabled'])) {
                    continue;
                }

                $key = $webhook['wecom_webhook_key'] ?? '';

                if (!is_string($key)) {
                    continue;
                }

                $key = trim($key);

                if (oyiso_is_valid_wecom_webhook_key($key)) {
                    $keys[$key] = $key;
                }
            }

            return array_values($keys);
        }

        if (empty($options['wecom_order_image_forward'])) {
            return [];
        }

        $legacyKey = oyiso_get_legacy_wecom_webhook_key();

        return '' !== $legacyKey ? [$legacyKey] : [];
    }
}

if (!function_exists('oyiso_has_enabled_wecom_webhook')) {
    function oyiso_has_enabled_wecom_webhook(): bool {
        return [] !== oyiso_get_enabled_wecom_webhook_keys();
    }
}

require_once __DIR__ . '/order-image-forwarder.php';
