<?php

namespace Cachicamo\WooCommerce\Billing;

use Cachicamo\WooCommerce\Api\Routes;
use Cachicamo\WooCommerce\Plugin;
use Cachicamo\WooCommerce\Settings\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Wires billing_mode = external_order: two native WC_Webhook rows push every order.created and
 * order.updated to the core's own WooCommerce receiver, and the plugin only has to close the
 * loop back onto the WordPress order once the core turns that payload into an invoice.
 */
class ExternalOrder {

	const OPTION_WEBHOOK_IDS  = 'cachicamoapp_external_order_webhooks';
	const SWEEP_HOOK          = 'cachicamoapp_link_sweep';
	const SWEEP_BATCH         = 50;
	const SWEEP_LOOKBACK_DAYS = 60;
	const RESEND_ACTION       = 'cachicamoapp_resend_external_order';

	public static function register_hooks() {
		add_filter( 'cron_schedules', array( __CLASS__, 'register_cron_schedule' ) );
		add_filter( 'woocommerce_webhook_should_deliver', array( __CLASS__, 'should_deliver' ), 10, 3 );
		add_action( self::SWEEP_HOOK, array( __CLASS__, 'run_link_sweep' ) );
		add_action( 'admin_post_' . self::RESEND_ACTION, array( __CLASS__, 'handle_resend' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_provision_webhooks' ) );

		if ( ! wp_next_scheduled( self::SWEEP_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'cachicamoapp_every_minute', self::SWEEP_HOOK );
		}

		\Cachicamo\WooCommerce\Webhooks\Dispatcher::on( 'document', array( __CLASS__, 'handle_document_event' ) );
	}

	public static function register_cron_schedule( $schedules ) {
		$schedules['cachicamoapp_every_minute'] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Every minute (Cachicamo external order sweep)', 'cachicamoapp-for-woo' ),
		);
		return $schedules;
	}

	/**
	 * Runs once, the first time the account is set to external_order: an already-provisioned
	 * pair of webhook ids is left untouched, since deleting and recreating them would hand every
	 * WooCommerce install a URL to update by hand.
	 */
	public static function maybe_provision_webhooks() {
		if ( 'external_order' !== Repository::get( 'billing_mode', '' ) ) {
			return;
		}
		if ( ! empty( get_option( self::OPTION_WEBHOOK_IDS ) ) ) {
			return;
		}
		self::provision_webhooks();
	}

	/**
	 * @return array{ok:bool,error:?string}
	 */
	public static function provision_webhooks() {
		if ( ! class_exists( '\\WC_Webhook' ) ) {
			return array(
				'ok'    => false,
				'error' => 'woocommerce_unavailable',
			);
		}

		$client = Plugin::instance()->service( 'api_client' );
		if ( null === $client ) {
			return array(
				'ok'    => false,
				'error' => 'unavailable',
			);
		}

		$key_result = $client->request( 'GET', Routes::webhooks_store_key() );
		if ( ! $key_result['ok'] || empty( $key_result['body']['woocommerce_url'] ) ) {
			return array(
				'ok'    => false,
				'error' => $key_result['error'],
			);
		}

		$delivery_url = $key_result['body']['woocommerce_url'];
		$secret       = wp_generate_password( 32, false );
		$user_id      = self::webhook_user_id();
		$webhook_ids  = array();

		foreach ( array( 'order.created', 'order.updated' ) as $topic ) {
			$webhook = new \WC_Webhook();
			$webhook->set_name( 'Cachicamo - ' . $topic );
			$webhook->set_topic( $topic );
			$webhook->set_delivery_url( $delivery_url );
			$webhook->set_secret( $secret );
			$webhook->set_api_version( 'wp_api_v3' );
			$webhook->set_user_id( $user_id );
			$webhook->set_status( 'active' );
			$webhook->save();
			$webhook_ids[ $topic ] = $webhook->get_id();
		}

		update_option( self::OPTION_WEBHOOK_IDS, $webhook_ids, false );
		Repository::set( 'external_order_webhook_secret', $secret );

		// The core only verifies a WooCommerce webhook's signature against this same value once
		// it lives in stores.extra_fields.woocommerce_webhook_secret; until this PUT succeeds the
		// webhooks above still deliver and the core still ingests them (an unconfigured secret is
		// accepted as-is), only the signature check stays off.
		$store_uuid    = Repository::get( 'store_uuid', '' );
		$secret_result = '' !== $store_uuid
			? $client->request( 'PUT', Routes::stores_webhook_secret( $store_uuid ), array( 'secret' => $secret ) )
			: array( 'ok' => false );

		if ( ! $secret_result['ok'] ) {
			return array(
				'ok'    => true,
				'error' => 'secret_not_persisted_on_core',
			);
		}

		return array(
			'ok'    => true,
			'error' => null,
		);
	}

	/**
	 * Only orders past start_order_number and in a trigger status leave WordPress: a webhook
	 * firing for every draft and cancelled order would flood the core with nothing to bill.
	 */
	public static function should_deliver( $should_deliver, $webhook, $arg ) {
		if ( ! $should_deliver ) {
			return $should_deliver;
		}
		if ( 'external_order' !== Repository::get( 'billing_mode', '' ) ) {
			return $should_deliver;
		}
		if ( ! in_array( $webhook->get_topic(), array( 'order.created', 'order.updated' ), true ) ) {
			return $should_deliver;
		}

		$order = wc_get_order( $arg );
		if ( ! $order instanceof \WC_Order ) {
			return false;
		}

		$order_number = (int) $order->get_order_number();
		if ( $order_number <= (int) Repository::get( 'start_order_number', 0 ) ) {
			return false;
		}

		$trigger_statuses = Repository::get( 'trigger_statuses', array() );
		return in_array( 'wc-' . $order->get_status(), $trigger_statuses, true );
	}

	public static function handle_resend() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'cachicamoapp-for-woo' ) );
		}

		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		check_admin_referer( self::RESEND_ACTION . '_' . $order_id );

		$webhook_ids = get_option( self::OPTION_WEBHOOK_IDS, array() );
		$webhook_id  = isset( $webhook_ids['order.updated'] ) ? (int) $webhook_ids['order.updated'] : 0;
		if ( $webhook_id > 0 && $order_id > 0 && class_exists( '\\WC_Webhook' ) ) {
			$webhook = new \WC_Webhook( $webhook_id );
			$webhook->deliver( $order_id );
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=shop_order' ) );
		exit;
	}

	/**
	 * document.created carries external_request_uuid only when the invoice was created from a
	 * pending external_invoice_request; a document billed by hand outside that flow carries none
	 * and is none of this class's business.
	 */
	public static function handle_document_event( $action, array $payload ) {
		if ( 'document.created' !== $action ) {
			return;
		}
		if ( 'external_order' !== Repository::get( 'billing_mode', '' ) ) {
			return;
		}
		if ( ! isset( $payload['provider'] ) || 'woocommerce' !== $payload['provider'] ) {
			return;
		}
		if ( isset( $payload['store_uuid'] ) && $payload['store_uuid'] !== Repository::get( 'store_uuid', '' ) ) {
			return;
		}
		$request_uuid = isset( $payload['external_request_uuid'] ) ? (string) $payload['external_request_uuid'] : '';
		$invoice_uuid = isset( $payload['id'] ) ? (string) $payload['id'] : '';
		if ( '' === $request_uuid || '' === $invoice_uuid ) {
			return;
		}

		self::link_from_request_uuid( $request_uuid, $invoice_uuid );
	}

	/**
	 * Orders in the marked status without `_cachicamo_invoice_uuid` from the last 60 days: the
	 * fallback path for when the notice webhook never arrives. Processed at 50 orders per minute.
	 */
	public static function run_link_sweep() {
		if ( 'external_order' !== Repository::get( 'billing_mode', '' ) ) {
			return;
		}

		$trigger_statuses = array_map(
			static function ( $status ) {
				return substr( $status, 0, 3 ) === 'wc-' ? substr( $status, 3 ) : $status;
			},
			Repository::get( 'trigger_statuses', array() )
		);
		if ( empty( $trigger_statuses ) ) {
			return;
		}

		$orders = wc_get_orders(
			array(
				'status'       => $trigger_statuses,
				'limit'        => self::SWEEP_BATCH,
				'date_created' => '>' . ( time() - self::SWEEP_LOOKBACK_DAYS * DAY_IN_SECONDS ),
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- point lookup on a single well-known meta key, batch capped by SWEEP_BATCH.
				'meta_query'   => array(
					array(
						'key'     => '_cachicamo_invoice_uuid',
						'compare' => 'NOT EXISTS',
					),
				),
				'return'       => 'ids',
			)
		);

		$client = Plugin::instance()->service( 'api_client' );
		if ( null === $client ) {
			return;
		}

		foreach ( $orders as $order_id ) {
			$result = $client->request(
				'GET',
				Routes::external_invoice_requests(),
				array(),
				array(
					'filter[provider]'           => 'woocommerce',
					'filter[external_reference]' => (string) $order_id,
				)
			);
			if ( ! $result['ok'] ) {
				continue;
			}

			$rows = isset( $result['body']['rows'] ) && is_array( $result['body']['rows'] )
				? $result['body']['rows']
				: array();
			foreach ( $rows as $row ) {
				if ( ! isset( $row['status'], $row['invoice_uuid'] ) || 'created' !== $row['status'] ) {
					continue;
				}
				self::link_order_with_invoice( $order_id, (string) $row['invoice_uuid'] );
				break;
			}
		}
	}

	/**
	 * WC_Webhook::build_payload() runs its REST callback as this user; a webhook created outside
	 * a logged-in admin request (WP-CLI, a cron-triggered provisioning retry) would otherwise
	 * carry user_id 0 and every delivery would 401.
	 */
	private static function webhook_user_id() {
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			return $user_id;
		}
		$admins = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'fields'  => 'ID',
			)
		);
		return ! empty( $admins ) ? (int) $admins[0] : 0;
	}

	private static function link_from_request_uuid( $request_uuid, $invoice_uuid ) {
		$client = Plugin::instance()->service( 'api_client' );
		if ( null === $client ) {
			return;
		}

		$request = $client->request( 'GET', Routes::external_invoice_requests_uuid( $request_uuid ) );
		if ( ! $request['ok'] || empty( $request['body']['external_reference'] ) ) {
			return;
		}
		if ( isset( $request['body']['provider'] ) && 'woocommerce' !== $request['body']['provider'] ) {
			return;
		}

		$order_id = absint( $request['body']['external_reference'] );
		if ( $order_id <= 0 ) {
			return;
		}

		self::link_order_with_invoice( $order_id, $invoice_uuid );
	}

	private static function link_order_with_invoice( $order_id, $invoice_uuid ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		if ( ! empty( $order->get_meta( '_cachicamo_invoice_uuid' ) ) ) {
			return;
		}

		$client = Plugin::instance()->service( 'api_client' );
		if ( null === $client ) {
			return;
		}
		$document = $client->request( 'GET', Routes::documents_by_uuid( $invoice_uuid ) );
		if ( ! $document['ok'] ) {
			return;
		}

		$order->update_meta_data( '_cachicamo_invoice_uuid', $invoice_uuid );
		if ( isset( $document['body']['document_number'] ) ) {
			$order->update_meta_data( '_cachicamo_document_number', $document['body']['document_number'] );
		}
		if ( isset( $document['body']['manual_control_number'] ) ) {
			$order->update_meta_data( '_cachicamo_control_number', $document['body']['manual_control_number'] );
		}
		$order->update_meta_data( '_cachicamo_state', 'issued' );
		$order->delete_meta_data( '_cachicamo_error' );
		$order->add_order_note( __( 'Cachicamo invoice linked.', 'cachicamoapp-for-woo' ) );
		$order->save();
	}
}
