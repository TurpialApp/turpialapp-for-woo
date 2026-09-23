<?php
/**
 * Minimal PSR-4 autoloader for the Cachicamo\WooCommerce\ namespace, used when composer's
 * vendor/autoload.php has not been generated on the host running the plugin.
 */

defined( 'ABSPATH' ) || exit;

if ( file_exists( CACHICAMO_APP_DIR . 'vendor/autoload.php' ) ) {
	require_once CACHICAMO_APP_DIR . 'vendor/autoload.php';
	return;
}

spl_autoload_register(
	function ( $class ) {
		$prefix = 'Cachicamo\\WooCommerce\\';
		if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$path     = CACHICAMO_APP_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( file_exists( $path ) ) {
			require $path;
		}
	}
);
