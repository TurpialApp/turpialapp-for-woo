<?php

namespace Cachicamo\WooCommerce\Admin;

use Cachicamo\WooCommerce\Account\Reachability;
use Cachicamo\WooCommerce\Account\Status;
use Cachicamo\WooCommerce\Api\Routes;
use Cachicamo\WooCommerce\Plugin;
use Cachicamo\WooCommerce\Settings\Repository;
use Cachicamo\WooCommerce\Webhooks\Subscriptions;

defined( 'ABSPATH' ) || exit;

/**
 * REST surface the admin screens (connection wizard, settings, payment mapping) call. Every
 * route requires manage_woocommerce; api_token never round-trips back to the browser once set.
 */
class Rest {

	const NAMESPACE = 'cachicamoapp/v1';

	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/admin/state',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_state' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/wizard/token',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'wizard_token' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/wizard/stores',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'wizard_stores' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/wizard/store',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'wizard_store' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/wizard/reachability',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'wizard_reachability' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/wizard/printer-documents',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'printer_documents' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/wizard/webhooks',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'wizard_webhooks' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_settings' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'save_settings' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/payment-gateways',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'payment_gateways' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);
	}

	public static function can_manage() {
		return current_user_can( 'manage_woocommerce' );
	}

	public static function get_state() {
		return new \WP_REST_Response(
			array(
				'connected'     => '' !== Repository::get( 'store_uuid', '' ),
				'account'       => Status::get(),
				'reachability'  => array( 'passed' => Reachability::has_passed() ),
				'route_error'   => (bool) get_option( 'cachicamoapp_route_error', false ),
				'settings'      => self::public_settings(),
			),
			200
		);
	}

	public static function wizard_token( \WP_REST_Request $request ) {
		$token = (string) $request->get_param( 'api_token' );
		if ( '' === $token ) {
			return new \WP_REST_Response( array( 'message' => __( 'A token is required.', 'cachicamoapp-for-woo' ) ), 400 );
		}

		Repository::set( 'api_token', $token );

		$account = Status::refresh();

		if ( get_option( 'cachicamoapp_route_error', false ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'Could not reach Cachicamo. Check the token or write to support.', 'cachicamoapp-for-woo' ) ), 401 );
		}

		return new \WP_REST_Response( array( 'account' => $account ), 200 );
	}

	public static function wizard_stores() {
		$client = Plugin::instance()->service( 'api_client' );
		$result = $client->request( 'GET', '/stores' );

		if ( ! $result['ok'] ) {
			return new \WP_REST_Response( array( 'message' => self::error_message( $result['error'] ) ), 400 );
		}

		return new \WP_REST_Response( array( 'stores' => $result['body'] ), 200 );
	}

	public static function wizard_store( \WP_REST_Request $request ) {
		$store_uuid = (string) $request->get_param( 'store_uuid' );
		if ( '' === $store_uuid ) {
			return new \WP_REST_Response( array( 'message' => __( 'A store is required.', 'cachicamoapp-for-woo' ) ), 400 );
		}

		$values = array( 'store_uuid' => $store_uuid );
		if ( '' === Repository::get( 'webhook_secret', '' ) ) {
			$values['webhook_secret'] = bin2hex( random_bytes( 32 ) );
		}
		Repository::set_many( $values );

		return new \WP_REST_Response( array( 'ok' => true ), 200 );
	}

	public static function wizard_reachability() {
		$result = Reachability::check();
		return new \WP_REST_Response( $result, $result['passed'] ? 200 : 400 );
	}

	public static function printer_documents() {
		$client = Plugin::instance()->service( 'api_client' );
		$result = $client->request( 'GET', Routes::printer_documents() );

		if ( ! $result['ok'] ) {
			return new \WP_REST_Response( array( 'message' => self::error_message( $result['error'] ) ), 400 );
		}

		$store_uuid = Repository::get( 'store_uuid', '' );
		$eligible   = array_values(
			array_filter(
				is_array( $result['body'] ) ? $result['body'] : array(),
				static function ( $document ) use ( $store_uuid ) {
					$is_digital  = isset( $document['device_type'] ) && 'digital' === $document['device_type'];
					$not_sandbox = empty( $document['device_setting']['sandbox'] );
					$assigned    = isset( $document['assigned'] ) && is_array( $document['assigned'] ) && in_array( $store_uuid, $document['assigned'], true );
					return $is_digital && $not_sandbox && $assigned;
				}
			)
		);

		return new \WP_REST_Response( array( 'printer_documents' => $eligible ), 200 );
	}

	public static function wizard_webhooks() {
		$results = Subscriptions::subscribe_all();
		return new \WP_REST_Response( array( 'subscriptions' => $results ), 200 );
	}

	public static function get_settings() {
		return new \WP_REST_Response( self::public_settings(), 200 );
	}

	public static function save_settings( \WP_REST_Request $request ) {
		$allowed = array(
			'billing_mode',
			'printer_document_uuid',
			'trigger_statuses',
			'start_order_number',
			'document_meta_key',
			'price_type',
			'stock_source',
			'content_source',
			'sync_fields',
			'image_master',
			'image_overwrite',
			'on_remote_delete',
			'payment_mapping',
			'free_line_tax_uuid',
		);

		$values = array();
		foreach ( $allowed as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$values[ $key ] = $request->get_param( $key );
			}
		}

		Repository::set_many( $values );

		return new \WP_REST_Response( self::public_settings(), 200 );
	}

	public static function payment_gateways() {
		$gateways = array();
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			foreach ( WC()->payment_gateways()->payment_gateways() as $gateway ) {
				$gateways[] = array(
					'id'      => $gateway->id,
					'title'   => $gateway->get_title(),
					'enabled' => 'yes' === $gateway->enabled,
				);
			}
		}
		return new \WP_REST_Response( array( 'gateways' => $gateways ), 200 );
	}

	private static function public_settings() {
		$settings = Repository::all();
		unset( $settings['api_token'], $settings['webhook_secret'] );
		return $settings;
	}

	private static function error_message( $error ) {
		$messages = array(
			'unauthorized'  => __( 'Could not connect to Cachicamo. Check your token or write to support.', 'cachicamoapp-for-woo' ),
			'expired'       => __( 'Your Cachicamo account is expired.', 'cachicamoapp-for-woo' ),
			'route_not_found' => __( 'Could not connect to Cachicamo. Write to support.', 'cachicamoapp-for-woo' ),
		);
		return isset( $messages[ $error ] ) ? $messages[ $error ] : __( 'Could not connect to Cachicamo. Try again in a moment.', 'cachicamoapp-for-woo' );
	}
}
