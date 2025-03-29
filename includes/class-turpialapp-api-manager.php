<?php
/**
 * API Manager Class.
 *
 * Handles communication with TurpialApp API.
 *
 * @package TurpialApp_For_WooCommerce
 * @since 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TurpialApp API Manager Class.
 *
 * Handles integration with TurpialApp API for WooCommerce.
 *
 * @package TurpialApp_For_WooCommerce
 * @since 1.0.0
 * @extends WC_Integration
 */
class TurpialApp_API_Manager extends WC_Integration {
	/**
	 * Static instance of this class.
	 *
	 * @var TurpialApp_API_Manager|null
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

		$this->id           = 'turpialapp-for-woo-manager';
		$this->has_fields   = false;
		$this->method_title = __( 'Turpial App', 'turpialapp-for-woo' );

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
	 * @return TurpialApp_API_Manager Instance of this class.
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
			'access_token'          => array(
				'title'   => __( 'Access Token', 'turpialapp-for-woo' ),
				'type'    => 'text',
				'default' => '',
			),
			'store_uuid'            => array(
				'title'   => __( 'Store/Warehouse S-ID', 'turpialapp-for-woo' ),
				'type'    => 'text',
				'default' => '',
			),
			'employee_uuid'         => array(
				'title'   => __( 'User/Employee T-ID', 'turpialapp-for-woo' ),
				'type'    => 'text',
				'default' => '',
			),
			'printer_document_uuid' => array(
				'title'   => __( 'Fiscal Printer', 'turpialapp-for-woo' ),
				'type'    => 'select',
				'default' => '',
			),
			'cron_time'             => array(
				'title'   => __( 'Cronjob Time in Minutes for Stock and Inventory Update', 'turpialapp-for-woo' ),
				'type'    => 'text',
				'default' => '10',
			),
			'send_order'            => array(
				'title'   => __( 'Automatically send orders as invoices once payment is validated.', 'turpialapp-for-woo' ),
				'type'    => 'checkbox',
				'label'   => __( 'Send orders as invoices to Turpial', 'turpialapp-for-woo' ),
				'default' => 'no',
			),
			'export_order_date'     => array(
				'title'   => __( 'Start date for exporting orders (Format YYYY-MM-DD)', 'turpialapp-for-woo' ),
				'type'    => 'text',
				'default' => '',
			),
			'debug'                 => array(
				'title'   => __( 'Debug', 'turpialapp-for-woo' ),
				'type'    => 'checkbox',
				'label'   => __( 'Activate log', 'turpialapp-for-woo' ),
				'default' => 'no',
			),
		);

		// Get all payment methods available in WooCommerce.
		$all_payment_methods = WC()->payment_gateways->payment_gateways();
		$options             = turpialapp_get_all_payment_method_for_select();
		foreach ( $all_payment_methods as $payment_method ) {
			$this->form_fields[ 'payment_method_' . $payment_method->id ] = array(
				'title'   => __( 'Payment Method for', 'turpialapp-for-woo' ) . ' ' . $payment_method->title,
				'type'    => 'select',
				'options' => array( '' => __( 'Select one after saving your access token', 'turpialapp-for-woo' ) ),
				'default' => '',
			);
			if ( count( $options ) > 0 ) {
				$this->form_fields[ 'payment_method_' . $payment_method->id ]['options'] = array( '' => __( 'Select one', 'turpialapp-for-woo' ) ) + $options;
			}
		}
		$options = turpialapp_get_all_printer_documents_options();
		if ( count( $options ) > 0 ) {
			$options = array( '' => __( 'Select one', 'turpialapp-for-woo' ) ) + $options;
			$this->form_fields['printer_document_uuid']['options'] = $options;
		} else {
			$this->form_fields['printer_document_uuid']['options'] = array( '' => __( 'Select one after saving your access token', 'turpialapp-for-woo' ) );
		}
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
		wp_clear_scheduled_hook( 'turpialapp_update_stock_from_api' );
		$cron_time = (int) preg_replace( '/[^0-9]/', '', $this->get_option( 'cron_time' ) );
		if ( $cron_time < 1 ) {
			$cron_time = 10; // Default to 10 minutes if invalid.
		}
		wp_schedule_single_event( time() + ( $cron_time * 60 ), 'turpialapp_update_stock_from_api' );
	}

	/**
	 * Make API request to TurpialApp endpoint.
	 *
	 * @since 1.0.0
	 * @param string $endpoint API endpoint path.
	 * @param array  $data Request data array.
	 * @param string $method HTTP request method.
	 * @return array|WP_Error Response data or error object.
	 */
	public function request( $endpoint, $data = array(), $method = 'GET' ) {
		// ... existing code ...
		return array();
	}
}
