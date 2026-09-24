<?php

namespace Cachicamo\WooCommerce\Catalog;

use Cachicamo\WooCommerce\Account\Status;
use Cachicamo\WooCommerce\Api\Client;
use Cachicamo\WooCommerce\Api\Routes;
use Cachicamo\WooCommerce\Jobs\RunHandler;
use Cachicamo\WooCommerce\Plugin;
use Cachicamo\WooCommerce\Settings\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Cachicamo -> WooCommerce catalog import (plan 4.16). Runs as a BatchRunner RunHandler: one
 * page of `GET /products` per batch, 50 products written to WooCommerce per page, and the
 * matching page of `GET /inventories/simple/export/json` applied for stock and price. New
 * WooCommerce ids are linked back with `WC-{id}` via `POST /products/bulk/json` at the end of
 * every page, never accumulated across pages.
 */
class ImportFlow implements RunHandler {

	const RUN_TYPE  = 'import';
	const PAGE_SIZE = 500;

	public function run_batch( $cursor, array $context ) {
		$page = (int) $cursor + 1;

		if ( ! Status::is_writable() ) {
			return array(
				'processed'   => 0,
				'next_cursor' => $cursor,
				'done'        => false,
				'retry_after' => 300,
			);
		}

		$client = Plugin::instance()->service( 'api_client' );
		if ( null === $client ) {
			return array(
				'processed'   => 0,
				'next_cursor' => $cursor,
				'done'        => true,
				'errors'      => array( 'api_client_unavailable' ),
			);
		}

		$products_response = $client->request(
			'GET',
			Routes::products( $page, self::PAGE_SIZE ),
			array(),
			array(
				'limit'     => self::PAGE_SIZE,
				'page'      => $page,
				'col_sort'  => 'created_at',
				'dir_sort'  => 'asc',
			)
		);

		if ( ! $products_response['ok'] ) {
			return array(
				'processed'   => 0,
				'next_cursor' => $cursor,
				'done'        => false,
				'errors'      => array( (string) $products_response['error'] ),
				'retry_after' => 60,
			);
		}

		$products = isset( $products_response['body']['rows'] ) ? $products_response['body']['rows'] : array();
		$total    = isset( $products_response['body']['total_rows'] ) ? (int) $products_response['body']['total_rows'] : null;

		if ( empty( $products ) ) {
			return array(
				'processed'   => 0,
				'next_cursor' => $cursor,
				'done'        => true,
				'total'       => $total,
			);
		}

		$inventory_by_uuid = $this->fetch_inventory_page( $client, $page );

		$errors    = array();
		$processed = 0;
		$new_links = array();

		foreach ( array_chunk( $products, 50 ) as $chunk ) {
			foreach ( $chunk as $product_payload ) {
				// GET /products nests a parent's variations under its own `variations` key
				// instead of listing them as rows of their own; the parent is upserted first so
				// Links::get_wc_id( parent_uuid ) resolves by the time each variation runs.
				$nodes = array( $product_payload );
				if ( ! empty( $product_payload['variations'] ) && is_array( $product_payload['variations'] ) ) {
					foreach ( $product_payload['variations'] as $variation_payload ) {
						$nodes[] = $variation_payload;
					}
				}

				foreach ( $nodes as $node_payload ) {
					try {
						$result = $this->upsert_product( $node_payload, $inventory_by_uuid );
						$processed++;
						if ( null !== $result && $result['is_new'] ) {
							$new_links[] = array(
								'id'       => $node_payload['uuid'],
								// The core's bulk JSON endpoint requires `type` on every item,
								// update included; this is real data already known from the
								// same payload, not a fabricated value.
								'type'     => self::node_type( $node_payload ),
								'sku_list' => Links::merge_sku_list(
									isset( $node_payload['sku_list'] ) ? $node_payload['sku_list'] : array(),
									null,
									$result['wc_id']
								),
							);
						}
					} catch ( \Exception $exception ) {
						$errors[] = $exception->getMessage();
					}
				}
			}
		}

		if ( ! empty( $new_links ) ) {
			foreach ( array_chunk( $new_links, 500 ) as $link_chunk ) {
				$link_response = $client->request( 'POST', Routes::products_bulk_json(), array( 'products' => $link_chunk ) );
				if ( ! $link_response['ok'] ) {
					$errors[] = 'link_back_failed: ' . (string) $link_response['error'];
				}
			}
		}

		return array(
			'processed'   => $processed,
			'next_cursor' => $page,
			'done'        => false,
			'total'       => $total,
			'errors'      => $errors,
		);
	}

