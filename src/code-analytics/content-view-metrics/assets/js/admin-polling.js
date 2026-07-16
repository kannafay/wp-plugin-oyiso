(function ($) {
    'use strict';

    var config = window.oyisoContentViewMetricsPolling || null;
    if (!config || !config.ajaxUrl || !config.action || !config.nonce || !config.postType) {
        return;
    }

    if (window.oyisoContentViewMetricsPollingStarted) {
        return;
    }
    window.oyisoContentViewMetricsPollingStarted = true;

    var request = null;
    var timer = null;
    var stopped = false;
    var pollInterval = Math.max(1000, Number(config.pollInterval) || 5000);
    var requestTimeout = Math.max(1000, Number(config.requestTimeout) || 10000);
    var retryDelay = Math.max(1000, Number(config.retryDelay) || 5000);

    function collectState() {
        var postIds = [];
        var state = {};

        document.querySelectorAll('[data-oyiso-view-metrics][data-post-id]').forEach(function (element) {
            var postId = String(element.getAttribute('data-post-id') || '');
            if (!/^\d+$/.test(postId)) {
                return;
            }

            postIds.push(postId);
            state[postId] = String(element.getAttribute('data-metrics-hash') || '');
        });

        return {
            postIds: postIds,
            state: state
        };
    }

    function schedule(delay) {
        window.clearTimeout(timer);
        timer = null;

        if (stopped || document.hidden) {
            return;
        }

        timer = window.setTimeout(poll, delay);
    }

    function replaceChangedItems(items) {
        if (!items || typeof items !== 'object') {
            return;
        }

        Object.keys(items).forEach(function (postId) {
            var item = items[postId];
            if (!item || typeof item.html !== 'string') {
                return;
            }

            var element = document.querySelector('[data-oyiso-view-metrics][data-post-id="' + postId + '"]');
            if (element) {
                $(element).replaceWith(item.html);
            }
        });
    }

    function poll() {
        if (stopped || document.hidden || request) {
            return;
        }

        var snapshot = collectState();
        if (!snapshot.postIds.length) {
            return;
        }

        var currentRequest = $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            dataType: 'json',
            timeout: requestTimeout,
            data: {
                action: config.action,
                nonce: config.nonce,
                post_type: config.postType,
                post_ids: snapshot.postIds,
                state: JSON.stringify(snapshot.state)
            }
        });
        var nextDelay = null;

        request = currentRequest;

        currentRequest.done(function (response) {
            if (!response || response.success !== true || !response.data) {
                nextDelay = retryDelay;
                return;
            }

            replaceChangedItems(response.data.items);
            nextDelay = pollInterval;
        }).fail(function (xhr, status) {
            if (status !== 'abort') {
                nextDelay = retryDelay;
            }
        }).always(function () {
            if (request === currentRequest) {
                request = null;
            }

            if (nextDelay !== null) {
                schedule(nextDelay);
            }
        });
    }

    function abortRequest() {
        window.clearTimeout(timer);
        timer = null;

        if (!request) {
            return;
        }

        var currentRequest = request;
        request = null;
        currentRequest.abort();
    }

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            abortRequest();
            return;
        }

        schedule(pollInterval);
    });

    window.addEventListener('pagehide', function () {
        stopped = true;
        abortRequest();
    });

    schedule(pollInterval);
}(jQuery));
