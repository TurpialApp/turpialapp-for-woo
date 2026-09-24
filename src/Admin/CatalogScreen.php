<?php

namespace Cachicamo\WooCommerce\Admin;

use Cachicamo\WooCommerce\Catalog\CategoriesExport;
use Cachicamo\WooCommerce\Catalog\ExportFlow;
use Cachicamo\WooCommerce\Catalog\Preview;
use Cachicamo\WooCommerce\Jobs\BatchRunner;
use Cachicamo\WooCommerce\Settings\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Catalog screen: preview and first-load buttons per flow (import, export, categories,
 * attributes). A first load only unlocks in the admin UI once its preview ran; the server side
 * enforces nothing beyond requiring manage_woocommerce, since the wizard state already gates
 * page access.
 */
class CatalogScreen {

	const NAMESPACE = 'cachicamoapp/v1';
	const PAGE_SLUG = 'cachicamoapp-catalog';

	public static function register_hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_page() {
		add_submenu_page(
			Menu::PAGE_SLUG,
			__( 'Catalog', 'cachicamoapp-for-woo' ),
			__( 'Catalog', 'cachicamoapp-for-woo' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function render() {
		echo '<div id="cachicamoapp-catalog-root"></div>';
	}

	public static function enqueue( $hook ) {
		if ( 'cachicamo-app_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		$asset_path = CACHICAMO_APP_DIR . 'assets/build/catalog.asset.php';
		$asset      = file_exists( $asset_path )
			? require $asset_path
			: array(
				'dependencies' => array( 'wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch' ),
				'version'      => CACHICAMO_APP_VERSION,
			);

		wp_enqueue_script(
			'cachicamoapp-catalog',
			CACHICAMO_APP_URL . 'assets/build/catalog.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_set_script_translations( 'cachicamoapp-catalog', 'cachicamoapp-for-woo', CACHICAMO_APP_DIR . 'languages' );

		if ( file_exists( CACHICAMO_APP_DIR . 'assets/build/catalog.css' ) ) {
			wp_enqueue_style( 'cachicamoapp-catalog', CACHICAMO_APP_URL . 'assets/build/catalog.css', array( 'wp-components' ), $asset['version'] );
		}

		wp_localize_script(
			'cachicamoapp-catalog',
			'cachicamoAppCatalog',
			array(
				'restUrl' => esc_url_raw( rest_url( self::NAMESPACE ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/catalog/preview/(?P<flow>export_products|export_categories|export_attributes)',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'preview' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/catalog/run/(?P<flow>export_products|export_categories)',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'run' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/catalog/state/(?P<flow>export_products|export_categories)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'state' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);
	}

	public static function can_manage() {
		return current_user_can( 'manage_woocommerce' );
	}

	public static function preview( \WP_REST_Request $request ) {
		$flow = $request->get_param( 'flow' );

		switch ( $flow ) {
			case 'export_products':
				$products = function_exists( 'wc_get_products' ) ? wc_get_products( array( 'limit' => -1 ) ) : array();
				$rows     = array();
				foreach ( $products as $product ) {
					$rows[] = array(
						'id'  => $product->get_id(),
						'sku' => $product->get_sku(),
					);
				}
				$result = Preview::run_export_products( $rows );
				break;

			case 'export_categories':
				$result = Preview::evaluate_categories( CategoriesExport::flattened_items() );
				break;

			default:
				return new \WP_REST_Response( array( 'message' => __( 'Unknown flow.', 'cachicamoapp-for-woo' ) ), 400 );
		}

		return new \WP_REST_Response( $result, 200 );
	}

	public static function run( \WP_REST_Request $request ) {
		$flow = $request->get_param( 'flow' );

		$first_load_done = Repository::get( 'first_load_done', array() );
		$key             = 'export_products' === $flow ? 'export' : 'categories';

		if ( 'export_products' === $flow ) {
			BatchRunner::register_handler( ExportFlow::RUN_TYPE, new ExportFlow() );
			BatchRunner::start( ExportFlow::RUN_TYPE, ExportFlow::export_context() );
		} else {
			BatchRunner::register_handler( CategoriesExport::RUN_TYPE, new CategoriesExport() );
			BatchRunner::start( CategoriesExport::RUN_TYPE, array() );
		}

		$first_load_done[ $key ] = true;
		Repository::set( 'first_load_done', $first_load_done );

		return new \WP_REST_Response( array( 'ok' => true ), 200 );
	}

	public static function state( \WP_REST_Request $request ) {
		$flow     = $request->get_param( 'flow' );
		$run_type = 'export_products' === $flow ? ExportFlow::RUN_TYPE : CategoriesExport::RUN_TYPE;
		$state    = BatchRunner::state( $run_type );

		return new \WP_REST_Response( null === $state ? array( 'status' => BatchRunner::STATUS_IDLE ) : $state, 200 );
	}
}
