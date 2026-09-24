<?php

namespace Cachicamo\WooCommerce\Catalog;

use Cachicamo\WooCommerce\Api\Routes;
use Cachicamo\WooCommerce\Jobs\RunHandler;
use Cachicamo\WooCommerce\Plugin;
use Cachicamo\WooCommerce\Pricing\TaxCatalog;
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
			if ( isset( $product_data['linked_uuid'] ) && null !== $product_data['linked_uuid'] ) {
				$product_data['current_price'] = self::fetch_current_price( $client, $product_data['linked_uuid'] );
			}
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
			'tax_percentage'     => self::tax_percentage_for( $product ),
		);
	}

	/**
	 * A WooCommerce tax class only carries a Cachicamo tax percentage when it was created by
	 * TaxCatalog::sync_woocommerce_tax_classes() for one of the account's IVA taxes; any other
	 * class (including WooCommerce's own "Standard") has no Cachicamo tax to report, so the
	 * item is sent without tax_percentage and the core's own validation rejects it.
	 *
	 * @return float|null
	 */
	private static function tax_percentage_for( $product ) {
		if ( 'taxable' !== $product->get_tax_status() ) {
			return null;
		}

		$tax_class = $product->get_tax_class();
		foreach ( TaxCatalog::all() as $tax ) {
			if ( sanitize_title( TaxCatalog::tax_class_name( $tax ) ) === $tax_class ) {
				return $tax['tax_rate'];
			}
		}
		return null;
	}

	/**
	 * Pure (no I/O). Maps one WooCommerce product row to a ProductBulkJSONItem, keeping every
	 * SKU already in Cachicamo (Links::merge_sku_list). Price is present-or-absent as a whole on
	 * the core's contract: a product already linked to Cachicamo keeps its other two tiers as
	 * they are today (`current_price`, read by the caller from `GET /prices/product/{uuid}`
	 * before this is called) and only the configured `price_type` tier mirrors WooCommerce; a
	 * product not linked yet has no prior tiers to preserve, so all three start equal.
	 *
	 * @param array<string,mixed> $product_data {..., linked_uuid?, current_price?:array{base,wholesale,retail,currency_iso}}
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

		$current_price = isset( $product_data['current_price'] ) && is_array( $product_data['current_price'] )
			? $product_data['current_price']
			: null;

		$price_tiers = null !== $current_price
			? array(
				'base'      => $current_price['base'],
				'wholesale' => $current_price['wholesale'],
				'retail'    => $current_price['retail'],
			)
			: array(
				'base'      => $price,
				'wholesale' => $price,
				'retail'    => $price,
			);
		$price_tiers[ $price_type ] = $price;

		$type = isset( $product_data['is_variation'] ) && $product_data['is_variation']
			? 'VARIATION'
			: ( isset( $product_data['is_variable'] ) && $product_data['is_variable'] ? 'VARIABLE' : 'SIMPLE' );

		$item = array(
			'type'     => $type,
			'sku_list' => $sku_list,
			'name'     => isset( $product_data['name'] ) ? $product_data['name'] : '',
			'active'   => isset( $product_data['active'] ) ? (bool) $product_data['active'] : true,
		);

		// A VARIABLE parent has no unit price of its own -- its variations carry it -- so the
		// item omits price entirely instead of sending a fabricated 0, which the core's price
		// batch would otherwise create as a real (and wrong) price row.
		if ( 'VARIABLE' !== $type ) {
			$item['price'] = $price_tiers;
			if ( ! empty( $context['currency_iso'] ) ) {
				$item['price']['currency_iso'] = $context['currency_iso'];
			}
		}

		if ( null !== $linked_uuid ) {
			$item['id'] = $linked_uuid;
		}
		if ( isset( $product_data['tax_percentage'] ) && null !== $product_data['tax_percentage'] ) {
			$item['tax_percentage'] = $product_data['tax_percentage'];
		}

		return $item;
	}

	/**
	 * @return array{base:float,wholesale:float,retail:float}|null
	 */
	private static function fetch_current_price( $client, $product_uuid ) {
		$response = $client->request( 'GET', Routes::prices_product( $product_uuid ) );
		if ( ! $response['ok'] || ! isset( $response['body']['amount_base'] ) ) {
			return null;
		}

		$body = $response['body'];
		return array(
			'base'      => ( (float) $body['amount_base'] ) / 10000,
			'wholesale' => ( (float) $body['amount_min_sale'] ) / 10000,
			'retail'    => ( (float) $body['amount_retail'] ) / 10000,
		);
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
		$decimals = 2;
		$client   = Plugin::instance()->service( 'api_client' );
		if ( null !== $client ) {
			$response = $client->request( 'GET', Routes::users_configuration() );
			if ( $response['ok'] && isset( $response['body']['unit_price_decimals'] ) ) {
				$decimals = (int) $response['body']['unit_price_decimals'];
			}
		}

		return array(
			'price_type'          => Repository::get( 'price_type', 'retail' ),
			'unit_price_decimals' => $decimals,
			'currency_iso'        => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : null,
		);
	}
}
