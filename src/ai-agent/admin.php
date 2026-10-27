<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/** @phpstan-type AgentRequest array{id?:int|string, run_id?:int, message?:string, selection?:string, page?:int, action?:string, proposal?:string,page_context?:string} */
final class Oyiso_Agent_Admin
{
    public static function routes(): void
    {
        Oyiso_Agent_Model_Settings::routes();
        Oyiso_Agent_File_Manager::routes();
        $permission = static fn(): bool => current_user_can('manage_options');
        $root = 'oyiso-agent/v1';
        register_rest_route($root, '/status', ['methods' => 'GET', 'permission_callback' => $permission, 'callback' => static fn(): WP_REST_Response => new WP_REST_Response(['enabled' => Oyiso_Agent_Config::enabled(), 'supported' => Oyiso_Agent_Config::supported(), 'writeTools' => Oyiso_Agent_Permissions::granted(), 'secrets' => Oyiso_Agent_Config::secretStatus(), 'models' => Oyiso_Agent_Connections::choices()])]);
        register_rest_route($root, '/connections/(?P<preset>deepseek|gemini|agnes)/test', ['methods' => 'POST', 'permission_callback' => $permission, 'callback' => static function (WP_REST_Request $request): WP_REST_Response|WP_Error {
            $result = Oyiso_Agent_Connectors::test((string) $request->get_param('preset'));
            return is_wp_error($result) ? $result : new WP_REST_Response($result);
        }]);
        register_rest_route($root, '/runs', [
            ['methods' => 'GET', 'permission_callback' => $permission, 'callback' => static fn(WP_REST_Request $request): WP_REST_Response => new WP_REST_Response(Oyiso_Agent_Store::recent(max(1, (int) $request->get_param('page'))), 200, ['Cache-Control' => 'no-store, private']), 'args' => ['page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]]],
            ['methods' => 'POST', 'permission_callback' => $permission, 'callback' => [self::class, 'send'], 'args' => ['message' => ['type' => 'string', 'required' => true, 'minLength' => 1, 'maxLength' => 4000], 'selection' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 251], 'run_id' => ['type' => 'integer', 'minimum' => 0, 'default' => 0], 'page_context' => ['type' => 'string', 'maxLength' => 110000]]],
            ['methods' => 'DELETE', 'permission_callback' => $permission, 'callback' => static function (WP_REST_Request $request): WP_REST_Response|WP_Error {
                $ids = $request->get_param('ids');
                if (!is_array($ids)) return new WP_Error('agent_delete_ids', '请选择要删除的对话。', ['status' => 400]);
                $result = Oyiso_Agent_Store::remove($ids);
                return is_wp_error($result) ? $result : new WP_REST_Response(['deleted' => $result]);
            }, 'args' => ['ids' => ['type' => 'array', 'required' => true, 'minItems' => 1, 'maxItems' => 100, 'uniqueItems' => true, 'items' => ['type' => 'integer', 'minimum' => 1]]]],
        ]);
        register_rest_route($root, '/runs/(?P<id>\d+)', ['methods' => 'GET', 'permission_callback' => $permission, 'callback' => [self::class, 'read']]);
        register_rest_route($root, '/files/(?P<id>\d+)', ['methods' => 'GET', 'permission_callback' => $permission, 'callback' => static function (WP_REST_Request $request): WP_REST_Response|WP_Error {
            $file = Oyiso_Agent_Attachments::read((int) $request->get_param('id'));
            return is_wp_error($file) ? $file : new WP_REST_Response($file, 200, ['Cache-Control' => 'no-store, private']);
        }]);
        foreach (['step', 'cancel'] as $action) {
            register_rest_route($root, '/runs/(?P<id>\d+)/' . $action, ['methods' => 'POST', 'permission_callback' => $permission, 'callback' => static fn(WP_REST_Request $request): WP_REST_Response|WP_Error => self::control($request, $action)]);
        }
        register_rest_route($root, '/runs/(?P<id>[0-9]+)/content/(?P<proposal>[a-f0-9-]{36})', ['methods' => 'POST', 'permission_callback' => $permission, 'callback' => [Oyiso_Agent_Content::class, 'control']]);
        register_rest_route($root, '/runs/(?P<id>\d+)/changes/(?P<proposal>[a-f0-9-]{36})', ['methods' => 'POST', 'permission_callback' => $permission, 'callback' => [self::class, 'change'], 'args' => ['action' => ['type' => 'string', 'enum' => ['apply', 'trash', 'restore', 'revert', 'dismiss'], 'required' => true]]]);
        register_rest_route($root, '/runs/(?P<id>\d+)/mcp/(?P<proposal>[a-f0-9-]{36})', ['methods' => 'POST', 'permission_callback' => $permission, 'callback' => [self::class, 'mcp'], 'args' => ['action' => ['type' => 'string', 'enum' => ['execute', 'dismiss'], 'required' => true]]]);
    }

    /** @param WP_REST_Request<AgentRequest> $request */
    public static function send(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!Oyiso_Agent_Config::supported() || !Oyiso_Agent_Config::enabled()) {
            return new WP_Error('agent_not_ready', '请先启用 AI Agent，并使用 WordPress 7.0 或以上版本。', ['status' => 400]);
        }
        $message = trim((string) $request->get_param('message'));
        $uploads = Oyiso_Agent_Attachments::uploads($request);
        if (is_wp_error($uploads)) return $uploads;
        $context = Oyiso_Agent_Page_Context::parse($request->get_param('page_context'));
        if (is_wp_error($context)) return $context;
        if ($message === '' && $uploads !== []) $message = '请分析附件。';
        if ($message === '') {
            return new WP_Error('agent_message_empty', '请输入要处理的任务。', ['status' => 400]);
        }
        $id = (int) $request->get_param('run_id');
        if ($id > 0 && !Oyiso_Agent_Store::owned($id)) {
            return self::missing();
        }
        $selection = $request->get_param('selection');
        if ($id === 0 && (!is_string($selection) || !isset(Oyiso_Agent_Connections::choices()[$selection]))) {
            return new WP_Error('agent_model_selection', '请先在 AI 助手中选择已添加且可用的模型。', ['status' => 400]);
        }
        $run = $id > 0 ? Oyiso_Agent_Store::get($id) : Oyiso_Agent_Store::create($message, is_string($selection) ? $selection : null);
        if (is_wp_error($run)) {
            return $run;
        }
        $id = $run['id'];
        if (!Oyiso_Agent_Store::lock('run_' . $id)) {
            return self::busy();
        }
        try {
            $run = Oyiso_Agent_Store::get($id);
            if (is_wp_error($run)) {
                return $run;
            }
            if (Oyiso_Agent_Store::cancelled($id) || ($run['conversation'] !== [] && in_array($run['status'], ['queued', 'running'], true))) {
                return new WP_Error('agent_task_active', '当前任务已取消或仍在执行，请等待完成或新建对话。', ['status' => 409]);
            }
            $selection = is_string($selection) ? $selection : ($run['selection'] ?? null);
            if ($selection === null) return new WP_Error('agent_model_selection', '此对话尚未选择模型，请在 AI 助手中选择模型后重新发送。', ['status' => 400]);
            if (!isset(Oyiso_Agent_Connections::choices()[$selection])) return new WP_Error('agent_model_selection', '所选模型已不可用，请重新选择模型。', ['status' => 400]);
            $run['selection'] = $selection;
            $attachments = Oyiso_Agent_Attachments::save($id, $uploads);
            if (is_wp_error($attachments)) return $attachments;
            Oyiso_Agent_Runner::message($run, $message, $attachments, $context);
        } finally {
            Oyiso_Agent_Store::unlock('run_' . $id);
        }
        if (!Oyiso_Agent_Live::requested($request)) Oyiso_Agent_Runner::enqueue($id);
        elseif (!wp_next_scheduled(Oyiso_Agent_Runner::HOOK, [$id])) wp_schedule_single_event(time() + 10, Oyiso_Agent_Runner::HOOK, [$id]);
        return new WP_REST_Response(Oyiso_Agent_Store::view($run), 201);
    }

