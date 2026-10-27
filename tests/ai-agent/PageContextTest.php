<?php

declare(strict_types=1);

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;

final class PageContextTest extends TestCase
{
    private array $options;
    private array $posts = [];
    private array $runs = [];

    protected function setUp(): void
    {
        wp_set_current_user(OYISO_AGENT_TEST_ADMIN);
        $this->options = Oyiso_Agent_Config::options();
        update_option('oyiso', array_replace($this->options, ['agent_enabled' => true, 'agent_plugin_tools_version' => '2', 'agent_mcp_namespaces' => ['oyiso-wordpress', 'oyiso-woocommerce'], 'agent_write_tools' => ['oyiso/apply-post-update', 'oyiso/revert-post-update', 'oyiso/create-post-draft', 'oyiso/apply-product-update']]), false);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(OYISO_AGENT_TEST_ADMIN);
        Oyiso_Agent_Abilities::bind(null);
        foreach ($this->runs as $id) { Oyiso_Agent_Store::cancel($id); wp_delete_post($id, true); }
        foreach ($this->posts as $id) wp_delete_post($id, true);
        update_option('oyiso', $this->options, false);
        unset($GLOBALS['post']);
        set_current_screen('dashboard');
    }

    private function post(string $type = 'post'): WP_Post
    {
        $id = wp_insert_post(['post_type' => $type, 'post_status' => 'draft', 'post_author' => OYISO_AGENT_TEST_ADMIN, 'post_title' => '页面上下文验证', 'post_content' => '<p>已保存的正文</p>', 'post_excerpt' => '原摘要'], true);
        self::assertIsInt($id); $this->posts[] = $id;
        return get_post($id);
    }

    private function task(): array
    {
        $run = Oyiso_Agent_Store::create('Page context integration fixture');
        self::assertIsArray($run); $this->runs[] = $run['id']; return $run;
    }

    private function context(WP_Post $post, array $fields = []): array
    {
        return ['screen' => $post->post_type, 'post_id' => $post->ID, 'signature' => wp_create_nonce('oyiso-agent-context:' . $post->post_type . ':' . $post->ID), 'label' => $post->post_title, 'url' => '/wp-admin/post.php?post=' . $post->ID . '&action=edit&_wpnonce=secret&api_key=secret', 'editor' => 'classic', 'dirty' => $fields !== [], 'fields' => array_replace(Oyiso_Agent_Page_Context::fields($post), $fields), 'selection' => '正文'];
    }

    private function tool(array &$run, string $name, array $input = []): mixed
    {
        Oyiso_Agent_Abilities::bind(new Oyiso_Agent_Tools($run));
        return wp_get_ability('oyiso/' . $name)->execute($input);
    }

