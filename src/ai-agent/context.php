<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/** @phpstan-import-type AgentConnection from Oyiso_Agent_Connections
 * @phpstan-type ContextModel array{limit:int,source:string}
 */
final class Oyiso_Agent_Context
{
    /** @phpstan-assert-if-true int<1024, 16777216> $value */
    public static function validLimit(mixed $value): bool
    {
        return is_int($value) && $value >= 1024 && $value <= 16777216;
    }

    /** Official model specifications checked on 2026-10-27. Unknown model IDs stay unknown. */
    public static function presetLimit(string $preset, string $model): ?int
    {
        $model = strtolower(preg_replace('/^models\//', '', $model) ?? $model);
        // https://developers.openai.com/api/docs/models
        // https://api-docs.deepseek.com/api/list-models/
        // https://ai.google.dev/gemini-api/docs/tokens
        // https://agnes-ai.cn/zh-Hans/docs/agnes-30-flash
        $models = match ($preset) {
            'openai' => ['gpt-6.1-sol' => 1050000, 'gpt-6-astra' => 1050000, 'gpt-6-sol' => 1050000, 'gpt-6-luna' => 1050000, 'gpt-4.1' => 1047576, 'gpt-4.1-2025-04-14' => 1047576],
            'deepseek' => array_fill_keys(['deepseek-flash', 'deepseek-v4-flash', 'deepseek-v4-flash-vision-exp', 'deepseek-v4-pro'], 1048576),
            'gemini' => array_fill_keys(['gemini-4-argon', 'gemini-3.8-flash', 'gemini-3.6-flash', 'gemini-3.1-pro-preview', 'gemini-2.5-pro', 'gemini-2.5-flash', 'gemini-2.5-flash-lite'], 1048576),
            'agnes' => ['agnes-2.5-flash' => 524288, 'agnes-3.0-flash' => 524288, 'agnes-2.5-pro' => 1048576, 'agnes-2.5-pro-beta' => 1048576],
            default => [],
        };
        return $models[$model] ?? null;
    }

    /** @param AgentConnection $row
     * @return array<string, ContextModel>
     */
    public static function connectionModels(array $row): array
    {
        $models = [];
        foreach ($row['models'] as $model) {
            $provided = $row['context_windows'][$model] ?? null;
            $limit = self::validLimit($provided) ? $provided : self::presetLimit($row['preset'], $model);
            if ($limit !== null) $models[$model] = ['limit' => $limit, 'source' => self::validLimit($provided) ? 'catalog' : 'preset'];
        }
        return $models;
    }

    /** @return array<string, ContextModel> */
    public static function models(): array
    {
        $models = [];
        $choices = Oyiso_Agent_Connections::choices();
        foreach (Oyiso_Agent_Connections::all() as $row) {
            foreach (self::connectionModels($row) as $model => $context) {
                $selection = $row['id'] . '|' . $model;
                if (isset($choices[$selection])) $models[$selection] = $context;
            }
        }
        return $models;
    }
}
