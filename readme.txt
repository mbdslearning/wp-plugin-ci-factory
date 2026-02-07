=== WooCommerce PayMongo Checkout ===
Contributors: yourname
Tags: woocommerce, payment, paymongo, checkout
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds PayMongo hosted Checkout Session payment gateway to WooCommerce (Classic + Blocks). Webhooks confirm payments, with Action Scheduler auto-cancel for unpaid orders.

== Description ==
This plugin registers a WooCommerce payment gateway that redirects customers to PayMongo-hosted checkout. Payment confirmation is done via signed PayMongo webhooks (REST endpoint). Supports WooCommerce Blocks checkout.

== Installation ==
1. Upload the plugin to /wp-content/plugins/woocommerce-paymongo-checkout/
2. Run `composer install --no-dev` inside the plugin folder (or deploy with vendor/ included).
3. Activate the plugin.
4. Configure: WooCommerce → Settings → Payments → PayMongo (Checkout).

== Frequently Asked Questions ==
= What webhook URL should I configure? =
Use the Webhook URL displayed in the gateway settings under “Webhook”. Subscribe to: payment.paid, payment.failed.

== Changelog ==
= 2.0.0 =
- Enterprise refactor: PSR-4, REST webhook, Action Scheduler hardening, blocks compatibility.
