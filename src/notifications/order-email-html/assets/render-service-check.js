(function ($) {
    'use strict';

    $(function () {
        var config = window.oyisoRenderServiceCheck || {};
        var labels = config.labels || {};
        var $serviceButton = $('#oyiso-check-render-service');
        var $keyButton = $('#oyiso-check-render-api-key');
        var $status = $('#oyiso-render-service-check-status');
        var $apiKey = $('[name="oyiso[woo_new_order_email_render_api_key]"]');
        var $buttons = $serviceButton.add($keyButton);

        if (!$buttons.length || !$status.length) {
            return;
        }

        function setStatus(type, message) {
            $status
                .removeClass('is-success is-error')
                .addClass(type === 'success' ? 'is-success' : 'is-error')
                .text(message || '');
        }

        function responseMessage(response, fallback) {
            return response && response.data && response.data.message
                ? response.data.message
                : fallback;
        }

        function runCheck($button, action, data) {
            var originalText = $button.text();
            var requestData = $.extend({
                action: action,
                nonce: config.nonce
            }, data || {});

            $buttons.prop('disabled', true);
            $button.text(labels.checking || '检测中…');
            $status.removeClass('is-success is-error').empty();

            $.post(config.ajaxUrl, requestData)
                .done(function (response) {
                    if (response && response.success) {
                        setStatus('success', responseMessage(response, '检测成功。'));
                        return;
                    }

                    setStatus('error', responseMessage(response, labels.error || '检测失败，请稍后重试。'));
                })
                .fail(function (xhr) {
                    setStatus(
                        'error',
                        responseMessage(xhr.responseJSON, labels.error || '检测失败，请稍后重试。')
                    );
                })
                .always(function () {
                    $buttons.prop('disabled', false);
                    $button.text(originalText);
                });
        }

        $serviceButton.on('click', function () {
            runCheck($serviceButton, 'oyiso_check_render_service');
        });

        $keyButton.on('click', function () {
            var apiKey = $.trim($apiKey.val() || '');

            if (!apiKey) {
                setStatus('error', labels.keyMissing || '请先填写渲染服务 Key。');
                return;
            }

            runCheck($keyButton, 'oyiso_check_render_api_key', { apiKey: apiKey });
        });
    });
}(jQuery));
