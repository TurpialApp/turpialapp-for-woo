<?php

namespace Cachicamo\WooCommerce\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * CASHEA_LINK: ProcessPaymentCasheaLink (models/Payments/async_payments.go) requires
 * identification_number, the buyer's document without its person-type letter.
 */
class CasheaLinkGateway extends AbstractCachicamoGateway {

	const TYPE = 'CASHEA_LINK';

	protected function icon_type() {
		return 'cashea_link';
	}

	protected function extra_fields( \WC_Order $order ) {
		$document = (string) $order->get_meta( '_cachicamo_document_id' );

		return array(
			'identification_number' => preg_replace( '/^[VEJGPC]/', '', $document ),
		);
	}
}
