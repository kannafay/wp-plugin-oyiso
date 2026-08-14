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

require_once __DIR__ . '/order-image-forwarder.php';
