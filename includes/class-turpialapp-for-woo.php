<?php
/**
 * Main plugin class
 *
 * @package TurpialApp_For_WooCommerce
 * @since 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class for TurpialApp WooCommerce integration
 *
 * Handles core plugin functionality including activation, deactivation,
 * and initialization of features.
 *
 * @package TurpialApp_For_WooCommerce
 * @since 1.0.0
 */
class TurpialApp_For_Woo {
	/**
	 * Constructor for the main plugin class
	 *
	 * Initializes plugin hooks and filters
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function __construct() {
		// Add custom action links to the plugin settings page.
		add_filter(
			'plugin_action_links_' . plugin_basename( plugin_dir_path( __DIR__ ) . 'turpialapp-for-woo.php' ),
			function ( $links ) {
				$mylinks = array(
					'<a style="font-weight: bold;color: red" href="' . admin_url( 'admin.php?page=wc-settings&tab=integration&section=turpialapp-for-woo-manager' ) . '">Configure</a>',
				);
				return array_merge( $links, $mylinks );
			}
		);

		// Register activation and deactivation hooks.
		register_activation_hook( plugin_dir_path( __DIR__ ) . 'turpialapp-for-woo.php', array( self::class, 'activate' ) );
		register_deactivation_hook( plugin_dir_path( __DIR__ ) . 'turpialapp-for-woo.php', array( self::class, 'deactivate' ) );

		// Initialize cron jobs.
		self::init_cron_jobs();
	}

	/**
	 * Initialize the plugin
	 *
	 * Sets up initial plugin configuration and schedules
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function init() {
		// Schedule the stock update event upon plugin activation.
		if ( ! wp_next_scheduled( 'turpialapp_update_stock_from_api' ) ) {
			wp_schedule_single_event( time() + 10, 'turpialapp_update_stock_from_api' );
		}
	}

	/**
	 * Plugin activation handler
	 *
	 * Runs when plugin is activated to set up required schedules and configurations
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function activate() {
		// Schedule the stock update event upon plugin activation.
		if ( ! wp_next_scheduled( 'turpialapp_update_stock_from_api' ) ) {
			wp_schedule_single_event( time() + 10, 'turpialapp_update_stock_from_api' );
		}
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
		wp_clear_scheduled_hook( 'turpialapp_update_stock_from_api' );
		wp_clear_scheduled_hook( 'turpialapp_export_orders_cron' );
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
		// Schedule the order export event to run hourly.
		add_action(
			'init',
			function () {
				if ( ! wp_next_scheduled( 'turpialapp_export_orders_cron' ) ) {
					wp_schedule_event( time(), 'hourly', 'turpialapp_export_orders_cron' );
				}
			}
		);

		// Register cron callbacks.
		add_action( 'turpialapp_update_stock_from_api', 'turpialapp_update_stock_from_api' );
		add_action(
			'turpialapp_export_orders_cron',
			function () {
				turpialapp_export_orders();
			}
		);
	}
}
