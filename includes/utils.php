<?php
/**
 * Utility and Debug Functions
 *
 * Helper functions for debugging and logging in TurpialApp integration.
 *
 * @package TurpialApp_For_WooCommerce
 * @since 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Logs debug information to WooCommerce logs
 *
 * Records debug information to WooCommerce logs if debugging is enabled in settings.
 * Supports multiple log levels and falls back to error_log if WC logger not available.
 *
 * @since 1.0.0
 * @param mixed  $log Data to log.
 * @param string $level Log level (debug, info, notice, warning, error, critical, alert, emergency).
 * @return void
 */
function turpialapp_log( $log, $level = '' ) {
	$setting = get_option( 'woocommerce_turpialapp-for-woo-manager_settings' );
	// Check if debugging is enabled.
	if ( ! isset( $setting['debug'] ) || 'no' === $setting['debug'] ) {
		return;
	}
	if ( '' === $level ) {
		$level = 'debug'; // Default log level.
	}
	if ( ! in_array( $level, array( 'debug', 'log', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency' ), true ) ) {
		$level = 'debug';
	}
	if ( function_exists( 'wc_get_logger' ) ) {
		$logger  = wc_get_logger();
		$context = array( 'source' => 'turpialapp-for-woo' );
		$logger->$level( is_string( $log ) ? $log : wp_json_encode( $log, JSON_PRETTY_PRINT ), $context );
	}
}

/**
 * Generates a key from the access token
 *
 * @since 1.0.0
 * @return string Key generated from the access token
 */
function turpialapp_access_token_key() {
	$setting = get_option( 'woocommerce_turpialapp-for-woo-manager_settings' );

	if ( ! isset( $setting['access_token'] ) ) {
		return null;
	}

	$key = substr( md5( $setting['access_token'] ), 0, 8 );
	return $key;
}
