<?php

namespace Cachicamo\WooCommerce\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Single admin page; the React app (client/admin) decides internally whether to show the
 * connection wizard or the settings screens based on GET /admin/state.
 */
class Menu {

	const PAGE_SLUG = 'cachicamoapp';

	public static function register_hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function register_page() {
		add_menu_page(
			__( 'Cachicamo App', 'cachicamoapp-for-woo' ),
			__( 'Cachicamo App', 'cachicamoapp-for-woo' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-cart',
			56
		);
	}

	public static function render() {
		echo '<div id="cachicamoapp-admin-root"></div>';
	}

	public static function enqueue( $hook ) {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		$asset_path = CACHICAMO_APP_DIR . 'assets/build/admin.asset.php';
		$asset      = file_exists( $asset_path )
			? require $asset_path
			: array(
				'dependencies' => array( 'wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch' ),
				'version'      => CACHICAMO_APP_VERSION,
			);

		wp_enqueue_script(
			'cachicamoapp-admin',
			CACHICAMO_APP_URL . 'assets/build/admin.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_set_script_translations( 'cachicamoapp-admin', 'cachicamoapp-for-woo', CACHICAMO_APP_DIR . 'languages' );

		if ( file_exists( CACHICAMO_APP_DIR . 'assets/build/admin.css' ) ) {
			wp_enqueue_style( 'cachicamoapp-admin', CACHICAMO_APP_URL . 'assets/build/admin.css', array( 'wp-components' ), $asset['version'] );
		}

		wp_localize_script(
			'cachicamoapp-admin',
			'cachicamoAppAdmin',
			array(
				'restUrl' => esc_url_raw( rest_url( 'cachicamoapp/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);
	}
}
