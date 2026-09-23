/**
 * Connection wizard (plan 4.17): token -> account -> store -> reachability -> billing mode ->
 * statuses/start order -> payment mapping -> webhook subscription. Each step is a POST/GET
 * against Admin\Rest; the wizard never talks to the core directly.
 */
import { useState } from '@wordpress/element';
import { Button, TextControl, Notice, RadioControl, CheckboxControl, SelectControl, Card, CardBody } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import api from '../api';
import PaymentMapping from './payment-mapping';

const STEPS = [ 'token', 'store', 'reachability', 'billing', 'statuses', 'mapping', 'webhooks', 'done' ];

export default function Wizard( { state, onDone } ) {
	const [ step, setStep ] = useState( state.connected ? ( state.reachability.passed ? 'done' : 'reachability' ) : 'token' );
	const [ token, setToken ] = useState( '' );
	const [ stores, setStores ] = useState( [] );
	const [ storeUuid, setStoreUuid ] = useState( '' );
	const [ printerDocuments, setPrinterDocuments ] = useState( [] );
	const [ settings, setSettings ] = useState( state.settings || {} );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );

	const run = ( promise, onSuccess ) => {
		setBusy( true );
		setError( null );
		promise
			.then( onSuccess )
			.catch( ( response ) => setError( response && response.message ? response.message : __( 'Something went wrong.', 'cachicamoapp-for-woo' ) ) )
			.finally( () => setBusy( false ) );
	};

	const submitToken = () => run(
		api( { path: '/admin/wizard/token', method: 'POST', data: { api_token: token } } )
			.then( () => api( { path: '/admin/wizard/stores' } ) ),
		( data ) => {
			setStores( data.stores || [] );
			setStep( 'store' );
		}
	);

	const submitStore = () => run(
		api( { path: '/admin/wizard/store', method: 'POST', data: { store_uuid: storeUuid } } ),
		() => setStep( 'reachability' )
	);

	const checkReachability = () => run(
		api( { path: '/admin/wizard/reachability', method: 'POST' } ),
		() => api( { path: '/admin/wizard/printer-documents' } ).then( ( data ) => {
			setPrinterDocuments( data.printer_documents || [] );
			setStep( 'billing' );
		} )
	);

	const saveSettings = ( patch, next ) => {
		const merged = { ...settings, ...patch };
		setSettings( merged );
		run(
			api( { path: '/admin/settings', method: 'POST', data: patch } ),
			() => setStep( next )
		);
	};

	const subscribeWebhooks = () => run(
		api( { path: '/admin/wizard/webhooks', method: 'POST' } ),
		() => {
			setStep( 'done' );
			onDone();
		}
	);

	return (
		<Card>
			<CardBody>
				{ error && <Notice status="error" onRemove={ () => setError( null ) }>{ error }</Notice> }

				{ 'token' === step && (
					<>
						<p>{ __( 'Enter your Cachicamo API token to connect this store.', 'cachicamoapp-for-woo' ) }</p>
						<TextControl label={ __( 'API token', 'cachicamoapp-for-woo' ) } value={ token } onChange={ setToken } type="password" />
						<Button variant="primary" isBusy={ busy } disabled={ busy || ! token } onClick={ submitToken }>
							{ __( 'Continue', 'cachicamoapp-for-woo' ) }
						</Button>
					</>
				) }

				{ 'store' === step && (
					<>
						<SelectControl
							label={ __( 'Store', 'cachicamoapp-for-woo' ) }
							value={ storeUuid }
							options={ [ { label: __( 'Select a store', 'cachicamoapp-for-woo' ), value: '' }, ...stores.map( ( store ) => ( { label: store.name, value: store.uuid } ) ) ] }
							onChange={ setStoreUuid }
						/>
						<Button variant="primary" isBusy={ busy } disabled={ busy || ! storeUuid } onClick={ submitStore }>
							{ __( 'Continue', 'cachicamoapp-for-woo' ) }
						</Button>
					</>
				) }

				{ 'reachability' === step && (
					<>
						<p>{ __( 'Cachicamo needs to confirm it can reach this store before syncing or billing anything.', 'cachicamoapp-for-woo' ) }</p>
						<Button variant="primary" isBusy={ busy } disabled={ busy } onClick={ checkReachability }>
							{ __( 'Check connection', 'cachicamoapp-for-woo' ) }
						</Button>
					</>
				) }

				{ 'billing' === step && (
					<>
						<RadioControl
							label={ __( 'Billing mode', 'cachicamoapp-for-woo' ) }
							selected={ settings.billing_mode || 'external_order' }
							options={ [
								{ label: __( 'Direct invoicing', 'cachicamoapp-for-woo' ), value: 'direct' },
								{ label: __( 'External order', 'cachicamoapp-for-woo' ), value: 'external_order' },
							] }
							onChange={ ( value ) => setSettings( { ...settings, billing_mode: value } ) }
						/>
						{ 'direct' === settings.billing_mode && (
							printerDocuments.length ? (
								<SelectControl
									label={ __( 'Fiscal printer', 'cachicamoapp-for-woo' ) }
									value={ settings.printer_document_uuid || '' }
									options={ [ { label: __( 'Select a printer', 'cachicamoapp-for-woo' ), value: '' }, ...printerDocuments.map( ( printer ) => ( { label: printer.name || printer.uuid, value: printer.uuid } ) ) ] }
									onChange={ ( value ) => setSettings( { ...settings, printer_document_uuid: value } ) }
								/>
							) : (
								<Notice status="warning" isDismissible={ false }>
									{ __( 'No digital fiscal printer outside sandbox is assigned to this store yet, so direct invoicing stays disabled.', 'cachicamoapp-for-woo' ) }
								</Notice>
							)
						) }
						<Button
							variant="primary"
							isBusy={ busy }
							disabled={ busy }
							onClick={ () => saveSettings( { billing_mode: settings.billing_mode, printer_document_uuid: settings.printer_document_uuid || '' }, 'statuses' ) }
						>
							{ __( 'Continue', 'cachicamoapp-for-woo' ) }
						</Button>
					</>
				) }

				{ 'statuses' === step && (
					<>
						{ [ 'wc-processing', 'wc-completed', 'wc-on-hold' ].map( ( status ) => (
							<CheckboxControl
								key={ status }
								label={ status }
								checked={ ( settings.trigger_statuses || [] ).includes( status ) }
								onChange={ ( checked ) => {
									const current = settings.trigger_statuses || [];
									const next = checked ? [ ...current, status ] : current.filter( ( item ) => item !== status );
									setSettings( { ...settings, trigger_statuses: next } );
								} }
							/>
						) ) }
						<TextControl
							label={ __( 'Starting order number', 'cachicamoapp-for-woo' ) }
							type="number"
							value={ settings.start_order_number || 0 }
							onChange={ ( value ) => setSettings( { ...settings, start_order_number: parseInt( value, 10 ) || 0 } ) }
						/>
						<Button
							variant="primary"
							isBusy={ busy }
							disabled={ busy }
							onClick={ () => saveSettings( { trigger_statuses: settings.trigger_statuses, start_order_number: settings.start_order_number }, 'mapping' ) }
						>
							{ __( 'Continue', 'cachicamoapp-for-woo' ) }
						</Button>
					</>
				) }

				{ 'mapping' === step && (
					<>
						<PaymentMapping value={ settings.payment_mapping || {} } onChange={ ( value ) => setSettings( { ...settings, payment_mapping: value } ) } />
						<Button
							variant="primary"
							isBusy={ busy }
							disabled={ busy }
							onClick={ () => saveSettings( { payment_mapping: settings.payment_mapping }, 'webhooks' ) }
						>
							{ __( 'Continue', 'cachicamoapp-for-woo' ) }
						</Button>
					</>
				) }

				{ 'webhooks' === step && (
					<>
						<p>{ __( 'Subscribe this store to the Cachicamo events it needs.', 'cachicamoapp-for-woo' ) }</p>
						<Button variant="primary" isBusy={ busy } disabled={ busy } onClick={ subscribeWebhooks }>
							{ __( 'Finish setup', 'cachicamoapp-for-woo' ) }
						</Button>
					</>
				) }

				{ 'done' === step && (
					<Notice status="success" isDismissible={ false }>{ __( 'Cachicamo App is connected.', 'cachicamoapp-for-woo' ) }</Notice>
				) }
			</CardBody>
		</Card>
	);
}
