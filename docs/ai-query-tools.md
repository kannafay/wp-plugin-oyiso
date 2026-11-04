# AI 助手通用查询

模型使用三个只读工具：`list-data-sources` → `describe-data-source` → `query-data`。数据源定义字段、类型、选项与权限，查询引擎统一处理条件、投影、排序、分组和聚合。增加数据源不需要新增模型工具或为自然语言问法增加分支。

内置数据源：

| 数据源 | 范围 | 权限 |
| --- | --- | --- |
| `orders` | 普通 WooCommerce 订单及已注册状态；金额、币种和时间，不含客户身份或支付凭据 | WooCommerce 工具已启用，`manage_woocommerce` |
| `products` | 可编辑的主商品，价格、库存、分类、描述纯文本/长度及已启用的 SEO 字段；不含独立变体和回收站 | WooCommerce 工具已启用，`edit_products`，逐条检查 `edit_post` |
| `posts` | 可编辑的已保存文章/页面、正文和摘要纯文本，包括草稿、私密、定时发布内容 | WordPress 工具已启用，内容类型编辑权限，逐条检查 `edit_post` |

所有入口还要求助手启用、`manage_options`、当前对话归属及未取消状态。数据源发现、执行每页和返回前都重新检查权限。查询不会生成修改快照；写入仍须使用 `get-product` / `get-post` 及原有确认流程。

## 查询协议

字段与枚举以 `describe-data-source` 实时返回的内容为准。没有 `where` 时不增加日期或业务状态筛选。

```json
{
  "source": "orders",
  "where": {
    "all": [
      {"field": "status", "op": "in", "value": ["processing", "completed"]},
      {"field": "date_created", "op": "between", "value": ["2026-01-01", "2026-12-31"]}
    ]
  },
  "group_by": [{"field": "date_created", "interval": "month"}],
  "metrics": [{"op": "count", "as": "orders"}, {"op": "sum", "field": "total", "as": "amount"}],
  "order_by": [{"field": "date_created_month", "direction": "asc"}],
  "limit": 20
}
```

- `where` 是 `{field, op, value}` 或嵌套的 `{all: [...]}` / `{any: [...]}`。空值判断 `is_empty` / `is_not_empty` 不传 `value`。缺失值不参与普通比较；数值 0 不为空。
- 操作符根据字段类型提供：等于、不等于、集合匹配、大小比较、区间、文本包含/前缀、空值判断。列表字段的 `contains` 是精确成员匹配，`in` 是集合有交集。
- 不传 `metrics` 时返回记录，`select` 控制字段；`order_by` 支持多个字段。排序后才分页，记录排序默认补充 ID 以稳定顺序。
- 聚合支持 `count/sum/avg/min/max`。`count` 统计记录数，不传字段；其他聚合要求数值字段，忽略空值。存在记录但数值全部未知时，数值聚合返回 null；零记录时 count/sum 为 0，avg/min/max 为 null。聚合输出由 `group_by` 和 `metrics` 定义，不同时传 `select`。
- `as` 指定唯一结果列名。排序聚合结果时使用结果列名。
- 日期接受站点时区的 `YYYY-MM-DD` 或 `YYYY-MM-DD HH:MM:SS`；仅日期的等于、区间和比较按整天解释。日期可按 day/month/year 分组。
- 金额字段声明 `currency_field`，引擎自动补充分币种分组，禁止把不同币种直接相加。`total` 包含税和运费，`net_total` 扣除已退金额；模型应说明实际采用的字段定义。

## 完整性与预算

引擎通过 WordPress / WooCommerce 查询 API 分批读取，兼容 WooCommerce 当前启用的订单存储方式。可安全下推的必要条件由适配器交给原生查询，其余条件由统一引擎计算。不会直接执行模型提供的 SQL、PHP、表名或任意元数据查询。

`complete=true` 表示本次筛选与聚合已遍历完整查询范围；`matched_records` 是全部匹配且可访问的记录数。`rows` 仍可能分页，结合 `total_rows`、`has_more`、`next_offset` 继续查询。分批读取是实时读取，不是事务快照；跨次分页期间数据变化后应重新查询。

默认预算为 20,000 条扫描记录、15 秒、1,000 个分组；每次输出 1–50 行，offset 上限 1,000。超出扫描、时间或分组预算会返回错误，**不返回部分统计，也不伪装成零结果**。可信 PHP 代码可通过 `oyiso_agent_query_limits` 调整执行预算，上限分别为 100,000 条、30 秒、2,000 个分组。列表字段或文本展示截断会在 `truncated_fields` 标明，不影响已经完成的筛选和聚合。

## 扩展数据源

通过 `oyiso_agent_query_sources` 注册 `Oyiso_Agent_Query_Source` 对象，提供：

1. 唯一 ID、说明、所属已启用工具命名空间及权限回调。
2. 允许读取的字段及类型、默认输出字段；金额字段可指定 `currency_field`。
3. 分页读取回调 `(offset, limit, filter, fields)`，返回 `Oyiso_Agent_Query_Page` 或 `WP_Error`。

读取回调须按稳定 ID 顺序返回请求字段、逐条检查对象权限，`consumed` 表示原生页消耗的条数（含无权返回的记录），`hasMore` 表示还有原生数据。字段值缺失或类型不符会导致整个查询失败。适配器可使用 `filter->required()` 取得必需条件进行安全下推；不能把 OR 子条件直接当作全局条件下推。

注册来自可信插件 PHP，不接受模型提供的回调。所属命名空间会出现在工具权限设置中，共享查询工具按数据源分别授权，不会联动开启其他插件。新增字段、数据源或插件适配器后，统一查询协议即可组合使用，不需要新增聊天问法。

原 `search-products`、`search-posts`、`order-summary` 保留为兼容入口，不再出现在模型可用工具或工具设置目录中。读取完整内容、生成修改方案和 MCP 插件工具继续使用原有能力。

## 检查

在启用 WordPress、WooCommerce 和本插件的本地安装中运行：

```text
php /path/to/phpunit.phar -c tests/ai-agent/phpunit.xml
php /path/to/phpstan.phar analyse -c tests/ai-agent/phpstan.neon --memory-limit=2G
```

集成测试沿用统一 bootstrap，屏蔽外发 HTTP/邮件并清理临时数据。通用查询测试包含真实订单、商品和文章适配器，以及超过旧 500 条限制的分页、分币种聚合、权限撤销、预算失败和第三方数据源扩展。
