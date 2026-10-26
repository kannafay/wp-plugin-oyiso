<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/** @phpstan-import-type Run from Oyiso_Agent_Store
 * @phpstan-type PluginInfo array{file:string,name:string,version:string,status:string,fingerprint:string}
 */
final class Oyiso_Agent_Plugins
{
    public const TOOLS = ['list-plugins', 'activate-plugin', 'deactivate-plugin'];
    public const ACTIONS = ['oyiso/activate-plugin', 'oyiso/deactivate-plugin'];

    public static function enabled(bool $fresh = false): bool
    {
        return in_array('oyiso-wordpress', Oyiso_Agent_MCP::namespaces(Oyiso_Agent_Config::options($fresh)), true);
    }

    /** @param Run $run
     * @param array<string,mixed> $input
     * @return array<string,mixed>|WP_Error
     */
    public static function call(array &$run, string $name, array $input): array|WP_Error
    {
        if (!self::enabled(true) || !current_user_can('activate_plugins')) return new WP_Error('agent_plugin_management_denied', '请启用 WordPress 工具，并确认当前账号有插件管理权限。', ['status' => 403]);
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        if ($name === 'list-plugins') return self::query($run, $input);
        if (!in_array('oyiso/' . $name, self::ACTIONS, true)) return new WP_Error('agent_unknown_tool', '插件工具不存在。');
        $permission = Oyiso_Agent_Permissions::check('oyiso/' . $name);
        if (is_wp_error($permission)) return $permission;
        $file = is_string($input['plugin_file'] ?? null) ? $input['plugin_file'] : '';
        $plugin = self::inspect($file);
        if (is_wp_error($plugin)) return $plugin;
        if (($run['plugin_snapshots'][$file] ?? '') !== $plugin['fingerprint']) return new WP_Error('agent_plugin_read_required', '请先在本轮查询插件并核对最新状态，再生成启停方案。');
        $activate = $name === 'activate-plugin';
        $check = self::check($file, $activate);
        if (is_wp_error($check)) return $check;
        $target = $activate ? 'active' : 'inactive';
        if ($plugin['status'] === $target) return ['status' => 'unchanged', 'plugin_file' => $file, 'notice' => $activate ? '插件已经启用，无需重复执行。' : '插件已经停用，无需重复执行。'];
        foreach ($run['mcp_actions'] ?? [] as $action) {
            if ($action['name'] === 'oyiso/' . $name && ($action['plugin']['file'] ?? '') === $file && in_array($action['status'], ['pending', 'executing'], true) && ($action['plugin_fingerprint'] ?? '') === $plugin['fingerprint']) return ['status' => $action['status'], 'action_id' => $action['id'], 'notice' => '此插件操作已在等待确认或执行，请勿重复提交。'];
        }
        if (count($run['mcp_actions'] ?? []) >= 20) return new WP_Error('agent_plugin_action_limit', '本次对话的操作方案已达上限，请新建对话。');
        $id = wp_generate_uuid4();
        $run['mcp_actions'][] = [
            'id' => $id, 'name' => 'oyiso/' . $name, 'label' => ($activate ? '启用插件：' : '停用插件：') . $plugin['name'],
            'input_json' => (string) wp_json_encode(['plugin_file' => $file]), 'status' => 'pending', 'result' => '',
            'plugin' => ['file' => $file, 'name' => $plugin['name'], 'version' => $plugin['version'], 'before' => $plugin['status'], 'after' => $target],
            'plugin_fingerprint' => $plugin['fingerprint'],
        ];
        return ['status' => 'pending', 'action_id' => $id, 'plugin_file' => $file, 'notice' => '插件状态尚未改变。必须由用户在卡片中确认后执行。'];
    }

