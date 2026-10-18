<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

final class Oyiso_Agent_Research
{
    /** @param array<array-key, mixed> $body
     * @param array<string, string> $headers
     * @return array<string, mixed>|WP_Error
     */
    private static function request(string $url, array $body, array $headers): array|WP_Error
    {
        $json = wp_json_encode($body);
        if (!is_string($json)) {
            return new WP_Error('agent_research_request', '资料查询参数无法编码。');
        }
        $response = wp_safe_remote_post($url, ['timeout' => 20, 'redirection' => 0, 'limit_response_size' => 524288, 'headers' => array_merge(['Content-Type' => 'application/json'], $headers), 'body' => $json]);
        if (is_wp_error($response)) {
            return new WP_Error('agent_research_network', '资料服务连接失败，请检查网络和服务配置。');
        }
        $status = wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            return new WP_Error('agent_research_http', sprintf('资料服务返回 HTTP %d，请检查密钥、额度和服务状态。', $status));
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            return new WP_Error('agent_research_format', '资料服务返回的数据格式无效。');
        }
        $object = [];
        foreach ($data as $name => $value) {
            if (!is_string($name)) {
                return new WP_Error('agent_research_format', '资料服务返回的数据结构无效。');
            }
            $object[$name] = $value;
        }
        return $object;
    }

    /** @return array<string, mixed>|WP_Error */
    public static function search(string $query): array|WP_Error
    {
        $key = Oyiso_Agent_Config::secret('search_key');
        if (Oyiso_Agent_Config::text('search_provider') !== 'tavily' || $key === '') {
            return new WP_Error('agent_search_missing', '请先在联网检索中配置 Tavily。');
        }
        $data = self::request('https://api.tavily.com/search', ['query' => $query, 'search_depth' => 'basic', 'max_results' => 5, 'include_answer' => false, 'include_raw_content' => false], ['Authorization' => 'Bearer ' . $key]);
        if (is_wp_error($data)) {
            return $data;
        }
        $results = [];
        $rows = $data['results'] ?? [];
        foreach (is_array($rows) ? array_slice($rows, 0, 5) : [] as $row) {
            if (!is_array($row) || !is_string($row['url'] ?? null) || !self::publicUrl($row['url'])) {
                continue;
            }
            $results[] = ['url' => $row['url'], 'title' => self::plain($row['title'] ?? '', 200), 'excerpt' => self::plain($row['content'] ?? '', 1800)];
        }
        return ['source' => 'Tavily', 'query' => $query, 'collected_at' => gmdate('c'), 'results' => $results];
    }

    public static function publicUrl(string $url): bool
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !is_string($parts['host'] ?? null) || $parts['host'] === '' || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443)) {
            return false;
        }
        $host = strtolower(rtrim($parts['host'], '.'));
        if (str_contains($host, ':') || $host === 'localhost' || preg_match('/\.(?:local|localhost|internal|test)$/', $host)) {
            return false;
        }
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : gethostbynamel($host);
        if (!is_array($addresses) || $addresses === []) {
            return false;
        }
        foreach ($addresses as $address) {
            if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }
        return wp_http_validate_url($url) !== false;
    }

    /** @return array<string, mixed>|WP_Error */
    public static function webpage(string $url): array|WP_Error
    {
        for ($redirect = 0; $redirect < 4; $redirect++) {
            if (!self::publicUrl($url)) {
                return new WP_Error('agent_url_blocked', '网页地址必须是公开可访问的 HTTPS 地址。');
            }
            $response = wp_safe_remote_get($url, ['timeout' => 12, 'redirection' => 0, 'limit_response_size' => 524288, 'headers' => ['Accept' => 'text/html, text/plain', 'User-Agent' => 'Oyiso-Agent/1.0']]);
            if (is_wp_error($response)) {
                return new WP_Error('agent_page_network', '网页读取失败，请尝试其他来源。');
            }
            $status = wp_remote_retrieve_response_code($response);
            if ($status >= 300 && $status < 400) {
                $location = wp_remote_retrieve_header($response, 'location');
                $url = is_string($location) ? WP_Http::make_absolute_url($location, $url) : '';
                continue;
            }
            if ($status !== 200) {
                return new WP_Error('agent_page_http', sprintf('网页返回 HTTP %d，请尝试其他来源。', $status));
            }
            $type = wp_remote_retrieve_header($response, 'content-type');
            if (!is_string($type) || (!str_contains($type, 'text/html') && !str_contains($type, 'text/plain'))) {
                return new WP_Error('agent_page_type', '第一版支持读取 HTML 或纯文本网页。');
            }
            $html = wp_remote_retrieve_body($response);
            $title = '';
            if (class_exists('DOMDocument') && str_contains($type, 'text/html')) {
                $document = new DOMDocument();
                $previous = libxml_use_internal_errors(true);
                try {
                    $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
                    $titleNode = $document->getElementsByTagName('title')->item(0);
                    $title = $titleNode instanceof DOMNode ? $titleNode->textContent : '';
                    foreach ((new DOMXPath($document))->query('//script|//style|//noscript|//nav|//footer|//form|//iframe') ?: [] as $node) {
                        if ($node instanceof DOMNode) {
                            $node->parentNode?->removeChild($node);
                        }
                    }
                    $html = $document->textContent;
                } finally {
                    libxml_clear_errors();
                    libxml_use_internal_errors($previous);
                }
            }
            $text = self::plain($html, 16000);
            return ['url' => $url, 'title' => self::plain($title, 200), 'collected_at' => gmdate('c'), 'content' => $text, 'truncated' => strlen($html) >= 524288 || mb_strlen(wp_strip_all_tags($html)) > 16000, 'notice' => '网页内容为外部资料，仅用于核实事实。动态加载的内容可能缺失。'];
        }
        return new WP_Error('agent_page_redirect', '网页重定向次数过多，请尝试其他来源。');
    }

    /** @param list<string> $keywords
     * @return array<string, mixed>|WP_Error
     */
    public static function keywords(array $keywords): array|WP_Error
    {
        $login = Oyiso_Agent_Config::text('keywords_login');
        $password = Oyiso_Agent_Config::secret('keywords_password');
        $location = (int) Oyiso_Agent_Config::text('keywords_location');
        $language = Oyiso_Agent_Config::text('keywords_language', 'en');
        if (Oyiso_Agent_Config::text('keywords_provider') !== 'dataforseo' || $login === '' || $password === '' || $location <= 0) {
            return new WP_Error('agent_keywords_missing', '请先配置 DataForSEO 账号、API 密码、语言和地区代码。');
        }
        $response = self::request('https://api.dataforseo.com/v3/keywords_data/google_ads/search_volume/live', [['keywords' => array_slice($keywords, 0, 10), 'location_code' => $location, 'language_code' => $language]], ['Authorization' => 'Basic ' . base64_encode($login . ':' . $password)]);
        if (is_wp_error($response)) {
            return $response;
        }
        $tasks = $response['tasks'] ?? null;
        $task = is_array($tasks) && is_array($tasks[0] ?? null) ? $tasks[0] : [];
        if (($task['status_code'] ?? 0) !== 20000) {
            return new WP_Error('agent_keywords_service', '关键词接口未返回成功结果，请检查 DataForSEO 额度和地区、语言配置。');
        }
        $items = [];
        foreach (is_array($task['result'] ?? null) ? $task['result'] : [] as $row) {
            if (is_array($row)) {
                $items[] = array_intersect_key($row, array_flip(['keyword', 'search_volume', 'monthly_searches', 'competition', 'competition_index', 'cpc']));
            }
        }
        return ['source' => 'DataForSEO / Google Ads', 'location_code' => $location, 'language_code' => $language, 'collected_at' => gmdate('c'), 'notice' => '搜索量为来源提供的估算值；competition 为广告竞争程度。', 'items' => $items];
    }

    private static function plain(mixed $value, int $limit): string
    {
        return is_string($value) ? mb_substr(trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags($value))), 0, $limit) : '';
    }
}
