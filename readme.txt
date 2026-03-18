=== FunnelKit USPS Priority Shipping Optimizer ===
Contributors: NV Digital
Tags: woocommerce, shipping, usps, pirateship, funnelkit, shipengine, shipstation
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.1.0
License: Proprietary

Optimize WooCommerce and FunnelKit orders for USPS Priority cubic custom boxes and USPS Priority flat-rate boxes using ShipEngine or ShipStation.

== Description ==

This plugin prepares USPS Priority shipping plans for WooCommerce orders by:

* collecting shippable order items,
* packing them with `dvdoug/boxpacker` when available (falls back to a single-item-per-box strategy otherwise),
* comparing custom cubic boxes and USPS flat-rate boxes,
* requesting USPS Priority rates from either **ShipEngine** or **ShipStation**,
* storing a package-by-package plan on the order for admin review and PirateShip export, and
* providing a **USPS Test Pricing** admin page where store managers can preview packing and live rates for any set of items without placing a real order.

= Carriers =

**ShipEngine** — uses the `POST /v1/rates` endpoint with an API-Key header. Supports all ShipEngine-connected USPS accounts.

**ShipStation** — uses the `GET /shipments/getrates` endpoint with HTTP Basic Authentication (API Key : API Secret). Supports any carrier connected to your ShipStation account (e.g. `stamps_com`).

You can switch between carriers any time from the settings page without losing the other carrier's credentials.

= Sandbox Mode =

When **Enable Sandbox Mode** is checked:

* All API log entries are prefixed with `[SANDBOX]` so test calls are visually distinct in the WooCommerce log viewer.
* A yellow warning banner is shown on the USPS Test Pricing admin page.
* Test pricing results include a note that rates are from a sandbox/test environment.

= USPS Cubic Pricing =

The plugin enforces all USPS Priority Mail Cubic eligibility rules:

* Volume ≤ 0.5 cubic feet (five tiers: 0.1, 0.2, 0.3, 0.4, 0.5 ft³).
* Longest side ≤ 18 inches.
* Total package weight ≤ 20 lbs.

Boxes that do not meet these criteria are silently excluded from cubic candidates but may still be evaluated as flat-rate options if configured with `box_type: "flat_rate"`.

= Box Configuration =

Boxes are stored as a JSON array in the plugin settings. Each box has inner/outer dimensions (inches), empty weight (ounces), maximum payload weight (lbs), and a type (`cubic` or `flat_rate`). See the `README.md` for the full JSON schema and examples.

= PirateShip Export =

From the WooCommerce order detail page, click **Export to PirateShip** to download a CSV ready for bulk import and label purchase on PirateShip.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/fk-usps-optimizer/`.
2. Run `composer install` (without `--no-dev` to include BoxPacker and dev tooling, or with `--no-dev` for production).
3. Activate the plugin in WordPress.
4. Open **WooCommerce → USPS Optimizer** and configure:
   - Carrier (ShipEngine or ShipStation)
   - API credentials for the chosen carrier
   - Ship-from address
   - Box definitions
5. Optionally open **WooCommerce → USPS Test Pricing** to verify packing and rates before going live.

== Frequently Asked Questions ==

= Does it support carriers other than USPS? =

The plugin is optimized for USPS Priority Mail (cubic and flat-rate). When using ShipStation you can configure any carrier code (e.g. `stamps_com`, `fedex`), but only cubic/flat-rate logic and cubic eligibility checks apply.

= Does it buy labels? =

No. It prepares package-level data that can be exported for PirateShip or reviewed by admins.

= Can I test rates without placing a real order? =

Yes. Open **WooCommerce → USPS Test Pricing**, enter item dimensions and a destination address, and click **Run Test Pricing** to get live rates from the active carrier.

= How do I add custom boxes? =

Edit the **Box Definitions JSON** field in **WooCommerce → USPS Optimizer**. You can also add boxes at runtime using the `fk_usps_optimizer_boxes` filter. See `README.md` for the full schema.

= How do I load API credentials from environment variables? =

Use the provided filters:

    add_filter( 'fk_usps_optimizer_shipengine_api_key', fn() => getenv( 'SHIPENGINE_API_KEY' ) ?: '' );
    add_filter( 'fk_usps_optimizer_shipstation_api_key', fn() => getenv( 'SHIPSTATION_API_KEY' ) ?: '' );
    add_filter( 'fk_usps_optimizer_shipstation_api_secret', fn() => getenv( 'SHIPSTATION_API_SECRET' ) ?: '' );

= What happens if BoxPacker is not installed? =

The plugin falls back to a one-item-per-box strategy that fits each item into the smallest configured box that accommodates it.

= Where are debug logs written? =

To the WooCommerce logger under the `fk-usps-optimizer` source. Enable debug logging in settings and view logs at **WooCommerce → Status → Logs**.

= Can I override the ShipStation API URL? =

Yes, using the `fk_usps_optimizer_shipstation_api_url` filter. This is useful for integration testing with a mock server.

== Changelog ==

= 1.1.0 =
* New: ShipStation carrier support (Basic-Auth, `GET /shipments/getrates`).
* New: Carrier selector in settings — choose ShipEngine or ShipStation.
* New: Sandbox Mode toggle — prefixes `[SANDBOX]` in logs, shows banner in admin.
* New: **WooCommerce → USPS Test Pricing** admin page for orderless rate previews.
* New: `Packing_Service::pack_items()` public method, reusable by the test suite.
* New: `ShipEngine_Service::build_test_package_plan()` for address-based rate lookups.
* New: WordPress filters for all new settings and the ShipStation API URL.
* Improved: Nonce is verified before any `$_POST` data is read on the test pricing page.

= 1.0.0 =
* Initial plugin scaffold with settings, ShipEngine rate-shopping, order planning, admin display, and PirateShip CSV export.

