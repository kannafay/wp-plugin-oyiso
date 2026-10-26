<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Reuse the abilities published by WoodMart MCP and other local MCP plugins.
 * @phpstan-import-type Run from Oyiso_Agent_Store
 */
final class Oyiso_Agent_MCP
{
    public const BUILTIN_NAMESPACE = 'oyiso-woocommerce';
    public const SETTINGS_VERSION = 'agent_plugin_tools_version';

    /** @param array<string, mixed>|null $options
     * @return list<string>
     */
    public static function namespaces(?array $options = null): array
    {
        $options ??= Oyiso_Agent_Config::options();
        $stored = $options['agent_mcp_namespaces'] ?? ['woodmart'];
        $namespaces = is_array($stored) ? array_values(array_filter($stored, 'is_string')) : [];
        // Older settings always enabled built-in WooCommerce tools independently of MCP.
        if (!in_array($options[self::SETTINGS_VERSION] ?? null, [1, '1', 2, '2'], true)) $namespaces[] = self::BUILTIN_NAMESPACE;
        if (!in_array($options[self::SETTINGS_VERSION] ?? null, [2, '2'], true)) $namespaces[] = 'oyiso-wordpress';
        return array_values(array_unique($namespaces));
    }

    public static function builtinEnabled(bool $fresh = false): bool
    {
        return in_array(self::BUILTIN_NAMESPACE, self::namespaces(Oyiso_Agent_Config::options($fresh)), true);
    }

    /** @return array<string, array<string, WP_Ability>> */
    private static function plugins(): array
    {
        $plugins = [];
        if (function_exists('wp_get_ability')) foreach (Oyiso_Agent_Content::TOOLS as $name) { $ability = wp_get_ability('oyiso/' . $name); if ($ability !== null) $plugins['wordpress'][$ability->get_name()] = $ability; }
        $builtins = Oyiso_Agent_Abilities::woocommerce();
        if ($builtins !== []) $plugins['woocommerce'] = $builtins;
        foreach (self::catalog() as $name => $ability) $plugins[explode('/', $name, 2)[0]][$name] = $ability;
        ksort($plugins);
        return isset($plugins['wordpress']) ? ['wordpress' => $plugins['wordpress']] + $plugins : $plugins;
    }

    public static function assets(string $hook): void
    {
        if ($hook !== 'plugins_page_oyiso' || !current_user_can('manage_options') || is_network_admin() || is_user_admin()) return;
        wp_enqueue_style('oyiso-mcp-settings', plugins_url('assets/mcp-settings.css', __FILE__), ['oyiso-admin-theme'], (string) filemtime(__DIR__ . '/assets/mcp-settings.css'));
        wp_enqueue_script('oyiso-mcp-settings', plugins_url('assets/mcp-settings.js', __FILE__), [], (string) filemtime(__DIR__ . '/assets/mcp-settings.js'), true);
    }

    /** @return array<string, WP_Ability> */
    public static function catalog(): array
    {
        $tools = [];
        if (!function_exists('wp_get_abilities')) return $tools;
        foreach (wp_get_abilities() as $ability) {
            $name = $ability->get_name();
            $namespace = explode('/', $name, 2)[0];
            $mcp = $ability->get_meta_item('mcp');
            // Never expose generic dispatchers: they could bypass the selected namespaces.
            if (in_array($namespace, ['oyiso', self::BUILTIN_NAMESPACE, 'oyiso-wordpress', 'mcp-adapter'], true) || !is_array($mcp) || ($mcp['public'] ?? false) !== true) continue;
            $tools[$name] = $ability;
        }
        ksort($tools);
        return $tools;
    }

    /** @return array<string, string> */
    public static function namespaceOptions(): array
    {
        $options = [];
        foreach (self::plugins() as $namespace => $tools) {
            $options[$namespace] = self::namespaceLabel($namespace) . '（' . count($tools) . ' 个工具）';
        }
        return $options;
    }

    /** @return list<string> */
    public static function sanitizeNamespaces(mixed $value): array
    {
        if (!is_array($value)) return [];
        $known = self::namespaceOptions();
        $known[self::BUILTIN_NAMESPACE] = 'WooCommerce 内置工具';
        $known['oyiso-wordpress'] = 'WordPress 内容工具';
        return array_values(array_unique(array_filter($value, static fn(mixed $namespace): bool => is_string($namespace) && isset($known[$namespace]))));
    }