    /** @param WP_REST_Request<AgentRequest> $request */
    public static function read(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (int) $request->get_param('id');
        if (!Oyiso_Agent_Store::owned($id)) {
            return self::missing();
        }
        $run = Oyiso_Agent_Store::get($id);
        return is_wp_error($run) ? $run : new WP_REST_Response(Oyiso_Agent_Store::view($run), 200, ['Cache-Control' => 'no-store, private']);
    }

    /** @param WP_REST_Request<AgentRequest> $request */
    public static function control(WP_REST_Request $request, string $action): WP_REST_Response|WP_Error
    {
        $id = (int) $request->get_param('id');
        if (!Oyiso_Agent_Store::owned($id)) {
            return self::missing();
        }
        if ($action === 'cancel') {
            $run = Oyiso_Agent_Store::get($id);
            if (is_wp_error($run)) {
                return $run;
            }
            if (!in_array($run['status'], ['queued', 'running', 'waiting'], true)) {
                return new WP_Error('agent_cancel_state', '此任务当前无需取消。', ['status' => 409]);
            }
            Oyiso_Agent_Store::cancel($id);
        } elseif (!Oyiso_Agent_Live::requested($request)) {
            (new Oyiso_Agent_Runner(new Oyiso_Agent_Model()))->step($id);
        }
        return self::read($request);
    }

