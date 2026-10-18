<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/** @phpstan-type OyisoHistoryRelease array{version: string, tag: string, date: string, body: string} */
final class Oyiso_Plugin_Update_Panel {
    public const HISTORY_CACHE_KEY = 'oyiso_github_release_history';
    public const HISTORY_ERROR_KEY = 'oyiso_github_release_history_error';
    private const RELEASES_URL = 'https://github.com/kannafay/wp-plugin-oyiso/releases';
    private const HISTORY_API_URL = 'https://api.github.com/repos/kannafay/wp-plugin-oyiso/releases?per_page=10';

    public function renderComparison(string $current, string $latest, string $published, bool $failed = false): string {
        $state = 'unknown';
        $label = $failed ? '检查失败' : '等待检查';

        if ($current !== '' && $latest !== '') {
            $comparison = version_compare($latest, $current);
            $state = $comparison > 0 ? 'available' : ($comparison < 0 ? 'ahead' : 'current');
            $label = $comparison > 0 ? '有新版本可更新' : ($comparison < 0 ? '当前版本领先' : '已是最新版本');
        }

        return '<section class="oyiso-update-overview" aria-label="版本对比">'
            . '<div class="oyiso-update-overview-heading"><h3>版本状态</h3>'
            . '<span class="oyiso-update-state oyiso-update-state--' . esc_attr($state) . '">' . esc_html($label) . '</span></div>'
            . '<div class="oyiso-update-versions">'
            . '<div class="oyiso-update-version"><span class="oyiso-update-version-label">当前版本</span>'
            . '<strong>' . esc_html($current !== '' ? 'v' . $current : '未知') . '</strong><span>此站点已安装</span></div>'
            . '<div class="oyiso-update-version oyiso-update-version--latest"><span class="oyiso-update-version-label">最新版本</span>'
            . '<strong>' . esc_html($latest !== '' ? 'v' . $latest : ($failed ? '获取失败' : '尚未检查')) . '</strong>'
            . '<span>' . esc_html($published !== '' ? '发布于 ' . $published : 'GitHub 最新正式版本') . '</span></div>'
            . '</div></section>';
    }

    public function handleAjaxHistory(): void {
        check_ajax_referer('oyiso_plugin_update_check', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => '无权限执行该操作'], 403);
        }

        $this->fetchHistory();
        wp_send_json_success(['historyHtml' => $this->renderHistory()]);
    }

    public function renderHistory(): string {
        $releases = $this->normalizeReleases(get_site_transient(self::HISTORY_CACHE_KEY));
        $error = get_site_transient(self::HISTORY_ERROR_KEY);
        $error = is_string($error) ? $error : '';
        $html = '';

        if ($error !== '') {
            $html .= '<p class="oyiso-update-history-error" role="status">' . esc_html($error)
                . ' <button type="button" class="button-link oyiso-update-history-retry">重新获取</button></p>';
        }

        if ($releases === null) {
            return $html !== '' ? $html : '<p class="oyiso-update-history-empty" data-needs-load="true">正在获取更新记录…</p>';
        }

        if ($releases === []) {
            return $html . '<p class="oyiso-update-history-empty">仓库暂无正式版本的更新记录。</p>';
        }

        $pluginData = oyiso_get_plugin_update_plugin_data();
        $current = (string) ($pluginData['Version'] ?? '');
        $latest = get_site_transient(Oyiso_GitHub_Updater::CACHE_KEY);
        $latest = is_array($latest) && ($latest['_status'] ?? '') === 'success' && is_string($latest['version'] ?? null)
            ? $latest['version'] : '';

        foreach ($releases as $index => $release) {
            $badges = '';

            if ($latest !== '' && version_compare($release['version'], $latest, '==')) {
                $badges .= '<span class="oyiso-update-release-badge">最新</span>';
            }

            if ($current !== '' && version_compare($release['version'], $current, '==')) {
                $badges .= '<span class="oyiso-update-release-badge oyiso-update-release-badge--installed">当前安装</span>';
            }

            $releaseUrl = self::RELEASES_URL . '/tag/' . rawurlencode($release['tag']);
            $html .= '<details class="oyiso-update-release"' . ($index === 0 ? ' open' : '') . '>'
                . '<summary><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>'
                . '<strong>v' . esc_html($release['version']) . '</strong>' . $badges
                . '<span class="oyiso-update-release-date">' . esc_html($release['date']) . '</span></summary>'
                . '<div class="oyiso-update-release-body">'
                . '<div class="oyiso-update-release-notes">' . esc_html($release['body'] !== '' ? $release['body'] : '此版本未提供更新说明。') . '</div>'
                . '<a href="' . esc_url($releaseUrl) . '" target="_blank" rel="noopener noreferrer">在 GitHub 查看此版本 <span aria-hidden="true">↗</span></a>'
                . '</div></details>';
        }

        return $html;
    }

    public function fetchHistory(): void {
        $response = wp_remote_get(self::HISTORY_API_URL, [
            'timeout' => 15,
            'limit_response_size' => 524288,
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'wp-plugin-oyiso',
            ],
        ]);

        if (is_wp_error($response)) {
            $this->storeHistoryError('连接 GitHub 失败，暂时无法获取更新记录。');
            return;
        }

        $status = wp_remote_retrieve_response_code($response);

        if ($status !== 200) {
            $this->storeHistoryError(in_array($status, [403, 429], true)
                ? 'GitHub 接口访问受限，请稍后重新获取更新记录。'
                : '未能获取更新记录，请稍后重试。');
            return;
        }

        $body = wp_remote_retrieve_body($response);
        $payload = json_decode($body, true);

        if (!str_starts_with(ltrim($body), '[') || $this->normalizeReleases($payload) === null) {
            $this->storeHistoryError('GitHub 更新记录格式异常，请稍后重试。');
            return;
        }

        set_site_transient(self::HISTORY_CACHE_KEY, $payload, 6 * HOUR_IN_SECONDS);
        delete_site_transient(self::HISTORY_ERROR_KEY);
    }

    private function storeHistoryError(string $message): void {
        set_site_transient(self::HISTORY_ERROR_KEY, $message, 15 * MINUTE_IN_SECONDS);
    }

    /** @return list<OyisoHistoryRelease>|null */
    private function normalizeReleases(mixed $payload): ?array {
        if (!is_array($payload) || !array_is_list($payload)) {
            return null;
        }

        $releases = [];

        foreach ($payload as $item) {
            if (!is_array($item) || !is_string($item['tag_name'] ?? null)) {
                return null;
            }

            if (!empty($item['draft']) || !empty($item['prerelease'])) {
                continue;
            }

            $tag = $item['tag_name'];
            $version = ltrim($tag, "vV \t\n\r\0\x0B");

            if (!preg_match('/^\d+(?:\.\d+)*(?:[-+][0-9A-Za-z.-]+)?$/D', $version)) {
                continue;
            }

            $published = is_string($item['published_at'] ?? null) ? strtotime($item['published_at']) : false;
            $date = $published !== false ? wp_date('Y-m-d', $published) : '';
            $releases[] = [
                'version' => $version,
                'tag' => $tag,
                'date' => is_string($date) ? $date : '',
                'body' => is_string($item['body'] ?? null) ? $item['body'] : '',
            ];

            if (count($releases) === 10) {
                break;
            }
        }

        return $releases;
    }
}
