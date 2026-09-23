/**
 * Settings screen (plan 4.4) for a store already connected: billing mode, sync fields, price
 * type, images, webhooks. Each field posts the whole form on save; Admin\Rest::save_settings
 * only accepts the keys in its own allow-list.
 */
import { useEffect, useState } from '@wordpress/element';
import { Button, TextControl, RadioControl, CheckboxControl, SelectControl, TabPanel, Notice, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import api from '../api';
import PaymentMapping from './payment-mapping';

const SYNC_FIELDS = [ 'name', 'description', 'short_description', 'categories', 'attributes', 'images', 'dimensions', 'price', 'stock', 'status' ];

export default function Settings() {
	const [ settings, setSettings ] = useState( null );
	const [ saved, setSaved ] = useState( false );
	const [ busy, setBusy ] = useState( false );

	useEffect( () => {
		api( { path: '/admin/settings' } ).then( setSettings );
	}, [] );

	if ( null === settings ) {
		return <Spinner />;
	}

	const set = ( patch ) => {
		setSettings( { ...settings, ...patch } );
		setSaved( false );
	};

	const save = () => {
		setBusy( true );
		api( { path: '/admin/settings', method: 'POST', data: settings } )
			.then( ( data ) => {
				setSettings( data );
				setSaved( true );
			} )
			.finally( () => setBusy( false ) );
	};

	const tabs = [
		{ name: 'general', title: __( 'General', 'cachicamoapp-for-woo' ) },
		{ name: 'sync', title: __( 'Synchronization', 'cachicamoapp-for-woo' ) },
		{ name: 'mapping', title: __( 'Payments', 'cachicamoapp-for-woo' ) },
	];

	return (
		<div className="cachicamoapp-settings">
			{ saved && <Notice status="success" onRemove={ () => setSaved( false ) }>{ __( 'Settings saved.', 'cachicamoapp-for-woo' ) }</Notice> }

			<TabPanel tabs={ tabs }>
				{ ( tab ) => {
					if ( 'general' === tab.name ) {
						return (
							<>
								<RadioControl
									label={ __( 'Billing mode', 'cachicamoapp-for-woo' ) }
									selected={ settings.billing_mode }
									options={ [
										{ label: __( 'Direct invoicing', 'cachicamoapp-for-woo' ), value: 'direct' },
										{ label: __( 'External order', 'cachicamoapp-for-woo' ), value: 'external_order' },
									] }
									onChange={ ( value ) => set( { billing_mode: value } ) }
								/>
								<TextControl
									label={ __( 'Starting order number', 'cachicamoapp-for-woo' ) }
									type="number"
									value={ settings.start_order_number }
									onChange={ ( value ) => set( { start_order_number: parseInt( value, 10 ) || 0 } ) }
								/>
								<TextControl
									label={ __( 'Order document meta key', 'cachicamoapp-for-woo' ) }
									help={ __( 'Empty uses the checkout field the plugin injects.', 'cachicamoapp-for-woo' ) }
									value={ settings.document_meta_key }
									onChange={ ( value ) => set( { document_meta_key: value } ) }
								/>
							</>
						);
					}

					if ( 'sync' === tab.name ) {
						return (
							<>
								<SelectControl
									label={ __( 'Price type', 'cachicamoapp-for-woo' ) }
									value={ settings.price_type }
									options={ [
										{ label: __( 'Base', 'cachicamoapp-for-woo' ), value: 'base' },
										{ label: __( 'Wholesale', 'cachicamoapp-for-woo' ), value: 'wholesale' },
										{ label: __( 'Retail', 'cachicamoapp-for-woo' ), value: 'retail' },
									] }
									onChange={ ( value ) => set( { price_type: value } ) }
								/>
								<SelectControl
									label={ __( 'Stock source', 'cachicamoapp-for-woo' ) }
									value={ settings.stock_source }
									options={ [
										{ label: 'Cachicamo', value: 'cachicamo' },
										{ label: 'WooCommerce', value: 'woocommerce' },
									] }
									onChange={ ( value ) => set( { stock_source: value } ) }
								/>
								<SelectControl
									label={ __( 'Content source', 'cachicamoapp-for-woo' ) }
									value={ settings.content_source }
									options={ [
										{ label: 'Cachicamo', value: 'cachicamo' },
										{ label: 'WooCommerce', value: 'woocommerce' },
										{ label: __( 'None', 'cachicamoapp-for-woo' ), value: 'none' },
									] }
									onChange={ ( value ) => set( { content_source: value } ) }
								/>
								<p>{ __( 'Fields to synchronize', 'cachicamoapp-for-woo' ) }</p>
								{ SYNC_FIELDS.map( ( field ) => (
									<CheckboxControl
										key={ field }
										label={ field }
										checked={ ( settings.sync_fields || [] ).includes( field ) }
										onChange={ ( checked ) => {
											const current = settings.sync_fields || [];
											set( { sync_fields: checked ? [ ...current, field ] : current.filter( ( item ) => item !== field ) } );
										} }
									/>
								) ) }
								<SelectControl
									label={ __( 'Image master', 'cachicamoapp-for-woo' ) }
									value={ settings.image_master }
									options={ [
										{ label: 'Cachicamo', value: 'cachicamo' },
										{ label: 'WooCommerce', value: 'woocommerce' },
									] }
									onChange={ ( value ) => set( { image_master: value } ) }
								/>
								<SelectControl
									label={ __( 'Overwrite images', 'cachicamoapp-for-woo' ) }
									value={ settings.image_overwrite }
									options={ [
										{ label: __( 'Always', 'cachicamoapp-for-woo' ), value: 'always' },
										{ label: __( 'Never', 'cachicamoapp-for-woo' ), value: 'never' },
									] }
									onChange={ ( value ) => set( { image_overwrite: value } ) }
								/>
								<SelectControl
									label={ __( 'On remote delete', 'cachicamoapp-for-woo' ) }
									value={ settings.on_remote_delete }
									options={ [
										{ label: __( 'Do nothing', 'cachicamoapp-for-woo' ), value: 'none' },
										{ label: __( 'Set to draft', 'cachicamoapp-for-woo' ), value: 'draft' },
									] }
									onChange={ ( value ) => set( { on_remote_delete: value } ) }
								/>
							</>
						);
					}

					return (
						<PaymentMapping
							value={ settings.payment_mapping || {} }
							onChange={ ( value ) => set( { payment_mapping: value } ) }
						/>
					);
				} }
			</TabPanel>

			<Button variant="primary" isBusy={ busy } disabled={ busy } onClick={ save }>
				{ __( 'Save changes', 'cachicamoapp-for-woo' ) }
			</Button>
		</div>
	);
}
