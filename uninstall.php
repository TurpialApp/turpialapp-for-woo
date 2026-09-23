<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/autoload.php';

use Cachicamo\WooCommerce\Settings\Repository;

global $wpdb;

$mode = get_option( 'cachicamoapp_uninstall_mode', 'keep' );

if ( class_exists( '\\ActionScheduler_DBStore' ) || function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( null, array(), 'cachicamoapp' );
}

if ( 'purge' !== $mode ) {
	return;
}

foreach ( wp_load_alloptions() as $option_name => $value ) {
	if ( 0 === strpos( $option_name, 'cachicamoapp_' ) ) {
		delete_option( $option_name );
	}
}

$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_cachicamo\\_%'" );

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cachicamo_links" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cachicamo_combo_components" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cachicamo_inbox" );

if ( function_exists( 'wc_get_webhook_ids' ) ) {
	foreach ( wc_get_webhook_ids() as $webhook_id ) {
		$webhook = new WC_Webhook( $webhook_id );
		if ( 0 === strpos( (string) $webhook->get_name(), 'cachicamoapp' ) ) {
			$webhook->delete( true );
		}
	}
}
