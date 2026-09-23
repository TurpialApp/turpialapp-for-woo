<?php

namespace Cachicamo\WooCommerce\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for the WooCommerce <-> Cachicamo product link. wp_cachicamo_links
 * (Schema::install) is the indexed lookup; _cachicamo_product_uuid on the WC object is kept in
 * sync so every other module can read the link with get_post_meta()/get_meta() alone.
 */
class Links {

	const KIND_PRODUCT   = 'product';
	const KIND_VARIATION = 'variation';
	const KIND_COMBO     = 'combo';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'cachicamo_links';
	}

	public static function get_uuid( $wc_id ) {
		global $wpdb;
		$uuid = $wpdb->get_var(
			$wpdb->prepare( 'SELECT cachicamo_uuid FROM ' . self::table() . ' WHERE wc_id = %d', $wc_id )
		);
		return $uuid ? $uuid : null;
	}

	public static function get_wc_id( $uuid ) {
		global $wpdb;
		$wc_id = $wpdb->get_var(
			$wpdb->prepare( 'SELECT wc_id FROM ' . self::table() . ' WHERE cachicamo_uuid = %s', $uuid )
		);
		return $wc_id ? (int) $wc_id : null;
	}

	public static function row( $wc_id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE wc_id = %d', $wc_id ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Upserts the wc_id <-> uuid pair and mirrors it on the WooCommerce object's own meta, so a
	 * caller that only has the post object never needs to query this table directly.
	 */
	public static function link( $wc_id, $uuid, $kind ) {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . self::table() . ' (wc_id, cachicamo_uuid, kind, updated_at) VALUES (%d, %s, %s, %s)
				 ON DUPLICATE KEY UPDATE cachicamo_uuid = VALUES(cachicamo_uuid), kind = VALUES(kind), updated_at = VALUES(updated_at)',
				$wc_id,
				$uuid,
				$kind,
				current_time( 'mysql', true )
			)
		);

		update_post_meta( $wc_id, '_cachicamo_product_uuid', $uuid );
	}

	public static function unlink( $wc_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'wc_id' => $wc_id ), array( '%d' ) );
		delete_post_meta( $wc_id, '_cachicamo_product_uuid' );
	}

	public static function set_stock_billable( $wc_id, $stock_billable ) {
		global $wpdb;
		$wpdb->update(
			self::table(),
			array(
				'stock_billable' => $stock_billable,
				'updated_at'     => current_time( 'mysql', true ),
			),
			array( 'wc_id' => $wc_id ),
			array( '%f', '%s' ),
			array( '%d' )
		);
	}

	public static function set_content_hash( $wc_id, $hash ) {
		global $wpdb;
		$wpdb->update(
			self::table(),
			array(
				'content_hash' => $hash,
				'updated_at'   => current_time( 'mysql', true ),
			),
			array( 'wc_id' => $wc_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	public static function content_hash_matches( $wc_id, $hash ) {
		$row = self::row( $wc_id );
		return null !== $row && null !== $row['content_hash'] && hash_equals( $row['content_hash'], (string) $hash );
	}

	/**
	 * Deterministic fingerprint of the fields product.* import writes, used to discard the echo
	 * of a webhook whose content this same plugin just wrote (plan 4.15).
	 */
	public static function content_hash( array $fields ) {
		ksort( $fields );
		return sha1( wp_json_encode( $fields ) );
	}

	/**
	 * The plan's rule for which SKU of a Cachicamo sku_list represents the product outside
	 * Cachicamo: the first one that isn't the plugin's own WC-{id} marker.
	 *
	 * @param array<int,string> $sku_list
	 * @return string|null
	 */
	public static function first_non_wc_sku( array $sku_list ) {
		foreach ( $sku_list as $sku ) {
			if ( ! is_string( $sku ) || '' === $sku ) {
				continue;
			}
			if ( 1 === preg_match( '/^WC-\d+$/', $sku ) ) {
				continue;
			}
			return $sku;
		}
		return null;
	}

	/**
	 * Union of what's already in Cachicamo's sku_list, the WooCommerce SKU (if any) and the
	 * plugin's own WC-{id} marker. Never drops a SKU that already exists in Cachicamo.
	 *
	 * @param array<int,string> $existing
	 * @param string|null       $wc_sku
	 * @param int               $wc_id
	 * @return array<int,string>
	 */
	public static function merge_sku_list( array $existing, $wc_sku, $wc_id ) {
		$merged = array_values(
			array_filter(
				$existing,
				static function ( $sku ) {
					return is_string( $sku ) && '' !== $sku;
				}
			)
		);

		if ( is_string( $wc_sku ) && '' !== $wc_sku && ! in_array( $wc_sku, $merged, true ) ) {
			$merged[] = $wc_sku;
		}

		$marker = 'WC-' . (int) $wc_id;
		if ( ! in_array( $marker, $merged, true ) ) {
			$merged[] = $marker;
		}

		return $merged;
	}
}
