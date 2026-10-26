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
        return Oyiso_Agent_Context_Presets::limit($preset, $model);
    }

    /** @param AgentConnection $row
     * @return array<string, ContextModel>
     */
    public static function connectionModels(array $row): array
    {
        $models = [];
        $provider = Oyiso_Agent_Context_Presets::provider($row['preset'], $row['url']);
        foreach ($row['models'] as $model) {
            $provided = $row['context_windows'][$model] ?? null;
            $limit = self::validLimit($provided) ? $provided : self::presetLimit($provider, $model);
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
