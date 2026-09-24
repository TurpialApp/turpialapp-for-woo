<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/autoload.php';

use Cachicamo\WooCommerce\Settings\Repository;

global $wpdb;

$cachicamoapp_mode = get_option( 'cachicamoapp_uninstall_mode', 'keep' );

if ( class_exists( '\\ActionScheduler_DBStore' ) || function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( null, array(), 'cachicamoapp' );
}

if ( 'purge' !== $cachicamoapp_mode ) {
	return;
}

foreach ( wp_load_alloptions() as $cachicamoapp_option_name => $cachicamoapp_option_value ) {
	if ( 0 === strpos( $cachicamoapp_option_name, 'cachicamoapp_' ) ) {
		delete_option( $cachicamoapp_option_name );
	}
}

// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- static LIKE pattern, no user input.
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_cachicamo\\_%'" );

$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', "{$wpdb->prefix}cachicamo_links" ) );
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', "{$wpdb->prefix}cachicamo_combo_components" ) );
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', "{$wpdb->prefix}cachicamo_inbox" ) );

if ( function_exists( 'wc_get_webhook_ids' ) ) {
	foreach ( wc_get_webhook_ids() as $cachicamoapp_webhook_id ) {
		$cachicamoapp_webhook = new WC_Webhook( $cachicamoapp_webhook_id );
		if ( 0 === strpos( (string) $cachicamoapp_webhook->get_name(), 'cachicamoapp' ) ) {
			$cachicamoapp_webhook->delete( true );
		}
	}
}
