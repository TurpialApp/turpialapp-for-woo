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
 * Get all available currencies from Cachicamo API.
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
 * Update product stock from Cachicamo API.
 *
 * Synchronizes inventory from Cachicamo to WooCommerce.
 * Cachicamo is the source of truth - stock values will always be overwritten.
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

	cachicamoapp_log( array( 'cachicamoapp_update_stock_from_api -> Starting stock sync' ), 'info' );

	// Get all WooCommerce products (simple and variable)
	$args = array(
		'status' => 'publish',
		'limit'  => -1, // Get all products
		'type'   => array( 'simple', 'variable' ),
	);

	$products = wc_get_products( $args );
	$synced_count = 0;
	$error_count = 0;

	foreach ( $products as $product ) {
		$sku = $product->get_sku();
		
		// Skip products without SKU
		if ( empty( $sku ) ) {
			continue;
		}

		// Search for product in Cachicamo by SKU
		$cachicamo_product = cachicamoapp_search_product_by_sku( $sku );
		
		if ( ! $cachicamo_product ) {
			cachicamoapp_log( array( 'cachicamoapp_update_stock_from_api -> Product not found in Cachicamo' => $sku ), 'debug' );
			continue;
		}

		// Get stock quantity from Cachicamo
		$stock_quantity = null;
		
		// If we have inventory data, use it
		if ( isset( $cachicamo_product['inventory'] ) && isset( $cachicamo_product['inventory']['quantity'] ) ) {
			$stock_quantity = floatval( $cachicamo_product['inventory']['quantity'] );
		} elseif ( isset( $cachicamo_product['quantity'] ) ) {
			$stock_quantity = floatval( $cachicamo_product['quantity'] );
		} else {
			// Try to get inventory from the store
			$inventory_result = wp_remote_get(
				CACHICAMO_APP_ENDPOINT . '/inventories/uuid/' . ( isset( $cachicamo_product['product']['uuid'] ) ? $cachicamo_product['product']['uuid'] : $cachicamo_product['uuid'] ),
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $setting['access_token'],
						'Content-Type'  => 'application/json',
						'X-Store-Uuid'  => $setting['store_uuid'],
					),
				)
			);
			
			if ( ! is_wp_error( $inventory_result ) ) {
				$inventory_data = json_decode( $inventory_result['body'], true );
				if ( isset( $inventory_data['quantity'] ) ) {
					$stock_quantity = floatval( $inventory_data['quantity'] );
				}
			}
		}

		if ( $stock_quantity === null ) {
			cachicamoapp_log( array( 'cachicamoapp_update_stock_from_api -> Could not determine stock quantity' => $sku ), 'warning' );
			$error_count++;
			continue;
		}

		// Update stock in WooCommerce
		// Cachicamo is the source of truth - always overwrite
		$product->set_manage_stock( true );
		$product->set_stock_quantity( max( 0, $stock_quantity ) ); // Ensure non-negative
		$product->set_stock_status( $stock_quantity > 0 ? 'instock' : 'outofstock' );
		
		// Handle variable products
		if ( $product->is_type( 'variable' ) ) {
			$variations = $product->get_children();
			foreach ( $variations as $variation_id ) {
				$variation = wc_get_product( $variation_id );
				if ( ! $variation ) {
					continue;
				}
				
				$variation_sku = $variation->get_sku();
				if ( empty( $variation_sku ) ) {
					continue;
				}
				
				// Search for variation in Cachicamo
				$cachicamo_variation = cachicamoapp_search_product_by_sku( $variation_sku );
				if ( ! $cachicamo_variation ) {
					continue;
				}
				
				$variation_stock = null;
				if ( isset( $cachicamo_variation['inventory'] ) && isset( $cachicamo_variation['inventory']['quantity'] ) ) {
					$variation_stock = floatval( $cachicamo_variation['inventory']['quantity'] );
				} elseif ( isset( $cachicamo_variation['quantity'] ) ) {
					$variation_stock = floatval( $cachicamo_variation['quantity'] );
				}
				
				if ( $variation_stock !== null ) {
					$variation->set_manage_stock( true );
					$variation->set_stock_quantity( max( 0, $variation_stock ) );
					$variation->set_stock_status( $variation_stock > 0 ? 'instock' : 'outofstock' );
					$variation->save();
				}
			}
		}
		
		$product->save();
		$synced_count++;
		
		cachicamoapp_log( array( 
			'cachicamoapp_update_stock_from_api -> Updated stock' => array(
				'sku' => $sku,
				'quantity' => $stock_quantity,
			)
		), 'info' );
	}

	// Update last sync time
	update_option( 'cachicamoapp_last_stock_sync', current_time( 'mysql' ) );
	update_option( 'cachicamoapp_last_stock_sync_count', $synced_count );
	update_option( 'cachicamoapp_last_stock_sync_errors', $error_count );

	cachicamoapp_log( array( 
		'cachicamoapp_update_stock_from_api -> Sync completed' => array(
			'synced' => $synced_count,
			'errors' => $error_count,
		)
	), 'info' );
}
