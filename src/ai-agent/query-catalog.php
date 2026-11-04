<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * @phpstan-import-type Row from Oyiso_Agent_Query_Source
 * @phpstan-import-type Value from Oyiso_Agent_Query_Source
 * @phpstan-import-type Field from Oyiso_Agent_Query_Source
 */
final class Oyiso_Agent_Query_Catalog
{
    /** @return array<string,Oyiso_Agent_Query_Source> */
    public static function available(): array
    {
        return array_filter(self::sources(), static fn(Oyiso_Agent_Query_Source $source): bool => $source->allowed());
    }

    /** All trusted registrations, including disabled sources, for the permission settings UI.
     * @return array<string,Oyiso_Agent_Query_Source>
     */
    public static function sources(): array
    {
        $sources = [self::posts()];
        if (function_exists('wc_get_orders')) $sources[] = self::orders();
        if (function_exists('wc_get_products')) $sources[] = self::products();
        /** Trusted PHP extensions register source adapters; this hook is not a model argument. */
        $registered = apply_filters('oyiso_agent_query_sources', $sources);
        $result = [];
        if (is_array($registered)) foreach ($registered as $source) {
            if ($source instanceof Oyiso_Agent_Query_Source && preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $source->id) === 1 && isset($source->fields['id'])) $result[$source->id] = $source;
        }
        return $result;
    }

    public static function pluginGroup(string $namespace): string
    {
        return match ($namespace) { 'oyiso-wordpress' => 'wordpress', Oyiso_Agent_MCP::BUILTIN_NAMESPACE => 'woocommerce', default => $namespace };
    }

    /** @return Field */
    private static function field(string $type, string $label): array
    {
        return ['type' => $type, 'label' => $label];
    }

    private static function orders(): Oyiso_Agent_Query_Source
    {
        $statuses = [];
        foreach (wc_get_order_statuses() as $status => $label) $statuses[str_starts_with($status, 'wc-') ? substr($status, 3) : $status] = $label;
        $fields = ['id' => self::field('integer', '订单 ID'), 'status' => self::field('string', '订单状态') + ['values' => $statuses], 'currency' => self::field('string', '币种'), 'payment_method' => self::field('string', '支付方式标识')];
        foreach (['date_created' => '创建时间', 'date_modified' => '修改时间', 'date_paid' => '付款时间', 'date_completed' => '完成时间'] as $key => $label) $fields[$key] = self::field('date', $label);
        foreach (['total' => '含税含运费订单总额，未扣退款', 'total_tax' => '税额', 'shipping_total' => '运费，不含运费税', 'discount_total' => '折扣，不含折扣税', 'refunded_total' => '已退金额', 'net_total' => '订单总额减已退金额'] as $key => $label) $fields[$key] = self::field('number', $label) + ['currency_field' => 'currency'];
        return new Oyiso_Agent_Query_Source('orders', 'WooCommerce 订单', Oyiso_Agent_MCP::BUILTIN_NAMESPACE, '普通订单，包含本站已注册的订单状态；不默认排除取消、退款或待付款状态。无日期条件时读取全部日期。金额字段各有口径；退款记录不是单独订单。不暴露客户姓名、地址、邮箱和支付凭据。', $fields, ['id', 'status', 'date_created', 'currency', 'total'], self::orderPage(...), static fn(): bool => current_user_can('manage_woocommerce'));
    }

    /** @param list<string> $fields */
    private static function orderPage(int $offset, int $limit, Oyiso_Agent_Query_Filter $filter, array $fields): Oyiso_Agent_Query_Page|WP_Error
    {
        $args = ['type' => 'shop_order', 'status' => array_keys(wc_get_order_statuses()), 'limit' => $limit, 'offset' => $offset, 'paginate' => true, 'orderby' => 'ID', 'order' => 'ASC'];
        foreach ($filter->required() as $condition) {
            if (in_array($condition->op, ['eq', 'in'], true)) {
                $values = is_array($condition->value) ? $condition->value : [$condition->value];
                if ($condition->field === 'id') $args['include'] = $values;
                if ($condition->field === 'status') $args['status'] = array_map(static fn($value): string => 'wc-' . (string) $value, $values);
                if ($condition->field === 'currency' && $condition->op === 'eq') $args['currency'] = $condition->value;
            }
            if (in_array($condition->field, ['date_created', 'date_paid', 'date_completed', 'date_modified'], true) && $condition->op === 'between' && is_array($condition->value)) $args[$condition->field] = implode('...', $condition->value);
        }
        $result = wc_get_orders($args);
        if (!$result instanceof stdClass || !is_array($result->orders ?? null)) return new WP_Error('agent_query_read', '订单数据读取失败。');
        $rows = [];
        foreach ($result->orders as $order) {
            if (!$order instanceof WC_Order) return new WP_Error('agent_query_read', '订单查询返回了无效记录。');
            $row = [];
            foreach ($fields as $field) $row[$field] = match ($field) {
                'id' => $order->get_id(), 'status' => $order->get_status(), 'currency' => $order->get_currency(), 'payment_method' => $order->get_payment_method(),
                'date_created' => $order->get_date_created()?->date('Y-m-d H:i:s'), 'date_modified' => $order->get_date_modified()?->date('Y-m-d H:i:s'),
                'date_paid' => $order->get_date_paid()?->date('Y-m-d H:i:s'), 'date_completed' => $order->get_date_completed()?->date('Y-m-d H:i:s'),
                'total' => (float) $order->get_total(), 'total_tax' => (float) $order->get_total_tax(), 'shipping_total' => (float) $order->get_shipping_total(), 'discount_total' => (float) $order->get_discount_total(),
                'refunded_total' => (float) $order->get_total_refunded(), 'net_total' => (float) $order->get_total() - (float) $order->get_total_refunded(),
                default => null,
            };
            $rows[] = $row;
        }
        return new Oyiso_Agent_Query_Page($rows, count($result->orders), $offset + count($result->orders) < (int) ($result->total ?? 0));
    }

    private static function products(): Oyiso_Agent_Query_Source
    {
        $fields = ['id' => self::field('integer', '商品 ID'), 'name' => self::field('string', '商品名称'), 'sku' => self::field('string', 'SKU'), 'status' => self::field('string', '发布状态') + ['values' => self::postStatuses()], 'type' => self::field('string', '商品类型') + ['values' => wc_get_product_types()], 'currency' => self::field('string', '店铺基础币种'),
            'stock_status' => self::field('string', '库存状态') + ['values' => wc_get_product_stock_status_options()], 'stock_quantity' => self::field('number', '库存数量，未管理库存时为空'), 'manage_stock' => self::field('boolean', '是否管理库存'), 'on_sale' => self::field('boolean', '是否促销'),
            'category_slugs' => self::field('list', '商品分类 slug 列表'), 'tag_slugs' => self::field('list', '商品标签 slug 列表'), 'description_length' => self::field('integer', '长描述纯文本字符数'), 'short_description_length' => self::field('integer', '短描述纯文本字符数')];
        foreach (['price' => '当前价格', 'regular_price' => '常规价格', 'sale_price' => '促销价格'] as $key => $label) $fields[$key] = self::field('number', $label) + ['currency_field' => 'currency'];
        foreach (['date_created' => '创建时间', 'date_modified' => '修改时间'] as $key => $label) $fields[$key] = self::field('date', $label);
        foreach (['description_text' => '长描述纯文本', 'short_description_text' => '短描述纯文本'] as $key => $label) $fields[$key] = self::field('string', $label) + ['groupable' => false, 'sortable' => false, 'description' => '按完整已保存纯文本筛选；返回展示文本可能截短，修改前使用 get-product 读取。'];
        if (Oyiso_Agent_Tools::seoEnabled()) foreach (Oyiso_Agent_Tools::SEO as $key => $meta) $fields[$key] = self::field('string', $key) + ['description' => '独立设置值；为空时页面仍可能使用全局 SEO 模板。'];
        return new Oyiso_Agent_Query_Source('products', 'WooCommerce 商品', Oyiso_Agent_MCP::BUILTIN_NAMESPACE, '当前账号可编辑的主商品，含草稿和私密商品，不含回收站及独立变体记录。价格以店铺基础币种和 WooCommerce 商品对象为准。', $fields, ['id', 'name', 'sku', 'status', 'price', 'currency', 'stock_status'], self::productPage(...), static fn(): bool => current_user_can('edit_products'));
    }

    /** @param list<string> $fields */
    private static function productPage(int $offset, int $limit, Oyiso_Agent_Query_Filter $filter, array $fields): Oyiso_Agent_Query_Page|WP_Error
    {
        $args = ['type' => array_keys(wc_get_product_types()), 'status' => array_keys(self::postStatuses()), 'limit' => $limit, 'offset' => $offset, 'paginate' => true, 'orderby' => 'ID', 'order' => 'ASC'];
        foreach ($filter->required() as $condition) {
            if (in_array($condition->op, ['eq', 'in'], true)) {
                $values = is_array($condition->value) ? $condition->value : [$condition->value];
                if ($condition->field === 'id') $args['include'] = $values;
                if (in_array($condition->field, ['status', 'type'], true)) $args[$condition->field] = $values;
                if ($condition->field === 'stock_status' && $condition->op === 'eq') $args['stock_status'] = $condition->value;
            }
            if ($condition->field === 'category_slugs' && $condition->op === 'contains') $args['category'] = [$condition->value];
        }
        $result = wc_get_products($args);
        if (!$result instanceof stdClass || !is_array($result->products ?? null)) return new WP_Error('agent_query_read', '商品数据读取失败。');
        $rows = [];
        foreach ($result->products as $product) {
            if (!$product instanceof WC_Product) return new WP_Error('agent_query_read', '商品查询返回了无效记录。');
            if (!current_user_can('edit_post', $product->get_id())) continue;
            $row = [];
            foreach ($fields as $field) {
                if (isset(Oyiso_Agent_Tools::SEO[$field])) { $row[$field] = self::meta($product->get_id(), Oyiso_Agent_Tools::SEO[$field]); continue; }
                $row[$field] = match ($field) {
                    'id' => $product->get_id(), 'name' => $product->get_name(), 'sku' => $product->get_sku(), 'status' => $product->get_status(), 'type' => $product->get_type(), 'currency' => get_woocommerce_currency(),
                    'price' => self::number($product->get_price()), 'regular_price' => self::number($product->get_regular_price()), 'sale_price' => self::number($product->get_sale_price()),
                    'date_created' => $product->get_date_created()?->date('Y-m-d H:i:s'), 'date_modified' => $product->get_date_modified()?->date('Y-m-d H:i:s'),
                    'stock_status' => $product->get_stock_status(), 'stock_quantity' => $product->get_stock_quantity(), 'manage_stock' => (bool) $product->get_manage_stock(), 'on_sale' => $product->is_on_sale(),
                    'category_slugs' => self::terms($product->get_id(), 'product_cat'), 'tag_slugs' => self::terms($product->get_id(), 'product_tag'),
                    'description_length' => mb_strlen(trim(wp_strip_all_tags($product->get_description()))), 'short_description_length' => mb_strlen(trim(wp_strip_all_tags($product->get_short_description()))),
                    'description_text' => trim(wp_strip_all_tags($product->get_description())), 'short_description_text' => trim(wp_strip_all_tags($product->get_short_description())), default => null,
                };
            }
            $rows[] = $row;
        }
        return new Oyiso_Agent_Query_Page($rows, count($result->products), $offset + count($result->products) < (int) ($result->total ?? 0));
    }

    /** @return array<string,string> */
    private static function postStatuses(): array
    {
        $statuses = [];
        foreach (['publish', 'draft', 'pending', 'private', 'future'] as $status) $statuses[$status] = get_post_status_object($status)->label ?? $status;
        return $statuses;
    }

    /** @return array<string,string> */
    private static function postTypes(): array
    {
        $types = [];
        foreach (['post', 'page'] as $type) {
            $object = get_post_type_object($type);
            if ($object !== null && current_user_can($object->cap->edit_posts)) $types[$type] = $object->label;
        }
        return $types;
    }

    private static function posts(): Oyiso_Agent_Query_Source
    {
        $fields = ['id' => self::field('integer', '内容 ID'), 'title' => self::field('string', '标题'), 'slug' => self::field('string', 'URL slug'), 'post_type' => self::field('string', '内容类型') + ['values' => self::postTypes()], 'status' => self::field('string', '发布状态') + ['values' => self::postStatuses()],
            'date_created' => self::field('date', '创建/发布安排时间'), 'date_modified' => self::field('date', '修改时间'), 'comment_count' => self::field('integer', '已计入内容的评论数'), 'content_length' => self::field('integer', '正文纯文本字符数'), 'excerpt_length' => self::field('integer', '摘要纯文本字符数'), 'category_slugs' => self::field('list', '分类 slug 列表'), 'tag_slugs' => self::field('list', '标签 slug 列表')];
        foreach (['content_text' => '正文纯文本', 'excerpt_text' => '摘要纯文本'] as $key => $label) $fields[$key] = self::field('string', $label) + ['groupable' => false, 'sortable' => false, 'description' => '按完整已保存纯文本筛选；返回展示文本可能截短，修改前使用 get-post 读取。'];
        if (Oyiso_Agent_Tools::seoEnabled()) foreach (Oyiso_Agent_Tools::SEO as $key => $meta) $fields[$key] = self::field('string', $key) + ['description' => '独立设置值；空值不代表页面最终 SEO 输出为空。'];
        return new Oyiso_Agent_Query_Source('posts', 'WordPress 文章与页面', 'oyiso-wordpress', '当前账号可编辑的已保存文章和页面，包含草稿、私密和定时发布内容，不含回收站。未保存的编辑内容需使用 get-post 读取。', $fields, ['id', 'title', 'post_type', 'status', 'date_modified'], self::postPage(...), static fn(): bool => self::postTypes() !== []);
    }

    /** @param list<string> $fields */
    private static function postPage(int $offset, int $limit, Oyiso_Agent_Query_Filter $filter, array $fields): Oyiso_Agent_Query_Page|WP_Error
    {
        $args = ['post_type' => array_keys(self::postTypes()), 'post_status' => array_keys(self::postStatuses()), 'posts_per_page' => $limit, 'offset' => $offset, 'orderby' => 'ID', 'order' => 'ASC', 'ignore_sticky_posts' => true];
        foreach ($filter->required() as $condition) if (in_array($condition->op, ['eq', 'in'], true)) {
            $values = is_array($condition->value) ? $condition->value : [$condition->value];
            if ($condition->field === 'id') $args['post__in'] = $values;
            if ($condition->field === 'post_type') $args['post_type'] = $values;
            if ($condition->field === 'status') $args['post_status'] = $values;
        }
        $result = new WP_Query($args);
        $rows = [];
        foreach ($result->posts as $post) {
            if (!$post instanceof WP_Post) return new WP_Error('agent_query_read', '内容查询返回了无效记录。');
            if (!current_user_can('edit_post', $post->ID)) continue;
            $row = [];
            foreach ($fields as $field) {
                if (isset(Oyiso_Agent_Tools::SEO[$field])) { $row[$field] = self::meta($post->ID, Oyiso_Agent_Tools::SEO[$field]); continue; }
                $row[$field] = match ($field) {
                    'id' => $post->ID, 'title' => $post->post_title, 'slug' => $post->post_name, 'post_type' => $post->post_type, 'status' => $post->post_status,
                    'date_created' => $post->post_date === '0000-00-00 00:00:00' ? null : $post->post_date, 'date_modified' => $post->post_modified === '0000-00-00 00:00:00' ? null : $post->post_modified,
                    'comment_count' => (int) $post->comment_count, 'content_length' => mb_strlen(trim(wp_strip_all_tags($post->post_content))), 'excerpt_length' => mb_strlen(trim(wp_strip_all_tags($post->post_excerpt))),
                    'content_text' => trim(wp_strip_all_tags($post->post_content)), 'excerpt_text' => trim(wp_strip_all_tags($post->post_excerpt)),
                    'category_slugs' => self::terms($post->ID, 'category'), 'tag_slugs' => self::terms($post->ID, 'post_tag'), default => null,
                };
            }
            $rows[] = $row;
        }
        return new Oyiso_Agent_Query_Page($rows, count($result->posts), $offset + count($result->posts) < (int) $result->found_posts);
    }

    private static function number(string $value): ?float
    {
        return $value === '' ? null : (float) $value;
    }

    private static function meta(int $id, string $key): string
    {
        $value = get_post_meta($id, $key, true);
        return is_string($value) ? $value : '';
    }

    /** @return list<string> */
    private static function terms(int $id, string $taxonomy): array
    {
        $terms = get_the_terms($id, $taxonomy);
        if (is_wp_error($terms)) throw new RuntimeException('分类数据读取失败。');
        return is_array($terms) ? array_values(array_map(static fn(WP_Term $term): string => $term->slug, $terms)) : [];
    }
}
