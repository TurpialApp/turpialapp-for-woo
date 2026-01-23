<?php
/**
 * Admin Functions.
 *
 * Functions for admin dashboard integration.
 *
 * @package CachicamoApp_For_WooCommerce
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
			'cachicamoapp_invoice_metabox',
			esc_html__( 'Cachicamo App', 'cachicamoapp-for-woo' ),
			'cachicamoapp_invoice_metabox_content',
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
		$version   = defined( 'CACHICAMO_APP_VERSION' ) ? CACHICAMO_APP_VERSION : '1.0.0';

		wp_enqueue_script(
			'cachicamoapp-admin-invoice',
			$asset_url,
			array( 'jquery' ),
			$version,
			true
		);
	}
);

/**
 * Render content for Cachicamo invoice metabox.
 *
 * @param WP_Post $post The order post object to render content for.
 * @return void
 */
function cachicamoapp_invoice_metabox_content( $post ) {
	$order        = wc_get_order( $post->ID );
	$invoice_uuid = $order->get_meta( '_cachicamoapp_invoice_uuid' );
	$last_error   = $order->get_meta( '_cachicamoapp_invoice_last_error' );

	if ( $invoice_uuid ) {
		// Display the invoice UUID and a link to view the invoice.
		echo '<p><strong>' . esc_html__( 'Invoice UUID', 'cachicamoapp-for-woo' ) . ':</strong> ' . esc_html( $invoice_uuid ) . '</p>';
		echo '<p><a href="https://dashboard.cachicamo.app/store/invoices/view-invoice/' . esc_attr( $invoice_uuid ) . '" target="_blank">' . esc_html__( 'View Invoice', 'cachicamoapp-for-woo' ) . '</a></p>';
	}

	if ( $last_error ) {
		// Display any last error messages.
		echo '<p class="error" style="color:red;">' . esc_html( $last_error ) . '</p>';
	}

	// Localize the script with necessary data.
	wp_localize_script(
		'cachicamoapp-admin-invoice',
		'cachicamoapp_invoice_data',
		array(
			'order_id'         => $post->ID,
			'nonce'            => wp_create_nonce( 'cachicamoapp_create_invoice' ),
			'error_message'    => esc_js( __( 'Error creating the invoice', 'cachicamoapp-for-woo' ) ),
			'connection_error' => esc_js( __( 'Connection error', 'cachicamoapp-for-woo' ) ),
		)
	);
}
