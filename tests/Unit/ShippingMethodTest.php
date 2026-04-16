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
}
