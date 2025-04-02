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
 * Get the TurpialApp settings
 *
 * @since 1.0.0
 * @return array|false Settings array or false if no settings are found
 */
function turpialapp_setting() {
	return get_option( 'woocommerce_turpialapp-for-woo-manager_settings' );
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
	$setting = turpialapp_setting();
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
	$setting = turpialapp_setting();

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
	$setting = turpialapp_setting();

	// Only add DNI field if no custom meta key is set.
	if ( empty( $setting['customer_dni_meta_key'] ) ) {
		$name_priority = $fields['billing']['billing_last_name']['priority'];

		$fields['billing']['billing_dni'] = array(
			'type'        => 'text',
			'label'       => __( 'DNI / National ID', 'turpialapp-for-woo' ),
			'placeholder' => __( 'Enter your DNI number', 'turpialapp-for-woo' ),
			'required'    => true,
			'class'       => array( 'form-row-wide' ),
			'clear'       => true,
			'priority'    => $name_priority + 1, // After last name.
		);
	}

	// Only add Company VAT field if no custom meta key is set.
	if ( empty( $setting['company_vat_meta_key'] ) ) {
		$company_priority = $fields['billing']['billing_company']['priority'];
		$company_required = $fields['billing']['billing_company']['required'];

		$fields['billing']['billing_company_vat'] = array(
			'type'        => 'text',
			'label'       => __( 'Company VAT Number', 'turpialapp-for-woo' ),
			'placeholder' => __( 'Enter company VAT number', 'turpialapp-for-woo' ),
			'class'       => array( 'form-row-wide' ),
			'clear'       => true,
			'priority'    => $company_priority + 1, // After company field.
			'required'    => $company_required,
		);
	}

	return $fields;
}
add_filter( 'woocommerce_checkout_fields', 'turpialapp_add_checkout_fields', 8, 1 );

/**
 * Save DNI and Company VAT fields to order meta
 *
 * @since 1.0.0
 * @param int $order_id Order ID.
 * @return void
 */
function turpialapp_save_checkout_fields( $order_id ) {
	$setting = turpialapp_setting();

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
	$setting = turpialapp_setting();

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
add_filter( 'woocommerce_order_formatted_billing_address', 'turpialapp_add_dni_vat_to_address', 10, 2 );
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

/**
 * Get the current gateway
 *
 * @since 1.0.0
 * @return string|false Current gateway ID or false if no gateway is found
 */
function turpialapp_get_current_gateway() {
	$available_gateways = WC()->payment_gateways->payment_gateways();
	$current_gateway    = null;
	$default_gateway    = get_option( 'woocommerce_default_gateway' );
	if ( ! empty( $available_gateways ) ) {
		if ( ! isset( WC()->session ) || is_null( WC()->session ) ) {
			WC()->session = new WC_Session_Handler();
			WC()->session->init();
		}
		if ( isset( WC()->session->chosen_payment_method ) && isset( $available_gateways[ WC()->session->chosen_payment_method ] ) ) {
			$current_gateway = $available_gateways[ WC()->session->chosen_payment_method ];
		} elseif ( isset( $available_gateways[ $default_gateway ] ) ) {
			$current_gateway = $available_gateways[ $default_gateway ];
		} else {
			$current_gateway = current( $available_gateways );
		}
	}
	if ( ! is_null( $current_gateway ) ) {
		return $current_gateway;
	} else {
		return false;
	}
}

/**
 * Add taxes to the cart based on the current gateway
 *
 * @since 1.0.0
 * @param WC_Cart $cart Cart object.
 * @return void
 */
add_action(
	'woocommerce_cart_calculate_fees',
	function ( $cart ) {
		$gateway = turpialapp_get_current_gateway();
		if ( ! $gateway ) {
			return;
		}

		$setting          = turpialapp_setting();
		$turpialapp_taxes = turpialapp_get_all_taxes();

		$calculation_base  = (float) $cart->subtotal_ex_tax;
		$calculation_base += (float) $cart->shipping_total;
		$calculation_base -= (float) $cart->get_total_discount() + (float) $cart->discount_cart;
		$calculation_base += (float) $cart->tax_total;
		$calculation_base += (float) $cart->shipping_tax_total;

		$fees = $cart->get_fees();
		foreach ( $fees as $fee ) {
			$calculation_base += (float) $fee->amount;
		}

		$turpialapp_payment_methods = turpialapp_get_all_payment_methods();
		foreach ( $turpialapp_payment_methods as $turpialapp_payment_method ) {
			if ( $turpialapp_payment_method['uuid'] === $setting[ 'payment_method_' . $gateway->id ] ) {
				$taxes = $turpialapp_payment_method['taxes'];
				if ( $taxes && count( $taxes ) > 0 ) {
					foreach ( $taxes as $tax ) {
						foreach ( $turpialapp_taxes as $turpialapp_tax ) {
							if ( $tax['uuid'] === $turpialapp_tax['uuid'] && $tax['sum_to_invoice'] ) {
								$cost = $tax['tax_rate'] * $calculation_base / 100;
								$cart->add_fee( $tax['name'], round( $cost, 2 ) );
							}
						}
					}
				}
			}
		}
	},
	PHP_INT_MAX,
	1
);
