<?php

namespace Cachicamo\WooCommerce\Pricing;

use Cachicamo\WooCommerce\Api\Routes;
use Cachicamo\WooCommerce\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Caches GET /currencies/rates in the cachicamoapp_rates option, refreshed by the
 * cachicamoapp_refresh_rates scheduled task every 600 seconds. Never fetched inline on a
 * visitor request: a storefront page never waits on the core.
 */
class Rates {

	const OPTION_NAME = 'cachicamoapp_rates';
	const TTL         = 600;

	public static function refresh() {
		$client = Plugin::instance()->service( 'api_client' );
		if ( null === $client ) {
			return false;
		}

		$result = $client->request( 'GET', Routes::currencies_rates() );
		if ( ! $result['ok'] ) {
			return false;
		}

		$system = isset( $result['body']['system_rates'] ) && is_array( $result['body']['system_rates'] )
			? $result['body']['system_rates']
			: array();
		$user   = isset( $result['body']['user_rates'] ) && is_array( $result['body']['user_rates'] )
			? $result['body']['user_rates']
			: array();

		$effective = array();
		foreach ( $system as $iso => $rate ) {
			$effective[ $iso ] = self::rate_value( $rate );
		}
		foreach ( $user as $iso => $rate ) {
			$effective[ $iso ] = self::rate_value( $rate );
		}
		if ( ! isset( $effective['USD'] ) ) {
			$effective['USD'] = 1.0;
		}

		update_option(
			self::OPTION_NAME,
			array(
				'rates'      => $effective,
				'fetched_at' => time(),
			)
		);

		return true;
	}

	private static function rate_value( $rate ) {
		if ( is_array( $rate ) && isset( $rate['rate'] ) ) {
			return (float) $rate['rate'];
		}
		return (float) $rate;
	}

	/**
	 * @return array<string,float> ISO 4217 -> units of that currency per 1 USD, USD always 1.0.
	 */
	public static function all() {
		$stored = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $stored ) || empty( $stored['rates'] ) ) {
			return array( 'USD' => 1.0 );
		}
		return $stored['rates'];
	}

	public static function get( $iso ) {
		$rates = self::all();
		return isset( $rates[ $iso ] ) ? (float) $rates[ $iso ] : null;
	}

	public static function fetched_at() {
		$stored = get_option( self::OPTION_NAME, array() );
		return is_array( $stored ) && isset( $stored['fetched_at'] ) ? (int) $stored['fetched_at'] : null;
	}

	public static function is_stale() {
		$fetched_at = self::fetched_at();
		if ( null === $fetched_at ) {
			return true;
		}
		return ( time() - $fetched_at ) > self::TTL;
	}
}