    private function control(array $run, string $proposal, string $action, array $extra = []): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/oyiso-agent/v1/runs/' . $run['id'] . '/content/' . $proposal);
        foreach (['action' => $action] + $extra as $key => $value) $request->set_param($key, $value);
        return rest_get_server()->dispatch($request);
    }

    private function prepare(array &$run, WP_Post $post, array $fields = ['content' => '<p>新正文</p>']): string
    {
        self::assertIsArray($this->tool($run, 'get-post', ['post_id' => $post->ID]));
        $proposal = $this->tool($run, 'prepare-post-update', ['post_id' => $post->ID, 'fields' => $fields]);
        self::assertIsArray($proposal);
        $run['status'] = 'waiting'; Oyiso_Agent_Store::save($run);
        return $proposal['proposal_id'];
    }

    public function testPageContextRecognizesTheEditorAndKeepsUnsavedContentSeparate(): void
    {
        $post = $this->post(); $GLOBALS['post'] = $post; set_current_screen('post');
        $seed = Oyiso_Agent_Page_Context::current();
        self::assertSame($post->ID, $seed['post_id']);
        $page = Oyiso_Agent_Page_Context::parse(wp_json_encode($this->context($post, ['title' => '未保存的标题', 'content' => '<p>未保存的正文</p>'])));
        self::assertIsArray($page); self::assertTrue($page['dirty']);
        self::assertSame('未保存的标题', $page['fields']['title']);
        self::assertSame('/wp-admin/post.php?post=' . $post->ID . '&action=edit', $page['url']);
        self::assertStringNotContainsString('secret', wp_json_encode($page));
        self::assertSame('<p>已保存的正文</p>', get_post($post->ID)->post_content);
        $run = $this->task(); Oyiso_Agent_Runner::message($run, '我当前在哪里', [], $page);
        self::assertSame($page, $this->tool($run, 'get-current-page'));
        self::assertSame('我当前在哪里', $run['conversation'][0]['text']);
        self::assertSame($page, $run['messages'][0]['oyiso_page_context']);
        self::assertSame($page, Oyiso_Agent_Page_Context::stored($page));
        self::assertNull(Oyiso_Agent_Page_Context::stored(['fields' => ['bad' => []]]));
    }

    public function testInvalidContextsAndRevokedAccessCannotReadEditorFields(): void
    {
        foreach (['{', '[]', str_repeat('x', 110001)] as $value) self::assertInstanceOf(WP_Error::class, Oyiso_Agent_Page_Context::parse($value));
        self::assertNull(Oyiso_Agent_Page_Context::parse(null));
        $post = $this->post(); $input = $this->context($post);
        $input['signature'] = 'wrong'; self::assertInstanceOf(WP_Error::class, Oyiso_Agent_Page_Context::parse(wp_json_encode($input)));
        $input = $this->context($post); $input['fields']['content'] = ['bad']; self::assertInstanceOf(WP_Error::class, Oyiso_Agent_Page_Context::parse(wp_json_encode($input)));
        $input = $this->context($post); $input['fields']['content'] = str_repeat('文', 20001);
        self::assertTrue(Oyiso_Agent_Page_Context::parse(wp_json_encode($input, JSON_UNESCAPED_UNICODE))['truncated']);
        $input = $this->context($post);
        update_option('oyiso', array_replace(Oyiso_Agent_Config::options(), ['agent_mcp_namespaces' => []]), false);
        $page = Oyiso_Agent_Page_Context::parse(wp_json_encode($input)); self::assertSame([], $page['fields']);
        $run = $this->task(); $run['page_context'] = $page;
        self::assertNotContains('oyiso/get-post', Oyiso_Agent_Abilities::allowed());
        self::assertSame([], $this->tool($run, 'get-current-page')['fields']);
        wp_set_current_user(0); self::assertInstanceOf(WP_Error::class, Oyiso_Agent_Page_Context::parse(wp_json_encode($input)));
    }

    public function testArticleAndPageChangesUseEditorContentAndSaveOnlyOnConfirmation(): void
    {
        foreach (['post', 'page'] as $type) {
            $post = $this->post($type); $run = $this->task();
            $run['page_context'] = Oyiso_Agent_Page_Context::parse(wp_json_encode($this->context($post, ['excerpt' => '未保存的摘要'])));
            $proposal = $this->prepare($run, $post);
            self::assertSame('未保存的摘要', $run['content_changes'][0]['before']['excerpt']);
            self::assertSame('<p>已保存的正文</p>', get_post($post->ID)->post_content);
            self::assertSame(200, $this->control($run, $proposal, 'apply')->get_status());
            self::assertSame('<p>新正文</p>', get_post($post->ID)->post_content);
            self::assertSame('未保存的摘要', get_post($post->ID)->post_excerpt);
            self::assertSame('draft', get_post_status($post->ID));
            self::assertSame(200, $this->control($run, $proposal, 'apply')->get_status());
            self::assertSame(200, $this->control($run, $proposal, 'revert')->get_status());
            self::assertSame('<p>已保存的正文</p>', get_post($post->ID)->post_content);
            self::assertSame('原摘要', get_post($post->ID)->post_excerpt);
        }
    }

    public function testContentTransactionErrorsNeverReportSuccessOrKeepPartialWrites(): void
    {
        global $wpdb;
        foreach (['START TRANSACTION', 'post lock', 'metadata lock', 'COMMIT'] as $failure) {
            $post = $this->post(); $run = $this->task(); $proposal = $this->prepare($run, $post);
            $before = Oyiso_Agent_Page_Context::fields($post);
            $filter = static function (string $query) use ($failure, $wpdb): string {
                $match = $query === $failure || str_contains($query, 'FOR UPDATE') && str_contains($query, $failure === 'post lock' ? $wpdb->posts : $wpdb->postmeta) && in_array($failure, ['post lock', 'metadata lock'], true);
                return $match ? 'SELECT oyiso_audit_missing_column' : $query;
            };
            $previous = $wpdb->suppress_errors(true); add_filter('query', $filter);
            try { $response = $this->control($run, $proposal, 'apply'); }
            finally { remove_filter('query', $filter); $wpdb->query('ROLLBACK'); $wpdb->suppress_errors($previous); clean_post_cache($post->ID); }
            self::assertSame(500, $response->get_status(), $failure);
            self::assertSame($before, Oyiso_Agent_Page_Context::fields(get_post($post->ID)), $failure);
            self::assertSame('pending', Oyiso_Agent_Store::get($run['id'])['content_changes'][0]['status']);
        }
    }

    public function testContentSaveVerifiesFieldsAndRollsBackSilentChanges(): void
    {
        $post = $this->post(); $run = $this->task(); $proposal = $this->prepare($run, $post);
        $before = Oyiso_Agent_Page_Context::fields($post);
        $filter = static function (array $data, array $input) use ($post): array {
            if ((int) ($input['ID'] ?? 0) === $post->ID) $data['post_excerpt'] = 'Unexpected fixture rewrite';
            return $data;
        };
        add_filter('wp_insert_post_data', $filter, 10, 2);
        try { $response = $this->control($run, $proposal, 'apply'); }
        finally { remove_filter('wp_insert_post_data', $filter, 10); }
        self::assertSame(500, $response->get_status());
        clean_post_cache($post->ID);
        self::assertSame($before, Oyiso_Agent_Page_Context::fields(get_post($post->ID)));
        self::assertSame('pending', Oyiso_Agent_Store::get($run['id'])['content_changes'][0]['status']);
    }

    public function testMissingDraftMarkerNeverCreatesAnUntrackedDraft(): void
    {
        $run = $this->task();
        $proposal = $this->tool($run, 'prepare-post-draft', ['fields' => ['title' => 'Oyiso security draft fixture', 'content' => '<p>Draft fixture</p>']]);
        $run['status'] = 'waiting'; Oyiso_Agent_Store::save($run);
        $filter = static fn(mixed $check, int $id, string $key): mixed => $key === '_oyiso_agent_draft' ? false : $check;
        add_filter('add_post_metadata', $filter, 10, 3);
        try { $response = $this->control($run, $proposal['proposal_id'], 'apply'); }
        finally { remove_filter('add_post_metadata', $filter, 10); }
        $created = get_posts(['post_type' => 'post', 'post_status' => 'draft', 'author' => OYISO_AGENT_TEST_ADMIN, 'title' => 'Oyiso security draft fixture', 'fields' => 'ids', 'numberposts' => -1]);
        $this->posts = array_merge($this->posts, $created);
        self::assertSame(500, $response->get_status());
        self::assertSame([], $created);
        self::assertSame('pending', Oyiso_Agent_Store::get($run['id'])['content_changes'][0]['status']);
    }

    public function testSavingRejectsManualChangesRevokedGrantsAndCancelledTasks(): void
    {
        $post = $this->post(); $run = $this->task(); $proposal = $this->prepare($run, $post);
        wp_update_post(['ID' => $post->ID, 'post_content' => '人工新编辑']);
        self::assertSame(409, $this->control($run, $proposal, 'apply')->get_status());
        self::assertSame('人工新编辑', get_post($post->ID)->post_content);
        update_option('oyiso', array_replace(Oyiso_Agent_Config::options(), ['agent_write_tools' => []]), false);
        self::assertSame(403, $this->control($run, $proposal, 'apply')->get_status());
        Oyiso_Agent_Store::cancel($run['id']); self::assertSame(409, $this->control($run, $proposal, 'apply')->get_status());
    }

    public function testDraftCreationIsExplicitIdempotentAndNeverPublishes(): void
    {
        $run = $this->task();
        $proposal = $this->tool($run, 'prepare-post-draft', ['post_type' => 'page', 'fields' => ['title' => '新页面草稿', 'content' => '<p>安全正文</p><script>alert(1)</script>']]);
        self::assertIsArray($proposal); self::assertSame(0, $run['content_changes'][0]['post_id']);
        $run['status'] = 'waiting'; Oyiso_Agent_Store::save($run);
        $response = $this->control($run, $proposal['proposal_id'], 'apply'); self::assertSame(200, $response->get_status());
        $change = $response->get_data()['content_changes'][0]; $this->posts[] = $change['post_id'];
        self::assertSame('draft', get_post_status($change['post_id']));
        self::assertStringNotContainsString('<script', get_post($change['post_id'])->post_content);
        Oyiso_Agent_Store::save($run); // Simulate a response lost after creating the draft.
        self::assertSame($change['post_id'], $this->control($run, $proposal['proposal_id'], 'apply')->get_data()['content_changes'][0]['post_id']);
        self::assertSame(409, $this->control($run, $proposal['proposal_id'], 'dismiss')->get_status());
        self::assertSame(409, $this->control($run, $proposal['proposal_id'], 'revert')->get_status());
        self::assertSame(400, $this->control($run, $proposal['proposal_id'], 'publish')->get_status());
    }

    public function testWritingFromAnEditorTargetsTheCurrentArticleOrPageWithoutCreatingAnotherPost(): void
    {
        foreach (['post', 'page'] as $type) foreach (['draft', 'auto-draft'] as $status) {
            $post = $this->post($type); $run = $this->task();
            if ($status === 'auto-draft') { wp_update_post(['ID' => $post->ID, 'post_status' => $status]); $post = get_post($post->ID); }
            $run['page_context'] = Oyiso_Agent_Page_Context::parse(wp_json_encode($this->context($post, ['content' => '<p>编辑器里未保存的正文</p>', 'excerpt' => '保留这份摘要'])));
            $counts = (array) wp_count_posts($type);
            $proposal = $this->tool($run, 'prepare-post-draft', ['fields' => ['title' => '新写作标题', 'content' => '<p>生成的文章正文</p>']]);
            self::assertIsArray($proposal);
            $change = $run['content_changes'][0];
            self::assertSame($post->ID, $change['post_id']); self::assertSame('update', $change['kind']);
            self::assertSame('保留这份摘要', $change['after']['excerpt']);
            self::assertSame('<p>编辑器里未保存的正文</p>', $change['before']['content']);
            $run['status'] = 'waiting'; Oyiso_Agent_Store::save($run);
            $payload = $this->control($run, $proposal['proposal_id'], 'editor'); self::assertSame(200, $payload->get_status());
            self::assertSame($post->ID, $payload->get_data()['post_id']);
            self::assertSame(['title' => '新写作标题', 'content' => '<p>生成的文章正文</p>'], $payload->get_data()['fields']);
            self::assertSame(200, $this->control($run, $proposal['proposal_id'], 'filled', ['signature' => $payload->get_data()['signature']])->get_status());
            self::assertSame($post->post_title, get_post($post->ID)->post_title);
            self::assertSame($post->post_content, get_post($post->ID)->post_content);
            self::assertSame($status, get_post_status($post->ID));
            self::assertSame($counts, (array) wp_count_posts($type));
        }
    }

    public function testExplicitSeparateDraftFromAnEditorStillRequiresItsOwnConfirmation(): void
    {
        $post = $this->post(); $run = $this->task();
        $run['page_context'] = Oyiso_Agent_Page_Context::parse(wp_json_encode($this->context($post)));
        $proposal = $this->tool($run, 'prepare-post-draft', ['destination' => 'new_draft', 'fields' => ['title' => '另外一篇文章', 'content' => '<p>新草稿正文</p>']]);
        self::assertIsArray($proposal); self::assertSame('draft', $run['content_changes'][0]['kind']); self::assertSame(0, $run['content_changes'][0]['post_id']);
        $run['status'] = 'waiting'; Oyiso_Agent_Store::save($run);
        $response = $this->control($run, $proposal['proposal_id'], 'apply'); self::assertSame(200, $response->get_status());
        $created = $response->get_data()['content_changes'][0]['post_id']; $this->posts[] = $created;
        self::assertNotSame($post->ID, $created); self::assertSame('draft', get_post_status($created));
        self::assertSame($post->post_content, get_post($post->ID)->post_content);
    }

    public function testWritingDestinationKeepsUpdateGrantsAndEditorGuards(): void
    {
        $post = $this->post(); $run = $this->task();
        $run['page_context'] = Oyiso_Agent_Page_Context::parse(wp_json_encode($this->context($post)));
        update_option('oyiso', array_replace(Oyiso_Agent_Config::options(), ['agent_write_tools' => ['oyiso/create-post-draft']]), false);
        self::assertSame('agent_write_disabled', $this->tool($run, 'prepare-post-draft', ['fields' => ['title' => '标题', 'content' => '正文']])->get_error_code());
        self::assertSame([], $run['content_changes'] ?? []);
        $run['page_context']['truncated'] = true;
        update_option('oyiso', array_replace(Oyiso_Agent_Config::options(), ['agent_write_tools' => ['oyiso/create-post-draft', 'oyiso/apply-post-update']]), false);
        self::assertSame('agent_context_truncated', $this->tool($run, 'prepare-post-draft', ['fields' => ['title' => '标题', 'content' => '正文']])->get_error_code());
        $run['page_context']['truncated'] = false; $run['page_context']['editor'] = 'unsupported';
        self::assertSame('agent_editor_unsupported', $this->tool($run, 'prepare-post-draft', ['fields' => ['title' => '标题', 'content' => '正文']])->get_error_code());
        unset($run['page_context']);
        self::assertSame('agent_editor_target', $this->tool($run, 'prepare-post-draft', ['destination' => 'current_editor', 'fields' => ['title' => '标题', 'content' => '正文']])->get_error_code());
    }

    public function testEditorFillDoesNotSaveAndRequiresAFreshGrantAndAcknowledgement(): void
    {
        $post = $this->post(); $run = $this->task(); $proposal = $this->prepare($run, $post);
        $payload = $this->control($run, $proposal, 'editor'); self::assertSame(200, $payload->get_status());
        self::assertSame(['content' => '<p>新正文</p>'], $payload->get_data()['fields']);
        self::assertSame('<p>已保存的正文</p>', get_post($post->ID)->post_content);
        self::assertSame(409, $this->control($run, $proposal, 'filled', ['signature' => 'wrong'])->get_status());
        $result = $this->control($run, $proposal, 'filled', ['signature' => $payload->get_data()['signature']]);
        self::assertSame('filled', $result->get_data()['content_changes'][0]['status']);
        self::assertSame('completed', $result->get_data()['status']);
        self::assertSame('<p>已保存的正文</p>', get_post($post->ID)->post_content);
        self::assertSame(200, $this->control($run, $proposal, 'editor')->get_status());
    }

    public function testFilledProposalsCanBeRecoveredAfterReloadWithoutWritingToTheSite(): void
    {
        foreach (['post', 'page', 'product'] as $type) {
            $post = $this->post($type); $run = $this->task();
            $product = $type === 'product'; $field = $product ? 'description' : 'content';
            $run['page_context'] = Oyiso_Agent_Page_Context::parse(wp_json_encode($this->context($post, [$field => '<p>之前未保存的内容</p>'])));
            self::assertIsArray($this->tool($run, $product ? 'get-product' : 'get-post', [$product ? 'product_id' : 'post_id' => $post->ID]));
            $proposal = $this->tool($run, $product ? 'prepare-product-update' : 'prepare-post-update', [$product ? 'product_id' : 'post_id' => $post->ID, 'fields' => [$field => '<p>需要恢复的 AI 内容</p>']]);
            self::assertIsArray($proposal);
            $run['status'] = 'waiting'; Oyiso_Agent_Store::save($run);
            $extra = $product ? ['kind' => 'product'] : [];
            $initial = $this->control($run, $proposal['proposal_id'], 'editor', $extra)->get_data();
            self::assertArrayNotHasKey('restore_from', $initial);
            self::assertSame(200, $this->control($run, $proposal['proposal_id'], 'filled', $extra + ['signature' => $initial['signature']])->get_status());
            $restored = $this->control($run, $proposal['proposal_id'], 'editor', $extra);
            self::assertSame(200, $restored->get_status());
            $payload = $restored->get_data();
            self::assertSame([$field => '<p>之前未保存的内容</p>'], $payload['expected']);
            self::assertSame([[$field => '<p>已保存的正文</p>'], [$field => '<p>需要恢复的 AI 内容</p>']], $payload['restore_from']);
            self::assertSame([$field => '<p>需要恢复的 AI 内容</p>'], $payload['fields']);
            self::assertSame(409, $this->control($run, $proposal['proposal_id'], 'filled', $extra + ['signature' => 'invalid'])->get_status());
            self::assertSame(200, $this->control($run, $proposal['proposal_id'], 'filled', $extra + ['signature' => $payload['signature']])->get_status());
            self::assertSame('<p>已保存的正文</p>', get_post($post->ID)->post_content);
            self::assertSame('原摘要', get_post($post->ID)->post_excerpt);
            $allowed = Oyiso_Agent_Config::options();
            update_option('oyiso', array_replace($allowed, ['agent_write_tools' => []]), false);
            self::assertSame(403, $this->control($run, $proposal['proposal_id'], 'editor', $extra)->get_status());
            self::assertSame(403, $this->control($run, $proposal['proposal_id'], 'filled', $extra + ['signature' => $payload['signature']])->get_status());
            update_option('oyiso', $allowed, false);
        }
    }

    public function testRecoveryRejectsDiscardedOrSavedProposalsAndCannotAcknowledgeADraftAsFilled(): void
    {
        $post = $this->post(); $run = $this->task(); $proposal = $this->prepare($run, $post);
        $payload = $this->control($run, $proposal, 'editor')->get_data();
        foreach (['superseded', 'dismissed', 'cancelled', 'applied', 'reverted'] as $status) {
            $run['content_changes'][0]['status'] = $status; Oyiso_Agent_Store::save($run);
            self::assertSame(409, $this->control($run, $proposal, 'editor')->get_status());
            self::assertSame(409, $this->control($run, $proposal, 'filled', ['signature' => $payload['signature']])->get_status());
        }
        $run['content_changes'][0]['kind'] = 'draft'; $run['content_changes'][0]['status'] = 'pending'; Oyiso_Agent_Store::save($run);
        self::assertSame(409, $this->control($run, $proposal, 'editor')->get_status());
        self::assertSame(409, $this->control($run, $proposal, 'filled', ['signature' => $payload['signature']])->get_status());
        self::assertSame('<p>已保存的正文</p>', get_post($post->ID)->post_content);
    }

    public function testFollowUpChangesReadTheLatestEditorInsteadOfThePreviousTurnSnapshot(): void
    {
        foreach (['post', 'page'] as $type) foreach ([false, true] as $saveFirst) {
            $post = $this->post($type); $run = $this->task();
            Oyiso_Agent_Runner::message($run, '优化当前内容', [], Oyiso_Agent_Page_Context::parse(wp_json_encode($this->context($post))));
            $first = ['content' => '<p>第一轮已填入的正文</p>', 'excerpt' => '第一轮摘要'];
            $proposal = $this->prepare($run, $post, $first);
            $payload = $this->control($run, $proposal, 'editor')->get_data();
            self::assertSame(200, $this->control($run, $proposal, 'filled', ['signature' => $payload['signature']])->get_status());
            $run = Oyiso_Agent_Store::get($run['id']);
            if ($saveFirst) wp_update_post(['ID' => $post->ID, 'post_content' => $first['content'], 'post_excerpt' => $first['excerpt']]);
            $context = Oyiso_Agent_Page_Context::parse(wp_json_encode($this->context($post, $first)));
            $context['dirty'] = !$saveFirst;
            Oyiso_Agent_Runner::message($run, '继续扩写刚才的内容', [], $context);
            self::assertArrayNotHasKey('post_snapshots', $run);
            self::assertSame('agent_content_not_read', $this->tool($run, 'prepare-post-update', ['post_id' => $post->ID, 'fields' => ['content' => '不能沿用第一轮读取结果']])->get_error_code());
            $proposal = $this->prepare($run, $post, ['content' => '<p>第二轮正文</p>', 'excerpt' => '第二轮摘要']);
            $change = $run['content_changes'][array_key_last($run['content_changes'])];
            self::assertCount(2, $run['content_changes']);
            self::assertSame('filled', $run['content_changes'][0]['status']);
            self::assertSame($first['content'], $change['before']['content']);
            self::assertSame($first['excerpt'], $change['before']['excerpt']);
            self::assertSame($saveFirst ? $first['content'] : $post->post_content, $change['saved']['content']);
            $payload = $this->control($run, $proposal, 'editor')->get_data();
            self::assertSame($context['fields'], $payload['expected']);
            self::assertSame(['content' => '<p>第二轮正文</p>', 'excerpt' => '第二轮摘要'], $payload['fields']);
        }
    }

    public function testRecoveryAlsoRestoresEarlierUnsavedFieldsKeptByTheLatestProposal(): void
    {
        $post = $this->post(); $run = $this->task();
        $run['page_context'] = Oyiso_Agent_Page_Context::parse(wp_json_encode($this->context($post, ['content' => '<p>前一版未保存的正文</p>', 'excerpt' => '前一版未保存的摘要'])));
        $proposal = $this->prepare($run, $post);
        $initial = $this->control($run, $proposal, 'editor')->get_data();
        self::assertSame(['content' => '<p>新正文</p>'], $initial['fields']);
        self::assertSame(200, $this->control($run, $proposal, 'filled', ['signature' => $initial['signature']])->get_status());
        $payload = $this->control($run, $proposal, 'editor')->get_data();
        self::assertSame(['content' => '<p>新正文</p>', 'excerpt' => '前一版未保存的摘要'], $payload['fields']);
        self::assertSame(['content' => '<p>已保存的正文</p>', 'excerpt' => '原摘要'], $payload['restore_from'][0]);
        self::assertSame('原摘要', get_post($post->ID)->post_excerpt);
    }

    public function testReadFirstValidationSearchAndTruncationGuards(): void
    {
        $post = $this->post(); $run = $this->task();
        self::assertInstanceOf(WP_Error::class, $this->tool($run, 'prepare-post-update', ['post_id' => $post->ID, 'fields' => ['title' => '新标题']]));
        self::assertContains($post->ID, array_column($this->tool($run, 'search-posts', ['search' => '页面上下文验证'])['items'], 'id'));
        $this->tool($run, 'get-post', ['post_id' => $post->ID]);
        foreach ([['post_status' => 'publish'], ['title' => str_repeat('a', 301)], ['content' => []]] as $fields) self::assertInstanceOf(WP_Error::class, $this->tool($run, 'prepare-post-update', ['post_id' => $post->ID, 'fields' => $fields]));
        $run['page_context'] = Oyiso_Agent_Page_Context::parse(wp_json_encode($this->context($post)));
        $run['page_context']['truncated'] = true;
        self::assertInstanceOf(WP_Error::class, $this->tool($run, 'prepare-post-update', ['post_id' => $post->ID, 'fields' => ['content' => '部分内容']]));
    }

    public function testProductFillKeepsTheUnsavedEditorBaselineAndNeverSavesTheProduct(): void
    {
        $post = $this->post('product'); $run = $this->task();
        $run['page_context'] = Oyiso_Agent_Page_Context::parse(wp_json_encode($this->context($post, ['description' => '<p>商品未保存的正文</p>'])));
        self::assertIsArray($this->tool($run, 'get-product', ['product_id' => $post->ID]));
        $proposal = $this->tool($run, 'prepare-product-update', ['product_id' => $post->ID, 'fields' => ['description' => '<p>基于新正文改写</p>']]);
        self::assertIsArray($proposal); $run['status'] = 'waiting'; Oyiso_Agent_Store::save($run);
        $payload = $this->control($run, $proposal['proposal_id'], 'editor', ['kind' => 'product']);
        self::assertSame(200, $payload->get_status());
        self::assertSame('<p>商品未保存的正文</p>', $payload->get_data()['expected']['description']);
        self::assertSame('<p>已保存的正文</p>', wc_get_product($post->ID)->get_description());
        $result = $this->control($run, $proposal['proposal_id'], 'filled', ['kind' => 'product', 'signature' => $payload->get_data()['signature']]);
        self::assertSame('filled', $result->get_data()['changes'][0]['status']);
        self::assertSame('<p>已保存的正文</p>', wc_get_product($post->ID)->get_description());
    }

    public function testSendingThroughRestValidatesAndAttachesTheContext(): void
    {
        $previous = get_option('oyiso_agent_connections', false);
        try {
            $connection = Oyiso_Agent_Connections::save(['name' => '页面上下文验证', 'preset' => 'custom', 'url' => 'https://api.example.com/v1', 'protocol' => 'openai_chat', 'models' => ['fixture-model'], 'key' => 'fixture-context-key']);
            self::assertIsArray($connection);
            $post = $this->post();
            $request = new WP_REST_Request('POST', '/oyiso-agent/v1/runs');
            foreach (['message' => '我当前在哪里', 'selection' => $connection['id'] . '|fixture-model', 'page_context' => wp_json_encode($this->context($post, ['title' => '最新未保存标题']))] as $key => $value) $request->set_param($key, $value);
            $response = rest_get_server()->dispatch($request); self::assertSame(201, $response->get_status());
            $this->runs[] = $response->get_data()['id'];
            self::assertSame('最新未保存标题', $response->get_data()['page_context']['fields']['title']);
            $request->set_param('page_context', '{}'); self::assertSame(400, rest_get_server()->dispatch($request)->get_status());
        } finally { if ($previous === false) delete_option('oyiso_agent_connections'); else update_option('oyiso_agent_connections', $previous, false); }
    }

    public function testWordPressToolSourcesCanBeDisabledAndOldFormsCannotReenableThem(): void
    {
        $post = $this->post(); $run = $this->task(); $proposal = $this->prepare($run, $post);
        $disabled = array_replace(Oyiso_Agent_Config::options(), ['agent_mcp_namespaces' => []]); update_option('oyiso', $disabled, false);
        self::assertSame(403, $this->control($run, $proposal, 'apply')->get_status());
        self::assertSame(403, $this->control($run, $proposal, 'editor')->get_status());
        $legacy = array_replace($disabled, ['agent_plugin_tools_version' => '1']);
        self::assertSame([], Oyiso_Agent_Config::saveOptions($legacy)['agent_mcp_namespaces']);
        self::assertSame(200, $this->control($run, $proposal, 'dismiss')->get_status());
        self::assertSame('dismissed', Oyiso_Agent_Store::get($run['id'])['content_changes'][0]['status']);
    }

    public function testOnlyTheLatestPageContextReachesTheModel(): void
    {
        $post = $this->post(); $page = Oyiso_Agent_Page_Context::parse(wp_json_encode($this->context($post)));
        $run = $this->task(); Oyiso_Agent_Runner::message($run, '第一条', [], $page);
        $page['label'] = '最新页面'; Oyiso_Agent_Runner::message($run, '当前在哪', [], $page);
        $received = [];
        $builder = $this->getMockBuilder(WP_AI_Client_Prompt_Builder::class)->disableOriginalConstructor()->onlyMethods(['__call', 'using_abilities'])->getMock();
        $builder->method('using_abilities')->willReturnSelf();
        $builder->method('__call')->willReturnCallback(static fn(string $name): mixed => $name === 'is_supported_for_text_generation' ? false : $builder);
        $model = new Oyiso_Agent_Model(static function (array $messages) use (&$received, $builder) { $received = $messages; return $builder; });
        $model->generate($run['messages'], [], '');
        self::assertCount(1, $received[0]->getParts()); self::assertCount(2, $received[1]->getParts());
        self::assertStringContainsString('最新页面', $received[1]->getParts()[1]->getText());
        self::assertStringContainsString('未保存', $received[1]->getParts()[1]->getText());
    }
}
