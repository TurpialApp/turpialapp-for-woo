<?php

namespace Cachicamo\WooCommerce\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * SPIDI: the core opens a payment session and shows the buyer's identifier and a charge detail,
 * both optional in ProcessPaymentSpidi (models/Payments/async_payments.go).
 */
class SpidiGateway extends AbstractCachicamoGateway {

	const TYPE = 'SPIDI';

	protected function icon_type() {
		return 'spidi';
	}

	protected function extra_fields( \WC_Order $order ) {
		$name = trim( $order->get_formatted_billing_full_name() );

		return array(
			'identifier'          => '' !== $name ? $name : __( 'Customer', 'cachicamoapp-for-woo' ),
			/* translators: %s: order number */
			'product_description' => sprintf( __( 'Order #%s', 'cachicamoapp-for-woo' ), $order->get_order_number() ),
		);
	}
}