    private static function namespaceLabel(string $namespace): string
    {
        return match ($namespace) {
            'woodmart' => 'WoodMart',
            'woocommerce' => 'WooCommerce',
            'wordpress' => 'WordPress',
            'ast-pro' => 'AST PRO',
            default => $namespace,
        };
    }

    /** @return array<string, WP_Ability> */
    public static function selected(bool $fresh = false): array
    {
        $namespaces = self::namespaces(Oyiso_Agent_Config::options($fresh));
        return array_filter(self::catalog(), static fn(WP_Ability $ability): bool => in_array(explode('/', $ability->get_name(), 2)[0], $namespaces, true));
    }

    public static function readonly(WP_Ability $ability): bool
    {
        $annotations = $ability->get_meta_item('annotations');
        if (!is_array($annotations) || ($annotations['destructive'] ?? false) === true) return false;
        // WoodMart MCP 1.0.2 omits annotations on its block-tree reader.
        $woodmartReader = $ability->get_name() === 'woodmart/gutenberg-get-content' && ($annotations['readonly'] ?? null) === null;
        return ($annotations['readonly'] ?? false) === true || $woodmartReader;
    }

    public static function render(): void
    {
        echo '<div class="oyiso-agent oyiso-agent-mcp-settings oyiso-agent-permission-settings"><p class="description">选择助手可用的工具。默认只读，按来源开启写入权限并保存，执行前仍需在对话中确认。</p>';
        if (!Oyiso_Agent_Config::supported()) {
            echo '<p>AI Agent 需要 WordPress 7.0 或以上版本，当前环境暂不可用。</p></div>';
            return;
        }
        $plugins = self::plugins();
        echo '<input type="hidden" name="oyiso[' . self::SETTINGS_VERSION . ']" value="2"><input type="hidden" name="oyiso[agent_mcp_namespaces][]" value=""><input type="hidden" name="oyiso[agent_write_tools][]" value="">';
        if ($plugins === []) {
            echo '<p>尚未发现可用的工具。</p></div>';
            return;
        }
        $granted = array_fill_keys(Oyiso_Agent_Permissions::configured(), true);
        $namespaces = self::namespaces();
        echo '<div class="oyiso-agent-table-wrap"><table class="widefat striped oyiso-agent-mcp-table oyiso-agent-permissions-table"><thead><tr><th scope="col">来源</th><th scope="col">类型</th><th scope="col">工具</th><th scope="col">启用工具</th><th scope="col">写入权限</th></tr></thead><tbody>';
        foreach ($plugins as $namespace => $abilities) self::renderPlugin($namespace, $abilities, $granted, $namespaces);
        echo '</tbody></table></div>';
        if (isset($plugins['woodmart'])) echo '<p class="description">WoodMart 区块和布局编辑适用于 Gutenberg；待处理的区块修改还需要在 AI Block Queue 中完成。</p>';
        echo '</div>';
    }

    /** @param array<string, WP_Ability> $tools
     * @param array<string, true> $granted
     * @param list<string> $namespaces
     */
    private static function renderPlugin(string $namespace, array $tools, array $granted, array $namespaces): void
    {
        $label = self::namespaceLabel($namespace);
        $writes = array_intersect_key(Oyiso_Agent_Permissions::writable(), $tools);
        $members = array_values(array_unique(array_map([Oyiso_Agent_Permissions::class, 'source'], array_keys($tools))));
        $enabled = count(array_intersect($members, $namespaces));
        $partial = $enabled > 0 && $enabled < count($members);
        $description = match ($namespace) { 'wordpress' => '文章、页面的查询、修改和新建草稿，', 'woocommerce' => '商品管理、订单统计等，', default => '' };
        printf('<tr data-agent-mcp-plugin="%s" data-agent-write-group><th scope="row">%s</th><td>%s</td><td><span>%s共 %d 个工具</span><details><summary>查看工具</summary><ul class="oyiso-agent-permission-tools">', esc_attr($namespace), esc_html($label), $namespace === 'wordpress' ? '系统' : '插件', esc_html($description), count($tools));
        foreach ($tools as $ability) {
            $kind = isset($writes[$ability->get_name()]) ? '写入' : (Oyiso_Agent_Permissions::required($ability->get_name()) !== null ? '方案预览' : '只读');
            if ($ability->get_name() === 'oyiso/dismiss-product-trash') $kind = '取消方案';
            printf('<li><span>%s</span><span class="description">%s</span></li>', esc_html($ability->get_label()), $kind);
        }
        echo '</ul></details>';
        if ($namespace === 'woodmart') echo '<p class="description"><a href="' . esc_url(admin_url('admin.php?page=xts_mcp')) . '">WoodMart MCP 设置</a> · <a href="https://woodmarttheme.com/docs/woodmart-mcp/" target="_blank" rel="noopener noreferrer">接入说明</a></p>';
        echo '</td><td>';
        printf('<label><input type="checkbox" data-agent-plugin-toggle data-partial="%s" aria-label="%s"%s> 启用</label>', $partial ? 'true' : 'false', esc_attr('启用 ' . $label . ' 工具'), checked($enabled === count($members), true, false));
        if ($partial) echo '<span class="description oyiso-agent-plugin-partial" data-agent-plugin-partial>部分工具已启用</span>';
        foreach ($members as $member) printf('<input type="hidden" data-agent-plugin-member name="oyiso[agent_mcp_namespaces][]" value="%s"%s>', esc_attr($member), disabled(!in_array($member, $namespaces, true), true, false));
        echo '</td><td>';
        echo Oyiso_Agent_Permissions::groupCell($writes, $granted, $label) . '</td></tr>';
    }

