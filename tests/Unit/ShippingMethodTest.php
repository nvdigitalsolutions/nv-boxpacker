<?php
/**
 * Unit tests for Shipping_Method.
 *
 * @package FK_USPS_Optimizer
 */

namespace FK_USPS_Optimizer\Tests\Unit;

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
}
