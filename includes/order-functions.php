<?php
/**
 * Order and Invoice Management Functions.
 *
 * Functions for handling orders and invoices with TurpialApp integration.
 *
 * @package TurpialApp_For_WooCommerce
 * @since 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exports an order to TurpialApp.
 *
 * Takes a WooCommerce order and exports it to TurpialApp system including
 * customer data, line items, taxes, shipping and payment information.
 *
 * @since 1.0.0
 * @param WC_Order $order WooCommerce order object to export.
 * @return void
 */
function turpialapp_export_order( $order ) {
	if ( ! is_object( $order ) ) {
		return; // Exit if the order is not a valid object.
	}
	$setting       = turpialapp_setting();
	$turpial_taxes = turpialapp_get_all_taxes();
	$order_id      = $order->get_id();
	$woo_rate      = $order->get_meta( 'currency_convertion_rate', true );
	if ( ! $woo_rate ) {
		$woo_rate = $order->get_meta( '_kbdvpagomovil_rate', true ); // Fallback to another rate if not found.
	}
	turpialapp_log(
		array(
			'turpialapp_export_orders -> Exporting: ' => $order_id,
			'woo_rate'                                => $woo_rate,
		),
		'info'
	);
	$total_taxes    = $order->get_total_tax();
	$total_shipping = $order->get_total_shipping();
	$items          = $order->get_items();

	$total = $order->get_total();
	if ( 0.01 > $total ) {
		turpialapp_log(
			array(
				'turpialapp_export_orders -> Invalid total' => $total,
				'order_id',
				$order_id,
			),
			'warning'
		);
		$order->update_meta_data( '_turpialapp_invoice_last_error', 'invalid total ' . $total );
		$order->save();
		return;
	}
	$payment_method_id = $order->get_payment_method();
	if ( ! isset( $setting[ 'payment_method_' . $payment_method_id ] ) ) {
		turpialapp_log(
			array(
				'turpialapp_export_orders -> Invalid payment method' => $payment_method_id,
				'order_id',
				$order_id,
			),
			'warning'
		);
		$order->update_meta_data( '_turpialapp_invoice_last_error', 'invalid payment method ' . $payment_method_id );
		$order->save();
		return;
	}

	$woo_list_state_country = WC()->countries->get_states( $order->get_billing_country() );
	$state_name             = isset( $woo_list_state_country[ $order->get_billing_state() ] ) ? $woo_list_state_country[ $order->get_billing_state() ] : $order->get_billing_state();
	$dni                    = apply_filters( 'woo_turpialapp_dni', null, $order );
	$customer               = turpialapp_get_or_export_customer(
		$order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
		$order->get_billing_email(),
		$dni,
		$order->get_billing_phone(),
		trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() ),
		$order->get_billing_city(),
		$state_name,
		$order->get_billing_postcode(),
		$order->get_billing_country()
	);
	if ( ! $customer ) {
		turpialapp_log( array( 'turpialapp_export_orders -> Error export customer to turpial', 'order_id', $order_id ), 'error' );
		$order->update_meta_data( '_turpialapp_invoice_last_error', 'error export customer to turpial' );
		$order->save();
		return;
	}

	$woo_currency_iso = $order->get_currency();
	$turpial_currency = turpialapp_get_all_currencies()[ $woo_currency_iso ] ?? null;
	if ( ! $turpial_currency ) {
		turpialapp_log(
			array(
				'turpialapp_export_orders -> Invalid currency' => $woo_currency_iso,
				'order_id',
				$order_id,
			),
			'warning'
		);
		$order->update_meta_data( '_turpialapp_invoice_last_error', 'invalid currency ' . $woo_currency_iso );
		$order->save();
		return;
	}

	if ( null === $turpial_taxes ) {
		turpialapp_log( array( 'turpialapp_export_orders -> Error import taxes from turpial', 'order_id', $order_id ), 'error' );
		$order->update_meta_data( '_turpialapp_invoice_last_error', 'error import taxes from turpial' );
		$order->save();
		return;
	}
	$items_array = array();
	foreach ( $items as $item_key => $item ) {
		$variation_id    = $item->get_variation_id();
		$product_id      = $item->get_product_id();
		$final_id        = $variation_id > 0 ? $variation_id : $product_id;
		$turpial_product = turpialapp_get_or_export_product( $final_id );
		if ( ! $turpial_product ) {
			turpialapp_log(
				array(
					'turpialapp_export_orders -> Error export product to turpial' => $final_id,
					'order_id',
					$order_id,
				),
				'error'
			);
			$order->update_meta_data( '_turpialapp_invoice_last_error', 'error export product to turpial - product id: ' . $final_id );
			$order->save();
			return;
		}
		turpialapp_log( array( 'turpialapp_export_orders -> product found' => $turpial_product ), 'info' );
		$product  = $item->get_product();
		$quantity = $item->get_quantity();
		$subtotal = $item->get_subtotal();
		$total    = $item->get_total();

		$unit_subtotal = $subtotal / $quantity;
		$unit_total    = $total / $quantity;

		$total_tax  = (float) $item->get_subtotal_tax();
		$tax_rate   = ( $total_tax / $subtotal ) * 100;
		$taxes_uuid = array();
		foreach ( $turpial_taxes as $t_tax ) {
			if ( $t_tax['sum_to_invoice'] && abs( $t_tax['tax_rate'] - $tax_rate ) < 0.01 ) {
				$taxes_uuid[] = $t_tax['uuid'];
				break;
			}
		}
		if ( count( $taxes_uuid ) === 0 && $tax_rate >= 0.01 ) {
			turpialapp_log(
				array(
					'turpialapp_export_orders -> Error tax not found in turpial' => $tax_rate,
					'order_id',
					$order_id,
				),
				'warning'
			);
			$order->update_meta_data( '_turpialapp_invoice_last_error', 'tax not found in turpial - tax rate: ' . $tax_rate );
			$order->save();
			return;
		}
		$items_array[] = array(
			'product_uuid'         => $turpial_product['product']['uuid'],
			'qty'                  => $quantity,
			'custom_unit_price'    => (int) round( $unit_subtotal * 10000 ),
			'custom_currency_uuid' => $turpial_currency['uuid'],
			'request_reference'    => '' . $item_key,
			'taxes_uuid'           => $taxes_uuid,
		);
	}
	// Check shipping cost.
	$shipping_total = $order->get_total_shipping();
	if ( $shipping_total > 0 ) {
		// Tax for shipping.
		$shipping_tax        = $order->get_shipping_tax();
		$tax_rate            = ( $shipping_tax / ( $shipping_total - $shipping_tax ) ) * 100;
		$shipping_taxes_uuid = array();
		foreach ( $turpial_taxes as $t_tax ) {
			if ( $t_tax['sum_to_invoice'] && abs( $t_tax['tax_rate'] - $tax_rate ) < 0.01 ) {
				$shipping_taxes_uuid[] = $t_tax['uuid'];
				break;
			}
		}
		if ( count( $shipping_taxes_uuid ) === 0 && $tax_rate >= 0.01 ) {
			turpialapp_log(
				array(
					'turpialapp_export_orders -> Shipping tax not found in turpial - tax rate: ' => $tax_rate,
					'order_id',
					$order_id,
				),
				'warning'
			);
			$order->update_meta_data( '_turpialapp_invoice_last_error', 'error, shipping tax not found in turpial - tax rate: ' . $tax_rate );
			$order->save();
			return;
		}
		$items_array[] = array(
			'qty'                  => 1,
			'custom_product_name'  => $order->get_shipping_method(),
			'custom_unit_price'    => (int) round( $shipping_total * 10000 ),
			'custom_currency_uuid' => $turpial_currency['uuid'],
			'request_reference'    => 'shipping',
		);
	}
	$turpialapp_taxes = turpialapp_get_all_taxes();
	$fees             = $order->get_fees();
	foreach ( $fees as $fee ) {
		$fee_found = false;
		foreach ( $turpialapp_taxes as $turpialapp_tax ) {
			if ( $turpialapp_tax['sum_to_invoice'] && $turpialapp_tax['name'] === $fee->name ) {
				$fee_found = true;
				break;
			}
		}
		if ( ! $fee_found ) {
			$items_array[] = array(
				'qty'                  => 1,
				'custom_product_name'  => $fee->name,
				'custom_unit_price'    => (int) round( $fee->amount * 10000 ),
				'custom_currency_uuid' => $turpial_currency['uuid'],
				'request_reference'    => 'fee',
			);
		}
	}

	$order_created = $order->get_date_created()->date( 'Y-m-d' );

	$invoice = array(
		'invoice_type'          => 'INVOICE',
		'customer_uuid'         => $customer['uuid'],
		'rate_date'             => $order_created,
		'products'              => $items_array,
		'payments'              => array(
			array(
				'payment_method_uuid' => $setting[ 'payment_method_' . $payment_method_id ],
				'total_payment'       => 1,
			),
		),
		'printer_document_uuid' => $setting['printer_document_uuid'],
	);

	$invoice = apply_filters( 'turpialapp_invoice_data_preview', $invoice, $order );
	turpialapp_log(
		array(
			'turpialapp_export_orders -> Invoice: ' => $invoice,
			'order_id',
			$order_id,
		),
		'info'
	);
	$setting = turpialapp_setting();
	$result  = wp_remote_post(
		TURPIAL_APP_ENDPOINT . '/invoices/preview',
		array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $setting['access_token'],
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				'X-Store-Uuid'  => $setting['store_uuid'],
			),
			'body'    => wp_json_encode( $invoice ),
		)
	);
	if ( is_wp_error( $result ) ) {
		turpialapp_log( array( 'turpialapp_export_orders[' . $order_id . '] -> error1' => $result->get_error_message() ), 'error' );
		$order->update_meta_data( '_turpialapp_invoice_last_error', $result->get_error_message() );
		$order->save();
		return;
	}
	$decoded = json_decode( $result['body'], true );
	turpialapp_log( array( 'turpialapp_export_orders[' . $order_id . '] -> result_preview' => $decoded ), 'debug' );
	if ( ! isset( $decoded['currency_rate_by_payment_methods'][ $payment_method_id ] ) ) {
		turpialapp_log( array( 'turpialapp_export_orders[' . $order_id . '] -> error2' => $decoded ), 'error' );
		$order->update_meta_data( '_turpialapp_invoice_last_error', 'api-error: ' . wp_json_encode( $decoded ) );
		$order->save();
		return;
	}

	$payment_rate = $decoded['currency_rate_by_payment_methods'][ $payment_method_id ];
	$invoice_rate = $decoded['currency_rate_invoice'];

	$invoice['payments'][0]['total_payment'] = round( ( $decoded['invoice']['total'] / $invoice_rate ) * $payment_rate, 0 );

	$invoice = apply_filters( 'turpialapp_invoice_data', $invoice, $decoded, $order );
	$result  = wp_remote_post(
		TURPIAL_APP_ENDPOINT . '/invoices/save_preview',
		array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $setting['access_token'],
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				'X-Store-Uuid'  => $setting['store_uuid'],
			),
			'body'    => wp_json_encode( $invoice ),
		)
	);
	$decoded = json_decode( $result['body'], true );
	turpialapp_log( array( 'turpialapp_export_orders[' . $order_id . '] -> result3' => $decoded ), 'info' );

	if ( is_wp_error( $result ) ) {
		turpialapp_log( array( 'turpialapp_export_orders[' . $order_id . '] -> error4' => $result->get_error_message() ), 'error' );
		return;
	}
	$decoded = json_decode( $result['body'], true );
	turpialapp_log( array( 'turpialapp_export_orders[' . $order_id . '] -> result' => $decoded ), 'info' );

	if ( ! isset( $decoded['invoice']['uuid'] ) ) {
		turpialapp_log( array( 'turpialapp_export_orders[' . $order_id . '] -> error' => $decoded ), 'error' );
		$order->update_meta_data( '_turpialapp_invoice_last_error', 'api-error' );
		$order->update_meta_data( '_turpialapp_invoice', $decoded );
		$order->save();
		return;
	}
	$order->update_meta_data( '_turpialapp_invoice_uuid', $decoded['invoice']['uuid'] );
	$order->update_meta_data( '_turpialapp_invoice_last_error', '' );
	$order->update_meta_data( '_turpialapp_invoice', $decoded );
	$order->save();
}

