<?php

namespace Cachicamo\WooCommerce\Checkout;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;
use Cachicamo\WooCommerce\Pricing\Rates;
use Cachicamo\WooCommerce\Settings\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Registers client/checkout/index.js with the cart-checkout-blocks integration point so it
 * loads only on the Store API-driven checkout, and hands it the exchange rates and the
 * gateway-to-payment-method mapping the storefront needs to show what the buyer will pay in
 * the chosen method's own currency, without a per-render request to the core.
 */
class BlocksAssets implements IntegrationInterface {

	const HANDLE = 'cachicamoapp-for-woo-checkout';

	public static function register_hooks() {
		add_action(
			'woocommerce_blocks_loaded',
			function () {
				if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
					return;
				}
				add_action(
					'woocommerce_blocks_checkout_block_registration',
					function ( $integration_registry ) {
						$integration_registry->register( new self() );
					}
				);

				if ( function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
					woocommerce_store_api_register_update_callback(
						array(
							'namespace' => 'cachicamoapp-for-woo',
							'callback'  => array( __CLASS__, 'update_chosen_payment_method' ),
						)
					);
				}
			}
		);
	}

	/**
	 * CheckoutTotals reads chosen_payment_method from the session to price IGTF; the blocks
	 * checkout has no server round trip on gateway change outside of this Store API callback,
	 * so client/checkout/index.js pushes the newly selected gateway here to force a recalc.
	 *
	 * @param array<string,mixed> $data
	 */
	public static function update_chosen_payment_method( $data ) {
		if ( ! isset( $data['payment_method'] ) || ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		WC()->session->set( 'chosen_payment_method', sanitize_text_field( $data['payment_method'] ) );
	}

	public function get_name() {
		return 'cachicamoapp-for-woo';
	}

	public function initialize() {
		$asset_file = CACHICAMO_APP_DIR . 'assets/build/checkout.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : array(
			'dependencies' => array(),
			'version'      => CACHICAMO_APP_VERSION,
		);

		$dependencies = array_unique(
			array_merge( $asset['dependencies'], array( 'wc-settings', 'wc-blocks-checkout' ) )
		);

		wp_register_script(
			self::HANDLE,
			CACHICAMO_APP_URL . 'assets/build/checkout.js',
			$dependencies,
			$asset['version'],
			true
		);
	}

	public function get_script_handles() {
		return array( self::HANDLE );
	}

	public function get_editor_script_handles() {
		return array();
	}

	public function get_script_data() {
		$mapping = Repository::get( 'payment_mapping', array() );

		return array(
			'rates'          => Rates::all(),
			'storeCurrency'  => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
			'paymentMapping' => is_array( $mapping ) ? $mapping : array(),
		);
	}
}
