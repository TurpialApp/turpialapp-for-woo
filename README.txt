=== Cachicamo App for WooCommerce ===
Contributors: kijamve
Tags: woocommerce, cachicamo, integration, api, inventory, sync, stock, webhooks
Requires at least: 5.0
Tested up to: 6.3
Stable tag: 1.0.0
Requires PHP: 7.2
License: MIT

== Description ==
Connect Cachicamo App platform with your WooCommerce store for seamless inventory synchronization and order management. This plugin synchronizes inventory from Cachicamo to WooCommerce and forwards order webhooks to Cachicamo for processing.

Cachicamo App for WooCommerce is a powerful integration plugin that connects your WooCommerce store with the Cachicamo App platform. This plugin enables automatic inventory synchronization from Cachicamo to WooCommerce and forwards order events via webhooks.

Key Features:
- Automatic inventory synchronization from Cachicamo to WooCommerce
- Stock updates based on SKU matching
- Support for simple and variable products
- Webhook integration for order events (created, updated, deleted)
- Configurable sync interval (default: 12 hours)
- Cachicamo is the source of truth for inventory

== Installation ==
Upload the plugin files to the /wp-content/plugins/cachicamoapp-for-woo directory, or install the plugin through the WordPress plugins screen directly.
Activate the plugin through the 'Plugins' screen in WordPress
- Go to WooCommerce > Settings > Integrations
- Select "Cachicamo App"
- Enter your Cachicamo API credentials (Access Token, Store UUID, Employee UUID)
- Configure WooCommerce REST API credentials (Base URL, Consumer Key, Consumer Secret)
- Copy the webhook URL and register it in WooCommerce webhooks settings
- Configure inventory sync interval
- Save changes

== Configuration ==

Cachicamo Configuration:
- Access Token: Your Cachicamo API access token
- Store UUID: Your Cachicamo Store/Warehouse UUID
- Employee UUID: Your Cachicamo User/Employee UUID

WooCommerce Configuration:
- Base URL: The base URL of your WooCommerce store (e.g., https://tutienda.com)
- Consumer Key: Consumer Key from WooCommerce REST API credentials
- Consumer Secret: Consumer Secret from WooCommerce REST API credentials

Webhooks Setup:
1. Copy the webhook URL displayed in the settings
2. Go to WooCommerce > Settings > Advanced > Webhooks
3. Create a new webhook with the following settings:
   - Name: Cachicamo Order Webhook
   - Status: Active
   - Topic: Order created / Order updated / Order deleted (create separate webhooks for each)
   - Delivery URL: Paste the copied webhook URL
   - Secret: Leave empty (authentication is handled by Cachicamo)

Inventory Synchronization:
- Sync Interval: Frequency of stock synchronization from Cachicamo to WooCommerce (in minutes)
- Default: 720 minutes (12 hours)
- Minimum: 10 minutes
- The plugin will automatically sync inventory based on SKU matching
- Cachicamo inventory values will always overwrite WooCommerce values

== Usage ==
Once configured, the plugin will automatically:
- Synchronize inventory from Cachicamo to WooCommerce at the configured interval
- Forward order webhooks to Cachicamo when orders are created, updated, or deleted
- Match products by SKU across all product variations

Manual Stock Sync:
You can trigger a manual stock synchronization by running the WordPress cron job or using WP-CLI:
`wp cron event run cachicamoapp_update_stock_from_api`

== Frequently Asked Questions ==
= How do I get API credentials for Cachicamo App? =
You need to register for an account at Cachicamo App and request API access credentials from your account dashboard.

= How does inventory synchronization work? =
The plugin searches for products in Cachicamo by SKU and updates the stock quantity in WooCommerce. Cachicamo is always the source of truth - WooCommerce stock will be overwritten.

= Does it support variable products? =
Yes, the plugin supports both simple and variable products. It searches for SKUs in all product variations.

= What happens if a product SKU is not found in Cachicamo? =
The product will be skipped during synchronization. No stock update will occur for that product.

= Can I change the sync interval? =
Yes, you can configure the sync interval in the plugin settings. The minimum interval is 10 minutes.

= How do webhooks work? =
When an order is created, updated, or deleted in WooCommerce, a webhook is sent to Cachicamo. Cachicamo Core will process the webhook and create/update orders, customers, and invoices as needed.

== Screenshots ==
Cachicamo App integration settings page
Webhook configuration instructions
Inventory synchronization settings

== Changelog ==
= 1.0.0 =
Initial release
- Renamed from Turpial App to Cachicamo App
- Implemented inventory synchronization from Cachicamo to WooCommerce
- Added webhook support for order events
- Removed order/product/customer export functionality (now handled via webhooks)
- Removed payment method and tax configuration (handled by Cachicamo Core)

== Support ==
For support, please contact:
Email: info@cachicamo.app
Website: https://cachicamo.app/support

== Credits ==
Developed by Kijam López
