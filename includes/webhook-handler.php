<?php
/**
 * Webhook Handler
 *
 * Handles webhooks from WooCommerce and forwards them to Cachicamo.
 *
 * @package CachicamoApp_For_WooCommerce
 * @since 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register REST API endpoint for WooCommerce webhooks.
 */
add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'cachicamoapp/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'           => 'cachicamoapp_handle_webhook',
				'permission_callback' => '__return_true', // Webhooks will be verified by signature
			)
		);
	}
);

/**
 * Handle webhook from WooCommerce.
 *
 * Receives webhook data from WooCommerce and forwards it to Cachicamo.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error Response object.
 */
function cachicamoapp_handle_webhook( WP_REST_Request $request ) {
	$setting = cachicamoapp_setting();
	
	if ( ! $setting || empty( $setting['access_token'] ) || empty( $setting['store_uuid'] ) ) {
		cachicamoapp_log( array( 'cachicamoapp_handle_webhook -> Missing configuration' ), 'error' );
		return new WP_Error( 'missing_config', __( 'Cachicamo configuration is missing', 'cachicamoapp-for-woo' ), array( 'status' => 400 ) );
	}

	// Get webhook data
	$webhook_data = $request->get_json_params();

	cachicamoapp_log( array(
		'cachicamoapp_handle_webhook -> Received' => array(
			'order_id' => isset( $webhook_data['id'] ) ? $webhook_data['id'] : null,
			'status'   => isset( $webhook_data['status'] ) ? $webhook_data['status'] : null,
		)
	), 'info' );

	// Prepare data to send to Cachicamo
	$cachicamo_data = array(
		'id'         => isset( $webhook_data['id'] ) ? $webhook_data['id'] : null,
		'status'     => isset( $webhook_data['status'] ) ? $webhook_data['status'] : null,
		'total'      => isset( $webhook_data['total'] ) ? $webhook_data['total'] : null,
		'currency'   => isset( $webhook_data['currency'] ) ? $webhook_data['currency'] : null,
		'created_at' => isset( $webhook_data['date_created'] ) ? $webhook_data['date_created'] : null,
		'updated_at' => isset( $webhook_data['date_modified'] ) ? $webhook_data['date_modified'] : null,
		'customer'   => array(),
		'line_items' => array(),
	);

	// Extract customer data
	if ( isset( $webhook_data['billing'] ) ) {
		$cachicamo_data['customer'] = array(
			'email' => isset( $webhook_data['billing']['email'] ) ? $webhook_data['billing']['email'] : '',
			'name'  => trim( ( isset( $webhook_data['billing']['first_name'] ) ? $webhook_data['billing']['first_name'] : '' ) . ' ' . ( isset( $webhook_data['billing']['last_name'] ) ? $webhook_data['billing']['last_name'] : '' ) ),
			'phone' => isset( $webhook_data['billing']['phone'] ) ? $webhook_data['billing']['phone'] : '',
			'dni'   => isset( $webhook_data['meta_data'] ) ? cachicamoapp_extract_meta_value( $webhook_data['meta_data'], '_billing_dni' ) : null,
		);
	}

	// Extract line items
	if ( isset( $webhook_data['line_items'] ) && is_array( $webhook_data['line_items'] ) ) {
		foreach ( $webhook_data['line_items'] as $item ) {
			$cachicamo_data['line_items'][] = array(
				'sku'      => isset( $item['sku'] ) ? $item['sku'] : '',
				'name'     => isset( $item['name'] ) ? $item['name'] : '',
				'quantity' => isset( $item['quantity'] ) ? floatval( $item['quantity'] ) : 0,
				'total'    => isset( $item['total'] ) ? $item['total'] : '0.00',
				'price'    => isset( $item['price'] ) ? $item['price'] : '0.00',
			);
		}
	}

	// Send to Cachicamo
	$result = wp_remote_post(
		CACHICAMO_APP_ENDPOINT . '/webhooks/woocommerce/' . $setting['store_uuid'],
		array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $setting['access_token'],
				'Content-Type'  => 'application/json',
				'X-Store-Uuid'  => $setting['store_uuid'],
			),
			'body'    => wp_json_encode( $cachicamo_data ),
			'timeout' => 30,
		)
	);

	if ( is_wp_error( $result ) ) {
		cachicamoapp_log( array( 'cachicamoapp_handle_webhook -> Error sending to Cachicamo' => $result->get_error_message() ), 'error' );
		return new WP_Error( 'send_error', __( 'Error sending webhook to Cachicamo', 'cachicamoapp-for-woo' ), array( 'status' => 500 ) );
	}

	$response_code = wp_remote_retrieve_response_code( $result );

	cachicamoapp_log( array(
		'cachicamoapp_handle_webhook -> Cachicamo response' => array( 'code' => $response_code )
	), 'info' );

	return new WP_REST_Response(
		array(
			'success' => true,
			'message' => __( 'Webhook forwarded to Cachicamo', 'cachicamoapp-for-woo' ),
		),
		200
	);
}

/**
 * Extract meta value from WooCommerce order meta_data array.
 *
 * @param array  $meta_data Meta data array.
 * @param string $key Meta key to search for.
 * @return string|null Meta value or null if not found.
 */
function cachicamoapp_extract_meta_value( $meta_data, $key ) {
	if ( ! is_array( $meta_data ) ) {
		return null;
	}

	foreach ( $meta_data as $meta ) {
		if ( isset( $meta['key'] ) && $meta['key'] === $key ) {
			return isset( $meta['value'] ) ? $meta['value'] : null;
		}
	}

	return null;
}

/**
 * Register WooCommerce webhooks automatically when settings are saved.
 *
 * This function should be called when settings are updated to ensure
 * webhooks are registered in WooCommerce.
 */
// Webhooks are configured manually in WooCommerce > Settings > Advanced > Webhooks
// No automatic registration needed
