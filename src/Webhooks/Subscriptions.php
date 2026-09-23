<?php

namespace Cachicamo\WooCommerce\Webhooks;

use Cachicamo\WooCommerce\Api\Routes;
use Cachicamo\WooCommerce\Plugin;
use Cachicamo\WooCommerce\Settings\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Subscribes this site's own destination to every event in EVENTS without touching another
 * destination already configured on the same event: the core's webhook config is shared across
 * every integration a store runs, so a blind overwrite would silently unsubscribe them.
 */
class Subscriptions {

	const MAX_DESTINATIONS = 3;

	const EVENTS = array(
		'product.created',
		'product.updated',
		'product.bulk_created',
		'product.bulk_updated',
		'product.deleted',
		'stock.updated',
		'stock.bulk_updated',
		'document.created',
		'document.updated',
		'payment_method.created',
		'payment_method.updated',
		'payment_method.deleted',
		'async_payment.created',
		'async_payment.updated',
	);

	/**
	 * @return array<string,array{ok:bool,error:string|null}> keyed by event
	 */
	public static function subscribe_all() {
		$results = array();
		foreach ( self::EVENTS as $event ) {
			$results[ $event ] = self::subscribe_one( $event );
		}
		update_option( 'cachicamoapp_webhook_subscriptions', $results, false );
		return $results;
	}

	private static function subscribe_one( $event ) {
		$client = Plugin::instance()->service( 'api_client' );
		if ( null === $client ) {
			return array(
				'ok'    => false,
				'error' => 'unavailable',
			);
		}

		$get = $client->request( 'GET', Routes::webhooks_config(), array(), array( 'event' => $event ) );
		if ( ! $get['ok'] ) {
			return array(
				'ok'    => false,
				'error' => $get['error'],
			);
		}

		$destinations = isset( $get['body']['destinations'] ) && is_array( $get['body']['destinations'] )
			? $get['body']['destinations']
			: array();

		$own_url  = rest_url( 'cachicamoapp/v1/events' );
		$foreign  = array_values(
			array_filter(
				$destinations,
				static function ( $destination ) use ( $own_url ) {
					return ! isset( $destination['url'] ) || $destination['url'] !== $own_url;
				}
			)
		);

		if ( count( $foreign ) >= self::MAX_DESTINATIONS ) {
			return array(
				'ok'    => false,
				'error' => 'destination_limit',
			);
		}

		$foreign[] = array(
			'url'     => $own_url,
			'method'  => 'POST',
			'headers' => array(
				'X-Cachicamo-Key' => Repository::get( 'webhook_secret', '' ),
			),
		);

		$post = $client->request(
			'POST',
			Routes::webhooks_config(),
			array(
				'event'        => $event,
				'destinations' => $foreign,
			)
		);

		return array(
			'ok'    => $post['ok'],
			'error' => $post['ok'] ? null : $post['error'],
		);
	}
}
