<?php

namespace Cachicamo\WooCommerce\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Single point of access to the cachicamoapp_settings option. Every settings key has its
 * default declared here; a caller never invents a default inline.
 */
class Repository {

	const OPTION_NAME = 'cachicamoapp_settings';

	/** @var array<string,mixed>|null */
	private static $cache = null;

	public static function defaults() {
		return array(
			'api_token'             => '',
			'store_uuid'            => '',
			'webhook_secret'        => '',
			'billing_mode'          => 'external_order',
			'printer_document_uuid' => '',
			'trigger_statuses'      => array( 'wc-processing', 'wc-completed' ),
			'start_order_number'    => 0,
			'document_meta_key'     => '',
			'price_type'            => 'retail',
			'stock_source'          => 'cachicamo',
			'content_source'        => 'cachicamo',
			'sync_fields'           => array(
				'name',
				'description',
				'short_description',
				'categories',
				'attributes',
				'images',
				'dimensions',
				'price',
				'stock',
				'status',
			),
			'image_master'          => 'cachicamo',
			'image_overwrite'       => 'never',
			'on_remote_delete'      => 'none',
			'payment_mapping'       => array(),
			'free_line_tax_uuid'    => '',
			'first_load_done'       => array(
				'import'     => false,
				'export'     => false,
				'categories' => false,
				'attributes' => false,
			),
		);
	}

	public static function all() {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION_NAME, array() );
			self::$cache = wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
		}
		return self::$cache;
	}

	public static function get( $key, $default = null ) {
		$all = self::all();
		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}
		return $default;
	}

	public static function set( $key, $value ) {
		$all         = self::all();
		$all[ $key ] = $value;
		self::$cache = $all;
		return update_option( self::OPTION_NAME, $all );
	}

	public static function set_many( array $values ) {
		$all          = array_merge( self::all(), $values );
		self::$cache  = $all;
		return update_option( self::OPTION_NAME, $all );
	}

	public static function flush_cache() {
		self::$cache = null;
	}
}
