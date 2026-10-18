<?php

declare(strict_types=1);

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
    http_response_code(404);
    exit;
}

use PHPUnit\Framework\TestCase;
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

    public function __construct(private array $turns)
    {
    }

    public function generate(array $history, array $abilities): Oyiso_Agent_Turn|WP_Error
    {
        $this->calls++;
        $this->allowed = $abilities;
        $turn = array_shift($this->turns);
        return $turn instanceof Closure ? $turn() : $turn;
    }
}

final class AgentTest extends TestCase
{
    private array $options;
    private mixed $secrets;
    private array $tasks = [];
    private array $products = [];
    private array $users = [];
    private ?Closure $http = null;

    protected function setUp(): void
    {
        wp_set_current_user(OYISO_AGENT_TEST_ADMIN);
        $this->options = Oyiso_Agent_Config::options();
        $this->secrets = get_option('oyiso_agent_secrets', false);
        update_option('oyiso', array_merge($this->options, ['agent_enabled' => true, 'agent_max_steps' => '8', 'agent_provider' => '', 'agent_model' => '', 'agent_search_provider' => '', 'agent_keywords_provider' => '']), false);
    }

    protected function tearDown(): void
    {
        Oyiso_Agent_Abilities::bind(null);
        wp_set_current_user(OYISO_AGENT_TEST_ADMIN);
        if ($this->http) remove_filter('pre_http_request', $this->http, 10);
        foreach ($this->tasks as $id) {
            wp_clear_scheduled_hook(Oyiso_Agent_Runner::HOOK, [$id]);
            as_unschedule_all_actions(Oyiso_Agent_Runner::HOOK, [$id], 'oyiso-agent');
            Oyiso_Agent_Store::unlock('run_' . $id);
            delete_option('oyiso_agent_lock_run_' . $id);
            wp_delete_post($id, true);
        }
        foreach ($this->products as $id) {
            delete_option('oyiso_agent_lock_product_' . $id);
            wc_get_product($id)?->delete(true);
        }
        foreach ($this->users as $id) wp_delete_user($id);
        update_option('oyiso', $this->options, false);
        if ($this->secrets === false) delete_option('oyiso_agent_secrets');
        else update_option('oyiso_agent_secrets', $this->secrets, false);
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

    private function http(Closure $callback): void
    {
        $this->http = $callback;
        add_filter('pre_http_request', $callback, 10, 3);
    }

    private static function response(array|string $body, int $status = 200, string $type = 'application/json'): array
    {
        return ['headers' => ['content-type' => $type], 'body' => is_array($body) ? wp_json_encode($body) : $body, 'response' => ['code' => $status, 'message' => 'fixture'], 'cookies' => [], 'filename' => null];
    }

    public function testAbilitiesAreRegisteredAndWriteAbilitiesAreNotModelTools(): void
    {
        foreach (['get-product', 'search-products', 'order-summary', 'search-web', 'read-webpage', 'get-keyword-metrics', 'prepare-product-update', 'apply-product-update', 'revert-product-update'] as $name) {
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
        $product->set_description('Changed during research'); $product->save();
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
        $response = $this->request('POST', 'runs', ['message' => 'Read a product']);
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
        $gateway = new OyisoAgentScriptedModel([$this->turn('', [['get-product', ['product_id' => $product->get_id()]]]), $this->turn('Read complete')]);
        $runner = new Oyiso_Agent_Runner($gateway);
        $runner->step($run['id']);
        self::assertSame('queued', Oyiso_Agent_Store::get($run['id'])['status']);
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
        $saved = Oyiso_Agent_Config::saveOptions(['agent_search_key' => 'fixture-secret-key', 'agent_keywords_password' => 'fixture-password']);
        self::assertSame('', $saved['agent_search_key']); self::assertSame('fixture-secret-key', Oyiso_Agent_Config::secret('search_key'));
        self::assertStringNotContainsString('fixture-secret-key', serialize(get_option('oyiso_agent_secrets')));
        Oyiso_Agent_Config::saveOptions(['agent_search_key' => '']);
        self::assertSame('fixture-secret-key', Oyiso_Agent_Config::secret('search_key'));
        self::assertStringNotContainsString('fixture-secret-key', Oyiso_Agent_Config::error('Bearer abc fixture-secret-key token=foo'));
        Oyiso_Agent_Config::saveOptions(['agent_search_key_clear' => true]);
        self::assertFalse(Oyiso_Agent_Config::hasSecret('search_key'));
    }

    public function testSearchReturnsSourceAndNeverSendsKeyToModelOutput(): void
    {
        Oyiso_Agent_Config::saveOptions(['agent_search_key' => 'fixture-search-key']);
        update_option('oyiso', array_merge(Oyiso_Agent_Config::options(), ['agent_search_provider' => 'tavily']));
        $this->http(function ($pre, $args, $url) {
            self::assertSame('https://api.tavily.com/search', $url);
            self::assertSame('Bearer fixture-search-key', $args['headers']['Authorization']);
            return self::response(['results' => [['url' => 'https://93.184.215.14/product', 'title' => 'Manufacturer', 'content' => 'Verified product information']]]);
        });
        $result = Oyiso_Agent_Research::search('product model');
        self::assertSame('Tavily', $result['source']); self::assertCount(1, $result['results']);
        self::assertStringNotContainsString('fixture-search-key', wp_json_encode($result));
    }

    public function testWebpageReaderStripsScriptsAndBlocksPrivateRedirects(): void
    {
        foreach (['http://example.com', 'https://localhost', 'https://127.0.0.1', 'https://10.0.0.1', 'https://169.254.169.254', 'https://user:pass@example.com', 'https://example.com:8443', 'https://vape.local', 'https://[::1]'] as $url) {
            self::assertFalse(Oyiso_Agent_Research::publicUrl($url));
        }
        $this->http(static fn() => self::response('<html><head><title>Source</title><script>SECRET_SCRIPT</script></head><body><h1>Product facts</h1><p>Capacity 100</p><nav>SECRET_NAV</nav></body></html>', 200, 'text/html'));
        $page = Oyiso_Agent_Research::webpage('https://93.184.215.14/product');
        self::assertSame('Source', $page['title']); self::assertStringContainsString('Product facts', $page['content']);
        self::assertStringNotContainsString('SECRET_SCRIPT', $page['content']); self::assertStringNotContainsString('SECRET_NAV', $page['content']);
        remove_filter('pre_http_request', $this->http, 10);
        $this->http = static fn() => ['headers' => ['location' => 'https://127.0.0.1/secret'], 'body' => '', 'response' => ['code' => 302, 'message' => 'redirect'], 'cookies' => [], 'filename' => null];
        add_filter('pre_http_request', $this->http, 10, 3);
        self::assertWPError(Oyiso_Agent_Research::webpage('https://93.184.215.14/product'));
    }

    public function testKeywordMetricsKeepRegionLanguageAndMetricSemantics(): void
    {
        Oyiso_Agent_Config::saveOptions(['agent_keywords_password' => 'fixture-password']);
        update_option('oyiso', array_merge(Oyiso_Agent_Config::options(), ['agent_keywords_provider' => 'dataforseo', 'agent_keywords_login' => 'fixture-login', 'agent_keywords_location' => '2616', 'agent_keywords_language' => 'pl']));
        $this->http(function ($pre, $args, $url) {
            $body = json_decode($args['body'], true);
            self::assertSame(2616, $body[0]['location_code']); self::assertSame('pl', $body[0]['language_code']);
            return self::response(['tasks' => [['status_code' => 20000, 'result' => [['keyword' => 'example', 'search_volume' => 90, 'competition' => 'LOW', 'competition_index' => 10, 'cpc' => 0.2]]]]]);
        });
        $result = Oyiso_Agent_Research::keywords(['example']);
        self::assertSame(90, $result['items'][0]['search_volume']);
        self::assertStringContainsString('广告', $result['notice']); self::assertSame(2616, $result['location_code']);
    }

    public function testMissingServicesAndInvalidDatesReturnExplicitErrors(): void
    {
        self::assertWPError(Oyiso_Agent_Research::search('not configured'));
        self::assertWPError(Oyiso_Agent_Research::keywords(['not configured']));
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

    public function testSettingsHaveOneParentAndFourChildrenAndRenderWithoutNestedForms(): void
    {
        $sections = CSF::$args['sections']['oyiso'];
        $children = array_values(array_filter($sections, static fn(array $section): bool => ($section['parent'] ?? '') === 'ai-agent'));
        self::assertSame(['agent-chat', 'agent-model', 'agent-research', 'agent-records'], array_column($children, 'id'));
        ob_start(); Oyiso_Agent_Admin::chat(); Oyiso_Agent_Admin::history(); Oyiso_Agent_Admin::connection(); $html = ob_get_clean();
        self::assertStringContainsString('oyiso-agent-chat', $html); self::assertStringContainsString('oyiso-agent-history', $html);
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
            self::assertCount(5, $sections);
            self::assertSame('ai-agent', $sections[0]['id']);
            $provider = array_values(array_filter($sections[2]['fields'], static fn(array $field): bool => ($field['id'] ?? '') === 'agent_provider'))[0];
            ob_start(); CSF::field($provider, '', 'oyiso'); $select = ob_get_clean();
            self::assertStringContainsString('自动选择', $select);
            self::assertStringNotContainsString('value="0"', $select);
        } finally { CSF::$args['sections'] = $oldSections; CSF::$fields = $oldFields; }
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

    public function testTransportFailuresDoNotReturnFabricatedResearch(): void
    {
        Oyiso_Agent_Config::saveOptions(['agent_search_key' => 'fixture-key']);
        update_option('oyiso', array_merge(Oyiso_Agent_Config::options(), ['agent_search_provider' => 'tavily']));
        $this->http(static fn() => self::response([], 401));
        self::assertWPError(Oyiso_Agent_Research::search('unauthorized'));
        remove_filter('pre_http_request', $this->http, 10);
        $this->http = static fn() => self::response('invalid-json');
        add_filter('pre_http_request', $this->http, 10, 3);
        self::assertWPError(Oyiso_Agent_Research::search('invalid response'));
        self::assertWPError(Oyiso_Agent_Research::webpage('https://93.184.215.14/data'));
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
        $run = $this->task(); Oyiso_Agent_Runner::message($run, 'Read a webpage');
        $this->http(function () use ($run) {
            $checkpoint = Oyiso_Agent_Store::get($run['id']);
            self::assertCount(1, $checkpoint['messages']);
            self::assertSame('running', $checkpoint['status']);
            return self::response('<p>Manufacturer facts</p>', 200, 'text/html');
        });
        (new Oyiso_Agent_Runner(new OyisoAgentScriptedModel([$this->turn('', [['read-webpage', ['url' => 'https://93.184.215.14/source']]])])))->step($run['id']);
        $fresh = Oyiso_Agent_Store::get($run['id']);
        self::assertCount(3, $fresh['messages']);
        self::assertContains('资料来源', array_column($fresh['events'], 'label'));
    }

    public function testRestStatusAndCompletedTaskCanContinueConversation(): void
    {
        $state = $this->request('GET', 'status')->get_data();
        self::assertTrue($state['enabled']); self::assertTrue($state['supported']);
        self::assertArrayNotHasKey('search_key', $state);
        $run = $this->task(); $run['status'] = 'completed'; Oyiso_Agent_Store::save($run);
        self::assertSame(201, $this->request('POST', 'runs', ['message' => 'Follow-up', 'run_id' => $run['id']])->get_status());
        self::assertSame(200, $this->request('GET', 'runs/' . $run['id'])->get_status());
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

    private static function assertWPError(mixed $value): void
    {
        self::assertInstanceOf(WP_Error::class, $value);
    }
}
