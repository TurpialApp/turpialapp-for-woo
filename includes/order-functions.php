<?php
/**
 * Order Functions.
 *
 * Functions for handling orders with CachicamoApp integration.
 * Note: Order export functions have been removed. Orders are now sent to Cachicamo via webhooks.
 *
 * @package CachicamoApp_For_WooCommerce
 * @since 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Order export functionality has been removed.
// Orders are now sent to Cachicamo via webhooks configured in WooCommerce.
// The webhook handler is in webhook-handler.php
