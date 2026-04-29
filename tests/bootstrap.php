<?php
/**
 * Test bootstrap for FK USPS Optimizer plugin tests.
 *
 * Defines all WordPress / WooCommerce function and class stubs required to
 * run the plugin's unit tests without a full WordPress installation.
 *
 * @package FK_USPS_Optimizer
 */

// ---------------------------------------------------------------------------
// Plugin constants (mimic a real WordPress environment).
// ---------------------------------------------------------------------------
define( 'ABSPATH', '/' );
define( 'FK_USPS_OPTIMIZER_VERSION', '1.0.0' );
define( 'FK_USPS_OPTIMIZER_PATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );
define( 'FK_USPS_OPTIMIZER_URL', 'http://example.com/wp-content/plugins/fk-usps-optimizer/' );
define( 'FK_USPS_OPTIMIZER_FILE', FK_USPS_OPTIMIZER_PATH . 'woocommerce-fk-usps-optimizer.php' );
define( 'MINUTE_IN_SECONDS', 60 );

// ---------------------------------------------------------------------------
// Composer autoloader (provides DVDoug\BoxPacker and PHPUnit).
// ---------------------------------------------------------------------------
require_once FK_USPS_OPTIMIZER_PATH . 'vendor/autoload.php';

// ---------------------------------------------------------------------------
// Shared global state used by the stubs below.
// Tests reset these in setUp() as needed.
// ---------------------------------------------------------------------------
$GLOBALS['_test_wp_options']       = array();
$GLOBALS['_test_wp_remote_post']   = null;   // null = default 200/{} response.
$GLOBALS['_test_wp_remote_get']    = null;   // null = default 200/{} response.
$GLOBALS['_test_wp_filters']       = array();
$GLOBALS['_test_settings_errors']  = array();
$GLOBALS['_test_current_user_can'] = true;
$GLOBALS['_test_wc_logger']        = null;
$GLOBALS['_test_wp_safe_redirect'] = null;
$GLOBALS['_test_wp_transients']    = array();
$GLOBALS['_test_wp_json_response'] = null;

// ---------------------------------------------------------------------------
// WordPress core stubs
// ---------------------------------------------------------------------------

/**
 * Merge user-defined arguments into defaults array.
 *
 * @param mixed $args     Value to merge with defaults.
 * @param mixed $defaults Default values.
 * @return array Merged array.
 */
function wp_parse_args( $args, $defaults = '' ): array {
	if ( is_object( $args ) ) {
		$args = get_object_vars( $args );
	} elseif ( ! is_array( $args ) ) {
		parse_str( (string) $args, $args );
	}
	if ( ! is_array( $defaults ) ) {
		$defaults = array();
	}
	return array_merge( $defaults, $args );
}

/**
 * Retrieve a WordPress option.
 *
 * @param string $option  Option name.
 * @param mixed  $default Default value.
 * @return mixed Option value or default.
 */
function get_option( string $option, $default = false ) {
	return array_key_exists( $option, $GLOBALS['_test_wp_options'] )
		? $GLOBALS['_test_wp_options'][ $option ]
		: $default;
}

/**
 * Update a WordPress option (stores in global state).
 *
 * @param string $option   Option name.
 * @param mixed  $value    Option value.
 * @param mixed  $autoload Autoload hint (ignored).
 * @return bool Always true.
 */
function update_option( string $option, $value, $autoload = null ): bool {
	$GLOBALS['_test_wp_options'][ $option ] = $value;
	return true;
}

/**
 * Apply filters to a value.
 *
 * @param string $tag        Filter tag.
 * @param mixed  $value      Value to filter.
 * @param mixed  ...$extra   Extra arguments passed to callbacks.
 * @return mixed Filtered value.
 */
function apply_filters( string $tag, $value, ...$extra ) {
	if ( ! empty( $GLOBALS['_test_wp_filters'][ $tag ] ) ) {
		foreach ( $GLOBALS['_test_wp_filters'][ $tag ] as $cb ) {
			$value = $cb( $value, ...$extra );
		}
	}
	return $value;
}

/**
 * Add a filter callback.
 *
 * @param string   $tag           Filter tag.
 * @param callable $callback      Callback.
 * @param int      $priority      Priority (ignored).
 * @param int      $accepted_args Accepted argument count (ignored).
 * @return bool Always true.
 */
function add_filter( string $tag, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	$GLOBALS['_test_wp_filters'][ $tag ][] = $callback;
	return true;
}

/**
 * Register an action hook (no-op in tests).
 *
 * @param string $tag           Action tag.
 * @param mixed  $callback      Callback.
 * @param int    $priority      Priority (ignored).
 * @param int    $accepted_args Accepted argument count (ignored).
 * @return bool Always true.
 */
function add_action( string $tag, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	return true;
}

/** No-op do_action stub. */
function do_action( string $tag, ...$args ): void {}

/**
 * JSON-encode a value (thin wrapper around json_encode).
 *
 * @param mixed $data    Value to encode.
 * @param int   $options JSON options bitmask.
 * @param int   $depth   Maximum depth.
 * @return string|false JSON string or false on failure.
 */
function wp_json_encode( $data, int $options = 0, int $depth = 512 ) {
	return json_encode( $data, $options, $depth );
}

/**
 * Strip slashes from a value.
 *
 * @param mixed $value Value to unslash.
 * @return mixed Unslashed value.
 */
function wp_unslash( $value ) {
	return is_array( $value )
		? array_map( 'wp_unslash', $value )
		: stripslashes( (string) $value );
}

/**
 * Sanitize a text field by stripping tags and trimming.
 *
 * @param string $str Input string.
 * @return string Sanitized string.
 */
function sanitize_text_field( string $str ): string {
	return trim( strip_tags( $str ) );
}

/**
 * Validate an email address (test stub mirroring WP behaviour for typical inputs).
 *
 * @param string $email Email to validate.
 * @return string|false The valid email when it passes filter_var, otherwise false.
 */
function is_email( string $email ) {
	$email = trim( $email );
	if ( '' === $email ) {
		return false;
	}
	return false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ? false : $email;
}

/**
 * Send an email (test stub).
 *
 * Records each call in $GLOBALS['_test_wp_mail'] and returns the value of
 * $GLOBALS['_test_wp_mail_return'] (defaults to true) so tests can assert
 * the arguments passed and simulate failures.
 *
 * @param string|string[] $to          Recipient(s).
 * @param string          $subject     Subject line.
 * @param string          $message     Body.
 * @param string|string[] $headers     Headers.
 * @param string|string[] $attachments Attachments.
 * @return bool
 */
function wp_mail( $to, string $subject, string $message, $headers = '', $attachments = array() ): bool {
	if ( ! isset( $GLOBALS['_test_wp_mail'] ) || ! is_array( $GLOBALS['_test_wp_mail'] ) ) {
		$GLOBALS['_test_wp_mail'] = array();
	}
	$GLOBALS['_test_wp_mail'][] = array(
		'to'          => $to,
		'subject'     => $subject,
		'message'     => $message,
		'headers'     => $headers,
		'attachments' => $attachments,
	);
	return array_key_exists( '_test_wp_mail_return', $GLOBALS ) ? (bool) $GLOBALS['_test_wp_mail_return'] : true;
}

/**
 * Generate a unique temp file name (test stub).
 *
 * @param string $filename Suggested filename.
 * @param string $dir      Optional directory.
 * @return string|false Path to the created temp file.
 */
function wp_tempnam( string $filename = '', string $dir = '' ) {
	if ( '' === $dir ) {
		$dir = sys_get_temp_dir();
	}
	return tempnam( $dir, 'fk_test_' );
}

/**
 * Get information about the site (test stub).
 *
 * @param string $show Field to fetch.
 * @return string
 */
function get_bloginfo( string $show = '' ): string {
	return $GLOBALS['_test_bloginfo'][ $show ] ?? 'Test Site';
}

/**
 * Convert a value to a non-negative integer.
 *
 * @param mixed $maybeint Value to convert.
 * @return int Non-negative integer.
 */
function absint( $maybeint ): int {
	return abs( (int) $maybeint );
}

/**
 * Escape an HTML attribute value.
 *
 * @param mixed $text Input value.
 * @return string Escaped string.
 */
function esc_attr( $text ): string {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

/**
 * Escape HTML for display.
 *
 * @param mixed $text Input value.
 * @return string Escaped string.
 */
function esc_html( $text ): string {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

/**
 * Translate and escape HTML.
 *
 * @param string $text   Text to translate and escape.
 * @param string $domain Text domain (ignored).
 * @return string Escaped text.
 */
function esc_html__( string $text, string $domain = 'default' ): string {
	return $text;
}

/** Echo an escaped translated string. */
function esc_html_e( string $text, string $domain = 'default' ): void {
	echo esc_html( $text );
}

/**
 * Translate a string.
 *
 * @param string $text   Text to translate.
 * @param string $domain Text domain (ignored).
 * @return string Translated text (pass-through in tests).
 */
function __( string $text, string $domain = 'default' ): string {
	return $text;
}

/**
 * Retrieve the translation of $text (singular or plural) based on $number.
 *
 * @param string $single Singular text.
 * @param string $plural Plural text.
 * @param int    $number Number to determine singular/plural.
 * @param string $domain Text domain.
 * @return string Singular or plural text.
 */
function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
	return 1 === $number ? $single : $plural;
}

/** Echo a translated string. */
function _e( string $text, string $domain = 'default' ): void {
	echo $text;
}

/**
 * Escape text for use in a textarea.
 *
 * @param mixed $text Input value.
 * @return string Escaped string.
 */
function esc_textarea( $text ): string {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

/**
 * Escape a URL.
 *
 * @param string $url URL to escape.
 * @return string Escaped URL (pass-through in tests).
 */
function esc_url( string $url ): string {
	return $url;
}

/**
 * Pass through post-content HTML (no filtering in tests).
 *
 * @param mixed $data HTML content.
 * @return string Content as string.
 */
function wp_kses_post( $data ): string {
	return (string) $data;
}

/**
 * Render a checked attribute for a checkbox.
 *
 * @param mixed $checked  Value to compare.
 * @param mixed $current  Current value (default true).
 * @param bool  $echo     Whether to echo the result.
 * @return string The checked attribute string or empty.
 */
function checked( $checked, $current = true, bool $echo = true ): string {
	$result = (string) $checked === (string) $current ? ' checked="checked"' : '';
	if ( $echo ) {
		echo $result; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	return $result;
}

/**
 * Return the current time in a given format.
 *
 * @param string $type 'mysql', 'timestamp', etc.
 * @param int    $gmt  Use GMT (ignored in tests).
 * @return string Fixed test timestamp.
 */
function current_time( string $type, int $gmt = 0 ): string {
	return '2024-01-01 00:00:00';
}

// ---------------------------------------------------------------------------
// WordPress nonce / AJAX stubs
// ---------------------------------------------------------------------------

/**
 * Create a nonce token (always returns a fixed value in tests).
 *
 * @param int|string $action Nonce action (ignored).
 * @return string Fixed test nonce.
 */
function wp_create_nonce( $action = -1 ): string {
	return 'test_nonce';
}

/**
 * Enqueue a stylesheet (no-op in tests).
 *
 * @param mixed ...$args Ignored.
 */
function wp_enqueue_style( ...$args ): void {}

/**
 * Enqueue a script (no-op in tests).
 *
 * @param mixed ...$args Ignored.
 */
function wp_enqueue_script( ...$args ): void {}

/**
 * Localise a script with data (no-op in tests; returns true).
 *
 * @param mixed ...$args Ignored.
 * @return bool Always true.
 */
function wp_localize_script( ...$args ): bool {
	return true;
}

/**
 * Verify an AJAX nonce (always passes in tests).
 *
 * @param int|string $action    Nonce action (ignored).
 * @param string     $query_arg Query parameter name (ignored).
 * @param bool       $stop      Whether to stop execution on failure (ignored).
 * @return int Always 1.
 */
function check_ajax_referer( $action = -1, $query_arg = false, bool $stop = true ): int {
	return 1;
}

/**
 * Send a JSON success response (stores result in global state for tests).
 *
 * @param mixed $data        Response data.
 * @param int   $status_code HTTP status code (ignored).
 * @param int   $options     JSON encode options (ignored).
 */
function wp_send_json_success( $data = null, int $status_code = 200, int $options = 0 ): void {
	$GLOBALS['_test_wp_json_response'] = array( 'success' => true, 'data' => $data );
}

/**
 * Send a JSON error response (stores result in global state for tests).
 *
 * @param mixed $data        Response data.
 * @param int   $status_code HTTP status code (ignored).
 * @param int   $options     JSON encode options (ignored).
 */
function wp_send_json_error( $data = null, int $status_code = 0, int $options = 0 ): void {
	$GLOBALS['_test_wp_json_response'] = array( 'success' => false, 'data' => $data );
}

// ---------------------------------------------------------------------------
// WordPress Settings API stubs (all no-ops)
// ---------------------------------------------------------------------------

/** @param mixed ...$args Ignored. */
function register_setting( ...$args ): void {}

/** @param mixed ...$args Ignored. */
function add_settings_section( ...$args ): void {}

/** @param mixed ...$args Ignored. */
function add_settings_field( ...$args ): void {}

/** @param mixed ...$args Ignored. */
function settings_fields( ...$args ): void {}

/** @param mixed ...$args Ignored. */
function do_settings_sections( ...$args ): void {}

/** Render a generic submit button. */
function submit_button( ...$args ): void {
	echo '<input type="submit" class="button button-primary" />';
}

/**
 * Add a settings error.
 *
 * @param string $setting Setting name.
 * @param string $code    Error code.
 * @param string $message Error message.
 * @param string $type    Error type.
 */
function add_settings_error( string $setting, string $code, string $message, string $type = 'error' ): void {
	$GLOBALS['_test_settings_errors'][] = compact( 'setting', 'code', 'message', 'type' );
}

/**
 * Add a submenu page (no-op).
 *
 * @param mixed ...$args Ignored.
 * @return string Placeholder hook suffix.
 */
function add_submenu_page( ...$args ): string {
	return 'hook_suffix';
}

// ---------------------------------------------------------------------------
// WordPress Admin stubs
// ---------------------------------------------------------------------------

/** @param mixed ...$args Ignored. */
function add_meta_box( ...$args ): void {}

/**
 * Check if the current user has a capability.
 *
 * @param string $capability Capability to check.
 * @param mixed  ...$args    Extra arguments (ignored).
 * @return bool Value from global state.
 */
function current_user_can( string $capability, ...$args ): bool {
	return (bool) ( $GLOBALS['_test_current_user_can'] ?? true );
}

/**
 * Append a nonce to a URL.
 *
 * @param string $actionurl Base URL.
 * @param mixed  $action    Nonce action (ignored).
 * @param string $name      Query parameter name (ignored).
 * @return string URL with test nonce appended.
 */
function wp_nonce_url( string $actionurl, $action = -1, string $name = '_wpnonce' ): string {
	return $actionurl . '&_wpnonce=test';
}

/**
 * Build an admin URL.
 *
 * @param string $path   Path relative to wp-admin.
 * @param string $scheme URL scheme (ignored).
 * @return string Full admin URL.
 */
function admin_url( string $path = '', string $scheme = 'admin' ): string {
	return 'http://example.com/wp-admin/' . ltrim( $path, '/' );
}

/**
 * Kill execution with an error message (throws RuntimeException in tests).
 *
 * @param mixed $message Error message.
 * @param mixed $title   Error title (ignored).
 * @param mixed $args    Extra arguments (ignored).
 * @throws \RuntimeException Always.
 */
function wp_die( $message = '', $title = '', $args = array() ): void {
	throw new \RuntimeException( is_string( $message ) ? $message : 'wp_die called' );
}

/**
 * Verify a nonce (always passes in tests).
 *
 * @param mixed  $action    Nonce action (ignored).
 * @param string $query_arg Query parameter name (ignored).
 * @return int Always 1.
 */
function check_admin_referer( $action = -1, string $query_arg = '_wpnonce' ): int {
	return 1;
}

/**
 * Verify a nonce value (always passes in tests).
 *
 * @param mixed $nonce  Nonce value (ignored).
 * @param mixed $action Nonce action (ignored).
 * @return int|false Always returns 1 in tests.
 */
function wp_verify_nonce( $nonce, $action = -1 ) {
	return 1;
}

/**
 * Output or return a nonce hidden input field.
 *
 * @param mixed  $action  Nonce action (ignored).
 * @param string $name    Field name.
 * @param bool   $referer Whether to include referer field (ignored).
 * @param bool   $echo    Whether to echo the field.
 * @return string HTML nonce field.
 */
function wp_nonce_field( $action = -1, string $name = '_wpnonce', bool $referer = true, bool $echo = true ): string {
	$html = '<input type="hidden" name="' . esc_attr( $name ) . '" value="test_nonce" />';
	if ( $echo ) {
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped above.
	}
	return $html;
}

/**
 * Sanitize a string key (lowercase alphanumeric, underscores, hyphens only).
 *
 * @param string $key Raw key.
 * @return string Sanitized key.
 */
function sanitize_key( string $key ): string {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $key ) );
}

/**
 * Append a trailing slash to a string.
 *
 * @param string $string Input string.
 * @return string String with trailing slash.
 */
function trailingslashit( string $string ): string {
	return rtrim( $string, '/\\' ) . '/';
}

/**
 * Escape a string for use in JavaScript.
 *
 * Uses json_encode() with JSON_UNESCAPED_UNICODE and JSON_HEX_TAG so that
 * the result is safe for inline JavaScript contexts (matches WordPress core).
 * The surrounding double-quotes from json_encode() are stripped since
 * esc_js() only returns the escaped content, not a quoted JS literal.
 *
 * @param string $text Text to escape.
 * @return string Escaped text.
 */
function esc_js( string $text ): string {
	$encoded = json_encode( $text, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
	// json_encode wraps strings in double-quotes; strip them.
	return substr( (string) $encoded, 1, -1 );
}

/**
 * Output a checked/selected HTML attribute for a select element.
 *
 * @param mixed  $selected One of the values to compare.
 * @param mixed  $current  The value to compare against.
 * @param bool   $echo     Whether to echo the attribute.
 * @return string Attribute string or empty string.
 */
function selected( $selected, $current = true, bool $echo = true ): string {
	$result = ( (string) $selected === (string) $current ) ? ' selected="selected"' : '';
	if ( $echo ) {
		echo $result; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attribute string.
	}
	return $result;
}

// ---------------------------------------------------------------------------
// WooCommerce stubs
// ---------------------------------------------------------------------------

/**
 * Get the screen ID for a WooCommerce page.
 *
 * @param string $name Page name.
 * @return string Screen ID.
 */
function wc_get_page_screen_id( string $name ): string {
	return 'woocommerce_page_wc-orders';
}

/**
 * Format a price with currency symbol.
 *
 * @param mixed $price Price to format.
 * @param array $args  Optional arguments (currency ignored).
 * @return string Formatted price string.
 */
function wc_price( $price, array $args = array() ): string {
	return '$' . number_format( (float) $price, 2 );
}

/**
 * Convert a dimension to a given unit.
 *
 * For tests, dimensions are assumed to already be in the target unit.
 *
 * @param mixed  $dimension  Dimension value.
 * @param string $to_unit    Target unit (ignored).
 * @param string $from_unit  Source unit (ignored).
 * @return float Dimension as float.
 */
function wc_get_dimension( $dimension, string $to_unit, string $from_unit = '' ): float {
	return (float) $dimension;
}

/**
 * Convert a weight to a given unit.
 *
 * For tests, weights are assumed to already be in the target unit.
 *
 * @param mixed  $weight    Weight value.
 * @param string $to_unit   Target unit (ignored).
 * @param string $from_unit Source unit (ignored).
 * @return float Weight as float.
 */
function wc_get_weight( $weight, string $to_unit, string $from_unit = '' ): float {
	return (float) $weight;
}

/**
 * Retrieve a WooCommerce order by ID.
 *
 * @param mixed $order_id Order ID.
 * @return \WC_Order|false Order object or false if not found.
 */
function wc_get_order( $order_id ) {
	return $GLOBALS['_test_wc_orders'][ (int) $order_id ] ?? false;
}

/**
 * Retrieve the WooCommerce logger instance.
 *
 * @return WC_Test_Logger Logger stub.
 */
function wc_get_logger(): WC_Test_Logger {
	if ( null === $GLOBALS['_test_wc_logger'] ) {
		$GLOBALS['_test_wc_logger'] = new WC_Test_Logger();
	}
	return $GLOBALS['_test_wc_logger'];
}

// ---------------------------------------------------------------------------
// WordPress HTTP stubs
// ---------------------------------------------------------------------------

/**
 * Perform a remote GET request.
 *
 * Returns the value stored in $GLOBALS['_test_wp_remote_get'].
 * If that value is callable, it is called with ($url, $args).
 * If null, a default 200-OK / empty-body response is returned.
 *
 * @param string $url  URL to GET.
 * @param array  $args Request arguments.
 * @return array|\WP_Error Response array or WP_Error.
 */
function wp_remote_get( string $url, array $args = array() ) {
	$stub = $GLOBALS['_test_wp_remote_get'] ?? null;
	if ( null === $stub ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
	}
	if ( is_callable( $stub ) ) {
		return $stub( $url, $args );
	}
	return $stub;
}

/**
 * Redirect to a URL safely (in tests, just stores the URL in global state).
 *
 * @param string $location URL to redirect to.
 * @param int    $status   HTTP status code (ignored in tests).
 * @param string $x_redirect_by Optional X-Redirect-By header value (ignored).
 * @return bool Always true.
 */
function wp_safe_redirect( string $location, int $status = 302, string $x_redirect_by = 'WordPress' ): bool {
	$GLOBALS['_test_wp_safe_redirect'] = $location;
	return true;
}

/**
 * Append query arguments to a URL.
 *
 * @param array  $args Query arguments to add.
 * @param string $url  Base URL.
 * @return string URL with query arguments appended.
 */
function add_query_arg( array $args, string $url = '' ): string {
	$query = http_build_query( $args );
	return $url . ( strpos( $url, '?' ) !== false ? '&' : '?' ) . $query;
}

/**
 * Get a transient value.
 *
 * @param string $transient Transient name.
 * @return mixed Value or false if not set.
 */
function get_transient( string $transient ) {
	return $GLOBALS['_test_wp_transients'][ $transient ] ?? false;
}

/**
 * Set a transient value.
 *
 * @param string $transient  Transient name.
 * @param mixed  $value      Value to store.
 * @param int    $expiration Expiration in seconds (ignored in tests).
 * @return bool Always true.
 */
function set_transient( string $transient, $value, int $expiration = 0 ): bool {
	$GLOBALS['_test_wp_transients'][ $transient ] = $value;
	return true;
}

/**
 * Delete a transient.
 *
 * @param string $transient Transient name.
 * @return bool Always true.
 */
function delete_transient( string $transient ): bool {
	unset( $GLOBALS['_test_wp_transients'][ $transient ] );
	return true;
}


/**
 * Perform a remote POST request.
 *
 * Returns the value stored in $GLOBALS['_test_wp_remote_post'].
 * If that value is callable, it is called with ($url, $args).
 * If null, a default 200-OK / empty-body response is returned.
 *
 * @param string $url  URL to POST to.
 * @param array  $args Request arguments.
 * @return array|\WP_Error Response array or WP_Error.
 */
function wp_remote_post( string $url, array $args = array() ) {
	$stub = $GLOBALS['_test_wp_remote_post'];
	if ( null === $stub ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
	}
	if ( is_callable( $stub ) ) {
		return $stub( $url, $args );
	}
	return $stub;
}

/**
 * Retrieve the HTTP status code from a remote response.
 *
 * @param array|\WP_Error $response Response array.
 * @return int HTTP status code, or 0 on error.
 */
function wp_remote_retrieve_response_code( $response ): int {
	if ( $response instanceof \WP_Error ) {
		return 0;
	}
	return (int) ( $response['response']['code'] ?? 0 );
}

/**
 * Retrieve the body from a remote response.
 *
 * @param array|\WP_Error $response Response array.
 * @return string Response body string.
 */
function wp_remote_retrieve_body( $response ): string {
	if ( $response instanceof \WP_Error ) {
		return '';
	}
	return (string) ( $response['body'] ?? '' );
}

/**
 * Check whether a value is a WP_Error object.
 *
 * @param mixed $thing Value to check.
 * @return bool True if it is a WP_Error instance.
 */
function is_wp_error( $thing ): bool {
	return $thing instanceof \WP_Error;
}

// ---------------------------------------------------------------------------
// WordPress / WooCommerce class stubs
// ---------------------------------------------------------------------------

/**
 * WordPress error class stub.
 */
class WP_Error {

	/** @var array<string, string[]> */
	protected array $errors = array();

	/** @var array<string, mixed> */
	protected array $error_data = array();

	/**
	 * Constructor.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @param mixed  $data    Additional data.
	 */
	public function __construct( $code = '', string $message = '', $data = '' ) {
		if ( $code ) {
			$this->errors[ (string) $code ][]  = $message;
			$this->error_data[ (string) $code ] = $data;
		}
	}

	/**
	 * Retrieve an error message.
	 *
	 * @param string $code Error code (uses first code when empty).
	 * @return string Error message.
	 */
	public function get_error_message( $code = '' ): string {
		if ( '' === (string) $code ) {
			$code = (string) array_key_first( $this->errors );
		}
		return (string) ( $this->errors[ (string) $code ][0] ?? '' );
	}

	/**
	 * Retrieve the first error code.
	 *
	 * @return string First error code.
	 */
	public function get_error_code(): string {
		return (string) ( array_key_first( $this->errors ) ?? '' );
	}
}

/**
 * WooCommerce logger stub used by tests to capture debug messages.
 */
class WC_Test_Logger {

	/** @var array<int, array{message: string, context: array}> */
	public array $logs = array();

	/**
	 * Log a debug message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context.
	 */
	public function debug( string $message, array $context = array() ): void {
		$this->logs[] = array( 'message' => $message, 'context' => $context );
	}
}

/**
 * WooCommerce order class stub.
 */
class WC_Order {

	public function get_id(): int { return 0; }
	public function get_order_number(): string { return '0'; }

	/**
	 * Get order items.
	 *
	 * @param string $type Item type filter.
	 * @return array Order items.
	 */
	public function get_items( $type = '' ): array { return array(); }
	public function needs_shipping_address(): bool { return true; }
	public function get_shipping_first_name(): string { return ''; }
	public function get_shipping_last_name(): string { return ''; }
	public function get_shipping_company(): string { return ''; }
	public function get_billing_phone(): string { return ''; }
	public function get_shipping_address_1(): string { return ''; }
	public function get_shipping_address_2(): string { return ''; }
	public function get_shipping_city(): string { return ''; }
	public function get_shipping_state(): string { return ''; }
	public function get_shipping_postcode(): string { return ''; }
	public function get_shipping_country(): string { return ''; }
	public function update_meta_data( string $key, $value ): void {}
	public function save(): int { return 0; }
	public function add_order_note( string $note, int $is_customer_note = 0, bool $added_by_user = false ): int { return 0; }

	/**
	 * Get the order's customer-provided note.
	 *
	 * @param string $context Context: "view" applies filters, "edit" returns raw.
	 * @return string Customer note.
	 */
	public function get_customer_note( string $context = 'view' ): string { return ''; }

	/**
	 * Set the order's customer-provided note.
	 *
	 * @param string $note Note value.
	 * @return void
	 */
	public function set_customer_note( string $note ): void {}

	/**
	 * Get order meta data.
	 *
	 * @param string $key     Meta key.
	 * @param bool   $single  Whether to return a single value.
	 * @param string $context Context.
	 * @return mixed Meta value.
	 */
	public function get_meta( string $key, bool $single = true, string $context = 'view' ) { return ''; }
}

/**
 * WooCommerce order item base class stub.
 */
class WC_Order_Item {
	public function get_name(): string { return ''; }
}

/**
 * WooCommerce order item product stub.
 */
class WC_Order_Item_Product extends WC_Order_Item {

	/**
	 * Get the associated product.
	 *
	 * @return \WC_Product|false Product or false.
	 */
	public function get_product() { return false; }
	public function get_quantity(): int { return 1; }
}

/**
 * WooCommerce product class stub.
 */
class WC_Product {
	public function get_id(): int { return 0; }
	public function get_sku(): string { return ''; }
	public function needs_shipping(): bool { return true; }

	/**
	 * Get product length.
	 *
	 * @param string $context Context.
	 * @return string Length value.
	 */
	public function get_length( string $context = 'view' ): string { return ''; }

	/**
	 * Get product width.
	 *
	 * @param string $context Context.
	 * @return string Width value.
	 */
	public function get_width( string $context = 'view' ): string { return ''; }

	/**
	 * Get product height.
	 *
	 * @param string $context Context.
	 * @return string Height value.
	 */
	public function get_height( string $context = 'view' ): string { return ''; }

	/**
	 * Get product weight.
	 *
	 * @param string $context Context.
	 * @return string Weight value.
	 */
	public function get_weight( string $context = 'view' ): string { return ''; }
}

/**
 * WooCommerce shipping method base class stub.
 */
class WC_Shipping_Method {

	/** @var string */
	public $id = '';

	/** @var int */
	public $instance_id = 0;

	/** @var string */
	public $method_title = '';

	/** @var string */
	public $method_description = '';

	/** @var array */
	public $supports = array();

	/** @var string */
	public $title = '';

	/** @var string */
	public $enabled = 'yes';

	/** @var array */
	public $instance_form_fields = array();

	/** @var array */
	protected $settings = array();

	/** @var array Rates added during calculate_shipping(). */
	public $rates = array();

	/** No-op in tests. */
	public function init_form_fields(): void {}

	/** No-op in tests. */
	public function init_settings(): void {}

	/**
	 * Get an option value.
	 *
	 * @param string $key     Option key.
	 * @param mixed  $default Default value.
	 * @return mixed Option value.
	 */
	public function get_option( string $key, $default = '' ) {
		return $this->settings[ $key ] ?? $default;
	}

	/**
	 * Return a rate ID.
	 *
	 * @param string $suffix Optional suffix.
	 * @return string Rate ID.
	 */
	public function get_rate_id( string $suffix = '' ): string {
		$rate_id = $this->id;
		if ( $this->instance_id ) {
			$rate_id .= ':' . $this->instance_id;
		}
		if ( '' !== $suffix ) {
			$rate_id .= ':' . $suffix;
		}
		return $rate_id;
	}

	/**
	 * Add a shipping rate (captured for testing).
	 *
	 * @param array $rate Rate data.
	 */
	public function add_rate( array $rate ): void {
		$this->rates[] = $rate;
	}
}

// ---------------------------------------------------------------------------
// Load plugin classes (after all stubs are in place).
// ---------------------------------------------------------------------------
require_once FK_USPS_OPTIMIZER_PATH . 'includes/class-boxpacker-item.php';
require_once FK_USPS_OPTIMIZER_PATH . 'includes/class-boxpacker-box.php';
require_once FK_USPS_OPTIMIZER_PATH . 'includes/class-settings.php';
require_once FK_USPS_OPTIMIZER_PATH . 'includes/class-order-plan-service.php';
require_once FK_USPS_OPTIMIZER_PATH . 'includes/class-packing-service.php';
require_once FK_USPS_OPTIMIZER_PATH . 'includes/class-shipengine-service.php';
require_once FK_USPS_OPTIMIZER_PATH . 'includes/class-shipstation-service.php';
require_once FK_USPS_OPTIMIZER_PATH . 'includes/class-pirateship-export.php';
require_once FK_USPS_OPTIMIZER_PATH . 'includes/class-admin-ui.php';
require_once FK_USPS_OPTIMIZER_PATH . 'includes/class-shipping-method.php';
