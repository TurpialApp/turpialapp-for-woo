<?php
/**
 * Basic API Functions
 *
 * Functions for managing basic API integration with CachicamoApp.
 *
 * @package CachicamoApp_For_WooCommerce
 * @since 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get tax rate with fallback logic.
 *
 * Fallback order:
 * 1. Get tax rate from product's tax_uuid
 * 2. If not found, use store's default tax rate
 * 3. If still not found, search for IVA (VAT) family with "G" tax code (standard IVA)
 * 4. If nothing found, default to 0 (no tax)
 *
 * @since 1.0.0
 * @param array  $tax_rates Tax rates array from Cachicamo API.
 * @param string $product_tax_uuid Product's tax UUID.
 * @param string $store_default_tax_uuid Store's default tax UUID.
 * @param string $user_uuid User UUID for fallback tax search.
 * @return float Tax rate as 1-100 percentage (e.g., 16 for 16% tax).
 */
function cachicamoapp_get_tax_rate_with_fallback( $tax_rates, $product_tax_uuid, $store_default_tax_uuid, $user_uuid ) {
	$tax_rate = 0; // Default: no tax

	// Step 1: Try to get product's specific tax rate
	if ( $product_tax_uuid && isset( $tax_rates[ $product_tax_uuid ] ) ) {
		return floatval( $tax_rates[ $product_tax_uuid ]['tax_rate'] );
	}

	// Step 2: Try to use store's default tax
	if ( $store_default_tax_uuid && isset( $tax_rates[ $store_default_tax_uuid ] ) ) {
		return floatval( $tax_rates[ $store_default_tax_uuid ]['tax_rate'] );
	}

	// Step 3: Search for IVA (VAT) with "G" tax code (standard IVA in Venezuela)
	// This is a common fallback for missing tax configuration
	foreach ( $tax_rates as $tax_uuid => $tax_data ) {
		if ( 
			isset( $tax_data['tax_family'] ) && $tax_data['tax_family'] === 'IVA' &&
			isset( $tax_data['tax_code'] ) && strtoupper( $tax_data['tax_code'] ) === 'G'
		) {
			return floatval( $tax_data['tax_rate'] );
		}
	}

	// Step 4: No tax found, return 0 (no tax)
	return $tax_rate;
}

/**
 * Apply tax calculation to a price.
 * 1. Convert price to store base currency and round to 2 decimals
 * 2. Apply tax (multiply by 1 + (tax_rate/100))
 * 3. Apply exchange rate
 * 4. Round to 4 decimals
 *
 * @since 1.0.0
 * @param float  $price Price amount.
 * @param string $currency_iso Currency ISO of the price (e.g., 'USD').
 * @param float  $tax_rate Tax rate as percentage (e.g., 16 for 16% tax, stored as 1-100).
 * @param array  $currency_rates Currency rates array from Cachicamo.
 * @param string $base_currency_iso Base currency ISO from user configuration (REQUIRED).
 * @param string $wc_currency WooCommerce store currency ISO (REQUIRED).
 * @return float Final price with tax applied, rounded to 4 decimals.
 */
function cachicamoapp_apply_tax_calculation( $price, $currency_iso, $tax_rate, $currency_rates, $base_currency_iso, $wc_currency ) {

	// Step 1: Convert price to base currency if needed and round to 2 decimals
	$price_in_base = $price;
	
	if ( $currency_iso !== $base_currency_iso ) {
		// Need to convert from $currency_iso to $base_currency_iso
		$price_in_base = cachicamoapp_convert_price_by_currency( $price, $currency_iso, $base_currency_iso, $currency_rates );
	}
	
	// Round to 2 decimals
	$price_in_base = round( $price_in_base, 2 );
	
	// Step 2: Apply tax (multiply by 1 + (tax_rate/100))
	// Note: tax_rate is stored as 1-100 (e.g., 16 for 16% tax)
	$price_with_tax = $price_in_base * ( 1 + ( $tax_rate / 100 ) );
	
	// Step 3 & 4: Apply exchange rate and round to 4 decimals
	// Note: At this point we have price in base currency with tax applied
	// If WooCommerce currency is different from base, we need to convert
	$final_price = $price_with_tax;
	if ( $wc_currency !== $base_currency_iso ) {
		$final_price = cachicamoapp_convert_price_by_currency( $price_with_tax, $base_currency_iso, $wc_currency, $currency_rates );
	}
	
	// Round to 4 decimals for final precision
	$final_price = round( $final_price, 4 );
	
	return $final_price;
}

/**
 * Get user configuration from Cachicamo API including base currency.
 *
 * @since 1.0.0
 * @return array|null User configuration with base_currency_uuid or null on error.
 */
function cachicamoapp_get_user_configuration() {
	$key = cachicamoapp_access_token_key();
	if ( ! $key ) {
		return null;
	}
	$cache_key = 'wc_capp_user_config_' . $key;
	$cache     = get_transient( $cache_key );
	if ( $cache ) {
		return 'error' === $cache ? null : $cache;
	}

	$setting = cachicamoapp_setting();

	$result = wp_remote_get(
		CACHICAMO_APP_ENDPOINT . '/users/configuration',
		array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $setting['access_token'],
				'Content-Type'  => 'application/json',
			),
		)
	);
	if ( is_wp_error( $result ) ) {
		cachicamoapp_log( array( 'cachicamoapp_get_user_configuration -> error' => $result ), 'error' );
		set_transient( $cache_key, 'error', 60 );
		return null;
	}

	$decoded = json_decode( $result['body'], true );
	if ( isset( $decoded['base_currency_uuid'] ) ) {
		set_transient( $cache_key, $decoded, 3600 * 24 ); // Cache for 24 hours
		return $decoded;
	}

	cachicamoapp_log( array( 'cachicamoapp_get_user_configuration -> error' => $decoded ), 'error' );
	set_transient( $cache_key, 'error', 60 );
	return null;
}

/**
 * Get store configuration from Cachicamo API including default tax.
 *
 * @since 1.0.0
 * @return array|null Store configuration with default_tax_uuid or null on error.
 */