    /** @param array<string, mixed> $input
     * @param Run $run
     * @return array<string, mixed>|WP_Error
     */
    public static function call(string $operation, array $input, array &$run): array|WP_Error
    {
        $granted = array_fill_keys(Oyiso_Agent_Permissions::granted(true), true);
        $selected = self::selected(true);
        $tools = array_filter($selected, static fn(WP_Ability $ability): bool => self::readonly($ability) || isset($granted[$ability->get_name()]));
        if ($operation === 'mcp-list-tools') {
            $search = is_string($input['search'] ?? null) ? mb_strtolower($input['search']) : '';
            $items = [];
            foreach ($tools as $name => $ability) {
                if ($search !== '' && !str_contains(mb_strtolower($name . ' ' . $ability->get_label() . ' ' . $ability->get_description()), $search)) continue;
                $items[] = ['name' => $name, 'label' => $ability->get_label(), 'description' => mb_substr($ability->get_description(), 0, 600), 'readonly' => self::readonly($ability)];
            }
            $page = is_int($input['page'] ?? null) ? max(1, $input['page']) : 1;
            return ['tools' => array_slice($items, ($page - 1) * 15, 15), 'total' => count($items), 'page' => $page];
        }
        $name = is_string($input['name'] ?? null) ? $input['name'] : '';
        $ability = $tools[$name] ?? null;
        if ($ability === null) {
            $disabled = $selected[$name] ?? null;
            if ($disabled !== null && !self::readonly($disabled)) return Oyiso_Agent_Permissions::denied($name);
            return new WP_Error('agent_mcp_unavailable', '该 MCP 工具未启用或已不可用。');
        }
        if (!self::readonly($ability)) {
            $permission = Oyiso_Agent_Permissions::check($name);
            if (is_wp_error($permission)) return $permission;
        }
        if ($operation === 'mcp-describe-tool') {
            return ['name' => $name, 'description' => $ability->get_description(), 'input_schema' => $ability->get_input_schema(), 'readonly' => self::readonly($ability), 'notice' => self::readonly($ability) ? '只读工具可直接调用。' : '修改类工具仅生成待确认操作，用户在界面中批准后才会执行。'];
        }
        $json = is_string($input['input_json'] ?? null) ? $input['input_json'] : '{}';
        $args = json_decode($json, true);
        if (!str_starts_with(ltrim($json), '{') || !is_array($args) || json_last_error() !== JSON_ERROR_NONE) return new WP_Error('agent_mcp_input', '工具参数须为 JSON 对象。');
        // Match MCP's empty-arguments behavior, including WoodMart's optional-input defaults.
        $args = $args === [] && array_key_exists('default', $ability->get_input_schema()) ? $ability->normalize_input(null) : $args;
        if (is_wp_error($args)) return $args;
        $valid = $ability->validate_input($args);
        if (is_wp_error($valid)) return $valid;
        if ($ability->check_permissions($args) !== true) return new WP_Error('agent_mcp_forbidden', '当前账号没有执行该 MCP 工具的权限。');
        if (self::readonly($ability)) return self::result($ability->execute($args));
        $actions = $run['mcp_actions'] ?? [];
        foreach ($actions as $action) {
            if ($action['name'] === $name && $action['input_json'] === $json && in_array($action['status'], ['pending', 'executing'], true)) return ['status' => $action['status'], 'action_id' => $action['id'], 'notice' => '此操作正在等待用户确认或执行，请勿重复提交。'];
        }
        if (count($actions) >= 20) return new WP_Error('agent_mcp_limit', '本次对话的 MCP 修改操作已达上限，请新建对话。');
        $id = wp_generate_uuid4();
        $run['mcp_actions'][] = ['id' => $id, 'name' => $name, 'label' => $ability->get_label(), 'input_json' => $json, 'status' => 'pending', 'result' => ''];
        return ['status' => 'pending', 'action_id' => $id, 'notice' => '操作尚未执行，用户须在待确认卡片中批准。'];
    }

