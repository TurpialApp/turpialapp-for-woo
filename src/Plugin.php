<?php

namespace Cachicamo\WooCommerce;

use Cachicamo\WooCommerce\Api\Client;
use Cachicamo\WooCommerce\Checkout\DocumentField;
use Cachicamo\WooCommerce\Checkout\OrderMeta;
use Cachicamo\WooCommerce\Jobs\Scheduler;
use Cachicamo\WooCommerce\Pricing\CartHooks;
use Cachicamo\WooCommerce\Pricing\CheckoutTotals;

defined( 'ABSPATH' ) || exit;

/**
 * Boots the plugin, wires WooCommerce compatibility flags and holds the registry other
 * modules use to reach each other. A module adds itself in register_default_services()
 * (or, once boot() has already run, by calling Plugin::instance()->register_service()) and
 * every other module resolves it through Plugin::instance()->service( 'name' ) instead of
 * instantiating it directly.
 */
class Plugin {

	/** @var self|null */
	private static $instance = null;

	/** @var array<string,object> */
	private $services = array();

	public static function boot() {
		if ( ! Requirements::are_met() ) {
			add_action( 'admin_notices', array( __CLASS__, 'requirements_notice' ) );
			return;
		}

		load_plugin_textdomain( 'cachicamoapp-for-woo', false, dirname( plugin_basename( CACHICAMO_APP_FILE ) ) . '/languages' );

		add_action( 'before_woocommerce_init', array( __CLASS__, 'declare_woocommerce_compatibility' ) );
		register_activation_hook( CACHICAMO_APP_FILE, array( __CLASS__, 'activate' ) );

		self::instance()->register_default_services();
	}

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function activate() {
		Schema::install();
	}

	public static function declare_woocommerce_compatibility() {
		if ( ! class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			return;
		}
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', CACHICAMO_APP_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', CACHICAMO_APP_FILE, true );
	}

	public static function requirements_notice() {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html( Requirements::missing_message() )
		);
	}

	private function register_default_services() {
		$this->register_service( 'api_client', new Client() );
		$this->register_service( 'scheduler', new Scheduler() );

		$this->services['scheduler']->register_hooks();

		DocumentField::register_hooks();
		CartHooks::register_hooks();
		CheckoutTotals::register_hooks();
		OrderMeta::register_hooks();

		\Cachicamo\WooCommerce\Account\Status::register_hooks();
		\Cachicamo\WooCommerce\Admin\Menu::register_hooks();
		\Cachicamo\WooCommerce\Webhooks\Handlers::register();

		add_action( 'rest_api_init', array( '\\Cachicamo\\WooCommerce\\Webhooks\\Receiver', 'register_routes' ) );
		add_action( 'rest_api_init', array( '\\Cachicamo\\WooCommerce\\Admin\\Rest', 'register_routes' ) );

		Schema::maybe_upgrade();
	}

	public function register_service( $name, $service ) {
		$this->services[ $name ] = $service;
		return $service;
	}

	public function service( $name ) {
		return isset( $this->services[ $name ] ) ? $this->services[ $name ] : null;
	}

	public function has_service( $name ) {
		return isset( $this->services[ $name ] );
	}
}
