<?php

declare(strict_types=1);

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
    http_response_code(404);
    exit;
}

use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use WordPress\AiClient\Tools\DTO\FunctionCall;

final class OyisoAgentScriptedModel implements Oyiso_Agent_Model_Gateway
{
    public int $calls = 0;
    public array $allowed = [];
    public array $selections = [];

    public function __construct(private array $turns)
    {
    }

    public function generate(array $history, array $abilities, ?string $selection = null, ?Closure $onText = null): Oyiso_Agent_Turn|WP_Error
    {
        $this->calls++;
        $this->selections[] = $selection;
        $this->allowed = $abilities;
        $turn = array_shift($this->turns);
        return $turn instanceof Closure ? $turn($onText) : $turn;
    }
}

final class AgentTest extends TestCase
{
    private array $options;
    private mixed $secrets;
    private mixed $connections;
    private array $tasks = [];
    private array $products = [];
    private array $users = [];

    protected function setUp(): void
    {
        wp_set_current_user(OYISO_AGENT_TEST_ADMIN);
        $this->options = Oyiso_Agent_Config::options();
        $this->secrets = get_option('oyiso_agent_secrets', false);
        $this->connections = get_option('oyiso_agent_connections', false);
        delete_option('oyiso_agent_connections');
        $options = $this->options;
        unset($options['agent_selection'], $options['agent_advanced']);
        update_option('oyiso', array_merge($options, ['agent_enabled' => true, 'agent_write_tools' => ['oyiso/apply-product-update', 'oyiso/revert-product-update', 'oyiso/apply-product-trash', 'oyiso/revert-product-trash'], 'agent_max_steps' => '8', 'agent_provider' => '', 'agent_model' => '']), false);
    }

    protected function tearDown(): void
    {
        Oyiso_Agent_Abilities::bind(null);
        wp_set_current_user(OYISO_AGENT_TEST_ADMIN);
        foreach ($this->tasks as $id) {
            wp_clear_scheduled_hook(Oyiso_Agent_Runner::HOOK, [$id]);
            as_unschedule_all_actions(Oyiso_Agent_Runner::HOOK, [$id], 'oyiso-agent');
            Oyiso_Agent_Store::unlock('run_' . $id);
            delete_option('oyiso_agent_lock_run_' . $id);
            wp_delete_post($id, true);
        }
        foreach ($this->products as $id) {
            delete_option('oyiso_agent_lock_product_' . $id);
            $product = wc_get_product($id);
            if ($product instanceof WC_Product) $product->delete(true);
        }
        foreach ($this->users as $id) wp_delete_user($id);
        update_option('oyiso', $this->options, false);
        if ($this->secrets === false) delete_option('oyiso_agent_secrets');
        else update_option('oyiso_agent_secrets', $this->secrets, false);
        if ($this->connections === false) delete_option('oyiso_agent_connections');
        else update_option('oyiso_agent_connections', $this->connections, false);
    }

    private function task(): array
    {
        $run = Oyiso_Agent_Store::create('Oyiso Agent integration fixture');
        self::assertIsArray($run);
        $this->tasks[] = $run['id'];
        return $run;
    }

    private function product(): WC_Product
    {
        $product = new WC_Product_Simple();
        $product->set_name('Oyiso Agent fixture ' . wp_generate_uuid4());
        $product->set_status('draft');
        $product->set_regular_price('19.90');
        $product->set_description('<p>Original manufacturer\'s description.</p>');
        $product->set_short_description('Original short description');
        $product->save();
        $this->products[] = $product->get_id();
        return $product;
    }

    private function tool(array &$run, string $name, array $args = [], string $approval = ''): mixed
    {
        Oyiso_Agent_Abilities::bind(new Oyiso_Agent_Tools($run, $approval));
        return wp_get_ability('oyiso/' . $name)->execute($args);
    }

    private function proposal(array &$run, WC_Product $product, array $fields = ['description' => '<p>New manufacturer\'s description.</p>', 'seo_title' => 'New SEO title']): string
    {
        self::assertIsArray($this->tool($run, 'get-product', ['product_id' => $product->get_id()]));
        $proposal = $this->tool($run, 'prepare-product-update', ['product_id' => $product->get_id(), 'fields' => $fields]);
        self::assertIsArray($proposal);
        Oyiso_Agent_Store::save($run);
        return $proposal['proposal_id'];
    }

    private function turn(string $text = '', array $calls = []): Oyiso_Agent_Turn
    {
        $parts = $text !== '' ? [new MessagePart($text)] : [];
        foreach ($calls as [$name, $args]) {
            $parts[] = new MessagePart(new FunctionCall(wp_generate_uuid4(), WP_AI_Client_Ability_Function_Resolver::ability_name_to_function_name('oyiso/' . $name), $args));
        }
        return new Oyiso_Agent_Turn(new ModelMessage($parts ?: [new MessagePart('')]), 40, 'fixture-model');
    }

    private function request(string $method, string $route, array $params = []): WP_REST_Response
    {
        $request = new WP_REST_Request($method, '/oyiso-agent/v1/' . $route);
        foreach ($params as $name => $value) $request->set_param($name, $value);
        return rest_get_server()->dispatch($request);
    }

    public function testExecutionRecordsPersistPerUserTurnWithSeparateUsageAndEvents(): void
    {
        $run = $this->task();
        Oyiso_Agent_Runner::message($run, 'First request');
        $runner = new Oyiso_Agent_Runner(new OyisoAgentScriptedModel([$this->turn('', [['get-current-page', []]]), $this->turn('First answer'), $this->turn('Second answer')]));
        $runner->step($run['id']); $runner->step($run['id']);
        $run = Oyiso_Agent_Store::get($run['id']); self::assertIsArray($run);
        $first = $run['executions'][0];
        self::assertSame(0, $first['index']); self::assertSame('completed', $first['status']);
        self::assertSame(2, $first['step']); self::assertSame(80, $first['tokens']);
        self::assertContains('调用工具', array_column($first['events'], 'label'));
        Oyiso_Agent_Runner::message($run, 'Second request'); $runner->step($run['id']);
        $fresh = Oyiso_Agent_Store::get($run['id']); self::assertIsArray($fresh);
        self::assertCount(2, $fresh['executions']); self::assertSame($first, $fresh['executions'][0]);
        self::assertSame(2, $fresh['executions'][1]['index']); self::assertSame(1, $fresh['executions'][1]['step']);
        self::assertSame(40, $fresh['executions'][1]['tokens']); self::assertSame(120, $fresh['tokens']);
        self::assertNotContains('调用工具', array_column($fresh['executions'][1]['events'], 'label'));
        $response = $this->request('GET', 'runs/' . $run['id']); self::assertSame(200, $response->get_status());
        $view = $response->get_data();
        self::assertSame([0, 2], array_column($view['executions'], 'index'));
        foreach ($view['executions'] as $execution) self::assertArrayNotHasKey('token_start', $execution);
        self::assertArrayNotHasKey('messages', $view);
    }

    public function testFailedAndCancelledExecutionDoNotChangePreviousCompletedRecords(): void
    {
        $run = $this->task(); Oyiso_Agent_Runner::message($run, 'First request');
        $runner = new Oyiso_Agent_Runner(new OyisoAgentScriptedModel([$this->turn('First answer'), new WP_Error('fixture_failure', 'Fixture model failed')]));
        $runner->step($run['id']); $run = Oyiso_Agent_Store::get($run['id']);
        $first = $run['executions'][0];
        Oyiso_Agent_Runner::message($run, 'Failed request'); $runner->step($run['id']);
        $fresh = Oyiso_Agent_Store::get($run['id']);
        self::assertSame($first, $fresh['executions'][0]); self::assertSame('failed', $fresh['executions'][1]['status']);
        self::assertSame(0, $fresh['executions'][1]['tokens']);
        self::assertContains('Fixture model failed', array_column($fresh['executions'][1]['events'], 'detail'));
        Oyiso_Agent_Store::cancel($run['id']);
        $cancelled = Oyiso_Agent_Store::view(Oyiso_Agent_Store::get($run['id']));
        self::assertSame('completed', $cancelled['executions'][0]['status']); self::assertSame('cancelled', $cancelled['executions'][1]['status']);
    }

    public function testLegacyEventsRemainOneHonestHistoryRecordWhenContinuingAConversation(): void
    {
        $run = $this->task();
        $run['conversation'] = [['role' => 'user', 'text' => 'Old first'], ['role' => 'assistant', 'text' => 'Old answer'], ['role' => 'user', 'text' => 'Old second'], ['role' => 'assistant', 'text' => 'Another answer']];
        $run['status'] = 'completed'; $run['step'] = 2; $run['tokens'] = 90;
        Oyiso_Agent_Store::event($run, 'info', 'Legacy combined event'); Oyiso_Agent_Store::save($run);
        $view = Oyiso_Agent_Store::view($run);
        self::assertCount(1, $view['executions']); self::assertTrue($view['executions'][0]['legacy']); self::assertSame(2, $view['executions'][0]['index']);
        self::assertArrayNotHasKey('executions', $run);
        $run['executions'] = [];
        self::assertCount(1, Oyiso_Agent_Store::view($run)['executions']);
        Oyiso_Agent_Runner::message($run, 'New request');
        $fresh = Oyiso_Agent_Store::get($run['id']);
        self::assertSame([2, 4], array_column($fresh['executions'], 'index'));
        self::assertSame('completed', $fresh['executions'][0]['status']); self::assertSame(90, $fresh['executions'][0]['tokens']);
        self::assertSame('queued', $fresh['executions'][1]['status']); self::assertSame(0, $fresh['executions'][1]['tokens']);
        self::assertSame(['Legacy combined event'], array_column($fresh['executions'][0]['events'], 'label'));
        self::assertSame(['已接收任务'], array_column($fresh['executions'][1]['events'], 'label'));
    }

