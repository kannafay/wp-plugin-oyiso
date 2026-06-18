<?php

defined('ABSPATH') || exit;

$settings = Oyiso_WC_Product_Table::getSettings();
$summary = Oyiso_WC_Product_Table::getSummary();
$columns = Oyiso_WC_Product_Table::getColumns();
$rows = Oyiso_WC_Product_Table::getRows();
$has_rows = !empty($rows);
$has_woo = Oyiso_WC_Product_Table::isWooCommerceAvailable();
$is_admin_viewer = Oyiso_WC_Product_Table::isAdminViewer();
$status_filters = $is_admin_viewer ? Oyiso_WC_Product_Table::getPublishStatusFilters() : [];
$has_stock_column = isset($columns['stock_status']);
$document_title = '产品资料总览 - ' . $summary['site_name'];
$toolbar_status_text = $has_rows ? '导出将以当前字段视图为准' : '当前暂无可导出数据';
$locked_column_keys = ['name'];
$stock_filters = [
    'instock' => '有货',
    'onbackorder' => '预售',
    'outofstock' => '缺货',
];
$status_filter_counts = array_fill_keys(array_keys($status_filters), 0);
$stock_filter_counts = array_fill_keys(array_keys($stock_filters), 0);

foreach ($rows as $row) {
    $status_key = (string) ($row['status_key'] ?? '');
    $stock_key = (string) ($row['stock_status_key'] ?? '');

    if ($status_key !== '' && array_key_exists($status_key, $status_filter_counts)) {
        $status_filter_counts[$status_key]++;
    }

    if ($stock_key !== '' && array_key_exists($stock_key, $stock_filter_counts)) {
        $stock_filter_counts[$stock_key]++;
    }
}
$status_filter_total = count($rows);
$stock_filter_total = count($rows);
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
    class="opt"
    data-oyiso-product-table
    data-table-slug="<?php echo esc_attr($settings['slug']); ?>"
