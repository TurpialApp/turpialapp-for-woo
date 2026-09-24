<?php

namespace Cachicamo\WooCommerce\Payments;

use Cachicamo\WooCommerce\Api\Routes;
use Cachicamo\WooCommerce\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Backstop for WebhookHandlers: a buyer who reaches the return page before the core's
 * async_payment.updated notification arrives (or a notification the store never received) still
 * sees the real status, by asking the core directly once when the thank-you page renders.
 */
class ThankyouPoll {

	public static function register_hooks() {
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'maybe_poll' ) );
	}

	public static function maybe_poll( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || $order->is_paid() ) {
			return;
		}

		$async_uuid = (string) $order->get_meta( '_cachicamo_async_payment_uuid' );
		if ( '' === $async_uuid ) {
			return;
		}

		$client = Plugin::instance()->service( 'api_client' );
		if ( null === $client ) {
			return;
		}

		$result = $client->request( 'GET', Routes::async_payments_uuid( $async_uuid ) );
		if ( ! $result['ok'] ) {
			return;
		}

		$status = isset( $result['body']['status'] ) ? (string) $result['body']['status'] : '';
		if ( 'accredited' === $status ) {
			$order->payment_complete();
		} elseif ( 'rejected' === $status ) {
			$order->update_status( 'failed' );
		}
	}
}
