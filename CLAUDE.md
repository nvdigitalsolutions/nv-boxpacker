# FK USPS Optimizer — Claude Code Context

> **This file is loaded every turn by Claude Code.** Keep it focused and actionable.
> Last reviewed: **July 2026** · Version: **1.0**

### Related Files

| File | Purpose |
|------|---------|
| [`AGENTS.md`](AGENTS.md) | Full AI agent inventory, BMAD roles, context-loading strategy |
| [`.context/conventions.md`](.context/conventions.md) | Naming conventions, PHP compat, code style |
| [`.context/security-checklist.md`](.context/security-checklist.md) | Security requirements for all code changes |
| [`.context/testing.md`](.context/testing.md) | PHPUnit test conventions |
| [`.github/copilot-instructions.md`](.github/copilot-instructions.md) | GitHub Copilot repo-level context |

---

## What This Is

FK USPS Optimizer is a **WooCommerce shipping plugin** that optimizes orders for USPS Priority cubic custom boxes and USPS Priority flat-rate boxes. It supports both US and Canadian destination addresses via ShipEngine and ShipStation carrier APIs.

The plugin:
- Packs WooCommerce order items using DVDoug BoxPacker (with a single-item-per-box fallback)
- Rate-shops packed boxes against ShipEngine (`POST /v1/rates`) and/or ShipStation (`GET /shipments/getrates`) APIs
- Registers as a native WooCommerce shipping method in shipping zones
- Provides an admin test pricing page for previewing rates without placing orders
- Exports packing plans as PirateShip-ready CSV files

## PHP Compatibility

| Requirement | Value |
|-------------|-------|
| **Minimum PHP** | **PHP 8.0+** |
| **WordPress** | 6.0+ |
| **WooCommerce** | Declared dependency |

PHP 8.0+ features are allowed: named arguments, union types, match expressions, nullsafe operator, constructor promotion, `str_contains()` / `str_starts_with()` / `str_ends_with()`.

## Naming Conventions

| Type | Pattern | Example |
|------|---------|---------|
| PHP Namespace | `FK_USPS_Optimizer\{Component}` | `FK_USPS_Optimizer\ShipEngine_Service` |
| PHP Classes | `{Feature}_{Component}` | `ShipEngine_Service`, `Order_Plan_Service` |
| Constants | `FK_USPS_OPTIMIZER_{NAME}` | `FK_USPS_OPTIMIZER_VERSION` |
| Action Hooks | `fk_usps_optimizer_{name}` | `fk_usps_optimizer_carriers` |
| Filter Hooks | `fk_usps_optimizer_{name}` | `fk_usps_optimizer_skip_rates` |
| Option Keys | `fk_usps_optimizer_{name}` | `fk_usps_optimizer_settings` |
| Text Domain | `fk-usps-optimizer` | (WordPress plugin text domain) |

## File Structure

```
woocommerce-fk-usps-optimizer.php       ← Plugin entry point
includes/
├── class-plugin.php                     ← Main singleton + DI container
├── class-settings.php                   ← Settings page, fields, sanitization
├── class-shipping-method.php            ← WC_Shipping_Method implementation
├── class-packing-service.php            ← BoxPacker integration + fallback
├── class-boxpacker-box.php              ← BoxPacker box adapter
├── class-boxpacker-item.php             ← BoxPacker item adapter
├── class-shipengine-service.php         ← ShipEngine API client
├── class-shipstation-service.php        ← ShipStation API client
├── class-order-plan-service.php         ← Order plan storage (post meta)
├── class-test-pricing-service.php       ← Admin test pricing logic
├── class-pirateship-export.php          ← PirateShip CSV export
├── class-admin-ui.php                   ← Order metabox + export link
└── class-admin-test-ui.php              ← Test pricing admin page
assets/
└── js/
    └── admin.js                          ← Settings page + test pricing JS
tests/
├── bootstrap.php                         ← WP stub definitions
└── Unit/
    └── *Test.php                         ← 14 PHPUnit test classes
dist/                                     ← Built plugin ZIP (CI artifact)
```

## Security — Non-Negotiable

Every code change must:
- **Sanitize input**: `sanitize_text_field()`, `absint()`, `sanitize_key()`, `sanitize_email()`
- **Escape output**: `esc_html()`, `esc_attr()`, `esc_url()`, `esc_textarea()`
- **Check capabilities**: `current_user_can( 'manage_woocommerce' )` for admin operations
- **Verify nonces**: `check_admin_referer()`, `check_ajax_referer()` for state changes
- **ABSPATH guard**: Every non-root PHP file starts with `if ( ! defined( 'ABSPATH' ) ) { exit; }`
- **API credentials**: Never log, commit, or expose ShipEngine/ShipStation keys

Full security checklist: [`.context/security-checklist.md`](.context/security-checklist.md)

## Key Architecture Patterns

### Plugin Bootstrap

```php
// woocommerce-fk-usps-optimizer.php
define( 'FK_USPS_OPTIMIZER_VERSION', '1.4.0' );
define( 'FK_USPS_OPTIMIZER_FILE', __FILE__ );
define( 'FK_USPS_OPTIMIZER_PATH', plugin_dir_path( __FILE__ ) );
define( 'FK_USPS_OPTIMIZER_URL', plugin_dir_url( __FILE__ ) );

require_once $autoload;
require_once FK_USPS_OPTIMIZER_PATH . 'includes/class-plugin.php';
\FK_USPS_Optimizer\Plugin::bootstrap();
```

### Singleton Pattern

