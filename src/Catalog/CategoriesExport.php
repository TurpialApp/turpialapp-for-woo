<?php

namespace Cachicamo\WooCommerce\Catalog;

use Cachicamo\WooCommerce\Api\Routes;
use Cachicamo\WooCommerce\Jobs\RunHandler;
use Cachicamo\WooCommerce\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce `product_cat` tree -> Cachicamo categories. Reuses Categories::flatten_to_depth
 * (pure) for the 5-level clamp; this class only supplies the WooCommerce-shaped tree and turns
 * the flattened result into the bulk upsert wire format.
 */
class CategoriesExport implements RunHandler {

	const RUN_TYPE   = 'export_categories';
	const MAX_DEPTH  = 5;
	const BATCH_SIZE = 500;

	public function run_batch( $cursor, array $context ) {
		$items = self::flattened_items();
		$total = count( $items );
		$slice = array_slice( $items, $cursor, self::BATCH_SIZE );

		if ( empty( $slice ) ) {
			return array(
				'processed'   => 0,
				'next_cursor' => $cursor,
				'done'        => true,
				'total'       => $total,
			);
		}

		$client   = Plugin::instance()->service( 'api_client' );
		$errors   = array();
		$response = $client->request( 'POST', Routes::products_categories_bulk(), array( 'categories' => self::without_wc_id( $slice ) ) );

		if ( $response['ok'] ) {
			self::store_links( $slice, isset( $response['body']['results'] ) ? $response['body']['results'] : array() );
		} else {
			$errors[] = $response['error'];
		}

		$next = $cursor + count( $slice );

		return array(
			'processed'   => count( $slice ),
			'next_cursor' => $next,
			'done'        => $next >= $total,
			'total'       => $total,
			'errors'      => $errors,
		);
	}

	/**
	 * @return array<int,array{ref:string,name:string,parent_ref?:string,parent_uuid?:string,metadata:array,wc_id:int}>
	 */
	public static function flattened_items() {
		return self::flatten_to_items( self::build_tree() );
	}

	public static function build_tree() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}

		$by_parent = array();
		foreach ( $terms as $term ) {
			$by_parent[ (int) $term->parent ][] = $term;
		}

		return self::build_nodes( $by_parent, 0 );
	}

	private static function build_nodes( array $by_parent, $parent_id ) {
		$nodes = array();
		if ( empty( $by_parent[ $parent_id ] ) ) {
			return $nodes;
		}

		foreach ( $by_parent[ $parent_id ] as $term ) {
			$nodes[] = array(
				'id'       => (int) $term->term_id,
				'name'     => $term->name,
				'children' => self::build_nodes( $by_parent, (int) $term->term_id ),
			);
		}

		return $nodes;
	}

	/**
	 * Pure (no I/O): clamps the tree to $max levels with Categories::flatten_to_depth, then
	 * flattens the resulting nested structure into an ordered, parent-first list of bulk upsert
	 * items. A node already linked to a Cachicamo category (checked by the caller building
	 * $tree, via the `linked_uuid` key) carries `parent_uuid` when its parent is also linked,
	 * falling back to `parent_ref` otherwise so the batch can still resolve it by position.
	 *
	 * @param array<int,array{id:int,name:string,children?:array,linked_uuid?:string}> $tree
	 * @return array<int,array{ref:string,name:string,parent_ref?:string,parent_uuid?:string,metadata:array,wc_id:int}>
	 */
	public static function flatten_to_items( array $tree, $max = self::MAX_DEPTH ) {
		$flattened = Categories::flatten_to_depth( $tree, $max );
		$items     = array();
		self::collect_items( $flattened, null, null, $items );
		return $items;
	}

	private static function collect_items( array $nodes, $parent_ref, $parent_uuid, array &$items ) {
		foreach ( $nodes as $node ) {
			if ( ! isset( $node['id'], $node['name'] ) ) {
				continue;
			}

			$ref              = 'wc-' . $node['id'];
			$linked_uuid       = isset( $node['linked_uuid'] ) ? $node['linked_uuid'] : Categories::uuid_for_term( $node['id'] );
			$item             = array(
				'ref'      => $ref,
				'name'     => $node['name'],
				'metadata' => array( 'wc_id' => $node['id'] ),
				'wc_id'    => $node['id'],
			);

			if ( null !== $parent_uuid ) {
				$item['parent_uuid'] = $parent_uuid;
			} elseif ( null !== $parent_ref ) {
				$item['parent_ref'] = $parent_ref;
			}

			$items[] = $item;

			self::collect_items(
				isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array(),
				$ref,
				$linked_uuid,
				$items
			);
		}
	}

	private static function without_wc_id( array $items ) {
		return array_map(
			static function ( $item ) {
				unset( $item['wc_id'] );
				return $item;
			},
			$items
		);
	}

	private static function store_links( array $items, array $results ) {
		$by_ref = array();
		foreach ( $results as $result ) {
			if ( isset( $result['ref'] ) ) {
				$by_ref[ $result['ref'] ] = $result;
			}
		}

		foreach ( $items as $item ) {
			if ( ! isset( $by_ref[ $item['ref'] ]['uuid'] ) ) {
				continue;
			}
			update_term_meta( $item['wc_id'], Categories::META_KEY, $by_ref[ $item['ref'] ]['uuid'] );
		}
	}
}
