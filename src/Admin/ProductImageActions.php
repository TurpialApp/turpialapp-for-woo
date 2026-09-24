<?php

namespace Cachicamo\WooCommerce\Admin;

use Cachicamo\WooCommerce\Account\Status;
use Cachicamo\WooCommerce\Api\Client;
use Cachicamo\WooCommerce\Api\Routes;
use Cachicamo\WooCommerce\Catalog\Images;
use Cachicamo\WooCommerce\Catalog\Links;
use Cachicamo\WooCommerce\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Manual per-product image sync: a "Send images to Cachicamo" and a "Bring images from
 * Cachicamo" action, on the product edit screen and as a bulk action on the products list.
 * Each call ignores the image_master/image_overwrite rule once -- it is an explicit, one-time
 * override the merchant asked for, not the automatic sync that ImportFlow/ExportFlow run.
 */
class ProductImageActions {

	const SEND_ACTION  = 'cachicamoapp_send_images';
	const FETCH_ACTION = 'cachicamoapp_fetch_images';
	const BULK_SEND     = 'cachicamoapp_bulk_send_images';
	const BULK_FETCH    = 'cachicamoapp_bulk_fetch_images';
	const NOTICE_TRANSIENT_PREFIX = 'cachicamoapp_image_notice_';

	public static function register_hooks() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_box' ) );
		add_action( 'admin_post_' . self::SEND_ACTION, array( __CLASS__, 'handle_send' ) );
		add_action( 'admin_post_' . self::FETCH_ACTION, array( __CLASS__, 'handle_fetch' ) );
		add_filter( 'bulk_actions-edit-product', array( __CLASS__, 'register_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-product', array( __CLASS__, 'handle_bulk_actions' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
	}

	public static function register_box() {
		add_meta_box(
			'cachicamoapp_images',
			__( 'Cachicamo images', 'cachicamoapp-for-woo' ),
			array( __CLASS__, 'render' ),
			'product',
			'side',
			'default'
		);
	}

	public static function render( $post ) {
		$uuid = Links::get_uuid( $post->ID );
		if ( ! $uuid ) {
			echo '<p>' . esc_html__( 'This product is not linked to Cachicamo yet.', 'cachicamoapp-for-woo' ) . '</p>';
			return;
		}

		$send_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::SEND_ACTION . '&product_id=' . $post->ID ),
			self::SEND_ACTION . '_' . $post->ID
		);
		$fetch_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::FETCH_ACTION . '&product_id=' . $post->ID ),
			self::FETCH_ACTION . '_' . $post->ID
		);

		echo '<p><a class="button" href="' . esc_url( $send_url ) . '">' . esc_html__( 'Send images to Cachicamo', 'cachicamoapp-for-woo' ) . '</a></p>';
		echo '<p><a class="button" href="' . esc_url( $fetch_url ) . '">' . esc_html__( 'Bring images from Cachicamo', 'cachicamoapp-for-woo' ) . '</a></p>';
	}

	public static function register_bulk_actions( array $actions ) {
		$actions[ self::BULK_SEND ]  = __( 'Send images to Cachicamo', 'cachicamoapp-for-woo' );
		$actions[ self::BULK_FETCH ] = __( 'Bring images from Cachicamo', 'cachicamoapp-for-woo' );
		return $actions;
	}

	public static function handle_bulk_actions( $redirect_to, $action, $post_ids ) {
		if ( self::BULK_SEND !== $action && self::BULK_FETCH !== $action ) {
			return $redirect_to;
		}

		if ( ! current_user_can( 'edit_products' ) ) {
			return $redirect_to;
		}

		$sent   = 0;
		$failed = 0;
		foreach ( $post_ids as $post_id ) {
			$ok = self::BULK_SEND === $action ? self::send( (int) $post_id ) : self::fetch( (int) $post_id );
			if ( $ok ) {
				++$sent;
			} else {
				++$failed;
			}
		}

		return add_query_arg(
			array(
				'cachicamoapp_images_done'   => $sent,
				'cachicamoapp_images_failed' => $failed,
			),
			$redirect_to
		);
	}

	public static function render_notice() {
		if ( ! isset( $_GET['cachicamoapp_images_done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$done   = absint( $_GET['cachicamoapp_images_done'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$failed = isset( $_GET['cachicamoapp_images_failed'] ) ? absint( $_GET['cachicamoapp_images_failed'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$class = $failed > 0 ? 'notice-warning' : 'notice-success';
		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html(
				sprintf(
					/* translators: 1: number of products updated, 2: number of products failed */
					__( 'Cachicamo images: %1$d updated, %2$d failed.', 'cachicamoapp-for-woo' ),
					$done,
					$failed
				)
			)
		);
	}

	public static function handle_send() {
		$product_id = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0;
		check_admin_referer( self::SEND_ACTION . '_' . $product_id );

		if ( ! current_user_can( 'edit_product', $product_id ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'cachicamoapp-for-woo' ) );
		}

		self::send( $product_id );
		self::redirect_back( $product_id );
	}

	public static function handle_fetch() {
		$product_id = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0;
		check_admin_referer( self::FETCH_ACTION . '_' . $product_id );

		if ( ! current_user_can( 'edit_product', $product_id ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'cachicamoapp-for-woo' ) );
		}

		self::fetch( $product_id );
		self::redirect_back( $product_id );
	}

	private static function redirect_back( $product_id ) {
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : get_edit_post_link( $product_id, '' ) );
		exit;
	}

	/**
	 * Sends this product's WooCommerce images to Cachicamo, unconditionally: the push direction
	 * has no image_master/image_overwrite check, since it always carries what WooCommerce has.
	 */
	private static function send( $product_id ) {
		if ( ! Status::is_writable() ) {
			return false;
		}

		$product = wc_get_product( $product_id );
		$uuid    = $product ? Links::get_uuid( $product_id ) : null;
		if ( ! $product || ! $uuid ) {
			return false;
		}

		$images = Images::for_export( $product );
		$type   = $product->is_type( 'variation' ) ? 'VARIATION' : ( $product->is_type( 'variable' ) ? 'VARIABLE' : 'SIMPLE' );

		/** @var Client $client */
		$client = Plugin::instance()->service( 'api_client' );

		$item = array(
			'id'     => $uuid,
			'type'   => $type,
			'images' => $images,
		);

		// A SIMPLE/VARIABLE product has no parent, and the core requires a tax on every
		// parent-level update -- carrying the current one over keeps this an images-only push
		// in practice. A VARIATION's tax always comes from its parent, so it is never sent.
		if ( 'VARIATION' !== $type ) {
			$current = $client->request( 'GET', Routes::products_uuid( $uuid ) );
			if ( empty( $current['ok'] ) ) {
				return false;
			}
			if ( isset( $current['body']['tax']['tax_rate'] ) ) {
				$item['tax_percentage'] = (float) $current['body']['tax']['tax_rate'];
			}
		}

		$result = $client->request(
			'POST',
			Routes::products_bulk_json(),
			array( 'products' => array( $item ) )
		);

		return ! empty( $result['ok'] );
	}

	/**
	 * Brings this product's Cachicamo images into WooCommerce, ignoring image_master/
	 * image_overwrite once for this call.
	 */
	private static function fetch( $product_id ) {
		if ( ! Status::is_writable() ) {
			return false;
		}

		$product = wc_get_product( $product_id );
		$uuid    = $product ? Links::get_uuid( $product_id ) : null;
		if ( ! $product || ! $uuid ) {
			return false;
		}

		/** @var Client $client */
		$client = Plugin::instance()->service( 'api_client' );
		$result = $client->request( 'GET', Routes::products_uuid( $uuid ) );
		if ( empty( $result['ok'] ) ) {
			return false;
		}

		$remote_images = isset( $result['body']['images'] ) && is_array( $result['body']['images'] )
			? $result['body']['images']
			: array();

		Images::apply_import( $product, $remote_images, true );

		return true;
	}
}
