<?php

namespace Cachicamo\WooCommerce\Billing;

use Cachicamo\WooCommerce\Api\Routes;
use Cachicamo\WooCommerce\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the Cachicamo customer for a WooCommerce order: matches the document id captured at
 * checkout against an existing customer, falling back to the buyer's email, and creates one
 * only when neither matches. The result is cached on the WordPress user so the same buyer never
 * triggers a second search on their next order.
 */
class CustomerResolver {

	const META_CUSTOMER_UUID = '_cachicamo_customer_uuid';

	/**
	 * @param \WC_Order $order
	 * @return array{ok:bool,customer_uuid:?string,error:?string}
	 */
	public static function resolve( \WC_Order $order ) {
		$user_id = $order->get_customer_id();
		if ( $user_id > 0 ) {
			$cached = get_user_meta( $user_id, self::META_CUSTOMER_UUID, true );
			if ( ! empty( $cached ) ) {
				return array(
					'ok'            => true,
					'customer_uuid' => $cached,
					'error'         => null,
				);
			}
		}

		$client   = Plugin::instance()->service( 'api_client' );
		$document = $order->get_meta( '_cachicamo_document_id' );
		$email    = $order->get_billing_email();

		if ( ! empty( $document ) ) {
			$found = self::search_by_document( $client, $document );
			if ( null !== $found ) {
				self::cache( $user_id, $found );
				return array(
					'ok'            => true,
					'customer_uuid' => $found,
					'error'         => null,
				);
			}
		}

		if ( ! empty( $email ) ) {
			$found = self::search_by_email( $client, $email );
			if ( null !== $found ) {
				self::cache( $user_id, $found );
				return array(
					'ok'            => true,
					'customer_uuid' => $found,
					'error'         => null,
				);
			}
		}

		return self::create( $client, $order, $document, $email, $user_id );
	}

	private static function search_by_document( $client, $document ) {
		$result = $client->request( 'GET', Routes::customers_search( $document ) );
		if ( ! $result['ok'] ) {
			return null;
		}

		$rows = self::rows( $result['body'] );
		$normalized = strtoupper( preg_replace( '/[^A-Z0-9]/', '', strtoupper( $document ) ) );

		foreach ( $rows as $row ) {
			$dni = isset( $row['dni'] ) ? strtoupper( preg_replace( '/[^A-Z0-9]/', '', $row['dni'] ) ) : '';
			$vat = isset( $row['company_vat'] ) ? strtoupper( preg_replace( '/[^A-Z0-9]/', '', $row['company_vat'] ) ) : '';
			if ( $normalized === $dni || $normalized === $vat ) {
				return isset( $row['uuid'] ) ? $row['uuid'] : null;
			}
		}
		return null;
	}

	private static function search_by_email( $client, $email ) {
		$result = $client->request( 'GET', Routes::customers_email( $email ) );
		if ( ! $result['ok'] ) {
			return null;
		}
		$rows = self::rows( $result['body'] );
		foreach ( $rows as $row ) {
			if ( isset( $row['uuid'] ) ) {
				return $row['uuid'];
			}
		}
		return null;
	}

	private static function create( $client, \WC_Order $order, $document, $email, $user_id ) {
		$country  = $order->get_billing_country();
		$is_legal = null !== $document && preg_match( '/^[JGC]/', $document );

		$body = array(
			'country' => $country ? $country : 'VE',
			'email'   => $email,
			'phone'   => $order->get_billing_phone(),
		);

		if ( $is_legal ) {
			$body['company']     = trim( $order->get_billing_company() ) ?: trim( $order->get_formatted_billing_full_name() );
			$body['company_vat'] = $document;
		} else {
			$body['name'] = trim( $order->get_formatted_billing_full_name() );
			if ( ! empty( $document ) ) {
				$body['dni'] = $document;
			}
		}

		$result = $client->request( 'POST', Routes::customers_create(), $body );
		if ( ! $result['ok'] || empty( $result['body']['uuid'] ) ) {
			return array(
				'ok'            => false,
				'customer_uuid' => null,
				'error'         => $result['error'],
			);
		}

		$uuid = $result['body']['uuid'];
		self::cache( $user_id, $uuid );

		return array(
			'ok'            => true,
			'customer_uuid' => $uuid,
			'error'         => null,
		);
	}

	private static function cache( $user_id, $customer_uuid ) {
		if ( $user_id > 0 ) {
			update_user_meta( $user_id, self::META_CUSTOMER_UUID, $customer_uuid );
		}
	}

	private static function rows( array $body ) {
		if ( isset( $body['data'] ) && is_array( $body['data'] ) ) {
			return $body['data'];
		}
		if ( isset( $body['uuid'] ) ) {
			return array( $body );
		}
		return is_array( $body ) ? $body : array();
	}
}
