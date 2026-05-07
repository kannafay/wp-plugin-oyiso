<?php

defined('ABSPATH') || exit;

$settings = Oyiso_WC_Product_Table::getSettings();
$summary = Oyiso_WC_Product_Table::getSummary();
$columns = Oyiso_WC_Product_Table::getColumns();
$rows = Oyiso_WC_Product_Table::getRows();
$has_rows = !empty($rows);
$has_woo = Oyiso_WC_Product_Table::isWooCommerceAvailable();
$document_title = '产品资料总览 - ' . $summary['site_name'];
$toolbar_status_text = $has_rows ? '导出将以当前字段视图为准' : '当前暂无可导出数据';
$locked_column_keys = ['name', 'link', 'specs'];
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?php echo esc_html($document_title); ?></title>
    <?php wp_head(); ?>
</head>
<body <?php body_class('oyiso-wc-product-table-body'); ?>>
<?php
if (function_exists('wp_body_open')) {
    wp_body_open();
}
?>
<div
    class="oyiso-product-table-shell"
    data-oyiso-product-table
    data-table-slug="<?php echo esc_attr($settings['slug']); ?>"
>
    <main class="oyiso-product-table-layout">
        <section class="oyiso-product-table-hero">
            <div class="oyiso-product-table-hero__grid">
                <div class="oyiso-product-table-hero__content">
                    <div class="oyiso-product-table-hero__eyebrow">WooCommerce Product Reference</div>
                    <h1>产品资料总览</h1>
                    <p>面向内容撰写、选品整理与运营协作的产品资料页。当前站点已发布商品将按产品名称 ASCII 顺序集中展示，支持快速检索、复制 Markdown 表格，以及导出 CSV / Markdown 文件。</p>
                    <div class="oyiso-product-table-hero__meta">
                        <span><strong><?php echo esc_html((string) $summary['product_count']); ?></strong> 个已发布产品</span>
                        <span>更新于 <?php echo esc_html($summary['generated_at']); ?></span>
                    </div>
                </div>
                <aside class="oyiso-product-table-hero__panel">
                    <div class="oyiso-product-table-hero__panel-label">访问地址</div>
                    <code><?php echo esc_html($summary['page_url']); ?></code>
                    <div class="oyiso-product-table-hero__panel-list">
                        <span>站点名称：<?php echo esc_html($summary['site_name']); ?></span>
                        <span>页面 Slug：/<?php echo esc_html($summary['slug']); ?></span>
                        <span>数据状态：<?php echo $has_woo ? 'WooCommerce 已连接' : 'WooCommerce 未连接'; ?></span>
                    </div>
                    <a href="<?php echo esc_url($summary['site_url']); ?>" class="oyiso-product-table-hero__site-link" target="_blank" rel="noopener noreferrer">查看站点首页</a>
                </aside>
            </div>
        </section>

        <section class="oyiso-product-table-toolbar">
            <div class="oyiso-product-table-toolbar__top">
                <label class="oyiso-product-table-toolbar__search">
                    <span>快速检索</span>
                    <input
                        type="search"
                        placeholder="输入产品名称、链接、规格或分类关键词"
                        data-role="table-search"
                        <?php disabled(!$has_rows); ?>
                    >
                </label>

                <div class="oyiso-product-table-toolbar__actions">
                    <button type="button" class="oyiso-product-table-button oyiso-product-table-button--primary" data-action="copy-markdown" <?php disabled(!$has_rows); ?>>复制 Markdown 表格</button>
                    <button type="button" class="oyiso-product-table-button" data-action="export-csv" <?php disabled(!$has_rows); ?>>导出 CSV 文件</button>
                    <button type="button" class="oyiso-product-table-button" data-action="export-markdown" <?php disabled(!$has_rows); ?>>导出 Markdown 文件</button>
                </div>
            </div>

            <div class="oyiso-product-table-toolbar__bottom">
                <div class="oyiso-product-table-toolbar__fields">
                    <span class="oyiso-product-table-toolbar__section-label">查看字段</span>
                    <div class="oyiso-product-table-toolbar__field-list" role="group" aria-label="选择显示字段">
                        <?php foreach ($columns as $column_key => $column) : ?>
                            <?php $is_locked_column = in_array($column_key, $locked_column_keys, true); ?>
                            <button
                                type="button"
                                class="oyiso-product-table-toolbar__field-chip"
                                data-role="column-toggle"
                                data-column-key="<?php echo esc_attr($column_key); ?>"
                                data-active="true"
                                data-locked="<?php echo $is_locked_column ? 'true' : 'false'; ?>"
                                aria-pressed="true"
                                title="<?php echo esc_attr($is_locked_column ? '该字段固定显示' : '切换字段显示'); ?>"
                                <?php disabled(!$has_rows || $is_locked_column); ?>
                            >
                                <?php echo esc_html($column['label']); ?>
                                <?php if ($is_locked_column) : ?>
                                    <span class="oyiso-product-table-toolbar__field-chip-note">固定</span>
                                <?php endif; ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="oyiso-product-table-statusbar__message" data-role="action-status" data-tone="neutral" aria-live="polite">
                    <?php echo esc_html($toolbar_status_text); ?>
                </div>
            </div>
        </section>

        <?php if (!$has_woo) : ?>
            <section class="oyiso-product-table-empty">
                <h2>未检测到 WooCommerce 环境</h2>
                <p>当前站点暂未启用或无法读取 WooCommerce 数据，因此暂时无法生成产品资料页。可使用下方备用地址继续排查访问状态。</p>
                <code><?php echo esc_html(Oyiso_WC_Product_Table::getFallbackUrl()); ?></code>
            </section>
        <?php elseif (!$has_rows) : ?>
            <section class="oyiso-product-table-empty">
                <h2>暂无可展示的产品数据</h2>
                <p>当前站点尚未发现已发布商品。待产品发布后，本页面会自动汇总并展示对应资料。</p>
            </section>
        <?php else : ?>
            <section class="oyiso-product-table-tablecard">
                <div class="oyiso-product-table-tablewrap">
                    <table class="oyiso-product-table-table">
                        <thead>
                        <tr>
                            <?php foreach ($columns as $column_key => $column) : ?>
                                <th data-column-key="<?php echo esc_attr($column_key); ?>" data-export-label="<?php echo esc_attr($column['label']); ?>">
                                    <?php echo esc_html($column['label']); ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $row) : ?>
                            <tr data-product-row data-search="<?php echo esc_attr(Oyiso_WC_Product_Table::getRowSearchText($row)); ?>">
                                <?php foreach ($columns as $column_key => $column) : ?>
                                    <td
                                        data-column-key="<?php echo esc_attr($column_key); ?>"
                                        data-export-csv="<?php echo esc_attr(Oyiso_WC_Product_Table::getCellExportValue($column_key, $row, 'csv')); ?>"
                                        data-export-markdown="<?php echo esc_attr(Oyiso_WC_Product_Table::getCellExportValue($column_key, $row, 'markdown')); ?>"
                                    >
                                        <?php echo wp_kses_post(Oyiso_WC_Product_Table::getCellDisplayHtml($column_key, $row)); ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="oyiso-product-table-empty oyiso-product-table-empty--filtered" data-role="filter-empty" hidden>
                <h2>未找到匹配产品</h2>
                <p>当前关键词未匹配到任何商品，请尝试更换关键词，或清空筛选条件后查看全部产品。</p>
            </section>
        <?php endif; ?>
    </main>
</div>
<?php wp_footer(); ?>
</body>
</html>
