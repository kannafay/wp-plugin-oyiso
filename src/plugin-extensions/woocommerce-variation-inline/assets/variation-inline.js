(function ($) {
    'use strict';

    if (typeof window.oyisoVIConfig === 'undefined') {
        return;
    }

    var config = window.oyisoVIConfig;
    var STOCK_LABELS = { instock: '有货', outofstock: '无货', onbackorder: '预售' };
    var STOCK_CLASSES = { instock: 'oyiso-vi-green', outofstock: 'oyiso-vi-red', onbackorder: 'oyiso-vi-orange' };

    function initVariation($variation) {
        if ($variation.find('.oyiso-vi-inline').length) {
            return;
        }

        var $h3 = $variation.children('h3');
        var $panel = $variation.find('.woocommerce_variable_attributes');
        var variationId = $variation.find('.variable_post_id').val() || '';

        // 读隐藏字段
        var regularPrice = $panel.find('input[name^="variable_regular_price"]').val() || '';
        var salePrice = $panel.find('input[name^="variable_sale_price"]').val() || '';
        var stockStatus = $panel.find('select[name^="variable_stock_status"]').val() || 'instock';
        var enabled = $panel.find('input[name^="variable_enabled"]').is(':checked');

        // 构建内联 HTML
        var html = '<span class="oyiso-vi-inline" data-variation="' + variationId + '">' +
            '<input type="text" class="oyiso-vi-price" data-field="regular_price" value="' + escAttr(regularPrice) + '" placeholder="常规价">' +
            '<input type="text" class="oyiso-vi-price" data-field="sale_price" value="' + escAttr(salePrice) + '" placeholder="销售价"' + (!regularPrice ? ' disabled' : '') + '>' +
            buildStockBtn(stockStatus) +
            buildEnabledBtn(enabled) +
            '</span>';

        $h3.append(html);

        // 绑定事件
        bindInlineEvents($variation);
        bindPanelSync($variation);
    }

    function buildStockBtn(status) {
        var label = STOCK_LABELS[status] || status;
        var cls = STOCK_CLASSES[status] || '';
        return '<button type="button" class="oyiso-vi-btn oyiso-vi-stock-btn ' + cls + '" data-field="stock_status" data-status="' + status + '">' + label + '</button>';
    }

    function buildEnabledBtn(enabled) {
        var label = enabled ? '启用' : '禁用';
        var cls = enabled ? 'oyiso-vi-green' : 'oyiso-vi-gray';
        return '<button type="button" class="oyiso-vi-btn oyiso-vi-enabled-btn ' + cls + '" data-field="enabled" data-status="' + (enabled ? '1' : '0') + '">' + label + '</button>';
    }

    function escAttr(str) {
        return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function bindInlineEvents($variation) {
        var $panel = $variation.find('.woocommerce_variable_attributes');

        // 价格输入：实时同步到隐藏字段，失焦时 AJAX 保存
        $variation.find('.oyiso-vi-price').on('input', function () {
            var $in = $(this);
            // 只允许数字和价格相关字符
            var cleaned = $in.val().replace(/[^0-9.,-]/g, '');
            if (cleaned !== $in.val()) {
                $in.val(cleaned);
            }
            var field = $in.data('field');
            var $hidden = $panel.find(field === 'regular_price'
                ? 'input[name^="variable_regular_price"]'
                : 'input[name^="variable_sale_price"]');
            $hidden.val($in.val());

            // 常规价为空时禁用销售价
            saveVariation($variation, field, $in.val(), $in);

            if (field === 'regular_price') {
                var $sale = $variation.find('.oyiso-vi-price[data-field="sale_price"]');
                if (!$in.val().trim()) {
                    $sale.val('').prop('disabled', true);
                    $panel.find('input[name^="variable_sale_price"]').val('');
                    saveVariation($variation, 'sale_price', '', $sale);
                } else {
                    $sale.prop('disabled', false);
                }
            }
        });

        // 库存状态：点击循环 + AJAX 保存
        $variation.find('.oyiso-vi-stock-btn').on('click', function () {
            var $btn = $(this);
            var current = $btn.data('status');
            var next = current === 'instock' ? 'outofstock'
                : current === 'outofstock' ? 'onbackorder'
                : 'instock';

            $btn.data('status', next);
            $btn.text(STOCK_LABELS[next] || next);
            $btn.removeClass('oyiso-vi-green oyiso-vi-red oyiso-vi-orange').addClass(STOCK_CLASSES[next] || '');

            // 同步隐藏字段
            $panel.find('select[name^="variable_stock_status"]').val(next).trigger('change');
            saveVariation($variation, 'stock_status', next, $btn);
        });

        // 启用状态：点击切换 + AJAX 保存
        $variation.find('.oyiso-vi-enabled-btn').on('click', function () {
            var $btn = $(this);
            var currentEnabled = $btn.data('status') === '1';
            var nextEnabled = !currentEnabled;

            $btn.data('status', nextEnabled ? '1' : '0');
            $btn.text(nextEnabled ? '启用' : '禁用');
            $btn.removeClass('oyiso-vi-green oyiso-vi-gray').addClass(nextEnabled ? 'oyiso-vi-green' : 'oyiso-vi-gray');

            // 同步隐藏字段
            $panel.find('input[name^="variable_enabled"]').prop('checked', nextEnabled).trigger('change');
            saveVariation($variation, 'enabled', nextEnabled ? '1' : '0', $btn);
        });
    }

    // 展开面板编辑 → 同步回内联
    function bindPanelSync($variation) {
        var $panel = $variation.find('.woocommerce_variable_attributes');

        $panel.find('input[name^="variable_regular_price"]').on('change input', function () {
            $variation.find('.oyiso-vi-price[data-field="regular_price"]').val($(this).val());
        });
        $panel.find('input[name^="variable_sale_price"]').on('change input', function () {
            $variation.find('.oyiso-vi-price[data-field="sale_price"]').val($(this).val());
        });
        $panel.find('select[name^="variable_stock_status"]').on('change', function () {
            var val = $(this).val();
            var $btn = $variation.find('.oyiso-vi-stock-btn');
            $btn.data('status', val).text(STOCK_LABELS[val] || val);
            $btn.removeClass('oyiso-vi-green oyiso-vi-red oyiso-vi-orange').addClass(STOCK_CLASSES[val] || '');
        });
        $panel.find('input[name^="variable_enabled"]').on('change', function () {
            var checked = $(this).is(':checked');
            var $btn = $variation.find('.oyiso-vi-enabled-btn');
            $btn.data('status', checked ? '1' : '0');
            $btn.text(checked ? '启用' : '禁用');
            $btn.removeClass('oyiso-vi-green oyiso-vi-gray').addClass(checked ? 'oyiso-vi-green' : 'oyiso-vi-gray');
        });
    }

    var saveTimers = {};
    function saveVariation($variation, field, value, $el) {
        var variationId = $variation.find('.variable_post_id').val();
        if (!variationId) {
            if ($el) { $el.removeClass('oyiso-vi-saving'); }
            return;
        }

        if ($el) { $el.addClass('oyiso-vi-saving'); }

        var key = variationId + '_' + field;
        clearTimeout(saveTimers[key]);
        saveTimers[key] = setTimeout(function () {
            $.ajax({
                url: config.ajaxurl,
                type: 'POST',
                data: {
                    action: config.action,
                    nonce: config.nonce,
                    variation_id: variationId,
                    field: field,
                    value: value
                },
                complete: function () {
                    if ($el) { $el.removeClass('oyiso-vi-saving'); }
                }
            });
        }, field === 'stock_status' || field === 'enabled' ? 0 : 400);
    }

    // 初始化所有已有变体
    function initAll() {
        $('#variable_product_options .woocommerce_variation').each(function () {
            initVariation($(this));
        });
    }

    // MutationObserver 捕获动态添加的变体
    var observerTimer = null;
    var observer = new MutationObserver(function () {
        clearTimeout(observerTimer);
        observerTimer = setTimeout(initAll, 100);
    });

    // 初始化和观察
    $(function () {
        initAll();

        var $container = $('#variable_product_options');
        if ($container.length) {
            observer.observe($container[0], { childList: true, subtree: true });
        }
    });

})(jQuery);
