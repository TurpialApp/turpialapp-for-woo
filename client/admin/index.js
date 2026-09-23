/**
 * Entry point for the admin screens: mounts App into #cachicamoapp-admin-root, which decides
 * between the connection wizard and the settings screens from GET /admin/state.
 */
import { createRoot } from '@wordpress/element';
import App from './app';

const root = document.getElementById( 'cachicamoapp-admin-root' );
if ( root ) {
	createRoot( root ).render( <App /> );
}
