<?php

defined('ABSPATH') || exit;

if (!function_exists('oyiso_get_plugin_update_main_file')) {
    function oyiso_get_plugin_update_main_file(): string {
        return dirname(dirname(dirname(__DIR__))) . '/oyiso.php';
    }
}

if (!function_exists('oyiso_get_plugin_update_plugin_data')) {
    function oyiso_get_plugin_update_plugin_data(): array {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return get_plugin_data(oyiso_get_plugin_update_main_file(), false, false);
    }
}

if (!function_exists('oyiso_render_plugin_update_panel')) {
    function oyiso_render_plugin_update_panel(): void {
        $pluginData = oyiso_get_plugin_update_plugin_data();
        $currentVersion = (string) ($pluginData['Version'] ?? '');
        $statusPayload = class_exists('Oyiso_GitHub_Updater')
            ? (new Oyiso_GitHub_Updater())->buildStatusPayload()
            : [
                'status_html' => '<p style="margin:12px 0 0;color:#6b7280;">尚未检查 GitHub 更新。</p>',
                'action_html' => '',
            ];

        echo '
            <div class="oyiso-plugin-update-panel">
                <p>当前插件已接入 GitHub Releases 在线更新。</p>
                <p>仓库地址：<a href="https://github.com/kannafay/wp-plugin-oyiso" target="_blank" rel="noopener noreferrer">https://github.com/kannafay/wp-plugin-oyiso</a></p>
                <p>如需手动更新，请下载最新 Release 安装包，并确保插件目录为 <code>wp-plugin-oyiso/</code>。</p>
                <p>当前版本：<code>' . esc_html($currentVersion) . '</code></p>
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <button type="button" class="button button-secondary" id="oyiso-plugin-update-check">检查更新</button>
                    <span id="oyiso-plugin-update-action">' . $statusPayload['action_html'] . '</span>
                </div>
                <div id="oyiso-plugin-update-status">' . $statusPayload['status_html'] . '</div>
            </div>
        ';
    }
}