    /** @param Run $run */
    public static function pending(array $run): bool
    {
        return count(array_filter($run['mcp_actions'] ?? [], static fn(array $action): bool => $action['status'] === 'pending')) > 0;
    }

    /** @param Run $run */
    public static function approve(array &$run, string $id, string $decision): true|WP_Error
    {
        if (!Oyiso_Agent_Config::enabled() || !current_user_can('manage_options') || !Oyiso_Agent_Store::owned($run['id']) || Oyiso_Agent_Store::cancelled($run['id'])) return new WP_Error('agent_mcp_forbidden', '当前任务已取消或没有执行权限。', ['status' => 403]);
        if (!in_array($decision, ['execute', 'dismiss'], true)) return new WP_Error('agent_mcp_decision', '操作无效。', ['status' => 400]);
        $run['mcp_actions'] ??= [];
        foreach ($run['mcp_actions'] as $index => $action) {
            if ($action['id'] !== $id) continue;
            if ($action['status'] !== 'pending') return new WP_Error('agent_mcp_state', '此操作已处理，不能重复执行。', ['status' => 409]);
            if ($decision === 'dismiss') {
                $run['mcp_actions'][$index] = array_replace($action, ['status' => 'dismissed']);
                $run['messages'][] = (new \WordPress\AiClient\Messages\DTO\UserMessage([new \WordPress\AiClient\Messages\DTO\MessagePart('用户已取消 MCP 操作 ' . $action['name'] . '（' . $id . '），尚未执行。用户再次明确要求之前，不要重新提交。')]))->toArray();
                return true;
            }
            $ability = self::selected(true)[$action['name']] ?? null;
            if ($ability === null) return new WP_Error('agent_mcp_unavailable', '该 MCP 工具未启用或已不可用。', ['status' => 400]);
            // Recheck the saved grant even for an old card or a changed tool annotation.
            $permission = Oyiso_Agent_Permissions::check($action['name']);
            if (is_wp_error($permission)) return $permission;
            $input = json_decode($action['input_json'], true);
            // Persist before the write: a disconnected client must never repeat a mutation.
            $run['mcp_actions'][$index] = array_replace($action, ['status' => 'executing']);
            Oyiso_Agent_Store::save($run);
            try {
                $result = self::result($ability->execute($input === [] && array_key_exists('default', $ability->get_input_schema()) ? null : $input));
            } catch (Throwable $error) {
                $result = new WP_Error('agent_mcp_execute', Oyiso_Agent_Config::error($error->getMessage()));
            }
            $failed = is_wp_error($result);
            $summary = $failed ? Oyiso_Agent_Config::error($result->get_error_message()) : self::json($result);
            $run['mcp_actions'][$index] = array_replace($action, ['status' => $failed ? 'failed' : 'completed', 'result' => $summary]);
            Oyiso_Agent_Store::event($run, $failed ? 'error' : 'success', $action['label'], $failed ? $summary : '用户已确认并执行 MCP 操作。');
            $run['messages'][] = (new \WordPress\AiClient\Messages\DTO\UserMessage([new \WordPress\AiClient\Messages\DTO\MessagePart('MCP 操作结果（工具资料）：' . self::json(['name' => $action['name'], 'status' => $failed ? 'failed' : 'completed', 'result' => $summary]))]))->toArray();
            return true;
        }
        return new WP_Error('agent_mcp_missing', '待确认操作不存在。', ['status' => 404]);
    }

    /** @return array<string, mixed>|WP_Error */
    private static function result(mixed $result): array|WP_Error
    {
        if (is_wp_error($result)) return $result;
        $json = self::json($result);
        if (strlen($json) > 24000) return ['output' => mb_strcut($json, 0, 24000), 'truncated' => true, 'notice' => '工具结果过长，已截断。请缩小查询范围。'];
        return ['output' => $result];
    }

    private static function json(mixed $value): string
    {
        return (string) wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
