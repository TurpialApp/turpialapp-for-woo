<?php
/**
 * Plugin Name: Turpial App for WooCommerce
 * Plugin URI: https://turpial.app/
 * Description: Export Orders to TurpialApp
 * Author: Kijam López
 * Author URI: https://turpial.app/
 * Version: 1.0.1
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
require_once TURPIAL_APP_DIR . 'includes/turpial-basic-api-functions.php';
require_once TURPIAL_APP_DIR . 'includes/product-functions.php';
require_once TURPIAL_APP_DIR . 'includes/customer-functions.php';
require_once TURPIAL_APP_DIR . 'includes/order-functions.php';
require_once TURPIAL_APP_DIR . 'includes/admin-functions.php';
require_once TURPIAL_APP_DIR . 'includes/utils.php';

/**
 * Initialize the plugin
 */
TurpialApp_For_Woo::init();

/**
 * Load plugin text domain for translations
 */
$turpialapp_locale = apply_filters( 'plugin_locale', get_locale(), 'turpialapp-for-woo' );
load_textdomain( 'turpialapp-for-woo', trailingslashit( WP_LANG_DIR ) . 'turpialapp-for-woo/turpialapp-for-woo-' . $turpialapp_locale . '.mo' );
load_plugin_textdomain( 'turpialapp-for-woo', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
