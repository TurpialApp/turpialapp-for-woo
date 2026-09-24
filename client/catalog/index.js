/**
 * Entry point for the catalog screen: mounts App into #cachicamoapp-catalog-root.
 */
import { createRoot } from '@wordpress/element';
import App from './app';

const root = document.getElementById( 'cachicamoapp-catalog-root' );
if ( root ) {
	createRoot( root ).render( <App /> );
}
