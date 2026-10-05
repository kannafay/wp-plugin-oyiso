(function ($, wp, wc) {
    'use strict';

    var streetKey = 'oyiso-checkout/street';
    var houseKey = 'oyiso-checkout/house-number';
    var cartStore = wc && wc.wcBlocksData && wc.wcBlocksData.cartStore;
    var syncing = false;

    function formatPostcode(value) {
        var compact = value.replace(/\s/g, '');
        return /^\d{5}$/.test(compact) ? compact.slice(0, 2) + '-' + compact.slice(2) : compact;
    }

    function formatPhone(value) {
        var compact = value.replace(/[\s().-]/g, '').replace(/^00/, '+');
        if (/^\d{9}$/.test(compact)) return '+48' + compact;
        if (/^48\d{9}$/.test(compact)) return '+' + compact;
        return compact;
    }

    function setRequired($field, required) {
        $field.toggleClass('validate-required', required);
        $field.find('input').prop('required', required).attr('aria-required', String(required));
        var $label = $field.children('label');
        $label.find('.required, .optional').remove();
        $label.append(required
            ? ' <abbr class="required" title="wymagane">*</abbr>'
            : ' <span class="optional">(opcjonalnie)</span>');
    }

    function updateClassic() {
        ['billing', 'shipping'].forEach(function (group) {
            var $country = $('#' + group + '_country');
            var $house = $('#' + group + '_oyiso_house_number_field');
            if (!$country.length || !$house.length) return;
            var poland = $country.val() === 'PL';
            $country.closest('.woocommerce-billing-fields__field-wrapper, .woocommerce-shipping-fields__field-wrapper').toggleClass('oyiso-poland-classic', poland);
            var $address = $('#' + group + '_address_1_field');
            var $apartment = $('#' + group + '_address_2_field');
            var $postcode = $('#' + group + '_postcode_field');
            var $city = $('#' + group + '_city_field');
            $house.toggle(poland).find('input').prop('disabled', !poland);
            setRequired($house, poland);
            [$apartment, $postcode, $city].forEach(function ($field) {
                $field.removeClass('form-row-first form-row-last form-row-wide').addClass(poland
                    ? ($field.is($postcode) ? 'form-row-first' : 'form-row-last')
                    : 'form-row-wide');
            });
            if (!$address.data('oyiso-original-label')) {
                $address.data('oyiso-original-label', $address.children('label').html());
            }
            if (poland) {
                $address.children('label').html('Ulica <abbr class="required" title="wymagane">*</abbr>');
                $address.find('input').attr('placeholder', 'np. Marszałkowska');
            } else {
                $address.children('label').html($address.data('oyiso-original-label'));
            }
            if (group === 'billing') {
                setRequired($('#billing_phone_field'), poland || Boolean(window.oyisoPolandCheckout && window.oyisoPolandCheckout.otherPhoneRequired));
            }
        });
    }

    function syncBlocks() {
        if (syncing || !cartStore || !wp || !wp.data) return;
        var cart = wp.data.select(cartStore).getCustomerData();
        if (!cart) return;
        syncing = true;
        try {
            ['billing', 'shipping'].forEach(function (group) {
                var address = cart[group + 'Address'];
                if (!address) return;
                var poland = address.country === 'PL' && typeof address[streetKey] === 'string' && typeof address[houseKey] === 'string';
                document.body.classList.toggle('oyiso-poland-' + group, poland);
                if (!poland) return;
                var combined = (address[streetKey].trim() + ' ' + address[houseKey].trim()).trim();
                if (combined !== address.address_1) {
                    var action = group === 'billing' ? 'setBillingAddress' : 'setShippingAddress';
                    wp.data.dispatch(cartStore)[action]({ address_1: combined });
                }
            });
        } finally {
            syncing = false;
        }
        openOptionalAddresses();
    }

    function openOptionalAddresses() {
        ['billing', 'shipping'].forEach(function (group) {
            if (!document.body.classList.contains('oyiso-poland-' + group)) return;
            var toggle = document.querySelector('#' + group + ' .wc-block-components-address-form__address_2-toggle');
            if (toggle) toggle.click();
        });
    }

    $(function () {
        updateClassic();
        $(document.body).on('country_to_state_changed updated_checkout', updateClassic);
        $(document).on('change', '#billing_country, #shipping_country', updateClassic);
        $(document).on('blur', '#billing_postcode, #shipping_postcode, #billing_phone, #shipping_phone', function () {
            var group = this.id.split('_')[0];
            if ($('#' + group + '_country').val() !== 'PL') return;
            var value = this.id.endsWith('_phone') ? formatPhone(this.value) : formatPostcode(this.value);
            if (value !== this.value) $(this).val(value).trigger('change');
        });
        if (cartStore && wp && wp.data) {
            syncBlocks();
            wp.data.subscribe(syncBlocks, cartStore);
            var checkout = document.querySelector('.wp-block-woocommerce-checkout');
            if (checkout) new MutationObserver(openOptionalAddresses).observe(checkout, { childList: true, subtree: true });
            $(document).on('focusout', '.wc-block-components-address-form input', function () {
                var group = this.closest('.wc-block-components-address-form') && this.closest('.wc-block-components-address-form').id;
                if (group !== 'billing' && group !== 'shipping') return;
                var field = this.id === group + '-phone' ? 'phone' : this.id === group + '-postcode' ? 'postcode' : '';
                var address = wp.data.select(cartStore).getCustomerData()[group + 'Address'];
                if (!field || !address || address.country !== 'PL') return;
                var value = field === 'phone' ? formatPhone(this.value) : formatPostcode(this.value);
                if (value !== this.value) {
                    var change = {};
                    change[field] = value;
                    wp.data.dispatch(cartStore)[group === 'billing' ? 'setBillingAddress' : 'setShippingAddress'](change);
                }
            });
        }
    });
})(window.jQuery, window.wp, window.wc);
