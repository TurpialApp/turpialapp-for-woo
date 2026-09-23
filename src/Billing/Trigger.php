<?php

namespace Cachicamo\WooCommerce\Billing;

use Cachicamo\WooCommerce\Account\Status;
use Cachicamo\WooCommerce\Settings\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether an order status transition should schedule a Cachicamo invoice, and runs the
 * issuance once Action Scheduler calls back. An order already invoiced, or one whose invoice
 * attempt is in a hard error state, never fires again on its own: only the metabox's manual
 * retry reschedules it.
 */
class Trigger {

	public static function register_hooks() {
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 10, 4 );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'on_refund' ), 10, 2 );
		add_action( 'woocommerce_order_partially_refunded', array( __CLASS__, 'on_refund' ), 10, 2 );
	}

	public static function on_status_changed( $order_id, $status_from, $status_to, $order = null ) {
		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( 'direct' !== Repository::get( 'billing_mode', 'external_order' ) ) {
			return;
		}

		$trigger_statuses = Repository::get( 'trigger_statuses', array() );
		if ( ! in_array( 'wc-' . $status_to, $trigger_statuses, true ) ) {
			return;
		}

		$order_number = (int) $order->get_order_number();
		if ( $order_number <= (int) Repository::get( 'start_order_number', 0 ) ) {
			return;
		}

		if ( ! empty( $order->get_meta( '_cachicamo_invoice_uuid' ) ) ) {
			return;
		}
		if ( 'error' === $order->get_meta( '_cachicamo_state' ) ) {
			return;
		}
		if ( Status::is_expired() ) {
			return;
		}
		if ( (float) $order->get_total() <= 0 ) {
			DirectInvoicer::issue( $order );
			return;
		}

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'cachicamoapp_issue_invoice', array( $order_id ), 'cachicamoapp' );
		}
	}

	public static function issue( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		DirectInvoicer::issue( $order );
	}

	/**
	 * A refund on an already-issued document never calls the core: a credit note is a fiscal act
	 * that must be reviewed before it is issued, never generated silently from a WooCommerce
	 * refund event.
	 */
	public static function on_refund( $order_id, $refund_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		if ( empty( $order->get_meta( '_cachicamo_invoice_uuid' ) ) ) {
			return;
		}
		$order->add_order_note(
			__( 'Issue the credit note in cachicamo.app.', 'cachicamoapp-for-woo' )
		);
	}
}
