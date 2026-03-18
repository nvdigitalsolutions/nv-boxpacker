<?php
/**
 * Unit tests for Settings.
 *
 * @package FK_USPS_Optimizer
 */

namespace FK_USPS_Optimizer\Tests\Unit;

use FK_USPS_Optimizer\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Settings class.
 */
class SettingsTest extends TestCase {

	/**
	 * System under test.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	protected function setUp(): void {
		$GLOBALS['_test_wp_options']      = array();
		$GLOBALS['_test_wp_filters']      = array();
		$GLOBALS['_test_settings_errors'] = array();
		$GLOBALS['_test_wp_transients']   = array();
		$this->settings                   = new Settings();
	}

	// -------------------------------------------------------------------------
	// Helper utilities
	// -------------------------------------------------------------------------

	/**
	 * Invoke a protected/private method via reflection.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Arguments.
	 * @return mixed Return value.
	 */
	private function call_protected( string $method, array $args = array() ) {
		$ref = new \ReflectionMethod( $this->settings, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $this->settings, $args );
	}

	// -------------------------------------------------------------------------
	// get_settings
	// -------------------------------------------------------------------------

	public function test_get_settings_returns_defaults_when_no_option_saved(): void {
		$result = $this->settings->get_settings();

		$this->assertSame( '', $result['shipengine_api_key'] );
		$this->assertSame( '', $result['shipengine_carrier_id'] );
		$this->assertSame( 'US', $result['ship_from_country'] );
		$this->assertSame( '0', $result['debug_logging'] );
		$this->assertNotEmpty( $result['boxes_json'] );
	}

	public function test_get_settings_merges_saved_option_over_defaults(): void {
		$GLOBALS['_test_wp_options'][ Settings::OPTION_KEY ] = array(
			'shipengine_api_key' => 'my_key',
			'ship_from_city'     => 'Austin',
		);

		$result = $this->settings->get_settings();

		$this->assertSame( 'my_key', $result['shipengine_api_key'] );
		$this->assertSame( 'Austin', $result['ship_from_city'] );
		// Defaults preserved for unset keys.
		$this->assertSame( 'US', $result['ship_from_country'] );
	}

	public function test_get_settings_boxes_json_default_is_valid_json(): void {
		$result = $this->settings->get_settings();
		$boxes  = json_decode( $result['boxes_json'], true );
		$this->assertIsArray( $boxes );
		$this->assertNotEmpty( $boxes );
	}

	// -------------------------------------------------------------------------
	// get_boxes
	// -------------------------------------------------------------------------

	public function test_get_boxes_returns_parsed_boxes(): void {
		$boxes = array( array( 'reference' => 'Box A', 'max_weight' => 20 ) );
		$GLOBALS['_test_wp_options'][ Settings::OPTION_KEY ] = array(
			'boxes_json' => json_encode( $boxes ),
		);

		$result = $this->settings->get_boxes();
		$this->assertCount( 1, $result );
		$this->assertSame( 'Box A', $result[0]['reference'] );
	}

	public function test_get_boxes_returns_defaults_when_json_invalid(): void {
		$GLOBALS['_test_wp_options'][ Settings::OPTION_KEY ] = array(
			'boxes_json' => 'NOT JSON',
		);

		$result = $this->settings->get_boxes();
		$this->assertNotEmpty( $result );
		// Should be the default set (5 boxes).
		$this->assertCount( 5, $result );
	}

	public function test_get_boxes_applies_filter(): void {
		$GLOBALS['_test_wp_filters']['fk_usps_optimizer_boxes'][] = function ( array $boxes ) {
			$boxes[] = array( 'reference' => 'Filtered Box' );
			return $boxes;
		};

		$result = $this->settings->get_boxes();

		$refs = array_column( $result, 'reference' );
		$this->assertContains( 'Filtered Box', $refs );
	}

	// -------------------------------------------------------------------------
	// get_ship_from_address
	// -------------------------------------------------------------------------

