(function () {
    'use strict';

    const wp = window.wp;
    const checkout = window.wc && window.wc.blocksCheckout;
    const components = window.wc && window.wc.blocksComponents;
    if (!wp || !wp.element || !wp.plugins || !checkout || !checkout.ExperimentalOrderShippingPackages) {
        return;
    }

    const createElement = wp.element.createElement;

    function MaintenanceOptions(props) {
        const data = props.extensions && props.extensions['oyiso-shipping-maintenance'];
        const groups = data && Array.isArray(data.packages) ? data.packages : [];
        const RadioControl = (props.components && props.components.RadioControl) || (components && components.RadioControl);
        if (!RadioControl || groups.length === 0) {
            return null;
        }

        return createElement(
            'div',
            { className: 'oyiso-shipping-maintenance-options' },
            groups.map(function (group) {
                return createElement(RadioControl, {
                    key: group.package_id,
                    disabled: true,
                    selected: '',
                    onChange: function () {},
                    options: group.options.map(function (option) {
                        return { value: option.id, label: option.label, description: option.message };
                    }),
                });
            })
        );
    }

    function ShippingPackagesMaintenance(props) {
        if (props.context === 'woocommerce/cart' && checkout.ExperimentalOrderMeta) {
            return null;
        }
        return createElement(MaintenanceOptions, props);
    }

    function CartMaintenance(props) {
        return props.context === 'woocommerce/cart' ? createElement(MaintenanceOptions, props) : null;
    }

    wp.plugins.registerPlugin('oyiso-shipping-maintenance', {
        scope: 'woocommerce-checkout',
        render: function () {
            return createElement(
                wp.element.Fragment,
                null,
                createElement(checkout.ExperimentalOrderShippingPackages, null, createElement(ShippingPackagesMaintenance)),
                checkout.ExperimentalOrderMeta ? createElement(checkout.ExperimentalOrderMeta, null, createElement(CartMaintenance)) : null
            );
        },
    });
})();
