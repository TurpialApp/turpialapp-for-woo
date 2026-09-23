<?php

namespace Cachicamo\WooCommerce\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Writes Cachicamo's billable stock onto the linked WooCommerce product or variation. Publishes
 * `quantity_billable` / `stock_billable` only (plan 4.1); never the raw on-hand quantity.
 */
class Stock {

	/**
	 * @param int   $wc_id
	 * @param float $billable
	 */
	public static function apply( $wc_id, $billable ) {
		$product = wc_get_product( $wc_id );
		if ( ! $product ) {
			return false;
		}

		$product->set_manage_stock( true );
		$product->set_stock_quantity( self::floor_non_negative( $billable ) );
		$product->set_stock_status( $billable > 0 ? 'instock' : 'outofstock' );
		$product->save();

		Links::set_stock_billable( $wc_id, $billable );

		return true;
	}

	/**
	 * A stock event's billable field can be named `quantity_billable` or `stock_billable`
	 * depending on the endpoint that produced it; both mean the same thing (plan 4.1).
	 *
	 * @param array<string,mixed> $payload
	 * @return float|null
	 */
	public static function billable_from_payload( array $payload ) {
		if ( array_key_exists( 'quantity_billable', $payload ) ) {
			return (float) $payload['quantity_billable'];
		}
		if ( array_key_exists( 'stock_billable', $payload ) ) {
			return (float) $payload['stock_billable'];
		}
		return null;
	}

	private static function floor_non_negative( $value ) {
		return max( 0, (int) floor( (float) $value ) );
	}
}
