<?php

namespace Cachicamo\WooCommerce\Catalog;

use Cachicamo\WooCommerce\Api\Client;
use Cachicamo\WooCommerce\Api\Routes;
use Cachicamo\WooCommerce\Plugin;
use Cachicamo\WooCommerce\Settings\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Cachicamo category tree <-> `product_cat` terms. A term's link to its Cachicamo UUID lives in
 * term meta `_cachicamo_category_uuid`; the reverse pointer (`wc_<store_uuid>`) lives on the
 * Cachicamo category itself and is written by whichever flow pushes categories out (export).
 */
class Categories {

	const META_KEY = '_cachicamo_category_uuid';

	/**
	 * Pulls `GET /products/categories` and mirrors the tree into `product_cat`, creating parents
	 * before children so `wp_insert_term`'s own parent id is always already known.
	 *
	 * @return array{created:int,updated:int,errors:array<int,string>}
	 */
	public static function import_tree() {
		$client = Plugin::instance()->service( 'api_client' );
		$result = array(
			'created' => 0,
			'updated' => 0,
			'errors'  => array(),
		);

		if ( null === $client ) {
			return $result;
		}

		$response = $client->request( 'GET', Routes::products_categories() );
		if ( ! $response['ok'] ) {
			$result['errors'][] = $response['error'];
			return $result;
		}

		$tree = isset( $response['body']['categories'] ) ? $response['body']['categories'] : $response['body'];
		if ( ! is_array( $tree ) ) {
			return $result;
		}

		self::import_nodes( $tree, 0, $result );

		return $result;
	}

	private static function import_nodes( array $nodes, $parent_term_id, array &$result ) {
		foreach ( $nodes as $node ) {
			if ( ! isset( $node['uuid'], $node['name'] ) ) {
				continue;
			}

			$term_id = self::upsert_term( (string) $node['uuid'], (string) $node['name'], $parent_term_id, $result );

			if ( null !== $term_id && ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
				self::import_nodes( $node['children'], $term_id, $result );
			}
		}
	}

	private static function upsert_term( $uuid, $name, $parent_term_id, array &$result ) {
		$term_id = self::term_for_uuid( $uuid );

		if ( null !== $term_id ) {
			wp_update_term(
				$term_id,
				'product_cat',
				array(
					'name'   => $name,
					'parent' => $parent_term_id,
				)
			);
			$result['updated']++;
			return $term_id;
		}

		$inserted = wp_insert_term( $name, 'product_cat', array( 'parent' => $parent_term_id ) );
		if ( is_wp_error( $inserted ) ) {
			$result['errors'][] = $inserted->get_error_message();
			return null;
		}

		$term_id = (int) $inserted['term_id'];
		update_term_meta( $term_id, self::META_KEY, $uuid );
		$result['created']++;

		return $term_id;
	}

	public static function term_for_uuid( $uuid ) {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'meta_key'   => self::META_KEY,
				'meta_value' => $uuid,
				'number'     => 1,
				'fields'     => 'ids',
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return null;
		}

		return (int) $terms[0];
	}

	public static function uuid_for_term( $term_id ) {
		$uuid = get_term_meta( $term_id, self::META_KEY, true );
		return $uuid ? $uuid : null;
	}

	/**
	 * Pure function (no I/O): collapses a category tree deeper than $max levels so everything
	 * below the last valid level hangs off that last valid parent instead of being dropped.
	 * Root nodes are depth 1.
	 *
	 * @param array<int,array{uuid?:string,name?:string,children?:array}> $tree
	 * @param int                                                          $max
	 * @return array<int,array{uuid?:string,name?:string,children?:array}>
	 */
	public static function flatten_to_depth( array $tree, $max = 5 ) {
		return self::flatten_nodes( $tree, 1, $max );
	}

	private static function flatten_nodes( array $nodes, $depth, $max ) {
		$flattened = array();

		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$children = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array();
			$node['children'] = array();

			if ( $depth >= $max ) {
				$flattened   = array_merge( $flattened, array( $node ), self::flatten_below( $children ) );
				continue;
			}

			$node['children'] = self::flatten_nodes( $children, $depth + 1, $max );
			$flattened[]      = $node;
		}

		return $flattened;
	}

	/**
	 * Everything past $max depth becomes siblings of the last valid node instead of nested
	 * children, since the destination has no room to nest them any deeper.
	 */
	private static function flatten_below( array $nodes ) {
		$siblings = array();

		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$children          = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array();
			$node['children']  = array();
			$siblings[]        = $node;
			$siblings          = array_merge( $siblings, self::flatten_below( $children ) );
		}

		return $siblings;
	}
}
