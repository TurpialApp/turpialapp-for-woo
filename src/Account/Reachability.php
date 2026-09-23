<?php

namespace Cachicamo\WooCommerce\Account;

use Cachicamo\WooCommerce\Api\Routes;
use Cachicamo\WooCommerce\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Proves the core can reach this store's own REST endpoint before any sync or billing writes
 * are allowed. The nonce lives in a transient so the GET /events?probe= callback the core
 * makes while handling the POST below can be checked against the same value this request set.
 */
class Reachability {

	const OPTION_NAME    = 'cachicamoapp_reachability';
	const TRANSIENT_NAME = 'cachicamoapp_reachability_nonce';
	const TRANSIENT_TTL  = 300;

	public static function current_nonce() {
		$nonce = get_transient( self::TRANSIENT_NAME );
		if ( ! is_string( $nonce ) || '' === $nonce ) {
			$nonce = self::issue_nonce();
		}
		return $nonce;
	}

	public static function issue_nonce() {
		$nonce = wp_generate_password( 32, false, false );
		set_transient( self::TRANSIENT_NAME, $nonce, self::TRANSIENT_TTL );
		return $nonce;
	}

	/**
	 * @return array{passed:bool,error:string|null}
	 */
	public static function check() {
		$client = Plugin::instance()->service( 'api_client' );
		if ( null === $client ) {
			return array(
				'passed' => false,
				'error'  => 'unavailable',
			);
		}

		$nonce     = self::issue_nonce();
		$probe_url = add_query_arg( 'probe', $nonce, rest_url( 'cachicamoapp/v1/events' ) );

		$result = $client->request(
			'POST',
			Routes::site_reachability(),
			array(
				'probe_url' => $probe_url,
				'nonce'     => $nonce,
			)
		);

		$passed = $result['ok'] && ! empty( $result['body']['reachable'] );

		update_option(
			self::OPTION_NAME,
			array(
				'passed'     => $passed,
				'checked_at' => time(),
			),
			false
		);

		return array(
			'passed' => $passed,
			'error'  => $passed ? null : $result['error'],
		);
	}

	public static function has_passed() {
		$stored = get_option( self::OPTION_NAME, array() );
		return is_array( $stored ) && ! empty( $stored['passed'] );
	}
}
