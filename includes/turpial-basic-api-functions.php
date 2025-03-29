<?php
/**
 * Product Functions
 *
 * Functions for managing product integration with TurpialApp.
 *
 * @package TurpialApp_For_WooCommerce
 * @since 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get all available currencies from Turpial API.
 *
 * @since 1.0.0
 * @return array|null Array of currencies or null on error.
 */
function turpialapp_get_all_currencies() {
	$key = turpialapp_access_token_key();
	if ( ! $key ) {
		return null;
	}
	$cache_key = 'wc_tapp_currencies_' . $key;
	$cache     = get_transient( $cache_key );
	if ( $cache ) {
		return 'error' === $cache ? array() : $cache; // Return cached currencies if available.
	}

	$setting = get_option( 'woocommerce_turpialapp-for-woo-manager_settings' );

	$result = wp_remote_get(
		TURPIAL_APP_ENDPOINT . '/currencies',
		array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $setting['access_token'],
				'Content-Type'  => 'application/json',
				'X-Store-Uuid'  => $setting['store_uuid'],
			),
		)
	);
	if ( is_wp_error( $result ) ) {
		turpialapp_log( array( 'turpialapp_get_all_currencies -> error' => $result ), 'error' );
		return null;
	}

	$decoded = json_decode( $result['body'], true );
	if ( isset( $decoded['USD'] ) ) {
		set_transient( $cache_key, $decoded, 3600 * 24 * 7 ); // Cache the currencies for 7 days.
		return $decoded;
	}

	turpialapp_log( array( 'turpialapp_get_all_currencies -> error' => $decoded ), 'error' );
	set_transient( $cache_key, 'error', 60 ); // Cache error for 1 minute.
	return array();
}

/**
 * Get all available payment methods from Turpial API.
 *
 * @since 1.0.0
 * @return array|null Array of payment methods or null on error.
 */
function turpialapp_get_all_payment_methods() {
	$key = turpialapp_access_token_key();
	if ( ! $key ) {
		return null;
	}
	$cache_key = 'wc_tapp_payment_method_' . $key;
	$cache     = get_transient( $cache_key );
	if ( $cache ) {
		return 'error' === $cache ? null : $cache; // Return cached payment methods if available.
	}

	$setting = get_option( 'woocommerce_turpialapp-for-woo-manager_settings' );
	$result  = wp_remote_get(
		TURPIAL_APP_ENDPOINT . '/payment_methods?limit=100&page=1',
		array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $setting['access_token'],
				'Content-Type'  => 'application/json',
				'X-Store-Uuid'  => $setting['store_uuid'],
			),
		)
	);
	if ( is_wp_error( $result ) ) {
		turpialapp_log( array( 'turpialapp_get_all_taxes -> error' => $result ), 'error' );
		return null;
	}
	$decoded = json_decode( $result['body'], true );

	if ( isset( $decoded['rows'] ) && count( $decoded['rows'] ) > 0 ) {
		set_transient( $cache_key, $decoded['rows'], 600 ); // Cache payment methods for 1 hour.
		return $decoded['rows'];
	}

	set_transient( $cache_key, array(), 60 ); // Cache empty result for 1 minute.
	return array();
}

/**
 * Get payment methods formatted for select dropdown.
 *
 * @since 1.0.0
 * @return array Array of payment methods options for select dropdown.
 */
function turpialapp_get_all_payment_method_for_select() {
	$payments = turpialapp_get_all_payment_methods();
	$options  = array();
	if ( $payments ) {
		foreach ( $payments as $payment ) {
			$options[ $payment['uuid'] ] = $payment['name']; // Create options for payment methods.
		}
	}
	return $options;
}

/**
 * Get all available printer documents from Turpial API.
 *
 * @since 1.0.0
 * @return array|null Array of printer documents or null on error.
 */
function turpialapp_get_all_printer_documents() {
	$key = turpialapp_access_token_key();
	if ( ! $key ) {
		return null;
	}
	$cache_key = 'wc_tapp_printer_document_' . $key;
	$cache     = get_transient( $cache_key );
	if ( $cache ) {
		return 'error' === $cache ? null : $cache; // Return cached payment methods if available.
	}

	$setting = get_option( 'woocommerce_turpialapp-for-woo-manager_settings' );
	$result  = wp_remote_get(
		TURPIAL_APP_ENDPOINT . '/printer_documents?limit=100&page=1',
		array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $setting['access_token'],
				'Content-Type'  => 'application/json',
				'X-Store-Uuid'  => $setting['store_uuid'],
			),
		)
	);
	if ( is_wp_error( $result ) ) {
		turpialapp_log( array( 'turpialapp_get_all_taxes -> error' => $result ), 'error' );
		return null;
	}
	$decoded = json_decode( $result['body'], true );

	if ( isset( $decoded['rows'] ) && count( $decoded['rows'] ) > 0 ) {
		set_transient( $cache_key, $decoded['rows'], 600 ); // Cache payment methods for 1 hour.
		return $decoded['rows'];
	}

	set_transient( $cache_key, array(), 60 ); // Cache empty result for 1 minute.
	return array();
}

/**
 * Get printer documents formatted for select dropdown.
 *
 * @since 1.0.0
 * @return array Array of printer documents options for select dropdown.
 */
function turpialapp_get_all_printer_documents_options() {
	$printers = turpialapp_get_all_printer_documents();
	if ( ! is_array( $printers ) || ! count( $printers ) ) {
		return array();
	}
	$options = array();
	foreach ( $printers as $printer ) {
		$options[ $printer['uuid'] ] = $printer['name'];
	}
	return $options;
}

/**
 * Get all available taxes from Turpial API.
 *
 * @since 1.0.0
 * @return array|null Array of taxes or null on error.
 */
function turpialapp_get_all_taxes() {
	$key = turpialapp_access_token_key();
	if ( ! $key ) {
		return null;
	}
	$cache_key = 'wc_tapp_taxes_' . $key;
	$cache     = get_transient( $cache_key );
	if ( $cache ) {
		return 'error' === $cache ? null : $cache; // Return cached taxes if available.
	}

	$setting = get_option( 'woocommerce_turpialapp-for-woo-manager_settings' );
	$result  = wp_remote_get(
		TURPIAL_APP_ENDPOINT . '/taxes',
		array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $setting['access_token'],
				'Content-Type'  => 'application/json',
				'X-Store-Uuid'  => $setting['store_uuid'],
			),
		)
	);
	if ( is_wp_error( $result ) ) {
		turpialapp_log( array( 'turpialapp_get_all_taxes -> error' => $result ), 'error' );
		return null;
	}
	$decoded = json_decode( $result['body'], true );
	if ( isset( $decoded[0]['uuid'] ) ) {
		set_transient( $cache_key, $decoded, 600 ); // Cache taxes for 1 hour.
		turpialapp_log( array( 'turpialapp_get_all_taxes -> result' => $result ), 'info' );
		return $decoded;
	}

	turpialapp_log( array( 'turpialapp_get_all_taxes -> error2' => $result ), 'error' );
	set_transient( $cache_key, array(), 60 ); // Cache empty result for 1 minute.
	return array();
}

/**
 * Update product stock from Turpial API.
 *
 * @since 1.0.0
 * @return void
 */
function turpialapp_update_stock_from_api() {
	$setting = get_option( 'woocommerce_turpialapp-for-woo-manager_settings' );
	// Future implementation for stock updates.
	// This function is commented in original code.
}