function cachicamoapp_get_store_configuration() {
	$key = cachicamoapp_access_token_key();
	if ( ! $key ) {
		return null;
	}
	$cache_key = 'wc_capp_store_config_' . $key;
	$cache     = get_transient( $cache_key );
	if ( $cache ) {
		return 'error' === $cache ? null : $cache;
	}

	$setting = cachicamoapp_setting();

	$result = wp_remote_get(
		CACHICAMO_APP_ENDPOINT . '/stores/configuration',
		array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $setting['access_token'],
				'Content-Type'  => 'application/json',
				'X-Store-Uuid'  => $setting['store_uuid'],
			),
		)
	);
	if ( is_wp_error( $result ) ) {
		cachicamoapp_log( array( 'cachicamoapp_get_store_configuration -> error' => $result ), 'error' );
		set_transient( $cache_key, 'error', 60 );
		return null;
	}

	$decoded = json_decode( $result['body'], true );
	if ( isset( $decoded['default_tax_uuid'] ) ) {
		set_transient( $cache_key, $decoded, 3600 * 24 ); // Cache for 24 hours
		return $decoded;
	}

	cachicamoapp_log( array( 'cachicamoapp_get_store_configuration -> error' => $decoded ), 'error' );
	set_transient( $cache_key, 'error', 60 );
	return null;
}

/**
 * Get base currency UUID and info for the store.
 *
 * @since 1.0.0
 * @return array|null Array with 'uuid' and 'iso' keys or null on error.
 */
function cachicamoapp_get_base_currency() {
	$user_config = cachicamoapp_get_user_configuration();
	if ( ! $user_config || ! isset( $user_config['base_currency_uuid'] ) ) {
		cachicamoapp_log( array( 'cachicamoapp_get_base_currency -> no base_currency_uuid' => true ), 'error' );
		return null;
	}

	$base_currency_uuid = $user_config['base_currency_uuid'];
	$all_currencies = cachicamoapp_get_all_currencies();
	
	if ( ! $all_currencies ) {
		return array( 'uuid' => $base_currency_uuid );
	}

	// Find currency info by UUID
	foreach ( $all_currencies as $iso => $currency_data ) {
		if ( isset( $currency_data['uuid'] ) && $currency_data['uuid'] === $base_currency_uuid ) {
			return array(
				'uuid' => $base_currency_uuid,
				'iso'  => $iso,
				'data' => $currency_data,
			);
		}
	}

	return array( 'uuid' => $base_currency_uuid );
}

/**
 * Get all currencies from Cachicamo API.
 *
 * @since 1.0.0
 * @return array|null Array of currencies or null on error.
 */
function cachicamoapp_get_all_currencies() {
	$key = cachicamoapp_access_token_key();
	if ( ! $key ) {
		return null;
	}
	$cache_key = 'wc_capp_currencies_' . $key;
	$cache     = get_transient( $cache_key );
	if ( $cache ) {
		return 'error' === $cache ? array() : $cache; // Return cached currencies if available.
	}

	$setting = cachicamoapp_setting();

	$result = wp_remote_get(
		CACHICAMO_APP_ENDPOINT . '/currencies',
		array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $setting['access_token'],
				'Content-Type'  => 'application/json',
				'X-Store-Uuid'  => $setting['store_uuid'],
			),
		)
	);
	if ( is_wp_error( $result ) ) {
		cachicamoapp_log( array( 'cachicamoapp_get_all_currencies -> error' => $result ), 'error' );
		return null;
	}

	$decoded = json_decode( $result['body'], true );
	if ( isset( $decoded['USD'] ) ) {
		set_transient( $cache_key, $decoded, 3600 * 24 * 7 ); // Cache the currencies for 7 days.
		return $decoded;
	}

	cachicamoapp_log( array( 'cachicamoapp_get_all_currencies -> error' => $decoded ), 'error' );
	set_transient( $cache_key, 'error', 60 ); // Cache error for 1 minute.
	return array();
}

/**
 * Get currency exchange rates from Cachicamo API.
 *
 * @since 1.0.0
 * @return array Array mapping ISO3 codes to exchange rates (e.g., ['USD' => 1.0, 'EUR' => 0.92])
 */
function cachicamoapp_get_currency_rates() {
	$key = cachicamoapp_access_token_key();
	if ( ! $key ) {
		return array();
	}
	$cache_key = 'wc_capp_currency_rates_' . $key;
	$cache     = get_transient( $cache_key );
	if ( $cache ) {
		return 'error' === $cache ? array() : $cache; // Return cached currency rates if available.
	}

	$setting = cachicamoapp_setting();

	$result = wp_remote_get(
		CACHICAMO_APP_ENDPOINT . '/currencies/rates',
		array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $setting['access_token'],
				'Content-Type'  => 'application/json',
				'X-Store-Uuid'  => $setting['store_uuid'],
			),
		)
	);
	if ( is_wp_error( $result ) ) {
		cachicamoapp_log( array( 'cachicamoapp_get_currency_rates -> error' => $result ), 'error' );
		return array();
	}

	$decoded = json_decode( $result['body'], true );
	$rates_map = array();

	// Extract rates from both system_rates and user_rates
	// Structure: { "system_rates": { "USD": { "rate": 1.0 }, ... }, "user_rates": { "EUR": { "rate": 0.92 }, ... } }
	if ( is_array( $decoded ) ) {
		// Process system rates first
		if ( isset( $decoded['system_rates'] ) && is_array( $decoded['system_rates'] ) ) {
			foreach ( $decoded['system_rates'] as $iso => $rate_data ) {
				if ( isset( $rate_data['rate'] ) ) {
					$rates_map[ $iso ] = floatval( $rate_data['rate'] );
				}
			}
		}

		// Process user rates (these override system rates if present)
		if ( isset( $decoded['user_rates'] ) && is_array( $decoded['user_rates'] ) ) {
			foreach ( $decoded['user_rates'] as $iso => $rate_data ) {
				if ( isset( $rate_data['rate'] ) ) {
					$rates_map[ $iso ] = floatval( $rate_data['rate'] );
				}
			}
		}
	}

	if ( ! empty( $rates_map ) ) {
		set_transient( $cache_key, $rates_map, 3600 * 6 ); // Cache for 6 hours.
		return $rates_map;
	}

	cachicamoapp_log( array( 'cachicamoapp_get_currency_rates -> error or empty' => $decoded ), 'error' );
	set_transient( $cache_key, 'error', 60 ); // Cache error for 1 minute.
	return array();
}

