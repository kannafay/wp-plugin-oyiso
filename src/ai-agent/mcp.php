<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Reuse the abilities published by WoodMart MCP and other local MCP plugins.
 * @phpstan-import-type Run from Oyiso_Agent_Store
 */
final class Oyiso_Agent_MCP
{
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
            if (in_array($namespace, ['oyiso', 'mcp-adapter'], true) || !is_array($mcp) || ($mcp['public'] ?? false) !== true) continue;
            $tools[$name] = $ability;
        }
        ksort($tools);
        return $tools;
    }

    /** @return array<string, string> */
    public static function namespaceOptions(): array
    {
        $counts = [];
        foreach (self::catalog() as $name => $ability) {
            $namespace = explode('/', $name, 2)[0];
            $counts[$namespace] = ($counts[$namespace] ?? 0) + 1;
        }
        $options = [];
        foreach ($counts as $namespace => $count) {
            $options[$namespace] = ($namespace === 'woodmart' ? 'WoodMart' : $namespace) . '（' . $count . ' 个工具）';
        }
        return $options;
    }

    /** @return array<string, WP_Ability> */
    public static function selected(): array
    {
        $namespaces = Oyiso_Agent_Config::options()['agent_mcp_namespaces'] ?? ['woodmart'];
        if (!is_array($namespaces)) return [];
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
        echo '<div class="oyiso-agent"><p>本站直接复用 WoodMart MCP 和其他插件公开的工具，无需另存应用程序密码。应用程序密码用于 Claude、Codex 等外部客户端连接本站。</p><p><a href="' . esc_url(admin_url('admin.php?page=xts_mcp')) . '">WoodMart MCP 设置</a> · <a href="https://woodmarttheme.com/docs/woodmart-mcp/" target="_blank" rel="noopener noreferrer">接入说明</a></p>';
        $tools = self::catalog();
        if ($tools === []) {
            echo '<p>尚未发现 MCP 工具。请先启用 WoodMart MCP 或支持 WordPress Abilities API 的插件。</p></div>';
            return;
        }
        echo '<div class="oyiso-agent-table-wrap"><table class="widefat striped oyiso-agent-mcp-table"><thead><tr><th>工具</th><th>用途</th><th>执行方式</th></tr></thead><tbody>';
        foreach ($tools as $name => $ability) {
            printf('<tr><td>%s<br><small>%s</small></td><td>%s</td><td>%s</td></tr>', esc_html($ability->get_label()), esc_html($name), esc_html(mb_substr($ability->get_description(), 0, 220)), esc_html(self::readonly($ability) ? '只读查询' : '对话中确认后执行'));
        }
        echo '</tbody></table></div><p class="description">WoodMart 区块和布局编辑适用于 Gutenberg；待处理的区块修改还需要在 AI Block Queue 中完成。</p></div>';
    }

    /** @param array<string, mixed> $input
     * @param Run $run
     * @return array<string, mixed>|WP_Error
     */
    public static function call(string $operation, array $input, array &$run): array|WP_Error
    {
        $tools = self::selected();
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
        if ($ability === null) return new WP_Error('agent_mcp_unavailable', '该 MCP 工具未启用或已不可用。');
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
            $ability = self::selected()[$action['name']] ?? null;
            if ($ability === null) return new WP_Error('agent_mcp_unavailable', '该 MCP 工具未启用或已不可用。', ['status' => 400]);
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
