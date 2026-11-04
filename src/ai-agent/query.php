<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * @phpstan-import-type Row from Oyiso_Agent_Query_Source
 * @phpstan-import-type Sort from Oyiso_Agent_Query_Spec
 * @phpstan-type Accumulator array{values:Row,count:int,metrics:array<string,array{count:int,sum:float,correction:float,min:?float,max:?float}>}
 */
final class Oyiso_Agent_Query
{
    public const TOOLS = ['list-data-sources', 'describe-data-source', 'query-data'];
    public const LEGACY = ['search-products', 'search-posts', 'order-summary'];

    /** @return array<string,array{string,string,array<string,mixed>,list<string>}> */
    public static function definitions(): array
    {
        $name = ['type' => 'string', 'minLength' => 1, 'maxLength' => 64];
        $alias = ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,63}$', 'description' => '唯一的小写字母、数字和下划线列名，以字母开头。'];
        return [
            'list-data-sources' => ['查找可查询数据', '列出当前已启用且有权限查询的数据源。查询站内数据前先发现数据源，再读取字段说明；其他插件能力仍可通过 MCP 工具发现。', [], []],
            'describe-data-source' => ['读取数据字段与查询规则', '读取数据源的字段、类型、状态选项、筛选操作符和统计口径。必须按这里返回的字段和值构造 query-data，不能猜测表名或字段。', ['source' => $name], ['source']],
            'query-data' => ['查询与统计站内数据', '组合筛选、排序、分页、分组和聚合。先 describe-data-source。where 可为 {field,op,value} 或递归的 {all:[条件]} / {any:[条件]}。metrics 的 op 支持 count/sum/avg/min/max，as 为结果列名；count 不传 field。group_by 支持字段及日期 interval: day/month/year。不传 metrics 时返回 select 指定的记录。省略 where 不增加业务条件；金额聚合自动按币种分组。只有 complete=true 才能引用统计；分页用 next_offset，不能把当前页当全部结果。', [
                'source' => $name,
                'select' => ['type' => 'array', 'items' => $name, 'minItems' => 1, 'maxItems' => 16],
                'where' => ['type' => 'object', 'additionalProperties' => true, 'description' => '字段条件 {field,op,value}，或 {all:[条件]} / {any:[条件]}；可嵌套，字段与操作符由数据源说明决定。'],
                'metrics' => ['type' => 'array', 'maxItems' => 8, 'items' => ['type' => 'object', 'properties' => ['op' => ['type' => 'string', 'enum' => ['count', 'sum', 'avg', 'min', 'max']], 'field' => $name, 'as' => $alias], 'required' => ['op'], 'additionalProperties' => false]],
                'group_by' => ['type' => 'array', 'maxItems' => 3, 'items' => ['type' => 'object', 'properties' => ['field' => $name, 'interval' => ['type' => 'string', 'enum' => ['value', 'day', 'month', 'year']], 'as' => $alias], 'required' => ['field'], 'additionalProperties' => false]],
                'order_by' => ['type' => 'array', 'maxItems' => 3, 'items' => ['type' => 'object', 'properties' => ['field' => $name, 'direction' => ['type' => 'string', 'enum' => ['asc', 'desc']]], 'required' => ['field'], 'additionalProperties' => false]],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50], 'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1000],
            ], ['source']],
        ];
    }

    /** @return array{max_records:int,max_seconds:int,max_groups:int,max_limit:int,max_offset:int} */
    public static function limits(): array
    {
        $defaults = ['max_records' => 20000, 'max_seconds' => 15, 'max_groups' => 1000, 'max_limit' => 50, 'max_offset' => 1000];
        $limits = apply_filters('oyiso_agent_query_limits', $defaults);
        if (is_array($limits)) foreach (['max_records' => 100000, 'max_seconds' => 30, 'max_groups' => 2000] as $key => $max) {
            if (is_int($limits[$key] ?? null)) $defaults[$key] = max(1, min($max, $limits[$key]));
        }
        return $defaults;
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>|WP_Error
     */
    public static function call(string $name, array $input, int $runId): array|WP_Error
    {
        if (!current_user_can('manage_options') || !Oyiso_Agent_Store::owned($runId) || Oyiso_Agent_Store::cancelled($runId)) return new WP_Error('agent_query_forbidden', '当前任务无权执行查询。', ['status' => 403]);
        try {
            $sources = Oyiso_Agent_Query_Catalog::available();
            if ($name === 'list-data-sources') {
                if ($input !== []) throw new InvalidArgumentException('数据源目录不接受参数。');
                $items = [];
                foreach ($sources as $source) $items[] = ['source' => $source->id, 'label' => $source->label, 'description' => $source->description];
                return ['sources' => $items, 'notice' => '只列出当前授权的数据源；先 describe-data-source，再 query-data。插件专属能力可继续使用 mcp-list-tools。'];
            }
            $id = $input['source'] ?? null;
            if (!is_string($id) || !isset($sources[$id])) return new WP_Error('agent_query_source', '数据源不可用或没有读取权限，请重新查找数据源。', ['status' => 403]);
            if ($name === 'describe-data-source') {
                if (array_diff(array_keys($input), ['source']) !== []) throw new InvalidArgumentException('数据源说明只接受 source。');
                return $sources[$id]->describe();
            }
            if ($name !== 'query-data') return new WP_Error('agent_unknown_tool', '查询工具不存在。');
            $spec = Oyiso_Agent_Query_Spec::parse($input, $sources[$id]);
            return self::execute($sources[$id], $spec, $input, $runId);
        } catch (InvalidArgumentException $error) {
            return new WP_Error('agent_query_invalid', $error->getMessage(), ['status' => 400]);
        } catch (Throwable $error) {
            return new WP_Error('agent_query_failed', '查询读取失败，未返回部分统计。请检查数据源后重试。', ['status' => 500]);
        }
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>|WP_Error
     */
    private static function execute(Oyiso_Agent_Query_Source $source, Oyiso_Agent_Query_Spec $spec, array $input, int $runId): array|WP_Error
    {
        $limits = self::limits(); $started = microtime(true); $startedAt = wp_date('Y-m-d H:i:s');
        $scanned = 0; $evaluated = 0; $matched = 0; $rows = []; $groups = []; $keep = $spec->offset + $spec->limit;
        $needed = $spec->fields(); $retained = array_flip(array_merge($spec->select, array_column($spec->sort, 'field')));
        if ($spec->metrics !== [] && $spec->groups === []) $groups['all'] = self::accumulator([]);
        do {
            if (Oyiso_Agent_Store::cancelled($runId)) return new WP_Error('agent_query_cancelled', '任务已取消，未返回部分统计。');
            if ($scanned >= $limits['max_records'] || microtime(true) - $started > $limits['max_seconds']) return self::limitError($limits);
            $page = $source->page($scanned, min(200, $limits['max_records'] - $scanned), $spec->where, $needed);
            if (is_wp_error($page)) return $page;
            if ($page->consumed < count($page->rows) || ($page->hasMore && $page->consumed === 0)) return new WP_Error('agent_query_read', '数据源分页无效，未返回部分统计。');
            $scanned += $page->consumed;
            foreach ($page->rows as $row) {
                $evaluated++;
                if (!$spec->where->matches($row)) continue;
                $matched++;
                if ($spec->metrics === []) {
                    $rows[] = array_intersect_key($row, $retained);
                    if (count($rows) >= $keep + 200) { self::sort($rows, $spec->sort); $rows = array_slice($rows, 0, $keep); }
                } else {
                    $values = [];
                    foreach ($spec->groups as $group) {
                        $value = $row[$group['field']] ?? null;
                        if ($group['interval'] !== 'value' && is_string($value)) $value = substr($value, 0, match ($group['interval']) { 'year' => 4, 'month' => 7, default => 10 });
                        $values[$group['as']] = $value;
                    }
                    $key = $spec->groups === [] ? 'all' : json_encode($values, JSON_THROW_ON_ERROR);
                    if (!isset($groups[$key])) {
                        if (count($groups) >= $limits['max_groups']) return self::limitError($limits);
                        $groups[$key] = self::accumulator($values);
                    }
                    self::accumulate($groups[$key], $row, $spec);
                }
            }
            if ($scanned > $limits['max_records'] || microtime(true) - $started > $limits['max_seconds']) return self::limitError($limits);
        } while ($page->hasMore);
        if (!$source->allowed() || Oyiso_Agent_Store::cancelled($runId)) return new WP_Error('agent_query_forbidden', '查询期间权限或任务状态已改变，未返回数据。', ['status' => 403]);
        $totalRows = $spec->metrics === [] ? $matched : count($groups);
        if ($spec->metrics !== []) {
            $rows = [];
            foreach ($groups as $group) {
                $row = $group['values'];
                foreach ($spec->metrics as $metric) {
                    $state = $group['metrics'][$metric['as']] ?? ['count' => 0, 'sum' => 0.0, 'correction' => 0.0, 'min' => null, 'max' => null];
                    $value = match ($metric['op']) {
                        'count' => $group['count'], 'sum' => $state['count'] > 0 || $group['count'] === 0 ? $state['sum'] : null, 'avg' => $state['count'] > 0 ? $state['sum'] / $state['count'] : null,
                        'min' => $state['min'], 'max' => $state['max'], default => null,
                    };
                    $row[$metric['as']] = is_float($value) && isset($source->fields[$metric['field']]['currency_field']) ? round($value, 8) : $value;
                }
                $rows[] = $row;
            }
        }
        self::sort($rows, $spec->sort);
        $rows = array_slice($rows, $spec->offset, $spec->limit);
        if ($spec->metrics === []) $rows = array_map(static fn(array $row): array => array_intersect_key($row, array_flip($spec->select)), $rows);
        $truncated = [];
        foreach ($rows as $index => &$row) foreach ($row as $field => &$value) {
            if (is_string($value) && mb_strlen($value) > 600) { $value = mb_substr($value, 0, 600); $truncated[$index][] = $field; }
            if (is_array($value) && (count($value) > 50 || count(array_filter($value, static fn(string $item): bool => mb_strlen($item) > 120)) > 0)) {
                $value = array_map(static fn(string $item): string => mb_substr($item, 0, 120), array_slice($value, 0, 50)); $truncated[$index][] = $field;
            }
        }
        unset($row, $value);
        while (count($rows) > 1 && strlen(json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) > 24000) array_pop($rows);
        if (strlen(json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) > 24000) return new WP_Error('agent_query_output', '单条查询结果过大，请减少 select 字段或使用聚合查询。');
        $truncated = array_intersect_key($truncated, $rows);
        $next = $spec->offset + count($rows);
        return ['source' => $source->id, 'complete' => true, 'read_consistency' => 'live', 'started_at' => $startedAt, 'finished_at' => wp_date('Y-m-d H:i:s'), 'timezone' => wp_timezone_string(),
            'where' => $input['where'] ?? new stdClass(), 'group_by' => $spec->groups, 'metrics' => $spec->metrics, 'automatic_group_by' => $spec->automaticGroups,
            'matched_records' => $matched, 'evaluated_records' => $evaluated, 'total_rows' => $totalRows, 'rows' => $rows, 'returned_rows' => count($rows), 'offset' => $spec->offset, 'has_more' => $next < $totalRows, 'next_offset' => $next < $totalRows ? $next : null, 'truncated_fields' => $truncated,
            'notice' => '筛选和聚合已遍历全部匹配范围，rows 可能只是结果的一页。未指定条件的字段不额外筛选。金额按币种分别计算，口径以字段说明为准。分批读取实时数据，不是事务快照；业务数据变化后需重新查询。truncated_fields 仅表示返回文本被截短，不影响本次筛选或聚合；修改前须重新读取完整内容。'];
    }

    /** @param Row $values
     * @return Accumulator
     */
    private static function accumulator(array $values): array
    {
        return ['values' => $values, 'count' => 0, 'metrics' => []];
    }

    /** @param Accumulator $group
     * @param Row $row
     */
    private static function accumulate(array &$group, array $row, Oyiso_Agent_Query_Spec $spec): void
    {
        $group['count']++;
        foreach ($spec->metrics as $metric) {
            if ($metric['op'] === 'count') continue;
            $value = $row[$metric['field']] ?? null;
            if ($value === null) continue;
            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) throw new RuntimeException('Invalid numeric source value.');
            $state = $group['metrics'][$metric['as']] ?? ['count' => 0, 'sum' => 0.0, 'correction' => 0.0, 'min' => null, 'max' => null];
            $state['count']++;
            if (in_array($metric['op'], ['sum', 'avg'], true)) {
                // Compensated summation avoids accumulating floating-point error across pages.
                $increment = $value - $state['correction']; $sum = $state['sum'] + $increment;
                if (!is_finite($sum)) throw new RuntimeException('Aggregate overflow.');
                $state['correction'] = ($sum - $state['sum']) - $increment; $state['sum'] = $sum;
            }
            $state['min'] = $state['min'] === null ? (float) $value : min($state['min'], (float) $value);
            $state['max'] = $state['max'] === null ? (float) $value : max($state['max'], (float) $value);
            $group['metrics'][$metric['as']] = $state;
        }
    }

    /** @param list<Row> $rows
     * @param list<Sort> $sort
     */
    private static function sort(array &$rows, array $sort): void
    {
        usort($rows, static function (array $a, array $b) use ($sort): int {
            foreach ($sort as $order) {
                $left = $a[$order['field']] ?? null; $right = $b[$order['field']] ?? null;
                if ($left === $right) continue;
                if ($left === null) return 1;
                if ($right === null) return -1;
                $result = is_string($left) && is_string($right) ? strcmp($left, $right) : $left <=> $right;
                if ($result !== 0) return $order['direction'] === 'desc' ? -$result : $result;
            }
            return 0;
        });
    }

    /** @param array<string,int> $limits */
    private static function limitError(array $limits): WP_Error
    {
        return new WP_Error('agent_query_limit', '查询超出单次读取、时间或分组预算，未返回部分统计。请按日期、状态或 ID 等条件拆分范围后重新查询；不能将失败当作零记录。', ['complete' => false, 'limits' => $limits]);
    }
}
