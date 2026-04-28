(function () {
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

    function updateAvailability(widget, availability) {
        if (!availability) {
            return;
        }

        var drawButton = widget.querySelector('[data-lottery-draw]');
        var parts = [];

        if (!availability.allowed && availability.reason) {
            setStatus(widget, availability.reason, true);
            if (drawButton) {
                drawButton.disabled = true;
            }
            return;
        }

        if (availability.total_remaining !== null) {
            parts.push(formatLabel(oyisoCouponLotteryI18n.totalRemaining, availability.total_remaining));
        }

        if (availability.daily_remaining !== null) {
            parts.push(formatLabel(oyisoCouponLotteryI18n.dailyRemaining, availability.daily_remaining));
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
        var showResultMeta = widget.dataset.showResultMeta !== '0';

        if (!records.length) {
            return '<div class="oyiso-coupon-lottery__empty">' + escapeHtml(oyisoCouponLotteryI18n.emptyRecords) + '</div>';
        }

        return records.map(function (record) {
            var actions = '';

            if (record.canClaim) {
                actions += '<button type="button" class="oyiso-coupon-lottery__inline-button oyiso-coupon-lottery__inline-button--primary" data-lottery-record-claim="' + record.id + '">' + escapeHtml(oyisoCouponLotteryI18n.claimButton) + '</button>';
            }

            if (record.couponCode && record.status === 'claimed') {
                actions += '<button type="button" class="oyiso-coupon-lottery__inline-button" data-lottery-record-copy="' + escapeHtml(record.couponCode) + '">' + escapeHtml(oyisoCouponLotteryI18n.copy) + '</button>';
            }

            if (record.editUrl) {
                actions += '<a class="oyiso-coupon-lottery__inline-button" href="' + escapeHtml(record.editUrl) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(oyisoCouponLotteryI18n.editButton) + '</a>';
            }

            var metaItems = '';

            if (showResultMeta) {
                metaItems += '<span>' + escapeHtml(oyisoCouponLotteryI18n.resultLabel) + '：' + escapeHtml(record.resultLabel) + '</span>';
            }

            metaItems += '<span>' + escapeHtml(oyisoCouponLotteryI18n.statusLabel) + '：' + escapeHtml(record.statusLabel) + '</span>';
            metaItems += '<span>' + escapeHtml(oyisoCouponLotteryI18n.timeLabel) + '：' + escapeHtml(record.createdAt) + '</span>';

            if (key === 'all' && record.userName) {
                metaItems += '<span>' + escapeHtml(oyisoCouponLotteryI18n.userLabel) + '：' + escapeHtml(record.userName) + '</span>';
            }

            if (record.couponCode && record.status === 'claimed') {
                metaItems += '<span>' + escapeHtml(oyisoCouponLotteryI18n.couponLabel) + '：' + escapeHtml(record.couponCode) + '</span>';
            }

            return '' +
                '<article class="oyiso-coupon-lottery__record-item">' +
                    '<div class="oyiso-coupon-lottery__record-main">' +
                        '<div class="oyiso-coupon-lottery__record-title">' + escapeHtml(record.prizeLabel) + '</div>' +
                        '<div class="oyiso-coupon-lottery__record-meta">' + metaItems + '</div>' +
                    '</div>' +
                    (actions ? '<div class="oyiso-coupon-lottery__record-actions">' + actions + '</div>' : '') +
                '</article>';
        }).join('');
    }

    function renderRecordsModal(widget, records) {
        var tabsRoot = widget.querySelector('[data-lottery-record-tabs]');
        var panelsRoot = widget.querySelector('[data-lottery-record-panels]');
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
            panel.innerHTML = renderRecordList(widget, key, records[key] || []);
            panelsRoot.appendChild(panel);
        });
    }

    function handleClaimSuccess(widget, data) {
        var successBlock = widget.querySelector('[data-lottery-claim-success]');
        var codeTarget = widget.querySelector('[data-lottery-coupon-code]');
        var claimButton = widget.querySelector('[data-lottery-claim]');

        if (codeTarget) {
            codeTarget.textContent = data.couponCode || '';
        }

        if (successBlock) {
            successBlock.hidden = false;
        }

        if (claimButton) {
            claimButton.hidden = true;
        }
    }

    function loadRecords(widget) {
        var panelsRoot = widget.querySelector('[data-lottery-record-panels]');

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

    document.addEventListener('click', function (event) {
        var drawButton = event.target.closest('[data-lottery-draw]');
        var claimButton = event.target.closest('[data-lottery-claim]');
        var recordsButton = event.target.closest('[data-lottery-records]');
        var closeButton = event.target.closest('[data-lottery-close]');
        var closeRecordsButton = event.target.closest('[data-lottery-records-close]');
        var tabButton = event.target.closest('[data-lottery-record-tab]');
        var copyButton = event.target.closest('[data-lottery-copy]');
        var recordCopyButton = event.target.closest('[data-lottery-record-copy]');
        var recordClaimButton = event.target.closest('[data-lottery-record-claim]');

        if (drawButton) {
            var widget = drawButton.closest('[data-oyiso-coupon-lottery]');

            if (!widget) {
                return;
            }

            drawButton.disabled = true;
            setStatus(widget, oyisoCouponLotteryI18n.loading, false);

            post('oyiso_coupon_lottery_draw', widget, {}).then(function (response) {
                if (!response || !response.success) {
                    throw new Error(response && response.data && response.data.message ? response.data.message : oyisoCouponLotteryI18n.loadFailed);
                }

                var resultModal = widget.querySelector('[data-lottery-result-modal]');
                var resultLabel = widget.querySelector('[data-lottery-result-label]');
                var resultMessage = widget.querySelector('[data-lottery-result-message]');
                var claimBtn = widget.querySelector('[data-lottery-claim]');
                var successBlock = widget.querySelector('[data-lottery-claim-success]');

                if (resultLabel) {
                    resultLabel.textContent = response.data.prizeLabel || '';
                }

                if (resultMessage) {
                    resultMessage.textContent = response.data.message || '';
                }

                if (successBlock) {
                    successBlock.hidden = true;
                }

                if (claimBtn) {
                    claimBtn.hidden = !response.data.claimable;
                    claimBtn.disabled = false;
                    claimBtn.dataset.recordId = response.data.recordId || '';
                    claimBtn.textContent = response.data.claimButton || oyisoCouponLotteryI18n.claimButton;
                }

                updateAvailability(widget, response.data.availability || null);
                openModal(resultModal);
            }).catch(function (error) {
                setStatus(widget, error.message || oyisoCouponLotteryI18n.loadFailed, true);
            }).finally(function () {
                drawButton.disabled = false;
            });

            return;
        }

        if (claimButton) {
            var claimWidget = claimButton.closest('[data-oyiso-coupon-lottery]');
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
                setStatus(claimWidget, response.data.message || '', false);
            }).catch(function (error) {
                setStatus(claimWidget, error.message || oyisoCouponLotteryI18n.loadFailed, true);
            }).finally(function () {
                claimButton.disabled = false;
            });

            return;
        }

        if (recordsButton) {
            var recordsWidget = recordsButton.closest('[data-oyiso-coupon-lottery]');

            if (!recordsWidget) {
                return;
            }

            openModal(recordsWidget.querySelector('[data-lottery-records-modal]'));
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

        if (copyButton) {
            var resultWidget = copyButton.closest('[data-oyiso-coupon-lottery]');
            var codeTarget = resultWidget ? resultWidget.querySelector('[data-lottery-coupon-code]') : null;

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
                    recordCopyButton.textContent = oyisoCouponLotteryI18n.copy;
                }, 1600);
            });

            return;
        }

        if (recordClaimButton) {
            var recordWidget = recordClaimButton.closest('[data-oyiso-coupon-lottery]');
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
                loadRecords(recordWidget);
            }).catch(function (error) {
                setStatus(recordWidget, error.message || oyisoCouponLotteryI18n.loadFailed, true);
            }).finally(function () {
                recordClaimButton.disabled = false;
            });
        }
    });
})();
