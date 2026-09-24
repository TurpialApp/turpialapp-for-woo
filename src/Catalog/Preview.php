<?php

namespace Cachicamo\WooCommerce\Catalog;

use Cachicamo\WooCommerce\Api\Routes;
use Cachicamo\WooCommerce\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Dry-run counters and conflicts for a flow, required before its first real run. The
 * evaluate_* methods are pure (no I/O, no WooCommerce calls) so the conflict rules are unit
 * tested directly; the run_* methods gather the WooCommerce/core data they need and delegate.
 */
class Preview {

	const CONFLICT_WC_SKU_TAKEN               = 'wc_sku_taken';
	const CONFLICT_WC_MARKER_TAKEN            = 'wc_marker_taken';
	const CONFLICT_CACHICAMO_SKU_TAKEN        = 'cachicamo_sku_taken';
	const CONFLICT_ATTRIBUTE_GROUP_UNRESOLVED = 'attribute_group_unresolved';

	/**
	 * Pure. $candidates: list of
	 * ['wc_id'=>int,'wc_sku'=>?string,'linked_uuid'=>?string,'cachicamo_skus'=>array<string>].
	 * $products_by_sku: SKU or WC-{id} marker => Cachicamo product uuid or null, as returned by
	 * POST /products/batch/sku. $wc_sku_owner: Cachicamo SKU => wc_id owning it in WooCommerce,
	 * preloaded via wc_get_product_id_by_sku so the loop never queries per item.
	 *
	 * @return array{created:int,updated:int,conflicts:array<int,array>}
	 */
	public static function evaluate_products( array $candidates, array $products_by_sku, array $wc_sku_owner ) {
		$created   = 0;
		$updated   = 0;
		$conflicts = array();

		foreach ( $candidates as $candidate ) {
			$wc_id       = $candidate['wc_id'];
			$linked_uuid = isset( $candidate['linked_uuid'] ) ? $candidate['linked_uuid'] : null;
			$wc_sku      = isset( $candidate['wc_sku'] ) && '' !== $candidate['wc_sku'] ? $candidate['wc_sku'] : null;
			$marker      = 'WC-' . $wc_id;

			if ( null !== $wc_sku && array_key_exists( $wc_sku, $products_by_sku )
				&& null !== $products_by_sku[ $wc_sku ] && $products_by_sku[ $wc_sku ] !== $linked_uuid ) {
				$conflicts[] = array(
					'type'       => self::CONFLICT_WC_SKU_TAKEN,
					'wc_id'      => $wc_id,
					'sku'        => $wc_sku,
					'owner_uuid' => $products_by_sku[ $wc_sku ],
				);
				continue;
			}

			if ( array_key_exists( $marker, $products_by_sku )
				&& null !== $products_by_sku[ $marker ] && $products_by_sku[ $marker ] !== $linked_uuid ) {
				$conflicts[] = array(
					'type'       => self::CONFLICT_WC_MARKER_TAKEN,
					'wc_id'      => $wc_id,
					'marker'     => $marker,
					'owner_uuid' => $products_by_sku[ $marker ],
				);
				continue;
			}

			$sku_conflict = false;
			foreach ( isset( $candidate['cachicamo_skus'] ) ? $candidate['cachicamo_skus'] : array() as $cachicamo_sku ) {
				if ( isset( $wc_sku_owner[ $cachicamo_sku ] ) && $wc_sku_owner[ $cachicamo_sku ] !== $wc_id ) {
					$conflicts[]  = array(
						'type'         => self::CONFLICT_CACHICAMO_SKU_TAKEN,
						'wc_id'        => $wc_id,
						'sku'          => $cachicamo_sku,
						'owner_wc_id'  => $wc_sku_owner[ $cachicamo_sku ],
					);
					$sku_conflict = true;
					break;
				}
			}
			if ( $sku_conflict ) {
				continue;
			}

			if ( null !== $linked_uuid ) {
				++$updated;
			} else {
				++$created;
			}
		}

		return array(
			'created'   => $created,
			'updated'   => $updated,
			'conflicts' => $conflicts,
		);
	}

