# Copilot Instructions for FK USPS Optimizer

This repository contains **FK USPS Optimizer**, a WooCommerce shipping plugin that optimizes orders for USPS Priority cubic custom boxes and flat-rate boxes to US and Canadian destinations using ShipEngine and ShipStation carrier APIs.

## Multi-Agent Awareness

This repo is developed by multiple AI agents. Before doing work, be aware of:

- **`AGENTS.md`** — single source of truth for the agent inventory, context-loading strategy, and inter-agent coordination rules.
- **`CLAUDE.md`** — Claude Code's per-turn context (naming conventions, PHP-compat, security, architecture).
- **`.github/agents/*.agent.md`** — GitHub Custom Agents auto-discovered by Copilot Coding Agent. Each file declares one role-specific agent. Per the layering rule in `AGENTS.md`, these files are intentionally slim — they do **not** restate naming/security/PHP-compat rules, so always cross-reference `AGENTS.md` + `CLAUDE.md` + `.context/` for those.
- **`.context/*.md`** — subsystem context files loaded on-demand (conventions, security checklist, testing).
- **`.bmad/agents/*.yaml`** — internal BMAD workflow agents for structured feature development.

When making changes, check `git log` first — another agent may have already addressed part of the task.

## Key Technologies

- **WordPress Plugin** (PHP 8.0+, WordPress 6.0+)
- **WooCommerce** — shipping method integration, shipping zones
- **Carrier APIs**: ShipEngine (`POST /v1/rates`), ShipStation (`GET /shipments/getrates`)
- **DVDoug BoxPacker** — 3D bin-packing algorithm
- **USPS Priority Mail** — cubic pricing and flat-rate box optimization

## Repository Structure

```
woocommerce-fk-usps-optimizer.php       ← Plugin entry point
includes/
├── class-plugin.php                     ← Main singleton + DI container
├── class-settings.php                   ← Settings page, fields, sanitization
├── class-shipping-method.php            ← WC_Shipping_Method implementation
├── class-packing-service.php            ← BoxPacker integration
├── class-shipengine-service.php         ← ShipEngine API client
├── class-shipstation-service.php        ← ShipStation API client
├── class-order-plan-service.php         ← Order plan storage
├── class-test-pricing-service.php       ← Admin test pricing
├── class-pirateship-export.php          ← PirateShip CSV export
├── class-admin-ui.php                   ← Order metabox
└── class-admin-test-ui.php              ← Test pricing admin page
tests/
└── Unit/
    └── *Test.php                         ← PHPUnit tests (512 tests)
assets/
└── js/
    └── admin.js                          ← Settings page JS
```

## Coding Standards

### PHP Standards

- **WordPress Coding Standards (WPCS)**: Zero violations
- **PHP 8.0+**: Named arguments, union types, match expressions, nullsafe operator, `str_contains()` all allowed
- **Naming Conventions**:
  - Namespace: `FK_USPS_Optimizer\{Component}`
  - Classes: `{Feature}_{Component}`
  - Hooks: `fk_usps_optimizer_{name}`
  - Options: `fk_usps_optimizer_{name}`
  - Text Domain: `fk-usps-optimizer`
- **Security**:
  - Always sanitize input: `sanitize_text_field()`, `absint()`, `sanitize_key()`
  - Always escape output: `esc_html()`, `esc_attr()`, `esc_url()`
  - Check capabilities before admin operations: `current_user_can( 'manage_woocommerce' )`
  - Verify nonces for state-changing requests
  - Never log or expose API keys
- **Documentation**: All classes, methods, and functions must have PHPDoc blocks

## Testing

```bash
# Run full test suite (512 tests, 1076 assertions)
composer run test

# Run with configuration
vendor/bin/phpunit --configuration phpunit.xml.dist

# Run a single test file
vendor/bin/phpunit --configuration phpunit.xml.dist tests/Unit/ShippingMethodTest.php
```

## Linting

```bash
# PHP linting (WordPress Coding Standards)
composer run lint
```

## Security First

This plugin handles:
- ShipEngine and ShipStation API credentials (stored in WordPress options)
- Customer shipping addresses (transmitted to carrier APIs)
- Package dimensions and weights
- PirateShip CSV export with customer data

Always:
- Validate and sanitize all input
- Escape all output
- Check capabilities before admin operations
- Use nonces for state-changing requests
- Use `wp_remote_*` functions for HTTP requests
- Never log API keys or customer PII

## Context Files

When working on changes, load the relevant context files from `.context/`:
- `.context/conventions.md` — always load
- `.context/security-checklist.md` — always load
- `.context/testing.md` — load when writing tests

## License

GPLv3 or later — See LICENSE file
