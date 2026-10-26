<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

final class Oyiso_Agent_Model_Catalog
{
    /** @param array<string, mixed> $input
     * @return array{models:list<array{id:string, name:string,context_window?:int}>, truncated:bool}|WP_Error
     */
    public static function fetch(array $input): array|WP_Error
    {
        $url = is_string($input['url'] ?? null) ? rtrim(trim($input['url']), '/') : '';
        $protocol = $input['protocol'] ?? null;
        $id = $input['id'] ?? '';
        $key = $input['key'] ?? '';
        $clear = $input['clear_key'] ?? false;
        if ($url === '' || Oyiso_Agent_Connectors::validateUrl($url) !== null || !is_string($protocol) || !isset(Oyiso_Agent_Connectors::protocols()[$protocol]) || !is_string($id) || ($id !== '' && !preg_match('/^[a-z0-9-]{1,50}$/', $id)) || !is_bool($clear) || !is_string($key) || Oyiso_Agent_Connectors::validateKey($key) !== null) {
            return self::error('请检查 Base URL、接口协议和 API Key。', 400);
        }
        if ($id !== '') {
            $connection = null;
            foreach (Oyiso_Agent_Connections::all() as $row) {
                if ($row['id'] === $id) $connection = $row;
            }
            if ($connection === null) return self::error('模型连接不存在，请重新打开提供商配置。', 404);
            if ($key === '' && !$clear) {
                // An unsaved endpoint must never receive another endpoint's stored credential.
                if ($url !== rtrim($connection['url'], '/')) return self::error('Base URL 已变更，请重新填写 API Key 后获取模型列表。', 400);
                $key = Oyiso_Agent_Config::secret(Oyiso_Agent_Connections::secretName($id));
            }
        }
        if ($clear || $key === '') return self::error('请填写 API Key 后获取模型列表，也可以手动添加模型。', 400);

        $gemini = $protocol === 'gemini';
        $anthropic = $protocol === 'anthropic_messages';
        if (empty(wp_parse_url($url, PHP_URL_PATH)) && $protocol !== 'openai_chat') $url .= $gemini ? '/v1beta' : '/v1';
        $headers = ['Accept' => 'application/json'] + match ($protocol) {
            'gemini' => ['x-goog-api-key' => $key],
            'anthropic_messages' => ['x-api-key' => $key, 'anthropic-version' => '2023-06-01'],
            default => ['Authorization' => 'Bearer ' . $key],
        };
        $models = [];
        $cursor = '';
        $seen = [];
        $truncated = false;
        $deadline = microtime(true) + 20.0;
        for ($page = 0; $page < 5; $page++) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) { $truncated = true; break; }
            $query = $gemini ? ['pageSize' => 1000] : ($anthropic ? ['limit' => 1000] : []);
            if ($cursor !== '') $query[$gemini ? 'pageToken' : 'after_id'] = $cursor;
            $endpoint = $url . '/models' . ($query !== [] ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
            $response = wp_safe_remote_get($endpoint, ['headers' => $headers, 'timeout' => min(10.0, $remaining), 'redirection' => 0, 'limit_response_size' => 2097152]);
            // Upstream bodies and transport errors may contain the unsaved or stored key.
            if (is_wp_error($response)) return self::error('无法获取模型列表，请检查地址和网络连接，或手动添加模型。');
            $status = wp_remote_retrieve_response_code($response);
            if ($status !== 200) {
                return self::error(match ($status) {
                    401, 403 => '认证失败，请检查 API Key 和访问权限，或手动添加模型。',
                    404, 405, 501 => '此接口未提供模型列表，请手动添加模型。',
                    429 => '请求过于频繁，请稍后重试，或手动添加模型。',
                    default => '获取模型列表失败，请检查连接配置，或手动添加模型。',
                });
            }
            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (!is_array($data)) return self::error('模型列表格式无法识别，请手动添加模型。');
            $items = $data[$gemini ? 'models' : 'data'] ?? ($gemini ? [] : null);
            if (!is_array($items) || !array_is_list($items)) return self::error('模型列表格式无法识别，请手动添加模型。');
            foreach ($items as $item) {
                if (!is_array($item)) continue;
                if ($gemini && isset($item['supportedGenerationMethods']) && (!is_array($item['supportedGenerationMethods']) || !in_array('generateContent', $item['supportedGenerationMethods'], true))) continue;
                $model = $item[$gemini ? 'name' : 'id'] ?? null;
                if (!is_string($model)) continue;
                if ($gemini) $model = preg_replace('/^models\//', '', $model) ?? $model;
                if ($model === '' || strlen($model) > 200 || preg_match('/[\x00-\x20\x7f|]/', $model) || str_contains($model, $key)) continue;
                $name = $item[$gemini ? 'displayName' : 'display_name'] ?? $model;
                $name = is_string($name) && !str_contains($name, $key) ? trim(wp_strip_all_tags($name)) : $model;
                if (!isset($models[$model]) && count($models) >= 5000) { $truncated = true; break; }
                $models[$model] = ['id' => $model, 'name' => $name !== '' ? mb_substr($name, 0, 200) : $model];
                $limit = $item[$gemini ? 'inputTokenLimit' : 'context_window'] ?? $item['context_length'] ?? $item['max_input_tokens'] ?? null;
                if (Oyiso_Agent_Context::validLimit($limit)) $models[$model]['context_window'] = $limit;
            }
            $next = $gemini ? ($data['nextPageToken'] ?? '') : ($anthropic && !empty($data['has_more']) ? ($data['last_id'] ?? '') : '');
            if ($next === '') {
                if ($anthropic && !empty($data['has_more'])) $truncated = true;
                break;
            }
            if (!is_string($next) || strlen($next) > 4096 || isset($seen[$next]) || count($models) >= 5000) { $truncated = true; break; }
            $seen[$next] = true;
            $cursor = $next;
            if ($page === 4) $truncated = true;
        }
        $models = array_values($models);
        usort($models, static fn(array $a, array $b): int => strnatcasecmp($a['id'], $b['id']));
        return ['models' => $models, 'truncated' => $truncated];
    }

    private static function error(string $message, int $status = 502): WP_Error
    {
        return new WP_Error('agent_model_catalog', $message, ['status' => $status]);
    }
}
