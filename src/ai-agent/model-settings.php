<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

final class Oyiso_Agent_Model_Settings
{
    public static function assets(string $hook): void
    {
        if ($hook !== 'plugins_page_oyiso' || !current_user_can('manage_options') || is_network_admin() || is_user_admin()) {
            return;
        }
        wp_enqueue_style('oyiso-model-settings', plugins_url('assets/model-settings.css', __FILE__), [], (string) filemtime(__DIR__ . '/assets/model-settings.css'));
        wp_enqueue_script('oyiso-model-settings', plugins_url('assets/model-settings.js', __FILE__), ['jquery'], (string) filemtime(__DIR__ . '/assets/model-settings.js'), true);
        wp_localize_script('oyiso-model-settings', 'oyisoModelSettings', ['url' => rest_url('oyiso-agent/v1/'), 'nonce' => wp_create_nonce('wp_rest')]);
    }

    public static function routes(): void
    {
        $permission = static fn(): bool => current_user_can('manage_options');
        register_rest_route('oyiso-agent/v1', '/providers', [
            ['methods' => 'GET', 'permission_callback' => $permission, 'callback' => static fn(): WP_REST_Response => new WP_REST_Response(['connections' => Oyiso_Agent_Connections::view()])],
            ['methods' => 'POST', 'permission_callback' => $permission, 'callback' => [self::class, 'save']],
        ]);
        register_rest_route('oyiso-agent/v1', '/providers/(?P<id>[a-z0-9-]{1,50})', [
            ['methods' => 'PUT', 'permission_callback' => $permission, 'callback' => [self::class, 'save']],
            ['methods' => 'DELETE', 'permission_callback' => $permission, 'callback' => static function (WP_REST_Request $request): WP_REST_Response|WP_Error {
                $id = $request->get_url_params()['id'] ?? '';
                $result = Oyiso_Agent_Connections::delete(is_string($id) ? $id : '');
                return is_wp_error($result) ? $result : new WP_REST_Response(['connections' => Oyiso_Agent_Connections::view()]);
            }],
        ]);
        register_rest_route('oyiso-agent/v1', '/providers/(?P<id>[a-z0-9-]{1,50})/test', ['methods' => 'POST', 'permission_callback' => $permission, 'callback' => static function (WP_REST_Request $request): WP_REST_Response|WP_Error {
            $id = $request->get_url_params()['id'] ?? '';
            $result = Oyiso_Agent_Connectors::test(is_string($id) ? $id : '', (string) $request->get_param('model'));
            return is_wp_error($result) ? $result : new WP_REST_Response($result);
        }, 'args' => ['model' => ['type' => 'string', 'required' => true, 'minLength' => 1, 'maxLength' => 200]]]);
    }

    /** @param WP_REST_Request<array<string, mixed>> $request */
    public static function save(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $input = json_decode($request->get_body(), true);
        if (!is_array($input)) {
            return new WP_Error('agent_connection_input', '请提交有效的连接配置。', ['status' => 400]);
        }
        $payload = [];
        foreach (['name', 'preset', 'url', 'protocol', 'models', 'key', 'clear_key'] as $field) {
            $payload[$field] = $input[$field] ?? ($field === 'key' ? '' : null);
        }
        $id = $request->get_url_params()['id'] ?? '';
        $id = is_string($id) ? $id : '';
        $result = Oyiso_Agent_Connections::save($payload, $id);
        return is_wp_error($result) ? $result : new WP_REST_Response(['connections' => Oyiso_Agent_Connections::view()], $id !== '' ? 200 : 201);
    }