/**
 * Get all tax rates from Cachicamo API.
 *
 * @since 1.0.0
 * @return array|null Array of tax rates or null on error.
 */
function cachicamoapp_get_all_tax_rates() {
	$key = cachicamoapp_access_token_key();
	if ( ! $key ) {
		return null;
	}
	$cache_key = 'wc_capp_tax_rates_' . $key;
	$cache     = get_transient( $cache_key );
	if ( $cache ) {
		return 'error' === $cache ? array() : $cache; // Return cached tax rates if available.
	}

	$setting = cachicamoapp_setting();

	$result = wp_remote_get(
		CACHICAMO_APP_ENDPOINT . '/taxes',
		array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $setting['access_token'],
				'Content-Type'  => 'application/json',
				'X-Store-Uuid'  => $setting['store_uuid'],
			),
		)
	);
	if ( is_wp_error( $result ) ) {
		cachicamoapp_log( array( 'cachicamoapp_get_all_tax_rates -> error' => $result ), 'error' );
		return null;
	}

	$decoded = json_decode( $result['body'], true );
	if ( is_array( $decoded ) && count( $decoded ) > 0 ) {
		set_transient( $cache_key, $decoded, 3600 * 24 * 7 ); // Cache for 7 days.
		return $decoded;
	}

	cachicamoapp_log( array( 'cachicamoapp_get_all_tax_rates -> error or empty' => $decoded ), 'error' );
	set_transient( $cache_key, 'error', 60 ); // Cache error for 1 minute.
	return array();
}

/**
 * Get all taxes for current store from Cachicamo API.
 *
 * @since 1.0.0
 * @return array|null Array of taxes or null on error.
 */
function cachicamoapp_get_store_taxes() {
	$key = cachicamoapp_access_token_key();
	if ( ! $key ) {
		return null;
	}
	$cache_key = 'wc_capp_store_taxes_' . $key;
	$cache     = get_transient( $cache_key );
	if ( $cache ) {
		return 'error' === $cache ? array() : $cache; // Return cached store taxes if available.
	}

	$setting = cachicamoapp_setting();

	$result = wp_remote_get(
		CACHICAMO_APP_ENDPOINT . '/taxes/store',
		array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $setting['access_token'],
				'Content-Type'  => 'application/json',
				'X-Store-Uuid'  => $setting['store_uuid'],
			),
		)
	);
	if ( is_wp_error( $result ) ) {
		cachicamoapp_log( array( 'cachicamoapp_get_store_taxes -> error' => $result ), 'error' );
		return null;
	}

	$decoded = json_decode( $result['body'], true );
	if ( is_array( $decoded ) && count( $decoded ) > 0 ) {
		set_transient( $cache_key, $decoded, 3600 * 24 * 7 ); // Cache for 7 days.
		return $decoded;
	}

	cachicamoapp_log( array( 'cachicamoapp_get_store_taxes -> error or empty' => $decoded ), 'error' );
	set_transient( $cache_key, 'error', 60 ); // Cache error for 1 minute.
	return array();
}

/**
 * Convert price between two currencies using exchange rates.
 *
 * All exchange rates are relative to USD, but this function works for any currency pair.
 * Formula: amount * (rate_to / rate_from)
 *
 * @since 1.0.0
 * @param float   $price Amount to convert.
 * @param string  $from_iso ISO code of source currency (e.g., 'EUR', 'VES').
 * @param string  $to_iso ISO code of target currency (e.g., 'USD', 'EUR').
 * @param array   $currency_rates Array mapping ISO codes to exchange rates (all relative to USD).
 * @return float Converted price. Returns original price if conversion fails.
 */
function cachicamoapp_convert_price_by_currency( $price, $from_iso, $to_iso, $currency_rates ) {
	$from_iso = strtoupper($from_iso);
	$to_iso = strtoupper($to_iso);
	
	if (in_array($from_iso, ['VEF', 'VEB', 'VES'])) {
		$from_iso = 'VES';
	}

	if (in_array($to_iso, ['VEF', 'VEB', 'VES'])) {
		$to_iso = 'VES';
	}
	
	// If currencies are the same, no conversion needed
	if ( $from_iso === $to_iso ) {
		return $price;
	}

	// Get rates for both currencies (all rates are relative to USD)
	$rate_from = $currency_rates[ $from_iso ] ?? null;
	$rate_to = $currency_rates[ $to_iso ] ?? null;

	// If either rate is missing, return original price
	if ( ! $rate_from || ! $rate_to ) {
		cachicamoapp_log( array(
			'cachicamoapp_convert_price_by_currency -> Missing exchange rate' => array(
				'from' => $from_iso,
				'to' => $to_iso,
				'rate_from' => $rate_from,
				'rate_to' => $rate_to,
			)
		), 'warning' );
		return null;
	}

	return $price * ( $rate_to / $rate_from );
}

/**
 * Get store details from Cachicamo API.
 *
 * @since 1.0.0
 * @return array|null Array of store details or null on error.
 */
function cachicamoapp_get_store_details() {
	$key = cachicamoapp_access_token_key();
	if ( ! $key ) {
		return null;
	}
	$cache_key = 'wc_capp_store_details_' . $key;
	$cache     = get_transient( $cache_key );
	if ( $cache ) {
		return 'error' === $cache ? null : $cache; // Return cached store details if available.
	}

	$setting = cachicamoapp_setting();
	$result  = wp_remote_get(
		CACHICAMO_APP_ENDPOINT . '/stores/uuid/' . $setting['store_uuid'],
		array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $setting['access_token'],
				'Content-Type'  => 'application/json',
				'X-Store-Uuid'  => $setting['store_uuid'],
			),
		)
	);
	if ( is_wp_error( $result ) ) {
		cachicamoapp_log( array( 'cachicamoapp_get_store_details -> error' => $result ), 'error' );
		set_transient( $cache_key, 'error', 60 ); // Cache empty result for 1 minute.
		return null;
	}
	$decoded = json_decode( $result['body'], true );
	set_transient( $cache_key, $decoded, 600 ); // Cache store details for 1 hour.
	return $decoded;
}

