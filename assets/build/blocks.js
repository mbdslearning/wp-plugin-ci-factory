/* eslint-disable */
(function () {
	// Globals provided by WooCommerce/WordPress.
	var registerPaymentMethod =
		window &&
		window.wc &&
		window.wc.wcBlocksRegistry &&
		window.wc.wcBlocksRegistry.registerPaymentMethod;

	var getSetting =
		window &&
		window.wc &&
		window.wc.wcSettings &&
		window.wc.wcSettings.getSetting;

	var decodeEntities =
		window &&
		window.wp &&
		window.wp.htmlEntities &&
		window.wp.htmlEntities.decodeEntities;

	var el = window && window.wp && window.wp.element && window.wp.element.createElement;

	if (!registerPaymentMethod || !getSetting || !el) {
		return;
	}

	var settings = getSetting('paymongo_checkout_data', {});
	var label = decodeEntities ? decodeEntities(settings.title || 'PayMongo (Checkout)') : (settings.title || 'PayMongo (Checkout)');

	var Content = function () {
		var desc = settings.description || 'You will be redirected to PayMongo to complete payment.';
		desc = decodeEntities ? decodeEntities(desc) : desc;
		return el('div', null, desc);
	};

	var Label = function (props) {
		var PaymentMethodLabel = props && props.components && props.components.PaymentMethodLabel;
		if (!PaymentMethodLabel) {
			return el('span', null, label);
		}
		return el(PaymentMethodLabel, { text: label });
	};

	registerPaymentMethod({
		name: 'paymongo_checkout',
		label: el(Label, null),
		content: el(Content, null),
		edit: el(Content, null),
		canMakePayment: function () {
			return !!settings.is_active;
		},
		ariaLabel: label,
		supports: {
			features: settings.supports || ['products'],
		},
	});
})();
