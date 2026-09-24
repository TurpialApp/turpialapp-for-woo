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

		if ( 'accredited' === $status ) {
			if ( ! $order->is_paid() ) {
				$order->payment_complete();
			}
		} elseif ( 'rejected' === $status ) {
			$order->update_status( 'failed' );
		}
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
