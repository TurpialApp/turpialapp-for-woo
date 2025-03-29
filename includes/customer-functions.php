<?php
/**
 * Customer API Functions.
 *
 * Functions for interacting with TurpialApp API.
 *
 * @package TurpialApp_For_WooCommerce
 * @since 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Search for a customer in Turpial by email.
 *
 * @since 1.0.0
 * @param string $email Customer email to search for.
 * @return array|null Found customer data or null if not found.
 */
function turpialapp_search_customer_by_email( $email ) {
	$key = turpialapp_access_token_key();
	if ( ! $key ) {
		return null;
	}
	$cache_key = 'wc_tapp_customer_' . md5( $email ) . '_' . $key;
	$cache     = get_transient( $cache_key );
	if ( $cache ) {
		return $cache; // Return cached customer if available.
	}
	$result = wp_remote_get(
		TURPIAL_APP_ENDPOINT . '/customers/email/' . $email,
		array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $setting['access_token'],
				'Content-Type'  => 'application/json',
				'X-Store-Uuid'  => $setting['store_uuid'],
			),
		)
	);
	if ( is_wp_error( $result ) ) {
		turpialapp_log( array( 'turpialapp_search_customer_by_email -> error' => $result ), 'error' );
		return null;
	}
	$decoded = json_decode( $result['body'], true );
	if ( isset( $decoded['uuid'] ) ) {
		set_transient( $cache_key, $decoded, 3600 ); // Cache the customer for 1 hour.
		return $decoded;
	}

	return null;
}

/**
 * Get or create a customer in Turpial.
 *
 * Searches for an existing customer by email, if not found creates a new one.
 *
 * @since 1.0.0
 * @param string $name Customer name.
 * @param string $dni Customer ID number/document.
 * @param string $company Customer company name.
 * @param string $vat_company Customer VAT number.
 * @param string $email Customer email.
 * @param string $phone Customer phone number.
 * @param string $address Customer address.
 * @param string $city Customer city.
 * @param string $state Customer state/province.
 * @param string $postcode Customer postal code.
 * @param string $country Customer country.
 * @return array|null Created/found customer data or null on error.
 */
function turpialapp_get_or_export_customer( $name, $dni, $company, $vat_company, $email, $phone, $address, $city, $state, $postcode, $country ) {
	$key = turpialapp_access_token_key();
	if ( ! $key ) {
		return null;
	}
	$cache_key = 'wc_tapp_customer_' . md5( $email ) . '_' . $key;
	$customer  = turpialapp_search_customer_by_email( $email );
	if ( $customer ) {
		return $customer; // Return existing customer if found.
	}
	if ( empty( $dni ) ) {
		$dni = null; // Set DNI to null if not provided.
	}

	if ( empty( $company ) || empty( $vat_company ) ) {
		$company     = null;
		$vat_company = null;
	}

	$request = array(
		'headers' => array(
			'Authorization' => 'Bearer ' . $setting['access_token'],
			'Content-Type'  => 'application/json',
			'X-Store-Uuid'  => $setting['store_uuid'],
		),
		'body'    => wp_json_encode(
			array(
				'email'    => $email,
				'name'     => $name,
				'dni'      => $dni,
				'company'  => $company,
				'vat'      => $vat_company,
				'address'  => $address,
				'phone'    => $phone,
				'city'     => $city,
				'state'    => $state,
				'postcode' => $postcode,
				'country'  => $country,
			)
		),
	);
	$result  = wp_remote_post( TURPIAL_APP_ENDPOINT . '/customers', $request );
	if ( is_wp_error( $result ) ) {
		turpialapp_log(
			array(
				'turpialapp_get_or_export_customer -> error' => $result,
				'request' => $request,
			),
			'error'
		);
		return null;
	}
	$decoded = json_decode( $result['body'], true );
	if ( isset( $decoded['uuid'] ) ) {
		set_transient( $cache_key, $decoded, 3600 ); // Cache the new customer for 1 hour.
		return $decoded;
	}

	turpialapp_log(
		array(
			'turpialapp_get_or_export_customer -> error2' => $result,
			'request'                                     => $request,
		),
		'error'
	);
	return null;
}