	public function test_get_ship_from_address_maps_settings_fields(): void {
		$GLOBALS['_test_wp_options'][ Settings::OPTION_KEY ] = array(
			'ship_from_name'        => 'John Doe',
			'ship_from_company'     => 'ACME',
			'ship_from_phone'       => '555-1234',
			'ship_from_address1'    => '100 Main St',
			'ship_from_address2'    => 'Suite 5',
			'ship_from_city'        => 'Springfield',
			'ship_from_state'       => 'IL',
			'ship_from_postal_code' => '62701',
			'ship_from_country'     => 'US',
		);

		$result = $this->settings->get_ship_from_address();

		$this->assertSame( 'John Doe', $result['name'] );
		$this->assertSame( 'ACME', $result['company_name'] );
		$this->assertSame( '555-1234', $result['phone'] );
		$this->assertSame( '100 Main St', $result['address_line1'] );
		$this->assertSame( 'Suite 5', $result['address_line2'] );
		$this->assertSame( 'Springfield', $result['city_locality'] );
		$this->assertSame( 'IL', $result['state_province'] );
		$this->assertSame( '62701', $result['postal_code'] );
		$this->assertSame( 'US', $result['country_code'] );
		$this->assertSame( 'no', $result['address_residential_indicator'] );
	}

	public function test_get_ship_from_address_applies_filter(): void {
		$GLOBALS['_test_wp_filters']['fk_usps_optimizer_ship_from_address'][] = function ( array $addr ) {
			$addr['name'] = 'Filtered Name';
			return $addr;
		};

		$result = $this->settings->get_ship_from_address();
		$this->assertSame( 'Filtered Name', $result['name'] );
	}

	// -------------------------------------------------------------------------
	// get_shipengine_api_key
	// -------------------------------------------------------------------------

	public function test_get_shipengine_api_key_returns_saved_key(): void {
		$GLOBALS['_test_wp_options'][ Settings::OPTION_KEY ] = array( 'shipengine_api_key' => 'secret_key' );
		$this->assertSame( 'secret_key', $this->settings->get_shipengine_api_key() );
	}

	public function test_get_shipengine_api_key_returns_empty_string_by_default(): void {
		$this->assertSame( '', $this->settings->get_shipengine_api_key() );
	}

	public function test_get_shipengine_api_key_applies_filter(): void {
		$GLOBALS['_test_wp_filters']['fk_usps_optimizer_shipengine_api_key'][] = fn() => 'overridden_key';
		$this->assertSame( 'overridden_key', $this->settings->get_shipengine_api_key() );
	}

	// -------------------------------------------------------------------------
	// get_shipengine_carrier_id
	// -------------------------------------------------------------------------

	public function test_get_shipengine_carrier_id_returns_saved_id(): void {
		$GLOBALS['_test_wp_options'][ Settings::OPTION_KEY ] = array( 'shipengine_carrier_id' => 'se-12345' );
		$this->assertSame( 'se-12345', $this->settings->get_shipengine_carrier_id() );
	}

	public function test_get_shipengine_carrier_id_returns_empty_by_default(): void {
		$this->assertSame( '', $this->settings->get_shipengine_carrier_id() );
	}

	public function test_get_shipengine_carrier_id_applies_filter(): void {
		$GLOBALS['_test_wp_filters']['fk_usps_optimizer_shipengine_carrier_id'][] = fn() => 'carrier_filter';
		$this->assertSame( 'carrier_filter', $this->settings->get_shipengine_carrier_id() );
	}

	// -------------------------------------------------------------------------
	// is_debug_logging_enabled
	// -------------------------------------------------------------------------

	public function test_debug_logging_disabled_by_default(): void {
		$this->assertFalse( $this->settings->is_debug_logging_enabled() );
	}

	public function test_debug_logging_enabled_when_option_is_one(): void {
		$GLOBALS['_test_wp_options'][ Settings::OPTION_KEY ] = array( 'debug_logging' => '1' );
		$this->assertTrue( $this->settings->is_debug_logging_enabled() );
	}

