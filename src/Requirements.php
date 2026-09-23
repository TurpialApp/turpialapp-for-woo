<?php

namespace Cachicamo\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Minimum versions the plugin needs to run. WC 8.9 is the floor the pricing and checkout
 * code relies on for HPOS and cart-checkout-blocks compatibility declarations.
 */
class Requirements {

	const MIN_WP_VERSION = '6.4';
	const MIN_PHP_VERSION = '7.4';
	const MIN_WC_VERSION = '8.9';

	public static function are_met() {
		return self::is_php_ok() && self::is_wp_ok() && self::is_wc_ok();
	}

	public static function is_php_ok() {
		return version_compare( PHP_VERSION, self::MIN_PHP_VERSION, '>=' );
	}

	public static function is_wp_ok() {
		global $wp_version;
		return version_compare( $wp_version, self::MIN_WP_VERSION, '>=' );
	}

	public static function is_wc_ok() {
		if ( ! defined( 'WC_VERSION' ) ) {
			return false;
		}
		return version_compare( WC_VERSION, self::MIN_WC_VERSION, '>=' );
	}

	public static function missing_message() {
		$missing = array();
		if ( ! self::is_php_ok() ) {
			$missing[] = sprintf(
				/* translators: %s: minimum PHP version */
				__( 'PHP %s or higher', 'cachicamoapp-for-woo' ),
				self::MIN_PHP_VERSION
			);
		}
		if ( ! self::is_wp_ok() ) {
			$missing[] = sprintf(
				/* translators: %s: minimum WordPress version */
				__( 'WordPress %s or higher', 'cachicamoapp-for-woo' ),
				self::MIN_WP_VERSION
			);
		}
		if ( ! self::is_wc_ok() ) {
			$missing[] = sprintf(
				/* translators: %s: minimum WooCommerce version */
				__( 'WooCommerce %s or higher, active', 'cachicamoapp-for-woo' ),
				self::MIN_WC_VERSION
			);
		}
		return sprintf(
			/* translators: %s: comma separated list of missing requirements */
			__( 'Cachicamo App for WooCommerce requires: %s.', 'cachicamoapp-for-woo' ),
			implode( ', ', $missing )
		);
	}
}
