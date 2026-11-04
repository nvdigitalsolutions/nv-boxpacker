<?php
/**
 * Unit tests for Shipping_Method.
 *
 * @package FK_USPS_Optimizer
 */

namespace FK_USPS_Optimizer\Tests\Unit;

use FK_USPS_Optimizer\Plugin;
use FK_USPS_Optimizer\Settings;
use FK_USPS_Optimizer\ShipStation_Service;
use FK_USPS_Optimizer\Shipping_Method;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Shipping_Method.
 */
class ShippingMethodTest extends TestCase {

	/**
	 * System under test.
	 *
	 * @var Shipping_Method
	 */
	private Shipping_Method $method;

	protected function setUp(): void {
		$GLOBALS['_test_wp_options']    = array();
		$GLOBALS['_test_wp_filters']    = array();
		$GLOBALS['_test_wp_transients'] = array();
		$GLOBALS['_test_wc_logger']     = new \WC_Test_Logger();

		$this->method = new Shipping_Method();
	}

	// -------------------------------------------------------------------------
	// Helper utilities
	// -------------------------------------------------------------------------

	/**
	 * Invoke a protected method via reflection.
	 *
	 * @param string $name Method name.
	 * @param array  $args Arguments.
	 * @return mixed Return value.
	 */
	private function call_protected( string $name, array $args = array() ) {
		$ref = new \ReflectionMethod( $this->method, $name );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $this->method, $args );
	}

	// -------------------------------------------------------------------------
	// build_ship_to
	// -------------------------------------------------------------------------

	public function test_build_ship_to_maps_destination_fields(): void {
		$dest   = array(
			'address'   => '123 Main St',
			'address_2' => 'Apt 4',
			'city'      => 'Austin',
			'state'     => 'TX',
			'postcode'  => '78701',
			'country'   => 'US',
		);
		$result = $this->call_protected( 'build_ship_to', array( $dest ) );

		$this->assertSame( '123 Main St', $result['address_line1'] );
		$this->assertSame( 'Apt 4', $result['address_line2'] );
		$this->assertSame( 'Austin', $result['city_locality'] );
		$this->assertSame( 'TX', $result['state_province'] );
		$this->assertSame( '78701', $result['postal_code'] );
		$this->assertSame( 'US', $result['country_code'] );
	}

	// -------------------------------------------------------------------------
	// format_estimated_delivery (protected)
	// -------------------------------------------------------------------------

	public function test_format_estimated_delivery_returns_empty_for_empty_string(): void {
		$result = $this->call_protected( 'format_estimated_delivery', array( '' ) );
		$this->assertSame( '', $result );
	}

	public function test_format_estimated_delivery_formats_iso_datetime(): void {
		// '2024-01-15T00:00:00Z' → 'Mon, Jan 15'.
		$result = $this->call_protected( 'format_estimated_delivery', array( '2024-01-15T00:00:00Z' ) );
		$this->assertSame( 'Mon, Jan 15', $result );
	}

	public function test_format_estimated_delivery_formats_plain_date(): void {
		// '2024-03-20' → 'Wed, Mar 20'.
		$result = $this->call_protected( 'format_estimated_delivery', array( '2024-03-20' ) );
		$this->assertSame( 'Wed, Mar 20', $result );
	}

	public function test_format_estimated_delivery_returns_empty_for_invalid_date(): void {
		// PHP 8.3+ throws DateMalformedStringException for truly unparseable strings.
		// Our catch(\Throwable) block returns '' in that case.
		$result = $this->call_protected( 'format_estimated_delivery', array( 'not-a-date' ) );
		$this->assertSame( '', $result );
	}

	// -------------------------------------------------------------------------
	// Phase 3: should_skip_rate_calculation
	// -------------------------------------------------------------------------

	public function test_skip_when_country_is_missing(): void {
		$package = array( 'destination' => array( 'postcode' => '78701' ) );
		$this->assertTrue( $this->call_protected( 'should_skip_rate_calculation', array( $package ) ) );
	}

	public function test_skip_when_us_postcode_is_too_short(): void {
		$package = array( 'destination' => array( 'country' => 'US', 'postcode' => '787' ) );
		$this->assertTrue( $this->call_protected( 'should_skip_rate_calculation', array( $package ) ) );
	}

	public function test_skip_when_us_postcode_is_empty(): void {
		$package = array( 'destination' => array( 'country' => 'US', 'postcode' => '' ) );
		$this->assertTrue( $this->call_protected( 'should_skip_rate_calculation', array( $package ) ) );
	}

	public function test_proceed_when_us_postcode_is_complete(): void {
		$package = array( 'destination' => array( 'country' => 'US', 'postcode' => '78701' ) );
		$this->assertFalse( $this->call_protected( 'should_skip_rate_calculation', array( $package ) ) );
	}

	public function test_us_postcode_short_circuit_uses_country_specific_default(): void {
		// CA defaults to 3, so a 3-char postcode passes for CA but the same
		// length still trips the US 5-char default.
		$ca = array( 'destination' => array( 'country' => 'CA', 'postcode' => 'M5V' ) );
		$us = array( 'destination' => array( 'country' => 'US', 'postcode' => 'M5V' ) );
		$this->assertFalse( $this->call_protected( 'should_skip_rate_calculation', array( $ca ) ) );
		$this->assertTrue( $this->call_protected( 'should_skip_rate_calculation', array( $us ) ) );
	}

	public function test_country_code_normalised_to_upper_case(): void {
		// Lowercase country should still be matched against the per-country
		// default map.
		$package = array( 'destination' => array( 'country' => 'us', 'postcode' => '787' ) );
		$this->assertTrue( $this->call_protected( 'should_skip_rate_calculation', array( $package ) ) );
	}

	public function test_min_postcode_length_filter_overrides_default(): void {
		$captured = array();
		$GLOBALS['_test_wp_filters']['fk_usps_optimizer_min_postcode_length'] = array(
			static function ( int $default, string $country ) use ( &$captured ): int {
				$captured[] = array( 'default' => $default, 'country' => $country );
				return 'GB' === $country ? 6 : $default;
			},
		);

		// GB default would be 3, filter pushes it to 6 so a 5-char postcode skips.
		$short_gb = array( 'destination' => array( 'country' => 'GB', 'postcode' => 'SW1A1' ) );
		$this->assertTrue( $this->call_protected( 'should_skip_rate_calculation', array( $short_gb ) ) );

		// Other countries fall through to the default the filter returned untouched.
		$us = array( 'destination' => array( 'country' => 'US', 'postcode' => '78701' ) );
		$this->assertFalse( $this->call_protected( 'should_skip_rate_calculation', array( $us ) ) );

		// Filter received the per-country defaults (GB=3, US=5) and uppercased country.
		$this->assertSame( 3, $captured[0]['default'] );
		$this->assertSame( 'GB', $captured[0]['country'] );
		$this->assertSame( 5, $captured[1]['default'] );
		$this->assertSame( 'US', $captured[1]['country'] );
	}

	public function test_min_postcode_length_filter_can_disable_check(): void {
		$GLOBALS['_test_wp_filters']['fk_usps_optimizer_min_postcode_length'] = array(
			static fn(): int => 0,
		);

		// 0 disables the check — even an empty postcode is allowed through
		// (so the country-only check is the sole gate).
		$package = array( 'destination' => array( 'country' => 'US', 'postcode' => '' ) );
		$this->assertFalse( $this->call_protected( 'should_skip_rate_calculation', array( $package ) ) );
	}

	public function test_skip_rates_filter_short_circuits_before_country_check(): void {
		$received = array();
		$GLOBALS['_test_wp_filters']['fk_usps_optimizer_skip_rates'] = array(
			static function ( bool $skip, array $package ) use ( &$received ): bool {
				$received = $package;
				return true;
			},
		);

		// Even a perfectly valid destination is short-circuited.
		$package = array(
			'destination' => array( 'country' => 'US', 'postcode' => '78701' ),
			'marker'      => 'context-passed-through',
		);
		$this->assertTrue( $this->call_protected( 'should_skip_rate_calculation', array( $package ) ) );

		// And the filter received the WC package as context.
		$this->assertSame( 'context-passed-through', $received['marker'] ?? null );
	}

	public function test_skip_rates_filter_returning_false_does_not_short_circuit(): void {
		$GLOBALS['_test_wp_filters']['fk_usps_optimizer_skip_rates'] = array(
			static fn( bool $skip ): bool => false,
		);

		$package = array( 'destination' => array( 'country' => 'US', 'postcode' => '78701' ) );
		$this->assertFalse( $this->call_protected( 'should_skip_rate_calculation', array( $package ) ) );
	}

	// -------------------------------------------------------------------------
	// Phase 3: log_calculate_shipping_timing
	// -------------------------------------------------------------------------

	public function test_timing_log_records_elapsed_ms_rate_count_and_destination(): void {
		$logger                       = new \WC_Test_Logger();
		$GLOBALS['_test_wc_logger']   = $logger;

		// Time the call relative to "now minus 50ms".
		$started_at = microtime( true ) - 0.05;
		$rates      = array( array( 'cost' => 1.0 ), array( 'cost' => 2.0 ) );
		$packages   = array( array(), array(), array() );
		$ship_to    = array( 'postal_code' => '78701', 'country_code' => 'US' );

		$this->call_protected(
			'log_calculate_shipping_timing',
			array( $started_at, $rates, $packages, $ship_to )
		);

		$this->assertCount( 1, $logger->logs );

		$entry   = $logger->logs[0];
		$context = json_decode( substr( $entry['message'], strpos( $entry['message'], '{' ) ), true );

		$this->assertIsArray( $context );
		$this->assertSame( 2, $context['rate_count'] );
		$this->assertSame( 3, $context['package_count'] );
		$this->assertSame( '78701', $context['postal_code'] );
		$this->assertSame( 'US', $context['country_code'] );
		$this->assertGreaterThanOrEqual( 40, $context['elapsed_ms'], 'Elapsed time should be ≳50ms.' );
		$this->assertSame( 'fk-usps-optimizer', $entry['context']['source'] );
		$this->assertStringStartsWith( 'calculate_shipping completed', $entry['message'] );
	}

	// -------------------------------------------------------------------------
	// build_rate_id — stable, content-derived rate IDs
	// -------------------------------------------------------------------------

	public function test_build_rate_id_is_deterministic_and_contains_service_code(): void {
		$rate = array( 'service_code' => 'ups_2nd_day_air', 'label' => 'UPS 2nd Day Air', 'cost' => 25.00 );

		$id1 = $this->call_protected( 'build_rate_id', array( $rate ) );
		$id2 = $this->call_protected( 'build_rate_id', array( $rate ) );

		$this->assertSame( $id1, $id2, 'The same option must always produce the same ID.' );
		$this->assertStringContainsString( 'ups_2nd_day_air', $id1 );
		$this->assertStringStartsWith( 'fk_usps_optimizer:', $id1 );
	}

	public function test_build_rate_id_differs_between_services(): void {
		$priority = array( 'service_code' => 'usps_priority_mail', 'label' => 'USPS Priority', 'cost' => 8.50 );
		$media    = array( 'service_code' => 'usps_media_mail', 'label' => 'USPS Media Mail', 'cost' => 8.50 );

		$id_priority = $this->call_protected( 'build_rate_id', array( $priority ) );
		$id_media    = $this->call_protected( 'build_rate_id', array( $media ) );

		$this->assertNotSame( $id_priority, $id_media );
		$this->assertStringContainsString( 'usps_priority_mail', $id_priority );
		$this->assertStringContainsString( 'usps_media_mail', $id_media );
	}

	public function test_build_rate_id_differs_for_same_service_with_different_combo(): void {
		$combo_a = array( 'service_code' => 'usps_priority_mail', 'label' => 'USPS Priority — Small', 'cost' => 8.50 );
		$combo_b = array( 'service_code' => 'usps_priority_mail', 'label' => 'USPS Priority — Large', 'cost' => 8.50 );

		$this->assertNotSame(
			$this->call_protected( 'build_rate_id', array( $combo_a ) ),
			$this->call_protected( 'build_rate_id', array( $combo_b ) )
		);
	}

	public function test_build_rate_id_changes_when_cost_changes(): void {
		$before = array( 'service_code' => 'ups_ground', 'label' => 'UPS Ground', 'cost' => 10.00 );
		$after  = array( 'service_code' => 'ups_ground', 'label' => 'UPS Ground', 'cost' => 11.00 );

		// A price change is a different offer: the old selection must not
		// silently bind to the re-priced option at a different index.
		$this->assertNotSame(
			$this->call_protected( 'build_rate_id', array( $before ) ),
			$this->call_protected( 'build_rate_id', array( $after ) )
		);
	}

	public function test_build_rate_id_uses_placeholder_when_service_code_missing(): void {
		$id = $this->call_protected(
			'build_rate_id',
			array( array( 'label' => 'Mystery', 'cost' => 5.00 ) )
		);

		$this->assertStringContainsString( 'any-service', $id );
	}

	public function test_build_rate_id_is_stable_under_reordering(): void {
		$ground  = array( 'service_code' => 'ups_ground', 'label' => 'UPS Ground', 'cost' => 9.00 );
		$air     = array( 'service_code' => 'ups_2nd_day_air', 'label' => 'UPS 2nd Day Air', 'cost' => 22.00 );

		$ids_first_pass  = array(
			$this->call_protected( 'build_rate_id', array( $ground ) ),
			$this->call_protected( 'build_rate_id', array( $air ) ),
		);
		$ids_second_pass = array(
			$this->call_protected( 'build_rate_id', array( $air ) ),
			$this->call_protected( 'build_rate_id', array( $ground ) ),
		);

		sort( $ids_first_pass );
		sort( $ids_second_pass );

		$this->assertSame( $ids_first_pass, $ids_second_pass );
	}

	// -------------------------------------------------------------------------
	// resolve_rate_title — never advertise a service the customer did not price
	// -------------------------------------------------------------------------

	public function test_resolve_rate_title_returns_sole_label(): void {
		$title = $this->call_protected(
			'resolve_rate_title',
			array( array( 'USPS Priority', 'USPS Priority' ) )
		);

		$this->assertSame( 'USPS Priority', $title );
	}

	public function test_resolve_rate_title_returns_most_frequent_label_when_mixed(): void {
		$title = $this->call_protected(
			'resolve_rate_title',
			array( array( 'USPS Priority', 'UPS Ground', 'USPS Priority' ) )
		);

		$this->assertSame( 'USPS Priority', $title );
	}

	public function test_resolve_rate_title_returns_empty_when_all_labels_blank(): void {
		$title = $this->call_protected(
			'resolve_rate_title',
			array( array( '', '   ' ) )
		);

		$this->assertSame( '', $title );
	}

	public function test_resolve_rate_title_ignores_blank_labels_among_valid(): void {
		$title = $this->call_protected(
			'resolve_rate_title',
			array( array( '', 'UPS Ground', '' ) )
		);

		$this->assertSame( 'UPS Ground', $title );
	}

	// -------------------------------------------------------------------------
	// first_carrier_code
	// -------------------------------------------------------------------------

	public function test_first_carrier_code_returns_first_non_empty(): void {
		$carrier = $this->call_protected(
			'first_carrier_code',
			array(
				array(
					array( 'carrier_code' => '' ),
					array( 'carrier_code' => 'ups' ),
					array( 'carrier_code' => 'usps' ),
				),
			)
		);

		$this->assertSame( 'ups', $carrier );
	}

	public function test_first_carrier_code_returns_empty_when_none_present(): void {
		$carrier = $this->call_protected(
			'first_carrier_code',
			array( array( array( 'no_carrier' => true ) ) )
		);

		$this->assertSame( '', $carrier );
	}

	// -------------------------------------------------------------------------
	// calculate_cheapest_option / calculate_all_options label fidelity
	// -------------------------------------------------------------------------

	public function test_calculate_cheapest_option_labels_rates_by_confirmed_service(): void {
		$settings = $this->make_rate_settings();
		$plugin   = $this->make_plugin_with_shipstation( $settings, '' );

		$GLOBALS['_test_wp_remote_post'] = $this->shipstation_multi_service_response();

		$rates = $this->call_protected(
			'calculate_cheapest_option',
			array( $plugin, array( $this->make_packed_package() ), $this->make_calc_ship_to(), $settings )
		);

		$this->assertCount( 2, $rates );
		// Cheapest-first.
		$this->assertSame( 'USPS Media Mail', $rates[0]['label'] );
		$this->assertSame( 4.20, $rates[0]['cost'] );
		$this->assertSame( 'usps_media_mail', $rates[0]['service_code'] );
		$this->assertSame( 'usps', $rates[0]['carrier_code'] );
		$this->assertSame( 'USPS Priority', $rates[1]['label'] );
		$this->assertSame( 8.50, $rates[1]['cost'] );
		$this->assertSame( 'usps_priority_mail', $rates[1]['service_code'] );
		// The generic method title must never leak into a priced option.
		$this->assertStringNotContainsString( 'USPS Priority Optimizer', $rates[0]['label'] );
		$this->assertStringNotContainsString( 'USPS Priority Optimizer', $rates[1]['label'] );
	}

	public function test_calculate_all_options_labels_rates_by_confirmed_service(): void {
		$settings = $this->make_rate_settings();
		$plugin   = $this->make_plugin_with_shipstation( $settings, '' );

		$GLOBALS['_test_wp_remote_post'] = $this->shipstation_multi_service_response();

		$rates = $this->call_protected(
			'calculate_all_options',
			array( $plugin, array( $this->make_packed_package() ), $this->make_calc_ship_to(), $settings )
		);

		$this->assertCount( 2, $rates );

		$this->assertStringContainsString( 'USPS Media Mail', $rates[0]['label'] );
		$this->assertSame( 'usps_media_mail', $rates[0]['service_code'] );
		$this->assertSame( 'USPS Media Mail', $rates[0]['service_label'] );

		$this->assertStringContainsString( 'USPS Priority', $rates[1]['label'] );
		$this->assertSame( 'usps_priority_mail', $rates[1]['service_code'] );
		$this->assertSame( 'USPS Priority', $rates[1]['service_label'] );

		$this->assertStringNotContainsString( 'USPS Priority Optimizer', $rates[0]['label'] );
		$this->assertStringNotContainsString( 'USPS Priority Optimizer', $rates[1]['label'] );
	}

	public function test_calculate_cheapest_option_skips_groups_with_no_label(): void {
		$settings = $this->make_rate_settings();
		$plugin   = $this->make_plugin_with_shipstation( $settings, '' );

		// Rates without a serviceCode and no configured service produce plans
		// with empty labels, which must be skipped rather than sold under the
		// generic method title.
		$GLOBALS['_test_wp_remote_post'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array(
				array( 'shipmentCost' => 4.20, 'otherCost' => 0.00 ),
			) ),
		);

		$rates = $this->call_protected(
			'calculate_cheapest_option',
			array( $plugin, array( $this->make_packed_package() ), $this->make_calc_ship_to(), $settings )
		);

		$this->assertSame( array(), $rates );
	}

	// -------------------------------------------------------------------------
	// Rate-calculator helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a settings mock for the rate calculators.
	 *
	 * @return Settings|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function make_rate_settings() {
		$settings = $this->createMock( Settings::class );
		$settings->method( 'is_show_package_count_enabled' )->willReturn( false );
		$settings->method( 'is_show_estimated_delivery_enabled' )->willReturn( false );

		// ShipStation service wiring.
		$settings->method( 'get_boxes_for_carrier' )->willReturn(
			array(
				array(
					'reference'    => 'Test Box',
					'package_code' => 'package',
					'package_name' => 'Test Box',
					'box_type'     => 'cubic',
					'outer_width'  => 8.0,
					'outer_length' => 8.0,
					'outer_depth'  => 6.0,
					'inner_width'  => 8.0,
					'inner_length' => 8.0,
					'inner_depth'  => 6.0,
					'empty_weight' => 3.0,
					'max_weight'   => 20.0,
				),
			)
		);
		$settings->method( 'get_shipstation_api_key' )->willReturn( 'k' );
		$settings->method( 'get_shipstation_api_secret' )->willReturn( 's' );
		$settings->method( 'get_shipstation_carrier_code' )->willReturn( 'stamps_com' );
		$settings->method( 'get_shipstation_service_code' )->willReturn( '' );
		$settings->method( 'get_ship_from_address' )->willReturn( array( 'postal_code' => '90210' ) );
		$settings->method( 'is_sandbox_mode_enabled' )->willReturn( true );
		$settings->method( 'is_debug_logging_enabled' )->willReturn( false );

		return $settings;
	}

	/**
	 * Build a Plugin mock whose carrier services are a single ShipStation
	 * service instance.
	 *
	 * @param Settings $settings     Settings mock.
	 * @param string   $service_code ShipStation service code override.
	 * @return Plugin|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function make_plugin_with_shipstation( Settings $settings, string $service_code ) {
		$service = new ShipStation_Service( $settings, 'stamps_com', $service_code );

		$plugin = $this->createMock( Plugin::class );
		$plugin->method( 'get_carrier_services' )->willReturn( array( $service ) );

		return $plugin;
	}

	/**
	 * A ShipStation response with two services (cheapest = Media Mail).
	 *
	 * @return array wp_remote_post()-shaped stub.
	 */
	private function shipstation_multi_service_response(): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array(
				array( 'serviceName' => 'USPS Priority Mail', 'serviceCode' => 'usps_priority_mail', 'shipmentCost' => 8.50, 'otherCost' => 0.00 ),
				array( 'serviceName' => 'USPS Media Mail', 'serviceCode' => 'usps_media_mail', 'shipmentCost' => 4.20, 'otherCost' => 0.00 ),
			) ),
		);
	}

	/**
	 * A minimal packed package for the rate calculators.
	 *
	 * @return array Packed package.
	 */
	private function make_packed_package(): array {
		return array(
			'dimensions' => array( 'length' => 6.0, 'width' => 6.0, 'height' => 4.0 ),
			'weight_oz'  => 16.0,
			'items'      => array( array( 'name' => 'Widget', 'weight_oz' => 16.0 ) ),
		);
	}

	/**
	 * A minimal ship-to array for the rate calculators.
	 *
	 * @return array Ship-to.
	 */
	private function make_calc_ship_to(): array {
		return array(
			'name'           => 'Jane Doe',
			'address_line1'  => '123 Main St',
			'city_locality'  => 'Austin',
			'state_province' => 'TX',
			'postal_code'    => '78701',
			'country_code'   => 'US',
		);
	}
}
