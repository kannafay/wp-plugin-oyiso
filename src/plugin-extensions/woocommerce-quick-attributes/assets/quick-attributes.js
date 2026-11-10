(function ($) {
    'use strict';

    if (typeof window.oyisoQAConfig === 'undefined') {
        return;
    }

    var config = window.oyisoQAConfig;
    var $modal = $('#oyiso-qa-modal');
    var $textarea = $('#oyiso-qa-modal-textarea');
    var $preview = $('#oyiso-qa-modal-preview');
    var $spinner = $('.oyiso-qa-modal-spinner');
    var $doBtn = $('.oyiso-qa-modal-do');
    var currentTaxonomy = '';
    var currentIdx = '';
    var $modalTitle = $modal.find('.oyiso-qa-modal-header h2');
    var activeTab = 'new';
    var isSubmitting = false;
    var previewTimer = null;
    var closeTimer = null;
    var requests = { submit: null, preview: null, terms: null };

    function setSubmitting(value) {
        isSubmitting = value;
        $doBtn.prop('disabled', value);
        $spinner.toggleClass('is-active', value);
        $('.oyiso-qa-tab').prop('disabled', value);
        $textarea.prop('disabled', value);
    }

    function cancelRequest(type) {
        var request = requests[type];
        // 先使回调失效，避免 abort 的同步回调影响下一次操作。
        requests[type] = null;
        if (request && request.xhr) {
            request.xhr.abort();
        }
    }

    function cancelPreview() {
        clearTimeout(previewTimer);
        previewTimer = null;
        cancelRequest('preview');
    }

    function resetRequests() {
        cancelPreview();
        cancelRequest('terms');
        cancelRequest('submit');
        setSubmitting(false);
        cachedTerms = null;
    }

    function responseMessage(resp, fallback) {
        return resp && resp.data && typeof resp.data.message === 'string' ? resp.data.message : fallback;
    }

    function requestMessage(xhr, status, fallback) {
        if (status === 'timeout') {
            return '请求超时，请重试。';
        }
        return responseMessage(xhr && xhr.responseJSON, fallback);
    }

    function sendRequest(type, data, onSuccess, onError, onComplete) {
        cancelRequest(type);
        var request = { xhr: null };
        requests[type] = request;

        function finish() {
            if (requests[type] !== request) {
                return;
            }
            requests[type] = null;
            if (onComplete) {
                onComplete();
            }
        }

        function fail(xhr, status) {
            if (requests[type] !== request) {
                return;
            }
            try {
                onError(xhr, status);
            } finally {
                finish();
            }
        }

        try {
            request.xhr = $.ajax({
                url: config.ajaxurl,
                type: 'POST',
                dataType: 'json',
                timeout: 30000,
                data: data,
                success: function (resp) {
                    if (requests[type] !== request) {
                        return;
                    }
                    try {
                        onSuccess(resp);
                    } catch (error) {
                        onError(null, 'callbackerror');
                    } finally {
                        // change 监听器等代码抛错时，jQuery 的 complete 可能不会执行。
                        finish();
                    }
                },
                error: fail
            });
            // 全局 beforeSend 提前取消时，options.error 尚未注册，需接住已拒绝的请求。
            request.xhr.fail(fail);
        } catch (error) {
            fail(null, 'error');
        }
    }

    function validTerms(terms) {
        return Array.isArray(terms) && terms.every(function (term) {
            return term && /^[1-9]\d*$/.test(String(term.id)) && typeof term.name === 'string';
        });
    }

    function setPreviewHint() {
        $preview
            .html('输入后此处将显示哪些值新建、哪些复用')
            .removeClass('oyiso-qa-preview-active oyiso-qa-preview-error oyiso-qa-preview-loading')
            .addClass('oyiso-qa-preview-hint');
    }

    function showInlineError(message) {
        $preview
            .text(message || '请求失败')
            .removeClass('oyiso-qa-preview-active oyiso-qa-preview-hint oyiso-qa-preview-loading')
            .addClass('oyiso-qa-preview-error')
            .show();
    }

    function showPreviewLoading() {
        $preview
            .html('<span class="oyiso-qa-spinner"></span><span>正在查询...</span>')
            .removeClass('oyiso-qa-preview-active oyiso-qa-preview-hint oyiso-qa-preview-error')
            .addClass('oyiso-qa-preview-loading')
            .show();
    }

    // 打开弹窗
    $(document).on('click', '.oyiso-qa-btn', function () {
        clearTimeout(closeTimer);
        resetRequests();
        currentTaxonomy = $(this).data('taxonomy');
        currentIdx = $(this).data('idx');
        $modalTitle.text('快速添加属性值 — ' + ($(this).data('label') || currentTaxonomy));
        $textarea.val('');
        setPreviewHint();
        switchTab('new');
        $modal.css('display', 'flex');
        // force reflow then trigger transition
        $modal[0].offsetHeight;
        $modal.addClass('is-open');
        setTimeout(function () { $textarea.focus(); }, 250);
    });

    // Tab 切换
    function switchTab(tab) {
        if (isSubmitting) {
            return;
        }
        cancelPreview();
        cancelRequest('terms');
        activeTab = tab;
        $('.oyiso-qa-tab').toggleClass('oyiso-qa-tab-active', false);
        $('.oyiso-qa-tab[data-tab="' + tab + '"]').addClass('oyiso-qa-tab-active');
        $('.oyiso-qa-tab-panel').hide();
        $('.oyiso-qa-tab-panel[data-panel="' + tab + '"]').show();

        if (tab === 'existing') {
            fetchTerms();
        } else {
            doPreview();
            setTimeout(function () { $textarea.focus(); }, 100);
        }
    }

    $('.oyiso-qa-tab').on('click', function () {
        switchTab($(this).data('tab'));
    });

    // 获取已有术语
    var cachedTerms = null;

    function fetchTerms() {
        var $list = $('.oyiso-qa-term-list');
        var $count = $('.oyiso-qa-term-count');

        if (cachedTerms && cachedTerms.taxonomy === currentTaxonomy) {
            renderTermList(cachedTerms.terms);
            return;
        }

        $list.html('<span class="spinner oyiso-qa-term-spinner" style="float:none;margin:12px auto;display:block;"></span>');
        $count.text('');

        var taxonomy = currentTaxonomy;
        sendRequest('terms', {
            action: config.getTermsAction,
            nonce: config.nonce,
            taxonomy: taxonomy
        }, function (resp) {
            if (!resp || !resp.success || !resp.data || !validTerms(resp.data.terms)) {
                $list.empty().append($('<div class="oyiso-qa-no-terms">').text(responseMessage(resp, '获取失败，请重试。')));
                return;
            }
            cachedTerms = { taxonomy: taxonomy, terms: resp.data.terms };
            renderTermList(resp.data.terms);
        }, function (xhr, status) {
            $list.empty().append($('<div class="oyiso-qa-no-terms">').text(requestMessage(xhr, status, '获取失败，请重试。')));
        });
    }

    function renderTermList(terms) {
        var $list = $('.oyiso-qa-term-list');
        var $count = $('.oyiso-qa-term-count');

        $count.text('共 ' + terms.length + ' 个');

        if (!terms.length) {
            $list.html('<div class="oyiso-qa-no-terms">该属性下暂无值</div>');
            return;
        }

        var html = '';
        $.each(terms, function (i, term) {
            html += '<label class="oyiso-qa-term-item">' +
                '<input type="checkbox" value="' + term.id + '" class="oyiso-qa-term-check">' +
                '<span>' + $('<span>').text(term.name).html() + '</span>' +
                '</label>';
        });
        $list.html(html);
    }

    // 全选 / 取消全选
    $(document).on('click', '.oyiso-qa-select-all', function () {
        $('.oyiso-qa-term-check').prop('checked', true);
    });

    $(document).on('click', '.oyiso-qa-deselect-all', function () {
        $('.oyiso-qa-term-check').prop('checked', false);
    });

    // 搜索过滤（纯前端，无需防抖）
    $(document).on('input', '.oyiso-qa-term-search', function () {
        var q = $(this).val().toLowerCase();
        $('.oyiso-qa-term-item').each(function () {
            var text = $(this).find('span').text().toLowerCase();
            $(this).toggle(text.indexOf(q) !== -1);
        });
    });

    // 关闭弹窗
    function closeModal() {
        resetRequests();
        $modal.removeClass('is-open');
        clearTimeout(closeTimer);
        closeTimer = setTimeout(function () { $modal.css('display', 'none'); }, 200);
        currentTaxonomy = '';
        currentIdx = '';
    }

    $modal.on('click', '.oyiso-qa-modal-close, .oyiso-qa-modal-cancel', closeModal);
    $modal.on('click', function (e) {
        if (e.target === this) { closeModal(); }
    });

    // 实时预览
    function doPreview() {
        cancelPreview();

        if (isSubmitting || activeTab !== 'new') {
            return;
        }

        var raw = $textarea.val().trim();

        if (!raw || !currentTaxonomy) {
            setPreviewHint();
            return;
        }

        showPreviewLoading();

        sendRequest('preview', {
            action: config.previewAction,
            nonce: config.nonce,
            taxonomy: currentTaxonomy,
            values: raw
        }, function (resp) {
            if (!resp || !resp.success || !resp.data || !Array.isArray(resp.data.new_vals) || !validTerms(resp.data.existing)) {
                showInlineError(responseMessage(resp, '预览失败，请重试。'));
                return;
            }
            var d = resp.data;
            var html = '<p style="margin:0 0 4px;font-weight:600;">预览（共 ' + d.total + ' 个）</p>';
            if (d.new_vals.length) {
                html += '<p style="margin:0;color:#007017;">新建 ' + d.new_vals.length + ' 个：' + $('<span>').text(d.new_vals.join(', ')).html() + '</p>';
            }
            if (d.existing.length) {
                var names = d.existing.map(function (t) { return t.name; });
                html += '<p style="margin:0;color:#646970;">复用 ' + d.existing.length + ' 个：' + $('<span>').text(names.join(', ')).html() + '</p>';
            }
            $preview.html(html).removeClass('oyiso-qa-preview-hint oyiso-qa-preview-loading oyiso-qa-preview-error').addClass('oyiso-qa-preview-active');
        }, function (xhr, status) {
            showInlineError(requestMessage(xhr, status, '预览失败，请重试。'));
        });
    }

    $textarea.on('input', function () {
        cancelPreview();
        previewTimer = setTimeout(doPreview, 200);
    });

    $textarea.on('blur', function () {
        if (previewTimer) {
            doPreview();
        }
    });

    // 确定添加
    $doBtn.on('click', function () {
        if (isSubmitting || !currentTaxonomy) {
            return;
        }
        if (activeTab === 'existing') {
            submitExistingTerms();
            return;
        }

        var raw = $textarea.val().trim();

        if (!raw) {
            return;
        }

        cancelPreview();
        setSubmitting(true);
        var taxonomy = currentTaxonomy;

        sendRequest('submit', {
            action: config.action,
            nonce: config.nonce,
            taxonomy: taxonomy,
            values: raw
        }, function (resp) {
            if (!resp || !resp.success || !resp.data || !validTerms(resp.data.terms) || !resp.data.terms.length) {
                showInlineError(responseMessage(resp, '未获取到有效的属性值，请重试。'));
                return;
            }
            applyTerms(taxonomy, resp.data.terms);
            closeModal();
        }, function (xhr, status) {
            showInlineError(requestMessage(xhr, status, status === 'callbackerror' ? '更新属性选项失败，请重试。' : '请求失败，请重试。'));
        }, function () {
            setSubmitting(false);
        });
    });

    function applyTerms(taxonomy, terms) {
        var $select = $('.attribute_values[data-taxonomy="' + taxonomy + '"]');
        if (!$select.length) {
            throw new Error('Attribute selector is unavailable');
        }
        var selected = $select.val() || [];
        if (!Array.isArray(selected)) {
            selected = [String(selected)];
        }
        $.each(terms, function (i, term) {
            var id = String(term.id);
            if ($select.find('option[value="' + id + '"]').length === 0) {
                $('<option>').val(id).text(term.name).prop('selected', true).appendTo($select);
            }
            if ($.inArray(id, selected) === -1) {
                selected.push(id);
            }
        });
        $select.val(selected).trigger('change');
    }

    function submitExistingTerms() {
        var checked = $('.oyiso-qa-term-check:checked');
        if (!checked.length) {
            return;
        }

        setSubmitting(true);
        try {
            var terms = [];
            checked.each(function () {
                terms.push({ id: $(this).val(), name: $(this).closest('.oyiso-qa-term-item').find('span').text() });
            });
            applyTerms(currentTaxonomy, terms);
            closeModal();
        } catch (error) {
            $('.oyiso-qa-term-count').text('更新属性选项失败，请重试。');
        } finally {
            setSubmitting(false);
        }
    }

})(jQuery);