	/**
	 * @return array<string,array<string,mixed>> product_uuid => inventory row
	 */
	private function fetch_inventory_page( Client $client, $page ) {
		$response = $client->request(
			'GET',
			Routes::inventories_simple_export_json(),
			array(),
			array( 'limit' => self::PAGE_SIZE, 'page' => $page )
		);

		if ( ! $response['ok'] ) {
			return array();
		}

		$rows = isset( $response['body']['rows'] ) ? $response['body']['rows'] : array();
		$by_uuid = array();
		foreach ( $rows as $row ) {
			if ( isset( $row['product_uuid'] ) ) {
				$by_uuid[ $row['product_uuid'] ] = $row;
			}
		}
		return $by_uuid;
	}

	/**
	 * Creates or updates the WooCommerce object for one Cachicamo product node (simple, variable
	 * parent, variation or combo) and its stock/price, honoring sync_fields, content_source and
	 * stock_source. Shared by the batch run and by the incremental webhook import.
	 *
	 * @param array<string,mixed>                 $product_payload
	 * @param array<string,array<string,mixed>>   $inventory_by_uuid
	 * @return array{wc_id:int,is_new:bool}|null
	 */
	/**
	 * Pure. The ProductBulkJSONItem `type` for one Cachicamo product node, from the same fields
	 * upsert_product() branches on.
	 *
	 * @return string SIMPLE|VARIABLE|VARIATION|COMBO
	 */
	public static function node_type( array $product_payload ) {
		if ( ! empty( $product_payload['is_combo'] ) ) {
			return 'COMBO';
		}
		if ( ! empty( $product_payload['parent_uuid'] ) ) {
			return 'VARIATION';
		}
		if ( ! empty( $product_payload['with_variations'] ) ) {
			return 'VARIABLE';
		}
		return 'SIMPLE';
	}

	public function upsert_product( array $product_payload, array $inventory_by_uuid = array() ) {
		if ( ! isset( $product_payload['uuid'] ) ) {
			return null;
		}

		$uuid           = (string) $product_payload['uuid'];
		$sync_fields    = Repository::get( 'sync_fields', array() );
		$content_source = Repository::get( 'content_source', 'cachicamo' );
		$stock_source   = Repository::get( 'stock_source', 'cachicamo' );

		if ( 'cachicamo' !== $content_source ) {
			return null;
		}

		$is_combo    = ! empty( $product_payload['is_combo'] );
		$is_variant  = ! empty( $product_payload['parent_uuid'] );
		$is_variable = ! $is_combo && ! $is_variant && ! empty( $product_payload['with_variations'] );

		$existing_wc_id = Links::get_wc_id( $uuid );
		$is_new         = null === $existing_wc_id;

		if ( $is_combo ) {
			$wc_id = $this->upsert_simple_product( $existing_wc_id, $product_payload, $sync_fields, true );
		} elseif ( $is_variant ) {
			$parent_wc_id = Links::get_wc_id( (string) $product_payload['parent_uuid'] );
			if ( null === $parent_wc_id ) {
				return null;
			}
			$wc_id = $this->upsert_variation( $existing_wc_id, $parent_wc_id, $product_payload, $sync_fields );
		} elseif ( $is_variable ) {
			$wc_id = $this->upsert_variable_product( $existing_wc_id, $product_payload, $sync_fields );
		} else {
			$wc_id = $this->upsert_simple_product( $existing_wc_id, $product_payload, $sync_fields, false );
		}

		if ( null === $wc_id ) {
			return null;
		}

		Links::link( $wc_id, $uuid, $is_combo ? Links::KIND_COMBO : ( $is_variant ? Links::KIND_VARIATION : Links::KIND_PRODUCT ) );

		if ( in_array( 'images', $sync_fields, true ) && ! empty( $product_payload['images'] ) && is_array( $product_payload['images'] ) ) {
			$wc_product = wc_get_product( $wc_id );
			if ( $wc_product ) {
				Images::apply_import( $wc_product, $product_payload['images'] );
			}
		}

		if ( $is_combo && isset( $product_payload['combo_items'] ) && is_array( $product_payload['combo_items'] ) ) {
			Combos::set_components( $uuid, $product_payload['combo_items'] );
			Combos::recalculate( $uuid );
		}

		if ( 'cachicamo' === $stock_source && ! $is_combo && isset( $inventory_by_uuid[ $uuid ] ) ) {
			$billable = Stock::billable_from_payload( $inventory_by_uuid[ $uuid ] );
			if ( null !== $billable ) {
				Stock::apply( $wc_id, $billable );
				foreach ( Combos::combos_using( $uuid ) as $combo_uuid ) {
					Combos::recalculate( $combo_uuid );
				}
			}
		}

		return array( 'wc_id' => $wc_id, 'is_new' => $is_new );
	}

