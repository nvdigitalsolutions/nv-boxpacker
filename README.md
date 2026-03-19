# FunnelKit USPS Priority Shipping Optimizer

A WooCommerce plugin that optimizes USPS Priority Mail shipping for FunnelKit orders by packing items into custom cubic boxes and USPS flat-rate boxes, rate-shopping through ShipEngine or ShipStation, and producing package-level plans suitable for PirateShip export.

---

## Table of Contents

1. [Overview](#overview)
2. [Requirements](#requirements)
3. [Installation](#installation)
   - [Production](#production)
   - [Development](#development)
4. [Configuration](#configuration)
   - [Shipping Carrier API](#shipping-carrier-api)
   - [ShipEngine Settings](#shipengine-settings)
   - [ShipStation Settings](#shipstation-settings)
   - [Test ShipEngine Connection](#test-shipengine-connection)
   - [Sandbox Mode](#sandbox-mode)
   - [Ship-From Address](#ship-from-address)
   - [Debug Logging](#debug-logging)
   - [Box Definitions](#box-definitions)
5. [Features](#features)
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

### Shipping Carrier API

| Setting | Values | Default |
|---|---|---|
| **Shipping Carrier API** | `ShipEngine` or `ShipStation` | ShipEngine |

Select which carrier API to use for live rate requests. Only one is active at a time; the other's credentials are saved but ignored. Switching the dropdown immediately shows or hides the relevant credential fields without saving the page.

---

### ShipEngine Settings

| Setting | Description |
|---|---|
| **ShipEngine API Key** | API key from [app.shipengine.com](https://app.shipengine.com) → API Management. |
| **ShipEngine Carrier ID** | The carrier ID for your USPS account (e.g. `se-123456`). Found in **Carriers** on the ShipEngine dashboard. |

ShipEngine uses a header-based `API-Key` authentication scheme and calls the `POST /v1/rates` endpoint.

---

### ShipStation Settings

| Setting | Description |
|---|---|
| **ShipStation API Key** | API key from ShipStation → Account Settings → API Settings. |
| **ShipStation API Secret** | API secret from the same page. |
| **ShipStation Carrier Code** | Carrier code to rate against (e.g. `stamps_com`, `fedex`). Default: `stamps_com`. |

ShipStation uses HTTP Basic Authentication (`API Key:API Secret`) and calls the `GET /shipments/getrates` endpoint.

> You can override the ShipStation API base URL using the `fk_usps_optimizer_shipstation_api_url` filter (see [Filter Hooks](#filter-hooks)).

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

Box definitions are stored as a JSON array in the **Box Definitions JSON** textarea. Each object in the array represents one physical box.

See [Box Definition JSON Schema](#box-definition-json-schema) for the full field reference and a worked example.

---

## Features

### Automatic Order Planning

On `woocommerce_checkout_order_processed` (priority 20), the plugin:

1. Collects shippable items via `Packing_Service::pack_order()`.
2. Packs them using BoxPacker (or fallback).
3. Rate-shops every packed package with the active carrier service.
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

The plugin packs the items and fetches live rates from the active carrier. Results are displayed per package and include:

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
| `Plugin` | `includes/class-plugin.php` | Singleton bootstrap. Instantiates all services, registers the `plugins_loaded` init hook, and wires `woocommerce_checkout_order_processed`. |
| `Settings` | `includes/class-settings.php` | Reads/writes all plugin options via `get_option`/`register_setting`. Provides typed getters for every setting. Renders the settings admin page. |
| `Packing_Service` | `includes/class-packing-service.php` | Collects shippable items from a `WC_Order`, packs them with `dvdoug/boxpacker` (or a simple fallback), and returns normalized package arrays. |
| `ShipEngine_Service` | `includes/class-shipengine-service.php` | Rate-shops packed packages against the ShipEngine v1 API (`POST /v1/rates`). Supports both order-based and address-based rate requests. |
| `ShipStation_Service` | `includes/class-shipstation-service.php` | Rate-shops packed packages against the ShipStation API (`GET /shipments/getrates`) using HTTP Basic Auth. Mirrors the ShipEngine interface. Prepends `[SANDBOX]` to log messages when sandbox mode is active. |
| `Test_Pricing_Service` | `includes/class-test-pricing-service.php` | Accepts raw form items, expands them by quantity, packs them, and delegates to the active carrier service. Returns a results array consumed by `Admin_Test_UI`. |
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
| `woocommerce_checkout_order_processed` | 20 | Triggers order packing and rate-shopping via `Plugin::process_order()`. |
| `wp_ajax_fk_usps_test_connection` | default | AJAX handler for the settings page **Test Connection** button. Verifies the nonce, calls `ShipEngine_Service::test_connection()`, and returns a JSON response so the result renders inline without a page reload. |

### Filter Hooks

All filters follow the naming convention `fk_usps_optimizer_{name}`.

| Filter | Default | Description |
|---|---|---|
| `fk_usps_optimizer_carrier` | `'shipengine'` | Override the active carrier service. Return `'shipengine'` or `'shipstation'`. |
| `fk_usps_optimizer_boxes` | _(saved JSON)_ | Modify or replace the array of box definitions at runtime before packing. |
| `fk_usps_optimizer_ship_from_address` | _(settings value)_ | Override the ship-from address array. Useful for multi-warehouse setups. |
| `fk_usps_optimizer_shipengine_api_key` | _(settings value)_ | Override the ShipEngine API key at runtime (e.g. from environment variable). |
| `fk_usps_optimizer_shipengine_carrier_id` | _(settings value)_ | Override the ShipEngine carrier ID at runtime. |
| `fk_usps_optimizer_shipstation_api_key` | _(settings value)_ | Override the ShipStation API key at runtime. |
| `fk_usps_optimizer_shipstation_api_secret` | _(settings value)_ | Override the ShipStation API secret at runtime. |
| `fk_usps_optimizer_shipstation_carrier_code` | `'stamps_com'` | Override the ShipStation carrier code at runtime. |
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
        'reference'    => 'My Custom Box',
        'package_code' => 'package',
        'package_name' => 'My Custom Box',
        'box_type'     => 'cubic',
        'outer_width'  => 10,
        'outer_length' => 10,
        'outer_depth'  => 10,
        'inner_width'  => 10,
        'inner_length' => 10,
        'inner_depth'  => 10,
        'empty_weight' => 4,
        'max_weight'   => 20,
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
| `outer_width` | integer | Outer width in **inches**. |
| `outer_length` | integer | Outer length in **inches**. |
| `outer_depth` | integer | Outer depth (height) in **inches**. |
| `inner_width` | integer | Inner usable width in **inches**. |
| `inner_length` | integer | Inner usable length in **inches**. |
| `inner_depth` | integer | Inner usable depth in **inches**. |
| `empty_weight` | integer | Empty box weight in **ounces**. Added to item weight when calculating shipment weight. |
| `max_weight` | integer | Maximum payload weight in **pounds**. |

**USPS Cubic Eligibility Rules (enforced automatically):**

- Volume ≤ 0.5 cubic feet.
- Longest side ≤ 18 inches.
- Total package weight (items + box) ≤ 20 lbs (320 oz).

Boxes that do not meet these criteria are silently excluded from cubic candidates. They are still considered as flat-rate candidates if `box_type` is `"flat_rate"`.

**Example JSON:**

```json
[
  {
    "reference":    "Cubic Small",
    "package_code": "package",
    "package_name": "Custom Cubic Small",
    "box_type":     "cubic",
    "outer_width":  8,
    "outer_length": 8,
    "outer_depth":  6,
    "inner_width":  8,
    "inner_length": 8,
    "inner_depth":  6,
    "empty_weight": 3,
    "max_weight":   20
  },
  {
    "reference":    "USPS Small Flat Rate",
    "package_code": "small_flat_rate_box",
    "package_name": "USPS Small Flat Rate Box",
    "box_type":     "flat_rate",
    "outer_width":  9,
    "outer_length": 6,
    "outer_depth":  2,
    "inner_width":  9,
    "inner_length": 6,
    "inner_depth":  2,
    "empty_weight": 4,
    "max_weight":   70
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
| `tests/Unit/SettingsTest.php` | All getters, sanitization, defaults, `get_boxes()`. |
| `tests/Unit/PackingServiceTest.php` | BoxPacker path, fallback path, `pack_items()`. |
| `tests/Unit/ShipEngineServiceTest.php` | Plan building, rate parsing, credential guards. |
| `tests/Unit/ShipStationServiceTest.php` | Plan building, Basic-Auth, sandbox logging, cheapest-rate selection. |
| `tests/Unit/TestPricingServiceTest.php` | Item expansion, carrier routing, warning generation. |
| `tests/Unit/AdminTestUiTest.php` | Page rendering, nonce flow, form repopulation, results table, sandbox badge. |

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
│       └── settings.js             # Carrier field toggle + AJAX test connection
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

### 1.2.0

- **New:** `assets/js/settings.js` — carrier credential fields show/hide instantly when the **Shipping Carrier API** dropdown changes (no save required).
- **Improved:** **Test Connection** button now fires an AJAX request and displays the pass/fail result inline — no page reload.
- **CI:** CI pipeline (`ci.yml`) now builds a production ZIP artifact after all tests pass, downloadable from the GitHub Actions **Actions** tab for 30 days.

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
