<?php

namespace Cachicamo\WooCommerce\Payments;

use Cachicamo\WooCommerce\Settings\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Builds one gateway instance per row of the cached payment method catalog that is active,
 * assigned to this store and of a type the plugin has a gateway for. The catalog is refreshed
 * by Webhooks\Handlers::handle_payment_method on payment_method.*, so a method that stops being
 * eligible simply drops out of the next build, taking the classic and blocks registrations,
 * process_payment and is_available with it.
 */
class GatewayRegistry {

	/**
	 * Keys are the literal type strings, not the classes' own TYPE constants: reading a class
	 * constant other than ::class forces the autoloader to load that class (and, through it,
	 * WC_Payment_Gateway), which is_eligible() must not require just to answer a yes/no.
	 *
	 * @var array<string,class-string<AbstractCachicamoGateway>>
	 */
	const TYPE_CLASSES = array(
		'WAYU_PAY'    => WayuPayGateway::class,
		'SPIDI'       => SpidiGateway::class,
		'BDV_BIOPAGO' => BiopagoGateway::class,
		'CASHEA_LINK' => CasheaLinkGateway::class,
	);

	public static function register_hooks() {
		add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'add_gateways' ) );
		add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'filter_available' ) );
		add_action( 'init', array( __CLASS__, 'sync_mapping' ) );
	}

	/**
	 * @return array<int,AbstractCachicamoGateway>
	 */
	public static function build_gateways() {
		$store_uuid = (string) Repository::get( 'store_uuid', '' );
		if ( '' === $store_uuid ) {
			return array();
		}

		$gateways = array();
		foreach ( Catalog::get() as $row ) {
			if ( ! self::is_eligible( $row, $store_uuid ) ) {
				continue;
			}
			$class = self::TYPE_CLASSES[ $row['type'] ];
			$gateways[] = new $class( $row );
		}
		return $gateways;
	}

	public static function add_gateways( $gateways ) {
		foreach ( self::build_gateways() as $gateway ) {
			$gateways[] = $gateway;
		}
		return $gateways;
	}

	/**
	 * Extra safety net on top of add_gateways() only building eligible rows: a gateway that
	 * WooCommerce still holds from a stale registration (a race between two requests reading
	 * different catalog snapshots) never reaches checkout as available.
	 *
	 * @param array<string,\WC_Payment_Gateway> $gateways
	 */
	public static function filter_available( $gateways ) {
		foreach ( $gateways as $id => $gateway ) {
			if ( 0 !== strpos( (string) $id, AbstractCachicamoGateway::ID_PREFIX ) ) {
				continue;
			}
			if ( ! $gateway instanceof AbstractCachicamoGateway || ! $gateway->is_available() ) {
				unset( $gateways[ $id ] );
			}
		}
		return $gateways;
	}

	/**
	 * Every Cachicamo gateway maps to its own payment_method_uuid without a manual entry in
	 * the Payments\Mapping screen: the gateway id already carries it.
	 */
	public static function sync_mapping() {
		$gateways = self::build_gateways();
		if ( empty( $gateways ) ) {
			return;
		}

		$mapping = Repository::get( 'payment_mapping', array() );
		$mapping = is_array( $mapping ) ? $mapping : array();
		$changed = false;

		foreach ( $gateways as $gateway ) {
			$uuid = $gateway->payment_method_uuid();
			if ( ! isset( $mapping[ $gateway->id ] ) || $mapping[ $gateway->id ] !== $uuid ) {
				$mapping[ $gateway->id ] = $uuid;
				$changed = true;
			}
		}

		if ( $changed ) {
			Repository::set( 'payment_mapping', $mapping );
		}
	}

	/**
	 * @param array<string,mixed> $row
	 */
	public static function is_eligible( array $row, $store_uuid ) {
		$type = isset( $row['type'] ) ? (string) $row['type'] : '';
		if ( ! isset( self::TYPE_CLASSES[ $type ] ) ) {
			return false;
		}
		if ( empty( $row['active'] ) || empty( $row['available_for_online_payment'] ) ) {
			return false;
		}

		$assigned = isset( $row['assigned'] ) && is_array( $row['assigned'] ) ? $row['assigned'] : array();
		foreach ( $assigned as $assignment ) {
			if ( isset( $assignment['store_uuid'] ) && (string) $assignment['store_uuid'] === $store_uuid ) {
				return true;
			}
		}
		return false;
	}
}