    public function testExecutionHistoryBoundsLongEventsWithoutOverwritingOtherTurns(): void
    {
        $run = $this->task(); Oyiso_Agent_Runner::message($run, 'Bounded execution');
        for ($i = 1; $i <= 120; $i++) Oyiso_Agent_Store::event($run, 'info', 'Event ' . $i . str_repeat('L', 180), str_repeat('d', 900));
        Oyiso_Agent_Store::save($run); $fresh = Oyiso_Agent_Store::get($run['id']);
        self::assertCount(100, $fresh['events']); self::assertCount(50, $fresh['executions'][0]['events']);
        self::assertTrue($fresh['executions'][0]['truncated']);
        self::assertStringStartsWith('Event 71', $fresh['executions'][0]['events'][0]['label']);
        self::assertSame(150, mb_strlen($fresh['executions'][0]['events'][0]['label']));
        self::assertSame(300, mb_strlen($fresh['executions'][0]['events'][0]['detail']));
        self::assertSame(900, mb_strlen($fresh['events'][0]['detail']));
        Oyiso_Agent_Runner::message($fresh, 'Next request');
        self::assertCount(50, $fresh['executions'][0]['events']); self::assertCount(1, $fresh['executions'][1]['events']);
    }

    public function testAbilitiesAreRegisteredAndWriteAbilitiesAreNotModelTools(): void
    {
        foreach (['get-product', 'search-products', 'order-summary', 'prepare-product-update', 'apply-product-update', 'revert-product-update'] as $name) {
            $ability = wp_get_ability('oyiso/' . $name);
            self::assertInstanceOf(WP_Ability::class, $ability);
            self::assertFalse($ability->get_meta()['show_in_rest']);
        }
        self::assertNotContains('oyiso/apply-product-update', Oyiso_Agent_Abilities::allowed());
        self::assertNotContains('oyiso/revert-product-update', Oyiso_Agent_Abilities::allowed());
        self::assertNotContains('oyiso/search-web', Oyiso_Agent_Abilities::allowed());
        self::assertTrue(Oyiso_Agent_Config::supported());
    }

    public function testSchemaAndTaskContextAreEnforced(): void
    {
        $run = $this->task();
        self::assertWPError(wp_get_ability('oyiso/get-product')->execute(['product_id' => 1]));
        self::assertWPError($this->tool($run, 'get-product', ['product_id' => -1]));
        self::assertWPError($this->tool($run, 'get-product', ['product_id' => 1, 'unexpected' => true]));
        self::assertWPError($this->tool($run, 'apply-product-update', ['proposal_id' => 'unknown']));
    }

    public function testProductReadAndPaginatedSearchReturnRealFields(): void
    {
        $run = $this->task(); $product = $this->product();
        update_post_meta($product->get_id(), '_seopress_titles_title', 'Current SEO');
        $result = $this->tool($run, 'get-product', ['product_id' => $product->get_id()]);
        self::assertSame('19.90', $result['price']);
        self::assertSame('Current SEO', $result['fields']['seo_title']);
        self::assertSame($product->get_description(), $run['snapshots'][$product->get_id()]['description']);
        $search = $this->tool($run, 'search-products', ['search' => $product->get_name(), 'page' => 1]);
        self::assertSame(1, $search['total']);
        self::assertSame($product->get_id(), $search['products'][0]['id']);
        self::assertContains('seo_description', $search['products'][0]['missing_fields']);
        self::assertWPError($this->tool($run, 'get-product', ['product_id' => 999999999]));
    }

    public function testPrepareDoesNotWriteAndApplyPreservesUnrelatedProductData(): void
    {
        $run = $this->task(); $product = $this->product();
        $before = Oyiso_Agent_Tools::fields($product);
        $id = $this->proposal($run, $product);
        self::assertSame($before, Oyiso_Agent_Tools::fields(wc_get_product($product->get_id())));
        self::assertWPError($this->tool($run, 'apply-product-update', ['proposal_id' => $id]));
        $result = $this->tool($run, 'apply-product-update', ['proposal_id' => $id], 'apply');
        self::assertSame('applied', $result['status']);
        $fresh = wc_get_product($product->get_id());
        self::assertSame('<p>New manufacturer\'s description.</p>', $fresh->get_description());
        self::assertSame($product->get_name(), $fresh->get_name());
        self::assertSame('19.90', $fresh->get_regular_price());
        self::assertSame('New SEO title', get_post_meta($product->get_id(), '_seopress_titles_title', true));
        self::assertSame($result, $this->tool($run, 'apply-product-update', ['proposal_id' => $id], 'apply'));
        self::assertSame('reverted', $this->tool($run, 'revert-product-update', ['proposal_id' => $id], 'revert')['status']);
        self::assertSame($before, Oyiso_Agent_Tools::fields(wc_get_product($product->get_id())));
        self::assertSame('reverted', $this->tool($run, 'revert-product-update', ['proposal_id' => $id], 'revert')['status']);
    }

    public function testApplyAndRevertRejectInterveningManualChanges(): void
    {
        $run = $this->task(); $product = $this->product();
        $id = $this->proposal($run, $product);
        $product->set_short_description('Manual edit'); $product->save();
        self::assertWPError($this->tool($run, 'apply-product-update', ['proposal_id' => $id], 'apply'));
        self::assertSame('Manual edit', wc_get_product($product->get_id())->get_short_description());
        $id = $this->proposal($run, wc_get_product($product->get_id()));
        self::assertIsArray($this->tool($run, 'apply-product-update', ['proposal_id' => $id], 'apply'));
        update_post_meta($product->get_id(), '_seopress_titles_desc', 'Later manual SEO edit');
        self::assertWPError($this->tool($run, 'revert-product-update', ['proposal_id' => $id], 'revert'));
    }

    public function testProposalRequiresReadAndSanitizesContent(): void
    {
        $run = $this->task(); $product = $this->product();
        $args = ['product_id' => $product->get_id(), 'fields' => ['description' => '<p>Text</p><script>alert(1)</script>']];
        self::assertWPError($this->tool($run, 'prepare-product-update', $args));
        $this->tool($run, 'get-product', ['product_id' => $product->get_id()]);
        self::assertIsArray($this->tool($run, 'prepare-product-update', $args));
        self::assertStringNotContainsString('<script', $run['changes'][0]['after']['description']);
        self::assertWPError($this->tool($run, 'prepare-product-update', ['product_id' => $product->get_id(), 'fields' => ['price' => '0']]));
        self::assertWPError($this->tool($run, 'prepare-product-update', ['product_id' => $product->get_id(), 'fields' => ['seo_title' => str_repeat('x', 301)]]));
    }

    public function testReplacementProposalSupersedesPendingAndStaleReadIsRejected(): void
    {
        $run = $this->task(); $product = $this->product();
        $this->proposal($run, $product);
        $this->proposal($run, $product, ['short_description' => 'Replacement']);
        self::assertSame('superseded', $run['changes'][0]['status']);
        $product->set_description('Changed during review'); $product->save();
        self::assertWPError($this->tool($run, 'prepare-product-update', ['product_id' => $product->get_id(), 'fields' => ['short_description' => 'Outdated proposal']]));
    }

    public function testProductLockBlocksConcurrentApply(): void
    {
        $run = $this->task(); $product = $this->product(); $id = $this->proposal($run, $product);
        self::assertTrue(Oyiso_Agent_Store::lock('product_' . $product->get_id()));
        self::assertWPError($this->tool($run, 'apply-product-update', ['proposal_id' => $id], 'apply'));
        Oyiso_Agent_Store::unlock('product_' . $product->get_id());
        self::assertIsArray($this->tool($run, 'apply-product-update', ['proposal_id' => $id], 'apply'));
    }

