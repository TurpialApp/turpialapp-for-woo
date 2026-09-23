<?php

namespace Cachicamo\WooCommerce\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the IGTF tax carried by a Cachicamo payment method, from the payment method catalog
 * cached in the cachicamoapp_payment_catalog transient (shape: GET /payment_methods response)
 * cross-referenced against the IVA/IGTF catalog in TaxCatalog. A payment method absent from the
 * transient, or with no tax of family IGTF, carries igtf_rate 0: the storefront must never block
 * on a missing cache, only skip the charge.
 */
class PaymentTaxes {

	const TRANSIENT_NAME = 'cachicamoapp_payment_catalog';

	/**
	 * @param string $payment_method_uuid
	 * @return array{igtf_rate:float,igtf_tax_uuid:string,currency_iso:string}
	 */
	public static function for_payment_method( $payment_method_uuid ) {
		$empty = array(
			'igtf_rate'     => 0.0,
			'igtf_tax_uuid' => '',
			'currency_iso'  => '',
		);

		if ( '' === (string) $payment_method_uuid ) {
			return $empty;
		}

		$method = self::find_method( $payment_method_uuid );
		if ( null === $method ) {
			return $empty;
		}

		$currency_iso = isset( $method['currency']['iso'] ) ? (string) $method['currency']['iso'] : '';

		$taxes = isset( $method['taxes'] ) && is_array( $method['taxes'] ) ? $method['taxes'] : array();
		foreach ( $taxes as $tax_ref ) {
			$tax_uuid = isset( $tax_ref['tax_uuid'] ) ? (string) $tax_ref['tax_uuid'] : '';
			if ( '' === $tax_uuid ) {
				continue;
			}

			$catalog_tax = TaxCatalog::get( $tax_uuid );
			if ( null !== $catalog_tax && 'IGTF' === $catalog_tax['tax_family'] ) {
				return array(
					'igtf_rate'     => (float) $catalog_tax['tax_rate'],
					'igtf_tax_uuid' => $tax_uuid,
					'currency_iso'  => $currency_iso,
				);
			}
		}

		return array(
			'igtf_rate'     => 0.0,
			'igtf_tax_uuid' => '',
			'currency_iso'  => $currency_iso,
		);
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private static function find_method( $payment_method_uuid ) {
		$rows = self::catalog_rows();
		foreach ( $rows as $row ) {
			if ( isset( $row['uuid'] ) && (string) $row['uuid'] === (string) $payment_method_uuid ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private static function catalog_rows() {
		$stored = get_transient( self::TRANSIENT_NAME );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		if ( isset( $stored['rows'] ) && is_array( $stored['rows'] ) ) {
			return $stored['rows'];
		}
		if ( isset( $stored['data'] ) && is_array( $stored['data'] ) ) {
			return $stored['data'];
		}

		return array_values( $stored );
	}
}
