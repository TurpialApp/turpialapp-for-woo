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
		CACHICAMO_APP_ENDPOINT . '/user/configuration',
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
		CACHICAMO_APP_ENDPOINT . '/store/' . $setting['store_uuid'] . '/configuration',
		array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $setting['access_token'],
				'Content-Type'  => 'application/json',
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
	cachicamoapp_log( array( 'cachicamoapp_search_product_by_sku[' . $sku . '] -> result' => $decoded ), 'info' );
	
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
 * Update product stock from Cachicamo API using batch endpoint.
 *
 * Synchronizes inventory from Cachicamo to WooCommerce using efficient batch queries.
 * Cachicamo is the source of truth - stock values will always be overwritten.
 *
 * This function processes SKUs in batches of 500 to avoid N+1 queries and improve performance.
 * Suitable for stores with thousands of products.
 *
 * @since 1.0.0
 * @return void
 */
function cachicamoapp_update_stock_from_api() {
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

	cachicamoapp_log( array( 
		'cachicamoapp_update_stock_from_api -> System data loaded' => array(
			'tax_rates_count' => count( $tax_rates ?? array() ),
			'store_taxes_count' => count( $store_taxes ?? array() ),
			'currency_rates_count' => count( $currency_rates ?? array() ),
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
			'total_products' => count( $products ),
			'type_breakdown' => $type_breakdown,
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

	// Analyze SKU types collected
	$real_skus_collected = array();
	$wc_id_skus_collected = array();
	foreach ( $all_skus as $sku ) {
		if ( strpos( $sku, 'WC-' ) === 0 ) {
			$wc_id_skus_collected[] = $sku;
		} else {
			$real_skus_collected[] = $sku;
		}
	}
	
	cachicamoapp_log( array( 
		'cachicamoapp_update_stock_from_api -> Products collected' => array(
			'total_products' => count( $products ),
			'simple_products' => $simple_count,
			'variable_products' => $variable_count,
			'total_variations' => $variation_count,
			'sku_statistics' => array(
				'products_with_sku' => $products_with_sku,
				'products_without_sku' => $products_without_sku,
				'variations_with_sku' => $variations_with_sku,
				'variations_without_sku' => $variations_without_sku,
				'total_skus' => count( $all_skus ),
				'real_skus_count' => count( $real_skus_collected ),
				'wc_id_skus_count' => count( $wc_id_skus_collected ),
			),
		)
	), 'info' );

	$synced_count = 0;
	$error_count = 0;
	$not_found_count = 0;

	// Process SKUs in batches of 500
	$batches = array_chunk( $all_skus, 500 );
	$batch_number = 0;

	foreach ( $batches as $batch_skus ) {
		$batch_number++;
		
		cachicamoapp_log( array( 
			'cachicamoapp_update_stock_from_api -> Processing batch' => array(
				'batch' => $batch_number,
				'total_batches' => count( $batches ),
				'skus_in_batch' => count( $batch_skus ),
			)
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
				'cachicamoapp_update_stock_from_api -> Batch request failed' => array(
					'batch' => $batch_number,
					'error' => $response->get_error_message(),
				)
			), 'error' );
			$error_count += count( $batch_skus );
			continue;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code !== 200 ) {
			cachicamoapp_log( array( 
				'cachicamoapp_update_stock_from_api -> Batch request failed' => array(
					'batch' => $batch_number,
					'status_code' => $status_code,
					'response' => wp_remote_retrieve_body( $response ),
				)
			), 'error' );
			$error_count += count( $batch_skus );
			continue;
		}

		$inventory_data = json_decode( wp_remote_retrieve_body( $response ), true );
		
		if ( ! is_array( $inventory_data ) ) {
			cachicamoapp_log( array( 
				'cachicamoapp_update_stock_from_api -> Invalid response format' => array(
					'batch' => $batch_number,
					'response_body' => wp_remote_retrieve_body( $response ),
				)
			), 'error' );
			$error_count += count( $batch_skus );
			continue;
		}

	// Process results and update WooCommerce products
	$not_found_skus = array();
	$found_skus = array();
	$updated_products = array();
	$received_inventory_items = array(); // Track what we received from API
	
	foreach ( $inventory_data as $inventory_item ) {
		if ( ! isset( $inventory_item['sku_list'] ) || ! isset( $inventory_item['stock_billable'] ) ) {
			continue;
		}

		$stock_quantity = floatval( $inventory_item['stock_billable'] );
		$inventory_skus = $inventory_item['sku_list'];
		
		// Track received items
		$received_inventory_items[] = array(
			'sku_list' => $inventory_skus,
			'stock_billable' => $stock_quantity,
		);

		$item_found = false;
		// Update all products/variations matching the SKUs
		foreach ( $inventory_skus as $sku ) {
			if ( ! isset( $sku_to_product_map[ $sku ] ) ) {
				continue;
			}

		$product_data = $sku_to_product_map[ $sku ];
		$wc_product = $product_data['product'];
		$product_id = $wc_product->get_id();
		$is_virtual = $wc_product->is_virtual();

		// Update stock in WooCommerce (only for non-virtual products)
		// Virtual products cannot have inventory
		if ( ! $is_virtual ) {
			// Cachicamo is the source of truth - always overwrite
			$wc_product->set_manage_stock( true );
			$wc_product->set_stock_quantity( max( 0, $stock_quantity ) ); // Ensure non-negative
			$wc_product->set_stock_status( $stock_quantity > 0 ? 'instock' : 'outofstock' );
		}

		// Update price if included in response
			if ( isset( $inventory_item['price'] ) && is_array( $inventory_item['price'] ) ) {
				$price_data = $inventory_item['price'];
				
				$retail_price = floatval( $price_data['amount_retail_format']['amount_float'] );
				
				// Get currency ISOs for conversion
				// price_data has amount_retail_format with currency_iso
				$from_currency = $price_data['amount_retail_format']['currency_iso'];

				// Get tax rate with fallback: product tax -> store default tax -> IVA G code -> 0
				$tax_rate = cachicamoapp_get_tax_rate_with_fallback( 
					$tax_rates, 
					$price_data['tax_uuid'] ?? null,
					isset( $store_config['default_tax_uuid'] ) ? $store_config['default_tax_uuid'] : null,
					$setting['user_uuid'] ?? null
				);
				
				// Apply tax calculation: convert to base currency -> apply tax -> convert to WC currency -> round to 4 decimals
				$final_price = cachicamoapp_apply_tax_calculation( 
					$retail_price, 
					$from_currency, 
					$tax_rate, 
					$currency_rates, 
					$base_currency_iso,
					$to_currency
				);
				
				if (!$final_price || $final_price < 0.01) {
					cachicamoapp_log( array( 
						'cachicamoapp_update_stock_from_api -> Final price is null' => array(
							'sku' => $sku,
							'price' => $price_data,
						)
					), 'error' );
					continue;
				}
				$wc_product->set_price( $final_price );
				$wc_product->set_regular_price( $final_price );
				$wc_product->set_sale_price( $final_price );
			}

			$wc_product->save();

			$synced_count++;
			$item_found = true;
			$found_skus[] = $sku;

			break; // Only update once per inventory item
		}
		
		if ( ! $item_found ) {
			// None of the SKUs in this inventory item matched any WooCommerce product
			$not_found_skus = array_merge( $not_found_skus, $inventory_skus );
		}
	}

		// Log summary for this batch
		cachicamoapp_log( array( 
			'cachicamoapp_update_stock_from_api -> Batch processing summary' => array(
				'batch' => $batch_number,
				'items_received' => count( $inventory_data ),
				'products_updated' => count( $updated_products ),
				'skus_sent' => $batch_skus,
				'inventory_items_received' => $received_inventory_items,
			)
		), 'info' );
		
		// Count SKUs not returned by API
		$requested_not_returned = array_diff( $batch_skus, $found_skus );
		if ( ! empty( $requested_not_returned ) ) {
			$not_found_count += count( $requested_not_returned );
		}
	}

	// Update last sync time and stats
	update_option( 'cachicamoapp_last_stock_sync', current_time( 'mysql' ) );
	update_option( 'cachicamoapp_last_stock_sync_count', $synced_count );
	update_option( 'cachicamoapp_last_stock_sync_errors', $error_count );
	update_option( 'cachicamoapp_last_stock_sync_not_found', $not_found_count );

	cachicamoapp_log( array( 
		'cachicamoapp_update_stock_from_api -> Batch sync completed' => array(
			'summary' => array(
				'total_products_processed' => count( $products ),
				'simple_products' => $simple_count,
				'variable_products' => $variable_count,
				'total_variations' => $variation_count,
			),
			'skus' => array(
				'total_skus_collected' => count( $all_skus ),
				'products_updated' => $synced_count,
				'not_found_in_api' => $not_found_count,
			),
			'processing' => array(
				'batches_processed' => count( $batches ),
				'errors' => $error_count,
			),
			'timestamp' => current_time( 'mysql' ),
		)
	), 'info' );
}
