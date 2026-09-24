<?php

namespace Cachicamo\WooCommerce\Billing;

use Cachicamo\WooCommerce\Api\Client;
use Cachicamo\WooCommerce\Api\Routes;
use Cachicamo\WooCommerce\Plugin;
use Cachicamo\WooCommerce\Pricing\Calculator;
use Cachicamo\WooCommerce\Pricing\PaymentTaxes;
use Cachicamo\WooCommerce\Pricing\TaxCatalog;
use Cachicamo\WooCommerce\Settings\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Issues the Cachicamo document for an order in billing_mode = direct: resolves the customer,
 * translates every order line into a document line, prices it against the core, closes the gap
 * between the order's own total and the priced total, and stores it under the order's own
 * idempotency key so a retry of the same order can never create a second document.
 */
class DirectInvoicer {

	const SCALE = Calculator::SCALE;

	/**
	 * @param \WC_Order $order
	 * @return array{ok:bool,error:?string,skipped:bool}
	 */
	public static function issue( \WC_Order $order ) {
		if ( (float) $order->get_total() <= 0 ) {
			self::mark_skipped( $order );
			return array(
				'ok'      => true,
				'error'   => null,
				'skipped' => true,
			);
		}

		$resolved = CustomerResolver::resolve( $order );
		if ( ! $resolved['ok'] ) {
			return self::fail( $order, 'cachicamoapp_customer_resolution_failed' );
		}

		$lines = self::build_lines( $order );
		if ( empty( $lines ) ) {
			self::mark_skipped( $order );
			return array(
				'ok'      => true,
				'error'   => null,
				'skipped' => true,
			);
		}

		$client             = Plugin::instance()->service( 'api_client' );
		$order_currency_iso = get_woocommerce_currency();
		$rate_date          = $order->get_meta( '_cachicamo_rate_date' );

		$body = array(
			'document_type'         => 'INVOICE',
			'printer_document_uuid' => Repository::get( 'printer_document_uuid', '' ),
			'customer_uuid'         => $resolved['customer_uuid'],
			'products'              => $lines,
			'metadata'              => array(
				'woocommerce_order_id'     => $order->get_id(),
				'woocommerce_order_number' => $order->get_order_number(),
				'woocommerce_site'         => home_url(),
			),
		);
		if ( ! empty( $rate_date ) ) {
			$body['rate_date'] = $rate_date;
		}

		$first_preview = $client->request( 'POST', Routes::documents_preview(), $body );
		if ( ! $first_preview['ok'] ) {
			return self::fail( $order, 'cachicamoapp_preview_failed' );
		}

		$document_currency_iso = self::document_currency_iso( $first_preview['body'], $order_currency_iso );
		$igtf_scaled           = self::igtf_charge_scaled( $order );
		$order_target          = ( (float) $order->get_total() * self::SCALE - $igtf_scaled ) / self::SCALE;
		$target                = Reconciler::convert_target(
			$order_target,
			$order_currency_iso,
			$document_currency_iso,
			self::currency_rate( $first_preview['body'], $order_currency_iso ),
			self::amount_raw( $first_preview['body'], 'currency_rate_invoice' )
		);

		$preview_total = self::amount( $first_preview['body'], 'total_invoice' );
		$taxable_base  = self::amount( $first_preview['body'], 'total_products' );
		$tax_total     = self::amount( $first_preview['body'], 'total_taxes' );

		$plan = Reconciler::reconcile( $target, $preview_total, $taxable_base, $tax_total );

		$second_preview = $first_preview;
		if ( 'adjustment_line' === $plan['action'] ) {
			$exempt = TaxCatalog::find_zero_rate_exempt_iva();
			if ( null === $exempt ) {
				return self::fail( $order, 'cachicamoapp_no_exempt_tax_configured' );
			}
			$body['products'][] = array(
				'custom_product_name' => 'Ajuste',
				'custom_unit_price'   => (int) round( $plan['amount'] * self::SCALE ),
				'custom_currency_uuid' => self::currency_uuid( $document_currency_iso ),
				'qty'                 => 1,
				'taxes_uuid'          => array( $exempt['uuid'] ),
			);
			$second_preview = $client->request( 'POST', Routes::documents_preview(), $body );
		} elseif ( 'global_discount' === $plan['action'] ) {
			$body['global_discount_amount'] = (int) round( $plan['amount'] * self::SCALE );
			$second_preview                 = $client->request( 'POST', Routes::documents_preview(), $body );
		}

		if ( ! $second_preview['ok'] ) {
			return self::fail( $order, 'cachicamoapp_preview_failed' );
		}

		$total_to_pay = self::amount( $second_preview['body'], 'total_to_pay' );
		$payment      = self::resolve_payment( $order, $total_to_pay, $second_preview['body'] );
		if ( null !== $payment ) {
			$body['payments'] = array( $payment );
		}

		$idempotency_key = 'wc:' . Repository::get( 'store_uuid', '' ) . ':' . $order->get_id();
		$save            = self::request_with_idempotency_key( $client, $body, $idempotency_key );

		$document = isset( $save['body']['document'] ) && is_array( $save['body']['document'] ) ? $save['body']['document'] : array();
		if ( ! $save['ok'] || empty( $document['uuid'] ) ) {
			return self::fail( $order, 'cachicamoapp_save_failed' );
		}

		$order->update_meta_data( '_cachicamo_invoice_uuid', $document['uuid'] );
		$order->update_meta_data( '_cachicamo_document_number', isset( $document['document_number'] ) ? $document['document_number'] : null );
		$order->update_meta_data( '_cachicamo_control_number', isset( $document['manual_control_number'] ) ? $document['manual_control_number'] : null );
		$order->update_meta_data( '_cachicamo_state', 'issued' );
		$order->delete_meta_data( '_cachicamo_error' );
		$order->add_order_note( __( 'Cachicamo invoice issued.', 'cachicamoapp-for-woo' ) );
		$order->save();

		return array(
			'ok'      => true,
			'error'   => null,
			'skipped' => false,
		);
	}