	/**
	 * Pure. $attributes: list of
	 * ['product_id'=>int,'attribute_name'=>string,'group_name'=>?string,'group_uuid'=>?string].
	 *
	 * @return array{created:int,updated:int,conflicts:array<int,array>}
	 */
	public static function evaluate_attributes( array $attributes ) {
		$created   = 0;
		$updated   = 0;
		$conflicts = array();

		foreach ( $attributes as $attribute ) {
			$group_name = Attributes::resolve_group_name( isset( $attribute['group_name'] ) ? $attribute['group_name'] : '' );

			if ( null === $group_name ) {
				$conflicts[] = array(
					'type'           => self::CONFLICT_ATTRIBUTE_GROUP_UNRESOLVED,
					'product_id'     => $attribute['product_id'],
					'attribute_name' => $attribute['attribute_name'],
				);
				continue;
			}

			if ( ! empty( $attribute['group_uuid'] ) ) {
				++$updated;
			} else {
				++$created;
			}
		}

		return array(
			'created'   => $created,
			'updated'   => $updated,
			'conflicts' => $conflicts,
		);
	}

	/**
	 * Pure. $flattened_items: output of CategoriesExport::flatten_to_items().
	 *
	 * @return array{created:int,updated:int,conflicts:array}
	 */
	public static function evaluate_categories( array $flattened_items ) {
		$created = 0;
		$updated = 0;

		foreach ( $flattened_items as $item ) {
			if ( isset( $item['parent_uuid'] ) || null !== Categories::uuid_for_term( $item['wc_id'] ) ) {
				++$updated;
			} else {
				++$created;
			}
		}

		return array(
			'created'   => $created,
			'updated'   => $updated,
			'conflicts' => array(),
		);
	}

	/**
	 * Dry run of the products export: reads WooCommerce products and their Cachicamo link,
	 * resolves SKUs against the core in one batch call and against WooCommerce's own SKU index,
	 * without writing anything.
	 */
	public static function run_export_products( array $wc_products ) {
		$candidates = array();
		$skus_to_check = array();

		foreach ( $wc_products as $product ) {
			$wc_id       = $product['id'];
			$linked_uuid = Links::get_uuid( $wc_id );
			$wc_sku      = isset( $product['sku'] ) ? $product['sku'] : '';
			$marker      = 'WC-' . $wc_id;

			if ( '' !== $wc_sku ) {
				$skus_to_check[ $wc_sku ] = true;
			}
			$skus_to_check[ $marker ] = true;

			$candidates[] = array(
				'wc_id'          => $wc_id,
				'wc_sku'         => '' !== $wc_sku ? $wc_sku : null,
				'linked_uuid'    => $linked_uuid,
				'cachicamo_skus' => isset( $product['cachicamo_sku_list'] ) ? $product['cachicamo_sku_list'] : array(),
			);
			foreach ( isset( $product['cachicamo_sku_list'] ) ? $product['cachicamo_sku_list'] : array() as $cachicamo_sku ) {
				$skus_to_check[ $cachicamo_sku ] = true;
			}
		}

		$products_by_sku = self::resolve_batch_sku( array_keys( $skus_to_check ) );
		$wc_sku_owner    = self::preload_wc_sku_owners( $candidates );

		return self::evaluate_products( $candidates, $products_by_sku, $wc_sku_owner );
	}

	/**
	 * @param array<int,string> $sku_list
	 * @return array<string,string|null>
	 */
	private static function resolve_batch_sku( array $sku_list ) {
		if ( empty( $sku_list ) ) {
			return array();
		}

		$client = Plugin::instance()->service( 'api_client' );
		$map    = array();

		foreach ( array_chunk( $sku_list, 500 ) as $chunk ) {
			$result = $client->request( 'POST', Routes::products_batch_sku(), array( 'sku_list' => $chunk ) );
			if ( $result['ok'] && isset( $result['body']['products_by_sku'] ) && is_array( $result['body']['products_by_sku'] ) ) {
				$map = array_merge( $map, $result['body']['products_by_sku'] );
			}
		}

		return $map;
	}

	/**
	 * @return array<string,int> Cachicamo SKU => wc_id owning it in WooCommerce, for every SKU
	 *                           already recorded on the candidate other than its own.
	 */
	private static function preload_wc_sku_owners( array $candidates ) {
		$owners = array();
		foreach ( $candidates as $candidate ) {
			foreach ( $candidate['cachicamo_skus'] as $sku ) {
				if ( '' === $sku || isset( $owners[ $sku ] ) ) {
					continue;
				}
				$owner_id = function_exists( 'wc_get_product_id_by_sku' ) ? wc_get_product_id_by_sku( $sku ) : 0;
				if ( $owner_id ) {
					$owners[ $sku ] = (int) $owner_id;
				}
			}
		}
		return $owners;
	}
}
