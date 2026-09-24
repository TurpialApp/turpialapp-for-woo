<?php

namespace Cachicamo\WooCommerce\Checkout;

use Cachicamo\WooCommerce\Settings\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Collects the buyer's identity document on checkout, classic and blocks alike, and stores the
 * normalized value in _cachicamo_document_id. When document_meta_key is set, no field is
 * injected: that meta key on the order/customer is read instead, and checkout fails before
 * payment if it is empty.
 */
class DocumentField {

	const META_KEY = '_cachicamo_document_id';
	const FIELD_ID = 'cachicamoapp/document';

	public static function register_hooks() {
		if ( self::uses_external_meta_key() ) {
			add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_external_meta_key' ), 10, 2 );
			return;
		}

		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'add_classic_field' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_classic_field' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_classic_field' ), 10, 2 );

		add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'register_block_field' ) );
	}

	private static function uses_external_meta_key() {
		return '' !== Repository::get( 'document_meta_key', '' );
	}

	public static function add_classic_field( $fields ) {
		$fields['billing']['billing_cachicamo_document'] = array(
			'type'        => 'text',
			'label'       => __( 'Documento de identidad (cédula o RIF)', 'cachicamoapp-for-woo' ),
			'required'    => true,
			'class'       => array( 'form-row-wide' ),
			'priority'    => 25,
		);
		return $fields;
	}

	public static function validate_classic_field( $data, $errors ) {
		$raw = isset( $_POST['billing_cachicamo_document'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_cachicamo_document'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checkout nonce already verified before this hook fires.
		self::validate_and_flag( $raw, $data['billing_country'] ?? '', $errors );
	}

	public static function save_classic_field( $order, $data ) {
		$raw        = isset( $_POST['billing_cachicamo_document'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_cachicamo_document'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checkout nonce already verified before this hook fires.
		$normalized = self::resolve_value( $raw, $order->get_billing_country() );
		if ( null !== $normalized ) {
			$order->update_meta_data( self::META_KEY, $normalized );
		}
	}

	public static function validate_external_meta_key( $data, $errors ) {
		$meta_key = Repository::get( 'document_meta_key', '' );
		$value    = isset( $data[ $meta_key ] ) ? $data[ $meta_key ] : '';
		if ( '' === trim( (string) $value ) ) {
			$errors->add( 'cachicamo_document_missing', __( 'Falta el documento de identidad.', 'cachicamoapp-for-woo' ) );
			return;
		}
		$normalized = self::resolve_value( $value, $data['billing_country'] ?? '' );
		if ( null === $normalized ) {
			$errors->add( 'cachicamo_document_invalid', self::invalid_message() );
		}
	}

	public static function register_block_field() {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		woocommerce_register_additional_checkout_field(
			array(
				'id'                => self::FIELD_ID,
				'label'             => __( 'Documento de identidad (cédula o RIF)', 'cachicamoapp-for-woo' ),
				'location'          => 'contact',
				'type'              => 'text',
				'required'          => true,
				'validate_callback' => array( __CLASS__, 'validate_block_field' ),
			)
		);
	}

	/**
	 * @return \WP_Error|null
	 */
	public static function validate_block_field( $value ) {
		if ( null === self::resolve_value( $value, '' ) ) {
			return new \WP_Error( 'cachicamo_document_invalid', self::invalid_message() );
		}
		return null;
	}

	private static function validate_and_flag( $raw, $country, $errors ) {
		if ( '' === trim( (string) $raw ) ) {
			$errors->add( 'cachicamo_document_missing', __( 'Falta el documento de identidad.', 'cachicamoapp-for-woo' ) );
			return;
		}
		if ( null === self::resolve_value( $raw, $country ) ) {
			$errors->add( 'cachicamo_document_invalid', self::invalid_message() );
		}
	}

	/**
	 * Normalizes and validates the document for VE; any other country only needs non-empty.
	 *
	 * @return string|null Normalized value, or null when invalid.
	 */
	public static function resolve_value( $raw, $country ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return null;
		}
		if ( 'VE' !== $country ) {
			return $raw;
		}
		return RifValidator::normalize( $raw );
	}

	private static function invalid_message() {
		return __( 'El documento de identidad no es válido.', 'cachicamoapp-for-woo' );
	}
}