	/**
	 * wp_remote_request has no header override per call in Client::request, so the idempotency
	 * key travels through a filter Client exposes for the duration of a single request.
	 */
	private static function request_with_idempotency_key( $client, array $body, $idempotency_key ) {
		$add_header = function ( $args ) use ( $idempotency_key ) {
			$args['headers']['Idempotency-Key'] = $idempotency_key;
			return $args;
		};
		add_filter( 'http_request_args', $add_header, 10, 1 );
		$result = $client->request( 'POST', Routes::documents_save_preview(), $body );
		remove_filter( 'http_request_args', $add_header, 10 );
		return $result;
	}

	private static function build_lines( \WC_Order $order ) {
		$lines               = array();
		$free_line_tax_uuid  = Repository::get( 'free_line_tax_uuid', '' );

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			/** @var \WC_Order_Item_Product $item */
			$line_total = (float) $item->get_total();
			$qty        = (float) $item->get_quantity();
			if ( $line_total <= 0 || $qty <= 0 ) {
				continue;
			}

			$product      = $item->get_product();
			$product_uuid = $product ? $product->get_meta( '_cachicamo_product_uuid' ) : '';
			$currency_iso = get_woocommerce_currency();

			if ( $product && ! empty( $product_uuid ) && $product->exists() && 'publish' === $product->get_status() ) {
				$tax_uuid = $product->get_meta( '_cachicamo_tax_uuid' );
				$lines[]  = array(
					'product_uuid'        => $product_uuid,
					'qty'                 => $qty,
					'custom_unit_price'   => (int) round( ( $line_total / $qty ) * self::SCALE ),
					'custom_currency_uuid' => self::currency_uuid( $currency_iso ),
					'taxes_uuid'          => ! empty( $tax_uuid ) ? array( $tax_uuid ) : array( $free_line_tax_uuid ),
				);
				continue;
			}

			$tax_uuid = self::woocommerce_line_tax_uuid( $item, $free_line_tax_uuid );
			$lines[]  = array(
				'custom_product_name' => $item->get_name(),
				'qty'                 => $qty,
				'custom_unit_price'   => (int) round( ( $line_total / $qty ) * self::SCALE ),
				'custom_currency_uuid' => self::currency_uuid( $currency_iso ),
				'taxes_uuid'          => array( $tax_uuid ),
			);
		}

