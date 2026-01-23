<?php
/**
 * API Manager Class.
 *
 * Handles communication with CachicamoApp API and WooCommerce configuration.
 *
 * @package CachicamoApp_For_WooCommerce
 * @since 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CachicamoApp API Manager Class.
 *
 * Handles integration with CachicamoApp API for WooCommerce.
 *
 * @package CachicamoApp_For_WooCommerce
 * @since 1.0.0
 * @extends WC_Integration
 */
class CachicamoApp_API_Manager extends WC_Integration {
	/**
	 * Static instance of this class.
	 *
	 * @var CachicamoApp_API_Manager|null
	 */
	private static $is_load = null;

	/**
	 * API endpoint.
	 *
	 * @var string
	 */
	private $api_endpoint;

	/**
	 * Constructor for the integration class.
	 *
	 * Initialize the integration, set up settings and hooks.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function __construct() {
		self::$is_load = $this;

		$this->id           = 'cachicamoapp-for-woo-manager';
		$this->has_fields   = false;
		$this->method_title = __( 'Cachicamo App', 'cachicamoapp-for-woo' );

		// Hook to process admin options when settings are updated.
		add_action( 'woocommerce_update_options_integration_' . $this->id, array( $this, 'process_admin_options' ) );

		// Load the settings.
		$this->init_settings();
		// Load the form fields.
		$this->init_form_fields();
	}

	/**
	 * Get singleton instance of this class.
	 *
	 * @since 1.0.0
	 * @return CachicamoApp_API_Manager Instance of this class.
	 */
	public static function get_instance() {
		if ( is_null( self::$is_load ) ) {
			self::$is_load = new self();
		}
		return self::$is_load;
	}

	/**
	 * Initialize form fields for the integration settings.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function init_form_fields() {
		// Define the form fields for the integration settings.
		$this->form_fields = array(
			'section_cachicamo' => array(
				'title' => __( 'Cachicamo Configuration', 'cachicamoapp-for-woo' ),
				'type'  => 'title',
			),
			'access_token'      => array(
				'title'       => __( 'Access Token', 'cachicamoapp-for-woo' ),
				'type'        => 'text',
				'description' => __( 'Your Cachicamo API access token', 'cachicamoapp-for-woo' ),
				'default'     => '',
			),
			'store_uuid'        => array(
				'title'       => __( 'Store/Warehouse UUID', 'cachicamoapp-for-woo' ),
				'type'        => 'text',
				'description' => __( 'Your Cachicamo Store UUID', 'cachicamoapp-for-woo' ),
				'default'     => '',
			),
			'employee_uuid'     => array(
				'title'       => __( 'User/Employee UUID', 'cachicamoapp-for-woo' ),
				'type'        => 'text',
				'description' => __( 'Your Cachicamo Employee UUID', 'cachicamoapp-for-woo' ),
				'default'     => '',
			),
			'section_inventory' => array(
				'title' => __( 'Inventory Synchronization', 'cachicamoapp-for-woo' ),
				'type'  => 'title',
			),
			'cron_time'         => array(
				'title'       => __( 'Sync Interval (minutes)', 'cachicamoapp-for-woo' ),
				'type'        => 'text',
				'description' => __( 'Frequency of stock synchronization from Cachicamo to WooCommerce (minimum 10 minutes, default: 720 = 12 hours)', 'cachicamoapp-for-woo' ),
				'default'     => '720',
			),
			'section_debug' => array(
				'title' => __( 'Debug', 'cachicamoapp-for-woo' ),
				'type'  => 'title',
			),
			'debug'             => array(
				'title'   => __( 'Debug', 'cachicamoapp-for-woo' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable logging', 'cachicamoapp-for-woo' ),
				'default' => 'no',
			),
		);
	}


	/**
	 * Process and save admin options.
	 *
	 * Saves the options and schedules stock update event.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function process_admin_options() {
		// Process the admin options and schedule the stock update event.
		parent::process_admin_options();
		wp_clear_scheduled_hook( 'cachicamoapp_update_stock_from_api' );
		$cron_time = (int) preg_replace( '/[^0-9]/', '', $this->get_option( 'cron_time' ) );
		if ( $cron_time < 10 ) {
			$cron_time = 720; // Default to 12 hours (720 minutes) if invalid or less than minimum.
		}
		wp_schedule_single_event( time() + ( $cron_time * 60 ), 'cachicamoapp_update_stock_from_api' );
	}

	/**
	 * Make API request to CachicamoApp endpoint.
	 *
	 * @since 1.0.0
	 * @param string $endpoint API endpoint path.
	 * @param array  $data Request data array.
	 * @param string $method HTTP request method.
	 * @return array|WP_Error Response data or error object.
	 */
	public function request( $endpoint, $data = array(), $method = 'GET' ) {
		$setting = $this->get_option( 'access_token' );
		if ( ! $setting ) {
			return new WP_Error( 'no_token', __( 'Access token not configured', 'cachicamoapp-for-woo' ) );
		}

		$args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization' => 'Bearer ' . $setting,
				'Content-Type'  => 'application/json',
				'X-Store-Uuid'  => $this->get_option( 'store_uuid' ),
			),
		);

		if ( 'POST' === $method || 'PUT' === $method ) {
			$args['body'] = wp_json_encode( $data );
		}

		$response = wp_remote_request( CACHICAMO_APP_ENDPOINT . $endpoint, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return json_decode( wp_remote_retrieve_body( $response ), true );
	}
}