    public static function render(): void
    {
        $data = ['connections' => Oyiso_Agent_Connections::view(), 'presets' => Oyiso_Agent_Connectors::connectionPresets(), 'protocols' => Oyiso_Agent_Connectors::protocols()];
        ?>
        <div class="oyiso-agent-settings" data-agent-settings data-state="<?php echo esc_attr((string) wp_json_encode($data)); ?>">
            <div class="oyiso-agent-settings-heading"><div><h3>模型提供商</h3><p class="description">每个连接独立保存密钥，同一家提供商可以添加多次。</p></div><button type="button" class="button button-primary" data-provider-add>添加提供商</button></div>
            <p data-provider-notice role="status" aria-live="polite" hidden></p>
            <div class="oyiso-agent-provider-table"><table class="widefat"><thead><tr><th>连接名称</th><th>接口</th><th>模型</th><th>密钥</th><th>操作</th></tr></thead><tbody data-provider-rows></tbody></table></div>
            <p class="description">提供商在弹窗内单独保存。默认模型和下方偏好使用页面顶部的「保存配置」。<a href="<?php echo esc_url(admin_url('options-connectors.php')); ?>">管理 WordPress 连接器</a></p>
            <dialog class="oyiso-agent-provider-dialog" aria-labelledby="oyiso-provider-dialog-title" data-provider-dialog>
                <div class="oyiso-agent-provider-dialog-heading"><h2 id="oyiso-provider-dialog-title">添加提供商</h2><button type="button" class="oyiso-agent-provider-close" data-provider-close aria-label="关闭">×</button></div>
                <div class="oyiso-agent-provider-dialog-body">
                    <div class="oyiso-agent-provider-fields">
                        <label>提供商预设<select data-provider-field="preset"><option value="openai">OpenAI</option><option value="deepseek">DeepSeek</option><option value="gemini">Gemini</option><option value="agnes">Agnes AI</option><option value="custom">自定义</option></select></label>
                        <label>连接名称<input type="text" maxlength="80" data-provider-field="name" placeholder="例如：DeepSeek 主账号"></label>
                        <label class="oyiso-agent-provider-wide">Base URL<input type="url" maxlength="1000" spellcheck="false" data-provider-field="url" placeholder="https://api.example.com/v1"></label>
                        <label class="oyiso-agent-provider-wide">接口类型<select data-provider-field="protocol"><?php foreach (Oyiso_Agent_Connectors::protocols() as $id => $protocol): ?><option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($protocol['name']); ?></option><?php endforeach; ?></select><span class="description">按服务商文档选择接口类型。Base URL 保留版本路径，无需填写具体端点。</span></label>
                        <label class="oyiso-agent-provider-wide">API Key<input type="password" maxlength="4096" autocomplete="new-password" data-provider-field="key" placeholder="填写 API Key"><span class="description">加密保存，已有密钥留空保持。</span></label>
                        <label class="oyiso-agent-provider-clear"><input type="checkbox" data-provider-field="clear_key">清除已保存的密钥</label>
                        <label class="oyiso-agent-provider-wide">可用模型<textarea rows="4" spellcheck="false" data-provider-field="models" placeholder="每行填写一个模型 ID"></textarea><span class="description">每行一个模型 ID，最多 30 个。用于 Agent 的模型须支持工具调用。</span></label>
                    </div>
                    <p class="oyiso-agent-provider-dialog-notice" data-provider-dialog-notice role="status" aria-live="polite"></p>
                </div>
                <div class="oyiso-agent-provider-dialog-footer"><button type="button" class="button" data-provider-close>取消</button><button type="button" class="button button-primary" data-provider-save>保存提供商</button></div>
            </dialog>
        </div>
        <?php
    }

    public static function selection(): void
    {
        $selected = Oyiso_Agent_Connections::selection();
        $choices = ['' => 'WordPress 自动选择'];
        foreach (Oyiso_Agent_Connections::all() as $row) {
            foreach ($row['models'] as $model) {
                $choices[$row['id'] . '|' . $model] = $row['name'] . ' / ' . $model;
            }
        }
        foreach (Oyiso_Agent_Config::providers() as $id => $name) {
            if ($id !== '' && !str_starts_with($id, 'oyiso-')) {
                $choices['wp:' . $id] = 'WordPress / ' . $name;
            }
        }
        if (!isset($choices[$selected])) {
            $choices[$selected] = '原默认模型已移除，请重新选择';
        }
        echo '<select name="oyiso[agent_selection]" data-provider-default>';
        foreach ($choices as $value => $label) {
            printf('<option value="%s"%s>%s</option>', esc_attr($value), $value === $selected ? ' selected' : '', esc_html($label));
        }
        echo '</select><p class="description">选择「连接名称 / 模型」。添加提供商后，可在这里设为默认模型。</p>';
    }
}
