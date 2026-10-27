<?php

declare(strict_types=1);

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) { http_response_code(404); exit; }

// Exercise the actual frontend bootstrap, without the admin framework loaded by bootstrap.php.
define('DISABLE_WP_CRON', true);
$_SERVER['HTTP_HOST'] = 'vape.local'; $_SERVER['SERVER_NAME'] = 'vape.local';
$_SERVER['REQUEST_URI'] = '/'; $_SERVER['SERVER_PORT'] = '80'; $_SERVER['REQUEST_METHOD'] = 'GET';
$root = getenv('OYISO_WORDPRESS_ROOT') ?: dirname(__DIR__, 5);
require_once $root . '/wp-includes/plugin.php';
add_filter('pre_http_request', static fn(mixed $pre): mixed => $pre === false ? new WP_Error('frontend_test_network', 'Outbound HTTP blocked during tests.') : $pre, PHP_INT_MAX);
add_filter('pre_wp_mail', '__return_true', PHP_INT_MIN);
add_filter('action_scheduler_allow_async_request_runner', '__return_false');
require_once $root . '/wp-load.php';
wp_set_current_user((int) ($argv[1] ?? 0));
$before = class_exists('CSF');
Oyiso_Agent_Admin::frontendAssets();
ob_start(); Oyiso_Agent_Admin::panel(); $markup = ob_get_clean();
echo wp_json_encode(['admin' => is_admin(), 'settings_framework_unchanged' => $before === class_exists('CSF'), 'script' => wp_script_is('oyiso-agent', 'enqueued'), 'modals' => wp_style_is('oyiso-modals', 'enqueued'), 'frontend_style' => wp_style_is('oyiso-agent-frontend', 'enqueued'), 'panel' => str_contains($markup, 'id="oyiso-agent-panel"')], JSON_UNESCAPED_UNICODE);
