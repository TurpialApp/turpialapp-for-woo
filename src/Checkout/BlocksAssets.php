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
			}
		);
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
