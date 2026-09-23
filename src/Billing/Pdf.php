<?php

namespace Cachicamo\WooCommerce\Billing;

use Cachicamo\WooCommerce\Api\Routes;
use Cachicamo\WooCommerce\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Serves the stamped PDF of an issued invoice behind a per-order token, resolving where the PDF
 * actually lives from the billing device's device_type: some printing houses hand back a
 * redirect URL, others require the plugin to fetch and stream the binary itself.
 */
class Pdf {

	const META_TOKEN = '_cachicamo_pdf_token';
	const META_TARGET = '_cachicamo_pdf_target';

	const REDIRECT_DEVICE_TYPES = array(
		'digital_thefactoryhka_ve' => array( 'metadata', 'digital_the_factory_result', 'resultado', 'urlConsulta' ),
		'unidigital_ve'            => array( 'metadata', 'unidigital_url' ),
	);

	const STREAM_DEVICE_TYPES = array( 'sigece_ve', 'abc_ve', 'cg_ve' );

	public static function register_hooks() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve' ) );
	}

	public static function token_for( \WC_Order $order ) {
		$token = $order->get_meta( self::META_TOKEN );
		if ( ! empty( $token ) ) {
			return $token;
		}
		$token = bin2hex( random_bytes( 32 ) );
		$order->update_meta_data( self::META_TOKEN, $token );
		$order->save();
		return $token;
	}

	public static function url_for( \WC_Order $order ) {
		return add_query_arg(
			array(
				'cachicamo_invoice' => $order->get_id(),
				'token'             => self::token_for( $order ),
			),
			home_url( '/' )
		);
	}

	public static function maybe_serve() {
		if ( ! isset( $_GET['cachicamo_invoice'], $_GET['token'] ) ) {
			return;
		}

		$order_id = absint( $_GET['cachicamo_invoice'] );
		$order    = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			self::not_found();
		}

		$stored_token = (string) $order->get_meta( self::META_TOKEN );
		$given_token  = (string) wp_unslash( $_GET['token'] );
		if ( '' === $stored_token || ! hash_equals( $stored_token, $given_token ) ) {
			self::not_found();
		}

		$invoice_uuid = $order->get_meta( '_cachicamo_invoice_uuid' );
		if ( empty( $invoice_uuid ) ) {
			self::not_found();
		}

		self::resolve_and_serve( $order, $invoice_uuid );
		exit;
	}

	private static function resolve_and_serve( \WC_Order $order, $invoice_uuid ) {
		$client = Plugin::instance()->service( 'api_client' );
		$result = $client->request( 'GET', Routes::documents_by_uuid( $invoice_uuid ) );
		if ( ! $result['ok'] ) {
			self::not_found();
		}

		$device_type = isset( $result['body']['printer']['device_type'] ) ? $result['body']['printer']['device_type'] : '';

		if ( isset( self::REDIRECT_DEVICE_TYPES[ $device_type ] ) ) {
			$target = self::dig( $result['body'], self::REDIRECT_DEVICE_TYPES[ $device_type ] );
			if ( empty( $target ) ) {
				self::not_found();
			}
			$order->update_meta_data( self::META_TARGET, $target );
			$order->save();
			wp_safe_redirect( $target, 302 );
			return;
		}

		if ( in_array( $device_type, self::STREAM_DEVICE_TYPES, true ) ) {
			$pdf = $client->raw_get( Routes::documents_digital_pdf( $invoice_uuid ) );
			if ( ! $pdf['ok'] ) {
				self::not_found();
			}
			header( 'Content-Type: application/pdf' );
			echo $pdf['raw'];
			return;
		}

		self::not_found();
	}

	private static function dig( array $body, array $path ) {
		$value = $body;
		foreach ( $path as $key ) {
			if ( ! is_array( $value ) || ! isset( $value[ $key ] ) ) {
				return null;
			}
			$value = $value[ $key ];
		}
		return $value;
	}

	private static function not_found() {
		global $wp_query;
		if ( $wp_query ) {
			$wp_query->set_404();
		}
		status_header( 404 );
		nocache_headers();
		exit;
	}
}