    /** @param WP_REST_Request<AgentRequest> $request */
    public static function change(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (int) $request->get_param('id');
        if (!Oyiso_Agent_Store::owned($id)) {
            return self::missing();
        }
        if (!Oyiso_Agent_Config::supported() || !Oyiso_Agent_Store::lock('run_' . $id)) {
            return self::busy();
        }
        try {
            $run = Oyiso_Agent_Store::get($id);
            if (is_wp_error($run)) {
                return $run;
            }
            if (in_array($run['status'], ['queued', 'running'], true)) {
                return new WP_Error('agent_change_state', '请等待任务停止后再操作修改方案。', ['status' => 409]);
            }
            $action = (string) $request->get_param('action');
            if (!in_array($action, ['apply', 'trash', 'restore', 'revert', 'dismiss'], true)) {
                return new WP_Error('agent_action_invalid', '操作无效。', ['status' => 400]);
            }
            if ($run['status'] === 'cancelled' && !in_array($action, ['revert', 'restore'], true)) {
                return new WP_Error('agent_change_cancelled', '任务已取消，待审核的修改已停止。', ['status' => 409]);
            }
            $proposal = (string) $request->get_param('proposal');
            $matches = array_values(array_filter($run['changes'], static fn(array $change): bool => $change['id'] === $proposal));
            if ($matches === []) return new WP_Error('agent_proposal_missing', '商品操作方案不存在。', ['status' => 404]);
            $kind = ($matches[0]['type'] ?? 'update') === 'trash' ? 'trash' : 'update';
            if ($kind === 'trash' && in_array($action, ['apply', 'revert'], true)) return new WP_Error('agent_trash_confirmation', '请刷新页面，通过新的“移入回收站”或“恢复商品”按钮确认。', ['status' => 400]);
            if ($kind !== 'trash' && in_array($action, ['trash', 'restore', 'dismiss'], true)) return new WP_Error('agent_action_invalid', '此修改方案不支持该操作。', ['status' => 400]);
            if ($action === 'trash') $action = 'apply';
            if ($action === 'restore') $action = 'revert';
            Oyiso_Agent_Abilities::bind(new Oyiso_Agent_Tools($run, $action));
            $ability = wp_get_ability('oyiso/' . $action . '-product-' . $kind);
            $result = $ability?->execute(['proposal_id' => $proposal]);
            if (is_wp_error($result)) {
                return $result;
            }
            if ($result === null) {
                return new WP_Error('agent_ability_missing', '商品修改能力尚未注册。', ['status' => 400]);
            }
            $run['status'] = count(array_filter($run['changes'], static fn(array $change): bool => $change['status'] === 'pending')) > 0 || Oyiso_Agent_Content::pending($run) || Oyiso_Agent_MCP::pending($run) ? 'waiting' : 'completed';
            $run['error'] = '';
            Oyiso_Agent_Store::save($run);
            return new WP_REST_Response(Oyiso_Agent_Store::view($run));
        } finally {
            Oyiso_Agent_Abilities::bind(null);
            Oyiso_Agent_Store::unlock('run_' . $id);
        }
    }

    /** @param WP_REST_Request<AgentRequest> $request */
    public static function mcp(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (int) $request->get_param('id');
        if (!Oyiso_Agent_Store::owned($id)) return self::missing();
        if (!Oyiso_Agent_Store::lock('run_' . $id)) return self::busy();
        $enqueue = false;
        try {
            $run = Oyiso_Agent_Store::get($id);
            if (is_wp_error($run)) return $run;
            if (in_array($run['status'], ['queued', 'running'], true)) return self::busy();
            $proposal = (string) $request->get_param('proposal');
            $decision = (string) $request->get_param('action');
            $result = Oyiso_Agent_MCP::approve($run, $proposal, $decision);
            if (is_wp_error($result)) return $result;
            $pending = Oyiso_Agent_MCP::pending($run) || Oyiso_Agent_Content::pending($run) || count(array_filter($run['changes'], static fn(array $change): bool => $change['status'] === 'pending')) > 0;
            $executed = count(array_filter($run['mcp_actions'] ?? [], static fn(array $action): bool => $action['id'] === $proposal && $action['status'] === 'completed')) > 0;
            $run['status'] = $pending ? 'waiting' : ($decision === 'execute' && $executed ? 'queued' : 'completed');
            if ($run['status'] === 'queued') $run['step'] = 0;
            Oyiso_Agent_Store::save($run);
            $enqueue = $run['status'] === 'queued';
            return new WP_REST_Response(Oyiso_Agent_Store::view($run));
        } finally {
            Oyiso_Agent_Store::unlock('run_' . $id);
            if ($enqueue) Oyiso_Agent_Runner::enqueue($id);
        }
    }

