<?php
/**
 * Plugin Name: Turpial App for WooCommerce
 * Plugin URI: https://yipi.app/
 * Description: Export Orders to TurpialApp
 * Author: Kijam López
 * Author URI: https://yipi.app/
 * Version: 1.0.2
 * License: MIT
 * Text Domain: turpialapp-for-woo
 * Domain Path: /
 *
 * @package TurpialApp_For_WooCommerce
 */

/**
 * Prevent direct access to this file
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Define plugin constants
 */
define( 'TURPIAL_APP_ENDPOINT', 'https://api.turpial.app' );
define( 'TURPIAL_APP_DIR', plugin_dir_path( __FILE__ ) );
define( 'TURPIAL_APP_URL', plugin_dir_url( __FILE__ ) );

/**
 * Load required plugin files
 */
require_once TURPIAL_APP_DIR . 'includes/class-turpialapp-for-woo.php';
require_once TURPIAL_APP_DIR . 'includes/class-turpialapp-api-manager.php';
require_once TURPIAL_APP_DIR . 'includes/api-functions.php';
require_once TURPIAL_APP_DIR . 'includes/product-functions.php';
require_once TURPIAL_APP_DIR . 'includes/customer-functions.php';
require_once TURPIAL_APP_DIR . 'includes/order-functions.php';
require_once TURPIAL_APP_DIR . 'includes/admin-functions.php';
require_once TURPIAL_APP_DIR . 'includes/utils.php';

/**
 * Initialize the plugin
 */
TurpialAppForWoo::init();

/**
 * Load plugin text domain for translations
 */
add_action(
	'init',
	function () {
		$locale = apply_filters( 'plugin_locale', get_locale(), 'turpialapp-for-woo' );

		load_textdomain( 'turpialapp-for-woo', trailingslashit( WP_LANG_DIR ) . 'turpialapp-for-woo/turpialapp-for-woo-' . $locale . '.mo' );
		load_plugin_textdomain( 'turpialapp-for-woo', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
	}
);

// Add TurpialApp integration to WooCommerce.
add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WC_Integration' ) ) {
			return;
		}

		// Add the integration to WooCommerce.
		add_filter(
			'woocommerce_integrations',
			function ( $integrations ) {
				$integrations[] = 'TurpialApp_API_Manager';
				return $integrations;
			}
		);
	}
);
