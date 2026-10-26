<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/** Preserve JSON Schema object maps without turning required/enum/default lists into objects. */
final class Oyiso_Agent_Tool_Schema
{
    /** @param array<string, mixed>|null $schema
     * @return array<string, mixed>
     */
    public static function parameters(?array $schema): array
    {
        return self::normalize($schema === null || $schema === [] ? ['type' => 'object'] : $schema);
    }

    /** @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private static function normalize(array $schema): array
    {
        if (($schema['type'] ?? null) === 'object' && !isset($schema['properties'])) $schema['properties'] = new stdClass();
        foreach (['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas'] as $keyword) {
            if (!is_array($schema[$keyword] ?? null)) continue;
            $map = new stdClass();
            foreach ($schema[$keyword] as $name => $value) $map->{(string) $name} = self::value($value);
            $schema[$keyword] = $map;
        }
        foreach (['items', 'additionalItems', 'contains', 'additionalProperties', 'unevaluatedProperties', 'unevaluatedItems', 'propertyNames', 'not', 'if', 'then', 'else'] as $keyword) {
            if (is_array($schema[$keyword] ?? null)) $schema[$keyword] = self::value($schema[$keyword]);
        }
        foreach (['allOf', 'anyOf', 'oneOf', 'prefixItems'] as $keyword) {
            if (is_array($schema[$keyword] ?? null)) $schema[$keyword] = array_map([self::class, 'value'], $schema[$keyword]);
        }
        return $schema;
    }

    private static function value(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if ($value === []) return new stdClass();
        if (array_is_list($value)) return array_map([self::class, 'value'], $value);
        $schema = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) return $value;
            $schema[$key] = $item;
        }
        return self::normalize($schema);
    }
}
