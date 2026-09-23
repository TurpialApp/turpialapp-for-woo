<?php

namespace Cachicamo\WooCommerce\Billing;

defined( 'ABSPATH' ) || exit;

/**
 * Pure arithmetic that closes the gap between the order's own total and what the core's
 * preview priced for the same lines, so the stored document settles for the exact amount the
 * order collected. No WordPress or HTTP dependency: every input is a plain number so it can be
 * fixed against the core's worked examples without a running store.
 */
class Reconciler {

	const TOLERANCE = 0.01;

	/**
	 * Converts an amount from the order's currency into the document's, using the same
	 * unit = origin * (rate_doc / rate_line) formula the core applies per line
	 * (currency/rules/convert.go:50-54), rounded to 2 decimals. Equal currencies skip the
	 * conversion entirely so a same-currency order never picks up a rounding difference.
	 */
	public static function convert_target( $amount, $order_currency_iso, $document_currency_iso, $rate_order, $rate_document ) {
		if ( $order_currency_iso === $document_currency_iso ) {
			return round( (float) $amount, 2 );
		}
		return round( (float) $amount * ( (float) $rate_document / (float) $rate_order ), 2 );
	}

	/**
	 * @param float $target        Total the order collected, in the document's currency.
	 * @param float $preview_total Total the first preview priced for the same lines.
	 * @param float $taxable_base  Sum of taxed line totals from the preview, before tax.
	 * @param float $tax_total     Sum of tax from the preview.
	 * @return array{action:string,amount:float}
	 *         action = "none" | "adjustment_line" | "global_discount".
	 *         amount is the exempt free-line amount, or the global_discount_amount to send,
	 *         never both, and absent for "none".
	 */
	public static function reconcile( $target, $preview_total, $taxable_base, $tax_total ) {
		$diff = round( (float) $target - (float) $preview_total, 2 );

		if ( abs( $diff ) < self::TOLERANCE ) {
			return array( 'action' => 'none' );
		}

		if ( $diff > 0 ) {
			return array(
				'action' => 'adjustment_line',
				'amount' => $diff,
			);
		}

		$excess = -$diff;
		$rate   = $taxable_base > 0 ? ( (float) $tax_total / (float) $taxable_base ) : 0.0;

		return array(
			'action' => 'global_discount',
			'amount' => round( $excess / ( 1 + $rate ), 2 ),
		);
	}
}