    /** Called only after the authenticated confirmation endpoint has durably claimed this action.
     * @param Run $run
     * @return array<string,mixed>|WP_Error
     */
    public static function apply(array $run, string $id): array|WP_Error
    {
        if (!Oyiso_Agent_Config::enabled() || !self::enabled(true) || !current_user_can('manage_options') || !Oyiso_Agent_Store::owned($run['id']) || Oyiso_Agent_Store::cancelled($run['id'])) return new WP_Error('agent_plugin_management_denied', '当前任务没有插件执行权限。', ['status' => 403]);
        $stored = Oyiso_Agent_Store::get($run['id']);
        if (is_wp_error($stored)) return $stored;
        $action = null;
        foreach ($stored['mcp_actions'] ?? [] as $candidate) if ($candidate['id'] === $id) { $action = $candidate; break; }
        if ($action === null || $action['status'] !== 'executing' || !in_array($action['name'], self::ACTIONS, true) || !isset($action['plugin'], $action['plugin_fingerprint'])) return new WP_Error('agent_plugin_confirmation_required', '插件操作尚未得到有效确认。', ['status' => 403]);
        $permission = Oyiso_Agent_Permissions::check($action['name']);
        if (is_wp_error($permission)) return $permission;
        $file = $action['plugin']['file'];
        $lock = 'plugin_' . hash('sha256', $file);
        if (!Oyiso_Agent_Store::lock($lock)) return new WP_Error('agent_plugin_busy', '此插件正在由另一项操作处理，请查询最新状态后重试。', ['status' => 409]);
        try {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            $plugin = self::inspect($file);
            if (is_wp_error($plugin)) return $plugin;
            if (!hash_equals($action['plugin_fingerprint'], $plugin['fingerprint'])) return new WP_Error('agent_plugin_changed', '插件文件、版本或启用状态已变化，旧方案已失效。请重新查询并生成方案。', ['status' => 409]);
            $activate = $action['name'] === 'oyiso/activate-plugin';
            $check = self::check($file, $activate);
            if (is_wp_error($check)) return $check;
            $level = ob_get_level();
            ob_start();
            try {
                if ($activate) {
                    $result = activate_plugin($file, '', false, false);
                    if (is_wp_error($result)) return new WP_Error('agent_plugin_activation_failed', '启用返回错误，当前状态为' . (is_plugin_active($file) ? '已启用' : '未启用') . '。' . wp_strip_all_tags($result->get_error_message()));
                } else {
                    deactivate_plugins($file, false, false);
                }
            } finally {
                while (ob_get_level() > $level) ob_end_clean();
            }
            if (is_plugin_active($file) !== $activate) return new WP_Error('agent_plugin_state_mismatch', '插件未达到预期状态，请查询插件状态后核对。');
            return ['plugin_file' => $file, 'name' => $plugin['name'], 'status' => $activate ? 'active' : 'inactive', 'notice' => $activate ? '插件已启用。' : '插件已停用。'];
        } finally { Oyiso_Agent_Store::unlock($lock); }
    }

    /** @param Run $run
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private static function query(array &$run, array $input): array
    {
        $search = is_string($input['search'] ?? null) ? mb_strtolower($input['search']) : '';
        $status = $input['status'] ?? 'all';
        $page = max(1, is_int($input['page'] ?? null) ? $input['page'] : 1);
        $items = [];
        foreach (array_keys(get_plugins()) as $file) {
            $plugin = self::inspect($file);
            if (is_wp_error($plugin)) continue;
            if ($search !== '' && !str_contains(mb_strtolower($plugin['name'] . ' ' . $file), $search)) continue;
            if ($status === 'active' && $plugin['status'] === 'inactive' || $status === 'inactive' && $plugin['status'] !== 'inactive') continue;
            $items[] = $plugin;
        }
        $total = count($items);
        $items = array_slice($items, ($page - 1) * 10, 10);
        foreach ($items as &$item) { $run['plugin_snapshots'][$item['file']] = $item['fingerprint']; unset($item['fingerprint']); }
        unset($item);
        return ['plugins' => $items, 'page' => $page, 'pages' => (int) ceil($total / 10), 'total' => $total, 'notice' => '使用本页返回的完整插件文件标识生成方案。启用和停用均须用户在卡片中确认；仅管理普通站点插件。'];
    }

    /** @return PluginInfo|WP_Error */
    private static function inspect(string $file): array|WP_Error
    {
        if ($file === '' || validate_file($file) !== 0 || !isset(get_plugins()[$file]) || !is_file(WP_PLUGIN_DIR . '/' . $file)) return new WP_Error('agent_plugin_missing', '插件不存在，请重新查询并使用准确的插件文件标识。', ['status' => 404]);
        $headers = get_plugin_data(WP_PLUGIN_DIR . '/' . $file, false, false);
        $name = wp_strip_all_tags($headers['Name']);
        $version = $headers['Version'];
        $status = is_plugin_active_for_network($file) ? 'network_active' : (is_plugin_active($file) ? 'active' : 'inactive');
        clearstatcache(true, WP_PLUGIN_DIR . '/' . $file);
        $json = wp_json_encode(['file' => $file, 'version' => $version, 'status' => $status, 'headers' => $headers, 'hash' => hash_file('sha256', WP_PLUGIN_DIR . '/' . $file)], JSON_INVALID_UTF8_SUBSTITUTE);
        return ['file' => $file, 'name' => $name, 'version' => $version, 'status' => $status, 'fingerprint' => hash('sha256', $json !== false ? $json : $file . $version . $status)];
    }

    private static function check(string $file, bool $activate): true|WP_Error
    {
        if (!current_user_can($activate ? 'activate_plugin' : 'deactivate_plugin', $file)) return new WP_Error('agent_plugin_management_denied', '当前账号没有此插件的启停权限。', ['status' => 403]);
        if ($file === plugin_basename(dirname(__DIR__, 2) . '/oyiso.php') && !$activate) return new WP_Error('agent_plugin_self', '不能通过助手停用提供本助手的插件，请到 WordPress 插件页面操作。');
        if (is_plugin_active_for_network($file) || is_multisite() && is_network_only_plugin($file)) return new WP_Error('agent_plugin_network', '网络启用或仅限网络的插件，请在网络后台管理。');
        if ($activate) return validate_plugin_requirements($file);
        WP_Plugin_Dependencies::initialize();
        if (WP_Plugin_Dependencies::has_active_dependents($file)) return new WP_Error('agent_plugin_dependency', '仍有已启用的插件依赖此插件，请先处理依赖插件。');
        return true;
    }
}