>
    <header class="opt-header">
        <div class="opt-header__inner">
            <div class="opt-header__left">
                <h1 class="opt-header__title">产品资料总览</h1>
            </div>
            <div class="opt-header__right">
                <div class="opt-header__stats">
                    <div class="opt-header__stat">
                        <span class="opt-header__stat-value"><?php echo esc_html((string) $summary['product_count']); ?></span>
                        <span class="opt-header__stat-label">已发布产品</span>
                    </div>
                    <div class="opt-header__stat">
                        <span class="opt-header__stat-value"><?php echo esc_html(date('m/d', strtotime($summary['generated_at']))); ?></span>
                        <span class="opt-header__stat-label">数据日期</span>
                    </div>
                </div>
                <a href="<?php echo esc_url($summary['site_url']); ?>" class="opt-header__site-link" target="_blank" rel="noopener noreferrer">
                    <?php echo esc_html($summary['site_name']); ?>
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17L17 7"/><path d="M7 7h10v10"/></svg>
                </a>
            </div>
        </div>
    </header>

    <main class="opt-main">
        <div class="opt-controls">
            <div class="opt-controls__row">
                <div class="opt-search">
                    <svg class="opt-search__icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    <input
                        type="search"
                        class="opt-search__input"
                        placeholder="搜索产品名称、SKU、分类..."
                        data-role="table-search"
                        <?php disabled(!$has_rows); ?>
                    >
                </div>

                <div class="opt-actions">
                    <button type="button" class="opt-btn opt-btn--fill" data-action="copy-markdown" <?php disabled(!$has_rows); ?>>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                        复制 Markdown
                    </button>
                    <div class="opt-dropdown" data-dropdown>
                        <button type="button" class="opt-btn" data-dropdown-trigger <?php disabled(!$has_rows); ?>>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                            导出
                            <svg class="opt-dropdown__caret" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                        </button>
                        <div class="opt-dropdown__menu" data-dropdown-menu hidden>
                            <button type="button" class="opt-dropdown__item" data-action="export-csv">导出为 CSV</button>
                            <button type="button" class="opt-dropdown__item" data-action="export-markdown">导出为 Markdown</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="opt-controls__row opt-controls__row--sub">
                <div class="opt-tools">
                <div class="opt-dropdown opt-fieldsfilter" data-dropdown>
                    <button type="button" class="opt-btn" data-dropdown-trigger <?php disabled(!$has_rows); ?>>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3h7a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-7m0-18H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h7m0-18v18"/></svg>
                        显示字段
                        <svg class="opt-dropdown__caret" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                    </button>
                    <div class="opt-dropdown__menu opt-dropdown__menu--left" data-dropdown-menu hidden style="padding: 6px; min-width: 180px; max-height: 400px; overflow-y: auto;">
                        <?php foreach ($columns as $column_key => $column) : ?>
                            <?php $is_locked = in_array($column_key, $locked_column_keys, true); ?>
                            <label style="display: flex; align-items: center; gap: 8px; padding: 8px; border-radius: 4px; cursor: <?php echo $is_locked ? 'not-allowed' : 'pointer'; ?>; opacity: <?php echo $is_locked ? '0.6' : '1'; ?>;" onmouseover="this.style.background='var(--opt-accent-soft)'" onmouseout="this.style.background='transparent'">
                                <input
                                    type="checkbox"
                                    data-role="column-toggle"
                                    data-column-key="<?php echo esc_attr($column_key); ?>"
                                    data-locked="<?php echo $is_locked ? 'true' : 'false'; ?>"
                                    <?php echo $is_locked ? 'checked disabled' : 'checked'; ?>
                                    style="margin: 0; width: 14px; height: 14px; accent-color: var(--opt-accent); cursor: inherit;"
                                >
                                <span style="font-size: 13px; font-weight: 500; user-select: none;"><?php echo esc_html($column['label']); ?></span>
                                <?php if ($is_locked): ?>
                                    <span style="margin-left: auto; font-size: 10px; color: var(--opt-text-tertiary);">固定</span>
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if ($is_admin_viewer) : ?>
                <div class="opt-dropdown opt-statusfilter" data-dropdown data-role="status-filter">
                    <button type="button" class="opt-btn" data-dropdown-trigger data-role="status-filter-trigger" <?php disabled(!$has_rows); ?>>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/></svg>
                        发布状态：<span data-role="status-filter-label">全部（<?php echo esc_html((string) $status_filter_total); ?>）</span>
                        <svg class="opt-dropdown__caret" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                    </button>
                    <div class="opt-dropdown__menu opt-dropdown__menu--left" data-dropdown-menu hidden style="padding: 6px; min-width: 160px;">
                        <button type="button" class="opt-dropdown__item is-active" data-action="filter-status" data-status-value="all">全部（<?php echo esc_html((string) $status_filter_total); ?>）</button>
                        <?php foreach ($status_filters as $status_key => $status_label) : ?>
                        <button type="button" class="opt-dropdown__item" data-action="filter-status" data-status-value="<?php echo esc_attr($status_key); ?>"><?php echo esc_html($status_label); ?>（<?php echo esc_html((string) ($status_filter_counts[$status_key] ?? 0)); ?>）</button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($has_stock_column) : ?>
                <div class="opt-dropdown opt-stockfilter" data-dropdown data-role="stock-filter">
                    <button type="button" class="opt-btn" data-dropdown-trigger data-role="stock-filter-trigger" <?php disabled(!$has_rows); ?>>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>
                        库存：<span data-role="stock-filter-label">全部（<?php echo esc_html((string) $stock_filter_total); ?>）</span>
                        <svg class="opt-dropdown__caret" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                    </button>
                    <div class="opt-dropdown__menu opt-dropdown__menu--left" data-dropdown-menu hidden style="padding: 6px; min-width: 140px;">
                        <button type="button" class="opt-dropdown__item is-active" data-action="filter-stock" data-stock-value="all">全部（<?php echo esc_html((string) $stock_filter_total); ?>）</button>
                        <?php foreach ($stock_filters as $stock_key => $stock_label) : ?>
                        <button type="button" class="opt-dropdown__item" data-action="filter-stock" data-stock-value="<?php echo esc_attr($stock_key); ?>"><?php echo esc_html($stock_label); ?>（<?php echo esc_html((string) ($stock_filter_counts[$stock_key] ?? 0)); ?>）</button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                </div>

                <div class="opt-status" data-role="action-status" data-tone="neutral" aria-live="polite">
                    <?php echo esc_html($toolbar_status_text); ?>
                </div>
            </div>
        </div>

        <?php if (!$has_woo) : ?>
            <div class="opt-empty">
                <div class="opt-empty__icon">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>
                </div>
                <h2>未检测到 WooCommerce</h2>
                <p>当前站点暂未启用 WooCommerce，无法生成产品资料。</p>
                <code class="opt-empty__code"><?php echo esc_html(Oyiso_WC_Product_Table::getFallbackUrl()); ?></code>
            </div>
        <?php elseif (!$has_rows) : ?>
            <div class="opt-empty">
                <div class="opt-empty__icon">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>
                </div>
                <h2>暂无产品数据</h2>
                <p>当前站点尚未发现已发布商品，产品发布后将自动展示。</p>
            </div>
        <?php else : ?>
            <div class="opt-tablecard">
                <div class="opt-tablewrap">
                    <table class="opt-table">
                        <thead>
                        <tr>
                            <th class="opt-table__check"><input type="checkbox" data-role="select-all" title="全选/取消全选"></th>
                            <?php foreach ($columns as $column_key => $column) : ?>
                                <th data-column-key="<?php echo esc_attr($column_key); ?>" data-export-label="<?php echo esc_attr($column['label']); ?>">
                                    <?php echo esc_html($column['label']); ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $row) : ?>
                            <tr data-product-row data-search="<?php echo esc_attr(Oyiso_WC_Product_Table::getRowSearchText($row)); ?>" data-stock-key="<?php echo esc_attr($row['stock_status_key'] ?? ''); ?>"<?php if ($is_admin_viewer) : ?> data-status="<?php echo esc_attr($row['status_key'] ?? ''); ?>"<?php endif; ?>>
                                <td class="opt-table__check"><input type="checkbox" data-role="select-row"></td>
                                <?php foreach ($columns as $column_key => $column) : ?>
                                    <td
                                        data-column-key="<?php echo esc_attr($column_key); ?>"
                                        data-export-csv="<?php echo esc_attr(Oyiso_WC_Product_Table::getCellExportValue($column_key, $row, 'csv')); ?>"
                                        data-export-markdown="<?php echo esc_attr(Oyiso_WC_Product_Table::getCellExportValue($column_key, $row, 'markdown')); ?>"
                                    >
                                        <?php echo Oyiso_WC_Product_Table::getCellDisplayHtml($column_key, $row); ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="opt-empty opt-empty--filtered" data-role="filter-empty" hidden>
                <div class="opt-empty__icon">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                </div>
                <h2>未找到匹配产品</h2>
                <p>请尝试更换关键词或清空筛选条件。</p>
            </div>
        <?php endif; ?>
    </main>

    <footer class="opt-footer">
        <span>由 <?php echo esc_html($summary['site_name']); ?> 生成</span>
        <span><?php echo esc_html($summary['generated_at']); ?></span>
    </footer>
</div>
<?php wp_footer(); ?>
</body>
</html>