/**
 * Search for products in Cachicamo by SKU.
 *
 * @since 1.0.0
 * @param string $sku The SKU to search for.
 * @return array|null Found product data array or null if not found/error.
 */
function cachicamoapp_search_product_by_sku( $sku ) {
	$key = cachicamoapp_access_token_key();
	if ( ! $key ) {
		return null;
	}
	$cache_key = 'wc_capp_product_' . $sku . '_' . $key;
	$cache     = get_transient( $cache_key );
	if ( $cache ) {
		// Return cached product if available.
		return $cache;
	}

	$setting = cachicamoapp_setting();

	// Search in inventories endpoint with SKU filter
	$result = wp_remote_get(
		CACHICAMO_APP_ENDPOINT . '/inventories?sku=' . rawurlencode( $sku ),
		array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $setting['access_token'],
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				'X-Store-Uuid'  => $setting['store_uuid'],
			),
		)
	);
	if ( is_wp_error( $result ) ) {
		cachicamoapp_log( array( 'cachicamoapp_search_product_by_sku[' . $sku . '] -> error' => $result ), 'error' );
		return null;
	}
	
	$decoded = json_decode( $result['body'], true );
	
	// Check if we have inventory data
	if ( isset( $decoded['rows'] ) && is_array( $decoded['rows'] ) && count( $decoded['rows'] ) > 0 ) {
		// Find the product that matches the SKU in any of its sku_list
		foreach ( $decoded['rows'] as $inventory_item ) {
			if ( isset( $inventory_item['product'] ) ) {
				$product = $inventory_item['product'];
				// Check if SKU is in the product's sku_list
				if ( isset( $product['sku_list'] ) && is_array( $product['sku_list'] ) && in_array( $sku, $product['sku_list'], true ) ) {
					// Return product with inventory data
					$result_data = array(
						'product' => $product,
						'inventory' => $inventory_item,
					);
					set_transient( $cache_key, $result_data, 3600 ); // Cache for 1 hour.
					return $result_data;
				}
			}
		}
	}
	
	// Also try searching in products endpoint
	$result = wp_remote_get(
		CACHICAMO_APP_ENDPOINT . '/products/search?limit=100&page=1&sku=' . rawurlencode( $sku ),
		array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $setting['access_token'],
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				'X-Store-Uuid'  => $setting['store_uuid'],
			),
		)
	);
	
	if ( is_wp_error( $result ) ) {
		cachicamoapp_log( array( 'cachicamoapp_search_product_by_sku[' . $sku . '] -> error2' => $result ), 'error' );
		return null;
	}
	
	$decoded = json_decode( $result['body'], true );

	if ( isset( $decoded['rows'] ) && is_array( $decoded['rows'] ) ) {
		// Search through all products and variations
		foreach ( $decoded['rows'] as $row ) {
			// Check if product has variations
			if ( isset( $row['with_variations'] ) && $row['with_variations'] && isset( $row['variations'] ) ) {
				foreach ( $row['variations'] as $variation ) {
					if ( isset( $variation['sku_list'] ) && is_array( $variation['sku_list'] ) && in_array( $sku, $variation['sku_list'], true ) ) {
						set_transient( $cache_key, $variation, 3600 ); // Cache the variation for 1 hour.
						return $variation;
					}
				}
			} elseif ( isset( $row['sku_list'] ) && is_array( $row['sku_list'] ) && in_array( $sku, $row['sku_list'], true ) ) {
				set_transient( $cache_key, $row, 3600 ); // Cache the product for 1 hour.
				return $row;
			}
		}
	}

	return null;
}

/**
 * Create database table for batch queue if it doesn't exist.
 *
 * @since 1.0.0
 * @return void
 */
function cachicamoapp_create_batch_queue_table() {
	global $wpdb;
	$table_name = $wpdb->prefix . 'cachicamoapp_batch_queue';
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE IF NOT EXISTS $table_name (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		batch_number int(11) NOT NULL,
		total_batches int(11) NOT NULL,
		sku_list longtext NOT NULL,
		status varchar(20) NOT NULL DEFAULT 'pending',
		created_at datetime NOT NULL,
		processed_at datetime DEFAULT NULL,
		error_message text DEFAULT NULL,
		PRIMARY KEY (id),
		KEY status (status),
		KEY batch_number (batch_number)
	) $charset_collate;";

	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
	dbDelta( $sql );
}

/**
 * Clear all pending batches from the queue.
 *
 * @since 1.0.0
 * @return int Number of rows deleted.
 */
function cachicamoapp_clear_batch_queue() {
	global $wpdb;
	$table_name = $wpdb->prefix . 'cachicamoapp_batch_queue';
	
	return $wpdb->query( "DELETE FROM $table_name WHERE status = 'pending'" );
}

/**
 * Get the next pending batch from the queue.
 *
 * @since 1.0.0
 * @return object|null Batch object or null if no pending batches.
 */
function cachicamoapp_get_next_pending_batch() {
	global $wpdb;
	$table_name = $wpdb->prefix . 'cachicamoapp_batch_queue';
	
	return $wpdb->get_row( 
		"SELECT * FROM $table_name WHERE status = 'pending' ORDER BY batch_number ASC LIMIT 1" 
	);
}

/**
 * Mark a batch as processed.
 *
 * @since 1.0.0
 * @param int    $batch_id Batch ID.
 * @param string $status Status ('completed' or 'error').
 * @param string $error_message Optional error message.
 * @return void
 */
