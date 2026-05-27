(function ($) {
    'use strict';

    if (typeof window.oyisoSplitConfig === 'undefined') {
        return;
    }

    var config = window.oyisoSplitConfig;
    var ids = [];
    var total = 0;
    var current = 0;
    var running = false;

    function getSelectedIds() {
        var checked = [];
        $('tbody#the-list .check-column input[type="checkbox"]:checked').each(function () {
            checked.push(parseInt($(this).val(), 10));
        });
        return checked;
    }

    function showPanel() {
        if ($('#oyiso-split-notice').length) {
            $('#oyiso-split-notice').remove();
        }

        var html = '<div class="notice notice-info" id="oyiso-split-notice">' +
            '<p><strong>变体拆分</strong>：即将拆分 ' + total + ' 个产品（仅可变产品会被处理）。</p>' +
            '<div id="oyiso-split-progress" style="margin:10px 0;">' +
            '<div style="background:#f0f0f0;border-radius:4px;overflow:hidden;height:24px;position:relative;">' +
            '<div id="oyiso-split-bar" style="background:#2271b1;height:100%;width:0%;transition:width .3s;"></div>' +
            '<span id="oyiso-split-bar-text" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);font-size:12px;color:#333;">0 / ' + total + ' (0%)</span>' +
            '</div>' +
            '<div id="oyiso-split-log" style="margin-top:8px;max-height:200px;overflow-y:auto;font-size:12px;color:#666;"></div>' +
            '</div>' +
            '<p>' +
            '<button type="button" class="button button-primary" id="oyiso-split-start">开始拆分</button> ' +
            '<button type="button" class="button" id="oyiso-split-cancel">取消</button>' +
            '</p>' +
            '</div>';

        var $wrap = $('.wrap');
        var $anchor = $wrap.find('.wp-header-end');
        if ($anchor.length) {
            $anchor.after(html);
        } else {
            $wrap.find('.subsubsub').before(html);
        }
        bindPanelEvents();
    }

    function updateProgress() {
        var pct = total > 0 ? Math.round((current / total) * 100) : 0;
        $('#oyiso-split-bar').css('width', pct + '%');
        $('#oyiso-split-bar-text').text(current + ' / ' + total + ' (' + pct + '%)');
    }

    function appendLog(msg, type) {
        var color = type === 'error' ? '#d63638' : type === 'success' ? '#00a32a' : '#666';
        var $log = $('#oyiso-split-log');
        $log.append('<div style="color:' + color + ';">' + msg + '</div>');
        $log.scrollTop($log[0].scrollHeight);
    }

    function splitNext() {
        if (!running || current >= total) {
            if (running) {
                appendLog('✓ 全部完成！', 'success');
                $('#oyiso-split-start').hide();
                $('#oyiso-split-cancel').text('刷新页面').show().off('click').on('click', function () {
                    window.location.reload();
                });
            }
            running = false;
            return;
        }

        var productId = ids[current];
        appendLog('正在处理产品 #' + productId + ' (' + (current + 1) + '/' + total + ')...');

        $.ajax({
            url: config.ajaxurl,
            type: 'POST',
            data: {
                action: config.action,
                nonce: config.nonce,
                product_id: productId
            },
            success: function (response) {
                if (response.success) {
                    var d = response.data;
                    appendLog('✓ ' + d.message, 'success');
                    if (d.errors && d.errors.length > 0) {
                        for (var i = 0; i < d.errors.length; i++) {
                            appendLog('  ⚠ ' + d.errors[i], 'error');
                        }
                    }
                } else {
                    appendLog('✗ #' + productId + ': ' + (response.data ? response.data.message : '未知错误'), 'error');
                }
            },
            error: function () {
                appendLog('✗ #' + productId + ': 请求失败', 'error');
            },
            complete: function () {
                current++;
                updateProgress();
                splitNext();
            }
        });
    }

    function bindPanelEvents() {
        $('#oyiso-split-start').on('click', function () {
            if (running) return;
            running = true;
            current = 0;
            $(this).prop('disabled', true).text('拆分中...');
            $('#oyiso-split-cancel').text('停止');
            appendLog('开始拆分 ' + total + ' 个产品...');
            updateProgress();
            splitNext();
        });

        $('#oyiso-split-cancel').on('click', function () {
            if (running) {
                running = false;
                appendLog('⚠ 已手动停止，已处理 ' + current + ' 个产品。', 'error');
                $('#oyiso-split-start').prop('disabled', true).text('已停止');
                $(this).hide();
            } else {
                $('#oyiso-split-notice').fadeOut(200, function () { $(this).remove(); });
            }
        });
    }

    // 拦截批量操作表单提交
    $(document).on('click', '#doaction, #doaction2', function (e) {
        var $select = $(this).prev('select');
        if ($select.val() !== config.bulkActionKey) {
            return;
        }

        e.preventDefault();

        ids = getSelectedIds().reverse();
        if (!ids.length) {
            alert('请先勾选产品。');
            return;
        }

        total = ids.length;
        current = 0;
        running = false;
        showPanel();
    });

})(jQuery);
