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
# Full test suite (512 tests, 1076 assertions)
composer run test

# Run with configuration (bootstrap + test suite)
vendor/bin/phpunit --configuration phpunit.xml.dist

# Run a single test class
vendor/bin/phpunit --configuration phpunit.xml.dist tests/Unit/ShippingMethodTest.php

# Run a single test method
vendor/bin/phpunit --configuration phpunit.xml.dist --filter test_skip_when_country_is_missing
```

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
