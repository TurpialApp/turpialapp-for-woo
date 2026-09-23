<?php

namespace Cachicamo\WooCommerce\Pricing;

use Cachicamo\WooCommerce\Api\Routes;
use Cachicamo\WooCommerce\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Mirrors GET /taxes into the cachicamoapp_tax_catalog option and into a matching WooCommerce
 * tax class per Cachicamo IVA tax, keyed in postmeta by the tax_uuid so a line item can be
 * traced back to the account's own tax.
 */
class TaxCatalog {

	const OPTION_NAME = 'cachicamoapp_tax_catalog';
	const META_TAX_UUID = '_cachicamo_tax_uuid';

	public static function refresh() {
		$client = Plugin::instance()->service( 'api_client' );
		if ( null === $client ) {
			return false;
		}

		$result = $client->request( 'GET', Routes::taxes() );
		if ( ! $result['ok'] ) {
			return false;
		}

		$taxes = isset( $result['body']['data'] ) && is_array( $result['body']['data'] )
			? $result['body']['data']
			: ( is_array( $result['body'] ) ? $result['body'] : array() );

		$by_uuid = array();
		foreach ( $taxes as $tax ) {
			if ( ! isset( $tax['uuid'] ) ) {
				continue;
			}
			$by_uuid[ $tax['uuid'] ] = array(
				'uuid'        => $tax['uuid'],
				'tax_family'  => isset( $tax['tax_family'] ) ? $tax['tax_family'] : '',
				'tax_rate'    => isset( $tax['tax_rate'] ) ? (float) $tax['tax_rate'] : 0.0,
				'name'        => isset( $tax['name'] ) ? $tax['name'] : '',
			);
		}

		update_option(
			self::OPTION_NAME,
			array(
				'taxes'      => $by_uuid,
				'fetched_at' => time(),
			)
		);

		self::sync_woocommerce_tax_classes( $by_uuid );

		return true;
	}

	/**
	 * @return array<string,array{uuid:string,tax_family:string,tax_rate:float,name:string}>
	 */
	public static function all() {
		$stored = get_option( self::OPTION_NAME, array() );
		return is_array( $stored ) && isset( $stored['taxes'] ) && is_array( $stored['taxes'] )
			? $stored['taxes']
			: array();
	}

	public static function get( $tax_uuid ) {
		$taxes = self::all();
		return isset( $taxes[ $tax_uuid ] ) ? $taxes[ $tax_uuid ] : null;
	}

	public static function find_zero_rate_exempt_iva() {
		foreach ( self::all() as $tax ) {
			if ( 'IVA' === $tax['tax_family'] && $tax['tax_rate'] < 0.01 ) {
				return $tax;
			}
		}
		return null;
	}

	/**
	 * One WooCommerce tax class per Cachicamo IVA tax, so woocommerce_tax_round_at_subtotal
	 * groups by the same tax_uuid the core groups by. Non-IVA taxes (IGTF, ISLR, LISAEA,
	 * REGIONAL, OTHER) never become a WooCommerce tax class: they are not product-level.
	 */
	private static function sync_woocommerce_tax_classes( array $taxes ) {
		if ( ! function_exists( 'WC_Tax' ) && ! class_exists( '\\WC_Tax' ) ) {
			return;
		}

		$existing_classes = array_map( 'strtolower', \WC_Tax::get_tax_class_slugs() );

		foreach ( $taxes as $tax ) {
			if ( 'IVA' !== $tax['tax_family'] ) {
				continue;
			}

			$class_name = self::tax_class_name( $tax );
			$slug       = sanitize_title( $class_name );
			if ( in_array( $slug, $existing_classes, true ) ) {
				continue;
			}

			\WC_Tax::create_tax_class( $class_name );

			$rates = \WC_Tax::get_rates_for_tax_class( $class_name );
			if ( empty( $rates ) ) {
				\WC_Tax::_insert_tax_rate(
					array(
						'tax_rate_country'  => '',
						'tax_rate_state'    => '',
						'tax_rate'          => (string) $tax['tax_rate'],
						'tax_rate_name'     => $tax['name'],
						'tax_rate_priority' => 1,
						'tax_rate_compound' => 0,
						'tax_rate_shipping' => 1,
						'tax_rate_order'    => 0,
						'tax_rate_class'    => $class_name,
					)
				);
			}
		}
	}

	public static function tax_class_name( array $tax ) {
		return 'Cachicamo ' . $tax['name'] . ' (' . substr( $tax['uuid'], 0, 8 ) . ')';
	}
}
