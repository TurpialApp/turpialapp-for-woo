<?php

namespace Cachicamo\WooCommerce\Billing;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the Cachicamo billing state on the order edit screen: document status, its number and
 * control number, a link to the PDF, the last error, and a manual retry that reschedules the
 * same issuance job under the same idempotency key.
 */
class OrderMetabox {

	const RETRY_ACTION = 'cachicamoapp_retry_invoice';

	public static function register_hooks() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_box' ) );
		add_action( 'admin_post_' . self::RETRY_ACTION, array( __CLASS__, 'handle_retry' ) );
	}

	public static function register_box() {
		$screen = class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';

		add_meta_box(
			'cachicamoapp_billing',
			__( 'Cachicamo', 'cachicamoapp-for-woo' ),
			array( __CLASS__, 'render' ),
			$screen,
			'side',
			'default'
		);
	}

	public static function render( $post_or_order ) {
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$state          = $order->get_meta( '_cachicamo_state' );
		$document_number = $order->get_meta( '_cachicamo_document_number' );
		$control_number  = $order->get_meta( '_cachicamo_control_number' );
		$error           = $order->get_meta( '_cachicamo_error' );
		$invoice_uuid    = $order->get_meta( '_cachicamo_invoice_uuid' );

		echo '<p><strong>' . esc_html__( 'Status', 'cachicamoapp-for-woo' ) . ':</strong> ' . esc_html( $state ? $state : '-' ) . '</p>';
		if ( ! empty( $document_number ) ) {
			echo '<p><strong>' . esc_html__( 'Document number', 'cachicamoapp-for-woo' ) . ':</strong> ' . esc_html( $document_number ) . '</p>';
		}
		if ( ! empty( $control_number ) ) {
			echo '<p><strong>' . esc_html__( 'Control number', 'cachicamoapp-for-woo' ) . ':</strong> ' . esc_html( $control_number ) . '</p>';
		}
		if ( ! empty( $invoice_uuid ) ) {
			echo '<p><a href="' . esc_url( Pdf::url_for( $order ) ) . '" target="_blank">' . esc_html__( 'View PDF', 'cachicamoapp-for-woo' ) . '</a></p>';
		}
		if ( ! empty( $error ) ) {
			echo '<p style="color:#a00;"><strong>' . esc_html__( 'Last error', 'cachicamoapp-for-woo' ) . ':</strong> ' . esc_html( $error ) . '</p>';
		}

		if ( 'external_order' === \Cachicamo\WooCommerce\Settings\Repository::get( 'billing_mode', '' ) ) {
			$resend_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=' . ExternalOrder::RESEND_ACTION . '&order_id=' . $order->get_id() ),
				ExternalOrder::RESEND_ACTION . '_' . $order->get_id()
			);
			echo '<p><a class="button" href="' . esc_url( $resend_url ) . '">' . esc_html__( 'Resend to Cachicamo', 'cachicamoapp-for-woo' ) . '</a></p>';
			return;
		}

		$retry_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::RETRY_ACTION . '&order_id=' . $order->get_id() ),
			self::RETRY_ACTION . '_' . $order->get_id()
		);
		echo '<p><a class="button" href="' . esc_url( $retry_url ) . '">' . esc_html__( 'Retry invoice', 'cachicamoapp-for-woo' ) . '</a></p>';
	}

	public static function handle_retry() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'cachicamoapp-for-woo' ) );
		}

		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		check_admin_referer( self::RETRY_ACTION . '_' . $order_id );

		if ( function_exists( 'as_enqueue_async_action' ) && $order_id > 0 ) {
			as_enqueue_async_action( 'cachicamoapp_issue_invoice', array( $order_id ), 'cachicamoapp' );
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=shop_order' ) );
		exit;
	}
}
