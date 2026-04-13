=== FunnelKit USPS Priority Shipping Optimizer ===
Contributors: nvdigitalsolutions
Tags: woocommerce, shipping, usps, box-packing, funnelkit
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.2.3
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Optimize WooCommerce and FunnelKit orders for USPS Priority cubic custom boxes and USPS Priority flat-rate boxes using ShipEngine, ShipStation, or both simultaneously.

== Description ==

This plugin prepares USPS Priority shipping plans for WooCommerce orders by:

* collecting shippable order items,
* packing them with `dvdoug/boxpacker` when available (falls back to a single-item-per-box strategy otherwise),
* comparing custom cubic boxes and USPS flat-rate boxes,
* requesting USPS Priority rates from **ShipEngine**, **ShipStation**, or both — comparing rates across all enabled carriers to find the cheapest option,
* storing a package-by-package plan on the order for admin review and PirateShip export,
* providing a **USPS Test Pricing** admin page where store managers can preview packing and live rates for any set of items without placing a real order, and
* registering a native **WooCommerce Shipping Method** so it appears in shipping zones and provides live optimized rates during cart and checkout.

When selecting carriers on the settings page, check one or both carrier APIs — the relevant credential fields are shown automatically. A **Test Connection** button lets you verify your ShipEngine API key and carrier ID inline, without reloading the page.

= Shipping Zones =

The plugin registers as a WooCommerce shipping method that can be added to any shipping zone. Navigate to **WooCommerce → Settings → Shipping** and add the **USPS Priority Optimizer** method to the desired zones.

= Display Settings =

**Show All Options** — When enabled, every combination of rated box candidates (cartesian product) is offered as a separate shipping option in the cart and checkout. Repeated box names are consolidated (e.g. "2× Small Flat Rate Box + Large Flat Rate Box").

**Show Package Count** — When enabled, the package count is appended to each shipping label (e.g. "USPS Priority Mail (2 packages)") with proper singular/plural handling.

**USPS Service Code** — Each carrier now has its own service code setting (**ShipEngine Service Code** and **ShipStation Service Code**). Configure which USPS service code is sent to each carrier API (default: `usps_priority_mail`).

= Carriers =

**ShipEngine** — uses the `POST /v1/rates` endpoint with an API-Key header. Supports all ShipEngine-connected USPS accounts.

**ShipStation** — uses the `GET /shipments/getrates` endpoint with HTTP Basic Authentication (API Key : API Secret). Supports any carrier connected to your ShipStation account (e.g. `stamps_com`).

You can enable both carriers simultaneously from the settings page. When multiple carriers are enabled, rates from all of them are compared and the cheapest option is used. Credentials for each carrier are saved independently.

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

= Third-Party Services =

This plugin connects to the following external services to retrieve USPS shipping rates. By using this plugin, your shipping data (addresses, package dimensions, and weights) is transmitted to these third-party APIs:

