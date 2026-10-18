jQuery(function ($) {
    'use strict';

    var $history = $('#oyiso-plugin-update-history');
    var busy = false;
    var refreshPending = false;

    if (!$history.length) {
        return;
    }

    function loadHistory(force) {
        if (busy) {
            refreshPending = refreshPending || force;
            return;
        }

        if (!force && (!$history.is(':visible') || !$history.find('[data-needs-load]').length)) {
            return;
        }

        busy = true;
        $history.attr('aria-busy', 'true');
        $history.find('button').prop('disabled', true);

        $.post(oyisoPluginUpdate.ajaxUrl, {
            action: 'oyiso_plugin_update_history',
            nonce: oyisoPluginUpdate.nonce
        }).done(function (response) {
            if (response && response.success && response.data && typeof response.data.historyHtml === 'string') {
                $history.html(response.data.historyHtml);
                return;
            }

            showError();
        }).fail(showError).always(function () {
            busy = false;
            $history.attr('aria-busy', 'false');
            $history.find('button').prop('disabled', false);

            if (refreshPending) {
                refreshPending = false;
                loadHistory(true);
            }
        });
    }

    function showError() {
        $history.find('.oyiso-update-history-empty, .oyiso-update-history-error').remove();
        $history.prepend($('<p class="oyiso-update-history-error" role="status"/>')
            .text('暂时无法获取更新记录，请稍后重试。 ')
            .append($('<button type="button" class="button-link oyiso-update-history-retry"/>').text('重新获取')));
    }

    $history.on('click', '.oyiso-update-history-retry', function () {
        loadHistory(true);
    });

    $(document).on('oyiso-plugin-update-checked', function () {
        loadHistory(true);
    });

    if ('IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    loadHistory(false);
                }
            });
        });
        observer.observe($history[0]);
    } else {
        $(window).on('hashchange', function () { loadHistory(false); });
        $('.csf-nav').on('click', 'a', function () {
            window.setTimeout(function () { loadHistory(false); }, 0);
        });
        loadHistory(false);
    }
});
