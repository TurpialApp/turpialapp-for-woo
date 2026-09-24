<?php

namespace Cachicamo\WooCommerce\Api;

defined( 'ABSPATH' ) || exit;

/**
 * The only list of Cachicamo core routes the plugin calls. No route is built anywhere else,
 * so a path change is a one-file edit and a grep here shows the plugin's full surface against
 * the core.
 */
class Routes {

	public static function me() {
		return '/users/me';
	}

	public static function site_reachability() {
		return '/integration/site_reachability';
	}

	public static function currencies_rates() {
		return '/currencies/rates';
	}

	public static function currencies_used() {
		return '/currencies/used';
	}

	public static function users_configuration() {
		return '/users/configuration';
	}

	public static function stores_configuration() {
		return '/stores/configuration';
	}

	public static function taxes() {
		return '/taxes';
	}

	public static function printer_documents() {
		return '/printer_documents';
	}

	public static function payment_methods() {
		return '/payment_methods';
	}

	public static function webhooks_config() {
		return '/webhooks/config';
	}

	public static function webhooks_config_action( $action ) {
		return '/webhooks/config/' . rawurlencode( $action );
	}

	public static function customers_search( $search ) {
		return '/customers/search/' . rawurlencode( $search );
	}

	public static function customers_email( $email ) {
		return '/customers/email/' . rawurlencode( $email );
	}

	public static function customers_create() {
		return '/customers';
	}

	public static function documents_preview() {
		return '/documents/preview';
	}

	public static function documents_save_preview() {
		return '/documents/save_preview';
	}

	public static function documents_by_uuid( $uuid ) {
		return '/documents/uuid/' . rawurlencode( $uuid );
	}

	public static function documents_digital_pdf( $uuid ) {
		return '/documents/digital_pdf/' . rawurlencode( $uuid );
	}

	public static function products( $page, $limit = 500 ) {
		return '/products';
	}

	public static function products_categories() {
		return '/products/categories';
	}

	public static function products_categories_bulk() {
		return '/products/categories/bulk';
	}

	public static function products_bulk_json() {
		return '/products/bulk/json';
	}

	public static function products_bulk_xlsx_status( $uuid ) {
		return '/products/bulk/xlsx/uuid/' . rawurlencode( $uuid );
	}

	public static function products_batch_sku() {
		return '/products/batch/sku';
	}

	public static function inventories_simple_export_json() {
		return '/inventories/simple/export/json';
	}

	public static function inventories_batch_sku() {
		return '/inventories/batch/sku';
	}

	public static function products_attribute_group() {
		return '/products/attribute_group';
	}

	public static function products_attribute_group_all() {
		return '/products/attribute_group/all';
	}

	public static function products_attribute( $attribute_group_uuid ) {
		return '/products/attribute/' . rawurlencode( $attribute_group_uuid );
	}

	public static function prices_product( $product_uuid ) {
		return '/prices/product/' . rawurlencode( $product_uuid );
	}
}
