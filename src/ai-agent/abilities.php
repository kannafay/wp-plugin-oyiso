<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

final class Oyiso_Agent_Abilities
{
    public const WOOCOMMERCE = ['search-products', 'get-product', 'order-summary', 'prepare-product-update', 'apply-product-update', 'revert-product-update', 'prepare-product-trash', 'apply-product-trash', 'revert-product-trash', 'dismiss-product-trash'];
    private static ?Oyiso_Agent_Tools $context = null;

    /** @return array<string, WP_Ability> */
    public static function woocommerce(): array
    {
        $tools = [];
        if (!function_exists('wc_get_product') || !function_exists('wp_get_ability')) return $tools;
        foreach (self::WOOCOMMERCE as $name) {
            $ability = wp_get_ability('oyiso/' . $name);
            if ($ability !== null) $tools[$ability->get_name()] = $ability;
        }
        return $tools;
    }

    public static function bind(?Oyiso_Agent_Tools $context): void
    {
        self::$context = $context;
    }

    public static function categories(): void
    {
        wp_register_ability_category('oyiso-agent', ['label' => 'Oyiso AI Agent', 'description' => '商品内容、站内数据和已启用的 MCP 工具。']);
    }

    public static function register(): void
    {
        $id = ['type' => 'integer', 'minimum' => 1];
        $text = ['type' => 'string', 'minLength' => 1, 'maxLength' => 300];
        $fields = [];
        foreach (['description' => 60000, 'short_description' => 12000, 'seo_title' => 300, 'seo_description' => 1000, 'seo_keywords' => 1000] as $name => $max) {
            $fields[$name] = ['type' => 'string', 'maxLength' => $max];
        }
        $definitions = [
            'get-current-page' => ['读取当前后台页面', '读取最近一条用户消息发送时的页面类型、标题、对象 ID、正文和未保存编辑。用户说当前页面、这篇文章或这个商品时先参考此信息。', [], []],
            'search-posts' => ['查询文章和页面', '按类型和关键词分页查询可编辑的文章或页面，每页 10 条。', ['post_type' => ['type' => 'string', 'enum' => ['post', 'page']], 'search' => $text, 'page' => ['type' => 'integer', 'minimum' => 1]], []],
            'get-post' => ['读取文章或页面', '读取标题、正文、摘要和 SEO 字段。当前编辑对象优先返回未保存的编辑器内容；修改前必须先读取。', ['post_id' => $id], ['post_id']],
            'prepare-post-update' => ['准备文章或页面修改', '生成修改预览，不保存。content 使用 HTML 或保留原结构的 Gutenberg 区块序列化内容，不要把 Markdown 直接写入正文。仅提交需要修改的字段。', ['post_id' => $id, 'fields' => ['type' => 'object', 'properties' => ['title' => $text, 'content' => ['type' => 'string', 'maxLength' => 60000], 'excerpt' => ['type' => 'string', 'maxLength' => 4000], 'seo_title' => $text, 'seo_description' => ['type' => 'string', 'maxLength' => 1000], 'seo_keywords' => ['type' => 'string', 'maxLength' => 1000]], 'additionalProperties' => false, 'minProperties' => 1]], ['post_id', 'fields']],
            'prepare-post-draft' => ['准备新建文章或页面草稿', '生成新建草稿的预览，不创建或发布。用户在卡片中确认后才创建 WordPress 草稿。', ['post_type' => ['type' => 'string', 'enum' => ['post', 'page']], 'fields' => ['type' => 'object', 'properties' => ['title' => $text, 'content' => ['type' => 'string', 'maxLength' => 60000], 'excerpt' => ['type' => 'string', 'maxLength' => 4000]], 'additionalProperties' => false, 'required' => ['title', 'content']]], ['fields']],
            'apply-post-update' => ['保存文章或页面修改', '仅供用户在内容预览卡片中确认保存，保持原发布状态。', ['proposal_id' => $text], ['proposal_id']],
            'revert-post-update' => ['撤回文章或页面修改', '仅供用户在内容卡片中确认撤回，拒绝覆盖后续人工修改。', ['proposal_id' => $text], ['proposal_id']],
            'create-post-draft' => ['创建文章或页面草稿', '仅供用户在新建草稿预览卡片中确认，不发布。', ['proposal_id' => $text], ['proposal_id']],
            'search-products' => ['查询商品', '分页查询商品，返回名称、SKU 和缺失的内容字段。category 为商品分类 slug，每页 10 条。', ['search' => $text, 'category' => $text, 'page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000]], []],
            'get-product' => ['读取商品', '读取商品真实参数、长短描述和 SEOPress 信息。准备修改前必须先读取。', ['product_id' => $id], ['product_id']],
            'order-summary' => ['订单统计', '统计指定日期区间内 processing/completed 订单数量及分币种总额，最多取 500 单，不返回客户个人信息。', ['start_date' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'], 'end_date' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$']], ['start_date', 'end_date']],
            'prepare-product-update' => ['准备商品修改', '生成商品长短描述和 SEO 字段的修改预览，返回待审核方案，不保存商品。仅提交需要修改的字段；SEO 字段需 SEOPress 已启用。', ['product_id' => $id, 'fields' => ['type' => 'object', 'properties' => $fields, 'minProperties' => 1, 'additionalProperties' => false]], ['product_id', 'fields']],
            'apply-product-update' => ['应用商品修改', '应用用户在后台明确批准的修改方案。仅供后台操作入口调用。', ['proposal_id' => $text], ['proposal_id']],
            'revert-product-update' => ['撤回商品修改', '将已保存的商品字段恢复到此前值，并检查是否存在后续修改。仅供后台操作入口调用。', ['proposal_id' => $text], ['proposal_id']],
            'prepare-product-trash' => ['准备删除商品', '先用 get-product 核对商品后，生成移入回收站的待确认卡片，不会删除商品。不能仅凭相似名称判定重复；返回待确认时须让用户核对具体商品。', ['product_id' => $id, 'reason' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500]], ['product_id', 'reason']],
            'apply-product-trash' => ['商品移入回收站', '执行用户在后台确认的商品删除方案，关联变体一起移入回收站，不永久删除商品或图片。仅供后台操作入口调用。', ['proposal_id' => $text], ['proposal_id']],
            'revert-product-trash' => ['恢复已删除商品', '恢复本次操作移入回收站的商品及关联变体，检查后续修改和 SKU 冲突。仅供后台操作入口调用。', ['proposal_id' => $text], ['proposal_id']],
            'dismiss-product-trash' => ['取消删除商品', '取消尚未执行的商品删除方案，商品保持原状。仅供后台操作入口调用。', ['proposal_id' => $text], ['proposal_id']],
            'mcp-list-tools' => ['查找 MCP 工具', '查找已启用的 WoodMart 和其他插件 MCP 工具。先查找工具，再读取详细参数；每页 15 个。', ['search' => ['type' => 'string', 'maxLength' => 200], 'page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000]], []],
            'mcp-describe-tool' => ['读取 MCP 工具说明', '读取指定 MCP 工具的用途、完整输入 schema 和读写属性。调用前必须先读取。', ['name' => $text], ['name']],
            'mcp-call-tool' => ['调用 MCP 工具', '调用已启用的 MCP 工具。input_json 为遵守工具 schema 的 JSON 对象字符串（无参数时为 {}）。只读工具立即执行；修改类仅生成待确认卡片，尚未保存。', ['name' => $text, 'input_json' => ['type' => 'string', 'maxLength' => 50000]], ['name', 'input_json']],
        ];
        foreach ($definitions as $name => [$label, $description, $properties, $required]) {
            wp_register_ability('oyiso/' . $name, [
                'label' => $label,
                'description' => $description,
                'category' => 'oyiso-agent',
                'input_schema' => ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false],
                'output_schema' => ['type' => 'object', 'additionalProperties' => true],
                'permission_callback' => static function () use ($name): bool {
                    if (!Oyiso_Agent_Config::enabled() || !current_user_can('manage_options') || self::$context === null) {
                        return false;
                    }
                    if (Oyiso_Agent_Permissions::required('oyiso/' . $name) !== null && !Oyiso_Agent_Permissions::allows('oyiso/' . $name)) return false;
                    return match ($name) {
                        'apply-product-update', 'apply-product-trash' => self::$context->approved('apply'),
                        'revert-product-update', 'revert-product-trash' => self::$context->approved('revert'),
                        'dismiss-product-trash' => self::$context->approved('dismiss'),
                        default => in_array('oyiso/' . $name, self::allowed(), true),
                    };
                },
                'execute_callback' => static function (array $input) use ($name): array|WP_Error {
                    return self::$context?->execute($name, $input) ?? new WP_Error('agent_context_missing', '请从 AI Agent 对话或修改预览中调用此能力。');
                },
                'meta' => ['show_in_rest' => false, 'annotations' => ['readonly' => !in_array($name, ['prepare-post-update', 'prepare-post-draft', 'apply-post-update', 'revert-post-update', 'create-post-draft', 'prepare-product-update', 'apply-product-update', 'revert-product-update', 'prepare-product-trash', 'apply-product-trash', 'revert-product-trash', 'dismiss-product-trash', 'mcp-call-tool'], true), 'destructive' => in_array($name, ['apply-product-update', 'revert-product-update', 'apply-product-trash', 'revert-product-trash'], true), 'idempotent' => !in_array($name, ['prepare-product-update', 'prepare-product-trash', 'mcp-call-tool'], true)]],
            ]);
        }
    }

    /** The model receives only this allowlist. Applying and reverting are UI actions.
     * @return list<string>
     */
    public static function allowed(): array
    {
        $names = ['oyiso/get-current-page'];
        if (Oyiso_Agent_Page_Context::enabled('post') && current_user_can('edit_posts')) {
            $names = array_merge($names, ['oyiso/search-posts', 'oyiso/get-post']);
            if (Oyiso_Agent_Permissions::allows('oyiso/prepare-post-update')) $names[] = 'oyiso/prepare-post-update';
            if (Oyiso_Agent_Permissions::allows('oyiso/prepare-post-draft')) $names[] = 'oyiso/prepare-post-draft';
        }
        if (Oyiso_Agent_MCP::builtinEnabled() && function_exists('wc_get_product') && current_user_can('edit_products')) {
            $names = array_merge($names, ['oyiso/search-products', 'oyiso/get-product']);
            if (Oyiso_Agent_Permissions::allows('oyiso/prepare-product-update')) $names[] = 'oyiso/prepare-product-update';
            if (Oyiso_Agent_Permissions::allows('oyiso/prepare-product-trash') && current_user_can('delete_products')) $names[] = 'oyiso/prepare-product-trash';
        }
        if (Oyiso_Agent_MCP::builtinEnabled() && function_exists('wc_get_orders') && current_user_can('manage_woocommerce')) {
            $names[] = 'oyiso/order-summary';
        }
        if (current_user_can('manage_options') && Oyiso_Agent_MCP::selected() !== []) {
            $names = array_merge($names, ['oyiso/mcp-list-tools', 'oyiso/mcp-describe-tool', 'oyiso/mcp-call-tool']);
        }
        return $names;
    }
}
