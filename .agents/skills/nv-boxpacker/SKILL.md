---
name: nv-boxpacker
description: Development playbook for the FK USPS Optimizer plugin (this repo) — the two-pass rate pipeline (checkout Shipping_Method vs order-plan Plugin::process_order), carrier service-code fidelity invariants (label must match the priced rate, content-derived rate IDs, chosen-service-constrained plans, allow-list filtering, CSV carrier/service derivation), ShipEngine /v1/rates and ShipStation getrates wire shapes, and the PHPUnit test seams. Use when working on rate calculation, checkout options, order plans, carrier services, PirateShip export, or debugging "label/price mismatch" and "mixed carrier" bugs in this repository.
---

# FK USPS Optimizer — repo playbook

Operational knowledge for developing the **FunnelKit USPS Priority Shipping Optimizer** in this repository. Load alongside `.context/conventions.md` + `.context/security-checklist.md` (always) and `.context/testing.md` (when writing tests). The sibling `wp-plugin-*` / `wp-security-*` skills in this repo cover generic WordPress rules; this skill covers what is specific to *this* plugin's rate engine.

## When to use this skill

- Any change to `includes/class-shipping-method.php`, `class-plugin.php` (process_order), `class-shipengine-service.php`, `class-shipstation-service.php`, or `class-pirateship-export.php`.
- Any bug report about checkout showing one service but charging another, plans splitting an order across carriers, or CSV/order-note services disagreeing with what the customer paid.
- Adding a carrier, a service code, or a service-label mapping.

## The rate pipeline — two independent passes

The plugin runs rate-shopping **twice per order**, and the two passes must stay consistent:

1. **Checkout** — `Shipping_Method::calculate_shipping()` packs the cart, rates per carrier service, and offers options. Whatever the customer picks becomes the order's shipping line item (label + cost) and is what they **paid**.
2. **Order plan** — `Plugin::process_order()` (hook `woocommerce_checkout_order_processed`) re-packs the order, re-rates, and stores the cheapest-per-package plan in order meta `_fk_usps_optimizer_plan`. This is what the metabox, order note, and PirateShip CSV show at label time.

The plan is a fresh estimate, not a copy of the checkout selection. `process_order()` must therefore **constrain itself to the service the customer selected** and warn when it cannot.

## Fidelity invariants — do not regress

These rules exist because of real production bugs (orders showing "USPS Priority" priced at Media Mail, plans mixing UPS and USPS, "UPS 2nd Day Air" at Ground price). Any change that breaks one of these needs a deliberate exception, not an accident:

1. **A plan's `service_code` comes from the API rate, never from settings.** ShipEngine responses carry `service_code` per rate; `parse_rate_response()` picks the cheapest rate and the plan must label *that* rate's code (`$rate['service_code'] ?? $requested_code`). ShipStation equivalents live in `build_plan_from_rate()`. A rate with no confirmed code gets an **empty** service label — callers skip it rather than advertising an unknown service.
2. **Rate IDs are content-derived.** `Shipping_Method::build_rate_id()` = `get_rate_id( sanitize_key(service_code) . '-' . md5(label|cost)[0:8] )`. Never reintroduce positional `:0/:1/:2` IDs — a re-sorted rate set silently remaps the customer's selection to a different service.
3. **Checkout rates persist their identity as meta.** `calculate_shipping()` writes `Plugin::META_SERVICE_CODE` / `META_SERVICE_LABEL` / `META_CARRIER_CODE` into each rate's `meta_data`. WooCommerce copies rate meta onto the order's shipping item, and `Plugin::get_chosen_shipping_info()` reads it back in `process_order()`.
4. **`process_order()` honors the chosen service.** `find_best_plan()` only rate-shops carrier services whose `supports_service_code()` accepts the chosen code, and requests that exact service. Fallback to cheapest-with-warning only when the chosen service cannot be rated. Mixed-carrier plans (UPS package 1 + USPS package 2) must never be silently produced.
5. **ShipStation filters by requested service and allow-list.** `request_rate()` and the specific-code branch of `build_all_plans_for_address()` run `filter_rates_by_service()` — never blind `$rates[0]` when a service was requested. `apply_allow_list_filter()` / `expand_allow_list()` handle multi-service carrier instances (empty service_code + allow-list).
6. **Never fall back to the generic method title.** `resolve_rate_title()` returns '' when a rate group has no usable label and the option is skipped. "USPS Priority Optimizer" must never appear on a priced option.
7. **PirateShip CSV derives carrier/service from the plan.** `PirateShip_Export::derive_carrier_and_service()` maps `service_code` → carrier/service (label fallback, legacy USPS Priority default). Never hardcode `USPS` / `Priority Mail`.
8. **Divergence warnings.** `process_order()` warns when the plan total ≠ `$order->get_shipping_total()` (only for orders shipped via this plugin), and when the chosen service can't be determined or rated.

## Carrier specifics

### ShipEngine (`class-shipengine-service.php`)

