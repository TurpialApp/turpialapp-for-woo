<?php

namespace Cachicamo\WooCommerce\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Forces the store options the core's rounding order depends on, and prices catalog and cart
 * lines with Calculator so the amount the storefront shows matches the amount the core will
 * invoice. IGTF is never a line item here: it is a checkout fee, added only once the buyer
 * picks a payment method carrying it.
 */
class CartHooks {

	public static function register_hooks() {
		add_action( 'cachicamoapp_settings_saved', array( __CLASS__, 'force_store_options' ) );
		add_action( 'cachicamoapp_account_connected', array( __CLASS__, 'force_store_options' ) );

		add_filter( 'woocommerce_product_get_price', array( __CLASS__, 'convert_catalog_price' ), 10, 2 );
		add_filter( 'woocommerce_product_get_regular_price', array( __CLASS__, 'convert_catalog_price' ), 10, 2 );
		add_filter( 'woocommerce_product_variation_get_price', array( __CLASS__, 'convert_catalog_price' ), 10, 2 );

		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'apply_line_prices' ), 20 );
	}

	/**
	 * woocommerce_calc_taxes stays off and prices keep carrying IVA inline (Decision F7-4a of
	 * Investigacion/02_IGTF_DECIMALES_TASA.md): only the rounding order and the unit price
	 * decimals are forced to match the core.
	 */
	public static function force_store_options() {
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'no' );
		update_option( 'woocommerce_tax_round_at_subtotal', 'yes' );
		update_option( 'woocommerce_price_num_decimals', self::unit_price_decimals() );
	}

	public static function unit_price_decimals() {
		return (int) get_option( 'cachicamoapp_unit_price_decimals', 2 );
	}

	public static function convert_catalog_price( $price, $product ) {
		if ( '' === $price || null === $price ) {
			return $price;
		}

		$source_currency = $product->get_meta( '_cachicamo_price_source_currency', true );
		$store_currency   = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';

		if ( '' === $source_currency || $source_currency === $store_currency ) {
			return $price;
		}

		$rates = Rates::all();
		if ( ! isset( $rates[ $source_currency ] ) || ! isset( $rates[ $store_currency ] ) ) {
			return $price;
		}

		$unit = (float) $price * ( $rates[ $store_currency ] / $rates[ $source_currency ] );
		return round( $unit, self::unit_price_decimals() );
	}

	public static function apply_line_prices( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'];
			$price   = self::convert_catalog_price( $product->get_price( 'edit' ), $product );
			$product->set_price( $price );
		}
	}
}
