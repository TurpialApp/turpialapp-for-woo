<?php
/**
 * Product Functions
 *
 * Functions for managing product integration with TurpialApp.
 *
 * @package TurpialApp_For_WooCommerce
 * @since 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Search for a product in Turpial by SKU.
 *
 * Makes an API request to search for a product by its SKU and returns the matching product data.
 * Results are cached for 1 hour to improve performance.
 *
 * @since 1.0.0
 * @param string $sku The SKU to search for.
 * @return array|null Found product data array or null if not found/error.
 */
function turpialapp_search_product_by_sku( $sku ) {
	$key = turpialapp_access_token_key();
	if ( ! $key ) {
		return null;
	}
	$cache_key = 'wc_tapp_product_' . $sku . '_' . $key;
	$cache     = get_transient( $cache_key );
	if ( $cache ) {
		// Return cached product if available.
		return $cache;
	}

	$setting = turpialapp_setting();

	// Example API call to search for a product by SKU.
	$result = wp_remote_get(
		TURPIAL_APP_ENDPOINT . '/products/search?limit=10&page=1&sku=' . rawurlencode( $sku ),
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
		turpialapp_log( array( 'turpialapp_search_product_by_sku[' . $sku . '] -> error' => $result ), 'error' );
		return null;
	}
	turpialapp_log( array( 'turpialapp_search_product_by_sku[' . $sku . '] -> result' => $result ), 'info' );
	$decoded = json_decode( $result['body'], true );
	if ( isset( $decoded['rows'] ) && isset( $decoded['rows'][0] ) ) {
		foreach ( $decoded['rows'] as $row ) {
			if ( $row['with_variations'] ) {
				foreach ( $row['variations'] as $variation ) {
					if ( in_array( $sku, $variation['sku_list'], true ) ) {
						set_transient( $cache_key, $variation, 3600 ); // Cache the variation for 1 hour.
						return $variation;
					}
				}
			} elseif ( in_array( $sku, $row['sku_list'], true ) ) {
				set_transient( $cache_key, $row, 3600 ); // Cache the product for 1 hour.
				return $row;
			}
		}
		return null;
	}

	return null;
}

/**
 * Get or create a product attribute in Turpial.
 *
 * Searches for an existing attribute or creates a new one if not found.
 * Results are cached to improve performance.
 *
 * @since 1.0.0
 * @param string $attribute_group Name of the attribute group.
 * @param string $value Attribute value to find/create.
 * @return string|null UUID of the attribute or null on error.
 */
