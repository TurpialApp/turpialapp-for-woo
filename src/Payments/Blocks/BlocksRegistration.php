<?php

namespace Cachicamo\WooCommerce\Payments\Blocks;

use Cachicamo\WooCommerce\Payments\GatewayRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Registers a PaymentMethodType per Cachicamo gateway with the cart-checkout-blocks
 * integration point, mirroring GatewayRegistry::add_gateways for the classic checkout.
 */
class BlocksRegistration {

	public static function register_hooks() {
		add_action(
			'woocommerce_blocks_loaded',
			function () {
				if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
					return;
				}
				add_action(
					'woocommerce_blocks_payment_method_type_registration',
					array( __CLASS__, 'register_methods' )
				);
			}
		);
	}

	public static function register_methods( $payment_method_registry ) {
		foreach ( GatewayRegistry::build_gateways() as $gateway ) {
			$payment_method_registry->register( new PaymentMethodType( $gateway ) );
		}
	}
}
