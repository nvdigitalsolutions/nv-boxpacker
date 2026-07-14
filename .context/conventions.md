# FK USPS Optimizer Coding Conventions

> **GSD Context File** — Load this at the start of every AI development session.
> Keep this file under 400 lines. Last reviewed: July 2026.

---

## PHP Compatibility

| Requirement | Value |
|-------------|-------|
| **Minimum PHP** | **PHP 8.0+** |
| **WordPress** | 6.0+ |
| **WooCommerce** | Declared dependency (Requires Plugins header) |

PHP 8.0+ means you can use: named arguments, union types `string|int`, match expressions, nullsafe operator `?->`, constructor promotion, `str_contains()` / `str_starts_with()` / `str_ends_with()`. No need for PHP 7.4 fallbacks.

---

## Class & Function Naming

| Type | Convention | Example |
|------|-----------|---------|
| PHP Namespace | `FK_USPS_Optimizer\{Component}` | `FK_USPS_Optimizer\ShipEngine_Service` |
| PHP Classes | `{Feature}_{Component}` (within namespace) | `ShipEngine_Service`, `Order_Plan_Service` |
| Constants | `FK_USPS_OPTIMIZER_{NAME}` | `FK_USPS_OPTIMIZER_VERSION` |
| Action Hooks | `fk_usps_optimizer_{name}` | `fk_usps_optimizer_carriers` |
| Filter Hooks | `fk_usps_optimizer_{name}` | `fk_usps_optimizer_skip_rates` |
| Option Keys | `fk_usps_optimizer_{name}` | `fk_usps_optimizer_settings` |
| Transient Keys | `fk_usps_opt_{hash}` | `fk_usps_opt_se_a1b2c3` |
| Text Domain | `fk-usps-optimizer` | (WordPress plugin text domain) |

---

## File Organization

```
woocommerce-fk-usps-optimizer.php       ← Plugin entry point (ABSPATH guard → constants → autoload → bootstrap)
includes/
├── class-plugin.php                     ← Main singleton + DI container
├── class-settings.php                   ← Settings page, field rendering, sanitization
├── class-shipping-method.php            ← WC_Shipping_Method implementation
├── class-packing-service.php            ← BoxPacker integration + fallback
├── class-boxpacker-box.php              ← BoxPacker box adapter
├── class-boxpacker-item.php             ← BoxPacker item adapter
├── class-shipengine-service.php         ← ShipEngine API client (POST /v1/rates)
├── class-shipstation-service.php        ← ShipStation API client (GET /shipments/getrates)
├── class-order-plan-service.php         ← Order plan storage/retrieval (post meta)
├── class-test-pricing-service.php       ← Admin test pricing logic
├── class-pirateship-export.php          ← PirateShip CSV export
├── class-admin-ui.php                   ← Order metabox + export link
└── class-admin-test-ui.php              ← Test pricing admin page
assets/
└── js/
    └── admin.js                          ← Settings page + test pricing JS
tests/
├── bootstrap.php                         ← WP stub definitions for unit tests
└── Unit/
    └── *Test.php                         ← 14 PHPUnit test classes
dist/                                     ← Built plugin ZIP (CI artifact)
```

---

## Build & Test Commands

```bash
# PHP linting (WordPress Coding Standards)
composer run lint

# PHPUnit test suite (512 tests, 1076 assertions)
composer run test

# Run specific test file
vendor/bin/phpunit --configuration phpunit.xml.dist tests/Unit/SettingsTest.php

# Run tests with coverage
vendor/bin/phpunit --configuration phpunit.xml.dist --coverage-html coverage/
```

> **Before every PR:** `composer run lint && composer run test`

---

## Key Constants

| Constant | Purpose |
|----------|---------|
| `FK_USPS_OPTIMIZER_VERSION` | Plugin version (must match plugin header) |
| `FK_USPS_OPTIMIZER_FILE` | Root plugin file path |
| `FK_USPS_OPTIMIZER_PATH` | Plugin directory path (trailing slash) |
| `FK_USPS_OPTIMIZER_URL` | Plugin directory URL (trailing slash) |

---

## PHP Code Style

- **Standard:** WordPress Coding Standards (WPCS) — zero violations
- **Indentation:** Tabs (not spaces)
- **Braces:** Opening brace on same line for functions/methods, Allman style for classes
- **Line length:** 120 characters max
- **PHP tags:** Full `<?php` only, never short tags

---

## PHPDoc Requirements

Every class, method, and function **must** have a PHPDoc block:

```php
/**
 * Brief description of what this does.
 *
 * Longer description if needed.
 *
 * @since  1.x.x
 * @param  string $param_name Description of parameter.
 * @return array|WP_Error Result or error.
 */
```

---

## Security Requirements (Always Apply)

### Input Sanitization

```php
sanitize_text_field( $input )      // General strings
absint( $input )                    // Positive integers
sanitize_key( $input )              // Option keys, slugs
sanitize_email( $input )            // Email addresses
wp_unslash( $_POST['key'] )         // Always unslash before sanitize
```

### Output Escaping

```php
esc_html( $value )                  // Plain text output
esc_attr( $value )                  // HTML attribute values
esc_url( $url )                     // URLs in href/src
esc_textarea( $value )              // Textarea content
wp_kses_post( $content )            // HTML content output
```

### Capability Checks

```php
if ( ! current_user_can( 'manage_woocommerce' ) ) {
    wp_die( esc_html__( 'Permission denied.', 'fk-usps-optimizer' ) );
}
```

### Nonce Verification

```php
check_admin_referer( 'fk_usps_optimizer_action' );
// Or:
if ( ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'fk_usps_optimizer_action' ) ) {
    wp_die( esc_html__( 'Security check failed.', 'fk-usps-optimizer' ) );
}
```

### ABSPATH Guard (All Non-Root PHP Files)

```php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
```

---

## Internationalization

```php
__( 'String', 'fk-usps-optimizer' )
esc_html__( 'String', 'fk-usps-optimizer' )
esc_attr__( 'String', 'fk-usps-optimizer' )
_n( 'Single', 'Plural', $count, 'fk-usps-optimizer' )
sprintf(
    /* translators: %d: a number. */
    __( 'Result: %d', 'fk-usps-optimizer' ),
    $number
)
```

---

## Commit Message Convention

```
feat(scope): brief description
fix(scope): brief description
docs(scope): brief description
test(scope): brief description
refactor(scope): brief description
chore(scope): brief description
```

Examples:
- `feat(shipping): add Canada destination support to rate calculation`
- `fix(shipstation): handle empty rate response gracefully`
- `test(shipping): add CA postcode validation tests`

---

## Version Locations (Must All Match)

When bumping the version, update ALL of these:
1. `woocommerce-fk-usps-optimizer.php` — plugin header `Version:`
2. `woocommerce-fk-usps-optimizer.php` — constant `FK_USPS_OPTIMIZER_VERSION`
3. `readme.txt` — `Stable tag:`
4. `composer.json` — `"version"` field (if present)