function cachicamoapp_mark_batch_processed( $batch_id, $status = 'completed', $error_message = null ) {
	global $wpdb;
	$table_name = $wpdb->prefix . 'cachicamoapp_batch_queue';
	
	$wpdb->update(
		$table_name,
		array(
			'status' => $status,
			'processed_at' => current_time( 'mysql' ),
			'error_message' => $error_message,
		),
		array( 'id' => $batch_id ),
		array( '%s', '%s', '%s' ),
		array( '%d' )
	);
}

/**
 * Count pending batches in the queue.
 *
 * @since 1.0.0
 * @return int Number of pending batches.
 */
function cachicamoapp_count_pending_batches() {
	global $wpdb;
	$table_name = $wpdb->prefix . 'cachicamoapp_batch_queue';
	
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE status = 'pending'" );
}

/**
 * Process a single batch of SKUs.
 *
 * @since 1.0.0
 * @param array $batch_skus Array of SKUs to process.
 * @param array $sku_to_product_map Mapping of SKUs to WooCommerce products.
 * @param array $system_data Pre-loaded system data (tax_rates, currency_rates, etc.).
 * @param int   $batch_number Current batch number.
 * @param int   $total_batches Total number of batches.
 * @return array Array with 'synced', 'errors', 'not_found' counts.
 */
function cachicamoapp_process_single_batch( $batch_skus, $sku_to_product_map, $system_data, $batch_number, $total_batches ) {
	$setting = cachicamoapp_setting();
	
	$synced_count = 0;
	$error_count = 0;
	$not_found_count = 0;
	
	cachicamoapp_log( array(
		'cachicamoapp_process_single_batch -> Batch ' . $batch_number . '/' . $total_batches => array( 'skus' => count( $batch_skus ) )
	), 'info' );

	// Call batch endpoint with price (include_virtual=1 to get virtual product prices too)
	$response = wp_remote_post(
		CACHICAMO_APP_ENDPOINT . '/inventories/batch/sku?with_price=1&include_virtual=1',
		array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $setting['access_token'],
				'Content-Type'  => 'application/json',
				'X-Store-Uuid'  => $setting['store_uuid'],
			),
			'body' => json_encode( array(
				'sku_list' => $batch_skus,
			) ),
			'timeout' => 60, // Increased timeout for batch processing
		)
	);

	if ( is_wp_error( $response ) ) {
		cachicamoapp_log( array( 
			'cachicamoapp_process_single_batch -> Batch request failed' => array(
				'batch' => $batch_number,
				'error' => $response->get_error_message(),
			)
		), 'error' );
		$error_count += count( $batch_skus );
		return array(
			'synced' => $synced_count,
			'errors' => $error_count,
			'not_found' => $not_found_count,
		);
	}

	$status_code = wp_remote_retrieve_response_code( $response );
	if ( $status_code !== 200 ) {
		cachicamoapp_log( array( 
			'cachicamoapp_process_single_batch -> Batch request failed' => array(
				'batch' => $batch_number,
				'status_code' => $status_code,
				'response' => wp_remote_retrieve_body( $response ),
			)
		), 'error' );
		$error_count += count( $batch_skus );
		return array(
			'synced' => $synced_count,
			'errors' => $error_count,
			'not_found' => $not_found_count,
		);
	}

	$inventory_data = json_decode( wp_remote_retrieve_body( $response ), true );
	
	if ( ! is_array( $inventory_data ) ) {
		cachicamoapp_log( array( 
			'cachicamoapp_process_single_batch -> Invalid response format' => array(
				'batch' => $batch_number,
				'response_body' => wp_remote_retrieve_body( $response ),
			)
		), 'error' );
		$error_count += count( $batch_skus );
		return array(
			'synced' => $synced_count,
			'errors' => $error_count,
			'not_found' => $not_found_count,
		);
	}

	// Process results and update WooCommerce products
	$found_skus = array();
	$product_summaries = array();

	// Extract system data
	$tax_rates = $system_data['tax_rates'];
	$currency_rates = $system_data['currency_rates'];
	$base_currency_iso = $system_data['base_currency_iso'];
	$to_currency = $system_data['to_currency'];
	$store_config = $system_data['store_config'];

	foreach ( $inventory_data as $inventory_item ) {
		if ( ! isset( $inventory_item['sku_list'] ) || ! isset( $inventory_item['stock_billable'] ) ) {
			continue;
		}

		$stock_quantity = floatval( $inventory_item['stock_billable'] );
		$inventory_skus = $inventory_item['sku_list'];

		$item_found = false;
		// Update all products/variations matching the SKUs
		foreach ( $inventory_skus as $sku_cachicamo ) {
			if ( ! isset( $sku_to_product_map[ $sku_cachicamo ] ) ) {
				continue;
			}

			$product_data = $sku_to_product_map[ $sku_cachicamo ];
			$wc_product = $product_data['product'];
			$product_id = $wc_product->get_id();
			$is_virtual = $wc_product->is_virtual();

			// Capture values BEFORE update for log summary
			$price_prev = $wc_product->get_price();
			$stock_prev = $is_virtual ? null : $wc_product->get_stock_quantity();
			$price_final = $price_prev;
			$stock_final = $is_virtual ? null : $stock_quantity;

			// Build WC identifier: pid for simple, pid + vid for variation
			$sku_wc = isset( $product_data['variation_id'] )
				? 'pid:' . $product_data['parent_id'] . ' vid:' . $product_data['variation_id']
				: 'pid:' . $product_id;

			if 

			// Update stock in WooCommerce (only for non-virtual products)
			if ( ! $is_virtual ) {
				$wc_product->set_manage_stock( true );
				$wc_product->set_stock_quantity( max( 0, $stock_quantity ) );
				$wc_product->set_stock_status( $stock_quantity > 0 ? 'instock' : 'outofstock' );
			}

			// Update price if included in response
			if ( isset( $inventory_item['price'] ) && is_array( $inventory_item['price'] ) ) {
				$price_data = $inventory_item['price'];

				$retail_price = floatval( $price_data['amount_retail_format']['amount_float'] );
				$from_currency = $price_data['amount_retail_format']['currency_iso'];
				$product_tax_uuid = $inventory_item['tax_uuid'] ?? null;

				$tax_rate = cachicamoapp_get_tax_rate_with_fallback(
					$tax_rates,
					$product_tax_uuid,
					isset( $store_config['default_tax_uuid'] ) ? $store_config['default_tax_uuid'] : null,
					$setting['user_uuid'] ?? null
				);

				$final_price = cachicamoapp_apply_tax_calculation(
					$retail_price,
					$from_currency,
					$tax_rate,
					$currency_rates,
					$base_currency_iso,
					$to_currency
				);

				if ( ! $final_price || $final_price < 0.01 ) {
					cachicamoapp_log( array(
						'cachicamoapp_process_single_batch -> Final price is null' => array(
							'sku_cachicamo' => $sku_cachicamo,
							'sku_wc' => $sku_wc,
						)
					), 'error' );
					continue;
				}

				$price_final = $final_price;
				$wc_product->set_price( $final_price );
				$wc_product->set_regular_price( $final_price );
				$wc_product->set_sale_price( $final_price );
			}

			$wc_product->save();

			$product_summaries[] = array(
				'sku_cachicamo' => $sku_cachicamo,
				'sku_wc'       => $sku_wc,
				'price_prev'   => $price_prev !== '' ? floatval( $price_prev ) : null,
				'price_final'  => $price_final !== '' ? floatval( $price_final ) : null,
				'stock_prev'   => $stock_prev,
				'stock_final'  => $stock_final,
			);

			$synced_count++;
			$item_found = true;
			$found_skus[] = $sku_cachicamo;

			break; // Only update once per inventory item
		}

		if ( ! $item_found ) {
			$not_found_count += count( $inventory_skus );
		}
	}

	// Log compact summary: one line per product + batch totals
	cachicamoapp_log( array(
		'cachicamoapp_process_single_batch -> Batch summary' => array(
			'batch' => $batch_number,
			'total_batches' => $total_batches,
			'items_received' => count( $inventory_data ),
			'products_updated' => $synced_count,
			'products' => $product_summaries,
		)
	), 'info' );
	
	// Count SKUs not returned by API
	$requested_not_returned = array_diff( $batch_skus, $found_skus );
	if ( ! empty( $requested_not_returned ) ) {
		$not_found_count += count( $requested_not_returned );
	}
	
	return array(
		'synced' => $synced_count,
		'errors' => $error_count,
		'not_found' => $not_found_count,
	);
}

