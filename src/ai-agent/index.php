<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/personalization.php';
require_once __DIR__ . '/connections.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/private-files.php';
require_once __DIR__ . '/attachments.php';
require_once __DIR__ . '/file-migration.php';
require_once __DIR__ . '/file-manager.php';
require_once __DIR__ . '/mcp.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/managed-connections.php';
require_once __DIR__ . '/model-catalog.php';
require_once __DIR__ . '/model-settings.php';
require_once __DIR__ . '/product-trash.php';
require_once __DIR__ . '/page-context.php';
require_once __DIR__ . '/content.php';
require_once __DIR__ . '/tools.php';
require_once __DIR__ . '/abilities.php';
require_once __DIR__ . '/model.php';
require_once __DIR__ . '/runner.php';
require_once __DIR__ . '/live.php';
require_once __DIR__ . '/admin.php';
require_once __DIR__ . '/settings.php';

// Expose legacy preferences to both the native settings form and model before the next save.
add_filter('option_oyiso', static fn(mixed $options): mixed => is_array($options) ? Oyiso_Agent_Personalization::mergePreferences($options) : $options);
add_filter('csf_oyiso_save', [Oyiso_Agent_Config::class, 'saveOptions']);
add_action('init', [Oyiso_Agent_Connectors::class, 'register'], 5);
add_action('wp_connectors_init', [Oyiso_Agent_Connectors::class, 'connectors']);
add_action('init', [Oyiso_Agent_Store::class, 'register']);
add_action('init', [Oyiso_Agent_Attachments::class, 'register']);
add_action('before_delete_post', [Oyiso_Agent_Attachments::class, 'cleanup'], 10, 2);
add_action('transition_post_status', [Oyiso_Agent_Product_Trash::class, 'statusChanged'], 10, 3);
add_action('admin_init', [Oyiso_Agent_File_Migration::class, 'schedule']);
add_action(Oyiso_Agent_File_Migration::HOOK, [Oyiso_Agent_File_Migration::class, 'batch']);
add_action('wp_abilities_api_categories_init', [Oyiso_Agent_Abilities::class, 'categories']);
add_action('wp_abilities_api_init', [Oyiso_Agent_Abilities::class, 'register']);
add_action('rest_api_init', [Oyiso_Agent_Admin::class, 'routes']);
add_filter('rest_pre_serve_request', [Oyiso_Agent_Live::class, 'serve'], 10, 4);
add_action('admin_enqueue_scripts', [Oyiso_Agent_Admin::class, 'assets']);
add_action('admin_footer', [Oyiso_Agent_Admin::class, 'panel']);
add_action(Oyiso_Agent_Runner::HOOK, static function (int $id): void {
    (new Oyiso_Agent_Runner(new Oyiso_Agent_Model()))->step($id);
});
