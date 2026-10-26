<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/** Documented chat/coding model IDs, checked 2026-10-27. See docs/ai-model-contexts.md. */
final class Oyiso_Agent_Context_Presets
{
    /** @var array<string, array<int, list<string>>> */
    private const MODELS = [
        // https://developers.openai.com/api/docs/models — each variant has its own specification.
        'openai' => [
            1050000 => [
                'gpt-6.1-sol', 'gpt-6.1-terra', 'gpt-6-astra', 'gpt-6-sol', 'gpt-6-luna',
                'gpt-5.6-sol', 'gpt-5.6-terra', 'gpt-5.6-luna', 'gpt-5.5', 'gpt-5.5-2026-04-23',
                'gpt-5.4', 'gpt-5.4-2026-03-05', 'gpt-5.4-pro',
            ],
            1047576 => [
                'gpt-4.1', 'gpt-4.1-2025-04-14', 'gpt-4.1-mini', 'gpt-4.1-mini-2025-04-14',
                'gpt-4.1-nano', 'gpt-4.1-nano-2025-04-14',
            ],
            400000 => [
                'gpt-5.4-mini', 'gpt-5.4-mini-2026-03-17', 'gpt-5.4-nano', 'gpt-5.4-nano-2026-03-17',
                'gpt-5.3-codex', 'gpt-5.2', 'gpt-5.2-2025-12-11', 'gpt-5.2-pro', 'gpt-5.2-codex',
                'gpt-5.1', 'gpt-5.1-2025-11-13', 'gpt-5.1-codex', 'gpt-5.1-codex-max', 'gpt-5.1-codex-mini',
                'gpt-5', 'gpt-5-2025-08-07', 'gpt-5-pro', 'gpt-5-mini', 'gpt-5-nano', 'gpt-5-codex', 'chat-latest',
            ],
            200000 => ['o3', 'o3-2025-04-16', 'o3-mini', 'o3-mini-2025-01-31', 'o3-pro', 'o4-mini', 'o4-mini-2025-04-16', 'o1'],
            128000 => ['gpt-5-chat-latest', 'gpt-5.1-chat-latest', 'gpt-5.2-chat-latest', 'gpt-4o', 'gpt-4o-2024-08-06', 'gpt-4o-mini', 'gpt-4o-mini-2024-07-18', 'o1-mini'],
        ],
        // https://platform.claude.com/docs/en/build-with-claude/context-windows
        'anthropic' => [
            1000000 => [
                'claude-fable-5-1', 'claude-mythos-5-1', 'claude-fable-5', 'claude-mythos-5', 'claude-mythos-preview',
                'claude-opus-5-5', 'claude-opus-5', 'claude-opus-4-8', 'claude-opus-4-7', 'claude-opus-4-6',
                'claude-sonnet-5-5', 'claude-sonnet-5', 'claude-sonnet-4-6', 'claude-haiku-5-5',
            ],
            200000 => ['claude-opus-4-5', 'claude-sonnet-4-5', 'claude-sonnet-4-5-20250929', 'claude-haiku-4-5', 'claude-haiku-4-5-20251001'],
        ],
        // https://ai.google.dev/gemini-api/docs/tokens — text models only, not Live/TTS/image variants.
        'gemini' => [
            1048576 => [
                'gemini-4-argon', 'gemini-3.8-flash', 'gemini-3.6-flash', 'gemini-3.5-flash-lite',
                'gemini-3.1-flash-lite', 'gemini-3.1-pro-preview', 'gemini-3-flash-preview', 'gemini-3-pro-preview',
                'gemini-2.5-pro', 'gemini-2.5-flash', 'gemini-2.5-flash-lite', 'gemini-2.0-flash', 'gemini-2.0-flash-lite',
            ],
        ],
        // https://api-docs.deepseek.com/quick_start/pricing/
        'deepseek' => [1048576 => ['deepseek-flash', 'deepseek-v4-flash', 'deepseek-v4-flash-vision-exp', 'deepseek-v4-pro']],
        // https://agnes-ai.cn/zh-Hans/docs/agnes-30-flash
        'agnes' => [524288 => ['agnes-2.5-flash', 'agnes-3.0-flash'], 1048576 => ['agnes-2.5-pro', 'agnes-2.5-pro-beta']],
        // https://www.alibabacloud.com/help/en/model-studio/text-generation-model/
        // No date-prefix matching: older Qwen Plus snapshots can have a smaller window.
        'qwen' => [
            1000000 => [
                'qwen3.8-max', 'qwen3.8-max-0902', 'qwen3.8-flash', 'qwen3.7-plus', 'qwen3.7-flash',
                'qwen3.7-max', 'qwen3.7-max-preview', 'qwen3.7-max-2026-06-08', 'qwen3.7-max-2026-05-20', 'qwen3.7-max-2026-05-17',
                'qwen3.6-plus', 'qwen3.6-flash', 'qwen3.6-flash-2026-04-16', 'qwen-plus', 'qwen-flash', 'qwen-turbo',
            ],
            262144 => ['qwen3.6-35b-a3b'],
            256000 => ['qwen3.6-max-preview'],
            131072 => ['qwen-max', 'qwq-plus'],
        ],
        // https://platform.kimi.ai/docs/api/models-overview
        'moonshot' => [
            1000000 => ['kimi-k3.1-pro', 'kimi-k3.1-pro-highspeed', 'kimi-k3'],
            256000 => ['kimi-k2.7-code', 'kimi-k2.7-code-highspeed', 'kimi-k2.6'],
        ],
        // https://docs.z.ai/guides/llm/glm-5.3 and /glm-5.2; no vendor-wide default.
        'zai' => [
            1000000 => ['glm-5.3', 'glm-5.3-flash', 'glm-5.3-flashx', 'glm-5.2'],
            200000 => ['glm-5', 'glm-4.7', 'glm-4.7-flash', 'glm-4.7-flashx'],
        ],
        // https://platform.minimax.io/docs/guides/text-generation
        'minimax' => [1000000 => ['minimax-m3.1-flash', 'minimax-m3']],
        // https://docs.x.ai/developers/models and /developers/migration/may-15-retirement
        'xai' => [
            500000 => ['grok-4.7', 'grok-4.6', 'grok-4.5', 'grok-4.5-latest', 'grok-build-latest'],
            1000000 => ['grok-4.3', 'grok-4.3-latest', 'grok-4-1-fast-reasoning', 'grok-4-1-fast-non-reasoning', 'grok-4-fast-reasoning', 'grok-4-fast-non-reasoning', 'grok-4-0709'],
        ],
        // https://docs.mistral.ai/models — conservative decimal values where only rounded k/M is published.
        'mistral' => [1000000 => ['mistral-large-4'], 256000 => ['mistral-medium-3-5', 'mistral-small-2603']],
        // https://ark.volcengine.com/region:cn-beijing/docs/agent-plan-personal-plan-overview
        // Dotted Coding Plan IDs and hyphenated public model IDs are distinct documented names.
        'doubao' => [
            1048576 => ['doubao-seed-2.1-pro', 'doubao-seed-2.1-lite', 'doubao-seed-2-1-lite', 'doubao-seed-evolving'],
            262144 => ['doubao-seed-2.0-mini', 'doubao-seed-2-0-mini'],
        ],
    ];

