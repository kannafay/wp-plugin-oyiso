<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Trusted adapters expose named fields, never client-supplied tables, SQL or callbacks.
 * @phpstan-type Value string|int|float|bool|null|list<string>
 * @phpstan-type Row array<string, Value>
 * @phpstan-type Field array{type:string,label:string,values?:array<string,string>,currency_field?:string,description?:string,groupable?:bool,sortable?:bool}
 */
final readonly class Oyiso_Agent_Query_Source
{
    /**
     * @param array<string, Field> $fields
     * @param list<string> $defaults
     * @param Closure(int,int,Oyiso_Agent_Query_Filter,list<string>): (Oyiso_Agent_Query_Page|WP_Error) $reader
     * @param Closure():bool $permission
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $namespace,
        public string $description,
        public array $fields,
        public array $defaults,
        private Closure $reader,
        private Closure $permission,
    ) {
    }

    public function allowed(): bool
    {
        $options = Oyiso_Agent_Config::options(true);
        return !empty($options['agent_enabled']) && current_user_can('manage_options')
            && in_array($this->namespace, Oyiso_Agent_MCP::namespaces($options), true) && ($this->permission)();
    }

    /** @param list<string> $fields */
    public function page(int $offset, int $limit, Oyiso_Agent_Query_Filter $filter, array $fields): Oyiso_Agent_Query_Page|WP_Error
    {
        if (!$this->allowed()) return new WP_Error('agent_query_forbidden', '此数据源未启用或当前没有读取权限。', ['status' => 403]);
        $page = ($this->reader)($offset, $limit, $filter, $fields);
        if (is_wp_error($page)) return $page;
        $rows = [];
        foreach ($page->rows as $row) {
            foreach ($fields as $field) {
                if (!array_key_exists($field, $row)) return new WP_Error('agent_query_read', '数据源缺少所需字段，未返回部分统计。');
                $value = $row[$field];
                if ($field === 'id' && $value === null) return new WP_Error('agent_query_read', '数据源记录缺少有效 ID，未返回部分统计。');
                if ($value === null) continue;
                $valid = match ($this->fields[$field]['type']) {
                    'integer' => is_int($value), 'number' => (is_int($value) || is_float($value)) && is_finite((float) $value), 'boolean' => is_bool($value),
                    'list' => self::stringList($value), default => is_string($value),
                };
                if (!$valid) return new WP_Error('agent_query_read', '数据源字段类型不符合声明，未返回部分统计。');
            }
            $rows[] = array_intersect_key($row, array_flip($fields));
        }
        return new Oyiso_Agent_Query_Page($rows, $page->consumed, $page->hasMore);
    }

    private static function stringList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value) && count(array_filter($value, 'is_string')) === count($value);
    }

    /** @return array<string,mixed> */
    public function describe(): array
    {
        $fields = [];
        foreach ($this->fields as $name => $field) {
            $fields[$name] = $field + ['operators' => Oyiso_Agent_Query_Filter::operators($field['type']), 'groupable' => $field['type'] !== 'list'];
        }
        return ['source' => $this->id, 'label' => $this->label, 'description' => $this->description, 'fields' => $fields, 'default_select' => $this->defaults,
            'query' => ['where' => ['condition' => ['field' => '字段名', 'op' => '字段支持的操作符', 'value' => '对应类型的值'], 'all' => '多个条件全部满足，可嵌套 all/any', 'any' => '多个条件至少满足一个'], 'metrics' => ['count', 'sum', 'avg', 'min', 'max'], 'group_by' => '字段名及可选 interval: day/month/year；日期按站点时区分组', 'order_by' => '字段名或聚合结果列名及 direction: asc/desc'],
            'limits' => Oyiso_Agent_Query::limits(), 'timezone' => wp_timezone_string(), 'notice' => '先根据字段及其定义构造查询。未传 where 时不增加业务筛选；金额聚合自动按 currency_field 分组。列表只提供查询字段，修改前仍须调用相应内容读取工具。'];
    }
}

/** @phpstan-import-type Row from Oyiso_Agent_Query_Source */
final readonly class Oyiso_Agent_Query_Page
{
    /** @param list<Row> $rows */
    public function __construct(public array $rows, public int $consumed, public bool $hasMore)
    {
    }
}
