/**
 * Registers a Cachicamo gateway with the Store API's payment registry. Payments\Blocks\PaymentMethodType
 * calls window.cachicamoRegisterGatewayMethod(name) once per gateway right after this script loads,
 * with the settings WooCommerce Blocks already exposes under `${name}_data`.
 */
import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { getSetting } from '@woocommerce/settings';
import { __ } from '@wordpress/i18n';
import { decodeEntities } from '@wordpress/html-entities';
import { createElement } from '@wordpress/element';

function Content( { description, icon } ) {
	return createElement(
		'div',
		{ className: 'cachicamoapp-payment-method' },
		icon
			? createElement( 'img', {
					src: icon,
					alt: '',
					className: 'cachicamoapp-payment-method__icon',
			  } )
			: null,
		createElement( 'span', null, description || '' )
	);
}

window.cachicamoRegisterGatewayMethod = function ( name ) {
	const settings = getSetting( name + '_data', {} );
	const label = decodeEntities( settings.title || __( 'Cachicamo', 'cachicamoapp-for-woo' ) );

	registerPaymentMethod( {
		name,
		label,
		ariaLabel: label,
		content: createElement( Content, { description: settings.description, icon: settings.icon } ),
		edit: createElement( Content, { description: settings.description, icon: settings.icon } ),
		canMakePayment: () => true,
		supports: { features: [ 'products' ] },
	} );
};
