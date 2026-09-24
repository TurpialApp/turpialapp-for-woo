<?php

namespace Cachicamo\WooCommerce\Webhooks;

use Cachicamo\WooCommerce\Account\Reachability;
use Cachicamo\WooCommerce\Settings\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's only REST route. POST stores the event and answers immediately so the core's
 * webhook delivery never waits on this site's processing; cachicamoapp_drain_inbox is what
 * actually dispatches the stored rows, in Dispatcher order, at most 200 per run.
 */
class Receiver {

	const NAMESPACE = 'cachicamoapp/v1';
	const ROUTE     = '/events';

	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_event' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_probe' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function handle_event( \WP_REST_Request $request ) {
		$secret = Repository::get( 'webhook_secret', '' );
		$key    = (string) $request->get_header( 'X-Cachicamo-Key' );

		if ( '' === $secret || ! hash_equals( $secret, $key ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'Invalid webhook key.', 'cachicamoapp-for-woo' ) ), 401 );
		}

		$payload = $request->get_json_params();
		$payload = is_array( $payload ) ? $payload : array();
		$action  = isset( $payload['action'] ) ? (string) $payload['action'] : '';

		if ( '' === $action ) {
			return new \WP_REST_Response( array( 'message' => __( 'Missing action.', 'cachicamoapp-for-woo' ) ), 400 );
		}

		self::store( $action, $payload );

		return new \WP_REST_Response( array( 'received' => true ), 200 );
	}

	public static function handle_probe( \WP_REST_Request $request ) {
		$probe = (string) $request->get_param( 'probe' );
		$nonce = Reachability::current_nonce();

		if ( '' === $probe || ! hash_equals( $nonce, $probe ) ) {
			return new \WP_REST_Response( null, 404 );
		}

		$response = new \WP_REST_Response( $nonce, 200 );
		$response->header( 'Content-Type', 'text/plain' );
		return $response;
	}

	private static function store( $action, array $payload ) {
		global $wpdb;

		$event_key = isset( $payload['event_id'] ) && '' !== $payload['event_id']
			? (string) $payload['event_id']
			: hash( 'sha1', $action . wp_json_encode( $payload ) . microtime() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- inbox write, no caching layer applies to an insert.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}cachicamo_inbox (event_key, action, payload, received_at) VALUES (%s, %s, %s, %s)",
				$event_key,
				$action,
				wp_json_encode( $payload ),
				current_time( 'mysql', true )
			)
		);
	}

	public static function drain_inbox( $limit = 200 ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- must read the pending queue fresh every run; caching would replay already-drained events.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT event_key, action, payload FROM {$wpdb->prefix}cachicamo_inbox ORDER BY received_at ASC LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return 0;
		}

		foreach ( $rows as $row ) {
			$payload = json_decode( $row['payload'], true );
			$payload = is_array( $payload ) ? $payload : array();

			try {
				Dispatcher::dispatch( $row['action'], $payload );
			} catch ( \Exception $exception ) {
				if ( function_exists( 'wc_get_logger' ) ) {
					wc_get_logger()->error( 'inbox dispatch failed for ' . $row['action'] . ': ' . $exception->getMessage(), array( 'source' => 'cachicamoapp' ) );
				}
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- inbox row consumed once dispatched, no caching layer applies to a delete.
			$wpdb->delete( "{$wpdb->prefix}cachicamo_inbox", array( 'event_key' => $row['event_key'] ) );
		}

		return count( $rows );
	}
}
