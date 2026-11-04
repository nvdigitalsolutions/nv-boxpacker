# FK USPS Optimizer Testing Conventions

> **GSD Context File** — Load when writing or reviewing tests.
> Last reviewed: July 2026.

---

## Test Structure

```
tests/
├── bootstrap.php            ← WP function stubs for unit test runtime
└── Unit/
    ├── AdminTestUiTest.php
    ├── AdminUiTest.php
    ├── BoxPackerBoxTest.php
    ├── BoxPackerItemTest.php
    ├── OrderPlanServiceTest.php
    ├── PackingServiceTest.php
    ├── PirateShipExportTest.php
    ├── PluginCarrierServicesTest.php
    ├── PluginProcessOrderTest.php
    ├── SettingsTest.php
    ├── ShipEngineServiceTest.php
    ├── ShipStationServiceTest.php
    ├── ShippingMethodTest.php
    └── TestPricingServiceTest.php
```

---

## Running Tests

```bash
# Full test suite (562 tests, 1187 assertions)
composer run test

# Run with configuration (bootstrap + test suite)
vendor/bin/phpunit --configuration phpunit.xml.dist

# Run a single test class
vendor/bin/phpunit --configuration phpunit.xml.dist tests/Unit/ShippingMethodTest.php

# Run a single test method
vendor/bin/phpunit --configuration phpunit.xml.dist --filter test_skip_when_country_is_missing
```

---

## Live API Tests (optional, network + credentials required)

`tests/Integration/` holds live tests that hit the **real** ShipEngine sandbox and ShipStation APIs. They are **skipped automatically** when credentials are missing, so they never block the unit suite or CI.

```bash
# Runs the live suite; skips what it cannot authenticate
composer run test-live
```

Credentials come from environment variables:

| Variable | Required | Default |
|----------|----------|---------|
| `FK_USPS_OPTIMIZER_TEST_SHIPENGINE_API_KEY` | for ShipEngine tests | — (sandbox `TEST_` key) |
| `FK_USPS_OPTIMIZER_TEST_SHIPENGINE_CARRIER_ID` | no | `se-423887` (sandbox stamps_com) |
| `FK_USPS_OPTIMIZER_TEST_SHIPSTATION_API_KEY` + `_API_SECRET` | for ShipStation tests | — |
| `FK_USPS_OPTIMIZER_TEST_SHIPSTATION_CARRIER_CODE` | no | `stamps_com` |
| `FK_USPS_OPTIMIZER_TEST_SHIPSTATION_SERVICE_CODE` | no | `usps_priority_mail` |
| `FK_USPS_OPTIMIZER_TEST_SHIP_FROM_POSTAL` / `_SHIP_TO_POSTAL` | no | `90210` / `78701` |
| `FK_USPS_OPTIMIZER_TEST_CA_BUNDLE` | only when PHP cURL has no system CAs | e.g. `C:\Program Files\Git\usr\ssl\cert.pem` |

**Where to keep credentials locally:** copy `phpunit.live.local.xml.example` → `phpunit.live.local.xml` (git-ignored) and fill in the `<env>` values, then run `vendor/bin/phpunit -c phpunit.live.local.xml`. Never commit API keys.

**Live passthrough seam:** the unit bootstrap stubs `wp_remote_post`/`wp_remote_get`; live tests set `$GLOBALS['_test_wp_remote_live'] = true` (and unset it in `tearDown()`), which routes those functions through `live_http_request()` (cURL) with the same response shape the plugin parsers expect.

---

## Test Bootstrap

The `tests/bootstrap.php` file defines all WordPress/WooCommerce function and class stubs needed to run unit tests without a full WordPress installation. Tests use global state to simulate WP behavior:

| Global | Purpose |
|--------|---------|
| `$GLOBALS['_test_wp_options']` | Simulates `get_option()` / `update_option()` |
| `$GLOBALS['_test_wp_remote_post']` | Stubs `wp_remote_post()` responses |
| `$GLOBALS['_test_wp_remote_get']` | Stubs `wp_remote_get()` responses |
| `$GLOBALS['_test_wp_filters']` | Simulates `apply_filters()` hook system |
| `$GLOBALS['_test_wp_transients']` | Simulates `get_transient()` / `set_transient()` |
| `$GLOBALS['_test_wc_logger']` | Stubs WooCommerce logger |

---

## Writing Tests

### Test Naming Convention

```
test_{what}_{under}_{which_condition}()
```

Examples:
- `test_skip_when_country_is_missing()`
- `test_proceed_when_us_postcode_is_complete()`
- `test_get_settings_returns_defaults_when_no_option_saved()`

### Test Structure (Arrange-Act-Assert)

```php
public function test_skip_when_ca_postcode_is_complete(): void {
    // Arrange
    $package = array(
        'destination' => array( 'country' => 'CA', 'postcode' => 'M5V 2T6' ),
    );

    // Act
    $result = $this->call_protected( 'should_skip_rate_calculation', array( $package ) );

    // Assert
    $this->assertFalse( $result );
}
```

### Testing Protected Methods

Use reflection to test protected/private methods:

```php
private function call_protected( string $name, array $args = array() ) {
    $ref = new \ReflectionMethod( $this->method, $name );
    $ref->setAccessible( true );
    return $ref->invokeArgs( $this->method, $args );
}
```

### Mocking Carrier APIs

To test rate calculation without hitting live APIs:

```php
// Stub wp_remote_post to return a mock success response:
$GLOBALS['_test_wp_remote_post'] = array(
    'response' => array( 'code' => 200 ),
    'body'     => json_encode( array(
        'rate_response' => array(
            'rates' => array(
                array(
                    'shipping_amount' => array(
                        'amount'   => 12.50,
                        'currency' => 'USD',
                    ),
                ),
            ),
        ),
    ) ),
);
```

---

## Coverage Expectations

| Component | Minimum Coverage | Priority |
|-----------|-----------------|----------|
| `Shipping_Method` | `should_skip_rate_calculation`, `build_ship_to`, `format_estimated_delivery` | 🔴 High |
| `Settings` | `get_settings`, `get_boxes`, `get_carriers`, `sanitize_settings` | 🔴 High |
| `ShipEngine_Service` | `build_candidates`, `is_cubic_eligible`, `request_rate_for_address` | 🟡 Medium |
| `ShipStation_Service` | `build_candidates`, `build_rate_request_descriptor` | 🟡 Medium |
| `Packing_Service` | `pack_items`, `get_shippable_items` | 🟡 Medium |
| `Plugin` | `get_carrier_services`, `process_order` | 🟢 Low |
| `Admin_Test_UI` | Form parsing, validation | 🟢 Low |

---

## Adding a New Test File

1. Create `tests/Unit/NewFeatureTest.php`
2. Extend `PHPUnit\Framework\TestCase`
3. Add `setUp()` to reset global state:
   ```php
   protected function setUp(): void {
       $GLOBALS['_test_wp_options']    = array();
       $GLOBALS['_test_wp_filters']    = array();
       $GLOBALS['_test_wp_transients'] = array();
   }
   ```
4. Use `\FK_USPS_Optimizer\Tests\Unit` namespace
5. Run `composer run test` to verify
