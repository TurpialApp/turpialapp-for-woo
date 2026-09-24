/**
 * One export flow's card: preview counters/conflicts, then a run button that only unlocks
 * once a preview has completed for this flow.
 */
import { useState } from '@wordpress/element';
import { Button, Card, CardBody, CardHeader, Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import api from './api';

export default function Flow( { title, previewSlug, runSlug } ) {
	const [ preview, setPreview ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ started, setStarted ] = useState( false );

	const runPreview = () => {
		setBusy( true );
		setError( null );
		api( { path: '/catalog/preview/' + previewSlug, method: 'POST' } )
			.then( ( data ) => setPreview( data ) )
			.catch( () => setError( __( 'Could not run the preview.', 'cachicamoapp-for-woo' ) ) )
			.finally( () => setBusy( false ) );
	};

	const runFlow = () => {
		if ( ! runSlug ) {
			return;
		}
		setBusy( true );
		setError( null );
		api( { path: '/catalog/run/' + runSlug, method: 'POST' } )
			.then( () => setStarted( true ) )
			.catch( () => setError( __( 'Could not start the run.', 'cachicamoapp-for-woo' ) ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<Card className="cachicamoapp-catalog-flow">
			<CardHeader>{ title }</CardHeader>
			<CardBody>
				{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
				<Button variant="secondary" isBusy={ busy } onClick={ runPreview } disabled={ busy }>
					{ __( 'Run preview', 'cachicamoapp-for-woo' ) }
				</Button>
				{ preview && (
					<p>
						{ sprintf(
							/* translators: 1: created count, 2: updated count, 3: conflict count */
							__( 'Created: %1$d · Updated: %2$d · Conflicts: %3$d', 'cachicamoapp-for-woo' ),
							preview.created,
							preview.updated,
							preview.conflicts.length
						) }
					</p>
				) }
				{ preview && preview.conflicts.length > 0 && (
					<Notice status="warning" isDismissible={ false }>
						{ sprintf(
							/* translators: %d: number of conflicts. */
							__( '%d conflicts must be resolved before the first run.', 'cachicamoapp-for-woo' ),
							preview.conflicts.length
						) }
					</Notice>
				) }
				{ runSlug && (
					<Button
						variant="primary"
						isBusy={ busy }
						disabled={ busy || ! preview || preview.conflicts.length > 0 || started }
						onClick={ runFlow }
					>
						{ started ? __( 'Running…', 'cachicamoapp-for-woo' ) : __( 'First load', 'cachicamoapp-for-woo' ) }
					</Button>
				) }
			</CardBody>
		</Card>
	);
}
