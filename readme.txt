=== FunnelKit USPS Priority Shipping Optimizer ===
Contributors: NV Digtal
Tags: woocommerce, shipping, usps, pirateship, funnelkit
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: Proprietary

Optimize WooCommerce and FunnelKit orders for USPS Priority cubic custom boxes and USPS Priority flat-rate boxes.

== Description ==

This plugin prepares USPS Priority shipping plans for WooCommerce orders by:

* collecting shippable order items,
* packing them with `dvdoug/boxpacker` when available,
* comparing custom cubic boxes and USPS flat-rate boxes,
* requesting USPS Priority rates from ShipEngine, and
* storing a package-by-package plan for admin review and PirateShip export.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/fk-usps-optimizer/`.
2. Run `composer install` if you want BoxPacker and developer tooling.
3. Activate the plugin in WordPress.
4. Open **WooCommerce → USPS Optimizer** and configure ShipEngine credentials, origin address, and box definitions.

== Frequently Asked Questions ==

= Does it support carriers other than USPS? =

No. The optimizer is intentionally limited to USPS Priority cubic custom packaging and USPS Priority flat-rate boxes.

= Does it buy labels? =

No. It prepares package-level data that can be exported for PirateShip.

== Changelog ==

= 1.0.0 =
* Initial plugin scaffold with settings, order planning, admin display, and PirateShip CSV export.