function turpialapp_get_create_attribute( $attribute_group, $value ) {
	$key = turpialapp_access_token_key();
	if ( ! $key ) {
		return null;
	}
	$cache_group_key      = 'wc_tapp_attribute_group_' . $attribute_group . '_' . $key;
	$cache_value_key      = 'wc_tapp_attribute_group_' . $attribute_group . '_' . $value . '_' . $key;
	$cache_group          = get_transient( $cache_group_key );
	$attribute_group_uuid = null;

	$setting = turpialapp_setting();

	if ( $cache_group ) {
		$attribute_group_uuid = $cache_group; // Use cached attribute group if available.
	} else {
		$result = wp_remote_get(
			TURPIAL_APP_ENDPOINT . '/products/attribute_group?limit=100&page=1&filter[name_group]=' . rawurlencode( $attribute_group ),
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $setting['access_token'],
					'Content-Type'  => 'application/json',
					'X-Store-Uuid'  => $setting['store_uuid'],
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			turpialapp_log( array( 'turpialapp_get_create_attribute -> attribute_group error' => $result ), 'error' );
			return null;
		}

		$decoded = json_decode( $result['body'], true );
		turpialapp_log( array( 'turpialapp_get_create_attribute -> search group result' => $decoded ), 'debug' );
		$attribute_group_uuid = null;
		if ( isset( $decoded['rows'][0] ) ) {
			foreach ( $decoded['rows'] as $row ) {
				if ( strtolower( $row['name_group'] ) === strtolower( $attribute_group ) ) {
					$attribute_group_uuid = $row['uuid'];
					turpialapp_log( array( 'turpialapp_get_create_attribute -> search group found' => $row ), 'info' );
					set_transient( $cache_group_key, $attribute_group_uuid, 3600 * 24 * 31 ); // Cache the attribute group for 31 days.
					break;
				}
			}
		}
	}

	if ( ! $attribute_group_uuid ) {
		// Create a new attribute group if it doesn't exist.
		$result = wp_remote_post(
			TURPIAL_APP_ENDPOINT . '/products/attribute_group',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $setting['access_token'],
					'Content-Type'  => 'application/json',
					'X-Store-Uuid'  => $setting['store_uuid'],
				),
				'body'    => wp_json_encode(
					array(
						'name_group' => $attribute_group,
						'is_visible' => true,
					)
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			turpialapp_log( array( 'turpialapp_get_create_attribute -> attribute_group error' => $result ), 'error' );
			return null;
		}

		$decoded = json_decode( $result['body'], true );

		if ( isset( $decoded['uuid'] ) ) {
			$attribute_group_uuid = $decoded['uuid'];
			turpialapp_log( array( 'turpialapp_get_create_attribute -> new group' => $decoded ), 'info' );
			set_transient( $cache_group_key, $attribute_group_uuid, 3600 * 24 * 31 ); // Cache the new attribute group for 31 days.
		} else {
			turpialapp_log( array( 'turpialapp_get_create_attribute -> attribute_group error2' => $result ), 'error' );
			return null;
		}
	}

	$cache_attribute = get_transient( $cache_value_key );
	if ( $cache_attribute ) {
		return $cache_attribute; // Return cached attribute if available.
	}
	$result = wp_remote_get(
		TURPIAL_APP_ENDPOINT . '/products/attribute?limit=100&page=1&filter[attribute_group_uuid]=' . $attribute_group_uuid,
		array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $setting['access_token'],
				'Content-Type'  => 'application/json',
				'X-Store-Uuid'  => $setting['store_uuid'],
			),
		)
	);

	if ( is_wp_error( $result ) ) {
		turpialapp_log( array( 'turpialapp_get_create_attribute -> attribute error' => $result ), 'error' );
		return null;
	}

	$decoded = json_decode( $result['body'], true );
	if ( isset( $decoded['rows'][0] ) ) {
		turpialapp_log( array( 'turpialapp_get_create_attribute -> result search' => $result ), 'debug' );
		foreach ( $decoded['rows'] as $row ) {
			if ( strtolower( $row['name_attribute'] ) === strtolower( $value ) ) {
				turpialapp_log( array( 'turpialapp_get_create_attribute -> found' => $row ), 'info' );
				set_transient( $cache_value_key, $row['uuid'], 3600 * 24 * 31 ); // Cache the attribute for 31 days.
				return $row['uuid'];
			}
		}
	}

	// Create a new attribute if it doesn't exist.
	$result = wp_remote_post(
		TURPIAL_APP_ENDPOINT . '/products/attribute/' . $attribute_group_uuid,
		array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $setting['access_token'],
				'Content-Type'  => 'application/json',
				'X-Store-Uuid'  => $setting['store_uuid'],
			),
			'body'    => wp_json_encode(
				array(
					'list_of_attributes' => array(
						'name_attribute' => $value,
						'customizable'   => false,
					),
				)
			),
		)
	);

	if ( is_wp_error( $result ) ) {
		turpialapp_log( array( 'turpialapp_get_create_attribute -> attribute error' => $result ), 'error' );
		return null;
	}

	$decoded = json_decode( $result['body'], true );
	if ( isset( $decoded['uuid'] ) ) {
		turpialapp_log( array( 'turpialapp_get_create_attribute -> result new' => $result ), 'info' );
		set_transient( $cache_value_key, $decoded['uuid'], 3600 * 24 * 31 ); // Cache the new attribute for 31 days.
		return $decoded['uuid'];
	}
}

/**
 * Export product attributes to Turpial.
 *
 * Exports all attributes from a WooCommerce product to Turpial system.
 * Creates attributes if they don't exist.
 *
 * @since 1.0.0
 * @param WC_Product $product WooCommerce product object to export attributes from.
 * @return array List of attribute UUIDs that were exported.
 */
function turpialapp_export_from_product_attributes( $product ) {
	$attributes = $product->get_attributes();
	$taxonomies = wc_get_attribute_taxonomies();
	$result     = array();
	foreach ( $attributes as $attribute_group_slug_key => $attribute_value ) {
		if ( is_string( $attribute_value ) ) {
			$group_name = explode( '-', $attribute_group_slug_key );
			$group_name = implode( ' ', $group_name );
			// Upper case first letter of each word.
			$group_name     = ucwords( $group_name );
			$attribute_uuid = turpialapp_get_create_attribute( $group_name, $attribute_value );
			if ( $attribute_uuid ) {
				$result[] = $attribute_uuid; // Add the attribute UUID to the result.
			}
		}
	}

	return $result; // Return the list of attribute UUIDs.
}

/**
 * Get or create a product in Turpial.
 *
 * Searches for an existing product by SKU or creates a new one.
 * Handles both simple and variable products.
 * For variable products, also exports all variations.
 *
 * @since 1.0.0
 * @param int         $product_id WooCommerce product ID to export.
 * @param string|null $product_parent_uuid Parent product UUID in Turpial (for variations).
 * @return array|null Array containing product data or null on error. Format:
 *                    ['product' => array, 'children' => array, 'parent' => array].
 */
