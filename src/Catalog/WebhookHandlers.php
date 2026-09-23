<?php

namespace Cachicamo\WooCommerce\Catalog;

use Cachicamo\WooCommerce\Api\Client;
use Cachicamo\WooCommerce\Api\Routes;
use Cachicamo\WooCommerce\Plugin;
use Cachicamo\WooCommerce\Settings\Repository;
use Cachicamo\WooCommerce\Webhooks\Dispatcher;

defined( 'ABSPATH' ) || exit;

/**
 * Catalog side of the inbox (plan 4.15): incremental import from `product.*`, stock and combo
 * recalculation from `stock.*`, and the remote-delete rule from `product.deleted` /
 * `product.updated` with `active: false`.
 */
class WebhookHandlers {

	public static function register() {
		Dispatcher::on( 'product', array( __CLASS__, 'handle_product' ) );
		Dispatcher::on( 'stock', array( __CLASS__, 'handle_stock' ) );
	}

	public static function handle_product( $action, array $payload ) {
		if ( 'product.deleted' === $action ) {
			if ( isset( $payload['uuid'] ) ) {
				RemoteDelete::handle( (string) $payload['uuid'] );
			}
			return;
		}

		foreach ( self::items_from( $payload ) as $item ) {
			self::handle_single_product( $action, $item );
		}
	}

	private static function items_from( array $payload ) {
		if ( isset( $payload['products'] ) && is_array( $payload['products'] ) ) {
			return $payload['products'];
		}
		if ( isset( $payload['uuid'] ) ) {
			return array( $payload );
		}
		return array();
	}

	private static function handle_single_product( $action, array $item ) {
		if ( ! isset( $item['uuid'] ) ) {
			return;
		}

		if ( array_key_exists( 'active', $item ) && false === $item['active'] ) {
			RemoteDelete::handle( (string) $item['uuid'] );
			return;
		}

		if ( 'cachicamo' !== Repository::get( 'content_source', 'cachicamo' ) ) {
			return;
		}

		$hash_fields = array(
			'name'                   => isset( $item['name'] ) ? $item['name'] : null,
			'description'            => isset( $item['description'] ) ? $item['description'] : null,
			'active'                 => isset( $item['active'] ) ? $item['active'] : null,
			'price'                  => isset( $item['price'] ) ? $item['price'] : null,
			'category_primary_uuid'  => isset( $item['category_primary_uuid'] ) ? $item['category_primary_uuid'] : null,
		);
		$hash = Links::content_hash( $hash_fields );

		$wc_id = Links::get_wc_id( $item['uuid'] );
		if ( null !== $wc_id && Links::content_hash_matches( $wc_id, $hash ) ) {
			return;
		}

		$import = new ImportFlow();
		$result = $import->upsert_product( $item );

		if ( null !== $result ) {
			Links::set_content_hash( $result['wc_id'], $hash );
		}
	}

	public static function handle_stock( $action, array $payload ) {
		foreach ( self::items_from_stock( $payload ) as $item ) {
			self::handle_single_stock( $item );
		}
	}

	private static function items_from_stock( array $payload ) {
		if ( isset( $payload['inventories'] ) && is_array( $payload['inventories'] ) ) {
			return $payload['inventories'];
		}
		if ( isset( $payload['product_uuid'] ) ) {
			return array( $payload );
		}
		return array();
	}

	private static function handle_single_stock( array $item ) {
		if ( ! isset( $item['product_uuid'], $item['store_uuid'] ) ) {
			return;
		}

		if ( 'cachicamo' !== Repository::get( 'stock_source', 'cachicamo' ) ) {
			return;
		}

		if ( (string) $item['store_uuid'] !== (string) Repository::get( 'store_uuid', '' ) ) {
			return;
		}

		$product_uuid = (string) $item['product_uuid'];
		$wc_id        = Links::get_wc_id( $product_uuid );
		if ( null === $wc_id ) {
			self::reload_variation_parent_stock( $product_uuid );
			return;
		}

		$billable = Stock::billable_from_payload( $item );
		if ( null === $billable ) {
			return;
		}

		Stock::apply( $wc_id, $billable );

		foreach ( Combos::combos_using( $product_uuid ) as $combo_uuid ) {
			Combos::recalculate( $combo_uuid );
		}
	}

	/**
	 * A stock event for a product with no link of its own can be a variation whose parent is
	 * linked; the batch of that parent's variations is re-read with
	 * `POST /inventories/batch/sku` so each variation's own stock is applied individually.
	 */
	private static function reload_variation_parent_stock( $product_uuid ) {
		$client = Plugin::instance()->service( 'api_client' );
		if ( null === $client ) {
			return;
		}

		$response = $client->request( 'POST', Routes::inventories_batch_sku(), array( 'ids' => array( $product_uuid ) ) );
		if ( ! $response['ok'] || empty( $response['body']['data'] ) ) {
			return;
		}

		foreach ( $response['body']['data'] as $row ) {
			if ( ! isset( $row['product_uuid'] ) ) {
				continue;
			}
			$wc_id = Links::get_wc_id( $row['product_uuid'] );
			if ( null === $wc_id ) {
				continue;
			}
			$billable = Stock::billable_from_payload( $row );
			if ( null !== $billable ) {
				Stock::apply( $wc_id, $billable );
			}
		}
	}
}
