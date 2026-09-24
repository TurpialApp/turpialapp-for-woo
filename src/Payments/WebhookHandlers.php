<?php

namespace Cachicamo\WooCommerce\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * async_payment.updated confirms a gateway payment WooCommerce left on-hold in
 * AbstractCachicamoGateway::process_payment(). The order is found from order_reference
 * (`{store_uuid}:{order_id}`, C9) rather than from any Cachicamo uuid, so it works the same
 * whether the payment was created by this plugin or elsewhere.
 */
class WebhookHandlers {

	public static function register() {
		\Cachicamo\WooCommerce\Webhooks\Dispatcher::on( 'async_payment', array( __CLASS__, 'handle' ) );
	}

	public static function handle( $action, array $payload ) {
		if ( 'async_payment.updated' !== $action ) {
			return;
		}

		$status = isset( $payload['status'] ) ? (string) $payload['status'] : '';
		$order  = self::order_from_reference( isset( $payload['order_reference'] ) ? (string) $payload['order_reference'] : '' );

		if ( null === $order ) {
			return;
		}

		$transition = self::transition_for_status( $status );
		if ( self::TRANSITION_COMPLETE === $transition ) {
			if ( ! $order->is_paid() ) {
				$order->payment_complete();
			}
		} elseif ( self::TRANSITION_FAILED === $transition ) {
			$order->update_status( 'failed' );
		}
	}

	const TRANSITION_COMPLETE = 'complete';
	const TRANSITION_FAILED   = 'failed';

	/**
	 * Pure mapping from an async_payment status to the order transition it drives, kept apart
	 * from handle() so it is testable without a \WC_Order.
	 *
	 * @return string|null One of the TRANSITION_* constants, or null for a status that leaves
	 * the order untouched (still pending, or any status not yet handled).
	 */
	public static function transition_for_status( $status ) {
		if ( 'accredited' === $status ) {
			return self::TRANSITION_COMPLETE;
		}
		if ( 'rejected' === $status ) {
			return self::TRANSITION_FAILED;
		}
		return null;
	}

	private static function order_from_reference( $order_reference ) {
		if ( '' === $order_reference || false === strpos( $order_reference, ':' ) ) {
			return null;
		}

		list( , $order_id ) = explode( ':', $order_reference, 2 );
		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) {
			return null;
		}

		$order = wc_get_order( $order_id );
		return $order instanceof \WC_Order ? $order : null;
	}
}