    public function testRestPermissionsAndOwnership(): void
    {
        $run = $this->task();
        $user = wp_insert_user(['user_login' => 'agent-fixture-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'administrator']);
        $this->users[] = $user;
        wp_set_current_user($user);
        self::assertSame(404, $this->request('GET', 'runs/' . $run['id'])->get_status());
        self::assertSame(404, $this->request('POST', 'runs/' . $run['id'] . '/cancel')->get_status());
        self::assertSame(404, $this->request('POST', 'runs', ['message' => 'No access', 'run_id' => $run['id']])->get_status());
        wp_set_current_user(0);
        self::assertSame(401, $this->request('GET', 'runs')->get_status());
        self::assertSame(401, $this->request('POST', 'runs', ['message' => 'No access'])->get_status());
    }

    public function testRestCreatesPrivateTaskAndCancellationPersists(): void
    {
        $connection = Oyiso_Agent_Connections::save(['name' => '任务连接', 'preset' => 'custom', 'url' => 'https://api.example.com/v1', 'protocol' => 'openai_responses', 'models' => ['fixture-model'], 'key' => 'fixture-task-key']);
        self::assertIsArray($connection);
        $response = $this->request('POST', 'runs', ['message' => 'Read a product', 'selection' => $connection['id'] . '|fixture-model']);
        self::assertSame(201, $response->get_status());
        $id = $response->get_data()['id']; $this->tasks[] = $id;
        self::assertSame('private', get_post_status($id));
        self::assertSame(409, $this->request('POST', 'runs', ['message' => 'Duplicate', 'run_id' => $id])->get_status());
        self::assertSame('cancelled', $this->request('POST', 'runs/' . $id . '/cancel')->get_data()['status']);
        $stale = Oyiso_Agent_Store::get($id); $stale['status'] = 'completed'; Oyiso_Agent_Store::save($stale);
        self::assertSame('cancelled', Oyiso_Agent_Store::get($id)['status']);
        self::assertSame(409, $this->request('POST', 'runs', ['message' => 'Continue cancelled', 'run_id' => $id])->get_status());
        $list = $this->request('GET', 'runs')->get_data();
        self::assertContains($id, array_column($list['items'], 'id'));
    }

    public function testIntegrationTasksDoNotAppearInTheRealAdministratorsHistory(): void
    {
        $account = get_userdata(OYISO_AGENT_TEST_ADMIN);
        self::assertInstanceOf(WP_User::class, $account);
        self::assertStringStartsWith('oyiso-agent-test-', $account->user_login);
        $run = $this->task();
        $admins = get_users(['role' => 'administrator', 'exclude' => [OYISO_AGENT_TEST_ADMIN], 'number' => 1, 'fields' => 'ID']);
        self::assertNotEmpty($admins);
        wp_set_current_user((int) $admins[0]);
        try {
            self::assertNotContains($run['id'], array_column($this->request('GET', 'runs')->get_data()['items'], 'id'));
            self::assertSame(404, $this->request('GET', 'runs/' . $run['id'])->get_status());
        } finally { wp_set_current_user(OYISO_AGENT_TEST_ADMIN); }
    }

    public function testConversationResponsesAreNotCacheable(): void
    {
        $run = $this->task();
        foreach (['runs', 'runs/' . $run['id']] as $route) {
            self::assertSame('no-store, private', $this->request('GET', $route)->get_headers()['Cache-Control'] ?? null);
        }
    }

    public function testResponseModelLabelsKeepTheirOriginalGroupAfterSwitchingOrDeletingConnections(): void
    {
        $input = ['name' => 'DeepSeek', 'preset' => 'custom', 'url' => 'https://api.example.com/v1', 'protocol' => 'openai_chat', 'models' => ['fixture-model'], 'key' => 'fixture-label-key'];
        $first = Oyiso_Agent_Connections::save($input); $second = Oyiso_Agent_Connections::save(array_replace($input, ['name' => 'Other provider']));
        self::assertIsArray($first); self::assertIsArray($second);
        self::assertSame('fixture-model', Oyiso_Agent_Connections::label('', 'fixture-model'), 'An ambiguous legacy model must not be assigned to an arbitrary group.');
        $run = $this->task(); $run['selection'] = $first['id'] . '|fixture-model';
        Oyiso_Agent_Runner::message($run, 'First request');
        self::assertSame('DeepSeek / fixture-model', $run['executions'][0]['model_label']);
        $runner = new Oyiso_Agent_Runner(new OyisoAgentScriptedModel([
            function (?Closure $onText) use ($run): Oyiso_Agent_Turn {
                $onText?->__invoke('First response');
                self::assertSame('DeepSeek / fixture-model', Oyiso_Agent_Store::get($run['id'])['partial']['model_label']);
                $connections = get_option('oyiso_agent_connections'); $connections[0]['name'] = 'Renamed during reply';
                update_option('oyiso_agent_connections', $connections, false);
                return $this->turn('First response');
            },
            $this->turn('Second response'),
        ]));
        $runner->step($run['id'], static function (string $text, int $step, string $model): void {});
        $run = Oyiso_Agent_Store::get($run['id']);
        self::assertSame('DeepSeek / fixture-model', $run['conversation'][1]['model_label']);
        $run['selection'] = $second['id'] . '|fixture-model'; Oyiso_Agent_Runner::message($run, 'Second request');
        self::assertSame('Other provider / fixture-model', $run['executions'][1]['model_label']);
        $runner->step($run['id']); $run = Oyiso_Agent_Store::get($run['id']);
        delete_option('oyiso_agent_connections');
        $view = Oyiso_Agent_Store::view($run);
        self::assertSame('DeepSeek / fixture-model', $view['conversation'][1]['model_label']);
        self::assertSame('Other provider / fixture-model', $view['conversation'][3]['model_label']);
        self::assertStringNotContainsString('fixture-label-key', wp_json_encode($view));
    }

    public function testConversationModelsAreValidatedStoredAndPassedToTheWorkerIndependently(): void
    {
        $input = ['name' => '对话模型', 'preset' => 'custom', 'url' => 'https://api.example.com/v1', 'protocol' => 'openai_responses', 'models' => ['first-model', 'second-model'], 'key' => 'fixture-conversation-key'];
        $connection = Oyiso_Agent_Connections::save($input); self::assertIsArray($connection);
        self::assertIsArray(Oyiso_Agent_Connections::save(array_replace($input, ['name' => '没有密钥', 'key' => ''])));
        self::assertIsArray(Oyiso_Agent_Connections::save(array_replace($input, ['name' => '没有模型', 'models' => []])));
        $first = $connection['id'] . '|first-model'; $second = $connection['id'] . '|second-model';
        $models = $this->request('GET', 'status')->get_data()['models'];
        self::assertSame([$first, $second], array_keys($models));
        self::assertStringNotContainsString('fixture-conversation-key', wp_json_encode($models));
        $before = $this->request('GET', 'runs')->get_data();
        foreach ([null, 'wp:external', 'missing|model', $connection['id'] . '|unlisted'] as $selection) {
            $payload = ['message' => 'Invalid selection']; if ($selection !== null) $payload['selection'] = $selection;
            self::assertSame(400, $this->request('POST', 'runs', $payload)->get_status());
        }
        self::assertSame($before, $this->request('GET', 'runs')->get_data());
        $options = Oyiso_Agent_Config::options(); $options['agent_selection'] = 'obsolete|global-default'; update_option('oyiso', $options, false);
        $created = $this->request('POST', 'runs', ['message' => 'First conversation', 'selection' => $first]);
        self::assertSame(201, $created->get_status()); $id = $created->get_data()['id']; $this->tasks[] = $id;
        self::assertSame($first, $created->get_data()['selection']);
        $other = $this->request('POST', 'runs', ['message' => 'Other conversation', 'selection' => $second]);
        self::assertSame(201, $other->get_status()); $otherId = $other->get_data()['id']; $this->tasks[] = $otherId;
        self::assertSame(409, $this->request('POST', 'runs', ['message' => 'Cannot change while queued', 'run_id' => $id, 'selection' => $second])->get_status());
        self::assertSame($first, Oyiso_Agent_Store::get($id)['selection']);
        $model = new OyisoAgentScriptedModel([$this->turn('First answer'), $this->turn('Second answer')]);
        $runner = new Oyiso_Agent_Runner($model); $runner->step($id); $runner->step($otherId);
        self::assertSame([$first, $second], $model->selections);
        self::assertSame($first, $this->request('GET', 'runs/' . $id)->get_data()['selection']);
        self::assertSame(201, $this->request('POST', 'runs', ['message' => 'Switch for follow-up', 'run_id' => $id, 'selection' => $second])->get_status());
        self::assertSame($second, Oyiso_Agent_Store::get($id)['selection']);
        self::assertSame('obsolete|global-default', Oyiso_Agent_Config::text('selection'));
        $next = Oyiso_Agent_Store::get($id); $next['status'] = 'completed'; Oyiso_Agent_Store::save($next);
        self::assertSame(400, $this->request('POST', 'runs', ['message' => 'Invalid follow-up', 'run_id' => $id, 'selection' => 'missing|model'])->get_status());
        self::assertSame($second, Oyiso_Agent_Store::get($id)['selection']);
        self::assertCount(3, Oyiso_Agent_Store::get($id)['conversation']);
    }

    public function testRestAppliesAndRevertsOnlyAfterTaskStops(): void
    {
        $run = $this->task(); $product = $this->product(); $proposal = $this->proposal($run, $product);
        self::assertSame(409, $this->request('POST', 'runs/' . $run['id'] . '/changes/' . $proposal, ['action' => 'apply'])->get_status());
        $run['status'] = 'waiting'; Oyiso_Agent_Store::save($run);
        $route = 'runs/' . $run['id'] . '/changes/' . $proposal;
        self::assertSame('applied', $this->request('POST', $route, ['action' => 'apply'])->get_data()['changes'][0]['status']);
        self::assertSame('reverted', $this->request('POST', $route, ['action' => 'revert'])->get_data()['changes'][0]['status']);
        self::assertSame(400, $this->request('POST', $route, ['action' => 'delete'])->get_status());
    }

    public function testRunnerCallsOnlyAllowedAbilitiesAndFinishes(): void
    {
        $run = $this->task(); $product = $this->product();
        Oyiso_Agent_Runner::message($run, 'Read product');
        $gateway = new OyisoAgentScriptedModel([$this->turn('', [['get-current-page', []], ['get-product', ['product_id' => $product->get_id()]]]), $this->turn('Read complete')]);
        $runner = new Oyiso_Agent_Runner($gateway);
        $runner->step($run['id']);
        self::assertSame('queued', Oyiso_Agent_Store::get($run['id'])['status']);
        $messages = Oyiso_Agent_Store::get($run['id'])['messages'];
        self::assertCount(4, $messages);
        foreach (array_slice($messages, 2) as $message) { self::assertCount(1, Message::fromArray($message)->getParts()); self::assertNotNull(Message::fromArray($message)->getParts()[0]->getFunctionResponse()); }
        $runner->step($run['id']);
        $fresh = Oyiso_Agent_Store::get($run['id']);
        self::assertSame('completed', $fresh['status']);
        self::assertSame(80, $fresh['tokens']);
        self::assertSame(2, $fresh['step']);
        self::assertNotContains('oyiso/apply-product-update', $gateway->allowed);
        self::assertArrayNotHasKey('messages', Oyiso_Agent_Store::view($fresh));
    }

    public function testRunnerStopsAtProposalAndNeverSavesProduct(): void
    {
        $run = $this->task(); $product = $this->product(); $original = $product->get_description();
        Oyiso_Agent_Runner::message($run, 'Improve product');
        $runner = new Oyiso_Agent_Runner(new OyisoAgentScriptedModel([$this->turn('', [['get-product', ['product_id' => $product->get_id()]], ['prepare-product-update', ['product_id' => $product->get_id(), 'fields' => ['description' => 'Draft proposal']]]])]));
        $runner->step($run['id']);
        $fresh = Oyiso_Agent_Store::get($run['id']);
        self::assertSame('waiting', $fresh['status']);
        self::assertCount(1, $fresh['changes']);
        self::assertSame($original, wc_get_product($product->get_id())->get_description());
    }

    public function testRunnerRejectsUnlistedAndDirectWriteCalls(): void
    {
        $run = $this->task(); Oyiso_Agent_Runner::message($run, 'Try direct write');
        $runner = new Oyiso_Agent_Runner(new OyisoAgentScriptedModel([$this->turn('', [['apply-product-update', ['proposal_id' => 'fake']], ['unknown', []]])]));
        $runner->step($run['id']);
        $fresh = Oyiso_Agent_Store::get($run['id']);
        self::assertSame('queued', $fresh['status']);
        self::assertStringContainsString('不在允许范围', wp_json_encode($fresh['messages'], JSON_UNESCAPED_UNICODE));
    }

    public function testCancellationDuringModelCallPreventsTools(): void
    {
        $run = $this->task(); $product = $this->product();
        Oyiso_Agent_Runner::message($run, 'Cancelled task');
        $runner = new Oyiso_Agent_Runner(new OyisoAgentScriptedModel([function () use ($run, $product) {
            Oyiso_Agent_Store::cancel($run['id']);
            return $this->turn('', [['get-product', ['product_id' => $product->get_id()]]]);
        }]));
        $runner->step($run['id']);
        $fresh = Oyiso_Agent_Store::get($run['id']);
        self::assertSame('cancelled', $fresh['status']); self::assertSame([], $fresh['snapshots']);
    }

    public function testLimitsErrorsAndDisabledAgentStopExecution(): void
    {
        $run = $this->task(); Oyiso_Agent_Runner::message($run, 'Budget limited');
        $run['step'] = 8; Oyiso_Agent_Store::save($run);
        $gateway = new OyisoAgentScriptedModel([]);
        (new Oyiso_Agent_Runner($gateway))->step($run['id']);
        self::assertSame(0, $gateway->calls); self::assertSame('failed', Oyiso_Agent_Store::get($run['id'])['status']);
        $run = $this->task(); Oyiso_Agent_Runner::message($run, 'Provider error');
        (new Oyiso_Agent_Runner(new OyisoAgentScriptedModel([new WP_Error('fixture', 'Provider unavailable')])))->step($run['id']);
        self::assertSame('Provider unavailable', Oyiso_Agent_Store::get($run['id'])['error']);
        update_option('oyiso', array_merge(Oyiso_Agent_Config::options(), ['agent_enabled' => false]));
        self::assertSame(400, $this->request('POST', 'runs', ['message' => 'Disabled'])->get_status());
        $run = $this->task(); (new Oyiso_Agent_Runner($gateway))->step($run['id']);
        self::assertSame('failed', Oyiso_Agent_Store::get($run['id'])['status']);
    }

    public function testLocksRecoverAndDoNotReleaseOtherOwners(): void
    {
        $resource = 'fixture_' . wp_generate_uuid4(); $key = 'oyiso_agent_lock_' . $resource;
        self::assertTrue(Oyiso_Agent_Store::lock($resource));
        self::assertFalse(Oyiso_Agent_Store::lock($resource));
        update_option($key, 'replacement-owner', false);
        Oyiso_Agent_Store::unlock($resource);
        self::assertSame('replacement-owner', get_option($key));
        update_option($key, (string) (time() - 300), false);
        self::assertTrue(Oyiso_Agent_Store::lock($resource));
        Oyiso_Agent_Store::unlock($resource);
        self::assertFalse(get_option($key));
        $run = $this->task(); $run['status'] = 'running';
        self::assertTrue(Oyiso_Agent_Store::view($run)['recoverable']);
    }

    public function testCredentialsAreEncryptedPreservedClearedAndRedacted(): void
    {
        $saved = Oyiso_Agent_Config::saveOptions(['agent_deepseek_key' => 'fixture-secret-key', 'agent_gemini_key' => 'fixture-other-key']);
        self::assertSame('', $saved['agent_deepseek_key']); self::assertSame('fixture-secret-key', Oyiso_Agent_Config::secret('deepseek_key'));
        self::assertStringNotContainsString('fixture-secret-key', serialize(get_option('oyiso_agent_secrets')));
        Oyiso_Agent_Config::saveOptions(['agent_deepseek_key' => '']);
        self::assertSame('fixture-secret-key', Oyiso_Agent_Config::secret('deepseek_key'));
        self::assertStringNotContainsString('fixture-secret-key', Oyiso_Agent_Config::error('Bearer abc fixture-secret-key token=foo'));
        Oyiso_Agent_Config::saveOptions(['agent_deepseek_key_clear' => true]);
        self::assertFalse(Oyiso_Agent_Config::hasSecret('deepseek_key'));
    }

    public function testRemovedResearchCannotBeEnabledByLegacySettingsOrOldToolCalls(): void
    {
        update_option('oyiso', array_merge(Oyiso_Agent_Config::options(), ['agent_search_provider' => 'tavily', 'agent_keywords_provider' => 'dataforseo']));
        update_option('oyiso_agent_secrets', ['search_key' => 'legacy-encrypted-search', 'keywords_password' => 'legacy-encrypted-keywords'], false);
        $saved = Oyiso_Agent_Config::saveOptions(['agent_search_key' => 'removed-input', 'agent_keywords_password' => 'removed-password']);
        self::assertArrayNotHasKey('agent_search_key', $saved);
        self::assertArrayNotHasKey('agent_keywords_password', $saved);
        self::assertSame('legacy-encrypted-search', get_option('oyiso_agent_secrets')['search_key']);
        self::assertSame(['deepseek_key', 'gemini_key', 'agnes_key'], array_keys(Oyiso_Agent_Config::secretStatus()));
        self::assertFalse(class_exists('Oyiso_Agent_Research', false));
        $run = $this->task();
        $tools = new Oyiso_Agent_Tools($run);
        foreach (['search-web', 'read-webpage', 'get-keyword-metrics'] as $name) {
            self::assertNull(wp_get_ability('oyiso/' . $name));
            self::assertNotContains('oyiso/' . $name, Oyiso_Agent_Abilities::allowed());
            self::assertSame('agent_unknown_tool', $tools->execute($name, [])->get_error_code());
        }
        $block = static function () { self::fail('Removed tools must not issue HTTP requests.'); };
        add_filter('pre_http_request', $block, 10);
        try {
            Oyiso_Agent_Runner::message($run, 'Legacy webpage request');
            (new Oyiso_Agent_Runner(new OyisoAgentScriptedModel([$this->turn('', [['read-webpage', ['url' => 'https://example.com/source']]])])))->step($run['id']);
            $fresh = Oyiso_Agent_Store::get($run['id']);
            self::assertContains('该工具或参数不在允许范围。', array_column($fresh['events'], 'detail'));
        } finally { remove_filter('pre_http_request', $block, 10); }
    }

    public function testInvalidOrderDatesReturnExplicitErrors(): void
    {
        $run = $this->task();
        self::assertWPError($this->tool($run, 'order-summary', ['start_date' => '2026-02-30', 'end_date' => '2026-03-01']));
        $summary = $this->tool($run, 'order-summary', ['start_date' => '2099-01-01', 'end_date' => '2099-01-02']);
        self::assertSame(0, $summary['total_orders']); self::assertFalse($summary['truncated']);
    }

    public function testNativeModelAdapterUsesOfficialAbilityToolDeclarations(): void
    {
        $turn = $this->turn('Native response');
        $metadata = $this->createMock(ModelMetadata::class); $metadata->method('getId')->willReturn('native-fixture');
        $result = $this->createMock(GenerativeAiResult::class);
        $result->method('getCandidates')->willReturn([new Candidate($turn->message, FinishReasonEnum::stop())]);
        $result->method('getTokenUsage')->willReturn(new TokenUsage(10, 20, 30));
        $result->method('getModelMetadata')->willReturn($metadata);
        $builder = $this->getMockBuilder(WP_AI_Client_Prompt_Builder::class)->disableOriginalConstructor()->onlyMethods(['__call', 'using_abilities'])->getMock();
        $builder->expects(self::once())->method('using_abilities')->with('oyiso/get-product')->willReturnSelf();
        $builder->method('__call')->willReturnCallback(static fn(string $name): mixed => match ($name) { 'is_supported_for_text_generation' => true, 'generate_text_result' => $result, default => $builder });
        $adapter = new Oyiso_Agent_Model(static fn() => $builder);
        $response = $adapter->generate([(new UserMessage([new MessagePart('Read product')]))->toArray()], ['oyiso/get-product']);
        self::assertInstanceOf(Oyiso_Agent_Turn::class, $response);
        self::assertSame(30, $response->tokens); self::assertSame('native-fixture', $response->model);
        self::assertStringContainsString('禁止编造', Oyiso_Agent_Model::instructions());
    }

    public function testSettingsGroupAdvancedFieldsWithModelsAndHistoryRendersWithoutNestedForms(): void
    {
        $sections = CSF::$args['sections']['oyiso'];
        $children = array_values(array_filter($sections, static fn(array $section): bool => ($section['parent'] ?? '') === 'ai-agent'));
        self::assertSame(['agent-model', 'agent-personalization', 'agent-tools', 'agent-records', 'agent-files'], array_column($children, 'id'));
        self::assertSame(['模型配置', '个性化', '工具权限', '对话记录', '文件管理'], array_column($children, 'title'));
        foreach ($children as $section) {
            self::assertSame('heading', $section['fields'][0]['type']);
            self::assertSame($section['title'], $section['fields'][0]['content']);
        }
        self::assertSame(['agent_language', 'agent_reply_language', 'agent_reply_detail', 'agent_instructions'], array_column(array_filter($children[1]['fields'], static fn(array $field): bool => ($field['class'] ?? '') !== 'hidden'), 'id'));
        self::assertNotContains('agent_language', array_column($children[0]['fields'], 'id'));
        $advanced = $children[0]['fields'][array_key_last($children[0]['fields'])];
        self::assertSame('agent_advanced', $advanced['id']);
        self::assertSame('fieldset', $advanced['type']);
        self::assertSame(['max_steps', 'model'], array_column($advanced['fields'], 'id'));
        self::assertSame(['agent_write_tools', 'agent_mcp_namespaces', 'agent_plugin_tools_version'], array_column($children[2]['fields'], 'id'));
        self::assertSame('callback', $children[2]['fields'][1]['type']);
        self::assertSame([], $children[2]['fields'][1]['default']);
        self::assertSame(['woodmart'], $children[2]['fields'][2]['default']);
        ob_start(); Oyiso_Agent_Admin::tools(); Oyiso_Agent_Admin::history(); Oyiso_Agent_Admin::connection(); Oyiso_Agent_File_Manager::render(); $html = ob_get_clean();
        self::assertStringNotContainsString('oyiso-agent-chat', $html); self::assertStringContainsString('oyiso-agent-history', $html);
        self::assertStringNotContainsString('oyiso-agent-tasks', $html);
        self::assertStringNotContainsString('<h2>对话记录</h2>', $html);
        self::assertStringNotContainsString('<h2>文件管理</h2>', $html);
        self::assertStringNotContainsString('执行记录', $html);
        self::assertStringContainsString('选择对话批量删除', $html);
        self::assertStringContainsString('oyiso-agent-files', $html);
        self::assertStringContainsString('查询商品', $html); self::assertStringContainsString('执行前仍需在对话中确认', $html);
        self::assertStringNotContainsString('<form', $html); self::assertStringContainsString('options-connectors.php', $html);
    }

    public function testRegistrationAndSettingsCanBeBuiltFromTheirPublicHooks(): void
    {
        global $wp_filter;
        foreach (wp_get_abilities() as $name => $ability) {
            if (str_starts_with($name, 'oyiso/')) wp_unregister_ability($name);
        }
        wp_unregister_ability_category('oyiso-agent');
        foreach (['wp_abilities_api_categories_init' => 'categories', 'wp_abilities_api_init' => 'register'] as $hook => $method) {
            $previous = $wp_filter[$hook];
            $wp_filter[$hook] = new WP_Hook();
            try {
                add_action($hook, [Oyiso_Agent_Abilities::class, $method]);
                do_action($hook);
            } finally { $wp_filter[$hook] = $previous; }
        }
        self::assertInstanceOf(WP_Ability::class, wp_get_ability('oyiso/get-product'));
        $oldSections = CSF::$args['sections']; $oldFields = CSF::$fields;
        try {
            CSF::$args['sections']['oyiso'] = [];
            require dirname(__DIR__, 2) . '/src/ai-agent/settings.php';
            $sections = CSF::$args['sections']['oyiso'];
            self::assertCount(6, $sections);
            self::assertSame('ai-agent', $sections[0]['id']);
            self::assertNotContains('agent_selection', array_column($sections[1]['fields'], 'id'));
            $provider = array_values(array_filter($sections[1]['fields'], static fn(array $field): bool => ($field['class'] ?? '') === 'oyiso-agent-model-providers'))[0];
            ob_start(); CSF::field($provider, '', 'oyiso'); $html = ob_get_clean();
            self::assertStringContainsString('data-agent-settings', $html);
            self::assertStringNotContainsString('data-provider-default', $html);
        } finally { CSF::$args['sections'] = $oldSections; CSF::$fields = $oldFields; }
    }

    public function testGlobalPanelAndAssetsAreAvailableOnDashboardOnlyToSiteAdministrators(): void
    {
        self::assertSame(10, has_action('admin_footer', [Oyiso_Agent_Admin::class, 'panel']));
        Oyiso_Agent_Admin::assets('index.php');
        self::assertTrue(wp_script_is('oyiso-agent', 'enqueued'));
        self::assertTrue(wp_script_is('oyiso-agent-panel', 'enqueued'));
        self::assertContains('oyiso-agent-panel', wp_scripts()->registered['oyiso-agent']->deps);
        self::assertStringContainsString('var oyisoAgent', wp_scripts()->get_data('oyiso-agent-panel', 'data'));
        self::assertTrue(wp_style_is('oyiso-agent', 'enqueued'));
        ob_start(); Oyiso_Agent_Admin::panel(); $html = ob_get_clean();
        self::assertStringContainsString('id="oyiso-agent-launcher"', $html);
        self::assertStringContainsString('aria-expanded="false"', $html);
        self::assertStringContainsString('aria-labelledby="oyiso-agent-title" hidden', $html);
        self::assertStringContainsString('id="oyiso-agent-chat"', $html);
        self::assertStringContainsString('id="oyiso-agent-launcher-wrap"', $html);
        self::assertStringContainsString('style="visibility: hidden" data-agent-initializing', $html);
        self::assertStringContainsString('aria-label="调整助手宽度"', $html);
        self::assertSame(8, substr_count($html, 'data-agent-resize="'));
        self::assertStringContainsString('data-agent-drag tabindex="-1"', $html);
        self::assertStringContainsString('data-agent-history-toggle aria-controls="oyiso-agent-panel-history"', $html);
        self::assertStringContainsString('data-agent-runs="panel"', $html);
        self::assertStringContainsString('data-agent-layout-menu role="group"', $html);
        self::assertStringContainsString('data-agent-layout-choice="left"', $html);
        self::assertStringContainsString('data-agent-layout-choice="right"', $html);
        self::assertStringContainsString('data-agent-reset', $html);
        self::assertStringContainsString('data-agent-history-dismiss', $html);
        self::assertStringNotContainsString('data-agent-tab="ai-agent/agent-records"', $html);
        self::assertStringNotContainsString('title="拖动调整宽度', $html);
        self::assertStringNotContainsString('<form', $html);
        wp_dequeue_script('oyiso-agent'); wp_dequeue_script('oyiso-agent-page'); wp_dequeue_script('oyiso-agent-panel'); wp_dequeue_style('oyiso-agent');
        wp_set_current_user(0);
        Oyiso_Agent_Admin::assets('index.php');
        self::assertFalse(wp_script_is('oyiso-agent', 'enqueued'));
        self::assertFalse(wp_script_is('oyiso-agent-panel', 'enqueued'));
        ob_start(); Oyiso_Agent_Admin::panel(); $html = ob_get_clean();
        self::assertSame('', $html);
    }

    public function testDisabledAgentEmitsNoGlobalMarkupOrAssetsEvenOnItsSettingsPage(): void
    {
        update_option('oyiso', array_merge(Oyiso_Agent_Config::options(), ['agent_enabled' => false]), false);
        foreach (['oyiso-agent', 'oyiso-agent-panel', 'oyiso-agent-page'] as $handle) {
            wp_dequeue_script($handle);
            wp_deregister_script($handle);
        }
        wp_dequeue_style('oyiso-agent');
        wp_deregister_style('oyiso-agent');
        foreach (['index.php', 'plugins_page_oyiso'] as $hook) {
            Oyiso_Agent_Admin::assets($hook);
            foreach (['oyiso-agent', 'oyiso-agent-panel', 'oyiso-agent-page'] as $handle) {
                self::assertFalse(wp_script_is($handle, 'enqueued'));
                self::assertFalse(wp_script_is($handle, 'registered'));
            }
            self::assertFalse(wp_style_is('oyiso-agent', 'enqueued'));
            self::assertFalse(wp_style_is('oyiso-agent', 'registered'));
            ob_start(); Oyiso_Agent_Admin::panel(); $html = ob_get_clean();
            self::assertSame('', $html);
        }
        ob_start(); Oyiso_Agent_Admin::history(); Oyiso_Agent_Admin::connectionTest('deepseek'); $html = ob_get_clean();
        self::assertStringNotContainsString('正在读取记录', $html);
        self::assertStringNotContainsString('<button', $html);
        self::assertStringContainsString('刷新页面', $html);
    }

    public function testFailedSeoWriteRollsBackProductFields(): void
    {
        $run = $this->task(); $product = $this->product(); $before = Oyiso_Agent_Tools::fields($product);
        $proposal = $this->proposal($run, $product);
        $block = static fn($value, $id, $key) => $key === '_seopress_titles_title' ? false : $value;
        add_filter('update_post_metadata', $block, 10, 3);
        try { self::assertWPError($this->tool($run, 'apply-product-update', ['proposal_id' => $proposal], 'apply')); }
        finally { remove_filter('update_post_metadata', $block, 10); }
        self::assertSame($before, Oyiso_Agent_Tools::fields(wc_get_product($product->get_id())));
        self::assertSame('pending', $run['changes'][0]['status']);
    }

    public function testNativeModelReportsUnavailableProviderAndContextLimit(): void
    {
        $builder = $this->getMockBuilder(WP_AI_Client_Prompt_Builder::class)->disableOriginalConstructor()->onlyMethods(['__call', 'using_abilities'])->getMock();
        $builder->method('using_abilities')->willReturnSelf();
        $builder->method('__call')->willReturnCallback(static fn(string $name): mixed => $name === 'is_supported_for_text_generation' ? false : $builder);
        $adapter = new Oyiso_Agent_Model(static fn() => $builder);
        $history = [(new UserMessage([new MessagePart('Read a product')]))->toArray()];
        self::assertWPError($adapter->generate($history, ['oyiso/get-product']));
        $history = [(new UserMessage([new MessagePart(str_repeat('x', 180001))]))->toArray()];
        self::assertWPError($adapter->generate($history, ['oyiso/get-product']));
        $adapter = new Oyiso_Agent_Model(static fn() => throw new RuntimeException('Provider initialization failed'));
        self::assertWPError($adapter->generate([], []));
    }

    public function testRunnerHandlesEmptyResponsesAndExcessiveToolCalls(): void
    {
        $run = $this->task(); Oyiso_Agent_Runner::message($run, 'Empty response');
        (new Oyiso_Agent_Runner(new OyisoAgentScriptedModel([$this->turn()])))->step($run['id']);
        self::assertSame('failed', Oyiso_Agent_Store::get($run['id'])['status']);
        $run = $this->task(); Oyiso_Agent_Runner::message($run, 'Too many tools');
        (new Oyiso_Agent_Runner(new OyisoAgentScriptedModel([$this->turn('', array_fill(0, 5, ['get-product', ['product_id' => 1]]))])))->step($run['id']);
        self::assertSame('failed', Oyiso_Agent_Store::get($run['id'])['status']);
    }

    public function testProgressCheckpointHasNoUnansweredToolCalls(): void
    {
        $run = $this->task(); Oyiso_Agent_Runner::message($run, 'Search products');
        $observed = false;
        $observe = function ($args) use ($run, &$observed) {
            $checkpoint = Oyiso_Agent_Store::get($run['id']);
            self::assertCount(1, $checkpoint['messages']);
            self::assertSame('running', $checkpoint['status']);
            $observed = true;
            return $args;
        };
        add_filter('woocommerce_product_object_query_args', $observe);
        try {
            (new Oyiso_Agent_Runner(new OyisoAgentScriptedModel([$this->turn('', [['search-products', []]])])))->step($run['id']);
        } finally { remove_filter('woocommerce_product_object_query_args', $observe); }
        self::assertTrue($observed);
        $fresh = Oyiso_Agent_Store::get($run['id']);
        self::assertCount(3, $fresh['messages']);
        self::assertContains('查询商品', array_column($fresh['events'], 'label'));
    }

    public function testRestStatusAndCompletedTaskCanContinueConversation(): void
    {
        $state = $this->request('GET', 'status')->get_data();
        self::assertTrue($state['enabled']); self::assertTrue($state['supported']);
        self::assertArrayNotHasKey('search_key', $state);
        self::assertArrayNotHasKey('search_configured', $state);
        self::assertArrayNotHasKey('keywords_configured', $state);
        $run = $this->task(); $run['status'] = 'completed'; Oyiso_Agent_Store::save($run);
        $missing = $this->request('POST', 'runs', ['message' => 'Follow-up', 'run_id' => $run['id']]);
        self::assertSame(400, $missing->get_status());
        self::assertSame('agent_model_selection', $missing->get_data()['code']);
        self::assertSame([], Oyiso_Agent_Store::get($run['id'])['conversation']);
        $connection = Oyiso_Agent_Connections::save(['name' => '旧对话重试', 'preset' => 'custom', 'url' => 'https://api.example.com/v1', 'protocol' => 'openai_responses', 'models' => ['fixture-model'], 'key' => 'fixture-retry-key']);
        self::assertIsArray($connection);
        self::assertSame(201, $this->request('POST', 'runs', ['message' => 'Follow-up', 'run_id' => $run['id'], 'selection' => $connection['id'] . '|fixture-model'])->get_status());
        self::assertSame($connection['id'] . '|fixture-model', Oyiso_Agent_Store::get($run['id'])['selection']);
        self::assertSame(200, $this->request('GET', 'runs/' . $run['id'])->get_status());
    }

    public function testEachReplyKeepsItsActualModelAcrossFollowUpsAndHistoryReads(): void
    {
        $run = $this->task();
        $runner = new Oyiso_Agent_Runner(new OyisoAgentScriptedModel([
            new Oyiso_Agent_Turn(new ModelMessage([new MessagePart('First reply')]), 2, 'first-model'),
            new Oyiso_Agent_Turn(new ModelMessage([new MessagePart('Second reply')]), 2, 'second-model'),
        ]));
        Oyiso_Agent_Runner::message($run, 'Hello'); $runner->step($run['id']);
        $run = Oyiso_Agent_Store::get($run['id']);
        Oyiso_Agent_Runner::message($run, 'Follow-up'); $runner->step($run['id']);
        self::assertSame([
            ['role' => 'user', 'text' => 'Hello'],
            ['role' => 'assistant', 'text' => 'First reply', 'model' => 'first-model', 'model_label' => 'first-model'],
            ['role' => 'user', 'text' => 'Follow-up'],
            ['role' => 'assistant', 'text' => 'Second reply', 'model' => 'second-model', 'model_label' => 'second-model'],
        ], $this->request('GET', 'runs/' . $run['id'])->get_data()['conversation']);
    }

    public function testPartialRepliesSurviveReadsWhileTheWorkerLockPreventsDuplicateGeneration(): void
    {
        $run = $this->task(); $run['selection'] = 'fixture|fixture-model';
        Oyiso_Agent_Runner::message($run, '你好');
        $beforeAbort = ignore_user_abort();
        $model = null;
        $model = new OyisoAgentScriptedModel([function (Closure $publish) use ($run, &$model) {
            self::assertSame(1, ignore_user_abort());
            $publish('你');
            $view = $this->request('GET', 'runs/' . $run['id'])->get_data();
            self::assertSame(['text' => '你', 'model' => 'fixture-model', 'model_label' => 'fixture-model'], $view['partial']);
            self::assertSame('running', $view['status']); self::assertFalse($view['recoverable']);
            (new Oyiso_Agent_Runner($model))->step($run['id']); self::assertSame(1, $model->calls);
            $publish('好');
            return $this->turn('你好');
        }]);
        (new Oyiso_Agent_Runner($model))->step($run['id']);
        $view = $this->request('GET', 'runs/' . $run['id'])->get_data();
        self::assertSame('completed', $view['status']); self::assertArrayNotHasKey('partial', $view);
        self::assertCount(2, $view['conversation']); self::assertSame('你好', $view['conversation'][1]['text']);
        self::assertSame($beforeAbort, ignore_user_abort());
    }

    public function testInterruptedAndCancelledStreamsKeepPartialTextWithoutAddingIncompleteModelHistory(): void
    {
        foreach (['failed', 'cancelled'] as $status) {
            $run = $this->task(); Oyiso_Agent_Runner::message($run, 'Stream test');
            $model = new OyisoAgentScriptedModel([static function (Closure $publish) use ($run, $status) {
                $publish('已收到的文字');
                if ($status === 'cancelled') { Oyiso_Agent_Store::cancel($run['id']); $publish('不该出现'); }
                return new WP_Error('interrupted', 'Connection interrupted');
            }]);
            (new Oyiso_Agent_Runner($model))->step($run['id']);
            $fresh = Oyiso_Agent_Store::get($run['id']);
            self::assertSame($status, $fresh['status']); self::assertSame('已收到的文字', $fresh['partial']['text']);
            self::assertCount(1, $fresh['messages']); self::assertCount(1, $fresh['conversation']);
        }
    }

    public function testCancellationSeesUpdatesOutsideCurrentMetadataCache(): void
    {
        global $wpdb;
        $run = $this->task();
        get_post_meta($run['id'], '_oyiso_agent_cancelled', true);
        $wpdb->insert($wpdb->postmeta, ['post_id' => $run['id'], 'meta_key' => '_oyiso_agent_cancelled', 'meta_value' => '1']);
        self::assertTrue(Oyiso_Agent_Store::cancelled($run['id']));
    }

    public function testCancelledConversationStillAllowsRevertingPreviouslyAppliedChanges(): void
    {
        $run = $this->task(); $product = $this->product(); $before = Oyiso_Agent_Tools::fields($product);
        $proposal = $this->proposal($run, $product);
        self::assertIsArray($this->tool($run, 'apply-product-update', ['proposal_id' => $proposal], 'apply'));
        Oyiso_Agent_Store::save($run); Oyiso_Agent_Store::cancel($run['id']);
        $result = $this->request('POST', 'runs/' . $run['id'] . '/changes/' . $proposal, ['action' => 'revert']);
        self::assertSame(200, $result->get_status());
        self::assertSame('cancelled', $result->get_data()['status']);
        self::assertSame('reverted', $result->get_data()['changes'][0]['status']);
        self::assertSame($before, Oyiso_Agent_Tools::fields(wc_get_product($product->get_id())));
    }

    private function trashProposal(array &$run, WC_Product $product): string
    {
        self::assertIsArray($this->tool($run, 'get-product', ['product_id' => $product->get_id()]));
        $proposal = $this->tool($run, 'prepare-product-trash', ['product_id' => $product->get_id(), 'reason' => '用户已指定此测试商品，移入回收站。']);
        self::assertIsArray($proposal);
        Oyiso_Agent_Store::save($run);
        return $proposal['proposal_id'];
    }

    /** @return array{WC_Product_Variable,WC_Product_Variation,WC_Product_Variation} */
    private function variableProduct(): array
    {
        $parent = new WC_Product_Variable();
        $parent->set_name('Oyiso Agent variable fixture ' . wp_generate_uuid4());
        $parent->set_status('publish'); $parent->save(); $this->products[] = $parent->get_id();
        $children = [];
        foreach (['publish', 'private'] as $status) {
            $child = new WC_Product_Variation(); $child->set_parent_id($parent->get_id());
            $child->set_status($status); $child->set_regular_price('20'); $child->save();
            $this->products[] = $child->get_id(); $children[] = $child;
        }
        return [$parent, $children[0], $children[1]];
    }

    public function testProductTrashToolsExposePreparationButNeverDirectWrites(): void
    {
        self::assertContains('oyiso/prepare-product-trash', Oyiso_Agent_Abilities::allowed());
        foreach (['apply', 'revert', 'dismiss'] as $action) {
            $ability = wp_get_ability('oyiso/' . $action . '-product-trash');
            self::assertInstanceOf(WP_Ability::class, $ability);
            self::assertNotContains($ability->get_name(), Oyiso_Agent_Abilities::allowed());
            self::assertFalse($ability->get_meta()['show_in_rest']);
        }
        $run = $this->task(); $product = $this->product(); $proposal = $this->trashProposal($run, $product);
        self::assertSame('draft', get_post_status($product->get_id()));
        self::assertSame('trash', $run['changes'][0]['type']);
        self::assertSame('pending', $run['changes'][0]['status']);
        self::assertWPError($this->tool($run, 'apply-product-trash', ['proposal_id' => $proposal]));
        self::assertWPError($this->tool($run, 'apply-product-update', ['proposal_id' => $proposal], 'apply'));
        self::assertSame('draft', get_post_status($product->get_id()));
    }

    public function testProductWritesDefaultToReadOnlyAndRejectDirectCallsEvenWithLegacyPermission(): void
    {
        $run = $this->task(); $product = $this->product();
        $update = $this->proposal($run, $product, ['description' => 'Unapproved edit']);
        $trashRun = $this->task(); $trash = $this->trashProposal($trashRun, $product);
        update_option('oyiso', ['agent_enabled' => true, 'agent_allow_product_trash' => true], false);
        self::assertSame([], Oyiso_Agent_Permissions::granted());
        self::assertSame([], $this->request('GET', 'status')->get_data()['writeTools']);
        self::assertContains('oyiso/get-product', Oyiso_Agent_Abilities::allowed());
        foreach (['prepare-product-update', 'prepare-product-trash'] as $name) {
            self::assertNotContains('oyiso/' . $name, Oyiso_Agent_Abilities::allowed());
            $args = ['product_id' => $product->get_id(), 'reason' => 'No permission', 'fields' => ['description' => 'No permission']];
            self::assertWPError($this->tool($run, $name, $args));
            self::assertSame('agent_write_disabled', (new Oyiso_Agent_Tools($run))->execute($name, $args)->get_error_code());
        }
        self::assertFalse($this->tool($run, 'get-product', ['product_id' => $product->get_id()])['can_trash']);
        foreach (['apply-product-update', 'revert-product-update', 'apply-product-trash', 'revert-product-trash'] as $name) {
            $approval = str_starts_with($name, 'revert') ? 'revert' : 'apply';
            $id = str_ends_with($name, 'trash') ? $trash : $update;
            self::assertWPError($this->tool($run, $name, ['proposal_id' => $id], $approval));
            self::assertSame('agent_write_disabled', (new Oyiso_Agent_Tools($run, $approval))->execute($name, ['proposal_id' => $id])->get_error_code());
        }
        self::assertWPError(Oyiso_Agent_Product_Trash::prepare($trashRun, $product->get_id(), 'No permission'));
        self::assertWPError(Oyiso_Agent_Product_Trash::change($trashRun, $trash, 'apply', 'apply'));
        self::assertWPError(Oyiso_Agent_Product_Trash::change($trashRun, $trash, 'revert', 'revert'));
        self::assertSame('draft', get_post_status($product->get_id()));
        self::assertSame($run['snapshots'][$product->get_id()], Oyiso_Agent_Tools::fields(wc_get_product($product->get_id())));
    }

    public function testProductTrashRevokedPermissionBlocksPendingPlansButAllowsDismissal(): void
    {
        $run = $this->task(); $product = $this->product(); $proposal = $this->trashProposal($run, $product);
        $run['status'] = 'waiting'; Oyiso_Agent_Store::save($run);
        update_option('oyiso', array_replace(Oyiso_Agent_Config::options(), ['agent_write_tools' => []]), false);
        self::assertWPError($this->tool($run, 'apply-product-trash', ['proposal_id' => $proposal], 'apply'));
        $result = (new Oyiso_Agent_Tools($run, 'apply'))->execute('apply-product-trash', ['proposal_id' => $proposal]);
        self::assertWPError($result); self::assertSame('agent_write_disabled', $result->get_error_code());
        self::assertWPError(Oyiso_Agent_Product_Trash::change($run, $proposal, 'apply', 'apply'));
        $route = 'runs/' . $run['id'] . '/changes/' . $proposal;
        self::assertGreaterThanOrEqual(400, $this->request('POST', $route, ['action' => 'trash'])->get_status());
        self::assertSame('pending', Oyiso_Agent_Store::get($run['id'])['changes'][0]['status']);
        self::assertSame('draft', get_post_status($product->get_id()));
        self::assertSame('dismissed', $this->request('POST', $route, ['action' => 'dismiss'])->get_data()['changes'][0]['status']);
    }

    public function testProductTrashRestoreRequiresItsOwnGrantAfterDeletionPermissionIsRevoked(): void
    {
        $run = $this->task(); $product = $this->product(); $proposal = $this->trashProposal($run, $product);
        $run['status'] = 'waiting'; Oyiso_Agent_Store::save($run);
        $route = 'runs/' . $run['id'] . '/changes/' . $proposal;
        self::assertSame('applied', $this->request('POST', $route, ['action' => 'trash'])->get_data()['changes'][0]['status']);
        update_option('oyiso', array_replace(Oyiso_Agent_Config::options(), ['agent_write_tools' => []]), false);
        self::assertGreaterThanOrEqual(400, $this->request('POST', $route, ['action' => 'restore'])->get_status());
        self::assertSame('trash', get_post_status($product->get_id()));
        update_option('oyiso', array_replace(Oyiso_Agent_Config::options(), ['agent_write_tools' => ['oyiso/revert-product-trash']]), false);
        self::assertNotContains('oyiso/prepare-product-trash', Oyiso_Agent_Abilities::allowed());
        self::assertSame('reverted', $this->request('POST', $route, ['action' => 'restore'])->get_data()['changes'][0]['status']);
        self::assertSame('draft', get_post_status($product->get_id()));
    }

    public function testProductUpdateGrantCanBeRevokedAndDoesNotAuthorizeRevertOrDeletion(): void
    {
        $run = $this->task(); $product = $this->product(); $proposal = $this->proposal($run, $product, ['description' => 'Approved description']);
        $run['status'] = 'waiting'; Oyiso_Agent_Store::save($run);
        $route = 'runs/' . $run['id'] . '/changes/' . $proposal;
        update_option('oyiso', array_replace(Oyiso_Agent_Config::options(), ['agent_write_tools' => []]), false);
        self::assertGreaterThanOrEqual(400, $this->request('POST', $route, ['action' => 'apply'])->get_status());
        update_option('oyiso', array_replace(Oyiso_Agent_Config::options(), ['agent_write_tools' => ['oyiso/apply-product-update']]), false);
        self::assertContains('oyiso/prepare-product-update', Oyiso_Agent_Abilities::allowed());
        self::assertNotContains('oyiso/prepare-product-trash', Oyiso_Agent_Abilities::allowed());
        self::assertSame('applied', $this->request('POST', $route, ['action' => 'apply'])->get_data()['changes'][0]['status']);
        self::assertGreaterThanOrEqual(400, $this->request('POST', $route, ['action' => 'revert'])->get_status());
        self::assertSame('Approved description', wc_get_product($product->get_id())->get_description());
        update_option('oyiso', array_replace(Oyiso_Agent_Config::options(), ['agent_write_tools' => ['oyiso/revert-product-update']]), false);
        self::assertSame('reverted', $this->request('POST', $route, ['action' => 'revert'])->get_data()['changes'][0]['status']);
        self::assertSame($run['snapshots'][$product->get_id()], Oyiso_Agent_Tools::fields(wc_get_product($product->get_id())));
    }

    public function testProductWritesRecheckSavedPermissionsWhenTheRequestCacheIsStale(): void
    {
        global $wpdb;
        $run = $this->task(); $product = $this->product(); $proposal = $this->proposal($run, $product, ['description' => 'Do not save']);
        $options = Oyiso_Agent_Config::options();
        try {
            foreach ([array_replace($options, ['agent_write_tools' => []]), array_replace($options, ['agent_enabled' => false])] as $saved) {
                self::assertNotFalse($wpdb->update($wpdb->options, ['option_value' => maybe_serialize($saved)], ['option_name' => 'oyiso']));
                self::assertTrue(Oyiso_Agent_Permissions::allows('oyiso/apply-product-update'), 'The old request still has a cached grant.');
                self::assertSame('agent_write_disabled', (new Oyiso_Agent_Tools($run, 'apply'))->execute('apply-product-update', ['proposal_id' => $proposal])->get_error_code());
                self::assertSame('agent_write_disabled', Oyiso_Agent_Product_Trash::prepare($run, $product->get_id(), 'No cached grants')->get_error_code());
                self::assertSame($run['snapshots'][$product->get_id()], Oyiso_Agent_Tools::fields(wc_get_product($product->get_id())));
            }
        } finally {
            $wpdb->update($wpdb->options, ['option_value' => maybe_serialize($options)], ['option_name' => 'oyiso']);
            wp_cache_delete('oyiso', 'options'); wp_cache_delete('alloptions', 'options');
        }
    }

    public function testProductTrashRequiresFreshReadAndValidReasonAndMainProduct(): void
    {
        $run = $this->task(); $product = $this->product();
        $args = ['product_id' => $product->get_id(), 'reason' => 'Test'];
        self::assertWPError($this->tool($run, 'prepare-product-trash', $args));
        $this->tool($run, 'get-product', ['product_id' => $product->get_id()]);
        self::assertWPError($this->tool($run, 'prepare-product-trash', array_replace($args, ['reason' => str_repeat('a', 501)])));
        self::assertWPError($this->tool($run, 'prepare-product-trash', array_replace($args, ['force' => true])));
        $product->set_regular_price('21'); $product->save();
        self::assertWPError($this->tool($run, 'prepare-product-trash', $args));
        [$parent, $child] = $this->variableProduct();
        $this->tool($run, 'get-product', ['product_id' => $child->get_id()]);
        self::assertWPError($this->tool($run, 'prepare-product-trash', ['product_id' => $child->get_id(), 'reason' => 'No standalone variations']));
        self::assertSame('publish', get_post_status($parent->get_id()));
    }

    public function testProductTrashAndRestorePreserveProductAndMediaAndAreIdempotent(): void
    {
        $run = $this->task(); $product = $this->product();
        $media = wp_insert_post(['post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'Oyiso trash image fixture']);
        try {
            $product->set_image_id($media); $product->save();
            $before = Oyiso_Agent_Tools::fields($product); $proposal = $this->trashProposal($run, $product);
            $pending = $run;
            self::assertSame('applied', $this->tool($run, 'apply-product-trash', ['proposal_id' => $proposal], 'apply')['status']);
            self::assertStringContainsString('status=applied', wp_json_encode($run['messages']));
            self::assertSame('trash', get_post_status($product->get_id()));
            self::assertInstanceOf(WP_Post::class, get_post($media));
            self::assertSame($media, (int) get_post_meta($product->get_id(), '_thumbnail_id', true));
            self::assertSame('applied', $this->tool($pending, 'apply-product-trash', ['proposal_id' => $proposal], 'apply')['status']);
            $applied = $run;
            self::assertSame('reverted', $this->tool($run, 'revert-product-trash', ['proposal_id' => $proposal], 'revert')['status']);
            self::assertStringContainsString('status=reverted', wp_json_encode($run['messages']));
            self::assertSame('draft', get_post_status($product->get_id()));
            self::assertSame($before, Oyiso_Agent_Tools::fields(wc_get_product($product->get_id())));
            self::assertSame('19.90', wc_get_product($product->get_id())->get_regular_price());
            self::assertSame('reverted', $this->tool($applied, 'revert-product-trash', ['proposal_id' => $proposal], 'revert')['status']);
        } finally { wp_delete_post($media, true); }
    }

    public function testProductTrashRestoresVariationStatusesAndLeavesPreviouslyTrashedOnesAlone(): void
    {
        $run = $this->task(); [$parent, $first, $second] = $this->variableProduct();
        $old = new WC_Product_Variation(); $old->set_parent_id($parent->get_id()); $old->set_status('private'); $old->save();
        $this->products[] = $old->get_id(); wp_trash_post($old->get_id());
        $proposal = $this->trashProposal($run, $parent);
        self::assertSame(2, $run['changes'][0]['variation_count']);
        self::assertIsArray($this->tool($run, 'apply-product-trash', ['proposal_id' => $proposal], 'apply'));
        foreach ([$parent, $first, $second, $old] as $item) self::assertSame('trash', get_post_status($item->get_id()));
        self::assertIsArray($this->tool($run, 'revert-product-trash', ['proposal_id' => $proposal], 'revert'));
        self::assertSame('publish', get_post_status($parent->get_id()));
        self::assertSame('publish', get_post_status($first->get_id()));
        self::assertSame('private', get_post_status($second->get_id()));
        self::assertSame('trash', get_post_status($old->get_id()));
    }

    public function testProductTrashRejectsChangesToProductOrVariationsDuringReview(): void
    {
        $run = $this->task(); [$parent, $child] = $this->variableProduct(); $proposal = $this->trashProposal($run, $parent);
        $child->set_regular_price('22'); $child->save();
        self::assertWPError($this->tool($run, 'apply-product-trash', ['proposal_id' => $proposal], 'apply'));
        self::assertSame('publish', get_post_status($parent->get_id()));
        $proposal = $this->trashProposal($run, wc_get_product($parent->get_id()));
        self::assertSame('superseded', $run['changes'][0]['status']);
        update_post_meta($parent->get_id(), '_stock', 42);
        self::assertWPError($this->tool($run, 'apply-product-trash', ['proposal_id' => $proposal], 'apply'));
        self::assertSame('publish', get_post_status($child->get_id()));
    }

    public function testProductTrashRollsBackWhenAVariationCannotBeTrashed(): void
    {
        $run = $this->task(); [$parent, $first, $second] = $this->variableProduct(); $proposal = $this->trashProposal($run, $parent);
        $block = static fn(mixed $pre, WP_Post $post): mixed => $post->ID === $second->get_id() ? false : $pre;
        add_filter('pre_trash_post', $block, 10, 2);
        try { self::assertWPError($this->tool($run, 'apply-product-trash', ['proposal_id' => $proposal], 'apply')); }
        finally { remove_filter('pre_trash_post', $block, 10); }
        self::assertSame('publish', get_post_status($parent->get_id()));
        self::assertSame('publish', get_post_status($first->get_id()));
        self::assertSame('private', get_post_status($second->get_id()));
        self::assertSame('pending', $run['changes'][0]['status']);
        self::assertFalse(metadata_exists('post', $parent->get_id(), '_oyiso_agent_product_trash'));
    }

    public function testProductTrashRechecksObjectPermissionsAndLocks(): void
    {
        $run = $this->task(); $product = $this->product(); $proposal = $this->trashProposal($run, $product);
        $deny = static function (array $allcaps, array $caps, array $args) use ($product): array {
            if (($args[0] ?? '') === 'delete_post' && ($args[2] ?? 0) === $product->get_id()) foreach ($caps as $cap) $allcaps[$cap] = false;
            return $allcaps;
        };
        add_filter('user_has_cap', $deny, 10, 3);
        try { self::assertWPError($this->tool($run, 'apply-product-trash', ['proposal_id' => $proposal], 'apply')); }
        finally { remove_filter('user_has_cap', $deny, 10); }
        self::assertSame('draft', get_post_status($product->get_id()));
        self::assertTrue(Oyiso_Agent_Store::lock('product_' . $product->get_id()));
        try { self::assertWPError($this->tool($run, 'apply-product-trash', ['proposal_id' => $proposal], 'apply')); }
        finally { Oyiso_Agent_Store::unlock('product_' . $product->get_id()); }
    }

    public function testProductTrashRestRequiresStoppedOwnedRunAndAllowsDismissal(): void
    {
        $run = $this->task(); $product = $this->product(); $proposal = $this->trashProposal($run, $product);
        $route = 'runs/' . $run['id'] . '/changes/' . $proposal;
        self::assertSame(409, $this->request('POST', $route, ['action' => 'trash'])->get_status());
        $run['status'] = 'waiting'; Oyiso_Agent_Store::save($run);
        self::assertSame(400, $this->request('POST', $route, ['action' => 'apply'])->get_status(), 'An old content-update button cannot confirm deletion.');
        self::assertSame('dismissed', $this->request('POST', $route, ['action' => 'dismiss'])->get_data()['changes'][0]['status']);
        self::assertSame('draft', get_post_status($product->get_id()));
        self::assertGreaterThanOrEqual(400, $this->request('POST', $route, ['action' => 'trash'])->get_status());
        $proposal = $this->trashProposal($run, $product); $route = 'runs/' . $run['id'] . '/changes/' . $proposal;
        $user = wp_insert_user(['user_login' => 'agent-trash-fixture-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'administrator']);
        $this->users[] = $user; wp_set_current_user($user);
        self::assertSame(404, $this->request('POST', $route, ['action' => 'trash'])->get_status());
        self::assertSame('draft', get_post_status($product->get_id()));
    }

    public function testProductTrashCanRestoreAfterCancellationButCannotApplyCancelledPlan(): void
    {
        $run = $this->task(); $product = $this->product(); $proposal = $this->trashProposal($run, $product);
        $run['status'] = 'waiting'; Oyiso_Agent_Store::save($run);
        $route = 'runs/' . $run['id'] . '/changes/' . $proposal;
        self::assertSame('applied', $this->request('POST', $route, ['action' => 'trash'])->get_data()['changes'][0]['status']);
        self::assertSame(400, $this->request('POST', $route, ['action' => 'revert'])->get_status(), 'An old content-revert button cannot restore a deleted product.');
        Oyiso_Agent_Store::cancel($run['id']);
        self::assertSame('reverted', $this->request('POST', $route, ['action' => 'restore'])->get_data()['changes'][0]['status']);
        self::assertSame('draft', get_post_status($product->get_id()));
        $other = $this->task(); $proposal = $this->trashProposal($other, $product); Oyiso_Agent_Store::cancel($other['id']);
        self::assertWPError($this->tool($other, 'apply-product-trash', ['proposal_id' => $proposal], 'apply'));
    }

    public function testProductTrashDoesNotRestoreAfterAnUnrelatedManualTrashCycle(): void
    {
        $run = $this->task(); $product = $this->product(); $proposal = $this->trashProposal($run, $product);
        self::assertIsArray($this->tool($run, 'apply-product-trash', ['proposal_id' => $proposal], 'apply'));
        wp_untrash_post($product->get_id()); wp_trash_post($product->get_id());
        self::assertWPError($this->tool($run, 'revert-product-trash', ['proposal_id' => $proposal], 'revert'));
        self::assertSame('trash', get_post_status($product->get_id()));
    }

    public function testProductTrashRestoreRejectsSkuReuse(): void
    {
        $run = $this->task(); $product = $this->product(); $product->set_sku('agent-trash-' . wp_generate_uuid4()); $product->save();
        $proposal = $this->trashProposal($run, $product);
        self::assertIsArray($this->tool($run, 'apply-product-trash', ['proposal_id' => $proposal], 'apply'));
        $other = $this->product(); $other->set_sku($product->get_sku()); $other->save();
        self::assertWPError($this->tool($run, 'revert-product-trash', ['proposal_id' => $proposal], 'revert'));
        self::assertSame('trash', get_post_status($product->get_id()));
        self::assertSame($product->get_sku(), wc_get_product($other->get_id())->get_sku());
    }

    public function testProductTrashRunnerStopsAtReviewWithoutDeleting(): void
    {
        $run = $this->task(); $product = $this->product(); Oyiso_Agent_Runner::message($run, '移入回收站');
        $runner = new Oyiso_Agent_Runner(new OyisoAgentScriptedModel([$this->turn('', [
            ['get-product', ['product_id' => $product->get_id()]],
            ['prepare-product-trash', ['product_id' => $product->get_id(), 'reason' => '明确指定的测试商品']],
            ['apply-product-trash', ['proposal_id' => 'not-approved']],
        ])]));
        $runner->step($run['id']);
        $fresh = Oyiso_Agent_Store::get($run['id']);
        self::assertSame('waiting', $fresh['status']); self::assertSame('pending', $fresh['changes'][0]['status']);
        self::assertStringContainsString('尚未执行', $fresh['conversation'][count($fresh['conversation']) - 1]['text']);
        self::assertSame('draft', get_post_status($product->get_id()));
    }

    public function testProductTrashDisabledNeverPermanentlyDeletes(): void
    {
        if (EMPTY_TRASH_DAYS) self::markTestSkipped('Run with EMPTY_TRASH_DAYS=0 defined before the WordPress bootstrap.');
        $run = $this->task(); $product = $this->product();
        self::assertIsArray($this->tool($run, 'get-product', ['product_id' => $product->get_id()]));
        self::assertWPError($this->tool($run, 'prepare-product-trash', ['product_id' => $product->get_id(), 'reason' => 'Must not hard-delete']));
        $proposal = wp_generate_uuid4();
        $run['changes'][] = ['id' => $proposal, 'type' => 'trash', 'product_id' => $product->get_id(), 'name' => $product->get_name(), 'before' => [], 'after' => [], 'status' => 'pending'];
        self::assertWPError($this->tool($run, 'apply-product-trash', ['proposal_id' => $proposal], 'apply'));
        self::assertSame('draft', get_post_status($product->get_id()));
        self::assertInstanceOf(WP_Post::class, get_post($product->get_id()));
    }

    public function testDisablingWooCommerceBlocksBuiltInReadsAndPendingWrites(): void
    {
        $run = $this->task(); $product = $this->product();
        $before = Oyiso_Agent_Tools::fields($product);
        $proposal = $this->proposal($run, $product);
        $run['status'] = 'waiting'; Oyiso_Agent_Store::save($run);
        update_option('oyiso', array_replace(Oyiso_Agent_Config::options(), ['agent_plugin_tools_version' => '1', 'agent_mcp_namespaces' => []]), false);
        foreach (['search-products', 'get-product', 'order-summary', 'prepare-product-update', 'prepare-product-trash'] as $name) {
            self::assertNotContains('oyiso/' . $name, Oyiso_Agent_Abilities::allowed());
            self::assertWPError((new Oyiso_Agent_Tools($run))->execute($name, ['product_id' => $product->get_id()]));
        }
        self::assertSame([], Oyiso_Agent_Permissions::granted(true));
        self::assertWPError((new Oyiso_Agent_Tools($run, 'apply'))->execute('apply-product-update', ['proposal_id' => $proposal]));
        self::assertGreaterThanOrEqual(400, $this->request('POST', 'runs/' . $run['id'] . '/changes/' . $proposal, ['action' => 'apply'])->get_status());
        self::assertSame($before, Oyiso_Agent_Tools::fields(wc_get_product($product->get_id())));
        self::assertSame('pending', Oyiso_Agent_Store::get($run['id'])['changes'][0]['status']);
    }

    public function testWooCommerceRevocationAfterAModelRequestBlocksEvenCachedReadTools(): void
    {
        global $wpdb;
        $run = $this->task(); $product = $this->product();
        $options = array_replace(Oyiso_Agent_Config::options(), ['agent_plugin_tools_version' => '1', 'agent_mcp_namespaces' => ['oyiso-woocommerce', 'woocommerce']]);
        update_option('oyiso', $options, false);
        self::assertContains('oyiso/get-product', Oyiso_Agent_Abilities::allowed());
        $revoked = array_replace($options, ['agent_mcp_namespaces' => []]);
        $wpdb->update($wpdb->options, ['option_value' => maybe_serialize($revoked)], ['option_name' => 'oyiso']);
        try {
            self::assertSame($options, get_option('oyiso'));
            self::assertWPError((new Oyiso_Agent_Tools($run))->execute('get-product', ['product_id' => $product->get_id()]));
            self::assertSame([], Oyiso_Agent_Permissions::granted(true));
        } finally { wp_cache_delete('oyiso', 'options'); wp_cache_delete('alloptions', 'options'); }
    }

    private static function assertWPError(mixed $value): void
    {
        self::assertInstanceOf(WP_Error::class, $value);
    }
}
