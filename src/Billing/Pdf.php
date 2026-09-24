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
		'digital_thefactoryhka_ve' => array( 'digital_the_factory_result', 'resultado', 'urlConsulta' ),
		'unidigital_ve'            => array( 'unidigital_url' ),
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
		$given_token  = sanitize_text_field( wp_unslash( $_GET['token'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- token is the capability, verified below with hash_equals().
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
			$metadata = self::document_metadata( $result['body'] );
			$target   = self::dig( $metadata, self::REDIRECT_DEVICE_TYPES[ $device_type ] );
			if ( empty( $target ) ) {
				self::not_found();
			}
			$host = wp_parse_url( $target, PHP_URL_HOST );
			if ( empty( $host ) || 'https' !== wp_parse_url( $target, PHP_URL_SCHEME ) ) {
				self::not_found();
			}
			$order->update_meta_data( self::META_TARGET, $target );
			$order->save();
			self::redirect_to_printing_house( $target, $host );
			return;
		}

		if ( in_array( $device_type, self::STREAM_DEVICE_TYPES, true ) ) {
			$pdf = $client->raw_get( Routes::documents_digital_pdf( $invoice_uuid ) );
			if ( ! $pdf['ok'] ) {
				self::not_found();
			}
			header( 'Content-Type: application/pdf' );
			echo $pdf['raw']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF stream, not markup.
			return;
		}

		self::not_found();
	}

	/**
	 * GET /documents/uuid/{uuid} nests the issuance result under document.metadata, stored as a
	 * JSON string rather than a decoded object.
	 *
	 * @param array<string,mixed> $body
	 * @return array<string,mixed>
	 */
	private static function document_metadata( array $body ) {
		$raw = isset( $body['document']['metadata'] ) ? $body['document']['metadata'] : null;
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			return is_array( $decoded ) ? $decoded : array();
		}
		return array();
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

	/**
	 * wp_safe_redirect() only follows the site's own host plus whatever allowed_redirect_hosts
	 * lists; the printing house domain is only known at request time from the core's own
	 * response, so the filter is scoped to this single redirect instead of a static allowlist.
	 */
	private static function redirect_to_printing_house( $target, $host ) {
		$allow_host = function ( $hosts ) use ( $host ) {
			$hosts[] = $host;
			return $hosts;
		};
		add_filter( 'allowed_redirect_hosts', $allow_host );
		wp_safe_redirect( $target, 302 );
		remove_filter( 'allowed_redirect_hosts', $allow_host );
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
