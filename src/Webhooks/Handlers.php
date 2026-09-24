<?php

namespace Cachicamo\WooCommerce\Webhooks;

defined( 'ABSPATH' ) || exit;

/**
 * Built-in Dispatcher listeners that don't belong to Catalog or Billing on their own. Other
 * modules register their own prefixes with Dispatcher::on() instead of adding cases here.
 */
class Handlers {

	public static function register() {
		Dispatcher::on( 'payment_method', array( __CLASS__, 'handle_payment_method' ) );
		Dispatcher::on( 'document', array( __CLASS__, 'handle_document' ) );
	}

	public static function handle_payment_method( $action, array $payload ) {
		delete_transient( 'cachicamoapp_payment_catalog' );

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'cachicamoapp_refresh_payment_catalog', array(), \Cachicamo\WooCommerce\Jobs\Scheduler::GROUP );
		}
	}

	/**
	 * document.updated carries the invoice's control number once the fiscal printer assigns
	 * one; the order was linked to the invoice UUID when it was billed, so the lookup goes by
	 * that meta rather than by order ID.
	 */
	public static function handle_document( $action, array $payload ) {
		if ( 'document.updated' !== $action ) {
			return;
		}

		$invoice_uuid = isset( $payload['uuid'] ) ? (string) $payload['uuid'] : '';
		$control_number = isset( $payload['control_number'] ) ? (string) $payload['control_number'] : '';

		if ( '' === $invoice_uuid || '' === $control_number ) {
			return;
		}

		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- point lookup on a single unique meta key, capped to one result.
				'meta_key'   => '_cachicamo_invoice_uuid',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- value of the same unique meta key above.
				'meta_value' => $invoice_uuid,
				'return'     => 'ids',
			)
		);

		if ( empty( $orders ) ) {
			return;
		}

		$order = wc_get_order( $orders[0] );
		if ( ! $order ) {
			return;
		}

		$order->update_meta_data( '_cachicamo_control_number', $control_number );
		$order->save();
	}
}