    public static function assets(string $hook): void
    {
        Oyiso_Agent_Model_Settings::assets($hook);
        Oyiso_Agent_File_Manager::assets($hook);
        Oyiso_Agent_MCP::assets($hook);
        if (!self::available()) {
            return;
        }
        Oyiso_Agent_File_Manager::previewAssets();
        wp_enqueue_style('oyiso-agent', plugins_url('assets/agent.css', __FILE__), ['oyiso-modals'], (string) filemtime(__DIR__ . '/assets/agent.css'));
        wp_enqueue_script('oyiso-agent-panel', plugins_url('assets/panel.js', __FILE__), [], (string) filemtime(__DIR__ . '/assets/panel.js'), true);
        wp_enqueue_script('oyiso-agent-page', plugins_url('assets/page-context.js', __FILE__), ['oyiso-agent-panel'], (string) filemtime(__DIR__ . '/assets/page-context.js'), true);
        wp_add_inline_script('oyiso-agent-page', 'window.oyisoAgent.pageContext = ' . wp_json_encode(Oyiso_Agent_Page_Context::current()) . ';', 'before');
        wp_enqueue_script('oyiso-agent-markdown-it', plugins_url('assets/lib/markdown-it.min.js', __FILE__), [], '15.0.3', true);
        wp_enqueue_script('oyiso-agent-markdown', plugins_url('assets/markdown.js', __FILE__), ['oyiso-agent-markdown-it'], (string) filemtime(__DIR__ . '/assets/markdown.js'), true);
        wp_enqueue_script('oyiso-agent-history', plugins_url('assets/history.js', __FILE__), ['oyiso-modal-motion'], (string) filemtime(__DIR__ . '/assets/history.js'), true);
        wp_enqueue_script('oyiso-agent-composer', plugins_url('assets/composer.js', __FILE__), ['oyiso-agent-file-preview'], (string) filemtime(__DIR__ . '/assets/composer.js'), true);
        wp_enqueue_script('oyiso-agent-live', plugins_url('assets/live.js', __FILE__), [], (string) filemtime(__DIR__ . '/assets/live.js'), true);
        wp_enqueue_script('oyiso-agent', plugins_url('assets/agent.js', __FILE__), ['jquery', 'oyiso-agent-panel', 'oyiso-agent-page', 'oyiso-agent-markdown', 'oyiso-agent-history', 'oyiso-agent-composer', 'oyiso-agent-live'], (string) filemtime(__DIR__ . '/assets/agent.js'), true);
        wp_localize_script('oyiso-agent-panel', 'oyisoAgent', ['url' => rest_url('oyiso-agent/v1/'), 'nonce' => wp_create_nonce('wp_rest'), 'user' => get_current_user_id(), 'enabled' => Oyiso_Agent_Config::enabled(), 'supported' => Oyiso_Agent_Config::supported(), 'writeTools' => Oyiso_Agent_Permissions::granted(), 'models' => Oyiso_Agent_Connections::choices(), 'attachmentMaxFile' => min(Oyiso_Agent_Attachments::MAX_FILE, wp_max_upload_size()), 'attachmentMaxImage' => min(Oyiso_Agent_Attachments::MAX_IMAGE_UPLOAD, wp_max_upload_size()), 'attachmentMaxVideo' => min(Oyiso_Agent_Attachments::MAX_VIDEO_UPLOAD, wp_max_upload_size()), 'attachmentMaxTotal' => min(Oyiso_Agent_Attachments::MAX_UPLOAD_TOTAL, max(0, wp_convert_hr_to_bytes(ini_get('post_max_size') ?: '8M') - 65536)), 'attachmentTextPreview' => Oyiso_Agent_Attachments::MAX_TEXT_PREVIEW]);
        wp_add_inline_script('oyiso-agent-panel', 'window.oyisoAgent.contextModels = ' . wp_json_encode(Oyiso_Agent_Context::models()) . ';', 'after');
    }

    public static function frontendAssets(): void
    {
        if (is_admin() || !self::available()) return;
        require_once dirname(__DIR__) . '/admin/config-collapse.php';
        oyiso_enqueue_admin_theme_assets();
        wp_enqueue_style('dashicons');
        self::assets('');
        wp_enqueue_style('oyiso-agent-frontend', plugins_url('assets/frontend.css', __FILE__), ['oyiso-agent'], (string) filemtime(__DIR__ . '/assets/frontend.css'));
    }

    /** @param list<string> $classes
     * @return list<string>
     */
    public static function bodyClasses(array $classes): array
    {
        if (!is_admin() && self::available()) $classes[] = 'oyiso-agent-frontend';
        return $classes;
    }

