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

		// Register AJAX handler for manual sync.
		add_action( 'wp_ajax_cachicamoapp_manual_sync', array( $this, 'ajax_manual_sync' ) );

		// Enqueue admin scripts.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

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
			'manual_sync'        => array(
				'title' => __( 'Manual Sync', 'cachicamoapp-for-woo' ),
				'type'  => 'manual_sync',
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

	/**
	 * Generate HTML for manual sync button.
	 *
	 * @since 1.0.0
	 * @param string $key Field key.
	 * @param array  $data Field data.
	 * @return string HTML output.
	 */
	public function generate_manual_sync_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$last_sync = get_option( 'cachicamoapp_last_stock_sync', '' );
		$sync_count = get_option( 'cachicamoapp_last_stock_sync_count', 0 );
		$sync_errors = get_option( 'cachicamoapp_last_stock_sync_errors', 0 );

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label><?php echo esc_html( $data['title'] ); ?></label>
			</th>
			<td class="forminp">
				<fieldset>
					<button type="button" id="cachicamoapp-manual-sync-btn" class="button button-secondary">
						<span class="dashicons dashicons-update" style="vertical-align: middle; margin-top: 3px;"></span>
						<?php esc_html_e( 'Force Inventory Sync', 'cachicamoapp-for-woo' ); ?>
					</button>
					<span id="cachicamoapp-sync-status" style="margin-left: 10px;"></span>
					<p class="description" style="margin-top: 10px;">
						<?php esc_html_e( 'Manually trigger inventory synchronization from Cachicamo to WooCommerce.', 'cachicamoapp-for-woo' ); ?>
					</p>
					<?php if ( $last_sync ) : ?>
						<div id="cachicamoapp-last-sync-info" style="margin-top: 10px; padding: 10px; background: #f0f0f1; border-left: 4px solid #2271b1;">
							<p style="margin: 0;">
								<strong><?php esc_html_e( 'Last Sync:', 'cachicamoapp-for-woo' ); ?></strong>
								<?php echo esc_html( $last_sync ); ?>
							</p>
							<?php if ( $sync_count > 0 ) : ?>
								<p style="margin: 5px 0 0 0;">
									<strong><?php esc_html_e( 'Products Synced:', 'cachicamoapp-for-woo' ); ?></strong>
									<?php echo esc_html( number_format_i18n( $sync_count ) ); ?>
								</p>
							<?php endif; ?>
							<?php if ( $sync_errors > 0 ) : ?>
								<p style="margin: 5px 0 0 0; color: #d63638;">
									<strong><?php esc_html_e( 'Errors:', 'cachicamoapp-for-woo' ); ?></strong>
									<?php echo esc_html( number_format_i18n( $sync_errors ) ); ?>
								</p>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</fieldset>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * Enqueue admin scripts for manual sync.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function enqueue_admin_scripts( $hook ) {
		// Only load on WooCommerce settings page.
		if ( 'woocommerce_page_wc-settings' !== $hook ) {
			return;
		}

		// Check if we're on the integration settings page.
		if ( ! isset( $_GET['tab'] ) || 'integration' !== $_GET['tab'] ) {
			return;
		}

		if ( ! isset( $_GET['section'] ) || 'cachicamoapp-for-woo-manager' !== $_GET['section'] ) {
			return;
		}

		wp_enqueue_script( 'jquery' );
		wp_add_inline_script(
			'jquery',
			"
			jQuery(document).ready(function($) {
				$('#cachicamoapp-manual-sync-btn').on('click', function(e) {
					e.preventDefault();
					var \$btn = $(this);
					var \$status = $('#cachicamoapp-sync-status');
					
					\$btn.prop('disabled', true);
					\$status.html('<span class=\"spinner is-active\" style=\"float: none; margin: 0 5px;\"></span> " . esc_js( __( 'Synchronizing...', 'cachicamoapp-for-woo' ) ) . "');
					
					$.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'cachicamoapp_manual_sync',
							nonce: '" . wp_create_nonce( 'cachicamoapp_manual_sync' ) . "'
						},
						success: function(response) {
							if (response.success) {
								\$status.html('<span style=\"color: #00a32a;\">✓ " . esc_js( __( 'Sync completed successfully', 'cachicamoapp-for-woo' ) ) . "</span>');
								if (response.data && response.data.message) {
									\$status.append('<br><small>' + response.data.message + '</small>');
								}
								// Reload page after 2 seconds to show updated stats
								setTimeout(function() {
									location.reload();
								}, 2000);
							} else {
								\$status.html('<span style=\"color: #d63638;\">✗ " . esc_js( __( 'Sync failed', 'cachicamoapp-for-woo' ) ) . "</span>');
								if (response.data && response.data.message) {
									\$status.append('<br><small style=\"color: #d63638;\">' + response.data.message + '</small>');
								}
								\$btn.prop('disabled', false);
							}
						},
						error: function() {
							\$status.html('<span style=\"color: #d63638;\">✗ " . esc_js( __( 'Connection error', 'cachicamoapp-for-woo' ) ) . "</span>');
							\$btn.prop('disabled', false);
						}
					});
				});
			});
			"
		);
	}

	/**
	 * AJAX handler for manual inventory sync.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function ajax_manual_sync() {
		// Verify nonce.
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'cachicamoapp_manual_sync' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed', 'cachicamoapp-for-woo' ) ) );
			return;
		}

		// Check user capabilities.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'cachicamoapp-for-woo' ) ) );
			return;
		}

		// Check if settings are configured.
		$setting = cachicamoapp_setting();
		if ( ! $setting || empty( $setting['access_token'] ) || empty( $setting['store_uuid'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Plugin configuration is incomplete. Please configure Access Token and Store UUID.', 'cachicamoapp-for-woo' ) ) );
			return;
		}

		// Run the sync function directly.
		// The function is already loaded via the main plugin file, but we include it here for safety.
		if ( ! function_exists( 'cachicamoapp_update_stock_from_api' ) ) {
			require_once CACHICAMO_APP_DIR . 'includes/cachicamo-basic-api-functions.php';
		}
		
		cachicamoapp_update_stock_from_api();
		
		$sync_count = get_option( 'cachicamoapp_last_stock_sync_count', 0 );
		$sync_errors = get_option( 'cachicamoapp_last_stock_sync_errors', 0 );
		
		$message = sprintf(
			// translators: %1$d: number of products synced, %2$d: number of errors.
			__( 'Sync completed. %1$d products updated. %2$d errors.', 'cachicamoapp-for-woo' ),
			$sync_count,
			$sync_errors
		);
		
		wp_send_json_success( array( 'message' => $message ) );
	}
}
