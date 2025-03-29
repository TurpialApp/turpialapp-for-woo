<?php
/**
 * WooCommerce Blocks integration for TurpialApp custom fields
 *
 * @package TurpialApp_For_WooCommerce
 * @since 1.0.0
 */

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Class TurpialApp_Checkout_Blocks_Integration
 */
class TurpialApp_Checkout_Blocks_Integration implements IntegrationInterface {
	/**
	 * The name of the integration.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'turpialapp-custom-fields';
	}

	/**
	 * When this integration should be loaded.
	 *
	 * @return boolean
	 */
	public function initialize() {
		return true;
	}

	/**
	 * Get script handles to enqueue.
	 *
	 * @return string[]
	 */
	public function get_script_handles() {
		$asset_file = include __DIR__ . '/../assets/checkout-blocks.asset.php';

		wp_register_script(
			'turpialapp-checkout-blocks',
			__DIR__ . '/../assets/checkout-blocks.js',
			$asset_file['dependencies'],
			$asset_file['version'],
			true
		);

		return array( 'turpialapp-checkout-blocks' );
	}

	/**
	 * Get style handles to enqueue.
	 *
	 * @return string[]
	 */
	public function get_style_handles() {
		return array();
	}

	/**
	 * Get script data.
	 *
	 * @return array
	 */
	public function get_script_data() {
		$setting = get_option( 'woocommerce_turpialapp-for-woo-manager_settings' );

		return array(
			'showDniField'   => empty( $setting['customer_dni_meta_key'] ),
			'showVatField'   => empty( $setting['company_vat_meta_key'] ),
			'dniLabel'       => __( 'DNI / National ID', 'turpialapp-for-woo' ),
			'dniPlaceholder' => __( 'Enter your DNI number', 'turpialapp-for-woo' ),
			'vatLabel'       => __( 'Company VAT Number', 'turpialapp-for-woo' ),
			'vatPlaceholder' => __( 'Enter company VAT number', 'turpialapp-for-woo' ),
		);
	}
}
