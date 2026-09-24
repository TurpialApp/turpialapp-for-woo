=== Cachicamo App for WooCommerce ===
Contributors: cachicamo
Tags: woocommerce, invoicing, venezuela, fiscal
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
WC requires at least: 8.9
WC tested up to: 9.4
Stable tag: 2.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Synchronizes products, inventory and orders between a WooCommerce store and Cachicamo App, and issues fiscal invoices in Venezuela.

== Description ==

Cachicamo App for WooCommerce connects a WooCommerce store to Cachicamo App: two-way product and inventory synchronization, order billing (direct or as an external order) and payment gateways for the local processors Cachicamo supports.

= Main features =

* Two-way product and inventory synchronization between WooCommerce and Cachicamo App.
* Fiscal invoicing of WooCommerce orders in Venezuela.
* Local payment gateways for the classic checkout and for the block-based checkout.
* Order status kept in sync with the payment and invoicing status reported by Cachicamo App.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` or install it from the WordPress plugin directory.
2. Activate it through the "Plugins" screen.
3. Go to Cachicamo App > Settings and connect your store with your Cachicamo API token.

== Frequently Asked Questions ==

= Does it require WooCommerce? =

Yes, WooCommerce 8.9 or higher, active.

= Does it work with the block-based checkout? =

Yes, the payment gateways register as WooCommerce Blocks payment methods and also work with the classic, shortcode-based checkout.

= Where is my data sent? =

Only to `api.cachicamo.app`, the Cachicamo App backend for the store's own account. See "External services" below.

== Screenshots ==

1. Cachicamo App > Settings: connection wizard to link the store with a Cachicamo App API token.
2. Cachicamo App > Catalog: preview and run product and category synchronization.
3. Cachicamo App > Settings: payment method mapping between WooCommerce and Cachicamo App.
4. Checkout with a Cachicamo App payment gateway selected.

== Changelog ==

= 2.0.0 =
* Rebuilt plugin.

== Upgrade Notice ==

= 2.0.0 =
Rebuilt plugin. Reconnect the store with a Cachicamo App API token after upgrading.

== External services ==

This plugin connects the store to Cachicamo App (`api.cachicamo.app`), the invoicing and inventory backend the store owner already has an account with. The connection is optional and only becomes active once the store owner enters their Cachicamo App API token in Cachicamo App > Settings; without a token, the plugin makes no outbound request.

Once connected, the plugin sends and receives, over HTTPS, authenticated with the store owner's own API token:

* Product and inventory data (name, SKU, price, stock) to keep the catalog in sync in both directions.
* Order data (customer, items, totals, taxes, payment method) needed to issue a fiscal invoice or register the order in Cachicamo App.
* Payment gateway requests and callbacks for the local payment processors enabled in Cachicamo App > Settings, so a checkout payment can be confirmed.

No data is sent to any third party other than Cachicamo App, and no data is sent before the store owner connects their own account.

Cachicamo App is a service operated by Cachicamo. [Terms of Service](https://docs.cachicamo.app/terms) and [Privacy Policy](https://docs.cachicamo.app/privacy).