function turpialapp_get_or_export_product( $product_id, $product_parent_uuid = null ) {
	$setting  = turpialapp_setting();
	$product  = wc_get_product( $product_id );
	$sku      = $product->get_sku();
	$with_sku = true;
	if ( ! $sku ) {
		$sku      = 'woo-' . $product_id; // Generate SKU if not available.
		$with_sku = false;
	}

	$exists = turpialapp_search_product_by_sku( $sku );

	if ( $exists ) {
		return array( 'product' => $exists ); // Return existing product if found.
	}

	// Check if product is variable.
	$variations = $product->get_children();

	$setting = turpialapp_setting();

	if ( $variations && count( $variations ) > 0 ) {
		$request = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $setting['access_token'],
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				'X-Store-Uuid'  => $setting['store_uuid'],
			),
			'body'    => wp_json_encode(
				array(
					'active'          => true,
					'attributes'      => array(),
					'categories'      => array(),
					'description'     => $product->get_description(),
					'name'            => $product->get_title(),
					'virtual'         => (bool) $product->is_virtual(),
					'weight'          => (float) $product->get_weight(),
					'width'           => (float) $product->get_width(),
					'height'          => (float) $product->get_height(),
					'depth'           => (float) $product->get_length(),
					'with_variations' => true,
				)
			),
		);

		$result = wp_remote_post( TURPIAL_APP_ENDPOINT . '/products', $request );
		if ( is_wp_error( $result ) ) {
			turpialapp_log(
				array(
					'turpialapp_export_product -> error' => $result,
					'request'                            => $request,
				),
				'error'
			);
			return null;
		}
		$decoded = json_decode( $result['body'], true );
		if ( isset( $decoded['uuid'] ) ) {
			$parent_uuid = $decoded['uuid'];
			$result      = array(
				'product'  => $decoded,
				'children' => array(),
			);
			foreach ( $variations as $variation_id ) {
				$child = turpialapp_get_or_export_product( $variation_id, $parent_uuid );
				if ( $child ) {
					$result['children'][] = $child['product']; // Add child product to the result.
				}
			}

			turpialapp_log(
				array(
					'turpialapp_export_product -> result' => $result,
					'product_id'                          => $product_id,
					'request'                             => $request,
				),
				'info'
			);
			return $result;
		}

		turpialapp_log(
			array(
				'turpialapp_export_product -> error result parent' => $decoded,
				'product_id' => $product_id,
				'request'    => $request,
			),
			'error'
		);
		return null;
	}
	// Check if current product is child of another.
	if ( $product->get_parent_id() && ! $product_parent_uuid ) {
		$result = turpialapp_get_or_export_product( $product->get_parent_id() );
		if ( $result ) {
			foreach ( $result['children'] as $child ) {
				if ( in_array( $sku, $child['sku_list'], true ) ) {
					turpialapp_log(
						array(
							'turpialapp_export_product -> child found in parent result' => $child,
							'product_id' => $product_id,
							'sku'        => $sku,
							'request'    => $request,
						),
						'info'
					);
					return array(
						'product' => $child,
						'parent'  => $result['product'],
					); // Return child product found in parent.
				}
			}
		}
		turpialapp_log(
			array(
				'turpialapp_export_product -> child not found in parent result' => $result,
				'product_id' => $product_id,
				'sku'        => $sku,
				'request'    => $request,
			),
			'warning'
		);

		return null;
	}
	$attributes = turpialapp_export_from_product_attributes( $product );
	$request    = array(
		'headers' => array(
			'Authorization' => 'Bearer ' . $setting['access_token'],
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
			'X-Store-Uuid'  => $setting['store_uuid'],
		),
		'body'    => wp_json_encode(
			array(
				'active'          => true,
				'attributes'      => $attributes,
				'categories'      => array(),
				'description'     => $product->get_description(),
				'parent_uuid'     => $product_parent_uuid,
				'sku_list'        => $with_sku ? array( $sku, 'woo-' . $product_id ) : array( $sku ),
				'virtual'         => (bool) $product->is_virtual(),
				'weight'          => (float) $product->get_weight(),
				'width'           => (float) $product->get_width(),
				'height'          => (float) $product->get_height(),
				'depth'           => (float) $product->get_length(),
				'with_variations' => false,
			)
		),
	);
	$result     = wp_remote_post( TURPIAL_APP_ENDPOINT . '/products', $request );

	if ( is_wp_error( $result ) ) {
		turpialapp_log( array( 'turpialapp_export_product -> error' => $result ), 'error' );
		return null;
	}

	$decoded = json_decode( $result['body'], true );
	if ( isset( $decoded['uuid'] ) ) {
		turpialapp_log(
			array(
				'turpialapp_export_product -> result' => array( 'product' => $decoded ),
				'product_id'                          => $product_id,
				'request'                             => $request,
			),
			'info'
		);
		return array( 'product' => $decoded ); // Return newly created product.
	}

	turpialapp_log(
		array(
			'turpialapp_export_product -> error result' => $decoded,
			'product_id'                                => $product_id,
			'request'                                   => $request,
		),
		'error'
	);
	return null;
}
