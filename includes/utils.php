<?php
/**
 * Utility and Debug Functions
 *
 * Helper functions for debugging and logging in CachicamoApp integration.
 *
 * @package CachicamoApp_For_WooCommerce
 * @since 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get the CachicamoApp settings
 *
 * @since 1.0.0
 * @return array|false Settings array or false if no settings are found
 */
function cachicamoapp_setting() {
	return get_option( 'woocommerce_cachicamoapp-for-woo-manager_settings' );
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
function cachicamoapp_log( $log, $level = '' ) {
	$setting = cachicamoapp_setting();
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
		$context = array( 'source' => 'cachicamoapp-for-woo' );
		$logger->$level( is_string( $log ) ? $log : wp_json_encode( $log, JSON_PRETTY_PRINT ), $context );
	}
}

/**
 * Generates a key from the access token
 *
 * @since 1.0.0
 * @return string Key generated from the access token
 */
function cachicamoapp_access_token_key() {
	$setting = cachicamoapp_setting();

	if ( ! isset( $setting['access_token'] ) ) {
		return null;
	}

	$key = substr( md5( CACHICAMO_APP_VERSION . $setting['access_token'] ), 0, 8 );
	return $key;
}

/**
 * Add DNI and Company VAT fields to WooCommerce checkout.
 *
 * @since 1.0.0
 * @param array $fields Checkout fields array.
 * @return array Modified fields array
 */
function cachicamoapp_add_checkout_fields( $fields ) {
	$setting = cachicamoapp_setting();

	$store_country = WC()->countries->get_base_country();

	// Only add DNI field if no custom meta key is set.
	if ( empty( $setting['customer_dni_meta_key'] ) ) {
		$name_priority = $fields['billing']['billing_last_name']['priority'];

		$fields['billing']['billing_dni'] = array(
			'type'        => 'text',
			'label'       => __( 'DNI / National ID', 'cachicamoapp-for-woo' ),
			'placeholder' => __( 'Enter your DNI number', 'cachicamoapp-for-woo' ),
			'required'    => in_array( $store_country, array( 'VE', 'AR' ), true ),
			'class'       => array( 'form-row-wide' ),
			'clear'       => true,
			'priority'    => $name_priority + 1, // After last name.
		);
	}

	// Only add Company VAT field if no custom meta key is set.
	if ( empty( $setting['company_vat_meta_key'] ) && isset( $fields['billing']['billing_company'] ) ) {
		$company_priority = $fields['billing']['billing_company']['priority'];
		$company_required = $fields['billing']['billing_company']['required'];

		$fields['billing']['billing_company_vat'] = array(
			'type'        => 'text',
			'label'       => __( 'Company VAT Number', 'cachicamoapp-for-woo' ),
			'placeholder' => __( 'Enter company VAT number', 'cachicamoapp-for-woo' ),
			'class'       => array( 'form-row-wide' ),
			'clear'       => true,
			'priority'    => $company_priority + 1, // After company field.
			'required'    => $company_required,
		);
	}

	return $fields;
}
add_filter( 'woocommerce_checkout_fields', 'cachicamoapp_add_checkout_fields', 20, 1 );

/**
 * Save DNI and Company VAT fields to order meta
 *
 * @since 1.0.0
 * @param int $order_id Order ID.
 * @return void
 */
function cachicamoapp_save_checkout_fields( $order_id ) {
	$setting = cachicamoapp_setting();

	if ( ! isset( $_POST['woocommerce-process-checkout-nonce'] ) ||
		! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woocommerce-process-checkout-nonce'] ) ), 'woocommerce-process_checkout' ) ) {
		return;
	}

	$order = wc_get_order( $order_id );

	if ( empty( $setting['customer_dni_meta_key'] ) && ! empty( $_POST['billing_dni'] ) ) {
		$order->update_meta_data( '_billing_dni', sanitize_text_field( wp_unslash( $_POST['billing_dni'] ) ) );
	}

	if ( empty( $setting['company_vat_meta_key'] ) && ! empty( $_POST['billing_company_vat'] ) ) {
		$order->update_meta_data( '_billing_company_vat', sanitize_text_field( wp_unslash( $_POST['billing_company_vat'] ) ) );
	}
	$order->save();
}
add_action( 'woocommerce_checkout_update_order_meta', 'cachicamoapp_save_checkout_fields' );


/**
 * Add DNI and VAT to displayed billing address.
 *
 * @since 1.0.0
 * @param array    $address_fields Address fields.
 * @param WC_Order $order Order object.
 * @return array Modified address fields
 */
function cachicamoapp_add_dni_vat_to_address( $address_fields, $order ) {
	$setting = cachicamoapp_setting();

	// Get DNI.
	$dni = $order->get_meta( '_billing_dni' ) ?? $order->get_meta( 'billing_dni' );
	if ( ! empty( $dni ) ) {
		$address_fields['dni'] = $dni;
	} elseif ( ! empty( $setting['customer_dni_meta_key'] ) ) {
		$key = ltrim( $setting['customer_dni_meta_key'], '_' );
		$dni = $order->get_meta( $key ) ?? $order->get_meta( '_' . $key ) ?? '';

		$address_fields['dni'] = $dni;
	}

	// Get VAT.
	$vat = $order->get_meta( '_billing_company_vat' ) ?? $order->get_meta( 'billing_company_vat' );
	if ( ! empty( $vat ) ) {
		$address_fields['company_vat'] = $vat;
	} elseif ( ! empty( $setting['company_vat_meta_key'] ) ) {
		$key = ltrim( $setting['company_vat_meta_key'], '_' );
		$vat = $order->get_meta( $key ) ?? $order->get_meta( '_' . $key ) ?? '';

		$address_fields['company_vat'] = $vat;
	}

	return $address_fields;
}
add_filter( 'woocommerce_order_formatted_billing_address', 'cachicamoapp_add_dni_vat_to_address', 10, 2 );
add_filter(
	'woocommerce_formatted_address_replacements',
	function ( $replacements, $address ) {
		$replacements['{dni}']         = $address['dni'] ?? '';
		$replacements['{company_vat}'] = $address['company_vat'] ?? '';
		return $replacements;
	},
	10,
	2
);
add_filter(
	'woocommerce_localisation_address_formats',
	function ( $formats ) {
		foreach ( $formats as $country => $format ) {
			$formats[ $country ] = str_replace( '{company}', '{company} {company_vat}', $format );
			$formats[ $country ] = str_replace( '{name}', '{name} {dni}', $format );
		}
		return $formats;
	},
	10,
	1
);

/**
 * Register custom checkout fields for WooCommerce Blocks (Checkout Bricks)
 */
function cachicamoapp_register_checkout_blocks_fields() {
	if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface' ) ) {
		return;
	}

	require_once __DIR__ . '/class-cachicamoapp-checkout-blocks-integration.php';
	add_action(
		'woocommerce_blocks_checkout_block_registration',
		function ( $integration_registry ) {
			$integration_registry->register( new CachicamoApp_Checkout_Blocks_Integration() );
		}
	);
}
add_action( 'init', 'cachicamoapp_register_checkout_blocks_fields' );
