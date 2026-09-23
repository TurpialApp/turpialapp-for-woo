<?php

namespace Cachicamo\WooCommerce\Checkout;

use Cachicamo\WooCommerce\Pricing\Rates;
use Cachicamo\WooCommerce\Settings\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Stamps the order, classic and blocks checkout alike, with what the core needs to price and
 * relate the payment to its document: the exchange rate used, the mapped Cachicamo payment
 * method and the amount the buyer owes in that method's own currency. Runs once per order, on
 * whichever of the two "order created" hooks the active checkout fires, always through
 * update_meta_data() + save() so it works the same under HPOS.
 */
class OrderMeta {

	const META_RATE_UUID       = '_cachicamo_rate_uuid';
	const META_RATE_DATE       = '_cachicamo_rate_date';
	const META_PAYMENT_METHOD  = '_cachicamo_payment_method_uuid';
	const META_PAYMENT_AMOUNT  = '_cachicamo_payment_amount';

	public static function register_hooks() {
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'save' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'save' ) );
	}

	public static function save( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$store_currency = $order->get_currency();
		$store_rate     = Rates::get( $store_currency );
		$store_meta     = Rates::meta( $store_currency );

		if ( null !== $store_meta && '' !== $store_meta['uuid'] ) {
			$order->update_meta_data( self::META_RATE_UUID, $store_meta['uuid'] );
			$order->update_meta_data( self::META_RATE_DATE, self::to_date( $store_meta['created_at'] ) );
		}

		$mapping    = Repository::get( 'payment_mapping', array() );
		$gateway_id = $order->get_payment_method();
		$method_uuid = isset( $mapping[ $gateway_id ] ) ? (string) $mapping[ $gateway_id ] : '';

		if ( '' !== $method_uuid ) {
			$method_taxes = \Cachicamo\WooCommerce\Pricing\PaymentTaxes::for_payment_method( $method_uuid );
			$method_rate  = '' !== $method_taxes['currency_iso'] ? Rates::get( $method_taxes['currency_iso'] ) : null;

			if ( null !== $store_rate && null !== $method_rate && $method_rate > 0 ) {
				$amount_in_method_currency = (float) $order->get_total() * ( $method_rate / $store_rate );
				$order->update_meta_data( self::META_PAYMENT_METHOD, $method_uuid );
				$order->update_meta_data( self::META_PAYMENT_AMOUNT, (int) round( $amount_in_method_currency * 10000 ) );
			}
		}

		$order->save();
	}

	private static function to_date( $datetime ) {
		if ( '' === $datetime ) {
			return gmdate( 'Y-m-d' );
		}
		$timestamp = strtotime( $datetime );
		return false !== $timestamp ? gmdate( 'Y-m-d', $timestamp ) : gmdate( 'Y-m-d' );
	}
}
