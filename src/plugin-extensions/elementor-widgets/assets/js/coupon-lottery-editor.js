(function ($) {
    'use strict';

    var WIDGET_TYPE = 'oyiso_coupon_lottery';
    var currentBinding = null;

    function roundToStep(value, step) {
        if (step === 0.1) {
            return Math.round(value * 10) / 10;
        }

        return Math.round(value);
    }

    function normalizeSliderValue(value, min, max) {
        var normalized = $.isPlainObject(value) ? $.extend(true, {}, value) : {};
        var rawSize = $.isPlainObject(value) && value.size !== undefined ? value.size : value;
        var size = parseFloat(rawSize);

        if (!isFinite(size)) {
            size = min;
        }

        size = Math.max(min, size);

        if (typeof max === 'number') {
            size = Math.min(max, size);
        }

        size = roundToStep(size, 1);
        normalized.unit = normalized.unit || '%';
        normalized.size = size;

        return normalized;
    }

    function normalizeNumberValue(value, min) {
        var numeric = parseFloat(value);

        if (!isFinite(numeric)) {
            numeric = min;
        }

        numeric = Math.max(min, numeric);

        return roundToStep(numeric, 0.1);
    }

    function normalizeRule(rule, rangeType) {
        var normalized = $.isPlainObject(rule) ? $.extend(true, {}, rule) : {};
        var mode = normalized.mode === 'single' ? 'single' : 'range';

        normalized.mode = mode;
        normalized.probability = normalizeSliderValue(normalized.probability, 1, 100);

        if (rangeType === 'amount') {
            if (mode === 'range') {
                normalized.start_amount = normalizeNumberValue(normalized.start_amount, 0.1);
                normalized.end_amount = normalizeNumberValue(normalized.end_amount, 0.1);
            } else {
                normalized.value_amount = normalizeNumberValue(normalized.value_amount, 0.1);
            }

            return normalized;
        }

        if (mode === 'range') {
            normalized.start_percent = normalizeSliderValue(normalized.start_percent, 1, 100);
            normalized.end_percent = normalizeSliderValue(normalized.end_percent, 1, 100);
        } else {
            normalized.value_percent = normalizeSliderValue(normalized.value_percent, 1, 100);
        }

        return normalized;
    }

    function normalizeRules(rules, rangeType) {
        if (!Array.isArray(rules)) {
            return rules;
        }

        return rules.map(function (rule) {
            return normalizeRule(rule, rangeType);
        });
    }

    function normalizeSettings(settings) {
        if (!settings || typeof settings !== 'object') {
            return settings;
        }

        var normalized = $.extend(true, {}, settings);

        if (Array.isArray(normalized.percent_rules)) {
            normalized.percent_rules = normalizeRules(normalized.percent_rules, 'percent');
        }

        if (Array.isArray(normalized.amount_rules)) {
            normalized.amount_rules = normalizeRules(normalized.amount_rules, 'amount');
        }

        return normalized;
    }

    function bindSettingsModel(model) {
        var settingsModel = model && typeof model.get === 'function' ? model.get('settings') : null;

        if (!settingsModel || typeof settingsModel.toJSON !== 'function' || typeof settingsModel.set !== 'function') {
            return;
        }

        if (currentBinding && currentBinding.settingsModel === settingsModel) {
            currentBinding.handler();
            return;
        }

        if (currentBinding) {
            currentBinding.settingsModel.off('change', currentBinding.handler);
        }

        var syncing = false;
        var handler = function () {
            if (syncing) {
                return;
            }

            syncing = true;

            var currentSettings = settingsModel.toJSON();
            var normalizedSettings = normalizeSettings(currentSettings);

            if (JSON.stringify(currentSettings) !== JSON.stringify(normalizedSettings)) {
                settingsModel.set(normalizedSettings);
            }

            syncing = false;
        };

        currentBinding = {
            settingsModel: settingsModel,
            handler: handler
        };

        settingsModel.on('change', handler);
        handler();
    }

    function boot() {
        if (!window.elementor || !window.elementor.hooks) {
            return;
        }

        window.elementor.hooks.addAction('panel/open_editor/widget/' + WIDGET_TYPE, function (panel, model) {
            bindSettingsModel(model);
        });

        if (typeof window.elementor.getCurrentElement === 'function') {
            var currentElement = window.elementor.getCurrentElement();
            var currentModel = currentElement && typeof currentElement.getEditModel === 'function'
                ? currentElement.getEditModel()
                : null;

            if (currentModel && currentModel.get('widgetType') === WIDGET_TYPE) {
                bindSettingsModel(currentModel);
            }
        }
    }

    $(boot);
}(jQuery));
