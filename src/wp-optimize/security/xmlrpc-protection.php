<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

final class Oyiso_Xmlrpc_Protection
{
    public static function register(bool $enabled): void
    {
        if (!$enabled) {
            return;
        }

        add_filter('xmlrpc_enabled', '__return_false');
        add_action('init', [self::class, 'blockRequest'], 0);
        remove_action('wp_head', 'rsd_link');
    }

    public static function blockRequest(): void
    {
        if (!defined('XMLRPC_REQUEST') || !XMLRPC_REQUEST) {
            return;
        }

        // xmlrpc_enabled alone does not disable unauthenticated calls such as pingbacks.
        // Block before WordPress constructs its XML-RPC server or exposes the RSD document.
        status_header(403);
        nocache_headers();
        header('Content-Type: text/plain; charset=utf-8');
        exit('XML-RPC access is disabled.');
    }
}
