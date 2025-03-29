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

	$key = substr( md5( TURPIAL_APP_VERSION . $setting['access_token'] ), 0, 8 );
	return $key;
}

/**
 * Add DNI and Company VAT fields to WooCommerce checkout.
 *
 * @since 1.0.0
 * @param array $fields Checkout fields array.
 * @return array Modified fields array
 */
function turpialapp_add_checkout_fields( $fields ) {
	$setting = get_option( 'woocommerce_turpialapp-for-woo-manager_settings' );

	// Only add DNI field if no custom meta key is set.
	if ( empty( $setting['customer_dni_meta_key'] ) ) {
		$fields['billing']['billing_dni'] = array(
			'type'        => 'text',
			'label'       => __( 'DNI / National ID', 'turpialapp-for-woo' ),
			'placeholder' => __( 'Enter your DNI number', 'turpialapp-for-woo' ),
			'required'    => true,
			'class'       => array( 'form-row-wide' ),
			'clear'       => true,
			'priority'    => 25, // After last name.
		);
	}

	// Only add Company VAT field if no custom meta key is set.
	if ( empty( $setting['company_vat_meta_key'] ) ) {
		$fields['billing']['billing_company_vat'] = array(
			'type'        => 'text',
			'label'       => __( 'Company VAT Number', 'turpialapp-for-woo' ),
			'placeholder' => __( 'Enter company VAT number', 'turpialapp-for-woo' ),
			'class'       => array( 'form-row-wide' ),
			'clear'       => true,
			'priority'    => 45, // After company field.
		);
	}

	return $fields;
}
add_filter( 'woocommerce_checkout_fields', 'turpialapp_add_checkout_fields' );

/**
 * Save DNI and Company VAT fields to order meta
 *
 * @since 1.0.0
 * @param int $order_id Order ID.
 * @return void
 */
function turpialapp_save_checkout_fields( $order_id ) {
	$setting = get_option( 'woocommerce_turpialapp-for-woo-manager_settings' );

	// Verify nonce.
	if ( ! isset( $_POST['woocommerce-process-checkout-nonce'] ) ||
		! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woocommerce-process-checkout-nonce'] ) ), 'woocommerce-process_checkout' ) ) {
		return;
	}

	if ( empty( $setting['customer_dni_meta_key'] ) && ! empty( $_POST['billing_dni'] ) ) {
		update_post_meta( $order_id, '_billing_dni', sanitize_text_field( wp_unslash( $_POST['billing_dni'] ) ) );
	}

	if ( empty( $setting['company_vat_meta_key'] ) && ! empty( $_POST['billing_company_vat'] ) ) {
		update_post_meta( $order_id, '_billing_company_vat', sanitize_text_field( wp_unslash( $_POST['billing_company_vat'] ) ) );
	}
}
add_action( 'woocommerce_checkout_update_order_meta', 'turpialapp_save_checkout_fields' );


/**
 * Add DNI and VAT to displayed billing address.
 *
 * @since 1.0.0
 * @param array    $address_fields Address fields.
 * @param WC_Order $order Order object.
 * @return array Modified address fields
 */
function turpialapp_add_dni_vat_to_address( $address_fields, $order ) {
	$setting = get_option( 'woocommerce_turpialapp-for-woo-manager_settings' );

	// Get DNI.
	$dni = $order->get_meta( '_billing_dni' );
	if ( ! empty( $dni ) ) {
		$address_fields['dni'] = array(
			'label' => __( 'DNI / National ID', 'turpialapp-for-woo' ),
			'value' => $dni,
		);
	} elseif ( ! empty( $setting['customer_dni_meta_key'] ) ) {
		$dni                   = $order->get_meta( $setting['customer_dni_meta_key'] ) ?? $order->get_meta( '_' . $setting['customer_dni_meta_key'] );
		$address_fields['dni'] = array(
			'label' => __( 'DNI / National ID', 'turpialapp-for-woo' ),
			'value' => $dni,
		);
	}

	// Get VAT.
	$vat = $order->get_meta( '_billing_company_vat' );
	if ( ! empty( $vat ) ) {
		$address_fields['company_vat'] = array(
			'label' => __( 'Company VAT Number', 'turpialapp-for-woo' ),
			'value' => $vat,
		);
	} elseif ( ! empty( $setting['company_vat_meta_key'] ) ) {
		$vat                           = $order->get_meta( $setting['company_vat_meta_key'] ) ?? $order->get_meta( '_' . $setting['company_vat_meta_key'] );
		$address_fields['company_vat'] = array(
			'label' => __( 'Company VAT Number', 'turpialapp-for-woo' ),
			'value' => $vat,
		);
	}

	return $address_fields;
}
add_filter( 'woocommerce_order_formatted_billing_address', 'turpialapp_add_dni_vat_to_address', 10, 2 );

/**
 * Register custom checkout fields for WooCommerce Blocks (Checkout Bricks)
 */
function turpialapp_register_checkout_blocks_fields() {
	if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface' ) ) {
		return;
	}

	require_once __DIR__ . '/class-turpialapp-checkout-blocks-integration.php';
	add_action(
		'woocommerce_blocks_checkout_block_registration',
		function ( $integration_registry ) {
			$integration_registry->register( new TurpialApp_Checkout_Blocks_Integration() );
		}
	);
}
add_action( 'init', 'turpialapp_register_checkout_blocks_fields' );
