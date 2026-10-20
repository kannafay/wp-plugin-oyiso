<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use WordPress\AiClient\Tools\DTO\FunctionResponse;

final readonly class Oyiso_Agent_Connection
{
    public function __construct(public string $id, public string $name, public string $url, public string $model, public bool $managed = false, public string $protocol = 'openai_chat')
    {
    }
}

final class Oyiso_Agent_Connectors
{
    /** @return array<string, array{name:string, url:string}> */
    public static function protocols(): array
    {
        return [
            'openai_responses' => ['name' => 'OpenAI Responses', 'url' => 'https://api.openai.com/v1'],
            'openai_chat' => ['name' => 'OpenAI Compatible', 'url' => 'https://api.openai.com/v1'],
            'anthropic_messages' => ['name' => 'Anthropic', 'url' => 'https://api.anthropic.com/v1'],
            'gemini' => ['name' => 'Google Generative AI', 'url' => 'https://generativelanguage.googleapis.com/v1beta'],
        ];
    }

    /** @return array<string, array{name:string, url:string, model:string, docs:string, protocol:string}> */
    public static function connectionPresets(): array
    {
        $presets = ['openai' => ['name' => 'OpenAI', 'url' => self::protocols()['openai_responses']['url'], 'model' => '', 'docs' => 'https://developers.openai.com/api/docs', 'protocol' => 'openai_responses']];
        foreach (self::presets() as $id => $preset) {
            $row = $preset + ['protocol' => $id === 'deepseek' ? 'openai_responses' : 'openai_chat'];
            // Only new connections use the native Gemini API; legacy endpoints keep their protocol.
            if ($id === 'gemini') {
                $row['url'] = self::protocols()['gemini']['url'];
                $row['protocol'] = 'gemini';
                $row['docs'] = 'https://ai.google.dev/gemini-api/docs';
            }
            $presets[$id] = $row;
        }
        return $presets;
    }

    /** @return array<string, array{name:string, url:string, model:string, docs:string}> */
    public static function presets(): array
    {
        return [
            'deepseek' => ['name' => 'DeepSeek', 'url' => 'https://api.deepseek.com', 'model' => 'deepseek-flash', 'docs' => 'https://api-docs.deepseek.com/zh-cn/'],
            'gemini' => ['name' => 'Gemini', 'url' => 'https://generativelanguage.googleapis.com/v1beta/openai', 'model' => 'gemini-3.8-flash', 'docs' => 'https://ai.google.dev/gemini-api/docs/openai'],
            'agnes' => ['name' => 'Agnes AI', 'url' => 'https://api.agnes-ai.cn/v1', 'model' => 'agnes-2.5-flash', 'docs' => 'https://agnes-ai.cn/zh-Hans/docs/agnes-25-flash'],
        ];
    }

    public static function preset(string $provider): string
    {
        $id = str_starts_with($provider, 'oyiso-') ? substr($provider, 6) : '';
        return isset(self::presets()[$id]) ? $id : '';
    }

    public static function load(string $id, bool $requireKey = true): Oyiso_Agent_Connection|WP_Error
    {
        $preset = self::presets()[$id] ?? null;
        if ($preset === null) {
            return new WP_Error('agent_connector_unknown', '模型预设不存在。', ['status' => 400]);
        }
        $url = rtrim(Oyiso_Agent_Config::text($id . '_url') ?: $preset['url'], '/');
        $model = Oyiso_Agent_Config::text($id . '_model') ?: $preset['model'];
        $connectionId = $id;
        $secret = $id . '_key';
        $name = $preset['name'];
        $managed = is_array(get_option('oyiso_agent_connections', null));
        $protocol = 'openai_chat';
        if ($managed) {
            $matching = array_values(array_filter(Oyiso_Agent_Connections::all(), static fn(array $row): bool => $row['id'] === 'legacy-' . $id));
            if ($matching === []) {
                return new WP_Error('agent_connector_retired', '此预设连接已迁移，请选择已保存的模型连接。', ['status' => 400]);
            }
            $connectionId = $matching[0]['id'];
            $name = $matching[0]['name'];
            $url = $matching[0]['url'];
            $model = $matching[0]['models'][0];
            $secret = Oyiso_Agent_Connections::secretName($connectionId);
            $protocol = $matching[0]['protocol'];
        }
        if (self::validateUrl($url) !== null || preg_match('/[\x00-\x20\x7f]/', $model) || strlen($model) > 200 || !isset(self::protocols()[$protocol])) {
            return new WP_Error('agent_connector_invalid', '请检查模型预设的 HTTPS API 地址和模型 ID。', ['status' => 400]);
        }
        if ($requireKey && Oyiso_Agent_Config::secret($secret) === '') {
            return new WP_Error('agent_connector_key', '请先在「模型配置」中保存此预设的 API Key。', ['status' => 400]);
        }
        return new Oyiso_Agent_Connection($connectionId, $name, $url, $model, $managed, $protocol);
    }

