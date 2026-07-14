# FK USPS Optimizer Security Checklist

> **GSD Context File** — Load this at the start of every AI development session.
> This checklist must be applied to **every code change** without exception.
> Last reviewed: July 2026.

---

## Pre-Implementation Security Review

Before writing any code, confirm:
- [ ] Authentication method identified (WordPress Nonce for admin, WooCommerce session for checkout)
- [ ] Required capabilities defined for each operation (`manage_woocommerce` for admin, none for checkout rates)
- [ ] Data flow mapped (what user input reaches the carrier API)
- [ ] API credentials location confirmed (ShipEngine API key, ShipStation API key/secret in WordPress options)
- [ ] Third-party data transmission identified (customer addresses, package dimensions → ShipEngine/ShipStation)

---

## Input Sanitization (Required for All User Input)

### Use the Right Function

| Input Type | Function |
|-----------|---------|
| General string | `sanitize_text_field()` |
| Multiline text | `sanitize_textarea_field()` |
| Integer | `absint()` or `intval()` |
| Float | `(float)` cast + range check |
| Email | `sanitize_email()` |
| URL (for storage) | `esc_url_raw()` |
| Slug/key | `sanitize_key()` |
| JSON string | `json_decode( $json, true )` then sanitize each value |
| SQL value | `$wpdb->prepare()` — never string-concatenate |

### Plugin-Specific Hotspots

| Data Point | Source | Sanitization |
|-----------|--------|-------------|
| Box dimensions (admin form) | `$_POST['boxes']` | `(float)` cast each dimension |
| Ship-to address (test pricing) | `$_POST['ship_to']` | `sanitize_text_field()` per field |
| Carrier API key | Settings form | `sanitize_text_field()` |
| Order IDs (export) | `$_GET['order_ids']` | `array_map( 'absint', ... )` |
| WooCommerce destination | `$package['destination']` | Already sanitized by WC — cast only |
| Boxes JSON | Settings textarea | Validate JSON, then sanitize each field |

### Common Mistakes to Avoid
- ❌ `$_POST['data']` without sanitization
- ❌ `json_decode( $_POST['boxes_json'] )` without sanitizing each box field
- ✅ `sanitize_text_field( wp_unslash( $_POST['api_key'] ) )`
- ✅ Parse JSON, then `(float)` cast dimensions, `sanitize_text_field()` cast strings

---

## Output Escaping (Required for All Output)

### Use the Right Function

| Output Context | Function |
|---------------|---------|
| Plain text in HTML | `esc_html()` |
| HTML attribute value | `esc_attr()` |
| URL in href/src/action | `esc_url()` |
| Textarea content | `esc_textarea()` |
| HTML content (trusted) | `wp_kses_post()` |
| Translation strings | `esc_html__()`, `esc_attr__()` |
| Price display | `wc_price()` with `wp_kses_post()` |

### Plugin-Specific Hotspots

| Output | Context | Escaping |
|--------|---------|---------|
| Box reference names | Settings table cells | `esc_attr()` in value, `esc_html()` in display |
| Shipping method label | Checkout | Already escaped by WooCommerce |
| Order meta box plan | Admin order page | `esc_html()` for text, `wp_kses_post()` for wc_price |
| Test pricing results | Admin page | `esc_html()` for text, `wp_kses_post()` for prices |
| PirateShip CSV | Admin export | `fputcsv()` — no HTML context |

---

## Capability Checks

Every privileged operation must check capability BEFORE execution:

| Operation | Required Capability |
|-----------|-------------------|
| View/change settings | `manage_woocommerce` |
| Run test pricing | `manage_woocommerce` |
| Export PirateShip CSV | `manage_woocommerce` |
| View order shipping plan | (inherent from `edit_shop_order` via metabox) |
| Checkout rate calculation | None — runs during WooCommerce checkout flow |

```php
// Admin page access:
if ( ! current_user_can( 'manage_woocommerce' ) ) {
    wp_die( esc_html__( 'You do not have permission to access this page.', 'fk-usps-optimizer' ) );
}

// AJAX handler:
if ( ! current_user_can( 'manage_woocommerce' ) ) {
    wp_send_json_error( array( 'message' => __( 'Permission denied.', 'fk-usps-optimizer' ) ) );
}
```

---

## Nonce Verification

All state-changing admin requests MUST verify a nonce:

```php
// In admin-post handlers:
check_admin_referer( 'fk_usps_optimizer_export_csv' );

// In AJAX handlers:
check_ajax_referer( 'fk_usps_test_connection', 'nonce' );

// In form submissions:
if ( ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'fk_usps_test_pricing' ) ) {
    $errors[] = __( 'Security check failed.', 'fk-usps-optimizer' );
}
```

---

## API Key & Credential Storage

- ✅ Store ShipEngine API key, ShipStation API key/secret in a single WordPress option (`fk_usps_optimizer_settings`)
- ✅ Send credentials via HTTPS-only API endpoints
- ✅ Use HTTP Basic Auth for ShipStation (`base64_encode( $key . ':' . $secret )`)
- ✅ Use API-Key header for ShipEngine
- ❌ Never log full API keys (not even partial keys in error messages)
- ❌ Never expose API keys in REST API responses or JS globals
- ❌ Never commit credentials or `.env` files to source control

---

## External HTTP Requests (Carrier APIs)

```php
// ShipEngine (API-Key header):
$response = wp_remote_post(
    'https://api.shipengine.com/v1/rates',
    array(
        'timeout' => $timeout,
        'headers' => array(
            'API-Key'      => $api_key,
            'Content-Type' => 'application/json',
        ),
        'body' => wp_json_encode( $payload ),
    )
);

// ShipStation (Basic Auth):
$auth = base64_encode( $api_key . ':' . $api_secret );
$response = wp_remote_get(
    $endpoint,
    array(
        'timeout' => $timeout,
        'headers' => array(
            'Authorization' => 'Basic ' . $auth,
            'Content-Type'  => 'application/json',
        ),
    )
);
```

- ✅ Always use `wp_remote_*` functions — never `curl_*` directly
- ✅ Check `is_wp_error( $response )` before using the result
- ✅ Check HTTP status code (`wp_remote_retrieve_response_code()`)
- ✅ Use configurable timeouts via `fk_usps_optimizer_api_timeout` filter (default 8s)
- ✅ Cache rate responses via transients to reduce API calls

---

## Rate Response Caching

- Rate responses are cached in WordPress transients to reduce carrier API calls
- Cache key is an MD5 hash of the rate-shaping inputs (carrier, service, dimensions, weight, destination)
- Default TTL: 5 minutes (filterable via `fk_usps_optimizer_rate_cache_ttl`)
- Sandbox mode bypasses the cache (always fetches live)
- Cache is per-site (uses `set_transient` / `get_transient`)

---

## ABSPATH Guard (Every Non-Root PHP File)

```php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
```

---

## Per-Commit Security Checklist

Before every commit, verify:
- [ ] All user input sanitized with appropriate function
- [ ] All output escaped with appropriate function
- [ ] Capability check present before every privileged operation
- [ ] Nonce verified for every state-changing request
- [ ] No API keys or credentials in source code
- [ ] ABSPATH guard on every new PHP file (except root plugin file)
- [ ] External HTTP requests use `wp_remote_*` functions
- [ ] Carrier API responses validated before use (check HTTP status, parse JSON, verify structure)
- [ ] No sensitive customer data logged in debug mode