    private static function available(): bool
    {
        return Oyiso_Agent_Config::enabled() && current_user_can('manage_options') && !is_network_admin() && !is_user_admin() && (is_admin() || Oyiso_Agent_Config::frontendEnabled());
    }

    public static function panel(): void
    {
        if (!self::available()) {
            return;
        }
        ?>
        <span id="oyiso-agent-launcher-help" class="screen-reader-text">拖动可移动，靠边自动收起；也可使用方向键移动，按住 Shift 加快。</span>
        <div id="oyiso-agent-launcher-wrap" style="visibility: hidden" data-agent-initializing><button type="button" id="oyiso-agent-launcher" aria-label="AI 助手" aria-describedby="oyiso-agent-launcher-help" aria-controls="oyiso-agent-panel" aria-expanded="false" title="AI 助手（可拖动，靠边自动收起）"><span class="dashicons dashicons-format-chat" aria-hidden="true"></span><span>AI 助手</span></button></div>
        <aside id="oyiso-agent-panel" class="oyiso-agent oyiso-agent-panel" aria-labelledby="oyiso-agent-title" hidden>
            <?php foreach (['w', 'e', 'n', 's', 'nw', 'ne', 'sw', 'se'] as $edge): ?>
                <div class="oyiso-agent-resize" data-agent-resize="<?php echo esc_attr($edge); ?>" <?php if (strlen($edge) === 1): ?>role="separator" tabindex="0" aria-label="<?php echo esc_attr(in_array($edge, ['w', 'e'], true) ? '调整助手宽度' : '调整助手高度'); ?>" aria-controls="oyiso-agent-panel" aria-orientation="<?php echo esc_attr(in_array($edge, ['w', 'e'], true) ? 'vertical' : 'horizontal'); ?>"<?php else: ?>aria-hidden="true"<?php endif; ?>></div>
            <?php endforeach; ?>
            <header class="oyiso-agent-panel-heading" data-agent-drag tabindex="-1">
                <h2 id="oyiso-agent-title">AI 助手</h2>
                <div class="oyiso-agent-panel-actions">
                    <button type="button" class="oyiso-agent-icon-button" data-agent-history-toggle aria-controls="oyiso-agent-panel-history" aria-expanded="false" aria-label="对话记录" title="对话记录"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11a9 9 0 1 1 2.6 7M3 4v7h7M12 7v5l3 2"/></svg></button>
                    <button type="button" class="button oyiso-agent-new" data-agent-new><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg><span>新建对话</span></button>
                    <button type="button" class="oyiso-agent-icon-button" data-agent-refresh title="刷新当前对话" aria-label="刷新当前对话" aria-busy="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12a9 9 0 0 1 15.36-6.36L21 8M21 3v5h-5M21 12a9 9 0 0 1-15.36 6.36L3 16M8 16H3v5"/></svg></button>
                    <div class="oyiso-agent-settings-wrap">
                    <button type="button" class="oyiso-agent-icon-button" data-agent-settings-toggle title="设置" aria-label="设置" aria-haspopup="menu" aria-controls="oyiso-agent-settings-menu" aria-expanded="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9.5 3-.4 2.1-1.8 1-2-.7-2.5 4.3L4.5 11v2l-1.7 1.3 2.5 4.3 2-.7 1.8 1 .4 2.1h5l.4-2.1 1.8-1 2 .7 2.5-4.3-1.7-1.3v-2l1.7-1.3-2.5-4.3-2 .7-1.8-1-.4-2.1z"/><circle cx="12" cy="12" r="3"/></svg></button>
                    <div id="oyiso-agent-settings-menu" class="oyiso-agent-settings-menu" data-agent-settings-menu role="menu" aria-label="助手设置" hidden>
                    <div class="oyiso-agent-layout-grid" data-agent-layout-menu role="group" aria-label="窗口布局">
                        <button type="button" role="menuitemradio" aria-checked="false" aria-label="左侧分屏" title="左侧分屏" data-agent-layout-choice="left"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16"/></svg><span>左侧</span></button>
                        <button type="button" role="menuitemradio" aria-checked="true" aria-label="右侧分屏" title="右侧分屏" data-agent-layout-choice="right"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M15 4v16"/></svg><span>右侧</span></button>
                        <button type="button" role="menuitemradio" aria-checked="false" aria-label="悬浮窗" title="悬浮窗" data-agent-layout-choice="floating"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="8" y="8" width="13" height="12" rx="2"/><path d="M16 8V3H3v13h5"/></svg><span>悬浮</span></button>
                        <button type="button" role="menuitem" data-agent-reset aria-label="恢复默认大小和位置" title="重置窗口"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M9 9v11"/></svg><span>重置</span></button>
                    </div>
                    <a role="menuitem" class="oyiso-agent-settings-link" data-agent-tab="ai-agent/agent-model" href="<?php echo esc_url(admin_url('plugins.php?page=oyiso#tab=ai-agent/agent-model')); ?>" aria-label="模型配置"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 17h16"/><circle cx="9" cy="7" r="3"/><circle cx="15" cy="17" r="3"/></svg><span>模型配置</span></a>
                    </div></div>
                    <button type="button" class="oyiso-agent-icon-button" data-agent-fullscreen-toggle title="全屏" aria-label="全屏" aria-pressed="false">
                        <span data-agent-fullscreen-icon="enter" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 3H5a2 2 0 0 0-2 2v3m13-5h3a2 2 0 0 1 2 2v3M3 16v3a2 2 0 0 0 2 2h3m8 0h3a2 2 0 0 0 2-2v-3"/></svg></span>
                        <span data-agent-fullscreen-icon="exit" aria-hidden="true" hidden><svg viewBox="0 0 24 24"><path d="M8 3v3a2 2 0 0 1-2 2H3m18 0h-3a2 2 0 0 1-2-2V3M3 16h3a2 2 0 0 1 2 2v3m8 0v-3a2 2 0 0 1 2-2h3"/></svg></span>
                    </button>
                    <button type="button" class="oyiso-agent-icon-button" data-agent-close title="关闭助手" aria-label="关闭助手"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M6 18 18 6"/></svg></button>
                </div>
            </header>
            <div class="oyiso-agent-panel-body">
                <section id="oyiso-agent-panel-history" class="oyiso-agent-panel-history" data-agent-runs="panel" aria-labelledby="oyiso-agent-history-title" hidden>
                    <div class="oyiso-agent-history-heading"><h3 id="oyiso-agent-history-title">对话记录</h3><button type="button" class="oyiso-agent-icon-button" data-agent-history-manage aria-label="选择对话" aria-pressed="false" title="选择对话"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="m7 12 3 3 7-7"/></svg></button><button type="button" class="oyiso-agent-icon-button" data-agent-history-refresh title="刷新记录" aria-label="刷新记录"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12a9 9 0 0 1 15.36-6.36L21 8M21 3v5h-5M21 12a9 9 0 0 1-15.36 6.36L3 16M8 16H3v5"/></svg></button><button type="button" class="oyiso-agent-icon-button" data-agent-history-back title="关闭对话记录" aria-label="关闭对话记录"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M6 18 18 6"/></svg></button></div>
                    <?php self::historySelection(); ?>
                    <p class="oyiso-agent-history-notice" data-agent-history-notice role="status" aria-live="polite"></p>
                    <ul class="oyiso-agent-history-list" data-agent-history-body aria-label="历史对话"></ul>
                    <div class="oyiso-agent-pagination"><button type="button" class="oyiso-agent-icon-button" data-agent-history-prev aria-label="上一页" title="上一页"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m14 6-6 6 6 6"/></svg></button><span data-agent-history-page></span><button type="button" class="oyiso-agent-icon-button" data-agent-history-next aria-label="下一页" title="下一页"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m10 6 6 6-6 6"/></svg></button></div>
                </section>
                <button type="button" class="oyiso-agent-history-dismiss" data-agent-history-dismiss tabindex="-1" aria-label="关闭对话记录" hidden></button>
                <?php self::chat(); ?>
            </div>
        </aside>
        <dialog class="oyiso-agent-confirm oyiso-confirm-dialog" data-agent-delete-dialog aria-labelledby="oyiso-agent-delete-title" aria-describedby="oyiso-agent-delete-description">
            <div class="oyiso-confirm-dialog__heading"><h2 id="oyiso-agent-delete-title">删除对话</h2><button type="button" class="oyiso-confirm-dialog__close" data-agent-delete-close aria-label="关闭">×</button></div>
            <div class="oyiso-confirm-dialog__body"><p id="oyiso-agent-delete-description" data-agent-delete-description></p><p class="oyiso-confirm-dialog__error" data-agent-delete-error role="alert" hidden></p></div>
            <div class="oyiso-confirm-dialog__footer"><button type="button" class="button" data-agent-delete-cancel>取消</button><button type="button" class="button oyiso-button-danger" data-agent-delete-confirm>删除</button></div>
        </dialog>
        <dialog class="oyiso-agent-confirm oyiso-confirm-dialog" data-agent-product-dialog aria-labelledby="oyiso-agent-product-title" aria-describedby="oyiso-agent-product-description">
            <div class="oyiso-confirm-dialog__heading"><h2 id="oyiso-agent-product-title">商品移入回收站</h2><button type="button" class="oyiso-confirm-dialog__close" data-agent-product-close aria-label="关闭">×</button></div>
            <div class="oyiso-confirm-dialog__body"><p id="oyiso-agent-product-description" data-agent-product-description></p><p class="description">商品及其关联变体会从前台下架，图片和历史订单保留。可在回收站保留期间恢复。</p></div>
            <div class="oyiso-confirm-dialog__footer"><button type="button" class="button" data-agent-product-cancel>取消</button><button type="button" class="button oyiso-button-danger" data-agent-product-confirm>移入回收站</button></div>
        </dialog>
        <dialog class="oyiso-agent-confirm oyiso-confirm-dialog" data-agent-refill-dialog aria-labelledby="oyiso-agent-refill-title" aria-describedby="oyiso-agent-refill-description oyiso-agent-refill-note">
            <div class="oyiso-confirm-dialog__heading"><h2 id="oyiso-agent-refill-title">重新填入编辑器</h2><button type="button" class="oyiso-confirm-dialog__close" data-agent-refill-close aria-label="关闭">×</button></div>
            <div class="oyiso-confirm-dialog__body"><p id="oyiso-agent-refill-description" data-agent-refill-description></p><p class="description" id="oyiso-agent-refill-note">将重新载入该方案涉及的内容，填入后仍需使用 WordPress 的保存按钮手动保存。</p></div>
            <div class="oyiso-confirm-dialog__footer"><button type="button" class="button" data-agent-refill-cancel>取消</button><button type="button" class="button button-primary" data-agent-refill-confirm>确认重新填入</button></div>
        </dialog>
        <?php
    }

