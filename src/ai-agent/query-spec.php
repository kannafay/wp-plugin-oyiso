<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * @phpstan-type Group array{field:string,interval:string,as:string}
 * @phpstan-type Metric array{op:string,field:string,as:string}
 * @phpstan-type Sort array{field:string,direction:string}
 */
final readonly class Oyiso_Agent_Query_Spec
{
    /** @param list<string> $select
     * @param list<Group> $groups
     * @param list<Metric> $metrics
     * @param list<Sort> $sort
     * @param list<string> $automaticGroups
     */
    private function __construct(public array $select, public Oyiso_Agent_Query_Filter $where, public array $groups, public array $metrics, public array $sort, public int $limit, public int $offset, public array $automaticGroups)
    {
    }

    /** @param array<string,mixed> $input */
    public static function parse(array $input, Oyiso_Agent_Query_Source $source): self
    {
        if (array_diff(array_keys($input), ['source', 'select', 'where', 'group_by', 'metrics', 'order_by', 'limit', 'offset']) !== []) throw new InvalidArgumentException('查询包含未知参数。');
        foreach ($input as $value) if ($value === null) throw new InvalidArgumentException('查询参数不能为 null；不需要的参数请省略。');
        $select = self::list($input['select'] ?? $source->defaults, 16, 'select');
        foreach ($select as $field) self::field($field, $source);
        if ($select === []) throw new InvalidArgumentException('select 不能为空。');
        $where = Oyiso_Agent_Query_Filter::parse($input['where'] ?? [], $source->fields);
        $groups = []; $metrics = []; $names = []; $automatic = [];
        foreach (self::list($input['group_by'] ?? [], 3, 'group_by') as $group) {
            if (!is_array($group) || array_diff(array_keys($group), ['field', 'interval', 'as']) !== []) throw new InvalidArgumentException('group_by 条目必须包含 field 和可选 interval/as。');
            $field = self::field($group['field'] ?? null, $source);
            $interval = $group['interval'] ?? 'value';
            if (!is_string($interval) || !in_array($interval, ['value', 'day', 'month', 'year'], true) || ($interval !== 'value' && $source->fields[$field]['type'] !== 'date') || $source->fields[$field]['type'] === 'list' || ($source->fields[$field]['groupable'] ?? true) === false) throw new InvalidArgumentException('该字段不能按指定方式分组。');
            $alias = self::alias($group['as'] ?? ($field . ($interval === 'value' ? '' : '_' . $interval)), $names);
            $groups[] = ['field' => $field, 'interval' => $interval, 'as' => $alias];
        }
        foreach (self::list($input['metrics'] ?? [], 8, 'metrics') as $metric) {
            if (!is_array($metric) || array_diff(array_keys($metric), ['op', 'field', 'as']) !== []) throw new InvalidArgumentException('metrics 条目必须包含 op 和可选 field/as。');
            $op = $metric['op'] ?? null;
            if (!is_string($op) || !in_array($op, ['count', 'sum', 'avg', 'min', 'max'], true)) throw new InvalidArgumentException('聚合操作必须是 count/sum/avg/min/max。');
            $field = $op === 'count' ? '' : self::field($metric['field'] ?? null, $source);
            if ($op === 'count' && array_key_exists('field', $metric)) throw new InvalidArgumentException('count 统计记录数，不接受 field。');
            if ($field !== '' && !in_array($source->fields[$field]['type'], ['number', 'integer'], true)) throw new InvalidArgumentException('数值聚合只能用于数值字段。');
            $alias = self::alias($metric['as'] ?? ($op . ($field === '' ? '' : '_' . $field)), $names);
            $metrics[] = ['op' => $op, 'field' => $field, 'as' => $alias];
            $currency = $field === '' ? null : ($source->fields[$field]['currency_field'] ?? null);
            if ($currency !== null && !in_array($currency, array_column($groups, 'field'), true)) {
                self::field($currency, $source);
                $groups[] = ['field' => $currency, 'interval' => 'value', 'as' => self::alias($currency, $names)];
                $automatic[] = $currency;
            }
        }
        if ($groups !== [] && $metrics === []) throw new InvalidArgumentException('分组查询需要至少一个聚合指标。');
        if ($metrics !== [] && array_key_exists('select', $input)) throw new InvalidArgumentException('聚合查询通过 group_by 和 metrics 指定输出列，不同时使用 select。');
        $sort = [];
        foreach (self::list($input['order_by'] ?? [], 3, 'order_by') as $order) {
            if (!is_array($order) || array_diff(array_keys($order), ['field', 'direction']) !== []) throw new InvalidArgumentException('order_by 条目必须包含 field 和可选 direction。');
            $field = $order['field'] ?? null; $direction = $order['direction'] ?? 'asc';
            if (!is_string($field) || !is_string($direction) || !in_array($direction, ['asc', 'desc'], true)) throw new InvalidArgumentException('排序字段或方向无效。');
            if ($metrics === []) {
                self::field($field, $source);
                if ($source->fields[$field]['type'] === 'list' || ($source->fields[$field]['sortable'] ?? true) === false) throw new InvalidArgumentException('该字段不能用于排序。');
            } elseif (!in_array($field, $names, true)) throw new InvalidArgumentException('聚合查询只能按分组或聚合结果的列名排序。');
            $sort[] = ['field' => $field, 'direction' => $direction];
        }
        if ($metrics === [] && !in_array('id', array_column($sort, 'field'), true)) $sort[] = ['field' => 'id', 'direction' => 'asc'];
        if ($metrics !== []) foreach ($groups as $group) if (!in_array($group['as'], array_column($sort, 'field'), true)) $sort[] = ['field' => $group['as'], 'direction' => 'asc'];
        $limit = $input['limit'] ?? 20; $offset = $input['offset'] ?? 0;
        if (!is_int($limit) || $limit < 1 || $limit > 50 || !is_int($offset) || $offset < 0 || $offset > 1000) throw new InvalidArgumentException('limit 须为 1–50，offset 须为 0–1000。');
        /** @var list<string> $select Validated field names above. */
        return new self(array_values(array_unique($select)), $where, $groups, $metrics, $sort, $limit, $offset, $automatic);
    }

    /** @return list<mixed> */
    private static function list(mixed $value, int $max, string $name): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $max) throw new InvalidArgumentException($name . ' 必须是最多 ' . $max . ' 项的列表。');
        return $value;
    }

    private static function field(mixed $field, Oyiso_Agent_Query_Source $source): string
    {
        if (!is_string($field) || !isset($source->fields[$field])) throw new InvalidArgumentException('未知或不允许查询的字段，请先读取数据源说明。');
        return $field;
    }

    /** @param list<string> $used */
    private static function alias(mixed $alias, array &$used): string
    {
        if (!is_string($alias) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $alias) !== 1 || in_array($alias, $used, true)) throw new InvalidArgumentException('输出列名必须唯一，并使用小写字母、数字和下划线。');
        $used[] = $alias;
        return $alias;
    }

    /** @return list<string> */
    public function fields(): array
    {
        $fields = $this->where->fields();
        if ($this->metrics === []) $fields = array_merge($fields, $this->select, array_column($this->sort, 'field'));
        else $fields = array_merge($fields, array_column($this->groups, 'field'), array_column($this->metrics, 'field'));
        return array_values(array_unique(array_filter(array_merge(['id'], $fields), static fn(string $field): bool => $field !== '')));
    }
}
