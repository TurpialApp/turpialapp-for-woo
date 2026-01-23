<?php
/**
 * Plugin Name: Cachicamo App for WooCommerce
 * Plugin URI: https://cachicamo.app/
 * Description: Connect Cachicamo with WooCommerce for inventory sync and order management
 * Author: Kijam López
 * Author URI: https://cachicamo.app/
 * Version: 1.0.0
 * License: MIT
 * Text Domain: cachicamoapp-for-woo
 * Domain Path: /
 *
 * @package CachicamoApp_For_WooCommerce
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
define( 'CACHICAMO_APP_ENDPOINT', 'https://api.cachicamo.app' );
define( 'CACHICAMO_APP_DIR', plugin_dir_path( __FILE__ ) );
define( 'CACHICAMO_APP_URL', plugin_dir_url( __FILE__ ) );
define( 'CACHICAMO_APP_VERSION', '1.0.0' );

/**
 * Load required plugin files
 */
require_once CACHICAMO_APP_DIR . 'includes/class-cachicamoapp-for-woo.php';
require_once CACHICAMO_APP_DIR . 'includes/cachicamo-basic-api-functions.php';
require_once CACHICAMO_APP_DIR . 'includes/product-functions.php';
require_once CACHICAMO_APP_DIR . 'includes/customer-functions.php';
require_once CACHICAMO_APP_DIR . 'includes/order-functions.php';
require_once CACHICAMO_APP_DIR . 'includes/admin-functions.php';
require_once CACHICAMO_APP_DIR . 'includes/utils.php';
require_once CACHICAMO_APP_DIR . 'includes/webhook-handler.php';

/**
 * Initialize the plugin
 */
CachicamoApp_For_Woo::init();

/**
 * Load plugin text domain for translations
 */
$cachicamoapp_locale = apply_filters( 'plugin_locale', get_locale(), 'cachicamoapp-for-woo' );
load_textdomain( 'cachicamoapp-for-woo', trailingslashit( WP_LANG_DIR ) . 'cachicamoapp-for-woo/cachicamoapp-for-woo-' . $cachicamoapp_locale . '.mo' );
load_plugin_textdomain( 'cachicamoapp-for-woo', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
