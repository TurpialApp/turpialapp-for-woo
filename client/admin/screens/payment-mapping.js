/**
 * Payment mapping screen (plan 4.11 "Mapeo"): one Cachicamo payment method per WooCommerce
 * gateway. The catalog of Cachicamo methods themselves is out of scope here (Payments\Catalog);
 * this only lists WooCommerce gateways and lets the admin type the target payment_method_uuid.
 */
import { useEffect, useState } from '@wordpress/element';
import { TextControl, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import api from '../api';

export default function PaymentMapping( { value, onChange } ) {
	const [ gateways, setGateways ] = useState( null );

	useEffect( () => {
		api( { path: '/admin/payment-gateways' } ).then( ( data ) => setGateways( data.gateways || [] ) );
	}, [] );

	if ( null === gateways ) {
		return <Spinner />;
	}

	if ( ! gateways.length ) {
		return <p>{ __( 'No WooCommerce payment gateways found.', 'cachicamoapp-for-woo' ) }</p>;
	}

	return (
		<div className="cachicamoapp-payment-mapping">
			<p>{ __( 'Match each WooCommerce payment gateway to its Cachicamo payment method UUID.', 'cachicamoapp-for-woo' ) }</p>
			{ gateways.map( ( gateway ) => (
				<TextControl
					key={ gateway.id }
					label={ gateway.title + ( gateway.enabled ? '' : ' ' + __( '(disabled)', 'cachicamoapp-for-woo' ) ) }
					value={ value[ gateway.id ] || '' }
					onChange={ ( uuid ) => onChange( { ...value, [ gateway.id ]: uuid } ) }
				/>
			) ) }
		</div>
	);
}