	public function test_debug_logging_disabled_when_option_is_zero(): void {
		$GLOBALS['_test_wp_options'][ Settings::OPTION_KEY ] = array( 'debug_logging' => '0' );
		$this->assertFalse( $this->settings->is_debug_logging_enabled() );
	}

	// -------------------------------------------------------------------------
	// sanitize_settings
	// -------------------------------------------------------------------------

	public function test_sanitize_settings_strips_tags_from_string_fields(): void {
		$input = array(
			'shipengine_api_key'    => '<b>my_key</b>',
			'shipengine_carrier_id' => 'se-1',
			'ship_from_name'        => 'John',
			'ship_from_company'     => '',
			'ship_from_phone'       => '',
			'ship_from_address1'    => '123 St',
			'ship_from_address2'    => '',
			'ship_from_city'        => 'City',
			'ship_from_state'       => 'CA',
			'ship_from_postal_code' => '90210',
			'ship_from_country'     => 'US',
			'debug_logging'         => '1',
			'boxes_json'            => json_encode( array() ),
		);

		$result = $this->settings->sanitize_settings( $input );

		$this->assertSame( 'my_key', $result['shipengine_api_key'] );
	}

	public function test_sanitize_settings_debug_logging_is_one_when_truthy(): void {
		$input  = array( 'debug_logging' => '1' ) + $this->empty_settings_input();
		$result = $this->settings->sanitize_settings( $input );
		$this->assertSame( '1', $result['debug_logging'] );
	}

	public function test_sanitize_settings_debug_logging_is_zero_when_absent(): void {
		$result = $this->settings->sanitize_settings( $this->empty_settings_input() );
		$this->assertSame( '0', $result['debug_logging'] );
	}

	public function test_sanitize_settings_returns_valid_boxes_json(): void {
		$boxes = array( array(
			'reference'    => 'Box',
			'package_code' => 'package',
			'package_name' => 'Box',
			'box_type'     => 'cubic',
			'outer_width'  => 8,
			'outer_length' => 8,
			'outer_depth'  => 6,
			'inner_width'  => 8,
			'inner_length' => 8,
			'inner_depth'  => 6,
			'empty_weight' => 3,
			'max_weight'   => 20,
		) );
		$input  = array( 'boxes_json' => json_encode( $boxes ) ) + $this->empty_settings_input();
		$result = $this->settings->sanitize_settings( $input );

		$decoded = json_decode( $result['boxes_json'], true );
		$this->assertIsArray( $decoded );
		$this->assertCount( 1, $decoded );
		$this->assertSame( 'Box', $decoded[0]['reference'] );
	}

	// -------------------------------------------------------------------------
	// sanitize_boxes_json (protected)
	// -------------------------------------------------------------------------

	public function test_sanitize_boxes_json_valid_returns_json_string(): void {
		$boxes = array( array(
			'reference'    => 'My Box',
			'package_code' => 'package',
			'package_name' => 'My Box Name',
			'box_type'     => 'flat_rate',
			'outer_width'  => 10,
			'outer_length' => 8,
			'outer_depth'  => 5,
			'inner_width'  => 10,
			'inner_length' => 8,
			'inner_depth'  => 5,
			'empty_weight' => 2,
			'max_weight'   => 70,
		) );

		$result  = $this->call_protected( 'sanitize_boxes_json', array( json_encode( $boxes ) ) );
		$decoded = json_decode( $result, true );

		$this->assertIsArray( $decoded );
		$this->assertSame( 'My Box', $decoded[0]['reference'] );
		$this->assertSame( 'flat_rate', $decoded[0]['box_type'] );
		$this->assertSame( 10, $decoded[0]['outer_width'] );
	}

	public function test_sanitize_boxes_json_invalid_json_returns_defaults_and_adds_error(): void {
		$result = $this->call_protected( 'sanitize_boxes_json', array( 'NOT VALID JSON' ) );

		$defaults = json_decode( $result, true );
		$this->assertIsArray( $defaults );
		$this->assertNotEmpty( $defaults );
		$this->assertNotEmpty( $GLOBALS['_test_settings_errors'] );
	}