/**
 * Process the next pending batch in the queue.
 *
 * This function is called by the scheduled cron job every minute.
 *
 * @since 1.0.0
 * @return void
 */
function cachicamoapp_process_next_batch_job() {
	$next_batch = cachicamoapp_get_next_pending_batch();
	
	if ( ! $next_batch ) {
		// No more pending batches, unschedule the recurring job
		wp_clear_scheduled_hook( 'cachicamoapp_process_batch_queue' );
		cachicamoapp_log( array( 'cachicamoapp_process_next_batch_job -> All batches completed' ), 'info' );
		return;
	}

	// Mark batch as processing
	cachicamoapp_mark_batch_processed( $next_batch->id, 'processing' );
	
	cachicamoapp_log( array(
		'cachicamoapp_process_next_batch_job -> Batch ' . $next_batch->batch_number . '/' . $next_batch->total_batches => array( 'id' => $next_batch->id )
	), 'info' );
	
	// Pre-load system data
	$tax_rates = cachicamoapp_get_all_tax_rates();
	$currency_rates = cachicamoapp_get_currency_rates();
	$base_currency = cachicamoapp_get_base_currency();
	$store_config = cachicamoapp_get_store_configuration();
	
	$base_currency_iso = isset( $base_currency['iso'] ) ? $base_currency['iso'] : 'VES';
	$to_currency = get_woocommerce_currency();
	
	$system_data = array(
		'tax_rates' => $tax_rates,
		'currency_rates' => $currency_rates,
		'base_currency_iso' => $base_currency_iso,
		'to_currency' => $to_currency,
		'store_config' => $store_config,
	);
	
	// Rebuild SKU to product map for this batch
	$batch_skus = json_decode( $next_batch->sku_list, true );
	
	// Build SKU to product map only for needed SKUs
	$sku_to_product_map = array();
	
	foreach ( $batch_skus as $sku ) {
		// Skip if already processed
		if ( isset( $sku_to_product_map[ $sku ] ) ) {
			continue;
		}
		
		$product = null;
		$is_wc_id = false;
		
		// Check if it's a WC-ID format (WC-123 for product ID or variation ID)
		if ( strpos( $sku, 'WC-' ) === 0 ) {
			$product_id = intval( substr( $sku, 3 ) );
			if ( $product_id > 0 ) {
				$product = wc_get_product( $product_id );
				$is_wc_id = true;
			}
		} else {
			// It's a regular SKU, search by SKU
			$product_id = wc_get_product_id_by_sku( $sku );
			if ( $product_id > 0 ) {
				$product = wc_get_product( $product_id );
			}
		}
		
		if ( ! $product ) {
			continue;
		}
		
		$product_id = $product->get_id();
		$product_sku = $product->get_sku();
		$product_type = $product->get_type();
		
		// Determine if it's a variation or parent product
		if ( $product_type === 'variation' ) {
			$parent_id = $product->get_parent_id();
			$variation_data = array(
				'product' => $product,
				'type' => 'variation',
				'parent_id' => $parent_id,
				'variation_id' => $product_id,
				'variation_name' => $product->get_name(),
			);
			
			// Map by actual SKU
			if ( ! empty( $product_sku ) && is_string( $product_sku ) && strlen( trim( $product_sku ) ) > 0 ) {
				$sku_to_product_map[ $product_sku ] = $variation_data;
			}
			
			// Map by WC-ID
			$sku_to_product_map[ 'WC-' . $product_id ] = $variation_data;
		} else {
			// Parent product (simple or variable)
			$product_data = array(
				'product' => $product,
				'type' => 'parent',
				'product_id' => $product_id,
				'product_name' => $product->get_name(),
			);
			
			// Map by actual SKU
			if ( ! empty( $product_sku ) && is_string( $product_sku ) && strlen( trim( $product_sku ) ) > 0 ) {
				$sku_to_product_map[ $product_sku ] = $product_data;
			}
			
			// Map by WC-ID
			$sku_to_product_map[ 'WC-' . $product_id ] = $product_data;
		}
	}
	
	// Process the batch
	try {
		$result = cachicamoapp_process_single_batch( 
			$batch_skus, 
			$sku_to_product_map, 
			$system_data, 
			$next_batch->batch_number, 
			$next_batch->total_batches 
		);
		
		// Update cumulative stats
		$synced_count = (int) get_option( 'cachicamoapp_last_stock_sync_count', 0 );
		$error_count = (int) get_option( 'cachicamoapp_last_stock_sync_errors', 0 );
		$not_found_count = (int) get_option( 'cachicamoapp_last_stock_sync_not_found', 0 );
		
		update_option( 'cachicamoapp_last_stock_sync_count', $synced_count + $result['synced'] );
		update_option( 'cachicamoapp_last_stock_sync_errors', $error_count + $result['errors'] );
		update_option( 'cachicamoapp_last_stock_sync_not_found', $not_found_count + $result['not_found'] );
		
		// Mark batch as completed
		cachicamoapp_mark_batch_processed( $next_batch->id, 'completed' );
		
		// Check if this was the last batch
		$remaining = cachicamoapp_count_pending_batches();
		if ( $remaining === 0 ) {
			// Update last sync time when all batches are done
			update_option( 'cachicamoapp_last_stock_sync', current_time( 'mysql' ) );
			
			cachicamoapp_log( array(
				'cachicamoapp_process_next_batch_job -> All batches done' => array(
					'synced' => get_option( 'cachicamoapp_last_stock_sync_count', 0 ),
					'errors' => get_option( 'cachicamoapp_last_stock_sync_errors', 0 ),
					'not_found' => get_option( 'cachicamoapp_last_stock_sync_not_found', 0 ),
				)
			), 'info' );
		}
		
	} catch ( Exception $e ) {
		cachicamoapp_log( array( 
			'cachicamoapp_process_next_batch_job -> Exception' => array(
				'batch_id' => $next_batch->id,
				'error' => $e->getMessage(),
			)
		), 'error' );
		
		cachicamoapp_mark_batch_processed( $next_batch->id, 'error', $e->getMessage() );
	}
}

