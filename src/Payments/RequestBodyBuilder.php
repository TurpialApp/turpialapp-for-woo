<?php

namespace Cachicamo\WooCommerce\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the POST /async_payments/ body (AsyncPaymentCreateBody in the core), kept pure and
 * apart from AbstractCachicamoGateway::process_payment so it is testable without WooCommerce.
 */
class RequestBodyBuilder {

	/**
	 * @param array<string,mixed> $extra_fields
	 * @return array<string,mixed>
	 */
	public static function build( $payment_method_uuid, $amount, $store_uuid, $order_id, array $extra_fields ) {
		return array(
			'payment_method_uuid'  => (string) $payment_method_uuid,
			'origin_total_payment' => (int) $amount,
			'order_reference'      => $store_uuid . ':' . $order_id,
			'extra_fields'         => $extra_fields,
		);
	}
}