- Endpoint `POST https://api.shipengine.com/v1/rates`; auth header `API-Key`.
- Payload: `rate_options.carrier_ids`, `shipment.{validate_address,ship_to,ship_from,packages[{package_code,weight,dimensions}],service_code}`.
- Response: `rate_response.rates[]` with `service_code` + `shipping_amount.{amount,currency}` per rate; multiple services possible when `service_code` is empty.
- USPS-only by construction: `build_candidates()` uses `get_boxes_for_carrier('usps')` and plans carry `carrier_code => 'usps'`.
- Cache: transient `fk_usps_opt_se_<md5>` keyed on carrier ids, service code, package code, ZIPs, dims, weight. Sandbox bypasses cache.

### ShipStation (`class-shipstation-service.php`)

- Endpoint `POST {base}/shipments/getrates`; basic auth.
- Payload: `carrierCode, serviceCode, packageCode, fromPostalCode, to*, weight, dimensions, confirmation, residential`.
- Response: bare array of `{serviceCode, shipmentCost, otherCost}` — `serviceCode` may be absent for some carriers; fallback to the requested code, but only when a specific service was requested.
- Carrier codes: `stamps_com`/`usps`/`endicia` → usps; `ups_walleted`/`ups` → ups; `fedex`; `dhl_express` (`get_carrier_keyword()`).
- Multi-service pairs are grouped per carrier in `Plugin::get_carrier_services()` — wildcard request + allow-list filtering.
- Cache: transient `fk_usps_opt_ss_<md5>`.

### Shared

- `get_intl_service_code_map()` (both services) maps domestic → international codes (US/PR pass through). Filterable via `fk_usps_optimizer_intl_service_code_map`. Keep `supports_service_code()` and the resolver using the same map.
- Service-label maps live in `get_service_label()` (ShipEngine is USPS-only; ShipStation covers UPS/FedEx/DHL). Add new codes to both the label map and the PirateShip `get_carrier_service_map()`.

## Key order meta

| Key | Where | Purpose |
|-----|-------|---------|
| `_fk_usps_optimizer_plan` | `Order_Plan_Service::META_KEY` | Stored plan (packages, totals, pirateship_rows, warnings) |
| `_fk_packing_plan_note` | `Plugin::PACKING_NOTE_META_KEY` | Rendered plan text injected into REST `customer_note` |
| `_fk_usps_optimizer_service_code` (+ `_label`, `_carrier_code`) | `Plugin::META_*`, written as shipping-item meta by WC from rate meta | The checkout-selected service, read by `process_order()` |

## Testing

- Stub HTTP via `$GLOBALS['_test_wp_remote_post']` (array or callable receiving `$url, $args` — capture payloads with callables). Parallel dispatch seam: `$GLOBALS['_test_wp_requests_multiple']`.
- All WP stubs live in `tests/bootstrap.php` — add stubs there when production code touches a new WC method (`get_shipping_methods`, `get_shipping_total`, `WC_Order_Item_Shipping` were added for the chosen-service capture).
- Reflection helper pattern: `call_protected()` / `call_protected_on()`.
- Commands: `composer run test` (currently 562 tests / 1187 assertions), `composer run lint`, `vendor/bin/phpcs` (scope: main file + `includes/` only — tests are excluded).
- **Live API tests** (`tests/Integration/`, `composer run test-live`): hit the real ShipEngine sandbox / ShipStation APIs; skip automatically without credentials. Credentials live in the git-ignored `phpunit.live.local.xml` (copy `phpunit.live.local.xml.example`) or env vars (`FK_USPS_OPTIMIZER_TEST_*`) — never in the repo. Live tests set `$GLOBALS['_test_wp_remote_live'] = true`, which routes the bootstrap's `wp_remote_*` stubs through `live_http_request()` (cURL). On machines where PHP cURL has no system CA bundle, set `FK_USPS_OPTIMIZER_TEST_CA_BUNDLE` (e.g. Git for Windows' `C:\Program Files\Git\usr\ssl\cert.pem`).
- On Windows the working tree is CRLF; phpcs flags EOL errors on files you did not touch — ignore those, keep *your* files clean (`vendor/bin/phpcbf <file>` normalizes).

## Gotchas

- Changing a public method signature on the services (`build_package_plan`, `request_rate`) — check `Plugin`, `Test_Pricing_Service`, and `Admin_Test_UI` call sites; keep new params optional with defaults.
- The metabox title still says "USPS Priority Shipping Plan" while plans can include UPS — renaming it requires updating `Admin_UI`, `AdminUiTest`, and note text assertions (`PluginProcessOrderTest` asserts 'USPS Shipping Plan').
- Version bump touches: plugin header + `FK_USPS_OPTIMIZER_VERSION` + `readme.txt` Stable tag (+ `composer.json` if present). Build the dist ZIP with `bin/build.sh`.
- Never log API keys or customer PII; credentials live in the `fk_usps_optimizer_settings` option only.
