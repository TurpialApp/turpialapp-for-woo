<?php

namespace Cachicamo\WooCommerce\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * WAYU_PAY: the core opens a remote checkout session and only needs a label for it, both
 * optional in ProcessPaymentWayuPay (models/Payments/async_payments.go).
 */
class WayuPayGateway extends AbstractCachicamoGateway {

	const TYPE = 'WAYU_PAY';

	protected function icon_type() {
		return 'wayu_pay';
	}

	protected function extra_fields( \WC_Order $order ) {
		$names = array();
		foreach ( $order->get_items() as $item ) {
			$names[] = $item->get_name();
		}

		return array(
			/* translators: %s: order number */
			'product_name'        => sprintf( __( 'Order #%s', 'cachicamoapp-for-woo' ), $order->get_order_number() ),
			'product_description' => wp_strip_all_tags( implode( ', ', $names ) ),
		);
	}
}
