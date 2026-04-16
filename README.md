# FunnelKit USPS Priority Shipping Optimizer

A WooCommerce plugin that optimizes USPS Priority Mail shipping for FunnelKit orders by packing items into custom cubic boxes and USPS flat-rate boxes, rate-shopping through ShipEngine or ShipStation, and producing package-level plans suitable for PirateShip export.

**Author:** NV Digital Solutions
**License:** GPLv3 or later — see [LICENSE](LICENSE) for details.

---

## Table of Contents

1. [Overview](#overview)
2. [Requirements](#requirements)
3. [Installation](#installation)
   - [Production](#production)
   - [Development](#development)
4. [Configuration](#configuration)
   - [Enabled Carrier APIs](#enabled-carrier-apis)
   - [ShipEngine Settings](#shipengine-settings)
   - [ShipStation Settings](#shipstation-settings)
   - [Service Codes](#service-codes)
   - [Display Settings](#display-settings)
   - [Test ShipEngine Connection](#test-shipengine-connection)
   - [Sandbox Mode](#sandbox-mode)
   - [Ship-From Address](#ship-from-address)
   - [Debug Logging](#debug-logging)
   - [Box Definitions](#box-definitions)
5. [Features](#features)
   - [WooCommerce Shipping Zones Integration](#woocommerce-shipping-zones-integration)
   - [Automatic Order Planning](#automatic-order-planning)
   - [Admin Test Pricing Page](#admin-test-pricing-page)
   - [PirateShip CSV Export](#pirateship-csv-export)
6. [Architecture](#architecture)
   - [Class Reference](#class-reference)
7. [WordPress Hooks and Filters](#wordpress-hooks-and-filters)
   - [Action Hooks](#action-hooks)
   - [Filter Hooks](#filter-hooks)
8. [Box Definition JSON Schema](#box-definition-json-schema)
9. [Development](#development)
   - [Running Tests](#running-tests)
   - [Building](#building)
   - [Code Style](#code-style)
   - [Directory Structure](#directory-structure)
10. [Changelog](#changelog)

---

## Overview

When a customer completes checkout, this plugin:

1. Collects all shippable items from the WooCommerce order.
2. Packs them using the [dvdoug/boxpacker](https://github.com/dvdoug/BoxPacker) library (falls back to a single-item-per-box strategy when the library is unavailable).
3. For each packed box, evaluates all configured boxes and picks the cheapest USPS Priority rate from either **ShipEngine** or **ShipStation**.
4. Stores a complete, package-level shipping plan on the order for admin review.
5. Generates PirateShip-compatible CSV rows for bulk label purchase.

Managers can also use the **WooCommerce → USPS Test Pricing** admin page to preview packing and rates for arbitrary items and addresses without placing a real order.

---

## Requirements

| Dependency | Minimum version |
|---|---|
| PHP | 8.0 |
| WordPress | 6.0 |
| WooCommerce | 7.0 |
| dvdoug/boxpacker | ^3.12 (optional; installed via Composer) |

---

## Installation

### Production

1. Download or build the plugin zip (see `bin/build.sh`).
2. Upload and activate through **Plugins → Add New → Upload Plugin** in WordPress, or extract to `wp-content/plugins/fk-usps-optimizer/`.
3. Open **WooCommerce → USPS Optimizer** to complete configuration.

> **Note:** `vendor/` is excluded from the repository. Run `composer install --no-dev` inside the plugin directory before deploying, or use the build script which handles this automatically.

### Development

```bash
# Clone the repository
git clone https://github.com/nvdigitalsolutions/nv-boxpacker.git
cd nv-boxpacker

# Install all dependencies including dev tools
composer install

# Verify the setup
composer test   # PHPUnit
composer phpcs  # PHP CodeSniffer (WPCS)
```

---

## Configuration

Navigate to **WooCommerce → USPS Optimizer** to manage all plugin settings.

### Enabled Carrier APIs

| Setting | Values | Default |
|---|---|---|
| **Enabled Carrier APIs** | `ShipEngine`, `ShipStation` (checkboxes) | ShipEngine |

Enable one or more carrier APIs for live rate requests. When multiple carriers are enabled, the plugin fetches rates from all of them and uses the cheapest option. Checking or unchecking a carrier immediately shows or hides its credential fields without saving the page.

---

### ShipEngine Settings

| Setting | Description |
|---|---|
| **ShipEngine API Key** | API key from [app.shipengine.com](https://app.shipengine.com) → API Management. |
| **ShipEngine Carrier ID** | The carrier ID for your USPS account (e.g. `se-123456`). Found in **Carriers** on the ShipEngine dashboard. |
| **ShipEngine Service Code** | Service code sent to ShipEngine for rate requests (e.g. `usps_priority_mail`). Default: `usps_priority_mail`. |

ShipEngine uses a header-based `API-Key` authentication scheme and calls the `POST /v1/rates` endpoint.

---

### ShipStation Settings

| Setting | Description |
|---|---|
| **ShipStation API Key** | API key from ShipStation → Account Settings → API Settings. |
| **ShipStation API Secret** | API secret from the same page. |
| **ShipStation Carrier Code** | Primary carrier code to rate against (e.g. `stamps_com`, `ups_walleted`). Default: `stamps_com`. |
| **ShipStation Service Code** | Service code sent to ShipStation for rate requests (e.g. `usps_priority_mail`). Default: `usps_priority_mail`. Leave empty to match any service from the carrier. |
| **ShipStation Additional Services** | Optional JSON array of extra carrier+service pairs to rate-shop alongside the primary pair. Each entry needs `carrier_code` and `service_code`. Example: `[{"carrier_code":"ups_walleted","service_code":"ups_ground"}]`. Rates from all pairs are compared and the cheapest wins. |

ShipStation uses HTTP Basic Authentication (`API Key:API Secret`) and calls the `GET /shipments/getrates` endpoint.

> You can override the ShipStation API base URL using the `fk_usps_optimizer_shipstation_api_url` filter (see [Filter Hooks](#filter-hooks)).

---

### Service Codes

Each carrier now has its own service code setting. This replaces the previous shared **USPS Service Code** field.

| Setting | Carrier | Default |
|---|---|---|
| **ShipEngine Service Code** | ShipEngine | `usps_priority_mail` |
| **ShipStation Service Code** | ShipStation | `usps_priority_mail` |

Common service codes include `usps_priority_mail`, `usps_first_class_mail`, and `usps_ground_advantage`. The legacy shared `service_code` setting is still respected as a fallback when per-carrier codes are empty (backward compatibility).

---

### Display Settings

These settings control how shipping rates appear in the WooCommerce cart and checkout.

| Setting | Type | Default | Description |
|---|---|---|---|
| **Show All Options** | Checkbox | Off | Display every combination of rated box candidates as a separate shipping option. When enabled, each packed package is rated against all configured boxes; the cartesian product of those candidates produces every possible plan, each offered as a distinct rate. Labels use the carrier-specific service name (e.g. "USPS Priority", "UPS Ground") derived from each plan's carrier and service codes. Repeated box names are consolidated (e.g. "2× Small Flat Rate Box + Large Flat Rate Box"). When disabled, only the single cheapest combined rate is shown. |
| **Show Package Count** | Checkbox | Off | Append the package count to each shipping option label. Example: "USPS Priority (2 packages)". Uses proper singular/plural forms. |
| **Show Estimated Delivery Date** | Checkbox | Off | Append the carrier-provided estimated delivery date to each shipping option label. Example: "USPS Priority — Est. delivery: Mon, Jan 15". For ShipEngine, the `estimated_delivery_date` field from the rate response is used directly. For ShipStation, the `transitDays` field is converted to a calendar date. The formatted date is also passed as WooCommerce rate metadata for themes and FunnelKit Checkout pages that render it. |
| **Additional Business Days** | Number (0–30) | 0 | Extra business days (Monday–Friday) added to every estimated delivery date. Use this to account for order processing or handling time. Weekends are skipped, so a 2-business-day buffer applied on a Thursday pushes the estimate to the following Monday. Applies to both carrier-returned and default transit-day estimates. |

---

### Test ShipEngine Connection

Below the settings form a **Test Connection** button verifies that:

1. The configured **ShipEngine API Key** authenticates successfully against the ShipEngine API.
2. The configured **Carrier ID** belongs to an active USPS carrier account (`stamps_com`, `usps`, or `endicia`).

Clicking the button fires an AJAX request — the result is displayed inline immediately below the button **without reloading the page**. Save your settings before running this test so the current values are used.

> **Tip:** For sandbox testing, enter a `TEST_`-prefixed API key from your ShipEngine dashboard and enable **Sandbox Mode** above.

---

### Sandbox Mode

When **Enable Sandbox Mode** is checked:

- All ShipStation and ShipEngine log entries are prefixed with `[SANDBOX]` so they are distinguishable from production calls.
- A yellow warning banner is displayed on the **USPS Test Pricing** admin page.
- The test pricing results table shows a notice that rates are from a sandbox environment.

Use sandbox mode during development and testing to clearly mark non-production API calls in the WooCommerce log viewer.

---

### Ship-From Address

| Field | Description |
|---|---|
| Ship From Name | Sender full name. |
| Ship From Company | Company name (optional). |
| Ship From Phone | Sender phone number. |
| Ship From Address 1 | Street address. |
| Ship From Address 2 | Suite/unit (optional). |
| Ship From City | City. |
| Ship From State | Two-letter state abbreviation (e.g. `CA`). |
| Ship From Postal Code | ZIP code. |
| Ship From Country | Two-letter country code. Default: `US`. |

The ship-from address is passed directly to both ShipEngine and ShipStation for rate calculations. It can also be overridden via the `fk_usps_optimizer_ship_from_address` filter.

---

### Debug Logging

When **Enable Debug Logging** is checked, all API requests, responses, and packing errors are written to the WooCommerce logger under the `fk-usps-optimizer` source. View logs at **WooCommerce → Status → Logs**.

---

### Box Definitions

Box definitions are managed via a visual table in the **Box Definitions** section of the settings page. Each row represents one physical box and can be added, edited, or removed directly in the UI.

Each box has inner/outer dimensions (inches), empty weight (ounces), maximum payload weight (lbs), a type (`cubic` or `flat_rate`), and an optional **Carrier** restriction (`Any`, `USPS`, `UPS`, `FedEx`).

See [Box Definition JSON Schema](#box-definition-json-schema) for the full field reference and a worked example.

---

## Features

### WooCommerce Shipping Zones Integration

The plugin registers a native **WC_Shipping_Method** (`fk_usps_optimizer`) so it appears in **WooCommerce → Settings → Shipping → Shipping Zones**. Add the **USPS Priority Optimizer** method to any zone to enable live, optimized USPS Priority rates during cart and checkout.

**How it works:**

1. Cart items are extracted and converted to the item format expected by `Packing_Service::pack_items()`.
2. Items without WooCommerce product dimensions (length, width, or height not set) are detected and packed individually via the fallback packer — one item per box. This prevents the BoxPacker default of 1×1×1 inch dimensions from producing incorrect packing results.
3. Packed packages are rate-shopped against all enabled carrier APIs; the cheapest rate per package wins.
4. Rates are cached in a transient for 30 minutes. The cache key includes carrier, service code, box configuration, display settings, item dimensions, and destination — so rates update immediately when any of these change.

When **Show All Options** is enabled, each rate option shows a descriptive label built from the carrier service name and the box names in that combination, such as "USPS Priority — 2× Small Flat Rate Box + Large Flat Rate Box" or "UPS Ground — 2× Bag". Repeated box names are consolidated (e.g. "2× Small" instead of "Small + Small"). Duplicate combinations are removed and results are sorted cheapest-first. When multiple carriers are configured, each carrier's rates are labeled with their own service name so customers can distinguish between USPS and UPS options.

---

### Automatic Order Planning

On `woocommerce_checkout_order_processed` (priority 20), the plugin:

1. Collects shippable items via `Packing_Service::pack_order()`.
2. Packs them using BoxPacker (or fallback).
3. Rate-shops every packed package across all enabled carrier services and picks the cheapest rate.
4. Saves the result to the order meta key `_fk_usps_shipping_plan` as a serialized PHP array.
5. Displays the plan on the order detail page under **USPS Shipping Plan**.

The plan structure is:

```php
[
    'created_at'          => '2024-01-15 12:00:00',  // UTC
    'total_package_count' => 2,
    'total_rate_amount'   => 14.65,
    'currency'            => 'USD',
    'packages'            => [ /* see Package Plan below */ ],
    'pirateship_rows'     => [ /* CSV row arrays */ ],
    'warnings'            => [ /* non-fatal notices */ ],
]
```

**Package Plan** (one entry per packed box):

```php
[
    'package_number' => 1,
    'mode'           => 'cubic',          // 'cubic' or 'flat_rate_box'
    'package_code'   => 'package',
    'package_name'   => 'Custom Cubic Small',
    'service_code'   => 'usps_priority_mail',
    'rate_amount'    => 7.45,
    'currency'       => 'USD',
    'weight_oz'      => 18.5,
    'dimensions'     => ['length' => 8, 'width' => 8, 'height' => 6],
    'cubic_tier'     => '0.2',            // empty string for flat-rate
    'packing_list'   => ['2x Widget', '1x Gadget'],
    'items'          => [ /* raw item arrays */ ],
]
```

---

### Admin Test Pricing Page

Navigate to **WooCommerce → USPS Test Pricing** to use the test pricing tool.

**How it works:**

1. Enter one or more items with name, quantity, dimensions (inches), and weight (ounces).
2. Enter the destination address.
3. Click **Run Test Pricing**.

The plugin packs the items and fetches live rates from all enabled carriers. Results are displayed per package and include:

- Estimated rate (formatted as WooCommerce price)
- Service code
- Dimensions and weight
- Cubic tier (when applicable)
- Packing list

Rows can be added dynamically with the **+ Add Item** button and removed with the **Remove** button next to each row. The form repopulates with submitted values so you can adjust and re-run without re-entering data.

> **Sandbox warning:** If Sandbox Mode is enabled, a yellow banner appears at the top of the page and the results table shows a note that rates come from a sandbox environment.

---

### PirateShip CSV Export

From the order detail page, click **Export to PirateShip** to download a CSV pre-formatted for bulk import into [PirateShip](https://www.pirateship.com). Each row corresponds to one package in the shipping plan.

---

## Architecture

### Class Reference

| Class | File | Responsibility |
|---|---|---|
| `Plugin` | `includes/class-plugin.php` | Singleton bootstrap. Instantiates all services, registers the `plugins_loaded` init hook, registers the WooCommerce shipping method, and wires `woocommerce_checkout_order_processed`. Compares rates across all enabled carriers via `get_carrier_services()`. |
| `Settings` | `includes/class-settings.php` | Reads/writes all plugin options via `get_option`/`register_setting`. Provides typed getters for every setting including `get_carriers()`, per-carrier service code getters (`get_shipengine_service_code()`, `get_shipstation_service_code()`), `get_shipstation_service_pairs()`, and `get_boxes_for_carrier()` for carrier-filtered box retrieval. Renders the settings admin page with a visual box management table. |
| `Shipping_Method` | `includes/class-shipping-method.php` | Extends `WC_Shipping_Method`. Provides live shipping rates in WooCommerce shipping zones. Extracts cart items, packs them, rate-shops via all enabled carrier services, and handles "Show All Options" via cartesian product of rated box candidates per package, with rate caching. |
| `Packing_Service` | `includes/class-packing-service.php` | Collects shippable items from a `WC_Order`, packs them with `dvdoug/boxpacker` (or a simple fallback), and returns normalized package arrays. `pack_items()` accepts an optional `$boxes` parameter to override the configured box definitions. Items without dimensions are separated and packed individually via fallback. Packed package dimensions use inner box dimensions. |
| `ShipEngine_Service` | `includes/class-shipengine-service.php` | Rate-shops packed packages against the ShipEngine v1 API (`POST /v1/rates`). Supports both order-based and address-based rate requests. |
| `ShipStation_Service` | `includes/class-shipstation-service.php` | Rate-shops packed packages against the ShipStation API (`GET /shipments/getrates`) using HTTP Basic Auth. Mirrors the ShipEngine interface. Supports carrier/service code overrides for multi-pair rate shopping. Prepends `[SANDBOX]` to log messages when sandbox mode is active. |
| `Test_Pricing_Service` | `includes/class-test-pricing-service.php` | Accepts raw form items, expands them by quantity, packs them, and delegates to all enabled carrier services. Returns a results array consumed by `Admin_Test_UI`. |
| `Order_Plan_Service` | `includes/class-order-plan-service.php` | Reads and writes the `_fk_usps_shipping_plan` order meta. |
| `PirateShip_Export` | `includes/class-pirateship-export.php` | Generates PirateShip CSV rows from package plans and streams the CSV download. |
| `Admin_UI` | `includes/class-admin-ui.php` | Renders the shipping plan on the WooCommerce order detail page. |
| `Admin_Test_UI` | `includes/class-admin-test-ui.php` | Renders the **WooCommerce → USPS Test Pricing** submenu page. Handles nonce-verified form POST, parses and sanitizes submitted data, and displays per-package results. |
| `BoxPacker_Box` | `includes/class-boxpacker-box.php` | Adapts the plugin's box definitions to the `dvdoug/boxpacker` `BoxInterface`. |
| `BoxPacker_Item` | `includes/class-boxpacker-item.php` | Adapts item arrays to the `dvdoug/boxpacker` `ItemInterface`. Carries the original item array as metadata. |

---

## WordPress Hooks and Filters

### Action Hooks

| Hook | Priority | Description |
|---|---|---|
| `plugins_loaded` | default | Calls `Plugin::init()` to check WooCommerce is active and register all sub-hooks. |
| `admin_init` | default | Registers settings fields via `Settings::register_settings()`. |
| `admin_menu` | default | Adds **USPS Optimizer** and **USPS Test Pricing** submenu pages under WooCommerce. |
| `admin_enqueue_scripts` | default | Enqueues `assets/js/settings.js` and localizes `ajaxUrl`, a nonce, and i18n strings on the settings page only. |
| `woocommerce_shipping_methods` | default (filter) | Registers `Shipping_Method` (`fk_usps_optimizer`) so it appears in WooCommerce shipping zones. |
| `woocommerce_checkout_order_processed` | 20 | Triggers order packing and rate-shopping via `Plugin::process_order()`. |
| `wp_ajax_fk_usps_test_connection` | default | AJAX handler for the settings page **Test Connection** button. Verifies the nonce, calls `ShipEngine_Service::test_connection()`, and returns a JSON response so the result renders inline without a page reload. |

### Filter Hooks

All filters follow the naming convention `fk_usps_optimizer_{name}`.

| Filter | Default | Description |
|---|---|---|
| `fk_usps_optimizer_carriers` | `['shipengine']` | Override the array of enabled carrier identifiers. Return an array containing `'shipengine'` and/or `'shipstation'`. |
| `fk_usps_optimizer_carrier` | `'shipengine'` | Override the primary carrier (first enabled). Return `'shipengine'` or `'shipstation'`. Kept for backward compatibility. |
| `fk_usps_optimizer_boxes` | _(saved JSON)_ | Modify or replace the array of box definitions at runtime before packing. |
| `fk_usps_optimizer_ship_from_address` | _(settings value)_ | Override the ship-from address array. Useful for multi-warehouse setups. |
| `fk_usps_optimizer_shipengine_api_key` | _(settings value)_ | Override the ShipEngine API key at runtime (e.g. from environment variable). |
| `fk_usps_optimizer_shipengine_carrier_id` | _(settings value)_ | Override the ShipEngine carrier ID at runtime. |
| `fk_usps_optimizer_shipengine_service_code` | `'usps_priority_mail'` | Override the ShipEngine service code at runtime. |
| `fk_usps_optimizer_shipstation_api_key` | _(settings value)_ | Override the ShipStation API key at runtime. |
| `fk_usps_optimizer_shipstation_api_secret` | _(settings value)_ | Override the ShipStation API secret at runtime. |
| `fk_usps_optimizer_shipstation_carrier_code` | `'stamps_com'` | Override the ShipStation carrier code at runtime. |
| `fk_usps_optimizer_shipstation_service_code` | `'usps_priority_mail'` | Override the ShipStation service code at runtime. |
| `fk_usps_optimizer_shipstation_service_pairs` | _(primary + additional)_ | Override the array of ShipStation carrier+service pairs used for rate shopping. Each entry is an associative array with `carrier_code` and `service_code`. |
| `fk_usps_optimizer_shipstation_api_url` | `'https://ssapi.shipstation.com'` | Override the ShipStation API base URL (useful for mocking in tests). |

**Example — load credentials from environment variables:**

```php
add_filter( 'fk_usps_optimizer_shipengine_api_key', function () {
    return getenv( 'SHIPENGINE_API_KEY' ) ?: '';
} );

add_filter( 'fk_usps_optimizer_shipstation_api_key', function () {
    return getenv( 'SHIPSTATION_API_KEY' ) ?: '';
} );
```

**Example — add a custom box definition:**

```php
add_filter( 'fk_usps_optimizer_boxes', function ( array $boxes ): array {
    $boxes[] = [
        'reference'           => 'My Custom Box',
        'package_code'        => 'package',
        'package_name'        => 'My Custom Box',
        'box_type'            => 'cubic',
        'outer_width'         => 10,
        'outer_length'        => 10,
        'outer_depth'         => 10,
        'inner_width'         => 10,
        'inner_length'        => 10,
        'inner_depth'         => 10,
        'empty_weight'        => 4,
        'max_weight'          => 20,
        'carrier_restriction' => '',
    ];
    return $boxes;
} );
```

---

## Box Definition JSON Schema

Box definitions are stored in the plugin settings as a JSON array. Each element is an object with the following fields:

| Field | Type | Description |
|---|---|---|
| `reference` | string | Human-readable label shown in admin UI and logs. |
| `package_code` | string | Carrier package code sent to the API (e.g. `package`, `small_flat_rate_box`). |
| `package_name` | string | Display name shown in the shipping plan. |
| `box_type` | string | `"cubic"` for custom cubic boxes; `"flat_rate"` for USPS flat-rate boxes. |
| `outer_width` | number | Outer width in **inches** (decimals supported, e.g. `12.25`). |
| `outer_length` | number | Outer length in **inches** (decimals supported). |
| `outer_depth` | number | Outer depth (height) in **inches** (decimals supported). |
| `inner_width` | number | Inner usable width in **inches** (decimals supported). |
| `inner_length` | number | Inner usable length in **inches** (decimals supported). |
| `inner_depth` | number | Inner usable depth in **inches** (decimals supported). |
| `empty_weight` | number | Empty box weight in **ounces** (decimals supported). Added to item weight when calculating shipment weight. |
| `max_weight` | number | Maximum payload weight in **pounds** (decimals supported). |
| `carrier_restriction` | string | Restrict this box to a specific carrier: `"usps"`, `"ups"`, `"fedex"`, or `""` (empty = available to all carriers). |

**USPS Cubic Eligibility Rules (enforced automatically):**

- Volume ≤ 0.5 cubic feet.
- Longest side ≤ 18 inches.
- Total package weight (items + box) ≤ 20 lbs (320 oz).

Boxes that do not meet these criteria are silently excluded from cubic candidates. They are still considered as flat-rate candidates if `box_type` is `"flat_rate"`.

**Example JSON:**

```json
[
  {
    "reference":           "1 Bag",
    "package_code":        "package",
    "package_name":        "1 Bag",
    "box_type":            "cubic",
    "outer_width":         8,
    "outer_length":        6,
    "outer_depth":         6,
    "inner_width":         8,
    "inner_length":        6,
    "inner_depth":         6,
    "empty_weight":        3,
    "max_weight":          5,
    "carrier_restriction": ""
  },
  {
    "reference":           "USPS Medium Flat Rate",
    "package_code":        "medium_flat_rate_box",
    "package_name":        "Medium Flat Rate Box",
    "box_type":            "flat_rate",
    "outer_width":         14,
    "outer_length":        12,
    "outer_depth":         3,
    "inner_width":         14,
    "inner_length":        12,
    "inner_depth":         3,
    "empty_weight":        6,
    "max_weight":          70,
    "carrier_restriction": "usps"
  }
]
```

---

## Development

### Running Tests

```bash
# Install all Composer dependencies (including dev)
composer install

# Run the full PHPUnit test suite
composer test

# Run a single test class
./vendor/bin/phpunit tests/Unit/ShipStationServiceTest.php

# Run a single test method
./vendor/bin/phpunit --filter test_build_test_package_plan_returns_best_rate
```

Tests live in `tests/Unit/`. A `tests/bootstrap.php` file provides WordPress and WooCommerce function stubs so the suite runs without a WordPress installation.

**Test classes:**

| File | Coverage |
|---|---|
| `tests/Unit/SettingsTest.php` | All getters, sanitization, defaults, `get_boxes()`, `get_boxes_for_carrier()`, `sanitize_boxes_array()`, multi-carrier settings, per-carrier service codes, ShipStation service pairs, box table UI rendering. |
| `tests/Unit/PackingServiceTest.php` | BoxPacker path, fallback path, `pack_items()`, unmeasured item handling, inner-dimensions packing, multi-package scenarios. |
| `tests/Unit/ShipEngineServiceTest.php` | Plan building, rate parsing, credential guards, `build_all_test_package_plans()`, per-carrier service code. |
| `tests/Unit/ShipStationServiceTest.php` | Plan building, Basic-Auth, sandbox logging, cheapest-rate selection, `serviceCode`/`packageCode` in requests, `build_all_test_package_plans()`, carrier/service code overrides. |
| `tests/Unit/PluginProcessOrderTest.php` | Multi-carrier order processing, cheapest-rate selection across carriers, single-carrier fallback. |
| `tests/Unit/TestPricingServiceTest.php` | Item expansion, multi-carrier routing, warning generation. |
| `tests/Unit/AdminTestUiTest.php` | Page rendering, nonce flow, form repopulation, results table, sandbox badge. |
| `tests/Unit/ShippingMethodTest.php` | Rate calculation, caching, cache key composition (carrier, boxes, display settings, service code), show-package-count labels. |

### Building

Use `bin/build.sh` to create a production-ready ZIP that excludes all development files (listed in `.distignore`):

```bash
# Build with the version from the plugin header
bash bin/build.sh

# The ZIP is written to build/woocommerce-fk-usps-optimizer-<version>.zip
```

The script installs production-only Composer dependencies (`--no-dev`), copies plugin files via `rsync`, and then restores your local dev dependencies.

**CI pipeline:**

Every CI run produces a downloadable ZIP artifact:

- The **CI** workflow (`ci.yml`) runs lint, PHPCS, and PHPUnit tests across PHP 8.0–8.3. After all matrix jobs pass, a `build` job runs `bin/build.sh` and uploads the resulting ZIP as a GitHub Actions artifact named **`plugin-zip`** (retained for 30 days). Download it from the **Actions** tab of any workflow run.
- The **Build** workflow (`build.yml`) triggers on every push to `main`, runs the same build script, and commits the ZIP to `dist/` so it is always available directly in the repository.

### Code Style

The project follows the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/) enforced via PHP_CodeSniffer:

```bash
# Check for violations
composer phpcs

# Auto-fix where possible
composer phpcbf
```

The PHPCS configuration lives in `phpcs.xml.dist`. The `manage_woocommerce` capability is registered as a custom known-good capability so PHPCS does not flag it.

### Directory Structure

```
fk-usps-optimizer/
├── assets/
│   └── js/
│       └── settings.js             # Carrier checkbox toggle + AJAX test connection
├── bin/
│   └── build.sh                    # Production build script
├── includes/
│   ├── class-admin-test-ui.php     # Test Pricing admin page
│   ├── class-admin-ui.php          # Order detail plan display
│   ├── class-boxpacker-box.php     # dvdoug/boxpacker Box adapter
│   ├── class-boxpacker-item.php    # dvdoug/boxpacker Item adapter
│   ├── class-order-plan-service.php
│   ├── class-packing-service.php
│   ├── class-pirateship-export.php
│   ├── class-plugin.php            # Singleton bootstrap
│   ├── class-settings.php
│   ├── class-shipengine-service.php
│   ├── class-shipping-method.php      # WooCommerce shipping method (zones integration)
│   ├── class-shipstation-service.php
│   └── class-test-pricing-service.php
├── tests/
│   ├── bootstrap.php               # PHPUnit bootstrap with WP/WC stubs
│   └── Unit/
│       ├── AdminTestUiTest.php
│       ├── PackingServiceTest.php
│       ├── SettingsTest.php
│       ├── ShipEngineServiceTest.php
│       ├── ShipStationServiceTest.php
│       ├── ShippingMethodTest.php
│       └── TestPricingServiceTest.php
├── composer.json
├── composer.lock
├── phpcs.xml.dist
├── phpunit.xml.dist
├── readme.txt                      # WordPress.org plugin readme
├── README.md                       # This file
└── woocommerce-fk-usps-optimizer.php  # Plugin entry point
```

---

## Changelog

### 1.2.8

- **New:** **Box Management Table UI** — box definitions are now managed via a visual table in the settings page instead of a raw JSON textarea. Each box can be added, edited, or removed individually with dedicated input fields for all dimensions, weights, and settings.
- **New:** **Carrier Restriction** — each box definition now includes a `carrier_restriction` field. Boxes can be assigned to a specific carrier (`usps`, `ups`, `fedex`) or left empty for all carriers. This prevents carrier-specific boxes (e.g. USPS Flat Rate) from being considered for incompatible carriers (e.g. UPS).
- **New:** `Settings::get_boxes_for_carrier( string $carrier )` — returns only boxes whose `carrier_restriction` is empty (available to all) or matches the given carrier keyword (case-insensitive).
- **New:** `Settings::sanitize_boxes_array()` — sanitizes box definitions submitted from the table-based UI. Rows with a blank reference are treated as deleted.
- **Changed:** Default box set updated: replaces Cubic Small, Cubic Medium, and 3 USPS Flat Rate boxes with 1 Bag, 2 Bag, 3 Bag, 4 Bag (cubic, any carrier), USPS Medium Flat Rate (flat rate, USPS-only), and USPS Large Flat Rate (flat rate, USPS-only).
- **Improved:** JavaScript (`settings.js`) updated with dynamic row add/remove handlers and automatic index re-sequencing for the box management table.
- **Improved:** Legacy `boxes_json` textarea is retained internally for backward compatibility with programmatic/filter-based box configuration.

### 1.2.6

- **New:** **Additional Business Days** setting — adds a configurable buffer (0–30 business days) to every estimated delivery date. The buffer skips weekends (Saturday and Sunday), so a 2-business-day buffer applied on a Thursday moves the estimate to the following Monday. Useful for accounting for order processing and handling time.
- **Fixed:** Transit-days buffer now correctly adds business days (Monday–Friday) instead of calendar days, matching the "Additional Business Days" setting label.

### 1.2.5

- **New:** **Show Estimated Delivery Date** setting — when enabled, the carrier-provided estimated delivery date is appended to each shipping option label on the cart and checkout pages (including FunnelKit Checkout). ShipEngine's `estimated_delivery_date` field is used directly; ShipStation's `transitDays` field is converted to a calendar date. The formatted date (e.g. "Est. delivery: Mon, Jan 15") is also passed as WooCommerce rate metadata for themes that render it.
- **New:** `ShipStation_Service::compute_delivery_date()` — converts a transit-day count into an ISO 8601 date string by adding the given number of days to the current WordPress site time.
- **New:** `Shipping_Method::format_estimated_delivery()` — formats an ISO 8601 datetime or YYYY-MM-DD date string into a short display label (e.g. "Mon, Jan 15").
- **New:** Plan data returned by both carrier services now includes an `estimated_delivery_date` field.

### 1.2.4

- **Fixed:** Box dimension and weight fields now accept decimal values (e.g. `12.25` inches, `3.5` oz). Previously the `sanitize_boxes_json()` method used `absint()` which truncated decimals to integers, so a box entered as 12.25 × 11.5 was silently stored as 12 × 11.

### 1.2.3

- **Fixed:** ShipStation rate amounts now include both `shipmentCost` and `otherCost` (fuel surcharges, residential delivery fees, etc.). Previously only `shipmentCost` was stored in `rate_amount`, causing UPS and other non-USPS carrier rates to appear significantly lower than the actual cost at checkout. The sorting/comparison logic already summed both fields, but the displayed rate did not.

### 1.2.2

- **Fixed:** Checkout shipping labels now display the correct carrier name (e.g. "UPS Ground", "USPS Priority") instead of always showing the method title ("USPS Priority") when multiple carriers are configured via ShipStation service pairs.
- **New:** `ShipStation_Service::get_service_label()` — derives a human-readable label from the carrier code and service code (e.g. `ups_walleted` + `ups_ground` → "UPS Ground").
- **New:** `ShipEngine_Service::get_service_label()` — derives a human-readable label from the ShipEngine service code (e.g. `usps_priority_mail` → "USPS Priority").
- **New:** Plan data returned by both carrier services now includes a `service_label` field, enabling carrier-aware UI throughout the plugin.
- **Improved:** "Show All Options" labels use the carrier-specific service label per combination instead of the static shipping method title.
- **Improved:** Single-rate (cheapest option) label uses the carrier service label when all packages share the same carrier, falling back to the method title for mixed-carrier scenarios.

### 1.2.1

- **New:** Multi-carrier support — enable both ShipEngine and ShipStation simultaneously. The plugin compares rates from all enabled carriers and selects the cheapest option per package.
- **New:** Per-carrier service codes — **ShipEngine Service Code** and **ShipStation Service Code** replace the shared USPS Service Code setting. The legacy setting is still respected as a fallback for backward compatibility.
- **New:** **ShipStation Additional Services** — configure multiple carrier+service pairs (e.g. UPS Ground + USPS Priority) as a JSON array. All pairs are rate-shopped and the cheapest rate wins.
- **New:** `ShipStation_Service` now accepts optional carrier/service code overrides in the constructor, enabling per-pair rate shopping without separate settings.
- **New:** `Plugin::get_carrier_services()` and `Test_Pricing_Service::get_carrier_services()` return all enabled carrier service instances for multi-carrier rate comparison.
- **New:** `Settings::get_carriers()` returns an array of all enabled carrier identifiers (supports comma-separated storage and legacy single-value strings).
- **New:** `Settings::get_shipstation_service_pairs()` returns the primary ShipStation carrier+service pair plus any additional pairs from the JSON config.
- **New:** WordPress filters: `fk_usps_optimizer_carriers`, `fk_usps_optimizer_shipengine_service_code`, `fk_usps_optimizer_shipstation_service_code`, `fk_usps_optimizer_shipstation_service_pairs`.
- **Changed:** Carrier selection on the settings page is now a set of checkboxes instead of a dropdown, allowing multiple carriers to be enabled at once.
- **Changed:** Settings page JS (`settings.js`) updated to toggle carrier credential fields based on checkbox state instead of dropdown value.
- **Improved:** Order processing, shipping method, and test pricing service all compare rates across all enabled carriers and pick the cheapest per package.

### 1.2.0

- **New:** WooCommerce Shipping Zones integration — the plugin now registers as a native `WC_Shipping_Method` (`fk_usps_optimizer`) that can be added to any shipping zone via **WooCommerce → Settings → Shipping**.
- **New:** **Show All Options** setting — when enabled, every combination (cartesian product) of rated box candidates per packed package is offered as a separate shipping option in the cart/checkout with descriptive box-name labels. Repeated box names are consolidated (e.g. "2× Small Flat Rate Box"). Duplicate combinations are deduplicated and results are sorted cheapest-first.
- **New:** **Show Package Count** setting — appends the package count to each shipping option label (e.g. "USPS Priority Mail (2 packages)") with proper singular/plural forms via `_n()`.
- **New:** **USPS Service Code** setting — configurable service code (default `usps_priority_mail`) sent to both ShipEngine and ShipStation for rate requests.
- **New:** `build_all_test_package_plans()` method on both `ShipEngine_Service` and `ShipStation_Service` — returns all rated plans for a package sorted cheapest-first, used by the "Show All Options" feature.
- **New:** `assets/js/settings.js` — carrier credential fields show/hide instantly when the **Shipping Carrier API** dropdown changes (no save required).
- **Improved:** **Test Connection** button now fires an AJAX request and displays the pass/fail result inline — no page reload.
- **Fixed:** Packed package dimensions now use box **inner** dimensions instead of outer, so the same box type correctly matches as a rate candidate in `package_fits_box()`.
- **Fixed:** Items without WooCommerce product dimensions (`has_dimensions=false`) are now packed individually via fallback instead of using BoxPacker with default 1×1×1 inch dimensions. Dimension detection uses a strict empty-string check to avoid treating valid zero-value dimensions as unmeasured.
- **Fixed:** Rate cache key now includes carrier, service code, show-all-options, show-package-count, and box configuration — preventing stale cached rates after settings changes.
- **Fixed:** ShipStation `request_rate()` now sends the configured `serviceCode` and `packageCode` instead of `null`, so only the intended service is rated (previously returned rates for all services, producing incorrect pricing).
- **CI:** CI pipeline (`ci.yml`) now builds a production ZIP artifact after all tests pass, downloadable from the GitHub Actions **Actions** tab for 30 days.
- **Security:** Escaped CSS class attribute output on the settings page admin notice.
- **Security:** Sanitized numerical dimension/weight inputs in the Test Pricing form using `sanitize_text_field()`.
- **Security:** Guarded `$_SERVER['REQUEST_METHOD']` access with `isset()` check before comparison.
- **Improved:** `Packing_Service::pack_items()` now accepts an optional `$boxes` parameter to override the configured box definitions, enabling callers to pack against custom box sets without modifying plugin settings.

### 1.1.0

- **New:** ShipStation carrier API support (Basic-Auth, `GET /shipments/getrates`).
- **New:** Carrier selector setting — choose ShipEngine or ShipStation per environment.
- **New:** Sandbox Mode toggle — prefixes `[SANDBOX]` in logs, shows banner in admin.
- **New:** **WooCommerce → USPS Test Pricing** admin page — pack arbitrary items and preview USPS rates without a real order.
- **New:** `Packing_Service::pack_items(array)` public method for reuse by the test suite and other callers.
- **New:** `ShipEngine_Service::build_test_package_plan()` for address-based (orderless) rate lookups.
- **New:** WordPress filters for all new settings and the ShipStation API URL.
- **Improved:** Nonce is now verified before any other `$_POST` data is read.
- **Improved:** `esc_js()` stub uses `json_encode()` with full hex-escape flags.

### 1.0.0

- Initial plugin scaffold with settings, ShipEngine rate-shopping, order planning, admin display, and PirateShip CSV export.
