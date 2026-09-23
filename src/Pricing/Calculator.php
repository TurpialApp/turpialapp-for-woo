<?php

namespace Cachicamo\WooCommerce\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Literal replica, in PHP, of the core's document pricing algorithm: converts and rounds each
 * unit price, totals each line on the already-rounded unit, groups tax by tax_uuid (never by
 * rate) and rounds each group to 2 decimals, then applies IGTF on the payments against the
 * pending balance, IGTF-bearing methods first. It is the only pricing implementation in the
 * plugin; the cart, the order metabox and direct invoicing all call it.
 *
 * All amounts operate in an integer scale of x10000, mirroring the core's int64 storage, so the
 * same float rounding boundary (e.g. .49995 vs .5) can never diverge between PHP and Go.
 */
class Calculator {

	const SCALE = 10000;

	/**
	 * @param array<int,array{unit_net:float,currency_iso:string,qty:float,tax_uuid:string,tax_rate:float}> $lines
	 * @param array<string,float> $rates ISO 4217 -> units of that currency per 1 USD; USD => 1.0.
	 * @param string $document_currency_iso
	 * @param int    $unit_price_decimals 2..8.
	 * @param array<int,array{amount:float,currency_iso:string,igtf_rate:float,igtf_tax_uuid:string}> $payments
	 *        igtf_rate is 0 when the payment method carries no IGTF tax.
	 * @param bool   $customer_igtf_exempt
	 * @return array{
	 *   lines: array<int,array{unit:float,line_total:float,tax_uuid:string,tax_amount:float}>,
	 *   tax_groups: array<string,float>,
	 *   total_products: float,
	 *   total_taxes: float,
	 *   total: float,
	 * }
	 */
	public static function calculate(
		array $lines,
		array $rates,
		$document_currency_iso,
		$unit_price_decimals,
		array $payments = array(),
		$customer_igtf_exempt = false
	) {
		$rate_doc = self::rate_for( $rates, $document_currency_iso );

		$total_products_scaled = 0;
		$tax_groups_scaled     = array();
		$calculated_lines      = array();

		foreach ( $lines as $line ) {
			$rate_line = self::rate_for( $rates, $line['currency_iso'] );

			$unit = (float) $line['unit_net'] * ( $rate_doc / $rate_line );
			$unit = round( $unit, $unit_price_decimals );

			$line_total_scaled = self::round_scaled( $unit * (float) $line['qty'], 4 );
			$total_products_scaled += $line_total_scaled;

			$tax_scaled = self::round_scaled( ( $line_total_scaled / self::SCALE ) * (float) $line['tax_rate'] / 100.0, 4 );
			$tax_uuid   = $line['tax_uuid'];
			$tax_groups_scaled[ $tax_uuid ] = self::round_scaled(
				( isset( $tax_groups_scaled[ $tax_uuid ] ) ? $tax_groups_scaled[ $tax_uuid ] / self::SCALE : 0.0 )
					+ ( $tax_scaled / self::SCALE ),
				4
			);

			$calculated_lines[] = array(
				'unit'        => $unit,
				'line_total'  => $line_total_scaled / self::SCALE,
				'tax_uuid'    => $tax_uuid,
				'tax_amount'  => $tax_scaled / self::SCALE,
			);
		}

		$rest_to_pay_scaled = $total_products_scaled;
		foreach ( $tax_groups_scaled as $group_scaled ) {
			$rest_to_pay_scaled += self::round_scaled( $group_scaled / self::SCALE, 2 );
		}

		$ordered_payments = self::order_payments( $payments, $rates, $rate_doc );

		foreach ( $ordered_payments as $payment ) {
			$rate_payment = self::rate_for( $rates, $payment['currency_iso'] );
			$amount_doc   = (float) $payment['amount'] * ( $rate_doc / $rate_payment );

			if ( $amount_doc < 0 ) {
				continue;
			}

			$base = $amount_doc;
			if ( $rest_to_pay_scaled > 0 ) {
				$rest_to_pay_scaled -= self::round_scaled( $base, 4 );
			}
			if ( $rest_to_pay_scaled < 0 ) {
				$base += $rest_to_pay_scaled / self::SCALE;
				$rest_to_pay_scaled = 0;
			}

			if ( empty( $payment['igtf_rate'] ) || $payment['igtf_rate'] <= 0 || $base <= 0 ) {
				continue;
			}

			$rate  = $customer_igtf_exempt ? 0.0 : (float) $payment['igtf_rate'];
			$igtf_scaled = self::round_scaled( $base * $rate / 100.0, 4 );

			$igtf_tax_uuid = $payment['igtf_tax_uuid'];
			$tax_groups_scaled[ $igtf_tax_uuid ] = self::round_scaled(
				( isset( $tax_groups_scaled[ $igtf_tax_uuid ] ) ? $tax_groups_scaled[ $igtf_tax_uuid ] / self::SCALE : 0.0 )
					+ ( $igtf_scaled / self::SCALE ),
				4
			);

			$rest_to_pay_scaled += $igtf_scaled;
		}

		$total_taxes_scaled = 0;
		$tax_groups = array();
		foreach ( $tax_groups_scaled as $tax_uuid => $group_scaled ) {
			$group_rounded_2 = self::round_scaled( $group_scaled / self::SCALE, 2 );
			$tax_groups[ $tax_uuid ] = $group_rounded_2 / self::SCALE;
			$total_taxes_scaled += $group_rounded_2;
		}

		$total_products = $total_products_scaled / self::SCALE;
		$total_taxes    = $total_taxes_scaled / self::SCALE;

		return array(
			'lines'          => $calculated_lines,
			'tax_groups'     => $tax_groups,
			'total_products' => $total_products,
			'total_taxes'    => $total_taxes,
			'total'          => $total_products + $total_taxes,
		);
	}

	/**
	 * IGTF-bearing methods first, then by document-currency amount descending, stable.
	 * Reversing this order under-collects the IGTF base (igtf.go:40-56).
	 */
	private static function order_payments( array $payments, array $rates, $rate_doc ) {
		$indexed = array();
		foreach ( $payments as $index => $payment ) {
			$rate_payment = self::rate_for( $rates, $payment['currency_iso'] );
			$indexed[]    = array(
				'payment'    => $payment,
				'has_igtf'   => ! empty( $payment['igtf_rate'] ) && $payment['igtf_rate'] > 0,
				'amount_doc' => (float) $payment['amount'] * ( $rate_doc / $rate_payment ),
				'index'      => $index,
			);
		}

		usort(
			$indexed,
			function ( $a, $b ) {
				if ( $a['has_igtf'] !== $b['has_igtf'] ) {
					return $a['has_igtf'] ? -1 : 1;
				}
				if ( $a['amount_doc'] === $b['amount_doc'] ) {
					return $a['index'] <=> $b['index'];
				}
				return $a['amount_doc'] > $b['amount_doc'] ? -1 : 1;
			}
		);

		return array_map(
			function ( $entry ) {
				return $entry['payment'];
			},
			$indexed
		);
	}

	private static function rate_for( array $rates, $iso ) {
		return isset( $rates[ $iso ] ) ? (float) $rates[ $iso ] : 1.0;
	}

	/**
	 * Rounds $amount (in real units) to $decimals and returns it in the internal x10000 scale,
	 * as an integer. Half-away-from-zero, same as PHP's own round() and Go's math.Round.
	 */
	private static function round_scaled( $amount, $decimals ) {
		$rounded = round( $amount, $decimals );
		return (int) round( $rounded * self::SCALE );
	}
}