    public static function validateUrl(mixed $value): ?string
    {
        if (!is_string($value)) {
            return '请输入有效的 HTTPS API 地址。';
        }
        if (trim($value) === '') {
            return null;
        }
        $parts = wp_parse_url(trim($value));
        $host = is_array($parts) && is_string($parts['host'] ?? null) ? strtolower($parts['host']) : '';
        if (strlen($value) > 1000 || !is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443)
            || preg_match('/[\x00-\x20\x7f\\\\]/', $value) || !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || $host === 'localhost' || !str_contains($host, '.') || preg_match('/\.(?:local|localhost|internal|test)\.?$/', $host)
            || (filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))) {
            return 'API 地址须为公开 HTTPS 地址，且不含账号、查询参数或片段。';
        }
        return null;
    }

    public static function validateKey(mixed $value): ?string
    {
        return !is_string($value) || strlen($value) > 4096 || preg_match('/[\x00-\x20\x7f]/', $value) ? 'API Key 不能包含空白字符，且最长为 4096 个字符。' : null;
    }

    public static function register(): void
    {
        if (!Oyiso_Agent_Config::supported()) {
            return;
        }
        require_once __DIR__ . '/providers.php';
        $registry = AiClient::defaultRegistry();
        foreach (['deepseek' => Oyiso_Agent_DeepSeek_Provider::class, 'gemini' => Oyiso_Agent_Gemini_Provider::class, 'agnes' => Oyiso_Agent_Agnes_Provider::class] as $id => $class) {
            if (!$registry->hasProvider('oyiso-' . $id)) {
                $registry->registerProvider($class);
            }
            $connection = self::load($id, false);
            $secret = !is_wp_error($connection) && $connection->managed ? Oyiso_Agent_Connections::secretName($connection->id) : $id . '_key';
            $registry->setProviderRequestAuthentication('oyiso-' . $id, new ApiKeyRequestAuthentication(Oyiso_Agent_Config::secret($secret)));
        }
        if (!$registry->hasProvider('oyiso-custom')) {
            $registry->registerProvider(Oyiso_Agent_Custom_Provider::class);
        }
    }

    public static function connectors(WP_Connector_Registry $registry): void
    {
        foreach (self::presets() as $id => $preset) {
            $registry->unregister('oyiso-' . $id);
            $registry->register('oyiso-' . $id, [
                'name' => $preset['name'] . ' · Oyiso',
                'description' => '在 Oyiso → AI Agent → 模型配置中管理地址、模型和加密 API Key。',
                'type' => 'ai_provider',
                // Oyiso supplies the provider authentication; no duplicate plaintext key setting is registered.
                'authentication' => ['method' => 'none'],
            ]);
        }
    }

    /** @return array{provider:string, model:string, message:string}|WP_Error */
    public static function test(string $id, string $model = ''): array|WP_Error
    {
        if (!Oyiso_Agent_Config::supported()) {
            return new WP_Error('agent_connector_unsupported', '模型连接需要 WordPress 7.0 或以上版本。', ['status' => 400]);
        }
        $connection = isset(self::presets()[$id]) ? self::load($id) : Oyiso_Agent_Connections::load($id, $model);
        if (is_wp_error($connection)) {
            return $connection;
        }
        try {
            self::register();
            $messages = [new UserMessage([new MessagePart('Call oyiso_connection_probe with value "ok". After its result is returned, answer OK without calling another tool.')])];
            $first = self::probe($connection, $messages)->generate_text_result();
            if (is_wp_error($first)) {
                return self::testError($first->get_error_message());
            }
            $message = $first->getCandidates()[0]->getMessage();
            $calls = array_values(array_filter($message->getParts(), static fn(MessagePart $part): bool => $part->getType()->isFunctionCall()));
            $call = count($calls) === 1 ? $calls[0]->getFunctionCall() : null;
            if ($call === null || $call->getId() === null || $call->getName() !== 'oyiso_connection_probe' || $call->getArgs() !== ['value' => 'ok']) {
                return self::testError('已收到模型响应，但工具调用测试未通过。请检查模型是否支持工具调用。');
            }
            $messages[] = $message;
            $messages[] = new UserMessage([new MessagePart(new FunctionResponse($call->getId(), $call->getName(), ['value' => 'ok']))]);
            $second = self::probe($connection, $messages)->generate_text_result();
            if (is_wp_error($second)) {
                return self::testError($second->get_error_message());
            }
            $reply = $second->getCandidates()[0]->getMessage()->getParts();
            $text = implode('', array_map(static fn(MessagePart $part): string => $part->getChannel()->isThought() ? '' : ($part->getText() ?? ''), $reply));
            if (trim($text) === '' || count(array_filter($reply, static fn(MessagePart $part): bool => $part->getType()->isFunctionCall())) > 0) {
                return self::testError('模型未能完成工具结果的回传测试，请更换模型或检查接口兼容性。');
            }
            return ['provider' => $connection->name, 'model' => $connection->model, 'message' => '连接成功，工具调用和结果回传测试通过。'];
        } catch (Throwable $error) {
            return self::testError($error->getMessage());
        }
    }

    /** @param list<Message> $messages */
    private static function probe(Oyiso_Agent_Connection $connection, array $messages): WP_AI_Client_Prompt_Builder
    {
        $options = new RequestOptions();
        $options->setTimeout(20.0);
        $options->setMaxRedirects(0);
        $model = $connection->managed ? Oyiso_Agent_Connections::model($connection->id, $connection->model) : AiClient::defaultRegistry()->getProviderModel('oyiso-' . $connection->id, $connection->model);
        if (is_wp_error($model)) {
            throw new RuntimeException($model->get_error_message());
        }
        return wp_ai_client_prompt($messages)->using_model($model)->using_max_tokens(2048)->using_request_options($options)
            ->using_function_declarations(new FunctionDeclaration('oyiso_connection_probe', 'Return the supplied value. This is a harmless connectivity test.', ['type' => 'object', 'properties' => ['value' => ['type' => 'string', 'enum' => ['ok']]], 'required' => ['value']]));
    }

    private static function testError(string $message): WP_Error
    {
        return new WP_Error('agent_connector_test', Oyiso_Agent_Config::error($message), ['status' => 400]);
    }
}
