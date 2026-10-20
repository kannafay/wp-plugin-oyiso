<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/** @phpstan-type AgentConnection array{id:string, name:string, preset:string, url:string, protocol:string, models:list<string>} */
final class Oyiso_Agent_Connections
{
    private const OPTION = 'oyiso_agent_connections';

    /** @return list<AgentConnection> */
    public static function all(): array
    {
        $stored = get_option(self::OPTION, null);
        if (is_array($stored)) {
            /** @var list<AgentConnection> $stored */
            return $stored;
        }
        $rows = [];
        foreach (Oyiso_Agent_Connectors::presets() as $preset => $defaults) {
            if (!Oyiso_Agent_Config::hasSecret($preset . '_key') && Oyiso_Agent_Config::text('provider') !== 'oyiso-' . $preset) {
                continue;
            }
            $id = 'legacy-' . $preset;
            $key = Oyiso_Agent_Config::secret($preset . '_key');
            if ($key !== '' && !Oyiso_Agent_Config::setSecret(self::secretName($id), $key)) {
                continue;
            }
            $rows[] = ['id' => $id, 'name' => $defaults['name'], 'preset' => $preset, 'url' => Oyiso_Agent_Config::text($preset . '_url') ?: $defaults['url'], 'protocol' => 'openai_chat', 'models' => [Oyiso_Agent_Config::text($preset . '_model') ?: $defaults['model']]];
        }
        // add_option keeps simultaneous first reads from overwriting an existing migration.
        add_option(self::OPTION, $rows, '', false);
        $saved = get_option(self::OPTION, $rows);
        /** @var list<AgentConnection> $saved */
        $options = Oyiso_Agent_Config::options();
        if (!array_key_exists('agent_selection', $options)) {
            $provider = Oyiso_Agent_Config::text('provider');
            foreach ($saved as $row) {
                if (isset($row['models'][0]) && ($provider === 'oyiso-' . $row['preset'] || ($provider === '' && Oyiso_Agent_Config::hasSecret(self::secretName($row['id']))))) {
                    $options['agent_selection'] = $row['id'] . '|' . $row['models'][0];
                    update_option('oyiso', $options, false);
                    break;
                }
            }
        }
        return $saved;
    }

    public static function secretName(string $id): string
    {
        return 'connection_' . $id . '_key';
    }

    /** @return list<array<string, mixed>> */
    public static function view(): array
    {
        return array_map(static fn(array $row): array => array_intersect_key($row, array_flip(['id', 'name', 'preset', 'url', 'protocol', 'models'])) + ['configured' => Oyiso_Agent_Config::hasSecret(self::secretName($row['id']))], self::all());
    }

    public static function selection(): string
    {
        $rows = self::all();
        $options = Oyiso_Agent_Config::options();
        if (isset($options['agent_selection']) && is_string($options['agent_selection'])) {
            return $options['agent_selection'];
        }
        $provider = Oyiso_Agent_Config::text('provider');
        $preset = Oyiso_Agent_Connectors::preset($provider);
        foreach ($rows as $row) {
            if ($row['preset'] === $preset && $preset !== '' && isset($row['models'][0])) {
                return $row['id'] . '|' . $row['models'][0];
            }
        }
        return $provider !== '' && $preset === '' ? 'wp:' . $provider : '';
    }

    public static function validateSelection(mixed $value): ?string
    {
        if (!is_string($value)) {
            return '请选择默认模型。';
        }
        if ($value === '' || (str_starts_with($value, 'wp:') && isset(Oyiso_Agent_Config::providers()[substr($value, 3)]) && !str_starts_with(substr($value, 3), 'oyiso-'))) {
            return null;
        }
        [$id, $model] = array_pad(explode('|', $value, 2), 2, '');
        $connection = self::load($id, $model);
        return is_wp_error($connection) ? $connection->get_error_message() : null;
    }

    public static function load(string $id, string $model): Oyiso_Agent_Connection|WP_Error
    {
        foreach (self::all() as $row) {
            if ($row['id'] !== $id) {
                continue;
            }
            if (!in_array($model, $row['models'], true) || Oyiso_Agent_Connectors::validateUrl($row['url']) !== null || !isset(Oyiso_Agent_Connectors::protocols()[$row['protocol']])) {
                return new WP_Error('agent_connection_model', '此连接或模型不可用，请检查模型配置。', ['status' => 400]);
            }
            if (!Oyiso_Agent_Config::hasSecret(self::secretName($id))) {
                return new WP_Error('agent_connection_key', '请先为此提供商保存 API Key。', ['status' => 400]);
            }
            return new Oyiso_Agent_Connection($id, $row['name'], $row['url'], $model, true, $row['protocol']);
        }
        return new WP_Error('agent_connection_missing', '模型连接不存在，请重新选择默认模型。', ['status' => 404]);
    }

