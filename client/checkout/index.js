/**
 * Cart-checkout-blocks integration. Forces the Store API to recalculate totals whenever the
 * buyer switches payment method (IGTF only applies to some methods, decided server side by
 * Pricing\CheckoutTotals) and shows what the buyer will pay in that method's own currency.
 */
import { extensionCartUpdate } from '@woocommerce/blocks-checkout';
import { getSetting } from '@woocommerce/settings';
import { __, sprintf } from '@wordpress/i18n';

const NAMESPACE = 'cachicamoapp-for-woo';
const SETTINGS_KEY = 'cachicamoapp-for-woo_data';

let lastPaymentMethod = null;

function pluginData() {
	return getSetting( SETTINGS_KEY, {
		rates: { USD: 1 },
		storeCurrency: 'USD',
		paymentMapping: {},
	} );
}

/**
 * Converts a store-currency amount into the payment method's own currency using the same
 * cached rates the order totals are priced from; this is display only, the amount the core
 * charges is always what Pricing\CheckoutTotals computed server side.
 */
function convertToPaymentCurrency( amountInStoreCurrency, paymentCurrencyIso ) {
	const { rates, storeCurrency } = pluginData();
	const rateStore   = rates[ storeCurrency ] || 1;
	const ratePayment = rates[ paymentCurrencyIso ] || 1;
	return amountInStoreCurrency * ( ratePayment / rateStore );
}

function showPaymentAmountNotice( gatewayId, totalInStoreCurrency ) {
	const { paymentMapping } = pluginData();
	const paymentMethodUuid = paymentMapping[ gatewayId ];
	if ( ! paymentMethodUuid ) {
		removePaymentAmountNotice();
		return;
	}

	const label = document.querySelector(
		`.wc-block-components-radio-control__option[data-value="${ gatewayId }"] .wc-block-components-radio-control__label`
	);
	if ( ! label ) {
		return;
	}

	let notice = label.querySelector( '.cachicamoapp-payment-amount' );
	if ( ! notice ) {
		notice = document.createElement( 'span' );
		notice.className = 'cachicamoapp-payment-amount';
		label.appendChild( notice );
	}

	const amount = convertToPaymentCurrency( totalInStoreCurrency, pluginData().storeCurrency );
	notice.textContent = sprintf(
		/* translators: %1$s: converted amount, %2$s: currency code. */
		__( ' — You will pay %1$s %2$s', 'cachicamoapp-for-woo' ),
		amount.toFixed( 2 ),
		pluginData().storeCurrency
	);
}

function removePaymentAmountNotice() {
	document.querySelectorAll( '.cachicamoapp-payment-amount' ).forEach( ( node ) => node.remove() );
}

/**
 * WooCommerce Blocks fires no dedicated "payment method changed" event: the active method is
 * read from wc/store/payment on every store subscription tick and diffed against the previous
 * one, so a real change is only acted on once.
 */
function watchPaymentMethodChanges( wp ) {
	if ( ! wp || ! wp.data || ! wp.data.subscribe || ! wp.data.select ) {
		return;
	}

	wp.data.subscribe( () => {
		const paymentStore = wp.data.select( 'wc/store/payment' );
		const cartStore    = wp.data.select( 'wc/store/cart' );
		if ( ! paymentStore || ! cartStore ) {
			return;
		}

		const activePaymentMethod = paymentStore.getActivePaymentMethod
			? paymentStore.getActivePaymentMethod()
			: null;

		if ( activePaymentMethod && activePaymentMethod !== lastPaymentMethod ) {
			lastPaymentMethod = activePaymentMethod;
			extensionCartUpdate( {
				namespace: NAMESPACE,
				data: { payment_method: activePaymentMethod },
			} );
		}

		const cartTotals = cartStore.getCartTotals ? cartStore.getCartTotals() : null;
		if ( activePaymentMethod && cartTotals && cartTotals.total_price ) {
			showPaymentAmountNotice( activePaymentMethod, Number( cartTotals.total_price ) / 100 );
		}
	} );
}

watchPaymentMethodChanges( window.wp );