The main `Plugin` class uses a singleton pattern. All service classes (Settings, ShipEngine_Service, ShipStation_Service, Packing_Service, etc.) are instantiated lazily through the Plugin container:

```php
$plugin   = Plugin::bootstrap();
$settings = $plugin->get_settings();
$packages = $plugin->get_packing_service()->pack_items( $items );
```

### Shipping Method

The plugin registers as a WooCommerce shipping method via `woocommerce_shipping_methods` filter. It calculates rates during checkout by:
1. Extracting items from the WC package
2. Packing items into boxes (BoxPacker)
3. Rate-shopping packed boxes against carrier APIs
4. Adding the cheapest or all options (depending on settings) as WC rates

### Carrier API Integration

Two carrier services implement the same interface pattern:
- `ShipEngine_Service` — `POST /v1/rates` with `API-Key` header
- `ShipStation_Service` — `POST /shipments/getrates` with HTTP Basic Auth

Both use `wp_remote_post()` and cache responses in transients (5-min TTL, filterable).

### Filter Hooks

| Hook | Type | Purpose |
|------|------|---------|
| `fk_usps_optimizer_carriers` | filter | Override enabled carrier list |
| `fk_usps_optimizer_skip_rates` | filter | Hard-skip rate calculation |
| `fk_usps_optimizer_min_postcode_length` | filter | Per-country postcode minimum |
| `fk_usps_optimizer_api_timeout` | filter | HTTP timeout per carrier |
| `fk_usps_optimizer_rate_cache_ttl` | filter | Rate cache TTL in seconds |
| `fk_usps_optimizer_max_candidates` | filter | Max box candidates to rate-shop |
| `fk_usps_optimizer_shipstation_api_url` | filter | Override ShipStation API base URL |

## Build & Test Commands

```bash
# PHP linting (WordPress Coding Standards)
composer run lint

# PHPUnit test suite (512 tests, 1076 assertions)
composer run test

# Run a specific test file
vendor/bin/phpunit --configuration phpunit.xml.dist tests/Unit/ShippingMethodTest.php

# Run a single test method
vendor/bin/phpunit --configuration phpunit.xml.dist --filter test_skip_when_country_is_missing
```

> **Before every PR:** `composer run lint && composer run test`

## Commit Convention

```
feat(scope): brief description
fix(scope): brief description
docs(scope): brief description
test(scope): brief description
```

## Context Engineering Files

| File | When to Load |
|------|-------------|
| `.context/conventions.md` | Always — naming, style, PHP compat, build commands |
| `.context/security-checklist.md` | Always — security requirements, API credential handling |
| `.context/testing.md` | Writing or reviewing PHPUnit tests |

---

## Coding Behavior Guidelines

### 1. Think Before Coding

**Don't assume. Don't hide confusion. Surface tradeoffs.**

Before implementing:
- State your assumptions explicitly. If uncertain, ask.
- If multiple interpretations exist, present them — don't pick silently.
- If a simpler approach exists, say so. Push back when warranted.

### 2. Simplicity First

**Minimum code that solves the problem. Nothing speculative.**

- No features beyond what was asked.
- No abstractions for single-use code.
- No error handling for impossible scenarios.

### 3. Surgical Changes

**Touch only what you must. Clean up only your own mess.**

- Don't "improve" adjacent code, comments, or formatting.
- Don't refactor things that aren't broken.
- Match existing style, even if you'd do it differently.

### 4. Goal-Driven Execution

**Define success criteria. Loop until verified.**

For multi-step tasks, state a brief plan:
```
1. [Step] → verify: [check]
2. [Step] → verify: [check]
3. [Step] → verify: [check]
```

---

## Version Locations (Must All Match)

When bumping the version, update ALL of these:
1. `woocommerce-fk-usps-optimizer.php` — plugin header `Version:`
2. `woocommerce-fk-usps-optimizer.php` — constant `FK_USPS_OPTIMIZER_VERSION`
3. `readme.txt` — `Stable tag:`
4. `composer.json` — `"version"` field

---

## Multi-Agent Ecosystem

This repository is developed by multiple AI coding agents. You (Claude Code) are one of them.

| Agent | Context File | Overlap |
|-------|-------------|---------|
| **Claude Code** | This file (`CLAUDE.md`) | — |
| **GitHub Copilot** | `.github/copilot-instructions.md` | Shares conventions, security rules |
| **GitHub Custom Agents** | `.github/agents/*.agent.md` | Role-specific only — defers to `AGENTS.md` / `CLAUDE.md` / `.context/` |
| **BMAD Agents** | `.bmad/agents/*.yaml` | Specialized workflow roles (6 agents) |

Full agent inventory: [`AGENTS.md`](AGENTS.md)

### Key Points for Claude Code Sessions
- Load `.context/conventions.md` + `.context/security-checklist.md` at minimum for every session.
- Load `.context/testing.md` only when writing/reviewing tests.
- If a BMAD workflow is active, the orchestrator agent coordinates — follow the phase gates.
- Do **not** duplicate work another agent has already completed. Check `git log` first.

---

## Troubleshooting

### Common Pitfalls

| Symptom | Cause | Fix |
|---------|-------|-----|
| Test fails with "Class WC_Test_Logger not found" | Running without bootstrap | Use `--configuration phpunit.xml.dist` |
| Carrier API returns no rates for Canada | Missing `toCountry` in payload | Ensure `country_code` is passed through from destination |
| ShipStation rate cache serving stale data | Transient not invalidated | Set `fk_usps_optimizer_rate_cache_ttl` filter or enable sandbox mode |
