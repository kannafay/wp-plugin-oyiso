<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/** @phpstan-type AgentRequest array{id?:int|string, run_id?:int, message?:string, page?:int, action?:string, proposal?:string} */
final class Oyiso_Agent_Admin
{
    public static function routes(): void
    {
        $permission = static fn(): bool => current_user_can('manage_options');
        $root = 'oyiso-agent/v1';
        register_rest_route($root, '/status', ['methods' => 'GET', 'permission_callback' => $permission, 'callback' => static fn(): WP_REST_Response => new WP_REST_Response(['enabled' => Oyiso_Agent_Config::enabled(), 'supported' => Oyiso_Agent_Config::supported(), 'search_configured' => Oyiso_Agent_Config::hasSecret('search_key'), 'keywords_configured' => Oyiso_Agent_Config::hasSecret('keywords_password'), 'secrets' => Oyiso_Agent_Config::secretStatus()])]);
        register_rest_route($root, '/connections/(?P<preset>deepseek|gemini|agnes)/test', ['methods' => 'POST', 'permission_callback' => $permission, 'callback' => static function (WP_REST_Request $request): WP_REST_Response|WP_Error {
            $result = Oyiso_Agent_Connectors::test((string) $request->get_param('preset'));
            return is_wp_error($result) ? $result : new WP_REST_Response($result);
        }]);
        register_rest_route($root, '/runs', [
            ['methods' => 'GET', 'permission_callback' => $permission, 'callback' => static fn(WP_REST_Request $request): WP_REST_Response => new WP_REST_Response(Oyiso_Agent_Store::recent(max(1, (int) $request->get_param('page')))), 'args' => ['page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]]],
            ['methods' => 'POST', 'permission_callback' => $permission, 'callback' => [self::class, 'send'], 'args' => ['message' => ['type' => 'string', 'required' => true, 'minLength' => 1, 'maxLength' => 4000], 'run_id' => ['type' => 'integer', 'minimum' => 0, 'default' => 0]]],
        ]);
        register_rest_route($root, '/runs/(?P<id>\d+)', ['methods' => 'GET', 'permission_callback' => $permission, 'callback' => [self::class, 'read']]);
        foreach (['step', 'cancel'] as $action) {
            register_rest_route($root, '/runs/(?P<id>\d+)/' . $action, ['methods' => 'POST', 'permission_callback' => $permission, 'callback' => static fn(WP_REST_Request $request): WP_REST_Response|WP_Error => self::control($request, $action)]);
        }
        register_rest_route($root, '/runs/(?P<id>\d+)/changes/(?P<proposal>[a-f0-9-]{36})', ['methods' => 'POST', 'permission_callback' => $permission, 'callback' => [self::class, 'change'], 'args' => ['action' => ['type' => 'string', 'enum' => ['apply', 'revert'], 'required' => true]]]);
    }

    /** @param WP_REST_Request<AgentRequest> $request */
    public static function send(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!Oyiso_Agent_Config::supported() || !Oyiso_Agent_Config::enabled()) {
            return new WP_Error('agent_not_ready', '请先启用 AI Agent，并使用 WordPress 7.0 或以上版本。', ['status' => 400]);
        }
        $message = trim((string) $request->get_param('message'));
        if ($message === '') {
            return new WP_Error('agent_message_empty', '请输入要处理的任务。', ['status' => 400]);
        }
        $id = (int) $request->get_param('run_id');
        if ($id > 0 && !Oyiso_Agent_Store::owned($id)) {
            return self::missing();
        }
        $run = $id > 0 ? Oyiso_Agent_Store::get($id) : Oyiso_Agent_Store::create($message);
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
            if (count($run['conversation']) >= 60) {
                return new WP_Error('agent_chat_limit', '本次对话已达上限，请新建对话。', ['status' => 400]);
            }
            Oyiso_Agent_Runner::message($run, $message);
        } finally {
            Oyiso_Agent_Store::unlock('run_' . $id);
        }
        Oyiso_Agent_Runner::enqueue($id);
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
        return is_wp_error($run) ? $run : new WP_REST_Response(Oyiso_Agent_Store::view($run));
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
        } else {
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
            if (!in_array($action, ['apply', 'revert'], true)) {
                return new WP_Error('agent_action_invalid', '操作无效。', ['status' => 400]);
            }
            if ($run['status'] === 'cancelled' && $action !== 'revert') {
                return new WP_Error('agent_change_cancelled', '任务已取消，待审核的修改已停止。', ['status' => 409]);
            }
            Oyiso_Agent_Abilities::bind(new Oyiso_Agent_Tools($run, $action));
            $ability = wp_get_ability('oyiso/' . ($action === 'apply' ? 'apply' : 'revert') . '-product-update');
            $result = $ability?->execute(['proposal_id' => (string) $request->get_param('proposal')]);
            if (is_wp_error($result)) {
                return $result;
            }
            if ($result === null) {
                return new WP_Error('agent_ability_missing', '商品修改能力尚未注册。', ['status' => 400]);
            }
            $run['status'] = count(array_filter($run['changes'], static fn(array $change): bool => $change['status'] === 'pending')) > 0 ? 'waiting' : 'completed';
            $run['error'] = '';
            Oyiso_Agent_Store::save($run);
            return new WP_REST_Response(Oyiso_Agent_Store::view($run));
        } finally {
            Oyiso_Agent_Abilities::bind(null);
            Oyiso_Agent_Store::unlock('run_' . $id);
        }
    }

    public static function assets(string $hook): void
    {
        if (!oyiso_is_settings_page_hook($hook)) {
            return;
        }
        wp_enqueue_style('oyiso-agent', plugins_url('assets/agent.css', __FILE__), [], (string) filemtime(__DIR__ . '/assets/agent.css'));
        wp_enqueue_script('oyiso-agent', plugins_url('assets/agent.js', __FILE__), [], (string) filemtime(__DIR__ . '/assets/agent.js'), true);
        wp_localize_script('oyiso-agent', 'oyisoAgent', ['url' => rest_url('oyiso-agent/v1/'), 'nonce' => wp_create_nonce('wp_rest'), 'user' => get_current_user_id(), 'enabled' => Oyiso_Agent_Config::enabled(), 'supported' => Oyiso_Agent_Config::supported()]);
    }

    public static function chat(): void
    {
        ?>
        <div id="oyiso-agent-chat" class="oyiso-agent">
            <div class="oyiso-agent-heading"><div><h2>对话助手</h2><p class="description">查询站内数据、调查公开资料，生成商品内容修改方案。</p></div><button type="button" class="button" data-agent-new>新建对话</button></div>
            <p class="oyiso-agent-notice" data-agent-notice role="status" aria-live="polite"></p>
            <div class="oyiso-agent-workspace">
                <div class="oyiso-agent-main">
                    <div class="oyiso-agent-messages" data-agent-messages role="log" aria-label="对话内容" aria-live="polite"><div class="oyiso-agent-empty"><h3>从一个具体任务开始</h3><p>可以指定商品 ID、分类或资料网址。商品修改会先生成预览。</p><div class="oyiso-agent-examples"><button type="button" class="button" data-agent-example="查找缺少短描述和 SEO 信息的商品，先列出需要完善的内容。">检查商品内容</button><button type="button" class="button" data-agent-example="我想完善一个商品的长短描述和 SEO 信息。请先询问商品 ID，再读取商品并核实资料，生成修改预览。">完善商品文案</button></div></div></div>
                    <div class="oyiso-agent-composer"><label class="screen-reader-text" for="oyiso-agent-prompt">任务指令</label><textarea id="oyiso-agent-prompt" rows="3" maxlength="4000" placeholder="例如：读取商品 123，参考官网资料完善长短描述和 SEO 信息，先给我预览。"></textarea><div class="oyiso-agent-composer-actions"><span class="description">Ctrl / ⌘ + Enter 发送</span><div><button type="button" class="button" data-agent-cancel hidden>取消任务</button><button type="button" class="button button-primary" data-agent-send>发送任务</button></div></div></div>
                </div>
                <aside class="oyiso-agent-progress" aria-label="执行过程"><div class="oyiso-agent-progress-heading"><h3>执行过程</h3><span data-agent-status>尚未开始</span></div><p class="description" data-agent-usage></p><ol data-agent-events><li class="description">发送任务后，这里会显示查询和处理进度。</li></ol></aside>
            </div>
            <div data-agent-changes class="oyiso-agent-changes"></div>
        </div>
        <?php
    }

    public static function connection(): void
    {
        if (!Oyiso_Agent_Config::supported()) {
            echo '<p>AI Agent 需要 WordPress 7.0 或以上版本，当前环境暂不可用。</p>';
            return;
        }
        printf('<p>选择下方的 DeepSeek、Gemini 或 agnes 预设后，可直接在本页配置。每个预设分别保存地址、模型和密钥。</p><p>也可使用已安装的 WordPress 模型连接器。<a href="%s">管理 WordPress 连接器</a></p>', esc_url(admin_url('options-connectors.php')));
    }

    public static function connectionTest(string $preset): void
    {
        printf('<button type="button" class="button" data-agent-test-connection="%s">测试连接</button><p class="description">先保存配置。测试会发送两次简短模型请求，检查工具调用和结果回传，并消耗少量 API 额度。</p><p data-agent-connection-notice="%s" role="status" aria-live="polite"></p>', esc_attr($preset), esc_attr($preset));
    }

    public static function history(): void
    {
        echo '<div id="oyiso-agent-history" class="oyiso-agent"><div class="oyiso-agent-heading"><div><h2>执行记录</h2><p class="description">查看你发起的对话、执行过程和商品修改。</p></div><button type="button" class="button" data-agent-history-refresh>刷新记录</button></div><p role="status" data-agent-history-notice></p><div class="oyiso-agent-table-wrap"><table class="widefat striped"><thead><tr><th>任务</th><th>状态</th><th>创建时间</th><th>操作</th></tr></thead><tbody data-agent-history-body><tr><td colspan="4">正在读取记录…</td></tr></tbody></table></div><div class="oyiso-agent-pagination"><button type="button" class="button" data-agent-history-prev>上一页</button><span data-agent-history-page></span><button type="button" class="button" data-agent-history-next>下一页</button></div></div>';
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
