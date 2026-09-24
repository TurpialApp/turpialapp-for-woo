<?php

namespace Cachicamo\WooCommerce\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * A Cachicamo COMBO comes down as a simple virtual product in WooCommerce, stock-managed with a
 * computed availability instead of its own inventory row. wp_cachicamo_combo_components
 * (Schema::install) holds the proportions so a component's stock.updated can recompute every
 * combo that uses it without another call to the core.
 */
class Combos {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'cachicamo_combo_components';
	}

	/**
	 * Replaces a combo's component list, one row per component, keyed by (combo_uuid,
	 * component_uuid) so a later import that resends the same combo just overwrites quantities.
	 *
	 * @param string $combo_uuid
	 * @param array<int,array{product_uuid?:string,id?:string,quantity:float}> $components As
	 *        GET /products nests them (`product_uuid`); `id` is accepted too since it's the
	 *        export-side field name of the same relation (ProductBulkJSONComboItem).
	 */
	public static function set_components( $combo_uuid, array $components ) {
		global $wpdb;

		$wpdb->delete( self::table(), array( 'combo_uuid' => $combo_uuid ), array( '%s' ) );

		foreach ( $components as $component ) {
			$component_uuid = isset( $component['product_uuid'] ) ? $component['product_uuid'] : ( isset( $component['id'] ) ? $component['id'] : null );
			if ( null === $component_uuid || ! isset( $component['quantity'] ) ) {
				continue;
			}
			$wpdb->insert(
				self::table(),
				array(
					'combo_uuid'     => $combo_uuid,
					'component_uuid' => $component_uuid,
					'quantity'       => $component['quantity'],
				),
				array( '%s', '%s', '%f' )
			);
		}
	}

	/**
	 * @return array<int,array{component_uuid:string,quantity:float}>
	 */
	public static function components_for( $combo_uuid ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT component_uuid, quantity FROM %i WHERE combo_uuid = %s',
				self::table(),
				$combo_uuid
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @return array<int,string> combo_uuid of every combo that uses $component_uuid
	 */
	public static function combos_using( $component_uuid ) {
		global $wpdb;
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT combo_uuid FROM %i WHERE component_uuid = %s',
				self::table(),
				$component_uuid
			)
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Combo availability = min across components of floor(billable / proportion).
	 * A component with zero billable stock, or a combo with no components, is 0 available.
	 *
	 * @param array<string,float> $billable_by_component component_uuid => billable stock
	 * @param array<int,array{component_uuid:string,quantity:float}> $components
	 * @return int
	 */
	public static function available_quantity( array $components, array $billable_by_component ) {
		if ( empty( $components ) ) {
			return 0;
		}

		$available = null;

		foreach ( $components as $component ) {
			$quantity = (float) $component['quantity'];
			if ( $quantity <= 0 ) {
				return 0;
			}

			$billable = isset( $billable_by_component[ $component['component_uuid'] ] )
				? (float) $billable_by_component[ $component['component_uuid'] ]
				: 0.0;

			$possible = (int) floor( $billable / $quantity );

			$available = null === $available ? $possible : min( $available, $possible );
		}

		return max( 0, (int) $available );
	}

	/**
	 * Recomputes and writes stock for the WooCommerce combo product linked to $combo_uuid, using
	 * each component's last known billable stock (the value Stock::apply() left in
	 * wp_cachicamo_links when that component's own stock.updated last landed).
	 *
	 * @param string $combo_uuid
	 */
	public static function recalculate( $combo_uuid ) {
		$wc_id = Links::get_wc_id( $combo_uuid );
		if ( null === $wc_id ) {
			return;
		}

		$product = wc_get_product( $wc_id );
		if ( ! $product ) {
			return;
		}

		$components = self::components_for( $combo_uuid );
		$billable   = array();

		foreach ( $components as $component ) {
			$component_wc_id = Links::get_wc_id( $component['component_uuid'] );
			$row              = null === $component_wc_id ? null : Links::row( $component_wc_id );
			$billable[ $component['component_uuid'] ] = null !== $row && null !== $row['stock_billable']
				? (float) $row['stock_billable']
				: 0.0;
		}

		$quantity = self::available_quantity( $components, $billable );

		$product->set_manage_stock( true );
		$product->set_stock_quantity( $quantity );
		$product->set_stock_status( $quantity > 0 ? 'instock' : 'outofstock' );
		$product->save();
	}
}