		if ( $order->get_shipping_total() > 0 ) {
			$lines[] = array(
				'custom_product_name' => __( 'Shipping', 'cachicamoapp-for-woo' ),
				'qty'                 => 1,
				'custom_unit_price'   => (int) round( (float) $order->get_shipping_total() * self::SCALE ),
				'custom_currency_uuid' => self::currency_uuid( get_woocommerce_currency() ),
				'taxes_uuid'          => array( $free_line_tax_uuid ),
			);
		}

		foreach ( $order->get_items( 'fee' ) as $fee ) {
			/** @var \WC_Order_Item_Fee $fee */
			if ( 'igtf' === $fee->get_meta( '_cachicamo_charge' ) ) {
				continue;
			}
			$amount = (float) $fee->get_total();
			if ( 0 === $amount ) {
				continue;
			}
			$lines[] = array(
				'custom_product_name' => $fee->get_name(),
				'qty'                 => 1,
				'custom_unit_price'   => (int) round( $amount * self::SCALE ),
				'custom_currency_uuid' => self::currency_uuid( get_woocommerce_currency() ),
				'taxes_uuid'          => array( $free_line_tax_uuid ),
			);
		}

		return $lines;
	}

	private static function woocommerce_line_tax_uuid( $item, $fallback ) {
		foreach ( TaxCatalog::all() as $tax ) {
			if ( TaxCatalog::tax_class_name( $tax ) === $item->get_tax_class() ) {
				return $tax['uuid'];
			}
		}
		return $fallback;
	}

	private static function igtf_charge_scaled( \WC_Order $order ) {
		foreach ( $order->get_items( 'fee' ) as $fee ) {
			if ( 'igtf' === $fee->get_meta( '_cachicamo_charge' ) ) {
				return (int) round( (float) $fee->get_total() * self::SCALE );
			}
		}
		return 0;
	}

	private static function resolve_payment( \WC_Order $order, $total_to_pay, array $preview_body ) {
		$mapping        = Repository::get( 'payment_mapping', array() );
		$gateway_id     = $order->get_payment_method();
		$payment_method_uuid = isset( $mapping[ $gateway_id ] ) ? $mapping[ $gateway_id ] : '';
		if ( empty( $payment_method_uuid ) ) {
			return null;
		}

		$method_taxes = PaymentTaxes::for_payment_method( $payment_method_uuid );
		$rates        = isset( $preview_body['currency_rate_by_payment_methods'] ) && is_array( $preview_body['currency_rate_by_payment_methods'] )
			? $preview_body['currency_rate_by_payment_methods']
			: array();
		// currency_rate_by_payment_methods is keyed by currency (its iso and its uuid), not by
		// payment_method_uuid: a payment method carries no rate of its own, it inherits its
		// currency's rate against the core's base currency, the same unit convert_target uses
		// for rate_order/rate_document -- total_to_pay is already in the document's own
		// currency, so converting it into the method's currency divides by the document's rate
		// and multiplies by the method's, exactly like currency/rules/convert.go:50-54.
		$document_currency_iso = self::document_currency_iso( $preview_body, $method_taxes['currency_iso'] );
		$rate_document         = self::amount_raw( $preview_body, 'currency_rate_invoice' );
		$rate_method           = isset( $rates[ $method_taxes['currency_iso'] ] ) ? (float) $rates[ $method_taxes['currency_iso'] ] : $rate_document;
		$conversion            = $document_currency_iso === $method_taxes['currency_iso'] ? 1.0 : ( $rate_method / $rate_document );
		// TaxCatalog/PaymentTaxes carry tax_rate as a percentage (3 for 3%), payment_amount takes
		// a fraction.
		$amount = self::payment_amount( $total_to_pay, $conversion, $method_taxes['igtf_rate'] / 100 );

		$payment = array(
			'payment_method_uuid' => $payment_method_uuid,
			'total_payment'       => (int) round( $amount * self::SCALE ),
		);

		$async_payment_uuid = $order->get_meta( '_cachicamo_async_payment_uuid' );
		if ( ! empty( $async_payment_uuid ) ) {
			$payment['async_payment_uuid'] = $async_payment_uuid;
		}
		$rate_date = $order->get_meta( '_cachicamo_rate_date' );
		if ( ! empty( $rate_date ) ) {
			$payment['rate_date'] = $rate_date;
		}

		return $payment;
	}

	/**
	 * P = T * (1 + r): the IGTF rate applies on the total to pay before the currency
	 * conversion of the mapped payment method, since the core taxes the payment in its own
	 * currency and the plugin has to send the converted amount already inflated.
	 *
	 * @param float $total_to_pay
	 * @param float $currency_rate
	 * @param float $igtf_rate
	 * @return float
	 */
	public static function payment_amount( $total_to_pay, $currency_rate, $igtf_rate ) {
		return round( $total_to_pay * $currency_rate * ( 1 + $igtf_rate ), 4 );
	}

	private static function currency_uuid( $currency_iso ) {
		return \Cachicamo\WooCommerce\Pricing\Rates::currency_uuid( $currency_iso );
	}

	private static function amount( array $body, $key ) {
		if ( ! isset( $body[ $key ] ) ) {
			return 0.0;
		}
		return (float) $body[ $key ] / self::SCALE;
	}

	/**
	 * @param array<string,mixed> $body
	 */
	private static function amount_raw( array $body, $key ) {
		return isset( $body[ $key ] ) ? (float) $body[ $key ] : 1.0;
	}

	/**
	 * The document's own currency, which follows the account's invoice currency and can differ
	 * from the order's: preview's invoice.currency.iso is the source of truth, the order's ISO
	 * is only the fallback for a malformed preview.
	 *
	 * @param array<string,mixed> $body
	 */
	private static function document_currency_iso( array $body, $fallback_iso ) {
		return isset( $body['invoice']['currency']['iso'] ) && '' !== $body['invoice']['currency']['iso']
			? $body['invoice']['currency']['iso']
			: $fallback_iso;
	}

	/**
	 * currency_rate_by_products is keyed by currency uuid and by iso; the order's own currency
	 * rate against the core's base is looked up by iso since the plugin never resolves the
	 * order currency's uuid on its own.
	 *
	 * @param array<string,mixed> $body
	 */
	private static function currency_rate( array $body, $currency_iso ) {
		return isset( $body['currency_rate_by_products'][ $currency_iso ] )
			? (float) $body['currency_rate_by_products'][ $currency_iso ]
			: 1.0;
	}

	private static function fail( \WC_Order $order, $error_key ) {
		$order->update_meta_data( '_cachicamo_state', 'error' );
		$order->update_meta_data( '_cachicamo_error', $error_key );
		$order->add_order_note( sprintf( 'Cachicamo invoice failed: %s', $error_key ) );
		$order->save();

		return array(
			'ok'      => false,
			'error'   => $error_key,
			'skipped' => false,
		);
	}

	private static function mark_skipped( \WC_Order $order ) {
		$order->update_meta_data( '_cachicamo_state', 'skipped' );
		$order->save();
	}
}
