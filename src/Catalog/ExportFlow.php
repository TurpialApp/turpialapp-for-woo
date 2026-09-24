<?php

namespace Cachicamo\WooCommerce\Catalog;

use Cachicamo\WooCommerce\Api\Routes;
use Cachicamo\WooCommerce\Jobs\RunHandler;
use Cachicamo\WooCommerce\Plugin;
use Cachicamo\WooCommerce\Settings\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce products -> Cachicamo, via POST /products/bulk/json. One page of WooCommerce
 * products becomes one job; the next page never starts before that job answers COMPLETED or
 * FAILED, so this class keeps its own small pending-job option instead of relying on
 * BatchRunner's context, which the runner treats as read-only across calls.
 */
class ExportFlow implements RunHandler {

	const RUN_TYPE  = 'export_products';
	const PAGE_SIZE = 50;
	const OPTION_JOB = 'cachicamoapp_export_products_job';

	public function run_batch( $cursor, array $context ) {
		$client = Plugin::instance()->service( 'api_client' );
		$job    = get_option( self::OPTION_JOB, null );

		if ( is_array( $job ) ) {
			return self::poll_pending_job( $client, $job, $cursor );
		}

		$products = self::fetch_products( $cursor );
		if ( empty( $products ) ) {
			return array(
				'processed'   => 0,
				'next_cursor' => $cursor,
				'done'        => true,
			);
		}

		$items = array();
		foreach ( $products as $product_data ) {
			$items[] = self::build_product_item( $product_data, $context );
		}

		$response = $client->request( 'POST', Routes::products_bulk_json(), array( 'products' => $items ) );

		if ( ! $response['ok'] || ! isset( $response['body']['uuid'] ) ) {
			return array(
				'processed'   => 0,
				'next_cursor' => $cursor,
				'done'        => false,
				'errors'      => array( isset( $response['error'] ) ? $response['error'] : 'unknown_error' ),
				'retry_after' => 60,
			);
		}

		update_option(
			self::OPTION_JOB,
			array(
				'uuid'        => $response['body']['uuid'],
				'wc_ids'      => wp_list_pluck( $products, 'id' ),
				'next_cursor' => $cursor + count( $products ),
			),
			false
		);

		return array(
			'processed'   => 0,
			'next_cursor' => $cursor,
			'done'        => false,
			'retry_after' => 15,
		);
	}

	private static function poll_pending_job( $client, array $job, $cursor ) {
		$status = $client->request( 'GET', Routes::products_bulk_xlsx_status( $job['uuid'] ) );

		if ( ! $status['ok'] ) {
			return array(
				'processed'   => 0,
				'next_cursor' => $cursor,
				'done'        => false,
				'errors'      => array( isset( $status['error'] ) ? $status['error'] : 'unknown_error' ),
				'retry_after' => 30,
			);
		}

		$job_status = isset( $status['body']['status'] ) ? $status['body']['status'] : '';
		if ( 'PROCESSING' === $job_status ) {
			return array(
				'processed'   => 0,
				'next_cursor' => $cursor,
				'done'        => false,
				'retry_after' => 15,
			);
		}

		$errors = array();
		if ( 'COMPLETED' === $job_status ) {
			self::store_links_from_result( $job['wc_ids'], isset( $status['body']['result'] ) ? $status['body']['result'] : array() );
		} else {
			$errors[] = 'export job ' . $job['uuid'] . ' ended as ' . $job_status;
		}

		delete_option( self::OPTION_JOB );

		return array(
			'processed'   => count( $job['wc_ids'] ),
			'next_cursor' => $job['next_cursor'],
			'done'        => false,
			'errors'      => $errors,
			'retry_after' => 0,
		);
	}

	private static function fetch_products( $cursor ) {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}

		$products = wc_get_products(
			array(
				'limit'  => self::PAGE_SIZE,
				'offset' => $cursor,
				'status' => array( 'publish', 'draft', 'private' ),
				'type'   => array( 'simple', 'variable' ),
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);

		$rows = array();
		foreach ( $products as $product ) {
			$rows[] = self::product_to_row( $product );
		}
		return $rows;
	}

	private static function product_to_row( $product ) {
		return array(
			'id'                 => $product->get_id(),
			'name'               => $product->get_name(),
			'sku'                => $product->get_sku(),
			'active'             => 'publish' === $product->get_status(),
			'is_variable'        => $product->is_type( 'variable' ),
			'is_variation'       => $product->is_type( 'variation' ),
			'regular_price'      => $product->get_regular_price(),
			'sale_price'         => $product->get_sale_price(),
			'linked_uuid'        => Links::get_uuid( $product->get_id() ),
			'existing_sku_list'  => array(),
		);
	}

	/**
	 * Pure (no I/O). Maps one WooCommerce product row to a ProductBulkJSONItem, keeping every
	 * SKU already in Cachicamo (Links::merge_sku_list). Price is present-or-absent as a whole
	 * on the core's contract, so the two tiers WooCommerce doesn't know about mirror the one it
	 * does instead of being zeroed out.
	 *
	 * @param array<string,mixed> $product_data
	 * @param array<string,mixed> $context {price_type?, unit_price_decimals?, currency_iso?}
	 */
	public static function build_product_item( array $product_data, array $context ) {
		$wc_id       = $product_data['id'];
		$linked_uuid = isset( $product_data['linked_uuid'] ) ? $product_data['linked_uuid'] : null;
		$sku_list    = Links::merge_sku_list(
			isset( $product_data['existing_sku_list'] ) ? $product_data['existing_sku_list'] : array(),
			isset( $product_data['sku'] ) ? $product_data['sku'] : null,
			$wc_id
		);

		$price_type = isset( $context['price_type'] ) ? $context['price_type'] : 'retail';
		$decimals   = isset( $context['unit_price_decimals'] ) ? (int) $context['unit_price_decimals'] : 2;

		$sale_price = isset( $product_data['sale_price'] ) && '' !== $product_data['sale_price'] ? (float) $product_data['sale_price'] : null;
		$price      = null !== $sale_price ? $sale_price : ( isset( $product_data['regular_price'] ) ? (float) $product_data['regular_price'] : 0.0 );
		$price      = round( $price, $decimals );

		$item = array(
			'type'     => isset( $product_data['is_variation'] ) && $product_data['is_variation']
				? 'VARIATION'
				: ( isset( $product_data['is_variable'] ) && $product_data['is_variable'] ? 'VARIABLE' : 'SIMPLE' ),
			'sku_list' => $sku_list,
			'name'     => isset( $product_data['name'] ) ? $product_data['name'] : '',
			'active'   => isset( $product_data['active'] ) ? (bool) $product_data['active'] : true,
			'price'    => array(
				'base'      => $price,
				'wholesale' => $price,
				'retail'    => $price,
			),
		);
		$item['price'][ $price_type ] = $price;

		if ( null !== $linked_uuid ) {
			$item['id'] = $linked_uuid;
		}
		if ( ! empty( $context['currency_iso'] ) ) {
			$item['price']['currency_iso'] = $context['currency_iso'];
		}

		return $item;
	}

	private static function store_links_from_result( array $wc_ids, array $result_items ) {
		foreach ( $wc_ids as $index => $wc_id ) {
			if ( ! isset( $result_items[ $index ]['id'] ) ) {
				continue;
			}
			Links::link( $wc_id, $result_items[ $index ]['id'], Links::KIND_PRODUCT );
		}
	}

	public static function export_context() {
		return array(
			'price_type'          => Repository::get( 'price_type', 'retail' ),
			'unit_price_decimals' => 2,
		);
	}
}
