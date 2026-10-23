<?php

declare(strict_types=1);

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
    http_response_code(404);
    exit;
}

// Integration checks use temporary products/tasks and block outbound HTTP/mail.
define('DISABLE_WP_CRON', true);
define('WP_ADMIN', true);
define('DOING_AJAX', true);
$_SERVER['HTTP_HOST'] = 'vape.local';
$_SERVER['SERVER_NAME'] = 'vape.local';
$_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['REQUEST_METHOD'] = 'POST';
$agentWpRoot = getenv('OYISO_WORDPRESS_ROOT') ?: dirname(__DIR__, 5);
if (!is_file($agentWpRoot . '/wp-load.php')) {
    throw new RuntimeException('请通过 OYISO_WORDPRESS_ROOT 指定本地 WordPress 安装目录。');
}
require_once $agentWpRoot . '/wp-includes/plugin.php';
add_filter('pre_http_request', static fn(mixed $pre): mixed => $pre === false ? new WP_Error('agent_test_network_blocked', 'Outbound HTTP blocked during tests.') : $pre, PHP_INT_MAX);
add_filter('pre_wp_mail', '__return_true', PHP_INT_MIN);
add_filter('action_scheduler_allow_async_request_runner', '__return_false');
require_once $agentWpRoot . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';
if (!function_exists('wc_get_product') || !Oyiso_Agent_Config::supported()) {
    throw new RuntimeException('集成检查需要 WordPress 7.0+ 和 WooCommerce。');
}
// Keep temporary conversations out of real administrators' history while checks run.
$agentTestAdmin = wp_insert_user(['user_login' => 'oyiso-agent-test-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(48), 'role' => 'administrator']);
if (is_wp_error($agentTestAdmin)) throw new RuntimeException('无法创建临时测试账号。');
define('OYISO_AGENT_TEST_ADMIN', $agentTestAdmin);
register_shutdown_function(static function (): void {
    wp_set_current_user(OYISO_AGENT_TEST_ADMIN);
    foreach (get_posts(['post_type' => Oyiso_Agent_Store::TYPE, 'post_status' => 'any', 'author' => OYISO_AGENT_TEST_ADMIN, 'numberposts' => -1, 'fields' => 'ids']) as $id) {
        Oyiso_Agent_Store::cancel($id);
        Oyiso_Agent_Attachments::eraseRun($id);
        wp_delete_post($id, true);
    }
    wp_delete_user(OYISO_AGENT_TEST_ADMIN);
});
wp_set_current_user(OYISO_AGENT_TEST_ADMIN);
wp_get_abilities();
