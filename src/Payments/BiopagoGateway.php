<?php

namespace Cachicamo\WooCommerce\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * BDV_BIOPAGO: ProcessPaymentBDVBiopago (models/Payments/async_payments.go) requires the
 * buyer's dni, dni_type, email and cellphone; reference and product_name fall back to the
 * order's own data when absent.
 */
class BiopagoGateway extends AbstractCachicamoGateway {

	const TYPE = 'BDV_BIOPAGO';

	protected function icon_type() {
		return 'bdv_biopago';
	}

	protected function extra_fields( \WC_Order $order ) {
		$document = (string) $order->get_meta( '_cachicamo_document_id' );
		$dni_type = 'V';
		$dni      = $document;

		if ( 1 === preg_match( '/^([VEJGPC])(\d+)$/', $document, $matches ) ) {
			$dni_type = $matches[1];
			$dni      = $matches[2];
		}

		return array(
			'dni'          => $dni,
			'dni_type'     => $dni_type,
			'email'        => $order->get_billing_email(),
			'cellphone'    => $order->get_billing_phone(),
			'reference'    => (string) $order->get_order_number(),
			/* translators: %s: order number */
			'product_name' => sprintf( __( 'Order #%s', 'cachicamoapp-for-woo' ), $order->get_order_number() ),
		);
	}
}
