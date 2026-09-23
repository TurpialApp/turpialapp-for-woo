<?php

namespace Cachicamo\WooCommerce\Account;

use Cachicamo\WooCommerce\Api\Routes;
use Cachicamo\WooCommerce\Plugin;
use Cachicamo\WooCommerce\Settings\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Mirrors GET /users/me into cachicamoapp_account. READY/PENDING/EXPIRED/TRIAL are the core's
 * own account states; a TRIAL past plan_expires_at is treated as EXPIRED so every other module
 * only has to ask is_expired().
 */
class Status {

	const OPTION_NAME = 'cachicamoapp_account';

	const STATUS_READY   = 'READY';
	const STATUS_PENDING = 'PENDING';
	const STATUS_EXPIRED = 'EXPIRED';
	const STATUS_TRIAL   = 'TRIAL';

	public static function register_hooks() {
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
	}

	public static function refresh() {
		$client = Plugin::instance()->service( 'api_client' );
		if ( null === $client || '' === Repository::get( 'api_token', '' ) ) {
			return self::get();
		}

		$result = $client->request( 'GET', Routes::me() );
		if ( ! $result['ok'] ) {
			if ( 'route_not_found' === $result['error'] ) {
				update_option( 'cachicamoapp_route_error', true, false );
			}
			return self::get();
		}

		update_option( 'cachicamoapp_route_error', false, false );

		$body = $result['body'];
		update_option(
			self::OPTION_NAME,
			array(
				'status'          => isset( $body['status'] ) ? $body['status'] : self::STATUS_READY,
				'plan_expires_at' => isset( $body['plan_expires_at'] ) ? $body['plan_expires_at'] : null,
				'checked_at'      => time(),
			),
			false
		);

		return self::get();
	}

	public static function get() {
		$stored = get_option( self::OPTION_NAME, array() );
		return wp_parse_args(
			is_array( $stored ) ? $stored : array(),
			array(
				'status'          => self::STATUS_READY,
				'plan_expires_at' => null,
				'checked_at'      => null,
			)
		);
	}

	public static function is_expired() {
		$account = self::get();
		if ( self::STATUS_EXPIRED === $account['status'] ) {
			return true;
		}
		if ( self::STATUS_TRIAL === $account['status'] && ! empty( $account['plan_expires_at'] ) ) {
			return strtotime( $account['plan_expires_at'] ) < time();
		}
		return false;
	}

	public static function is_pending() {
		return self::STATUS_PENDING === self::get()['status'];
	}

	/**
	 * False when the account is expired, the last request hit an unregistered core route, or
	 * the reachability check has not passed. Every write path checks this before calling the
	 * core; it never depends on the caller remembering all three conditions.
	 */
	public static function is_writable() {
		if ( self::is_expired() ) {
			return false;
		}
		if ( get_option( 'cachicamoapp_route_error', false ) ) {
			return false;
		}
		if ( class_exists( '\\Cachicamo\\WooCommerce\\Account\\Reachability' ) && ! Reachability::has_passed() ) {
			return false;
		}
		return true;
	}

	public static function admin_notice() {
		if ( '' === Repository::get( 'store_uuid', '' ) ) {
			return;
		}

		$account = self::get();

		if ( self::STATUS_PENDING === $account['status'] ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: plan expiration date. */
						__( 'Your Cachicamo subscription has a pending payment; it expires on %s.', 'cachicamoapp-for-woo' ),
						$account['plan_expires_at'] ? date_i18n( get_option( 'date_format' ), strtotime( $account['plan_expires_at'] ) ) : '-'
					)
				)
			);
			return;
		}

		if ( self::is_expired() ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Your Cachicamo account is expired. Renew it at cachicamo.app to resume syncing and invoicing.', 'cachicamoapp-for-woo' )
			);
		}
	}
}