if (!class_exists('Oyiso_GitHub_Updater')) {
    final class Oyiso_GitHub_Updater {
        public const CACHE_KEY = 'oyiso_github_release_meta';

        private const CACHE_TTL = 21600;
        private const FAILURE_CACHE_TTL = 900;
        private const REPO_URL = 'https://github.com/kannafay/wp-plugin-oyiso';
        private const API_URL = 'https://api.github.com/repos/kannafay/wp-plugin-oyiso/releases/latest';
        private const PLUGIN_SLUG = 'wp-plugin-oyiso';

        public static function init(): void {
            $instance = new self();

            add_filter('pre_set_site_transient_update_plugins', [$instance, 'injectUpdate']);
            add_filter('plugins_api', [$instance, 'injectPluginInfo'], 20, 3);
            add_action('upgrader_process_complete', [$instance, 'purgeCache'], 10, 2);
            add_action('admin_enqueue_scripts', [$instance, 'enqueueAdminAssets']);
            add_action('wp_ajax_oyiso_plugin_update_check', [$instance, 'handleAjaxCheck']);
        }

        public function enqueueAdminAssets(string $hook): void {
            if (!oyiso_is_settings_page_hook($hook)) {
                return;
            }

            wp_register_script('oyiso-plugin-update', '', ['jquery'], null, true);
            wp_enqueue_script('oyiso-plugin-update');
            wp_localize_script('oyiso-plugin-update', 'oyisoPluginUpdate', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('oyiso_plugin_update_check'),
                'labels'  => [
                    'checking'      => '正在检查 GitHub 更新...',
                    'error'         => '检查失败，请稍后重试。',
                    'confirmUpdate' => '确定立即更新插件吗？建议先备份插件数据。',
                ],
            ]);

            wp_add_inline_script('oyiso-plugin-update', <<<'JS'
jQuery(function ($) {
    var $button = $('#oyiso-plugin-update-check');
    var $action = $('#oyiso-plugin-update-action');
    var $status = $('#oyiso-plugin-update-status');

    if (!$button.length || !$action.length || !$status.length) {
        return;
    }

    $(document).on('click', '.oyiso-plugin-update-now', function (event) {
        if (!window.confirm(oyisoPluginUpdate.labels.confirmUpdate)) {
            event.preventDefault();
        }
    });

    $button.on('click', function () {
        $button.prop('disabled', true);
        $action.empty();
        $status.html('<p style="margin:12px 0 0;color:#6b7280;">' + oyisoPluginUpdate.labels.checking + '</p>');

        $.post(oyisoPluginUpdate.ajaxUrl, {
            action: 'oyiso_plugin_update_check',
            nonce: oyisoPluginUpdate.nonce
        }).done(function (response) {
            if (response && response.success && response.data && response.data.statusHtml !== undefined) {
                $status.html(response.data.statusHtml);
                $action.html(response.data.actionHtml || '');
                return;
            }

            var message = response && response.data && response.data.message
                ? response.data.message
                : oyisoPluginUpdate.labels.error;

            $status.html('<p style="margin:12px 0 0;color:#b91c1c;">' + $('<div/>').text(message).html() + '</p>');
        }).fail(function (xhr) {
            var message = oyisoPluginUpdate.labels.error;

            if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                message = xhr.responseJSON.data.message;
            }

            $status.html('<p style="margin:12px 0 0;color:#b91c1c;">' + $('<div/>').text(message).html() + '</p>');
        }).always(function () {
            $button.prop('disabled', false);
        });
    });
});
JS);
        }

        public function injectUpdate($transient) {
            if (!is_object($transient) || empty($transient->checked)) {
                return $transient;
            }

            if (!is_admin() && !wp_doing_cron()) {
                return $transient;
            }

            return $this->mergeReleaseIntoTransient($transient, true);
        }

        public function injectPluginInfo($result, $action, $args) {
            if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== self::PLUGIN_SLUG) {
                return $result;
            }

            $pluginData = oyiso_get_plugin_update_plugin_data();
            $release = $this->getStoredRelease();

            if (!$release) {
                return $result;
            }

            return (object) [
                'name'           => $pluginData['Name'] ?: '橘子猫头',
                'slug'           => self::PLUGIN_SLUG,
                'version'        => $release['version'],
                'author'         => '<a href="' . esc_url(self::REPO_URL) . '" target="_blank" rel="noopener noreferrer">橘子猫头</a>',
                'author_profile' => esc_url(self::REPO_URL),
                'homepage'       => esc_url(self::REPO_URL),
                'requires'       => $release['requires'],
                'tested'         => $release['tested'],
                'requires_php'   => $release['requires_php'],
                'last_updated'   => $release['last_updated'],
                'download_link'  => $release['download_url'],
                'sections'       => [
                    'description' => $this->buildDescriptionSection($pluginData, $release),
                    'changelog'   => $this->buildChangelogSection($release),
                ],
            ];
        }

        public function purgeCache($upgrader, array $hookExtra): void {
            if (($hookExtra['type'] ?? '') !== 'plugin') {
                return;
            }

            delete_site_transient(self::CACHE_KEY);
        }

        public function handleAjaxCheck(): void {
            check_ajax_referer('oyiso_plugin_update_check', 'nonce');

            if (!current_user_can('manage_options')) {
                wp_send_json_error([
                    'message' => '无权限执行该操作',
                ], 403);
            }

            $release = $this->requestLatestRelease(true);

            if (is_wp_error($release)) {
                wp_send_json_error([
                    'message' => $release->get_error_message(),
                ], 400);
            }

            $statusPayload = $this->buildStatusPayload($release);

            wp_send_json_success([
                'statusHtml' => $statusPayload['status_html'],
                'actionHtml' => $statusPayload['action_html'],
            ]);
        }

        public function buildStatusPayload(?array $cachedOverride = null): array {
            $pluginData = oyiso_get_plugin_update_plugin_data();
            $currentVersion = (string) ($pluginData['Version'] ?? '');
            $cached = is_array($cachedOverride) ? $cachedOverride : get_site_transient(self::CACHE_KEY);

            if (!is_array($cached)) {
                return [
                    'status_html' => '<p style="margin:12px 0 0;color:#6b7280;">尚未检查 GitHub 更新。</p>',
                    'action_html' => '',
                ];
            }

            $checkedAt = !empty($cached['checked_at'])
                ? wp_date('Y-m-d H:i:s', (int) $cached['checked_at'])
                : '';

            if (($cached['_status'] ?? '') === 'success') {
                $this->primeUpdateTransient($cached);

                $latestVersion = (string) ($cached['version'] ?? '');
                $hasUpdate = $latestVersion !== '' && $currentVersion !== '' && version_compare($latestVersion, $currentVersion, '>');
                $statusColor = $hasUpdate ? '#15803d' : '#6b7280';
                $statusText = $hasUpdate
                    ? sprintf('已检测到新版本 %1$s，当前版本为 %2$s。', $latestVersion, $currentVersion)
                    : sprintf('当前已是最新版本 %s。', $currentVersion);

                if ($checkedAt !== '') {
                    $statusText .= ' 上次检查时间：' . $checkedAt . '。';
                }

                $statusHtml = '<p style="margin:12px 0 0;color:' . esc_attr($statusColor) . ';">' . esc_html($statusText) . '</p>';
                $actionHtml = '';

                if ($hasUpdate) {
                    $upgradeUrl = $this->getUpgradeUrl();

                    if ($upgradeUrl !== '') {
                        $actionHtml = '<a class="button button-primary oyiso-plugin-update-now" href="' . esc_url($upgradeUrl) . '">立即更新</a>';
                    } elseif (is_multisite()) {
                        $actionHtml = '<span style="display:inline-flex;align-items:center;gap:8px;">'
                            . '<span class="button button-primary disabled" aria-disabled="true" style="pointer-events:none;opacity:.6;">立即更新</span>'
                            . '<span style="color:#6b7280;font-size:12px;line-height:1.4;">仅超级管理员可操作</span>'
                            . '</span>';
                    } else {
                        $actionHtml = '<span style="display:inline-flex;align-items:center;gap:8px;">'
                            . '<span class="button button-primary disabled" aria-disabled="true" style="pointer-events:none;opacity:.6;">立即更新</span>'
                            . '<span style="color:#6b7280;font-size:12px;line-height:1.4;">当前账号无更新权限</span>'
                            . '</span>';
                    }
                }

                return [
                    'status_html' => $statusHtml,
                    'action_html' => $actionHtml,
                ];
            }

            if (($cached['_status'] ?? '') === 'error') {
                $message = '上次检查未成功，可点击按钮重试。';

                if ($checkedAt !== '') {
                    $message .= ' 最近一次尝试时间：' . $checkedAt . '。';
                }

                return [
                    'status_html' => '<p style="margin:12px 0 0;color:#6b7280;">' . esc_html($message) . '</p>',
                    'action_html' => '',
                ];
            }

            return [
                'status_html' => '<p style="margin:12px 0 0;color:#6b7280;">尚未检查 GitHub 更新。</p>',
                'action_html' => '',
            ];
        }

        private function mergeReleaseIntoTransient($transient, bool $allowFetch = false, ?array $releaseOverride = null) {
            $pluginFile = plugin_basename(oyiso_get_plugin_update_main_file());
            $pluginData = oyiso_get_plugin_update_plugin_data();
            $release = $releaseOverride;

            if ($release === null) {
                $release = $allowFetch
                    ? $this->requestLatestRelease(false)
                    : $this->getStoredRelease();
            }

            if (is_wp_error($release) || !$release || empty($pluginData['Version'])) {
                return $transient;
            }

            if (!isset($transient->response) || !is_array($transient->response)) {
                $transient->response = [];
            }

            if (!isset($transient->no_update) || !is_array($transient->no_update)) {
                $transient->no_update = [];
            }

            if (version_compare($release['version'], $pluginData['Version'], '>')) {
                $transient->response[$pluginFile] = (object) [
                    'slug'         => self::PLUGIN_SLUG,
                    'plugin'       => $pluginFile,
                    'new_version'  => $release['version'],
                    'url'          => self::REPO_URL,
                    'package'      => $release['download_url'],
                    'tested'       => $release['tested'],
                    'requires'     => $release['requires'],
                    'requires_php' => $release['requires_php'],
                ];

                unset($transient->no_update[$pluginFile]);

                return $transient;
            }

            $transient->no_update[$pluginFile] = (object) [
                'slug'         => self::PLUGIN_SLUG,
                'plugin'       => $pluginFile,
                'new_version'  => $pluginData['Version'],
                'url'          => self::REPO_URL,
                'package'      => '',
                'tested'       => $release['tested'],
                'requires'     => $release['requires'],
                'requires_php' => $release['requires_php'],
            ];

            unset($transient->response[$pluginFile]);

            return $transient;
        }

        private function primeUpdateTransient(array $release): void {
            $transient = get_site_transient('update_plugins');

            if (!is_object($transient)) {
                $transient = (object) [
                    'last_checked' => time(),
                    'checked'      => [],
                    'response'     => [],
                    'translations' => [],
                    'no_update'    => [],
                ];
            }

            if (!isset($transient->checked) || !is_array($transient->checked)) {
                $transient->checked = [];
            }

            $pluginFile = plugin_basename(oyiso_get_plugin_update_main_file());
            $pluginData = oyiso_get_plugin_update_plugin_data();
            $currentVersion = (string) ($pluginData['Version'] ?? '');

            $transient->checked[$pluginFile] = $currentVersion;
            $transient->last_checked = !empty($release['checked_at']) ? (int) $release['checked_at'] : time();
            $transient = $this->mergeReleaseIntoTransient($transient, false, $release);

            set_site_transient('update_plugins', $transient);
            wp_clean_plugins_cache(false);
        }

        private function getUpgradeUrl(): string {
            if (!current_user_can('update_plugins')) {
                return '';
            }

            $pluginFile = plugin_basename(oyiso_get_plugin_update_main_file());
            $url = self_admin_url('update.php?action=upgrade-plugin&plugin=' . rawurlencode($pluginFile));

            return wp_nonce_url($url, 'upgrade-plugin_' . $pluginFile);
        }

        private function getStoredRelease(): ?array {
            $cached = get_site_transient(self::CACHE_KEY);

            if (!is_array($cached) || ($cached['_status'] ?? '') !== 'success') {
                return null;
            }

            return $cached;
        }

        private function requestLatestRelease(bool $forceRefresh = false) {
            if (!$forceRefresh) {
                $cached = get_site_transient(self::CACHE_KEY);

                if (is_array($cached)) {
                    if (($cached['_status'] ?? '') === 'success') {
                        return $cached;
                    }

                    if (($cached['_status'] ?? '') === 'error') {
                        return new WP_Error(
                            'oyiso_plugin_update_error',
                            (string) ($cached['message'] ?? '暂时无法检查 GitHub 更新。')
                        );
                    }
                }
            }

            $pluginData = oyiso_get_plugin_update_plugin_data();
            $response = wp_remote_get(self::API_URL, [
                'timeout' => 15,
                'headers' => [
                    'Accept'     => 'application/vnd.github+json',
                    'User-Agent' => self::PLUGIN_SLUG . '/' . ($pluginData['Version'] ?? 'unknown') . '; ' . home_url('/'),
                ],
            ]);

            if (is_wp_error($response)) {
                return $this->storeReleaseError('连接 GitHub 失败，请检查服务器外网访问能力。');
            }

            $statusCode = (int) wp_remote_retrieve_response_code($response);

            if ($statusCode !== 200) {
                if ($statusCode === 404) {
                    return $this->storeReleaseError('GitHub 仓库还没有可用的正式 Release，或 latest Release 不存在。');
                }

                if ($statusCode === 403) {
                    return $this->storeReleaseError('GitHub 更新接口暂时拒绝访问，可能是触发了访问限制。');
                }

                return $this->storeReleaseError(sprintf('GitHub 更新接口返回异常状态：HTTP %d。', $statusCode));
            }

            $payload = json_decode((string) wp_remote_retrieve_body($response), true);

            if (!is_array($payload) || empty($payload['tag_name'])) {
                return $this->storeReleaseError('GitHub Release 数据格式异常。');
            }

            $downloadUrl = $this->findReleaseZipUrl($payload);

            if ($downloadUrl === '') {
                return $this->storeReleaseError('未找到可用的插件 ZIP 附件，请先在 GitHub Release 中上传打包文件。');
            }

            $release = [
                '_status'      => 'success',
                'version'      => ltrim((string) $payload['tag_name'], "vV \t\n\r\0\x0B"),
                'download_url' => $downloadUrl,
                'requires'     => '',
                'requires_php' => '',
                'tested'       => '',
                'last_updated' => !empty($payload['published_at']) ? gmdate('Y-m-d', strtotime((string) $payload['published_at'])) : '',
                'body'         => isset($payload['body']) ? (string) $payload['body'] : '',
                'name'         => isset($payload['name']) ? (string) $payload['name'] : '',
                'checked_at'   => time(),
            ];

            set_site_transient(self::CACHE_KEY, $release, self::CACHE_TTL);

            return $release;
        }

        private function storeReleaseError(string $message): WP_Error {
            set_site_transient(self::CACHE_KEY, [
                '_status'    => 'error',
                'message'    => $message,
                'checked_at' => time(),
            ], self::FAILURE_CACHE_TTL);

            return new WP_Error('oyiso_plugin_update_error', $message);
        }

        private function findReleaseZipUrl(array $payload): string {
            if (empty($payload['assets']) || !is_array($payload['assets'])) {
                return '';
            }

            $fallbackUrl = '';

            foreach ($payload['assets'] as $asset) {
                if (empty($asset['name']) || empty($asset['browser_download_url'])) {
                    continue;
                }

                $assetName = (string) $asset['name'];
                $assetUrl = (string) $asset['browser_download_url'];

                if (!preg_match('/\.zip$/i', $assetName)) {
                    continue;
                }

                if (preg_match('/^wp-plugin-oyiso(?:[-_].+)?\.zip$/i', $assetName)) {
                    return $assetUrl;
                }

                if ($fallbackUrl === '') {
                    $fallbackUrl = $assetUrl;
                }
            }

            return $fallbackUrl;
        }

        private function buildDescriptionSection(array $pluginData, array $release): string {
            $description = !empty($pluginData['Description']) ? $pluginData['Description'] : '橘子猫头的多功能实用插件';
            $releaseName = $release['name'] !== '' ? $release['name'] : ('v' . $release['version']);

            return wpautop(
                esc_html($description) . "\n\n" .
                sprintf('当前最新发布版本：%s', $releaseName)
            );
        }

        private function buildChangelogSection(array $release): string {
            if ($release['body'] === '') {
                return '<p>当前版本未提供更新日志。</p>';
            }

            return wpautop(esc_html($release['body']));
        }
    }
}

if (class_exists('CSF')) {
    CSF::createSection($prefix, [
        'parent'   => 'oyiso-update-backup',
        'id'       => 'oyiso-update',
        'tab_id'   => 'oyiso-update',
        'title'    => '检查更新',
        'icon'     => 'fas fa-cloud-download-alt',
        'priority' => 20,
        'fields'   => [
            [
                'type'    => 'heading',
                'content' => '检查更新',
            ],
            [
                'type'     => 'callback',
                'function' => 'oyiso_render_plugin_update_panel',
            ],
        ],
    ]);
}

Oyiso_GitHub_Updater::init();