**ShipEngine** (https://www.shipengine.com)
Used to fetch USPS Priority Mail shipping rates. Requires a ShipEngine API key and carrier ID.
- API endpoint: `https://api.shipengine.com/v1/rates`
- Terms of Service: https://www.shipengine.com/terms-of-service/
- Privacy Policy: https://www.shipengine.com/privacy-policy/

**ShipStation** (https://www.shipstation.com)
Used as an alternative carrier API to fetch USPS shipping rates. Requires a ShipStation API key and secret.
- API endpoint: `https://ssapi.shipstation.com/shipments/getrates`
- Terms of Service: https://www.shipstation.com/terms-of-service/
- Privacy Policy: https://www.shipstation.com/privacy-policy/

No data is transmitted to these services until you configure your API credentials and either process an order or use the Test Pricing page.

== Installation ==

1. Download the plugin ZIP from the `dist/` directory in the repository, or from the **Actions** tab of any CI run (artifact: `plugin-zip`).
2. Upload the ZIP and activate through **Plugins → Add New → Upload Plugin** in WordPress, or extract to `wp-content/plugins/fk-usps-optimizer/`.
3. Activate the plugin in WordPress.
4. Open **WooCommerce → USPS Optimizer** and configure:
   - Carrier (ShipEngine or ShipStation)
   - API credentials for the chosen carrier
   - Ship-from address
   - Box definitions
5. Optionally open **WooCommerce → USPS Test Pricing** to verify packing and rates before going live.

== Frequently Asked Questions ==

= How do I add this to my WooCommerce shipping zones? =

Navigate to **WooCommerce → Settings → Shipping**, select a zone, click **Add shipping method**, and choose **USPS Priority Optimizer**. The plugin will provide live optimized rates during cart and checkout for customers in that zone.

= Does it support carriers other than USPS? =

The plugin is optimized for USPS Priority Mail (cubic and flat-rate). When using ShipStation you can configure any carrier code (e.g. `stamps_com`, `ups_walleted`), and you can set up multiple carrier+service pairs to rate-shop across different carriers (e.g. UPS Ground + USPS Priority). Checkout labels automatically display the correct carrier name (e.g. "UPS Ground", "USPS Priority") based on the carrier and service codes. Only cubic/flat-rate logic and cubic eligibility checks apply to USPS-specific features.

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

= What happens to products without dimensions? =

Items that do not have length, width, and height set in WooCommerce are automatically detected and packed individually (one item per box) via the fallback packer. This prevents BoxPacker from using default 1×1×1 inch dimensions, which would produce incorrect packing results.

= Where are debug logs written? =

To the WooCommerce logger under the `fk-usps-optimizer` source. Enable debug logging in settings and view logs at **WooCommerce → Status → Logs**.

= Can I override the ShipStation API URL? =

Yes, using the `fk_usps_optimizer_shipstation_api_url` filter. This is useful for integration testing with a mock server.

== Changelog ==

= 1.2.3 =
* Fixed: ShipStation rate amounts now include both `shipmentCost` and `otherCost` (fuel surcharges, residential delivery fees, etc.). Previously only `shipmentCost` was used, causing UPS and other non-USPS carrier rates to appear lower than the actual cost at checkout.

= 1.2.2 =
* Fixed: Checkout shipping labels now display the correct carrier name (e.g. "UPS Ground", "USPS Priority") instead of always showing "USPS Priority" when multiple carriers are configured.
* New: `ShipStation_Service::get_service_label()` derives a human-readable label from carrier and service codes (e.g. "UPS Ground", "USPS Priority").
* New: `ShipEngine_Service::get_service_label()` derives a human-readable label from the service code (e.g. "USPS Priority", "USPS First Class").
* New: Plan data returned by both carrier services now includes a `service_label` field.
* Improved: "Show All Options" labels use the carrier service label per combination instead of the static method title.
* Improved: Single-rate (cheapest option) label uses the carrier service label when all packages share the same carrier.

= 1.2.1 =
* New: Multi-carrier support — enable both ShipEngine and ShipStation simultaneously; the plugin compares rates across all enabled carriers and uses the cheapest.
* New: Per-carrier service codes — separate ShipEngine Service Code and ShipStation Service Code settings replace the shared USPS Service Code (legacy setting kept for backward compatibility).
* New: ShipStation Additional Services — configure multiple carrier+service pairs (e.g. UPS Ground + USPS Priority) as a JSON array for cross-carrier rate shopping.
* Changed: Carrier selection is now checkboxes instead of a dropdown, allowing multiple carriers at once.
* Improved: Order processing, shipping zones, and test pricing all compare rates across all enabled carriers per package.

= 1.2.0 =
* New: WooCommerce Shipping Zones integration — register as a native WC_Shipping_Method for live rates during cart and checkout.
* New: **Show All Options** setting — display all rated box candidate combinations as separate shipping options.
* New: **Show Package Count** setting — append package count to shipping labels with proper singular/plural forms.
* New: **USPS Service Code** setting — configurable service code (default usps_priority_mail) for rate requests.
* New: Carrier credential fields show/hide automatically when switching the **Shipping Carrier API** dropdown — no page save needed.
* Improved: **Test Connection** button now uses AJAX and displays the result inline without reloading the page.
* Fixed: Packed packages now use box inner dimensions so the same box type matches as a rate candidate.
* Fixed: Items without WooCommerce dimensions are packed individually via fallback instead of using incorrect defaults.
* Fixed: Rate cache key includes box config and display settings to prevent stale cached rates after settings changes.
* Fixed: ShipStation now sends the configured serviceCode and packageCode in rate requests.
* CI: Production ZIP artifact is now built and uploaded automatically after every successful CI run.
* Security: Escaped CSS class attribute output on the settings page admin notice.
* Security: Sanitized numerical dimension/weight inputs in the Test Pricing form.
* Security: Guarded `$_SERVER['REQUEST_METHOD']` access with `isset()` check.
* Improved: `Packing_Service::pack_items()` now accepts an optional `$boxes` parameter to pack against custom box sets.

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

