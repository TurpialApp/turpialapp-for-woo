<?php
/**
 * Admin Functions.
 *
 * Functions for admin dashboard integration.
 *
 * @package TurpialApp_For_WooCommerce
 * @since 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add a metabox to WooCommerce orders.
 *
 * @return void
 */
add_action(
	'add_meta_boxes',
	function () {
		// Detect if using custom orders table in WooCommerce.
		$screen = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) &&
				wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
		? wc_get_page_screen_id( 'shop-order' )
		: 'shop_order';

		add_meta_box(
			'turpialapp_invoice_metabox',
			esc_html__( 'Turpial App Invoice', 'turpialapp-for-woo' ),
			'turpialapp_invoice_metabox_content',
			$screen,
			'side',
			'default'
		);
	}
);

/**
 * Enqueue admin scripts and styles.
 *
 * @return void
 */
add_action(
	'admin_enqueue_scripts',
	function () {
		$asset_url = plugin_dir_url( __DIR__ ) . 'assets/js/admin-invoice.js';
		$version   = defined( 'TURPIALAPP_FOR_WOO_VERSION' ) ? TURPIALAPP_FOR_WOO_VERSION : '1.0.0';

		wp_enqueue_script(
			'turpialapp-admin-invoice',
			$asset_url,
			array( 'jquery' ),
			$version,
			true
		);
	}
);

/**
 * Render content for Turpial invoice metabox.
 *
 * @param WP_Post $post The order post object to render content for.
 * @return void
 */
function turpialapp_invoice_metabox_content( $post ) {
	$order        = wc_get_order( $post->ID );
	$invoice_uuid = $order->get_meta( '_turpialapp_invoice_uuid' );
	$last_error   = $order->get_meta( '_turpialapp_invoice_last_error' );

	if ( $invoice_uuid ) {
		// Display the invoice UUID and a link to view the invoice.
		echo '<p><strong>' . esc_html__( 'Invoice UUID', 'turpialapp-for-woo' ) . ':</strong> ' . esc_html( $invoice_uuid ) . '</p>';
		echo '<p><a href="https://dashboard.turpial.app/store/invoices/view-invoice/' . esc_attr( $invoice_uuid ) . '" target="_blank">' . esc_html__( 'View Invoice', 'turpialapp-for-woo' ) . '</a></p>';
	} else {
		// Display button to create an invoice.
		wp_nonce_field( 'turpialapp_create_invoice', 'turpialapp_nonce' );
		echo '<button type="button" class="button button-primary" id="turpialapp_create_invoice">' .
			esc_html__( 'Create Invoice', 'turpialapp-for-woo' ) . '</button>';
		echo '<span class="spinner" style="float:none;"></span>';
	}

	if ( $last_error ) {
		// Display any last error messages.
		echo '<p class="error" style="color:red;">' . esc_html( $last_error ) . '</p>';
	}

	// Localize the script with necessary data.
	wp_localize_script(
		'turpialapp-admin-invoice',
		'turpialapp_invoice_data',
		array(
			'order_id'         => $post->ID,
			'nonce'            => wp_create_nonce( 'turpialapp_create_invoice' ),
			'error_message'    => esc_js( __( 'Error creating the invoice', 'turpialapp-for-woo' ) ),
			'connection_error' => esc_js( __( 'Connection error', 'turpialapp-for-woo' ) ),
		)
	);
}

/**
 * Handle AJAX request to create an invoice.
 *
 * @return void
 */
add_action(
	'wp_ajax_turpialapp_create_invoice',
	function () {
		check_ajax_referer( 'turpialapp_create_invoice', 'nonce' );

		if ( ! isset( $_POST['order_id'] ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Missing order ID', 'turpialapp-for-woo' ) ) );
			return;
		}

		$order_id = intval( $_POST['order_id'] );
		if ( ! $order_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid order ID', 'turpialapp-for-woo' ) ) );
			return;
		}

		try {
			turpialapp_export_order( wc_get_order( $order_id ) ); // Attempt to export the order.
			wp_send_json_success();
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}
);