	public function test_sanitize_boxes_json_skips_non_array_box_entries(): void {
		$json   = json_encode( array( 'string_entry', array( 'reference' => 'Good Box', 'package_code' => 'package', 'package_name' => 'Good', 'box_type' => 'cubic', 'outer_width' => 8, 'outer_length' => 8, 'outer_depth' => 6, 'inner_width' => 8, 'inner_length' => 8, 'inner_depth' => 6, 'empty_weight' => 3, 'max_weight' => 20 ) ) );
		$result = $this->call_protected( 'sanitize_boxes_json', array( $json ) );

		$decoded = json_decode( $result, true );
		$this->assertCount( 1, $decoded );
		$this->assertSame( 'Good Box', $decoded[0]['reference'] );
	}

	public function test_sanitize_boxes_json_defaults_box_type_to_cubic_for_unknown_type(): void {
		$boxes  = array( array( 'reference' => 'X', 'package_code' => 'package', 'package_name' => 'X', 'box_type' => 'unknown', 'outer_width' => 8, 'outer_length' => 8, 'outer_depth' => 6, 'inner_width' => 8, 'inner_length' => 8, 'inner_depth' => 6, 'empty_weight' => 3, 'max_weight' => 20 ) );
		$result = $this->call_protected( 'sanitize_boxes_json', array( json_encode( $boxes ) ) );

		$decoded = json_decode( $result, true );
		$this->assertSame( 'cubic', $decoded[0]['box_type'] );
	}

	public function test_sanitize_boxes_json_accepts_flat_rate_box_type(): void {
		$boxes  = array( array( 'reference' => 'FR', 'package_code' => 'small_flat_rate_box', 'package_name' => 'FR', 'box_type' => 'flat_rate', 'outer_width' => 9, 'outer_length' => 6, 'outer_depth' => 2, 'inner_width' => 9, 'inner_length' => 6, 'inner_depth' => 2, 'empty_weight' => 4, 'max_weight' => 70 ) );
		$result = $this->call_protected( 'sanitize_boxes_json', array( json_encode( $boxes ) ) );

		$decoded = json_decode( $result, true );
		$this->assertSame( 'flat_rate', $decoded[0]['box_type'] );
	}

	// -------------------------------------------------------------------------
	// get_default_boxes (protected)
	// -------------------------------------------------------------------------

	public function test_get_default_boxes_returns_five_entries(): void {
		$defaults = $this->call_protected( 'get_default_boxes' );
		$this->assertCount( 5, $defaults );
	}

	public function test_get_default_boxes_includes_cubic_and_flat_rate_types(): void {
		$defaults = $this->call_protected( 'get_default_boxes' );
		$types    = array_column( $defaults, 'box_type' );

		$this->assertContains( 'cubic', $types );
		$this->assertContains( 'flat_rate', $types );
	}

	public function test_get_default_boxes_each_entry_has_required_keys(): void {
		$required = array( 'reference', 'package_code', 'package_name', 'box_type', 'outer_width', 'outer_length', 'outer_depth', 'inner_width', 'inner_length', 'inner_depth', 'empty_weight', 'max_weight' );
		$defaults = $this->call_protected( 'get_default_boxes' );

		foreach ( $defaults as $box ) {
			foreach ( $required as $key ) {
				$this->assertArrayHasKey( $key, $box, "Default box missing key: $key" );
			}
		}
	}

	public function test_get_default_boxes_max_weight_is_positive(): void {
		$defaults = $this->call_protected( 'get_default_boxes' );
		foreach ( $defaults as $box ) {
			$this->assertGreaterThan( 0, $box['max_weight'] );
		}
	}

	// -------------------------------------------------------------------------
	// register (smoke test)
	// -------------------------------------------------------------------------

	public function test_register_runs_without_error(): void {
		// Hooks are no-ops in tests; verify no exception is thrown.
		$this->settings->register();
		$this->assertTrue( true );
	}

	// -------------------------------------------------------------------------
	// render_field (output testing)
	// -------------------------------------------------------------------------

