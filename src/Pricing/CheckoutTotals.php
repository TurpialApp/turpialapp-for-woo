<?php

namespace Cachicamo\WooCommerce\Pricing;

use Cachicamo\WooCommerce\Settings\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Charges IGTF as a checkout fee on the currently chosen payment method, then forces the
 * cart's product tax breakdown and grand total to the same figures Calculator would invoice,
 * so the storefront never quotes an amount the core would round differently. IGTF itself is
 * never folded into the product tax groups here: it stays a plain WC_Cart fee (untaxed), which
 * WooCommerce already adds into cart_contents_total/total on its own before this class's
 * woocommerce_calculated_total filter runs.
 */
class CheckoutTotals {

	const FEE_ID   = 'cachicamo-igtf';
	const FEE_NAME = 'IGTF';

	public static function register_hooks() {
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'add_igtf_fee' ) );
		add_filter( 'woocommerce_checkout_create_order_fee_item', array( __CLASS__, 'tag_igtf_fee_item' ), 10, 2 );

		add_filter( 'woocommerce_calculated_total', array( __CLASS__, 'override_total' ), 10, 2 );
		add_action( 'woocommerce_after_calculate_totals', array( __CLASS__, 'override_product_taxes' ), 20 );
	}

	public static function add_igtf_fee( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		$igtf = self::igtf_for_chosen_method();
		if ( null === $igtf || $igtf['igtf_rate'] <= 0 ) {
			return;
		}

		$base   = (float) $cart->get_subtotal() + (float) $cart->get_subtotal_tax();
		$amount = round( $base * $igtf['igtf_rate'] / 100.0, 2 );
		if ( $amount <= 0 ) {
			return;
		}

		$cart->add_fee( self::FEE_NAME, $amount, false );
	}

	public static function tag_igtf_fee_item( $item, $fee ) {
		if ( self::FEE_NAME === $fee->name ) {
			$item->add_meta_data( '_cachicamo_charge', 'igtf' );
		}
		return $item;
	}

	/**
	 * @return array{amount:float,currency_iso:string,igtf_rate:float,igtf_tax_uuid:string}|null
	 */
	public static function igtf_for_chosen_method() {
		$gateway_id = function_exists( 'WC' ) && WC()->session ? WC()->session->get( 'chosen_payment_method' ) : '';
		if ( '' === (string) $gateway_id ) {
			return null;
		}

		$mapping = Repository::get( 'payment_mapping', array() );
		if ( ! isset( $mapping[ $gateway_id ] ) || '' === $mapping[ $gateway_id ] ) {
			return null;
		}

		return array_merge(
			array( 'payment_method_uuid' => $mapping[ $gateway_id ] ),
			PaymentTaxes::for_payment_method( $mapping[ $gateway_id ] )
		);
	}

	public static function override_total( $total, $cart ) {
		$calculated = self::calculate_products_and_taxes( $cart );
		if ( null === $calculated ) {
			return $total;
		}
		return round( $calculated['total_products'] + $calculated['total_taxes'] + (float) $cart->get_fee_total() + (float) $cart->get_fee_tax(), wc_get_price_decimals() );
	}

	public static function override_product_taxes( $cart ) {
		$calculated = self::calculate_products_and_taxes( $cart );
		if ( null === $calculated ) {
			return;
		}

		$rate_amounts   = array();
		$total_tax      = 0.0;
		foreach ( $calculated['tax_groups'] as $tax_uuid => $amount ) {
			$rate_id = self::rate_id_for_tax_uuid( $tax_uuid );
			if ( null === $rate_id ) {
				continue;
			}
			$rate_amounts[ $rate_id ] = ( isset( $rate_amounts[ $rate_id ] ) ? $rate_amounts[ $rate_id ] : 0 ) + $amount;
			$total_tax               += $amount;
		}

		$cart->set_cart_contents_taxes( $rate_amounts );
		$cart->set_cart_contents_tax( $total_tax );
		$cart->set_total_tax( $total_tax + (float) $cart->get_fee_tax() + (float) $cart->get_shipping_tax() );
	}

	/**
	 * @return array{total_products:float,total_taxes:float,tax_groups:array<string,float>}|null
	 */
	private static function calculate_products_and_taxes( $cart ) {
		$lines = array();
		foreach ( $cart->get_cart() as $cart_item ) {
			$product  = $cart_item['data'];
			$tax_uuid = $product->get_meta( TaxCatalog::META_TAX_UUID, true );
			$tax      = '' !== $tax_uuid ? TaxCatalog::get( $tax_uuid ) : null;

			$lines[] = array(
				'unit_net'     => (float) $product->get_price( 'edit' ),
				'currency_iso' => self::store_currency(),
				'qty'          => (float) $cart_item['quantity'],
				'tax_uuid'     => null !== $tax ? $tax['uuid'] : (string) $tax_uuid,
				'tax_rate'     => null !== $tax ? (float) $tax['tax_rate'] : 0.0,
			);
		}

		if ( empty( $lines ) ) {
			return null;
		}

		return Calculator::calculate(
			$lines,
			Rates::all(),
			self::store_currency(),
			CartHooks::unit_price_decimals()
		);
	}

	private static function rate_id_for_tax_uuid( $tax_uuid ) {
		if ( ! class_exists( '\\WC_Tax' ) ) {
			return null;
		}

		$tax = TaxCatalog::get( $tax_uuid );
		if ( null === $tax || 'IVA' !== $tax['tax_family'] ) {
			return null;
		}

		$rates = \WC_Tax::get_rates_for_tax_class( TaxCatalog::tax_class_name( $tax ) );
		if ( empty( $rates ) ) {
			return null;
		}

		return array_key_first( $rates );
	}

	private static function store_currency() {
		return function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';
	}
}
