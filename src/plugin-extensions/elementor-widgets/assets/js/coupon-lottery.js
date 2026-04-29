(function () {
    var widgetSeed = 0;
    var modalThemeProperties = [
        '--oyiso-lottery-accent',
        '--oyiso-lottery-panel-bg',
        '--oyiso-lottery-surface',
        '--oyiso-lottery-surface-soft',
        '--oyiso-lottery-line',
        '--oyiso-lottery-title-color',
        '--oyiso-lottery-description-color',
        '--oyiso-lottery-text-strong',
        '--oyiso-lottery-text-muted',
        '--oyiso-lottery-status-bg',
        '--oyiso-lottery-status-border',
        '--oyiso-lottery-status-color',
        '--oyiso-lottery-status-error-bg',
        '--oyiso-lottery-status-error-border',
        '--oyiso-lottery-status-error-color',
        '--oyiso-lottery-primary-bg',
        '--oyiso-lottery-primary-border',
        '--oyiso-lottery-primary-text',
        '--oyiso-lottery-secondary-bg',
        '--oyiso-lottery-secondary-text',
        '--oyiso-lottery-record-bg',
        '--oyiso-lottery-record-border',
        '--oyiso-lottery-result-lose',
        '--oyiso-scope-group-color'
    ];

    function post(action, widget, extra) {
        var params = new URLSearchParams();
        params.set('action', action);
        params.set('nonce', oyisoCouponLotteryI18n.nonce);
        params.set('payload', widget.dataset.payload || '');
        params.set('signature', widget.dataset.signature || '');

        Object.keys(extra || {}).forEach(function (key) {
            params.set(key, extra[key]);
        });

        return fetch(oyisoCouponLotteryI18n.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: params.toString()
        }).then(function (response) {
            return response.json().catch(function () {
                return {
                    success: false,
                    data: {
                        message: oyisoCouponLotteryI18n.loadFailed
                    }
                };
            });
        });
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function toClassToken(value) {
        return String(value || '')
            .toLowerCase()
            .replace(/[^a-z0-9_-]+/g, '-')
            .replace(/^-+|-+$/g, '') || 'default';
    }

    function copyText(text) {
        if (!text) {
            return Promise.reject();
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text);
        }

        var input = document.createElement('input');
        input.value = text;
        document.body.appendChild(input);
        input.select();
        document.execCommand('copy');
        document.body.removeChild(input);
        return Promise.resolve();
    }

    function setStatus(widget, text, isError) {
        var target = widget.querySelector('[data-lottery-status]');

        if (!target) {
            return;
        }

        target.textContent = text || '';
        target.classList.toggle('is-error', !!isError);
    }

    function formatLabel(template, value) {
        return String(template || '').replace('%d', value);
    }

    function setDrawingState(widget, active) {
        var drawButton = widget.querySelector('[data-lottery-draw]');
        var drawLabel = drawButton ? drawButton.querySelector('.oyiso-coupon-lottery__button-label') : null;

        widget.classList.toggle('is-drawing', !!active);

        if (!drawButton || !drawLabel) {
            return;
        }

        if (!drawButton.dataset.defaultLabel) {
            drawButton.dataset.defaultLabel = drawLabel.textContent || '';
        }

        drawLabel.textContent = active
            ? (oyisoCouponLotteryI18n.drawLoading || oyisoCouponLotteryI18n.loading)
            : drawButton.dataset.defaultLabel;
    }

    function finishDrawingState(widget) {
        var drawButton = widget ? widget.querySelector('[data-lottery-draw]') : null;

        if (drawButton) {
            drawButton.disabled = !!(widget && widget._oyisoDrawAvailabilityDisabled);
        }

        setDrawingState(widget, false);
    }

    function ensureWidgetId(widget) {
        if (!widget) {
            return '';
        }

        if (!widget.dataset.lotteryWidgetId) {
            widgetSeed += 1;
            widget.dataset.lotteryWidgetId = 'oyiso-lottery-' + widgetSeed;
        }

        return widget.dataset.lotteryWidgetId;
    }

    function getWidgetFromNode(node) {
        if (!node) {
            return null;
        }

        var widget = node.closest('[data-oyiso-coupon-lottery]');

        if (widget) {
            return widget;
        }

        var modal = node.closest('[data-lottery-owner-id]');

        if (!modal || !modal.dataset.lotteryOwnerId) {
            return null;
        }

        return document.querySelector('[data-oyiso-coupon-lottery][data-lottery-widget-id="' + modal.dataset.lotteryOwnerId + '"]');
    }

    function mountModalToBody(widget, selector, propName) {
        var modal = widget.querySelector(selector);

        if (!modal) {
            return null;
        }

        ensureWidgetId(widget);
        modal.dataset.lotteryOwnerId = widget.dataset.lotteryWidgetId;
        widget[propName] = modal;

        if (modal.parentNode !== document.body) {
            document.body.appendChild(modal);
        }

        return modal;
    }

    function getResultModal(widget) {
        return widget ? (widget._oyisoResultModal || widget.querySelector('[data-lottery-result-modal]')) : null;
    }

    function getRecordsModal(widget) {
        return widget ? (widget._oyisoRecordsModal || widget.querySelector('[data-lottery-records-modal]')) : null;
    }

    function getResultElement(widget, selector) {
        var modal = getResultModal(widget);
        return modal ? modal.querySelector(selector) : null;
    }

    function getRecordsElement(widget, selector) {
        var modal = getRecordsModal(widget);
        return modal ? modal.querySelector(selector) : null;
    }

    function syncModalStyles(widget, modal) {
        var computed;
        var index;
        var property;
        var value;

        if (!widget || !modal || !window.getComputedStyle) {
            return;
        }

        computed = window.getComputedStyle(widget);

        for (index = 0; index < modalThemeProperties.length; index += 1) {
            property = modalThemeProperties[index];

            value = computed.getPropertyValue(property);

            if (value) {
                modal.style.setProperty(property, value.trim());
            }
        }
    }

    function syncWidgetModals(widget) {
        syncModalStyles(widget, getResultModal(widget));
        syncModalStyles(widget, getRecordsModal(widget));
    }

    function applyResultState(widget, resultType) {
        var resultPanel = getResultElement(widget, '[data-lottery-result-panel]');

        if (!resultPanel) {
            return;
        }

        resultPanel.classList.remove('is-win', 'is-lose');
        resultPanel.classList.add(resultType === 'win' ? 'is-win' : 'is-lose');
    }

    function updateAvailability(widget, availability) {
        if (!availability) {
            return;
        }

        var drawButton = widget.querySelector('[data-lottery-draw]');
        var parts = [];

        if (!availability.allowed && availability.reason) {
            setStatus(widget, availability.reason, true);
            widget._oyisoDrawAvailabilityDisabled = true;
            if (drawButton) {
                drawButton.disabled = true;
            }
            return;
        }

        widget._oyisoDrawAvailabilityDisabled = false;

        if (availability.total_remaining !== null) {
            parts.push(formatLabel(oyisoCouponLotteryI18n.totalRemaining, availability.total_remaining));
        }

        if (availability.daily_remaining !== null) {
            parts.push(formatLabel(oyisoCouponLotteryI18n.dailyRemaining, availability.daily_remaining));
        }

        if (availability.prize_pool_remaining !== null) {
            parts.push(formatLabel(oyisoCouponLotteryI18n.prizePoolRemaining, availability.prize_pool_remaining));
        }

        setStatus(widget, parts.length ? parts.join(' / ') : oyisoCouponLotteryI18n.availableNow, false);
    }

    function openModal(modal) {
        if (!modal) {
            return;
        }

        modal.hidden = false;
        modal.classList.remove('is-closing');
        document.body.classList.add('oyiso-lottery-modal-open');
    }

    function closeModal(modal) {
        if (!modal) {
            return;
        }

        if (modal.hidden || modal.classList.contains('is-closing')) {
            return;
        }

        modal.classList.add('is-closing');
        document.body.classList.remove('oyiso-lottery-modal-open');

        window.setTimeout(function () {
            modal.hidden = true;
            modal.classList.remove('is-closing');
        }, 180);
    }

    function renderRecordList(widget, key, records) {
        if (!records.length) {
            return '<div class="oyiso-coupon-lottery__empty">' + escapeHtml(oyisoCouponLotteryI18n.emptyRecords) + '</div>';
        }

        return records.map(function (record) {
            return renderRecordItem(widget, key, record);
        }).join('');
    }

    function renderRecordActions(record) {
        var actions = '';

        if (record.canClaim) {
            actions += '<button type="button" class="oyiso-coupon-lottery__inline-button oyiso-coupon-lottery__inline-button--primary" data-lottery-record-claim="' + record.id + '">' + escapeHtml(oyisoCouponLotteryI18n.claimButton) + '</button>';
        }

        if (record.resultType === 'win' && record.scopeHtml) {
            actions += '<button type="button" class="oyiso-coupon-lottery__inline-button" data-coupon-scope="' + escapeHtml(record.scopeHtml) + '" data-coupon-code="' + escapeHtml(record.couponCode || '') + '">' + escapeHtml(oyisoCouponLotteryI18n.recordScopeButton || oyisoCouponLotteryI18n.scopeButton || '') + '</button>';
        }

        if (record.couponCode && record.status === 'claimed') {
            actions += '<button type="button" class="oyiso-coupon-lottery__inline-button" data-lottery-record-copy="' + escapeHtml(record.couponCode) + '">' + escapeHtml(oyisoCouponLotteryI18n.recordCopy || oyisoCouponLotteryI18n.copy) + '</button>';
        }

        if (record.editUrl) {
            actions += '<a class="oyiso-coupon-lottery__inline-button" href="' + escapeHtml(record.editUrl) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(oyisoCouponLotteryI18n.editButton) + '</a>';
        }

        return actions;
    }

    function normalizeClaimedRecord(record, data) {
        var nextRecord = Object.assign({}, record || {}, data && data.record ? data.record : {});

        if (!nextRecord || !nextRecord.id) {
            return nextRecord;
        }

        if (data && data.couponCode) {
            nextRecord.couponCode = data.couponCode;
        }

        nextRecord.status = 'claimed';
        nextRecord.statusLabel = oyisoCouponLotteryI18n.claimedLabel || nextRecord.statusLabel || 'Claimed';
        nextRecord.canClaim = false;

        return nextRecord;
    }

    function renderRecordDetail(label, value, modifier) {
        if (!value) {
            return '';
        }

        return '' +
            '<span class="oyiso-coupon-lottery__record-detail' + (modifier ? ' oyiso-coupon-lottery__record-detail--' + modifier : '') + '">' +
                '<span class="oyiso-coupon-lottery__record-detail-label">' + escapeHtml(label) + '</span>' +
                '<span class="oyiso-coupon-lottery__record-detail-value">' + escapeHtml(value) + '</span>' +
            '</span>';
    }

    function renderRecordDetails(widget, key, record) {
        var details = '';

        if (record.couponCode && record.status === 'claimed') {
            details += renderRecordDetail(oyisoCouponLotteryI18n.couponLabel, record.couponCode, 'code');
        }

        if (key === 'all' && record.userName) {
            details += renderRecordDetail(oyisoCouponLotteryI18n.userLabel, record.userName, 'user');
        }

        details += renderRecordDetail(oyisoCouponLotteryI18n.timeLabel, record.createdAt, 'time');

        return details;
    }

    function renderRecordItem(widget, key, record) {
        var recordStatusClass = toClassToken(record.status || '');
        var actions = renderRecordActions(record);

        return '' +
            '<article class="oyiso-coupon-lottery__record-item oyiso-coupon-lottery__record-item--' + recordStatusClass + '" data-lottery-record-id="' + record.id + '">' +
                '<div class="oyiso-coupon-lottery__record-main">' +
                    '<div class="oyiso-coupon-lottery__record-head">' +
                        '<div class="oyiso-coupon-lottery__record-title">' + escapeHtml(record.prizeLabel) + '</div>' +
                        '<span class="oyiso-coupon-lottery__record-status oyiso-coupon-lottery__record-status--' + recordStatusClass + '">' + escapeHtml(record.statusLabel) + '</span>' +
                    '</div>' +
                    '<div class="oyiso-coupon-lottery__record-details">' + renderRecordDetails(widget, key, record) + '</div>' +
                '</div>' +
                (actions ? '<div class="oyiso-coupon-lottery__record-actions">' + actions + '</div>' : '') +
            '</article>';
    }

    function renderLoadMoreButton(hasMore) {
        if (!hasMore) {
            return '';
        }

        return '' +
            '<div class="oyiso-coupon-lottery__record-more">' +
                '<button type="button" class="oyiso-coupon-lottery__button oyiso-coupon-lottery__button--secondary oyiso-coupon-lottery__record-more-button" data-lottery-record-more>' + escapeHtml(oyisoCouponLotteryI18n.loadMore) + '</button>' +
            '</div>';
    }

    function updateRecordItems(widget, record) {
        var recordsModal = getRecordsModal(widget);

        if (!recordsModal || !record || !record.id) {
            return;
        }

        recordsModal.querySelectorAll('[data-lottery-record-id="' + record.id + '"]').forEach(function (item) {
            var panel = item.closest('[data-lottery-record-panel]');
            var key = panel ? panel.dataset.lotteryRecordPanel : 'mine';

            item.outerHTML = renderRecordItem(widget, key, record);
        });
    }

    function getRecordPanel(modal, key) {
        return modal ? modal.querySelector('[data-lottery-record-panel="' + key + '"]') : null;
    }

    function setPanelMeta(panel, key, data) {
        var nextOffset = data && typeof data.nextOffset !== 'undefined' ? parseInt(data.nextOffset, 10) : 0;
        panel.dataset.lotteryRecordPanel = key;
        panel.dataset.lotteryOffset = String(isNaN(nextOffset) ? 0 : nextOffset);
        panel.dataset.lotteryHasMore = data && data.hasMore ? '1' : '0';
    }

    function renderRecordPanel(widget, key, data, append) {
        var recordsModal = getRecordsModal(widget);
        var panel = getRecordPanel(recordsModal, key);
        var itemsRoot;
        var moreRoot;
        var items = data && data.items ? data.items : [];

        if (!panel) {
            return;
        }

        itemsRoot = panel.querySelector('[data-lottery-record-items]');
        moreRoot = panel.querySelector('[data-lottery-record-more-wrap]');

        if (!itemsRoot || !moreRoot) {
            return;
        }

        if (!append) {
            itemsRoot.innerHTML = renderRecordList(widget, key, items);
        } else if (items.length) {
            itemsRoot.insertAdjacentHTML('beforeend', items.map(function (record) {
                return renderRecordItem(widget, key, record);
            }).join(''));
        }

        if (append && !items.length && !itemsRoot.children.length) {
            itemsRoot.innerHTML = renderRecordList(widget, key, []);
        }

        moreRoot.innerHTML = renderLoadMoreButton(!!(data && data.hasMore));
        setPanelMeta(panel, key, data || {});
    }

    function renderRecordsModal(widget, records) {
        var tabsRoot = getRecordsElement(widget, '[data-lottery-record-tabs]');
        var panelsRoot = getRecordsElement(widget, '[data-lottery-record-panels]');
        var keys = Object.keys(records || {});

        if (!tabsRoot || !panelsRoot) {
            return;
        }

        tabsRoot.innerHTML = '';
        panelsRoot.innerHTML = '';

        keys.forEach(function (key, index) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'oyiso-coupon-lottery__tab' + (index === 0 ? ' is-active' : '');
            button.dataset.lotteryRecordTab = key;
            button.textContent = key === 'all' ? oyisoCouponLotteryI18n.allRecords : oyisoCouponLotteryI18n.myRecords;
            tabsRoot.appendChild(button);

            var panel = document.createElement('div');
            panel.className = 'oyiso-coupon-lottery__record-panel' + (index === 0 ? ' is-active' : '');
            panel.dataset.lotteryRecordPanel = key;
            panel.hidden = index !== 0;
            panel.innerHTML = '' +
                '<div class="oyiso-coupon-lottery__record-items" data-lottery-record-items></div>' +
                '<div class="oyiso-coupon-lottery__record-more-wrap" data-lottery-record-more-wrap></div>';
            panelsRoot.appendChild(panel);
            renderRecordPanel(widget, key, records[key] || {}, false);
        });
    }

    function handleClaimSuccess(widget, data) {
        var successBlock = getResultElement(widget, '[data-lottery-claim-success]');
        var codeTarget = getResultElement(widget, '[data-lottery-coupon-code]');
        var claimButton = getResultElement(widget, '[data-lottery-claim]');
        var claimCopyButton = getResultElement(widget, '[data-lottery-claim-copy]');

        if (codeTarget) {
            codeTarget.textContent = data.couponCode || '';
        }

        if (successBlock) {
            successBlock.hidden = false;
        }

        if (claimButton) {
            claimButton.hidden = true;
        }

        if (claimCopyButton) {
            claimCopyButton.hidden = !data.couponCode;
            claimCopyButton.dataset.lotteryCopy = data.couponCode || '';
        }
    }

    function loadRecords(widget) {
        var panelsRoot = getRecordsElement(widget, '[data-lottery-record-panels]');

        if (panelsRoot) {
            panelsRoot.innerHTML = '<div class="oyiso-coupon-lottery__empty">' + escapeHtml(oyisoCouponLotteryI18n.loading) + '</div>';
        }

        return post('oyiso_coupon_lottery_records', widget, {}).then(function (response) {
            if (!response || !response.success) {
                throw new Error(response && response.data && response.data.message ? response.data.message : oyisoCouponLotteryI18n.loadFailed);
            }

            renderRecordsModal(widget, response.data.records || {});
        }).catch(function (error) {
            if (panelsRoot) {
                panelsRoot.innerHTML = '<div class="oyiso-coupon-lottery__empty">' + escapeHtml(error.message || oyisoCouponLotteryI18n.loadFailed) + '</div>';
            }
        });
    }

    function loadMoreRecords(widget, key, button) {
        var recordsModal = getRecordsModal(widget);
        var panel = getRecordPanel(recordsModal, key);
        var offset;
        var originalLabel;

        if (!panel) {
            return Promise.resolve();
        }

        offset = parseInt(panel.dataset.lotteryOffset || '0', 10);
        originalLabel = button ? button.textContent : '';

        if (button) {
            button.disabled = true;
            button.textContent = oyisoCouponLotteryI18n.loadingMore || oyisoCouponLotteryI18n.loading;
        }

        return post('oyiso_coupon_lottery_records', widget, {
            tab: key,
            offset: isNaN(offset) ? 0 : offset
        }).then(function (response) {
            if (!response || !response.success) {
                throw new Error(response && response.data && response.data.message ? response.data.message : oyisoCouponLotteryI18n.loadFailed);
            }

            renderRecordPanel(widget, key, response.data.data || {}, true);
        }).catch(function (error) {
            setStatus(widget, error.message || oyisoCouponLotteryI18n.loadFailed, true);
        }).finally(function () {
            if (button && document.body.contains(button)) {
                button.disabled = false;
                button.textContent = originalLabel || oyisoCouponLotteryI18n.loadMore;
            }
        });
    }

    function initWidget(widget) {
        var drawButton;

        if (!widget || widget.dataset.lotteryModalReady === '1') {
            return;
        }

        mountModalToBody(widget, '[data-lottery-result-modal]', '_oyisoResultModal');
        mountModalToBody(widget, '[data-lottery-records-modal]', '_oyisoRecordsModal');
        syncWidgetModals(widget);
        drawButton = widget.querySelector('[data-lottery-draw]');
        widget._oyisoDrawAvailabilityDisabled = !!(drawButton && drawButton.disabled);
        widget.dataset.lotteryModalReady = '1';
    }

    document.querySelectorAll('[data-oyiso-coupon-lottery]').forEach(initWidget);

    document.addEventListener('click', function (event) {
        var drawButton = event.target.closest('[data-lottery-draw]');
        var claimButton = event.target.closest('[data-lottery-claim]');
        var recordsButton = event.target.closest('[data-lottery-records]');
        var closeButton = event.target.closest('[data-lottery-close]');
        var closeRecordsButton = event.target.closest('[data-lottery-records-close]');
        var tabButton = event.target.closest('[data-lottery-record-tab]');
        var moreButton = event.target.closest('[data-lottery-record-more]');
        var copyButton = event.target.closest('[data-lottery-copy]');
        var claimCopyButton = event.target.closest('[data-lottery-claim-copy]');
        var recordCopyButton = event.target.closest('[data-lottery-record-copy]');
        var recordClaimButton = event.target.closest('[data-lottery-record-claim]');

        if (drawButton) {
            var widget = getWidgetFromNode(drawButton);

            if (!widget) {
                return;
            }

            drawButton.disabled = true;
            setDrawingState(widget, true);

            post('oyiso_coupon_lottery_draw', widget, {}).then(function (response) {
                if (!response || !response.success) {
                    if (response && response.data && response.data.availability) {
                        updateAvailability(widget, response.data.availability);
                    }
                    throw new Error(response && response.data && response.data.message ? response.data.message : oyisoCouponLotteryI18n.loadFailed);
                }

                var resultModal = getResultModal(widget);
                var resultLabel = getResultElement(widget, '[data-lottery-result-label]');
                var resultMessage = getResultElement(widget, '[data-lottery-result-message]');
                var resultDetails = getResultElement(widget, '[data-lottery-result-details]');
                var resultDetailsContent = getResultElement(widget, '[data-lottery-result-details-content]');
                var claimBtn = getResultElement(widget, '[data-lottery-claim]');
                var claimCopyBtn = getResultElement(widget, '[data-lottery-claim-copy]');
                var successBlock = getResultElement(widget, '[data-lottery-claim-success]');
                var codeTarget = getResultElement(widget, '[data-lottery-coupon-code]');

                if (resultLabel) {
                    resultLabel.textContent = response.data.prizeLabel || '';
                }

                if (resultMessage) {
                    resultMessage.textContent = response.data.message || '';
                }

                if (resultDetailsContent) {
                    resultDetailsContent.textContent = response.data.couponDescription || '';
                }

                if (resultDetails) {
                    resultDetails.hidden = !response.data.claimable || !(response.data.couponDescription || '');
                }

                if (successBlock) {
                    successBlock.hidden = true;
                }

                if (codeTarget) {
                    codeTarget.textContent = '';
                }

                if (claimBtn) {
                    claimBtn.hidden = !response.data.claimable;
                    claimBtn.disabled = false;
                    claimBtn.dataset.recordId = response.data.recordId || '';
                    claimBtn.textContent = response.data.claimButton || oyisoCouponLotteryI18n.claimButton;
                }

                if (claimCopyBtn) {
                    claimCopyBtn.hidden = true;
                    claimCopyBtn.dataset.lotteryCopy = '';
                }

                applyResultState(widget, response.data.resultType || 'lose');
                updateAvailability(widget, response.data.availability || null);

                window.setTimeout(function () {
                    syncWidgetModals(widget);
                    openModal(resultModal);
                    finishDrawingState(widget);
                }, 920);
            }).catch(function (error) {
                setStatus(widget, error.message || oyisoCouponLotteryI18n.loadFailed, true);
                finishDrawingState(widget);
            });

            return;
        }

        if (claimButton) {
            var claimWidget = getWidgetFromNode(claimButton);
            var recordId = claimButton.dataset.recordId || '';

            if (!claimWidget || !recordId) {
                return;
            }

            claimButton.disabled = true;

            post('oyiso_coupon_lottery_claim', claimWidget, {
                recordId: recordId
            }).then(function (response) {
                if (!response || !response.success) {
                    throw new Error(response && response.data && response.data.message ? response.data.message : oyisoCouponLotteryI18n.loadFailed);
                }

                handleClaimSuccess(claimWidget, response.data || {});
                updateRecordItems(claimWidget, normalizeClaimedRecord({
                    id: recordId
                }, response.data || {}));
                setStatus(claimWidget, response.data.message || '', false);
            }).catch(function (error) {
                setStatus(claimWidget, error.message || oyisoCouponLotteryI18n.loadFailed, true);
            }).finally(function () {
                claimButton.disabled = false;
            });

            return;
        }

        if (recordsButton) {
            var recordsWidget = getWidgetFromNode(recordsButton);

            if (!recordsWidget) {
                return;
            }

            syncWidgetModals(recordsWidget);
            openModal(getRecordsModal(recordsWidget));
            loadRecords(recordsWidget);
            return;
        }

        if (closeButton) {
            closeModal(closeButton.closest('[data-lottery-result-modal]'));
            return;
        }

        if (closeRecordsButton) {
            closeModal(closeRecordsButton.closest('[data-lottery-records-modal]'));
            return;
        }

        if (tabButton) {
            var modal = tabButton.closest('[data-lottery-records-modal]');

            if (!modal) {
                return;
            }

            modal.querySelectorAll('[data-lottery-record-tab]').forEach(function (button) {
                button.classList.toggle('is-active', button === tabButton);
            });

            modal.querySelectorAll('[data-lottery-record-panel]').forEach(function (panel) {
                var active = panel.dataset.lotteryRecordPanel === tabButton.dataset.lotteryRecordTab;
                panel.hidden = !active;
                panel.classList.toggle('is-active', active);
            });

            return;
        }

        if (claimCopyButton) {
            copyText(claimCopyButton.dataset.lotteryCopy || '').then(function () {
                claimCopyButton.textContent = oyisoCouponLotteryI18n.copied;
                window.setTimeout(function () {
                    claimCopyButton.textContent = oyisoCouponLotteryI18n.copy;
                }, 1600);
            });

            return;
        }

        if (moreButton) {
            var moreModal = moreButton.closest('[data-lottery-records-modal]');
            var morePanel = moreButton.closest('[data-lottery-record-panel]');
            var moreWidget = getWidgetFromNode(moreButton);

            if (!moreModal || !morePanel || !moreWidget) {
                return;
            }

            loadMoreRecords(moreWidget, morePanel.dataset.lotteryRecordPanel || 'mine', moreButton);
            return;
        }

        if (copyButton) {
            var resultWidget = getWidgetFromNode(copyButton);
            var codeTarget = resultWidget ? getResultElement(resultWidget, '[data-lottery-coupon-code]') : null;

            copyText(codeTarget ? codeTarget.textContent : '').then(function () {
                copyButton.textContent = oyisoCouponLotteryI18n.copied;
                window.setTimeout(function () {
                    copyButton.textContent = oyisoCouponLotteryI18n.copy;
                }, 1600);
            });

            return;
        }

        if (recordCopyButton) {
            copyText(recordCopyButton.dataset.lotteryRecordCopy || '').then(function () {
                recordCopyButton.textContent = oyisoCouponLotteryI18n.copied;
                window.setTimeout(function () {
                    recordCopyButton.textContent = oyisoCouponLotteryI18n.recordCopy || oyisoCouponLotteryI18n.copy;
                }, 1600);
            });

            return;
        }

        if (recordClaimButton) {
            var recordWidget = getWidgetFromNode(recordClaimButton);
            var recordClaimId = recordClaimButton.dataset.lotteryRecordClaim || '';

            if (!recordWidget || !recordClaimId) {
                return;
            }

            recordClaimButton.disabled = true;

            post('oyiso_coupon_lottery_claim', recordWidget, {
                recordId: recordClaimId
            }).then(function (response) {
                if (!response || !response.success) {
                    throw new Error(response && response.data && response.data.message ? response.data.message : oyisoCouponLotteryI18n.loadFailed);
                }

                setStatus(recordWidget, response.data.message || '', false);
                updateRecordItems(recordWidget, normalizeClaimedRecord({
                    id: recordClaimId
                }, response.data || {}));
            }).catch(function (error) {
                setStatus(recordWidget, error.message || oyisoCouponLotteryI18n.loadFailed, true);
            }).finally(function () {
                recordClaimButton.disabled = false;
            });
        }
    });
})();
