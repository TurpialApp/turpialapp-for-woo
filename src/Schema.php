<?php

namespace Cachicamo\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Own tables (plan 4.6), created and upgraded with dbDelta. cachicamo_links duplicates the
 * _cachicamo_product_uuid meta with an index because wp_postmeta doesn't index meta_value:
 * looking up the product for an inbox event by meta would be a table scan per event.
 */
class Schema {

	const DB_VERSION = '1.0.0';

	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$prefix           = $wpdb->prefix;

		$sql = "CREATE TABLE {$prefix}cachicamo_links (
  wc_id BIGINT UNSIGNED NOT NULL,
  cachicamo_uuid VARCHAR(36) NOT NULL,
  kind VARCHAR(16) NOT NULL,
  stock_billable DECIMAL(20,4) NULL,
  content_hash CHAR(40) NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (wc_id),
  UNIQUE KEY cachicamo_uuid (cachicamo_uuid)
) $charset_collate;
CREATE TABLE {$prefix}cachicamo_combo_components (
  combo_uuid VARCHAR(36) NOT NULL,
  component_uuid VARCHAR(36) NOT NULL,
  quantity DECIMAL(20,4) NOT NULL,
  PRIMARY KEY  (combo_uuid, component_uuid),
  KEY component_uuid (component_uuid)
) $charset_collate;
CREATE TABLE {$prefix}cachicamo_inbox (
  event_key VARCHAR(80) NOT NULL,
  action VARCHAR(64) NOT NULL,
  payload LONGTEXT NOT NULL,
  received_at DATETIME NOT NULL,
  PRIMARY KEY  (event_key),
  KEY received_at (received_at)
) $charset_collate;";

		dbDelta( $sql );

		update_option( 'cachicamoapp_db_version', self::DB_VERSION );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'cachicamoapp_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}
}
