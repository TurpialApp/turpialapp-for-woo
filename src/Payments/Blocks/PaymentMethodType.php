<?php

namespace Cachicamo\WooCommerce\Payments\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined( 'ABSPATH' ) || exit;

/**
 * One instance per Cachicamo gateway, registered by BlocksRegistration for every request the
 * cart-checkout-blocks integration point fires. Loads a single shared script (payments.js) and
 * tells it, through an inline call, which gateway id to register with @woocommerce/blocks-registry;
 * the gateway's own title/description/icon reach the frontend through get_payment_method_data(),
 * WooCommerce Blocks' own settings channel, not a second localization.
 */
class PaymentMethodType extends AbstractPaymentMethodType {

	const HANDLE = 'cachicamoapp-for-woo-payments';

	/** @var \Cachicamo\WooCommerce\Payments\AbstractCachicamoGateway */
	private $gateway;

	public function __construct( \Cachicamo\WooCommerce\Payments\AbstractCachicamoGateway $gateway ) {
		$this->gateway = $gateway;
		$this->name    = $gateway->id;
	}

	public function initialize() {
		$this->settings = array();
	}

	public function is_active() {
		return $this->gateway->is_available();
	}

	public function get_payment_method_script_handles() {
		if ( ! wp_script_is( self::HANDLE, 'registered' ) ) {
			$asset_file = CACHICAMO_APP_DIR . 'assets/build/payments.asset.php';
			$asset      = file_exists( $asset_file ) ? require $asset_file : array(
				'dependencies' => array(),
				'version'      => CACHICAMO_APP_VERSION,
			);

			wp_register_script(
				self::HANDLE,
				CACHICAMO_APP_URL . 'assets/build/payments.js',
				array_unique( array_merge( $asset['dependencies'], array( 'wc-blocks-registry', 'wc-settings' ) ) ),
				$asset['version'],
				true
			);
		}

		wp_add_inline_script(
			self::HANDLE,
			sprintf( 'window.cachicamoRegisterGatewayMethod(%s);', wp_json_encode( $this->name ) ),
			'after'
		);

		return array( self::HANDLE );
	}

	public function get_payment_method_data() {
		return array(
			'title'       => $this->gateway->get_title(),
			'description' => $this->gateway->get_description(),
			'icon'        => $this->gateway->icon,
		);
	}
}