    public static function limit(string $provider, string $model): ?int
    {
        $model = strtolower(preg_replace('/^models\//', '', $model) ?? $model);
        foreach (self::MODELS[$provider] ?? [] as $limit => $models) {
            if (in_array($model, $models, true)) return $limit;
        }
        return null;
    }

    /** Identify custom connections by exact official hosts, never by display name or a host suffix. */
    public static function provider(string $preset, string $url): string
    {
        $host = wp_parse_url($url, PHP_URL_HOST);
        $host = is_string($host) ? strtolower($host) : '';
        return match ($host) {
            'api.openai.com' => 'openai',
            'api.anthropic.com' => 'anthropic',
            'generativelanguage.googleapis.com' => 'gemini',
            'api.deepseek.com' => 'deepseek',
            'api.agnes-ai.cn' => 'agnes',
            'dashscope.aliyuncs.com', 'dashscope-intl.aliyuncs.com', 'dashscope-us.aliyuncs.com' => 'qwen',
            'api.moonshot.ai', 'api.moonshot.cn' => 'moonshot',
            'api.z.ai', 'open.bigmodel.cn' => 'zai',
            'api.minimax.io', 'api.minimaxi.com', 'api.minimax.chat' => 'minimax',
            'api.x.ai', 'us.api.x.ai' => 'xai',
            'api.mistral.ai' => 'mistral',
            'ark.cn-beijing.volces.com' => 'doubao',
            default => $preset,
        };
    }
}
