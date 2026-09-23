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
}
