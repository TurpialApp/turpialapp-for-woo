<?php
/**
 * WooCommerce Blocks integration for CachicamoApp custom fields
 *
 * @package CachicamoApp_For_WooCommerce
 * @since 1.0.0
 */

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Class CachicamoApp_Checkout_Blocks_Integration
 */
class CachicamoApp_Checkout_Blocks_Integration implements IntegrationInterface {
	/**
	 * The name of the integration.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'cachicamoapp-custom-fields';
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
			'cachicamoapp-checkout-blocks',
			__DIR__ . '/../assets/checkout-blocks.js',
			$asset_file['dependencies'],
			$asset_file['version'],
			true
		);

		return array( 'cachicamoapp-checkout-blocks' );
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
		$setting = cachicamoapp_setting();

		return array(
			'showDniField'       => empty( $setting['customer_dni_meta_key'] ),
			'showVatField'       => empty( $setting['company_vat_meta_key'] ),
			'dniLabel'           => __( 'DNI / National ID', 'cachicamoapp-for-woo' ),
			'dniPlaceholder'     => __( 'Enter your DNI number', 'cachicamoapp-for-woo' ),
			'companyLabel'       => __( 'Company Name', 'cachicamoapp-for-woo' ),
			'companyPlaceholder' => __( 'Enter company Name', 'cachicamoapp-for-woo' ),
			'vatLabel'           => __( 'Company VAT Number', 'cachicamoapp-for-woo' ),
			'vatPlaceholder'     => __( 'Enter company VAT number', 'cachicamoapp-for-woo' ),
		);
	}
}