    private static function chat(): void
    {
        ?>
        <div id="oyiso-agent-chat" class="oyiso-agent">
            <p class="oyiso-agent-page-context" data-agent-page-context hidden></p>
            <p class="oyiso-agent-notice" data-agent-notice role="status" aria-live="polite"></p>
            <div class="oyiso-agent-scroll">
                <details class="oyiso-agent-legacy-proposals" data-agent-legacy-proposals hidden><summary>早期操作方案</summary><div data-agent-changes class="oyiso-agent-changes"></div></details>
                <div class="oyiso-agent-messages" data-agent-messages role="log" aria-label="对话内容" aria-live="polite"><div class="oyiso-agent-empty"><span class="oyiso-agent-empty-mark" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M13 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-8M10 14l1-4 8-8 3 3-8 8-4 1Z"/></svg></span><h3>一起完成当前的工作</h3><p>写文章、完善商品，或查询站内数据。告诉我你想做什么。</p><div class="oyiso-agent-examples"><button type="button" class="button" data-agent-example="查找缺少短描述和 SEO 信息的商品，先列出需要完善的内容。">检查商品内容</button><button type="button" class="button" data-agent-example="我想完善一个商品的长短描述和 SEO 信息。请先询问商品 ID，再读取商品并核对现有信息，生成修改预览。">完善商品文案</button></div><p class="oyiso-agent-empty-note">修改会先生成预览，由你确认后执行。</p></div></div>
                <span class="oyiso-agent-response-status" data-agent-response-status role="status" aria-live="polite" hidden><span class="oyiso-agent-response-dot" aria-hidden="true"></span><span data-agent-response-label>正在准备回复…</span></span>
            </div>
            <div data-agent-progress-parking hidden><details class="oyiso-agent-progress" hidden><summary><span class="oyiso-agent-progress-title">执行过程</span><span class="oyiso-agent-progress-state" data-agent-status>尚未开始</span></summary><div class="oyiso-agent-progress-body"><p class="description oyiso-agent-progress-usage" data-agent-usage></p><ol class="oyiso-agent-progress-events" data-agent-events></ol></div></details></div>
            <div class="oyiso-agent-composer">
                <div class="oyiso-agent-composer-surface">
                    <label class="screen-reader-text" for="oyiso-agent-prompt">任务指令</label>
                    <div class="oyiso-agent-attachments" data-agent-attachments hidden></div>
                    <textarea id="oyiso-agent-prompt" rows="1" maxlength="4000" placeholder="发送消息，或粘贴图片和文件…"></textarea>
                    <div class="oyiso-agent-composer-actions">
                        <button type="button" class="oyiso-agent-icon-button" data-agent-attach aria-label="添加附件" title="添加附件"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg></button><input type="file" data-agent-file multiple accept="image/png,image/jpeg,image/webp,image/gif,video/mp4,video/webm,.mp4,.webm,application/pdf,.txt,.md,.csv,.tsv,.json,.html,.htm,.xml,.log,.yaml,.yml,.css,.js,.ts,.php,.sql" hidden>
                        <select id="oyiso-agent-model" class="oyiso-agent-model-picker" data-agent-model aria-label="对话模型"><option value="">暂无可用模型</option></select>
                        <div>
                            <span class="oyiso-agent-context" data-agent-context>
                                <button type="button" class="oyiso-agent-context-button" data-agent-context-button aria-label="上下文用量尚未获取" aria-describedby="oyiso-agent-context-detail"><svg viewBox="0 0 24 24" aria-hidden="true"><circle class="oyiso-agent-context-track" cx="12" cy="12" r="9"/><circle class="oyiso-agent-context-value" cx="12" cy="12" r="9" pathLength="100" stroke-dasharray="100" stroke-dashoffset="100"/></svg><span data-agent-context-percent>—</span></button>
                                <span class="oyiso-agent-context-detail" id="oyiso-agent-context-detail" role="tooltip"><strong>上下文用量</strong><span data-agent-context-count>等待首次请求</span><span data-agent-context-limit>模型容量尚未获取</span><span class="oyiso-agent-context-note" data-agent-context-note>显示最近一次请求的输入用量，响应后更新。</span></span>
                            </span>
                            <button type="button" class="button oyiso-agent-composer-control" data-agent-cancel aria-label="停止回复" title="停止回复" hidden><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="6" y="6" width="12" height="12" rx="2"/></svg></button>
                            <button type="button" class="button button-primary oyiso-agent-composer-control" data-agent-send aria-label="发送" title="发送"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V5m-6 6 6-6 6 6"/></svg></button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public static function connection(): void
    {
        if (!Oyiso_Agent_Config::supported()) {
            echo '<p>AI Agent 需要 WordPress 7.0 或以上版本，当前环境暂不可用。</p>';
            return;
        }
        printf('<p>选择下方的 DeepSeek、Gemini 或 Agnes AI 预设后，可直接在本页配置。每个预设分别保存地址、模型和密钥。</p><p>也可使用已安装的 WordPress 模型连接器。<a href="%s">管理 WordPress 连接器</a></p>', esc_url(admin_url('options-connectors.php')));
    }

    public static function connectionTest(string $preset): void
    {
        if (!Oyiso_Agent_Config::enabled()) {
            echo '<p class="description">请先启用 AI Agent 并保存配置，刷新页面后测试连接。</p>';
            return;
        }
        printf('<button type="button" class="button" data-agent-test-connection="%s">测试连接</button><p class="description">先保存配置。测试会发送两次简短模型请求，检查工具调用和结果回传，并消耗少量 API 额度。</p><p data-agent-connection-notice="%s" role="status" aria-live="polite"></p>', esc_attr($preset), esc_attr($preset));
    }

    public static function history(): void
    {
        if (!Oyiso_Agent_Config::enabled()) {
            echo '<p class="description">请先启用 AI Agent 并保存配置，刷新页面后查看对话记录。</p>';
            return;
        }
        echo '<div id="oyiso-agent-history" class="oyiso-agent" data-agent-runs="history"><div class="oyiso-agent-heading"><p class="description">查看对话和执行过程，或选择对话批量删除。</p><div><button type="button" class="button" data-agent-history-manage aria-pressed="false">选择对话</button> <button type="button" class="button" data-agent-history-refresh>刷新记录</button></div></div>';
        self::historySelection();
        echo '<p role="status" aria-live="polite" data-agent-history-notice></p><div class="oyiso-agent-table-wrap"><table class="widefat striped"><thead><tr><th data-agent-history-select-column hidden>选择</th><th>对话</th><th>状态</th><th>更新时间</th><th>操作</th></tr></thead><tbody data-agent-history-body><tr><td colspan="5">正在读取记录…</td></tr></tbody></table></div><div class="oyiso-agent-pagination"><button type="button" class="button" data-agent-history-prev>上一页</button><span data-agent-history-page></span><button type="button" class="button" data-agent-history-next>下一页</button></div></div>';
    }

    private static function historySelection(): void
    {
        echo '<div class="oyiso-agent-history-selection" data-agent-history-selection hidden><label><input type="checkbox" data-agent-history-all> 本页</label><span data-agent-history-count>已选 0</span><button type="button" class="button button-small" data-agent-history-delete disabled>删除</button></div>';
    }

    public static function tools(): void
    {
        Oyiso_Agent_MCP::render();
    }

    private static function missing(): WP_Error
    {
        return new WP_Error('agent_task_missing', '任务不存在或不属于当前用户。', ['status' => 404]);
    }

    private static function busy(): WP_Error
    {
        return new WP_Error('agent_task_busy', '任务正在处理，请稍后重试。', ['status' => 409]);
    }
}