	private function upsert_simple_product( $wc_id, array $payload, array $sync_fields, $is_combo ) {
		$product = null !== $wc_id ? wc_get_product( $wc_id ) : null;
		if ( ! $product ) {
			$product = new \WC_Product_Simple();
		}

		if ( $is_combo ) {
			$product->set_virtual( true );
		} elseif ( isset( $payload['is_virtual'] ) ) {
			$product->set_virtual( (bool) $payload['is_virtual'] );
		}

		$this->apply_common_fields( $product, $payload, $sync_fields );

		$product->save();

		return $product->get_id();
	}

	private function upsert_variable_product( $wc_id, array $payload, array $sync_fields ) {
		$product = null !== $wc_id ? wc_get_product( $wc_id ) : null;
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			$product = new \WC_Product_Variable();
		}

		$this->apply_common_fields( $product, $payload, $sync_fields );

		$product->save();

		return $product->get_id();
	}

	private function upsert_variation( $wc_id, $parent_wc_id, array $payload, array $sync_fields ) {
		$variation = null !== $wc_id ? wc_get_product( $wc_id ) : null;
		if ( ! $variation ) {
			$variation = new \WC_Product_Variation();
			$variation->set_parent_id( $parent_wc_id );
		}

		$this->apply_common_fields( $variation, $payload, $sync_fields );

		if ( in_array( 'attributes', $sync_fields, true ) && isset( $payload['attributes'] ) && is_array( $payload['attributes'] ) ) {
			$this->apply_variation_attributes( $parent_wc_id, $variation, $payload['attributes'] );
		}

		if ( in_array( 'stock', $sync_fields, true ) ) {
			$variation->set_manage_stock( true );
		}

		$variation->save();

		return $variation->get_id();
	}

	/**
	 * Maps one Cachicamo attribute list ({ attribute_group: { name_group }, attributes: [{
	 * name_attribute }] }) onto the WooCommerce variation, registering each group as a global
	 * `pa_*` attribute on the parent (one term per distinct value, flagged for variations) the
	 * first time it's seen.
	 *
	 * @param int                       $parent_wc_id
	 * @param \WC_Product_Variation     $variation
	 * @param array<int,array<string,mixed>> $cachicamo_attributes
	 */
	private function apply_variation_attributes( $parent_wc_id, $variation, array $cachicamo_attributes ) {
		$parent = wc_get_product( $parent_wc_id );
		if ( ! $parent ) {
			return;
		}

		$variation_attributes = array();
		$parent_attributes    = $parent->get_attributes();
		$parent_changed       = false;

		foreach ( $cachicamo_attributes as $entry ) {
			$group_name = isset( $entry['attribute_group']['name_group'] ) ? $entry['attribute_group']['name_group'] : null;
			$values     = isset( $entry['attributes'] ) && is_array( $entry['attributes'] ) ? $entry['attributes'] : array();
			$value_name = isset( $values[0]['name_attribute'] ) ? $values[0]['name_attribute'] : null;

			if ( null === $group_name || null === $value_name ) {
				continue;
			}

			$taxonomy = Attributes::ensure_global_attribute( $group_name );
			if ( null === $taxonomy ) {
				continue;
			}

			$term_slug = Attributes::ensure_term( $taxonomy, $value_name );
			if ( null === $term_slug ) {
				continue;
			}

			wp_set_object_terms( $parent_wc_id, $term_slug, $taxonomy, true );

			if ( ! isset( $parent_attributes[ $taxonomy ] ) ) {
				$attribute = new \WC_Product_Attribute();
				// A taxonomy attribute is only recognized as one (`is_taxonomy` = 1, options
				// read back as term ids) when its id points at the registered `pa_*` taxonomy;
				// without it WooCommerce stores the options as literal strings instead.
				$attribute->set_id( wc_attribute_taxonomy_id_by_name( $taxonomy ) );
				$attribute->set_name( $taxonomy );
				$attribute->set_options( wp_get_object_terms( $parent_wc_id, $taxonomy, array( 'fields' => 'ids' ) ) );
				$attribute->set_position( count( $parent_attributes ) );
				$attribute->set_visible( true );
				$attribute->set_variation( true );
				$parent_attributes[ $taxonomy ] = $attribute;
				$parent_changed                 = true;
			} else {
				$options = $parent_attributes[ $taxonomy ]->get_options();
				$term    = get_term_by( 'slug', $term_slug, $taxonomy );
				if ( $term instanceof \WP_Term && ! in_array( $term->term_id, $options, true ) ) {
					$options[] = $term->term_id;
					$parent_attributes[ $taxonomy ]->set_options( $options );
					$parent_changed = true;
				}
			}

			$variation_attributes[ $taxonomy ] = $term_slug;
		}

		if ( $parent_changed ) {
			$parent->set_attributes( array_values( $parent_attributes ) );
			$parent->save();
		}

		if ( ! empty( $variation_attributes ) ) {
			$variation->set_attributes( $variation_attributes );
		}
	}

	private function apply_common_fields( $product, array $payload, array $sync_fields ) {
		if ( in_array( 'name', $sync_fields, true ) && isset( $payload['name'] ) ) {
			$product->set_name( $payload['name'] );
		}
		if ( in_array( 'description', $sync_fields, true ) && isset( $payload['description'] ) ) {
			$product->set_description( (string) $payload['description'] );
		}
		if ( in_array( 'status', $sync_fields, true ) ) {
			$active = ! isset( $payload['active'] ) || $payload['active'];
			$product->set_status( $active ? 'publish' : 'draft' );
		}
		if ( in_array( 'dimensions', $sync_fields, true ) ) {
			if ( isset( $payload['weight'] ) ) {
				$product->set_weight( $payload['weight'] );
			}
			if ( isset( $payload['width'] ) ) {
				$product->set_width( $payload['width'] );
			}
			if ( isset( $payload['height'] ) ) {
				$product->set_height( $payload['height'] );
			}
		}
		if ( in_array( 'price', $sync_fields, true ) && isset( $payload['price'] ) && is_array( $payload['price'] ) ) {
			$price_type = Repository::get( 'price_type', 'retail' );
			$amount     = isset( $payload['price'][ $price_type ] ) ? (float) $payload['price'][ $price_type ] : null;
			if ( null !== $amount ) {
				$product->set_regular_price( (string) $amount );
				$product->update_meta_data(
					'_cachicamo_price_source',
					array(
						'amount'       => $amount,
						'currency_iso' => isset( $payload['price']['currency_iso'] ) ? $payload['price']['currency_iso'] : null,
					)
				);
			}
		}
		if ( in_array( 'categories', $sync_fields, true ) && isset( $payload['category_primary_uuid'] ) ) {
			$term_id = Categories::term_for_uuid( $payload['category_primary_uuid'] );
			if ( null !== $term_id && is_a( $product, 'WC_Product' ) && ! is_a( $product, 'WC_Product_Variation' ) ) {
				$product->set_category_ids( array( $term_id ) );
				$product->update_meta_data( '_cachicamo_primary_category_uuid', $payload['category_primary_uuid'] );
			}
		}
	}
}
