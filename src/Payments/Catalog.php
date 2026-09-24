<?php

namespace Cachicamo\WooCommerce\Payments;

use Cachicamo\WooCommerce\Api\Routes;
use Cachicamo\WooCommerce\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Mirrors GET /payment_methods into the cachicamoapp_payment_catalog transient (48 h),
 * the shape PaymentTaxes reads to resolve a method's IGTF rate. Only the scheduled task and a
 * settings save may call refresh(): the storefront checkout only ever reads get(), which fails
 * open with an empty catalog so a stale or missing cache never blocks a sale.
 */
class Catalog {

	const TRANSIENT_NAME = 'cachicamoapp_payment_catalog';
	const TRANSIENT_TTL  = 48 * HOUR_IN_SECONDS;

	public static function refresh() {
		$client = Plugin::instance()->service( 'api_client' );
		if ( null === $client ) {
			return false;
		}

		$result = $client->request( 'GET', Routes::payment_methods() );
		if ( ! $result['ok'] ) {
			return false;
		}

		$rows = isset( $result['body']['rows'] ) && is_array( $result['body']['rows'] )
			? $result['body']['rows']
			: ( is_array( $result['body'] ) ? $result['body'] : array() );

		set_transient( self::TRANSIENT_NAME, array( 'rows' => array_values( $rows ) ), self::TRANSIENT_TTL );

		return true;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function get() {
		$stored = get_transient( self::TRANSIENT_NAME );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		if ( isset( $stored['rows'] ) && is_array( $stored['rows'] ) ) {
			return $stored['rows'];
		}

		return array();
	}
}