/**
 * Update product stock from Cachicamo API using batch endpoint.
 *
 * Synchronizes inventory from Cachicamo to WooCommerce using efficient batch queries.
 * Cachicamo is the source of truth - stock values will always be overwritten.
 *
 * This function processes SKUs in batches of 500. If multiple batches are needed,
 * only the first batch is processed immediately, and the rest are queued to be
 * processed every 1 minute by a scheduled job.
 *
 * @since 1.0.0
 * @return void
 */
function cachicamoapp_update_stock_from_api() {
	// Ensure the batch queue table exists
	cachicamoapp_create_batch_queue_table();
	$setting = cachicamoapp_setting();
	if ( ! $setting || empty( $setting['access_token'] ) || empty( $setting['store_uuid'] ) ) {
		cachicamoapp_log( array( 'cachicamoapp_update_stock_from_api -> Missing configuration' ), 'error' );
		return;
	}

	cachicamoapp_log( array( 'cachicamoapp_update_stock_from_api -> Starting batch stock sync' ), 'info' );

	// Pre-load system data for calculations
	$tax_rates = cachicamoapp_get_all_tax_rates();
	$store_taxes = cachicamoapp_get_store_taxes();
	$currency_rates = cachicamoapp_get_currency_rates();
	$base_currency = cachicamoapp_get_base_currency();
	$store_config = cachicamoapp_get_store_configuration();
	
	// Extract base currency info once (never changes during loop execution)
	$base_currency_uuid = $base_currency ? $base_currency['uuid'] : null;
	$base_currency_iso = isset( $base_currency['iso'] ) ? $base_currency['iso'] : 'VES';
	$to_currency = get_woocommerce_currency(); // WooCommerce store currency (never changes)

	// Initial config: tax_rates, store_taxes, currency_rates (kept for reference)
	cachicamoapp_log( array(
		'cachicamoapp_update_stock_from_api -> System data (tax_rates, store_taxes, currency_rates)' => array(
			'tax_rates' => $tax_rates,
			'store_taxes' => $store_taxes,
			'currency_rates' => $currency_rates,
			'base_currency_iso' => $base_currency_iso,
			'wc_store_currency' => $to_currency,
		)
	), 'info' );

	// Get all WooCommerce products with SKUs
	// Query ALL product types to ensure we don't miss anything
	// Common WooCommerce product types: simple, variable, variation, grouped, external, bundle, composite, subscription
	
	$all_products_args = array(
		'status' => 'publish',
		'limit'  => -1,
	);
	
	$products = wc_get_products( $all_products_args );
	
	// Verify product types we got
	$type_breakdown = array();
	foreach ( $products as $product ) {
		$type = $product->get_type();
		if ( ! isset( $type_breakdown[ $type ] ) ) {
			$type_breakdown[ $type ] = 0;
		}
		$type_breakdown[ $type ]++;
	}
	
	cachicamoapp_log( array(
		'cachicamoapp_update_stock_from_api -> Products retrieved' => array(
			'total' => count( $products ),
			'by_type' => $type_breakdown,
		)
	), 'info' );
	
	// Collect all SKUs and create SKU -> Product mapping
	$sku_to_product_map = array();
	$all_skus = array();
	$simple_count = 0;
	$variable_count = 0;
	$variation_count = 0;
	$products_with_sku = 0;
	$products_without_sku = 0;
	$variations_with_sku = 0;
	$variations_without_sku = 0;

	foreach ( $products as $product ) {
		$product_id = $product->get_id();
		$sku = $product->get_sku();
		$wc_id_sku = 'WC-' . $product_id;
		$product_type = $product->get_type();
		
		$product_data = array(
			'product' => $product,
			'type' => 'parent',
			'product_id' => $product_id,
			'product_name' => $product->get_name(),
		);

		// Use both real SKU and WC-{ID} for better matching
		if ( ! empty( $sku ) && is_string( $sku ) && strlen( trim( $sku ) ) > 0 ) {
			$sku_to_product_map[ $sku ] = $product_data;
			$all_skus[] = $sku;
			$products_with_sku++;
		} else {
			$products_without_sku++;
		}
		
		// Always add WC-{ID} format
		$sku_to_product_map[ $wc_id_sku ] = $product_data;
		$all_skus[] = $wc_id_sku;

		if ( $product_type === 'simple' ) {
			$simple_count++;
		}

		// Handle variable products - collect variation SKUs
		if ( $product->is_type( 'variable' ) ) {
			$variable_count++;
			$variations = $product->get_children();
			
			foreach ( $variations as $variation_id ) {
				$variation = wc_get_product( $variation_id );
				if ( ! $variation ) {
					continue;
				}
				
				$variation_sku = $variation->get_sku();
				$variation_wc_id = 'WC-' . $variation_id;
				$variation_count++;
				
				$variation_data = array(
					'product' => $variation,
					'type' => 'variation',
					'parent_id' => $product_id,
					'variation_id' => $variation_id,
					'variation_name' => $variation->get_name(),
				);

				// Use both real SKU and WC-{ID} for better matching
				if ( ! empty( $variation_sku ) && is_string( $variation_sku ) && strlen( trim( $variation_sku ) ) > 0 ) {
					$sku_to_product_map[ $variation_sku ] = $variation_data;
					$all_skus[] = $variation_sku;
					$variations_with_sku++;
				} else {
					$variations_without_sku++;
				}
				
				// Always add WC-{ID} format
				$sku_to_product_map[ $variation_wc_id ] = $variation_data;
				$all_skus[] = $variation_wc_id;
			}
		}
	}

	if ( empty( $all_skus ) ) {
		cachicamoapp_log( array( 'cachicamoapp_update_stock_from_api -> No products with SKU found' ), 'warning' );
		return;
	}

	cachicamoapp_log( array(
		'cachicamoapp_update_stock_from_api -> Products collected' => array(
			'total_skus' => count( $all_skus ),
			'simple' => $simple_count,
			'variable' => $variable_count,
			'variations' => $variation_count,
		)
	), 'info' );

	// Reset cumulative stats for new sync run
	update_option( 'cachicamoapp_last_stock_sync_count', 0 );
	update_option( 'cachicamoapp_last_stock_sync_errors', 0 );
	update_option( 'cachicamoapp_last_stock_sync_not_found', 0 );
	
	// Prepare system data for batch processing
	$system_data = array(
		'tax_rates' => $tax_rates,
		'currency_rates' => $currency_rates,
		'base_currency_iso' => $base_currency_iso,
		'to_currency' => $to_currency,
		'store_config' => $store_config,
	);

	// Process SKUs in batches of 500
	$batches = array_chunk( $all_skus, 500 );
	$total_batches = count( $batches );
	
	cachicamoapp_log( array(
		'cachicamoapp_update_stock_from_api -> Batch queue' => array(
			'batches' => $total_batches,
			'skus' => count( $all_skus ),
		)
	), 'info' );
	
	// Clear any existing pending batches
	cachicamoapp_clear_batch_queue();
	
	// If only 1 batch, process it immediately without queuing
	if ( $total_batches === 1 ) {
		cachicamoapp_log( array( 'cachicamoapp_update_stock_from_api -> Single batch, processing now' ), 'info' );
		
		$result = cachicamoapp_process_single_batch( 
			$batches[0], 
			$sku_to_product_map, 
			$system_data, 
			1, 
			1 
		);
		
		// Update stats
		update_option( 'cachicamoapp_last_stock_sync', current_time( 'mysql' ) );
		update_option( 'cachicamoapp_last_stock_sync_count', $result['synced'] );
		update_option( 'cachicamoapp_last_stock_sync_errors', $result['errors'] );
		update_option( 'cachicamoapp_last_stock_sync_not_found', $result['not_found'] );
		
		cachicamoapp_log( array(
			'cachicamoapp_update_stock_from_api -> Sync done' => array(
				'synced' => $result['synced'],
				'errors' => $result['errors'],
				'not_found' => $result['not_found'],
			)
		), 'info' );
		
		return;
	}
	
	// Multiple batches: save all to database
	global $wpdb;
	$table_name = $wpdb->prefix . 'cachicamoapp_batch_queue';
	
	foreach ( $batches as $batch_number => $batch_skus ) {
		$wpdb->insert(
			$table_name,
			array(
				'batch_number' => $batch_number + 1,
				'total_batches' => $total_batches,
				'sku_list' => json_encode( $batch_skus ),
				'status' => 'pending',
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);
	}
	
	cachicamoapp_log( array(
		'cachicamoapp_update_stock_from_api -> Batches queued' => array( 'batches' => $total_batches )
	), 'info' );
	
	// Schedule recurring job to process remaining batches every 1 minute
	if ( ! wp_next_scheduled( 'cachicamoapp_process_batch_queue' ) ) {
		wp_schedule_event( time() + 10, 'cachicamoapp_one_minute', 'cachicamoapp_process_batch_queue' );
		
		cachicamoapp_log( array( 
			'cachicamoapp_update_stock_from_api -> Scheduled recurring job' => array(
				'remaining_batches' => $total_batches,
				'next_run' => date( 'Y-m-d H:i:s', time() + 10 ),
			)
		), 'info' );
	}
	
	cachicamoapp_log( array( 
		'cachicamoapp_update_stock_from_api -> Initial batch completed, remaining in queue' => array(
			'summary' => array(
				'total_products_processed' => count( $products ),
				'simple_products' => $simple_count,
				'variable_products' => $variable_count,
				'total_variations' => $variation_count,
			),
			'skus' => array(
				'total_skus_collected' => count( $all_skus ),
			),
			'processing' => array(
				'total_batches' => $total_batches,
			),
			'timestamp' => current_time( 'mysql' ),
		)
	), 'info' );
}
