<?php
/**
 * Plugin Name: Cachicamo App for WooCommerce
 * Plugin URI: https://cachicamo.app/
 * Description: Synchronizes products, inventory and orders between a WooCommerce store and Cachicamo App.
 * Version: 2.0.0
 * Author: Cachicamo
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: cachicamoapp-for-woo
 * Domain Path: /languages
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.9
 */

defined( 'ABSPATH' ) || exit;

define( 'CACHICAMO_APP_VERSION', '2.0.0' );
define( 'CACHICAMO_APP_FILE', __FILE__ );
define( 'CACHICAMO_APP_DIR', plugin_dir_path( __FILE__ ) );
define( 'CACHICAMO_APP_URL', plugin_dir_url( __FILE__ ) );

if ( ! defined( 'CACHICAMO_APP_API_URL' ) ) {
	define( 'CACHICAMO_APP_API_URL', 'https://api.cachicamo.app' );
}

require_once CACHICAMO_APP_DIR . 'src/autoload.php';

add_action( 'plugins_loaded', array( '\\Cachicamo\\WooCommerce\\Plugin', 'boot' ) );
