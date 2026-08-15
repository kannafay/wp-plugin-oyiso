(function ($) {
    'use strict';

    $(function () {
        var config = window.oyisoWeComTest || {};
        var webhookWrapperSelector = '.csf-cloneable-wrapper[data-field-id="[wecom_webhooks]"]';

        function updateChannelStatus($item) {
            var $input = $item.find('input[name$="[enabled]"]').first();
            var $title = $item.children('.csf-cloneable-title')
                .find('.csf-cloneable-text')
                .first();

            if (!$input.length || !$title.length) {
                return;
            }

            var isEnabled = String($input.val()) === '1';
            var statusText = isEnabled ? '已启用' : '未启用';
            var statusClass = isEnabled ? 'is-enabled' : 'is-disabled';
            var $status = $title.find('.oyiso-wecom-channel-status').first();

            if (!$status.length) {
                $status = $('<span class="oyiso-wecom-channel-status" aria-live="polite"></span>');
                $title.prepend($status);
            }

            if (!$status.hasClass(statusClass) || $status.text() !== statusText) {
                $status
                    .removeClass('is-enabled is-disabled')
                    .addClass(statusClass)
                    .text(statusText);
            }
        }

        function updateAllChannelStatuses() {
            $(webhookWrapperSelector)
                .children('.csf-cloneable-item')
                .each(function () {
                    updateChannelStatus($(this));
                });
        }

        function setStatus($status, type, message) {
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

        updateAllChannelStatuses();

        $(document).on(
            'change',
            webhookWrapperSelector + ' input[name$="[enabled]"]',
            function () {
                updateChannelStatus($(this).closest('.csf-cloneable-item'));
            }
        );

        var webhookWrapper = document.querySelector(webhookWrapperSelector);

        if (webhookWrapper && window.MutationObserver) {
            var observer = new MutationObserver(function () {
                updateAllChannelStatuses();
            });

            observer.observe(webhookWrapper, {
                childList: true,
                subtree: true
            });
        }

        $(document).on('click', '.oyiso-wecom-test-button', function () {
            var $button = $(this);
            var $item = $button.closest('.csf-cloneable-item');
            var $status = $item.find('.oyiso-wecom-test-status').first();
            var $key = $item.find('input[name$="[wecom_webhook_key]"]').first();
            var key = $.trim($key.val() || '');
            var originalText = $button.text();

            if (!key) {
                setStatus($status, 'error', '请先填写 Webhook Key。');
                return;
            }

            $button.prop('disabled', true).text('发送中…');
            $status.removeClass('is-success is-error').empty();

            $.post(config.ajaxUrl, {
                action: 'oyiso_test_wecom_webhook',
                nonce: config.nonce,
                key: key
            })
                .done(function (response) {
                    if (response && response.success) {
                        setStatus($status, 'success', responseMessage(response, '测试消息已发送。'));
                        return;
                    }

                    setStatus($status, 'error', responseMessage(response, '测试发送失败。'));
                })
                .fail(function (xhr) {
                    setStatus($status, 'error', responseMessage(xhr.responseJSON, '测试发送失败。'));
                })
                .always(function () {
                    $button.prop('disabled', false).text(originalText);
                });
        });
    });
}(jQuery));