/**
 * Exports all pending orders to TurpialApp.
 *
 * Processes orders that haven't been exported yet based on configuration settings
 * and date filters.
 *
 * @since 1.0.0
 * @param bool $force Whether to force export regardless of settings.
 * @return void
 */
function turpialapp_export_orders( $force = false ) {
	global $wpdb;
	$setting           = turpialapp_setting();
	$send_order        = $setting['send_order'] ?? 'no';
	$export_order_date = $setting['export_order_date'] ?? '';
	if ( ! $force && ( ! $export_order_date || ! $send_order || 'no' === $send_order ) ) {
		return; // Exit if conditions for exporting orders are not met.
	}
	$export_order_date_time = strtotime( $export_order_date );
	if ( ! $export_order_date_time ) {
		turpialapp_log( array( 'turpialapp_export_orders -> Invalid date' => $export_order_date ), 'warning' );
		return;
	}
	$export_order_date = gmdate( 'Y-m-d', $export_order_date_time );

	// Query orders using WP_Query instead of direct DB query.
	$args = array(
		'post_type'      => 'shop_order',
		'post_status'    => array( 'wc-processing', 'wc-completed' ),
		'posts_per_page' => 10,
		'date_query'     => array(
			array(
				'after'     => $export_order_date . ' 00:00:00',
				'inclusive' => true,
			),
		),
		'meta_query'     => array(
			array(
				'key'     => '_turpialapp_invoice_uuid',
				'compare' => 'NOT EXISTS',
			),
		),
		'fields'         => 'ids',
	);

	$query  = new WP_Query( $args );
	$orders = $query->posts;

	if ( empty( $orders ) ) {
		turpialapp_log( array( 'turpialapp_export_orders -> Order not found ' => $orders ), 'warning' );
		return;
	}

	turpialapp_log( array( 'turpialapp_export_orders -> Processing: ' => $orders ), 'info' );

	foreach ( $orders as $order ) {
		turpialapp_export_order( wc_get_order( $order->ID ) ); // Export each order.
	}
}

