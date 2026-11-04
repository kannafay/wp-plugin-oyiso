<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * @phpstan-import-type Row from Oyiso_Agent_Query_Source
 * @phpstan-import-type Field from Oyiso_Agent_Query_Source
 * @phpstan-import-type Value from Oyiso_Agent_Query_Source
 */
final readonly class Oyiso_Agent_Query_Filter
{
    /** @param Value|list<int|float|bool|null> $value
     * @param list<self> $children
     */
    private function __construct(public string $field = '', public string $op = 'all', public string $type = '', public string|int|float|bool|null|array $value = null, public array $children = [])
    {
    }

    /** @return list<string> */
    public static function operators(string $type): array
    {
        return match ($type) {
            'integer', 'number', 'date' => ['eq', 'ne', 'in', 'not_in', 'gt', 'gte', 'lt', 'lte', 'between', 'is_empty', 'is_not_empty'],
            'boolean' => ['eq', 'ne', 'is_empty', 'is_not_empty'],
            'list' => ['contains', 'in', 'not_in', 'is_empty', 'is_not_empty'],
            default => ['eq', 'ne', 'in', 'not_in', 'contains', 'starts_with', 'is_empty', 'is_not_empty'],
        };
    }

    /** @param array<string,Field> $fields */
    public static function parse(mixed $raw, array $fields, int $depth = 0, int &$count = 0): self
    {
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) throw new InvalidArgumentException('where 必须是条件对象。');
        if ($depth > 4 || ++$count > 40) throw new InvalidArgumentException('筛选条件过多或嵌套超过 4 层。');
        if ($raw === []) {
            if ($depth > 0) throw new InvalidArgumentException('all/any 中不能包含空条件。');
            return new self();
        }
        if (isset($raw['all']) || isset($raw['any'])) {
            $op = isset($raw['all']) ? 'all' : 'any';
            if (count($raw) !== 1 || !is_array($raw[$op]) || !array_is_list($raw[$op]) || $raw[$op] === []) throw new InvalidArgumentException('all/any 必须只包含非空条件列表。');
            $children = [];
            foreach ($raw[$op] as $child) $children[] = self::parse($child, $fields, $depth + 1, $count);
            return new self(op: $op, children: $children);
        }
        if (array_diff(array_keys($raw), ['field', 'op', 'value']) !== [] || !is_string($raw['field'] ?? null) || !isset($fields[$raw['field']]) || !is_string($raw['op'] ?? null)) throw new InvalidArgumentException('筛选字段或参数无效，请先读取数据源说明。');
        $field = $fields[$raw['field']]; $op = $raw['op'];
        if (!in_array($op, self::operators($field['type']), true)) throw new InvalidArgumentException('该字段不支持筛选操作符 ' . $op . '。');
        if (in_array($op, ['is_empty', 'is_not_empty'], true)) {
            if (array_key_exists('value', $raw)) throw new InvalidArgumentException('空值判断不接受 value。');
            return new self($raw['field'], $op, $field['type']);
        }
        if (!array_key_exists('value', $raw)) throw new InvalidArgumentException('筛选条件缺少 value。');
        $value = $raw['value'];
        if (in_array($op, ['in', 'not_in', 'between'], true)) {
            if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > 100 || ($op === 'between' && count($value) !== 2)) throw new InvalidArgumentException('in/not_in 需要 1–100 个值，between 需要两个边界值。');
            foreach ($value as $item) self::validateValue($item, $field);
            if ($op === 'between') {
                $start = $value[0]; $end = $value[1];
                if ($field['type'] === 'date' && is_string($start) && is_string($end)) {
                    if (strlen($start) === 10) $start .= ' 00:00:00';
                    if (strlen($end) === 10) $end .= ' 23:59:59';
                }
                if ($start > $end) throw new InvalidArgumentException('between 的起点不能大于终点。');
            }
        } else {
            self::validateValue($value, $field);
        }
        /** @var Value|list<int|float|bool|null> $value Validated scalar or list above. */
        return new self($raw['field'], $op, $field['type'], $value);
    }

    /** @param Field $field */
    private static function validateValue(mixed $value, array $field): void
    {
        $valid = match ($field['type']) {
            'integer' => is_int($value),
            'number' => (is_int($value) || is_float($value)) && is_finite((float) $value),
            'boolean' => is_bool($value),
            'date' => is_string($value) && self::validDate($value),
            default => is_string($value) && mb_strlen($value) <= 500,
        };
        if (!$valid) throw new InvalidArgumentException('筛选值类型无效，日期使用 YYYY-MM-DD 或 YYYY-MM-DD HH:MM:SS（站点时区）。');
        if (isset($field['values']) && (!is_string($value) || !array_key_exists($value, $field['values']))) throw new InvalidArgumentException('筛选值不属于此字段的可用选项，请重新读取数据源说明。');
    }

    private static function validDate(string $value): bool
    {
        $format = strlen($value) === 10 ? 'Y-m-d' : 'Y-m-d H:i:s';
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value, wp_timezone());
        return $date !== false && $date->format($format) === $value;
    }

    /** @return list<string> */
    public function fields(): array
    {
        $names = $this->field === '' ? [] : [$this->field];
        foreach ($this->children as $child) $names = array_merge($names, $child->fields());
        return array_values(array_unique($names));
    }

    /** Safe necessary conditions for native query pushdown; OR branches remain in the evaluator.
     * @return list<self>
     */
    public function required(): array
    {
        if ($this->field !== '') return [$this];
        $result = [];
        if ($this->op === 'all') foreach ($this->children as $child) $result = array_merge($result, $child->required());
        return $result;
    }

    /** @param Row $row */
    public function matches(array $row): bool
    {
        if ($this->field === '') {
            foreach ($this->children as $child) {
                $match = $child->matches($row);
                if ($this->op === 'all' && !$match) return false;
                if ($this->op === 'any' && $match) return true;
            }
            return $this->op === 'all';
        }
        $actual = $row[$this->field] ?? null;
        $empty = $actual === null || $actual === '' || $actual === [];
        if ($this->op === 'is_empty') return $empty;
        if ($this->op === 'is_not_empty') return !$empty;
        // Missing values do not pass comparisons, including negative comparisons.
        if ($actual === null) return false;
        if ($this->type === 'list') {
            if (!is_array($actual)) return false;
            $expected = is_array($this->value) ? $this->value : [$this->value];
            $found = count(array_intersect($actual, $expected)) > 0;
            return $this->op === 'not_in' ? !$found : $found;
        }
        if (is_array($actual)) return false;
        if (in_array($this->op, ['in', 'not_in'], true)) {
            $found = false;
            if (is_array($this->value)) foreach ($this->value as $value) if ($this->compare($actual, $value, 'eq')) $found = true;
            return $this->op === 'in' ? $found : !$found;
        }
        if ($this->op === 'between' && is_array($this->value)) return $this->compare($actual, $this->value[0], 'gte') && $this->compare($actual, $this->value[1], 'lte');
        return !is_array($this->value) && $this->compare($actual, $this->value, $this->op);
    }

    private function compare(string|int|float|bool $actual, string|int|float|bool|null $expected, string $op): bool
    {
        if ($this->type === 'date' && is_string($expected) && strlen($expected) === 10) {
            $start = $expected . ' 00:00:00'; $end = $expected . ' 23:59:59';
            return match ($op) {
                'eq' => $actual >= $start && $actual <= $end,
                'ne' => $actual < $start || $actual > $end,
                'gt' => $actual > $end, 'gte' => $actual >= $start,
                'lt' => $actual < $start, 'lte' => $actual <= $end,
                default => false,
            };
        }
        $equal = (is_int($actual) || is_float($actual)) && (is_int($expected) || is_float($expected)) ? $actual == $expected : $actual === $expected;
        return match ($op) {
            'eq' => $equal, 'ne' => !$equal,
            'gt' => $actual > $expected, 'gte' => $actual >= $expected,
            'lt' => $actual < $expected, 'lte' => $actual <= $expected,
            'contains' => is_string($actual) && is_string($expected) && mb_stripos($actual, $expected) !== false,
            'starts_with' => is_string($actual) && is_string($expected) && str_starts_with(mb_strtolower($actual), mb_strtolower($expected)),
            default => false,
        };
    }
}
