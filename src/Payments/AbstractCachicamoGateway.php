<?php

namespace Cachicamo\WooCommerce\Payments;

use Cachicamo\WooCommerce\Api\Routes;
use Cachicamo\WooCommerce\Plugin;
use Cachicamo\WooCommerce\Settings\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * One instance per row of the Cachicamo payment method catalog eligible for online payment
 * (GatewayRegistry decides eligibility). The gateway id carries the payment method uuid
 * (`cachicamo_<uuid>`) so OrderMeta and PaymentTaxes resolve it without a manual mapping entry.
 * A subclass only supplies its icon and the extra_fields the core's provider requires.
 */
abstract class AbstractCachicamoGateway extends \WC_Payment_Gateway {

	const ID_PREFIX = 'cachicamo_';

	/** @var array<string,mixed> */
	protected $payment_method_row;

	/** @var string */
	protected $payment_method_uuid;

	public function __construct( array $payment_method_row ) {
		$this->payment_method_row = $payment_method_row;
		$this->payment_method_uuid = isset( $payment_method_row['uuid'] ) ? (string) $payment_method_row['uuid'] : '';

		$this->id                 = self::ID_PREFIX . $this->payment_method_uuid;
		$this->method_title       = isset( $payment_method_row['name'] ) ? (string) $payment_method_row['name'] : $this->id;
		$this->method_description = $this->method_title;
		$this->has_fields         = false;
		$this->supports           = array( 'products' );
		$this->icon               = CACHICAMO_APP_URL . 'assets/icons/' . $this->icon_type() . '.svg';

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->method_title;
		$this->description = '';
		$this->enabled      = '' !== $this->payment_method_uuid ? 'yes' : 'no';
	}

	public function init_form_fields() {
		$this->form_fields = array();
	}

	public function is_available() {
		if ( '' === $this->payment_method_uuid ) {
			return false;
		}
		return parent::is_available();
	}

	public function payment_method_uuid() {
		return $this->payment_method_uuid;
	}

	/**
	 * @return string One of assets/icons/<type>.svg without the extension.
	 */
	abstract protected function icon_type();

	/**
	 * @return array<string,mixed> extra_fields for AsyncPaymentCreateBody, shaped for this
	 * provider's ProcessPayment* in the core.
	 */
	abstract protected function extra_fields( \WC_Order $order );

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return $this->fail_with_notice( null );
		}

		$client = Plugin::instance()->service( 'api_client' );
		if ( null === $client ) {
			return $this->fail_with_notice( $order );
		}

		$amount = (int) $order->get_meta( '_cachicamo_payment_amount' );
		if ( 0 === $amount ) {
			return $this->fail_with_notice( $order );
		}

		$body = RequestBodyBuilder::build(
			$this->payment_method_uuid,
			$amount,
			Repository::get( 'store_uuid', '' ),
			$order->get_id(),
			$this->extra_fields( $order )
		);

		$result = $client->request( 'POST', Routes::async_payments_create(), $body );
		if ( ! $result['ok'] ) {
			return $this->fail_with_notice( $order );
		}

		$payment      = isset( $result['body'] ) && is_array( $result['body'] ) ? $result['body'] : array();
		$async_uuid   = isset( $payment['uuid'] ) ? (string) $payment['uuid'] : '';
		$extra_fields = isset( $payment['extra_fields'] ) && is_array( $payment['extra_fields'] ) ? $payment['extra_fields'] : array();
		$payment_url  = isset( $extra_fields['payment_url'] ) ? (string) $extra_fields['payment_url'] : '';

		if ( '' === $async_uuid || '' === $payment_url ) {
			return $this->fail_with_notice( $order );
		}

		$order->update_meta_data( '_cachicamo_async_payment_uuid', $async_uuid );
		$order->update_status( 'on-hold' );
		$order->save();

		if ( null !== WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $payment_url,
		);
	}

	private function fail_with_notice( $order ) {
		wc_add_notice(
			__( 'This payment method is not available right now, choose another one.', 'cachicamoapp-for-woo' ),
			'error'
		);
		if ( $order instanceof \WC_Order ) {
			$order->update_status( 'failed' );
		}
		return array( 'result' => 'failure' );
	}
}
