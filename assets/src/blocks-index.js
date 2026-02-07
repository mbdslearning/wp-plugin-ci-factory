/**
 * Blocks source file (ESNext). Built output is in /assets/build/blocks.js
 */

import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { getSetting } from '@woocommerce/settings';
import { decodeEntities } from '@wordpress/html-entities';
import { createElement } from '@wordpress/element';

const settings = getSetting( 'paymongo_checkout_data', {} );
const label = decodeEntities( settings.title || 'PayMongo (Checkout)' );

const Content = () =>
	createElement(
		'div',
		null,
		decodeEntities(
			settings.description ||
				'You will be redirected to PayMongo to complete payment.'
		)
	);

const Label = ( props ) => {
	const { PaymentMethodLabel } = props.components;
	return createElement( PaymentMethodLabel, { text: label } );
};

registerPaymentMethod( {
	name: 'paymongo_checkout',
	label: createElement( Label, null ),
	content: createElement( Content, null ),
	edit: createElement( Content, null ),
	canMakePayment: () => !! settings.is_active,
	ariaLabel: label,
	supports: {
		features: settings.supports || [ 'products' ],
	},
} );
