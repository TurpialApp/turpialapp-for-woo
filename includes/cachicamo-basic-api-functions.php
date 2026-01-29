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

		// Call batch endpoint
		$response = wp_remote_post(
			CACHICAMO_APP_ENDPOINT . '/inventories/batch/sku',
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

				// Update stock in WooCommerce
				// Cachicamo is the source of truth - always overwrite
				$wc_product->set_manage_stock( true );
				$wc_product->set_stock_quantity( max( 0, $stock_quantity ) ); // Ensure non-negative
				$wc_product->set_stock_status( $stock_quantity > 0 ? 'instock' : 'outofstock' );
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
