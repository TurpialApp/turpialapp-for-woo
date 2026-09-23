import { useEffect, useState } from '@wordpress/element';
import { Spinner, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import api from './api';
import Wizard from './screens/wizard';
import Settings from './screens/settings';

export default function App() {
	const [ state, setState ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ loading, setLoading ] = useState( true );

	const reload = () => {
		setLoading( true );
		api( { path: '/admin/state' } )
			.then( ( data ) => {
				setState( data );
				setError( null );
			} )
			.catch( () => setError( __( 'Could not load the Cachicamo App status.', 'cachicamoapp-for-woo' ) ) )
			.finally( () => setLoading( false ) );
	};

	useEffect( reload, [] );

	if ( loading ) {
		return <Spinner />;
	}

	if ( error ) {
		return <Notice status="error" isDismissible={ false }>{ error }</Notice>;
	}

	return (
		<div className="cachicamoapp-admin">
			<h1>{ __( 'Cachicamo App', 'cachicamoapp-for-woo' ) }</h1>
			{ state.account && 'EXPIRED' === state.account.status && (
				<Notice status="error" isDismissible={ false }>
					{ __( 'Your Cachicamo account is expired. Renew it at cachicamo.app.', 'cachicamoapp-for-woo' ) }
				</Notice>
			) }
			{ state.account && 'PENDING' === state.account.status && (
				<Notice status="warning" isDismissible={ false }>
					{ __( 'Your Cachicamo subscription has a pending payment.', 'cachicamoapp-for-woo' ) }
				</Notice>
			) }
			{ ! state.connected || ! state.reachability.passed ? (
				<Wizard state={ state } onDone={ reload } />
			) : (
				<Settings />
			) }
		</div>
	);
}