    public static function model(string $id, string $model): \WordPress\AiClient\Providers\Models\Contracts\ModelInterface|WP_Error
    {
        $connection = self::load($id, $model);
        if (is_wp_error($connection)) {
            return $connection;
        }
        Oyiso_Agent_Connectors::register();
        $metadata = Oyiso_Agent_Model_Directory::metadata($model);
        $provider = Oyiso_Agent_Custom_Provider::metadata();
        return $connection->protocol === 'openai_chat' ? new Oyiso_Agent_Compatible_Model($metadata, $provider, $id) : new Oyiso_Agent_Native_Model($metadata, $provider, $id);
    }

    /** @param array<string, mixed> $input
     * @return AgentConnection|WP_Error
     */
    public static function save(array $input, string $id = ''): array|WP_Error
    {
        if (!Oyiso_Agent_Store::lock('connections')) {
            return new WP_Error('agent_connection_busy', '配置正在保存，请稍后重试。', ['status' => 409]);
        }
        try {
            $rows = self::all();
            $index = array_search($id, array_column($rows, 'id'), true);
            if ($id !== '' && $index === false) {
                return new WP_Error('agent_connection_missing', '模型连接不存在。', ['status' => 404]);
            }
            if ($id === '' && count($rows) >= 30) {
                return new WP_Error('agent_connection_limit', '最多添加 30 个模型连接。', ['status' => 400]);
            }
            $name = is_string($input['name'] ?? null) ? trim(wp_strip_all_tags($input['name'])) : '';
            $url = is_string($input['url'] ?? null) ? rtrim(trim($input['url']), '/') : '';
            $preset = is_string($input['preset'] ?? null) ? $input['preset'] : '';
            $models = $input['models'] ?? [];
            $key = $input['key'] ?? '';
            $protocol = $input['protocol'] ?? '';
            if ($name === '' || mb_strlen($name) > 80 || $url === '' || Oyiso_Agent_Connectors::validateUrl($url) !== null || !in_array($preset, [...array_keys(Oyiso_Agent_Connectors::connectionPresets()), 'custom'], true) || !is_string($protocol) || !isset(Oyiso_Agent_Connectors::protocols()[$protocol]) || !is_string($key) || Oyiso_Agent_Connectors::validateKey($key) !== null || !is_array($models) || count($models) > 30) {
                return new WP_Error('agent_connection_invalid', '请检查连接名称、公开 HTTPS 地址、接口协议、API Key 和模型列表。', ['status' => 400]);
            }
            $clean = [];
            foreach ($models as $model) {
                if (!is_string($model) || $model === '' || strlen($model) > 200 || preg_match('/[\x00-\x20\x7f|]/', $model)) {
                    return new WP_Error('agent_connection_models', '模型 ID 不能为空、包含空格或竖线，且最长为 200 个字符。', ['status' => 400]);
                }
                $clean[] = $model;
            }
            $models = array_values(array_unique($clean));
            [$selectedId, $selectedModel] = array_pad(explode('|', self::selection(), 2), 2, '');
            if ($id !== '' && $selectedId === $id && (!in_array($selectedModel, $models, true) || !empty($input['clear_key']))) {
                return new WP_Error('agent_connection_default', '请先更换默认模型并保存配置，再移除此模型或清除密钥。', ['status' => 409]);
            }
            $id = $id !== '' ? $id : wp_generate_uuid4();
            if (($key !== '' || !empty($input['clear_key'])) && !Oyiso_Agent_Config::setSecret(self::secretName($id), !empty($input['clear_key']) ? '' : $key)) {
                return new WP_Error('agent_connection_encrypt', 'API Key 加密保存失败，请重试。', ['status' => 500]);
            }
            $row = ['id' => $id, 'name' => $name, 'preset' => $preset, 'url' => $url, 'protocol' => $protocol, 'models' => $models];
            if ($index === false) {
                $rows[] = $row;
            } else {
                $rows[$index] = $row;
            }
            update_option(self::OPTION, $rows, false);
            return $row;
        } finally {
            Oyiso_Agent_Store::unlock('connections');
        }
    }

    public static function delete(string $id): true|WP_Error
    {
        if (!Oyiso_Agent_Store::lock('connections')) {
            return new WP_Error('agent_connection_busy', '配置正在保存，请稍后重试。', ['status' => 409]);
        }
        try {
            if (str_starts_with(self::selection(), $id . '|')) {
                return new WP_Error('agent_connection_default', '请先更换默认模型并保存配置，再删除此提供商。', ['status' => 409]);
            }
            $rows = self::all();
            $remaining = array_values(array_filter($rows, static fn(array $row): bool => $row['id'] !== $id));
            if (count($remaining) === count($rows)) {
                return new WP_Error('agent_connection_missing', '模型连接不存在。', ['status' => 404]);
            }
            update_option(self::OPTION, $remaining, false);
            Oyiso_Agent_Config::setSecret(self::secretName($id), '');
            return true;
        } finally {
            Oyiso_Agent_Store::unlock('connections');
        }
    }
}
