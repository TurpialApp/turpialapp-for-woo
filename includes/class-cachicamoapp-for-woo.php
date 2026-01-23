<?php
/**
 * Main plugin class
 *
 * @package CachicamoApp_For_WooCommerce
 * @since 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class for CachicamoApp WooCommerce integration
 *
 * Handles core plugin functionality including activation, deactivation,
 * and initialization of features.
 *
 * @package CachicamoApp_For_WooCommerce
 * @since 1.0.0
 */
class CachicamoApp_For_Woo {
	/**
	 * Initialize the main plugin class
	 *
	 * Initializes plugin hooks and filters
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function init() {
		// Add custom action links to the plugin settings page.
		add_filter(
			'plugin_action_links_' . plugin_basename( plugin_dir_path( __DIR__ ) . 'cachicamoapp-for-woo.php' ),
			function ( $links ) {
				$mylinks = array(
					'<a style="font-weight: bold;color: red" href="' . admin_url( 'admin.php?page=wc-settings&tab=integration&section=cachicamoapp-for-woo-manager' ) . '">' . __( 'Settings', 'cachicamoapp-for-woo' ) . '</a>',
				);
				return array_merge( $links, $mylinks );
			}
		);

		// Add CachicamoApp integration to WooCommerce.
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
						require_once __DIR__ . '/class-cachicamoapp-api-manager.php';
						$integrations[] = 'CachicamoApp_API_Manager';
						return $integrations;
					}
				);
			}
		);

		register_deactivation_hook( plugin_dir_path( __DIR__ ) . 'cachicamoapp-for-woo.php', array( self::class, 'deactivate' ) );

		// Initialize cron jobs.
		self::init_cron_jobs();
	}


	/**
	 * Plugin deactivation handler
	 *
	 * Cleans up plugin schedules and configurations on deactivation
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function deactivate() {
		// Clear the scheduled stock update event upon plugin deactivation.
		wp_clear_scheduled_hook( 'cachicamoapp_update_stock_from_api' );
	}

	/**
	 * Initialize cron jobs
	 *
	 * Sets up WordPress cron schedules and handlers for plugin tasks
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private static function init_cron_jobs() {
		// Schedule the stock update event upon plugin activation.
		if ( ! wp_next_scheduled( 'cachicamoapp_update_stock_from_api' ) ) {
			wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'cachicamoapp_update_stock_from_api' );
		}

		// Register cron callbacks.
		add_action( 'cachicamoapp_update_stock_from_api', 'cachicamoapp_update_stock_from_api' );
	}
}
