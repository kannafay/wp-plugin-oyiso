<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\AbstractProvider;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;

abstract class Oyiso_Agent_Provider extends AbstractProvider
{
    protected const PRESET = '';

    protected static function createProviderMetadata(): ProviderMetadata
    {
        $preset = Oyiso_Agent_Connectors::presets()[static::PRESET];
        return new ProviderMetadata('oyiso-' . static::PRESET, $preset['name'] . ' · Oyiso', ProviderTypeEnum::cloud(), $preset['docs'], RequestAuthenticationMethod::apiKey(), '在 Oyiso → AI Agent → 模型配置中管理地址、模型和 API Key。');
    }

    protected static function createModel(ModelMetadata $modelMetadata, ProviderMetadata $providerMetadata): ModelInterface
    {
        return new Oyiso_Agent_Compatible_Model($modelMetadata, $providerMetadata);
    }

    protected static function createProviderAvailability(): ProviderAvailabilityInterface
    {
        return new Oyiso_Agent_Provider_Availability(static::PRESET);
    }

    protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface
    {
        return new Oyiso_Agent_Model_Directory(static::PRESET);
    }
}

final class Oyiso_Agent_DeepSeek_Provider extends Oyiso_Agent_Provider
{
    protected const PRESET = 'deepseek';
}

final class Oyiso_Agent_Gemini_Provider extends Oyiso_Agent_Provider
{
    protected const PRESET = 'gemini';
}

final class Oyiso_Agent_Agnes_Provider extends Oyiso_Agent_Provider
{
    protected const PRESET = 'agnes';
}

final class Oyiso_Agent_Custom_Provider extends Oyiso_Agent_Provider
{
    protected const PRESET = 'custom';

    protected static function createProviderMetadata(): ProviderMetadata
    {
        return new ProviderMetadata('oyiso-custom', 'Oyiso 模型连接', ProviderTypeEnum::cloud(), 'https://developer.wordpress.org/', RequestAuthenticationMethod::apiKey());
    }
}

final readonly class Oyiso_Agent_Provider_Availability implements ProviderAvailabilityInterface
{
    public function __construct(private string $id)
    {
    }

    public function isConfigured(): bool
    {
        return !is_wp_error(Oyiso_Agent_Connectors::load($this->id));
    }
}

final readonly class Oyiso_Agent_Model_Directory implements ModelMetadataDirectoryInterface
{
    public function __construct(private string $id)
    {
    }

    public function listModelMetadata(): array
    {
        $connection = Oyiso_Agent_Connectors::load($this->id, false);
        if (is_wp_error($connection)) {
            return [];
        }
        return [self::metadata($connection->model)];
    }

    public static function metadata(string $model): ModelMetadata
    {
        $options = array_map(
            static fn(OptionEnum $option): SupportedOption => new SupportedOption($option),
            [OptionEnum::systemInstruction(), OptionEnum::maxTokens(), OptionEnum::functionDeclarations()]
        );
        $options[] = new SupportedOption(OptionEnum::inputModalities(), [[ModalityEnum::text()]]);
        $options[] = new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::text()]]);
        return new ModelMetadata($model, $model, [CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory()], $options);
    }

    public function hasModelMetadata(string $modelId): bool
    {
        return ($this->listModelMetadata()[0] ?? null)?->getId() === $modelId;
    }

    public function getModelMetadata(string $modelId): ModelMetadata
    {
        $metadata = $this->listModelMetadata()[0] ?? null;
        if ($metadata === null || $metadata->getId() !== $modelId) {
            throw new InvalidArgumentException('模型 ID 与 Oyiso 中保存的配置不一致。');
        }
        return $metadata;
    }
}

/** @phpstan-import-type MessageData from AbstractOpenAiCompatibleTextGenerationModel
 * @phpstan-import-type ToolCallData from AbstractOpenAiCompatibleTextGenerationModel
 * @phpstan-type CompatibleToolCallData array{type?:string, id?:string, function?:array{name?:string, arguments:string|array<string,mixed>}, extra_content?:mixed}
 */
final class Oyiso_Agent_Compatible_Model extends AbstractOpenAiCompatibleTextGenerationModel
{
    public function __construct(ModelMetadata $metadata, ProviderMetadata $providerMetadata, private readonly ?string $connectionId = null)
    {
        parent::__construct($metadata, $providerMetadata);
    }

    protected function createRequest(HttpMethodEnum $method, string $path, array $headers = [], $data = null): Request
    {
        $connection = $this->connectionId !== null ? Oyiso_Agent_Connections::load($this->connectionId, $this->metadata()->getId()) : Oyiso_Agent_Connectors::load(Oyiso_Agent_Connectors::preset($this->providerMetadata()->getId()));
        if (is_wp_error($connection)) {
            throw new InvalidArgumentException($connection->get_error_message());
        }
        if ($connection->managed) {
            $this->setRequestAuthentication(new ApiKeyRequestAuthentication(Oyiso_Agent_Config::secret(Oyiso_Agent_Connections::secretName($connection->id))));
        }
        $configuredOptions = $this->getRequestOptions();
        $options = $configuredOptions !== null ? clone $configuredOptions : new RequestOptions();
        $options->setMaxRedirects(0);
        $options->setTimeout($options->getTimeout() ?? 45.0);
        return new Request($method, $connection->url . '/' . ltrim($path, '/'), $headers, $data, $options);
    }

    protected function prepareMessagesParam(array $messages, ?string $systemInstruction = null): array
    {
        $prepared = parent::prepareMessagesParam($messages, $systemInstruction);
        $offset = $systemInstruction ? 1 : 0;
        foreach ($messages as $index => $message) {
            $thought = '';
            $hasThought = false;
            foreach ($message->getParts() as $part) {
                if ($part->getChannel()->isThought()) {
                    $hasThought = true;
                    $thought .= $part->getText() ?? '';
                }
            }
            if ($message->getRole()->isModel() && $hasThought) {
                $prepared[$index + $offset]['reasoning_content'] = $thought;
            }
        }
        // Text-only Chat Completions providers also accept strings; this avoids empty content arrays on tool calls.
        foreach ($prepared as &$message) {
            if (is_array($message['content'] ?? null)) {
                $content = '';
                foreach ($message['content'] as $part) {
                    if (!is_array($part) || !is_string($part['text'] ?? null)) {
                        throw new InvalidArgumentException('Oyiso 模型预设当前仅支持文本和工具调用。');
                    }
                    $content .= $part['text'];
                }
                $message['content'] = $content;
            }
        }
        unset($message);
        return $prepared;
    }

    protected function getMessagePartToolCallData(MessagePart $part): ?array
    {
        $data = parent::getMessagePartToolCallData($part);
        $signature = $part->getThoughtSignature();
        if ($data !== null && $signature !== null) {
            $data['extra_content'] = ['google' => ['thought_signature' => $signature]];
        }
        return $data;
    }

    /** @param CompatibleToolCallData $toolCallData */
    protected function parseResponseChoiceMessageToolCallPart(array $toolCallData): ?MessagePart
    {
        $part = parent::parseResponseChoiceMessageToolCallPart($toolCallData);
        $extra = $toolCallData['extra_content'] ?? null;
        $google = is_array($extra) ? ($extra['google'] ?? null) : null;
        $signature = is_array($google) ? ($google['thought_signature'] ?? null) : null;
        return $part !== null && is_string($signature) && $part->getFunctionCall() !== null ? new MessagePart($part->getFunctionCall(), $part->getChannel(), $signature) : $part;
    }
}