	public function test_render_field_outputs_text_input_for_api_key(): void {
		ob_start();
		$this->settings->render_field( array( 'key' => 'shipengine_api_key' ) );
		$output = ob_get_clean();
		$this->assertStringContainsString( 'input', $output );
		$this->assertStringContainsString( 'shipengine_api_key', $output );
	}

	public function test_render_field_outputs_checkbox_for_debug_logging(): void {
		ob_start();
		$this->settings->render_field( array( 'key' => 'debug_logging' ) );
		$output = ob_get_clean();
		$this->assertStringContainsString( 'checkbox', $output );
	}

	public function test_render_field_outputs_textarea_for_boxes_json(): void {
		ob_start();
		$this->settings->render_field( array( 'key' => 'boxes_json' ) );
		$output = ob_get_clean();
		$this->assertStringContainsString( 'textarea', $output );
	}

	public function test_render_field_outputs_select_for_carrier_with_usps_note(): void {
		ob_start();
		$this->settings->render_field( array( 'key' => 'carrier' ) );
		$output = ob_get_clean();
		$this->assertStringContainsString( 'select', $output );
		$this->assertStringContainsString( 'USPS', $output );
		$this->assertStringContainsString( 'shipengine', $output );
	}

	public function test_render_field_sandbox_mode_label_mentions_test_key(): void {
		ob_start();
		$this->settings->render_field( array( 'key' => 'sandbox_mode' ) );
		$output = ob_get_clean();
		$this->assertStringContainsString( 'TEST_', $output );
	}

	public function test_render_field_carrier_id_shows_description(): void {
		ob_start();
		$this->settings->render_field( array( 'key' => 'shipengine_carrier_id' ) );
		$output = ob_get_clean();
		$this->assertStringContainsString( 'USPS carrier ID', $output );
		$this->assertStringContainsString( 'Test Connection', $output );
	}

	public function test_render_page_includes_test_connection_form(): void {
		ob_start();
		$this->settings->render_page();
		$output = ob_get_clean();
		$this->assertStringContainsString( 'fk_usps_test_connection', $output );
		$this->assertStringContainsString( 'Test ShipEngine Connection', $output );
	}

	public function test_render_page_displays_success_transient(): void {
		$GLOBALS['_test_wp_transients']['fk_usps_test_connection_result'] = array(
			'success' => true,
			'message' => 'Connection successful! USPS carrier "USPS" is active.',
		);

		ob_start();
		$this->settings->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-success', $output );
		$this->assertStringContainsString( 'Connection successful', $output );
		// Transient should be consumed.
		$this->assertFalse( $GLOBALS['_test_wp_transients']['fk_usps_test_connection_result'] ?? false );
	}

	public function test_render_page_displays_error_transient(): void {
		$GLOBALS['_test_wp_transients']['fk_usps_test_connection_result'] = array(
			'success' => false,
			'message' => 'Invalid ShipEngine API key.',
		);

		ob_start();
		$this->settings->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringContainsString( 'Invalid ShipEngine API key', $output );
	}


	// -------------------------------------------------------------------------
	// render_page (smoke test)
	// -------------------------------------------------------------------------

	public function test_render_page_outputs_wrap_div(): void {
		ob_start();
		$this->settings->render_page();
		$output = ob_get_clean();
		$this->assertStringContainsString( 'class="wrap"', $output );
		$this->assertStringContainsString( 'form', $output );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Return an input array with all fields empty.
	 *
	 * @return array Empty input.
	 */
	private function empty_settings_input(): array {
		return array(
			'shipengine_api_key'    => '',
			'shipengine_carrier_id' => '',
			'ship_from_name'        => '',
			'ship_from_company'     => '',
			'ship_from_phone'       => '',
			'ship_from_address1'    => '',
			'ship_from_address2'    => '',
			'ship_from_city'        => '',
			'ship_from_state'       => '',
			'ship_from_postal_code' => '',
			'ship_from_country'     => 'US',
			'debug_logging'         => '',
			'boxes_json'            => json_encode( array() ),
		);
	}
}
