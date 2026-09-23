<?php

namespace Cachicamo\WooCommerce\Api;

use Cachicamo\WooCommerce\Settings\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * HTTP client against the Cachicamo core. Every call goes through request(), which applies
 * the Guard, sets the standard headers and interprets the response per the plan's table 4.3.
 */
class Client {

	/**
	 * Fatal: the core answered a route that does not exist (Echo's own 404 body). The plugin
	 * must stop everything and never retry, so this is surfaced as its own exception type.
	 */
	const ERROR_ROUTE_NOT_FOUND = 'route_not_found';
	const ERROR_UNAUTHORIZED    = 'unauthorized';
	const ERROR_EXPIRED         = 'expired';
	const ERROR_FORBIDDEN       = 'forbidden';
	const ERROR_NOT_FOUND       = 'not_found';
	const ERROR_CONFLICT        = 'conflict';
	const ERROR_PAYLOAD_TOO_LARGE = 'payload_too_large';
	const ERROR_RATE_LIMITED    = 'rate_limited';
	const ERROR_QUOTA_EXCEEDED  = 'quota_exceeded';
	const ERROR_SERVICE_BUSY    = 'service_busy';
	const ERROR_SERVER          = 'server_error';
	const ERROR_TRANSPORT       = 'transport_error';
	const ERROR_GUARDED         = 'guarded';
	const ERROR_VALIDATION      = 'validation';

	/**
	 * @param string               $method
	 * @param string               $route  From Api\Routes; never a hand-built path.
	 * @param array<string,mixed>  $body
	 * @param array<string,mixed>  $query
	 * @return array{ok:bool,status:int,body:array<string,mixed>,error:string|null,retry_after:int|null}
	 */
	public function request( $method, $route, array $body = array(), array $query = array() ) {
		$guarded_field = Guard::detect_in_fields( $this->flatten( $body ) );
		if ( null !== $guarded_field ) {
			return array(
				'ok'          => false,
				'status'      => 0,
				'body'        => array(),
				'error'       => self::ERROR_GUARDED,
				'field'       => $guarded_field,
				'retry_after' => null,
			);
		}

		$url = untrailingslashit( CACHICAMO_APP_API_URL ) . $route;
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => $this->headers(),
		);
		if ( ! empty( $body ) && in_array( $method, array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$this->log( 'transport error calling ' . $route . ': ' . $response->get_error_message() );
			return array(
				'ok'          => false,
				'status'      => 0,
				'body'        => array(),
				'error'       => self::ERROR_TRANSPORT,
				'retry_after' => 60,
			);
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		$decoded = is_array( $decoded ) ? $decoded : array();

		return $this->interpret( $status, $decoded, $response, $route );
	}

	private function headers() {
		return array(
			'Authorization' => 'Bearer ' . Repository::get( 'api_token', '' ),
			'X-Store-Uuid'  => Repository::get( 'store_uuid', '' ),
			'X-Language'    => $this->language(),
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
		);
	}

	private function language() {
		$locale = get_locale();
		return substr( $locale, 0, 2 ) ?: 'es';
	}

	/**
	 * Table 4.3: same condition always yields the same outcome, whatever the caller.
	 */
	private function interpret( $status, array $decoded, $response, $route ) {
		$remaining = $this->rate_limit_remaining( $response );
		$near_limit = null !== $remaining && $remaining < 5;

		$result = array(
			'ok'          => $status >= 200 && $status < 300,
			'status'      => $status,
			'body'        => $decoded,
			'error'       => null,
			'retry_after' => null,
		);

		if ( $result['ok'] ) {
			if ( $near_limit ) {
				$result['retry_after'] = 60;
			}
			return $result;
		}

		switch ( true ) {
			case 400 === $status:
				$result['error'] = self::ERROR_VALIDATION;
				break;
			case 401 === $status:
				$result['error'] = self::ERROR_UNAUTHORIZED;
				break;
			case 402 === $status:
				$result['error'] = self::ERROR_EXPIRED;
				break;
			case 403 === $status:
				$result['error'] = self::ERROR_FORBIDDEN;
				break;
			case 404 === $status:
				$result['error'] = $this->is_echo_route_not_found( $decoded )
					? self::ERROR_ROUTE_NOT_FOUND
					: self::ERROR_NOT_FOUND;
				if ( self::ERROR_ROUTE_NOT_FOUND === $result['error'] ) {
					$this->log( 'FATAL route_error on ' . $route . ': route does not exist on the core' );
				}
				break;
			case 409 === $status:
				$result['error'] = self::ERROR_CONFLICT;
				break;
			case 413 === $status:
				$result['error'] = self::ERROR_PAYLOAD_TOO_LARGE;
				break;
			case 429 === $status:
				if ( array_key_exists( 'error', $decoded ) ) {
					$result['error']       = self::ERROR_RATE_LIMITED;
					$result['retry_after'] = 60;
				} else {
					$result['error'] = self::ERROR_QUOTA_EXCEEDED;
				}
				break;
			case 503 === $status:
				$result['error']       = self::ERROR_SERVICE_BUSY;
				$result['retry_after'] = 60;
				break;
			case $status >= 500:
				$result['error'] = self::ERROR_SERVER;
				break;
			default:
				$result['error'] = self::ERROR_SERVER;
		}

		$this->log( sprintf( 'core answered %d on %s: %s', $status, $route, wp_json_encode( $decoded ) ) );

		return $result;
	}

	/**
	 * Echo's own "not found" body for an unregistered route, distinct from a business 404
	 * ("customer not found"). A fatal route error must never be confused with a normal
	 * missing-resource response.
	 */
	private function is_echo_route_not_found( array $decoded ) {
		return isset( $decoded['message'] ) && 'Not Found' === $decoded['message'] && 1 === count( $decoded );
	}

	private function rate_limit_remaining( $response ) {
		$headers = array(
			wp_remote_retrieve_header( $response, 'X-RateLimit-Post-Remaining' ),
			wp_remote_retrieve_header( $response, 'X-RateLimit-Get-Remaining' ),
			wp_remote_retrieve_header( $response, 'X-RateLimit-Other-Remaining' ),
		);
		$values = array_filter( $headers, static function ( $value ) {
			return '' !== $value && null !== $value;
		} );
		if ( empty( $values ) ) {
			return null;
		}
		return (int) min( array_map( 'intval', $values ) );
	}

	private function flatten( array $data, $prefix = '' ) {
		$flat = array();
		foreach ( $data as $key => $value ) {
			$path = '' === $prefix ? $key : $prefix . '.' . $key;
			if ( is_array( $value ) ) {
				$flat += $this->flatten( $value, $path );
				continue;
			}
			$flat[ $path ] = $value;
		}
		return $flat;
	}

	private function log( $message ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->debug( $message, array( 'source' => 'cachicamoapp' ) );
		}
	}
}
