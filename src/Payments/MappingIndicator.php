<?php

namespace Cachicamo\WooCommerce\Payments;

use Cachicamo\WooCommerce\Settings\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Admin-only: Settings > Payments shows the Cachicamo icon crossed out next to every native
 * WooCommerce gateway that has no entry in payment_mapping, so an unmapped method is visible
 * without opening the mapping screen. Cachicamo's own gateways are always mapped to their own
 * uuid (GatewayRegistry::sync_mapping) and never marked.
 */
class MappingIndicator {

	public static function register_hooks() {
		add_filter( 'woocommerce_gateway_title', array( __CLASS__, 'filter_title' ), 20, 2 );
	}

	public static function filter_title( $title, $gateway_id ) {
		if ( ! is_admin() || 0 === strpos( (string) $gateway_id, AbstractCachicamoGateway::ID_PREFIX ) ) {
			return $title;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || false === strpos( (string) $screen->id, 'wc-settings' ) ) {
			return $title;
		}

		$mapping = Repository::get( 'payment_mapping', array() );
		$mapped  = isset( $mapping[ $gateway_id ] ) && '' !== (string) $mapping[ $gateway_id ];

		$icon = CACHICAMO_APP_URL . 'assets/icons/' . ( $mapped ? 'cachicamo.svg' : 'cachicamo-unmapped.svg' );
		$label = $mapped
			? __( 'Mapped to a Cachicamo payment method', 'cachicamoapp-for-woo' )
			: __( 'Not mapped to any Cachicamo payment method', 'cachicamoapp-for-woo' );

		return $title . sprintf(
			' <img src="%1$s" alt="%2$s" title="%2$s" width="16" height="16" style="vertical-align:text-bottom;" />',
			esc_url( $icon ),
			esc_attr( $label )
		);
	}
}