/**
 * Hook to detect order status changes and trigger exports.
 *
 * Listens for WooCommerce order status changes and exports orders to TurpialApp
 * when they reach completed or processing status.
 *
 * @since 1.0.0
 * @param int    $order_id The order ID that changed status.
 * @param string $old_status Previous order status.
 * @param string $new_status New order status.
 * @return void
 */
add_action(
	'woocommerce_order_status_changed',
	function ( $order_id, $old_status, $new_status ) {
		// Get the settings.
		$setting = turpialapp_setting();

		// Check if automatic sending is enabled.
		if ( ! isset( $setting['send_order'] ) || 'yes' !== $setting['send_order'] ) {
			return;
		}

		$order        = wc_get_order( $order_id );
		$invoice_uuid = $order->get_meta( '_turpialapp_invoice_uuid' );

		// Check if the new status is 'completed' or 'processing'.
		if ( ! $invoice_uuid && ( 'completed' === $new_status || 'processing' === $new_status ) ) {
			turpialapp_log(
				array(
					'auto_export_order' => array(
						'order_id'   => $order_id,
						'old_status' => $old_status,
						'new_status' => $new_status,
					),
				)
			);

			// Export the order to Turpial.
			turpialapp_export_order( wc_get_order( $order_id ) );
		}
	},
	10,
	3
);
