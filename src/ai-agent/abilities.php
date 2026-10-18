<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

final class Oyiso_Agent_Abilities
{
    private static ?Oyiso_Agent_Tools $context = null;

    public static function bind(?Oyiso_Agent_Tools $context): void
    {
        self::$context = $context;
    }

    public static function categories(): void
    {
        wp_register_ability_category('oyiso-agent', ['label' => 'Oyiso AI Agent', 'description' => '商品内容编辑和站内数据查询。']);
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
            'search-products' => ['查询商品', '分页查询商品，返回名称、SKU 和缺失的内容字段。category 为商品分类 slug，每页 10 条。', ['search' => $text, 'category' => $text, 'page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000]], []],
            'get-product' => ['读取商品', '读取商品真实参数、长短描述和 SEOPress 信息。准备修改前必须先读取。', ['product_id' => $id], ['product_id']],
            'order-summary' => ['订单统计', '统计指定日期区间内 processing/completed 订单数量及分币种总额，最多取 500 单，不返回客户个人信息。', ['start_date' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'], 'end_date' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$']], ['start_date', 'end_date']],
            'prepare-product-update' => ['准备商品修改', '生成商品长短描述和 SEO 字段的修改预览，返回待审核方案，不保存商品。仅提交需要修改的字段；SEO 字段需 SEOPress 已启用。', ['product_id' => $id, 'fields' => ['type' => 'object', 'properties' => $fields, 'minProperties' => 1, 'additionalProperties' => false]], ['product_id', 'fields']],
            'apply-product-update' => ['应用商品修改', '应用用户在后台明确批准的修改方案。仅供后台操作入口调用。', ['proposal_id' => $text], ['proposal_id']],
            'revert-product-update' => ['撤回商品修改', '将已保存的商品字段恢复到此前值，并检查是否存在后续修改。仅供后台操作入口调用。', ['proposal_id' => $text], ['proposal_id']],
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
                    return match ($name) {
                        'apply-product-update' => self::$context->approved('apply'),
                        'revert-product-update' => self::$context->approved('revert'),
                        default => in_array('oyiso/' . $name, self::allowed(), true),
                    };
                },
                'execute_callback' => static function (array $input) use ($name): array|WP_Error {
                    return self::$context?->execute($name, $input) ?? new WP_Error('agent_context_missing', '请从 AI Agent 对话或修改预览中调用此能力。');
                },
                'meta' => ['show_in_rest' => false, 'annotations' => ['readonly' => !in_array($name, ['prepare-product-update', 'apply-product-update', 'revert-product-update'], true), 'destructive' => in_array($name, ['apply-product-update', 'revert-product-update'], true), 'idempotent' => $name !== 'prepare-product-update']],
            ]);
        }
    }

    /** The model receives only this allowlist. Applying and reverting are UI actions.
     * @return list<string>
     */
    public static function allowed(): array
    {
        $names = [];
        if (function_exists('wc_get_product') && current_user_can('edit_products')) {
            $names = array_merge($names, ['oyiso/search-products', 'oyiso/get-product', 'oyiso/prepare-product-update']);
        }
        if (function_exists('wc_get_orders') && current_user_can('manage_woocommerce')) {
            $names[] = 'oyiso/order-summary';
        }
        return $names;
    }
}
