<?php

namespace Cachicamo\WooCommerce\Catalog;

use Cachicamo\WooCommerce\Settings\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Product image resolution between WooCommerce and Cachicamo. A product's image set going out
 * is the featured image followed by its gallery, capped at MAX_IMAGES and filtered to
 * MIN_DIMENSION on both axes -- the same shape the core's bulk import expects and the plan's
 * table for the export flow requires. Bringing images in from Cachicamo goes through
 * media_sideload_image() so each attachment is deduplicated by its source URL via
 * META_SOURCE_URL, never re-downloaded twice for the same URL.
 */
class Images {

	const MIN_DIMENSION = 150;
	const MAX_IMAGES    = 20;
	const META_SOURCE_URL = '_cachicamo_image_url';

	/**
	 * URLs to send to Cachicamo for this product: featured image first, then gallery, filtered
	 * to images at least MIN_DIMENSION x MIN_DIMENSION, capped at MAX_IMAGES.
	 *
	 * @return string[]
	 */
	public static function for_export( \WC_Product $product ) {
		$attachment_ids = array();

		$featured_id = $product->get_image_id();
		if ( $featured_id ) {
			$attachment_ids[] = (int) $featured_id;
		}

		foreach ( $product->get_gallery_image_ids() as $gallery_id ) {
			$attachment_ids[] = (int) $gallery_id;
		}

		$candidates = array();
		foreach ( array_unique( $attachment_ids ) as $attachment_id ) {
			$url = wp_get_attachment_url( $attachment_id );
			if ( ! $url ) {
				continue;
			}
			$metadata     = wp_get_attachment_metadata( $attachment_id );
			$candidates[] = array(
				'url'    => $url,
				'width'  => isset( $metadata['width'] ) ? (int) $metadata['width'] : null,
				'height' => isset( $metadata['height'] ) ? (int) $metadata['height'] : null,
			);
		}

		return self::select_export_urls( $candidates );
	}

	/**
	 * Pure selection: cover image first (the caller's array order), images below
	 * MIN_DIMENSION on either axis dropped, capped at MAX_IMAGES. A candidate with no known
	 * dimensions (null width/height) is kept, since WordPress doesn't always have metadata for
	 * an externally-hosted or not-yet-processed attachment.
	 *
	 * @param array<int,array{url:string,width:?int,height:?int}> $candidates
	 * @return string[]
	 */
	public static function select_export_urls( array $candidates ) {
		$urls = array();
		foreach ( $candidates as $candidate ) {
			if ( count( $urls ) >= self::MAX_IMAGES ) {
				break;
			}
			if ( null !== $candidate['width'] && $candidate['width'] < self::MIN_DIMENSION ) {
				continue;
			}
			if ( null !== $candidate['height'] && $candidate['height'] < self::MIN_DIMENSION ) {
				continue;
			}
			$urls[] = $candidate['url'];
		}
		return $urls;
	}

	/**
	 * Pure decision for apply_import(): whether to sideload Cachicamo's images onto the
	 * WooCommerce product, per the plan's rule (4.4/762).
	 */
	public static function should_sideload( $has_cachicamo_images, $has_woocommerce_images, $image_master, $image_overwrite, $force = false ) {
		if ( ! $has_cachicamo_images ) {
			return false;
		}
		if ( ! $has_woocommerce_images ) {
			return true;
		}
		if ( $force ) {
			return true;
		}
		if ( 'woocommerce' === $image_master ) {
			return false;
		}
		return 'never' !== $image_overwrite;
	}

	public static function has_images( \WC_Product $product ) {
		return $product->get_image_id() || count( $product->get_gallery_image_ids() ) > 0;
	}

	/**
	 * Applies Cachicamo's image URLs onto a WooCommerce product following the plan's rule
	 * (4.4/762): no image on the Cachicamo side leaves the product untouched here (the export
	 * side is what carries the WooCommerce URL over, not this method); no image on the
	 * WooCommerce side sideloads every Cachicamo URL; both sides present defer to image_master
	 * and image_overwrite, unless $force bypasses that check for a manual "bring from Cachicamo"
	 * action, which ignores the rule once.
	 *
	 * @param string[] $cachicamo_urls
	 */
	public static function apply_import( \WC_Product $product, array $cachicamo_urls, $force = false ) {
		$cachicamo_urls = array_slice( array_values( array_filter( $cachicamo_urls ) ), 0, self::MAX_IMAGES );

		$sideload = self::should_sideload(
			! empty( $cachicamo_urls ),
			self::has_images( $product ),
			Repository::get( 'image_master', 'cachicamo' ),
			Repository::get( 'image_overwrite', 'never' ),
			$force
		);

		if ( $sideload ) {
			self::sideload_all( $product, $cachicamo_urls );
		}
	}

	/**
	 * @param string[] $urls
	 */
	private static function sideload_all( \WC_Product $product, array $urls ) {
		$attachment_ids = array();
		foreach ( $urls as $url ) {
			$attachment_id = self::find_or_sideload( $url, $product->get_id() );
			if ( $attachment_id ) {
				$attachment_ids[] = $attachment_id;
			}
		}

		if ( empty( $attachment_ids ) ) {
			return;
		}

		$product->set_image_id( array_shift( $attachment_ids ) );
		$product->set_gallery_image_ids( $attachment_ids );
		$product->save();
	}

	private static function find_or_sideload( $url, $post_id ) {
		$existing = self::find_attachment_by_source_url( $url );
		if ( $existing ) {
			return $existing;
		}

		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attachment_id = media_sideload_image( $url, $post_id, null, 'id' );
		if ( is_wp_error( $attachment_id ) ) {
			return null;
		}

		update_post_meta( $attachment_id, self::META_SOURCE_URL, $url );

		return (int) $attachment_id;
	}

	private static function find_attachment_by_source_url( $url ) {
		global $wpdb;
		$attachment_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
				self::META_SOURCE_URL,
				$url
			)
		);
		return $attachment_id ? (int) $attachment_id : null;
	}
}
