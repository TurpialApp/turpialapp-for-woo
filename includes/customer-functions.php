<?php
/**
 * Customer API Functions.
 *
 * Functions for interacting with CachicamoApp API.
 * Note: Customer export functions have been removed as we follow an On-Demand approach.
 * Customers will be created in Cachicamo when orders are received via webhooks.
 *
 * @package CachicamoApp_For_WooCommerce
 * @since 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Customer export functionality has been removed.
// Customers will be created in Cachicamo Core when webhooks are received.
