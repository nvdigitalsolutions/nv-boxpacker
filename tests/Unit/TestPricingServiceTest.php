<?php
/**
 * Unit tests for Test_Pricing_Service.
 *
 * @package FK_USPS_Optimizer
 */

namespace FK_USPS_Optimizer\Tests\Unit;

use FK_USPS_Optimizer\Packing_Service;
use FK_USPS_Optimizer\Settings;
use FK_USPS_Optimizer\ShipEngine_Service;
use FK_USPS_Optimizer\ShipStation_Service;
use FK_USPS_Optimizer\Test_Pricing_Service;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Test_Pricing_Service.
 */
class TestPricingServiceTest extends TestCase {

	/**
	 * Settings mock.
	 *
	 * @var Settings|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $settings;

	/**
	 * Packing service mock.
	 *
	 * @var Packing_Service|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $packing_service;

	/**
	 * ShipEngine service mock.
	 *
	 * @var ShipEngine_Service|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $shipengine_service;

	/**
	 * ShipStation service mock.
	 *
	 * @var ShipStation_Service|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $shipstation_service;

	/**
	 * System under test.
	 *
	 * @var Test_Pricing_Service
	 */
	private Test_Pricing_Service $service;

	protected function setUp(): void {
		$GLOBALS['_test_wp_filters'] = array();

		$this->settings            = $this->createMock( Settings::class );
		$this->packing_service     = $this->createMock( Packing_Service::class );
		$this->shipengine_service  = $this->createMock( ShipEngine_Service::class );
		$this->shipstation_service = $this->createMock( ShipStation_Service::class );

		$this->service = new Test_Pricing_Service(
			$this->settings,
			$this->packing_service,
			$this->shipengine_service,
			$this->shipstation_service
		);
	}

	// -------------------------------------------------------------------------
	// Helper utilities
	// -------------------------------------------------------------------------

	/**
	 * Invoke a protected method via reflection.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Arguments.
	 * @return mixed Return value.
	 */
	private function call_protected( string $method, array $args = array() ) {
		$ref = new \ReflectionMethod( $this->service, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $this->service, $args );
	}

	/**
	 * Build a valid raw item row as submitted by the admin form.
	 *
	 * @param array $overrides Field overrides.
	 * @return array Raw item row.
	 */
	private function make_raw_item( array $overrides = array() ): array {
		return array_merge(
			array(
				'name'      => 'Widget',
				'qty'       => '2',
				'length'    => '6.0',
				'width'     => '4.0',
				'height'    => '3.0',
				'weight_oz' => '8.0',
			),
			$overrides
		);
	}

	/**
	 * Build a minimal packed-package array (output of Packing_Service::pack_items).
	 *
	 * @return array Packed package.
	 */
	private function make_packed_package(): array {
		return array(
			'packed_box' => array( 'reference' => 'Small', 'package_code' => 'package', 'package_name' => 'Small Box', 'box_type' => 'cubic' ),
			'items'      => array( array( 'name' => 'Widget', 'weight_oz' => 8.0 ) ),
			'weight_oz'  => 8.0,
			'dimensions' => array( 'length' => 8.0, 'width' => 8.0, 'height' => 6.0 ),
		);
	}

	/**
	 * Build a minimal plan array (output of a carrier's build_test_package_plan).
	 *
	 * @param int   $number Package number.
	 * @param float $rate   Rate amount.
	 * @return array Package plan.
	 */
	private function make_plan( int $number = 1, float $rate = 7.99 ): array {
		return array(
			'package_number' => $number,
			'mode'           => 'cubic',
			'package_code'   => 'package',
			'package_name'   => 'Small Box',
			'service_code'   => 'usps_priority_mail',
			'rate_amount'    => $rate,
			'currency'       => 'USD',
			'weight_oz'      => 11.0,
			'dimensions'     => array( 'length' => 8.0, 'width' => 8.0, 'height' => 6.0 ),
			'cubic_tier'     => '0.3',
			'packing_list'   => array( '1x Widget' ),
			'items'          => array( array( 'name' => 'Widget', 'weight_oz' => 8.0 ) ),
		);
	}

	/**
	 * Build a minimal ship-to address.
	 *
	 * @return array Address array.
	 */
	private function make_ship_to(): array {
		return array(
			'name'           => 'Jane Doe',
			'address_line1'  => '1 Main St',
			'city_locality'  => 'Austin',
			'state_province' => 'TX',
			'postal_code'    => '78701',
			'country_code'   => 'US',
		);
	}

	// -------------------------------------------------------------------------
	// expand_items
	// -------------------------------------------------------------------------

	public function test_expand_items_creates_one_entry_per_unit(): void {
		$raw    = array( $this->make_raw_item( array( 'qty' => '3' ) ) );
		$result = $this->service->expand_items( $raw );
		$this->assertCount( 3, $result );
	}

	public function test_expand_items_each_entry_has_required_keys(): void {
		$raw    = array( $this->make_raw_item() );
		$result = $this->service->expand_items( $raw );
		$this->assertArrayHasKey( 'name', $result[0] );
		$this->assertArrayHasKey( 'length', $result[0] );
		$this->assertArrayHasKey( 'width', $result[0] );
		$this->assertArrayHasKey( 'height', $result[0] );
		$this->assertArrayHasKey( 'weight_oz', $result[0] );
	}

	public function test_expand_items_skips_entirely_blank_rows(): void {
		$raw = array(
			array( 'name' => '', 'length' => '', 'weight_oz' => '', 'qty' => '1', 'width' => '', 'height' => '' ),
			$this->make_raw_item(),
		);
		$result = $this->service->expand_items( $raw );
		$this->assertCount( 2, $result ); // qty=2 from the valid item.
	}

	public function test_expand_items_assigns_default_name_when_blank(): void {
		$raw    = array( array( 'name' => '', 'qty' => '1', 'length' => '5', 'width' => '4', 'height' => '3', 'weight_oz' => '8' ) );
		$result = $this->service->expand_items( $raw );
		$this->assertStringContainsString( 'Item', $result[0]['name'] );
	}

	public function test_expand_items_clamps_dimensions_to_minimum_0_1(): void {
		$raw    = array( array( 'name' => 'Tiny', 'qty' => '1', 'length' => '0', 'width' => '-5', 'height' => '', 'weight_oz' => '0' ) );
		$result = $this->service->expand_items( $raw );
		$this->assertGreaterThanOrEqual( 0.1, $result[0]['length'] );
		$this->assertGreaterThanOrEqual( 0.1, $result[0]['width'] );
		$this->assertGreaterThanOrEqual( 0.1, $result[0]['weight_oz'] );
	}

	public function test_expand_items_returns_empty_array_for_all_blank_rows(): void {
		$raw    = array(
			array( 'name' => '', 'length' => '', 'weight_oz' => '', 'qty' => '1', 'width' => '', 'height' => '' ),
		);
		$result = $this->service->expand_items( $raw );
		$this->assertSame( array(), $result );
	}

	public function test_expand_items_ignores_non_array_entries(): void {
		$raw    = array( 'string_entry', $this->make_raw_item( array( 'qty' => '1' ) ) );
		$result = $this->service->expand_items( $raw );
		$this->assertCount( 1, $result );
	}

	// -------------------------------------------------------------------------
	// run — no items
	// -------------------------------------------------------------------------

	public function test_run_returns_warning_when_no_valid_items(): void {
		$this->settings->method( 'get_carriers' )->willReturn( array( 'shipengine' ) );
		$this->settings->method( 'is_sandbox_mode_enabled' )->willReturn( false );

		$result = $this->service->run(
			array( array( 'name' => '', 'length' => '', 'weight_oz' => '', 'qty' => '1', 'width' => '', 'height' => '' ) ),
			$this->make_ship_to()
		);

		$this->assertNotEmpty( $result['warnings'] );
		$this->assertEmpty( $result['packages'] );
	}

	// -------------------------------------------------------------------------
	// run — packing returns nothing
	// -------------------------------------------------------------------------

	public function test_run_returns_warning_when_packing_produces_nothing(): void {
		$this->settings->method( 'get_carriers' )->willReturn( array( 'shipengine' ) );
		$this->settings->method( 'is_sandbox_mode_enabled' )->willReturn( false );
		$this->packing_service->method( 'pack_items' )->willReturn( array() );

		$result = $this->service->run( array( $this->make_raw_item() ), $this->make_ship_to() );

		$this->assertNotEmpty( $result['warnings'] );
		$this->assertEmpty( $result['packages'] );
	}

	// -------------------------------------------------------------------------
	// run — rate lookup fails
	// -------------------------------------------------------------------------

	public function test_run_adds_warning_when_rate_not_found_for_package(): void {
		$this->settings->method( 'get_carriers' )->willReturn( array( 'shipengine' ) );
		$this->settings->method( 'is_sandbox_mode_enabled' )->willReturn( false );
		$this->packing_service->method( 'pack_items' )->willReturn( array( $this->make_packed_package() ) );
		$this->shipengine_service->method( 'build_test_package_plan' )->willReturn( array() );

		$result = $this->service->run( array( $this->make_raw_item() ), $this->make_ship_to() );

		$this->assertNotEmpty( $result['warnings'] );
		$this->assertEmpty( $result['packages'] );
	}

	// -------------------------------------------------------------------------
	// run — ShipEngine path
	// -------------------------------------------------------------------------

	public function test_run_uses_shipengine_when_carrier_is_shipengine(): void {
		$this->settings->method( 'get_carriers' )->willReturn( array( 'shipengine' ) );
		$this->settings->method( 'is_sandbox_mode_enabled' )->willReturn( false );
		$this->packing_service->method( 'pack_items' )->willReturn( array( $this->make_packed_package() ) );
		$this->shipengine_service->method( 'build_test_package_plan' )->willReturn( $this->make_plan() );
		$this->shipstation_service->expects( $this->never() )->method( 'build_test_package_plan' );

		$result = $this->service->run( array( $this->make_raw_item() ), $this->make_ship_to() );

		$this->assertCount( 1, $result['packages'] );
		$this->assertSame( array( 'shipengine' ), $result['carriers'] );
	}

	// -------------------------------------------------------------------------
	// run — ShipStation path
	// -------------------------------------------------------------------------

	public function test_run_uses_shipstation_when_carrier_is_shipstation(): void {
		$this->settings->method( 'get_carriers' )->willReturn( array( 'shipstation' ) );
		$this->settings->method( 'is_sandbox_mode_enabled' )->willReturn( false );
		$this->packing_service->method( 'pack_items' )->willReturn( array( $this->make_packed_package() ) );
		$this->shipstation_service->method( 'build_test_package_plan' )->willReturn( $this->make_plan() );
		$this->shipengine_service->expects( $this->never() )->method( 'build_test_package_plan' );

		$result = $this->service->run( array( $this->make_raw_item() ), $this->make_ship_to() );

		$this->assertCount( 1, $result['packages'] );
		$this->assertSame( array( 'shipstation' ), $result['carriers'] );
	}

	// -------------------------------------------------------------------------
	// run — multi-carrier: picks cheapest across both providers
	// -------------------------------------------------------------------------

	public function test_run_picks_cheapest_rate_across_multiple_carriers(): void {
		$this->settings->method( 'get_carriers' )->willReturn( array( 'shipengine', 'shipstation' ) );
		$this->settings->method( 'is_sandbox_mode_enabled' )->willReturn( false );
		$this->packing_service->method( 'pack_items' )->willReturn( array( $this->make_packed_package() ) );
		// ShipEngine returns $7.99, ShipStation returns $5.50.
		$this->shipengine_service->method( 'build_test_package_plan' )->willReturn( $this->make_plan( 1, 7.99 ) );
		$this->shipstation_service->method( 'build_test_package_plan' )->willReturn( $this->make_plan( 1, 5.50 ) );

		$result = $this->service->run( array( $this->make_raw_item() ), $this->make_ship_to() );

		$this->assertCount( 1, $result['packages'] );
		$this->assertSame( 5.50, $result['total_rate_amount'] );
		$this->assertSame( array( 'shipengine', 'shipstation' ), $result['carriers'] );
	}

	public function test_run_multi_carrier_falls_back_when_one_fails(): void {
		$this->settings->method( 'get_carriers' )->willReturn( array( 'shipengine', 'shipstation' ) );
		$this->settings->method( 'is_sandbox_mode_enabled' )->willReturn( false );
		$this->packing_service->method( 'pack_items' )->willReturn( array( $this->make_packed_package() ) );
		// ShipEngine fails, ShipStation returns $5.50.
		$this->shipengine_service->method( 'build_test_package_plan' )->willReturn( array() );
		$this->shipstation_service->method( 'build_test_package_plan' )->willReturn( $this->make_plan( 1, 5.50 ) );

		$result = $this->service->run( array( $this->make_raw_item() ), $this->make_ship_to() );

		$this->assertCount( 1, $result['packages'] );
		$this->assertSame( 5.50, $result['total_rate_amount'] );
	}

	// -------------------------------------------------------------------------
	// run — totals and sandbox flag
	// -------------------------------------------------------------------------

	public function test_run_accumulates_total_rate_from_multiple_packages(): void {
		$this->settings->method( 'get_carriers' )->willReturn( array( 'shipengine' ) );
		$this->settings->method( 'is_sandbox_mode_enabled' )->willReturn( false );
		$this->packing_service->method( 'pack_items' )->willReturn( array(
			$this->make_packed_package(),
			$this->make_packed_package(),
		) );
		$this->shipengine_service->method( 'build_test_package_plan' )->willReturnOnConsecutiveCalls(
			$this->make_plan( 1, 7.50 ),
			$this->make_plan( 2, 5.25 )
		);

		$result = $this->service->run( array( $this->make_raw_item() ), $this->make_ship_to() );

		$this->assertSame( 12.75, $result['total_rate_amount'] );
		$this->assertCount( 2, $result['packages'] );
	}

	public function test_run_sets_sandbox_true_when_sandbox_mode_enabled(): void {
		$this->settings->method( 'get_carriers' )->willReturn( array( 'shipengine' ) );
		$this->settings->method( 'is_sandbox_mode_enabled' )->willReturn( true );
		$this->packing_service->method( 'pack_items' )->willReturn( array() );

		$result = $this->service->run( array( $this->make_raw_item() ), $this->make_ship_to() );

		$this->assertTrue( $result['sandbox'] );
	}

	public function test_run_sets_sandbox_false_when_sandbox_mode_disabled(): void {
		$this->settings->method( 'get_carriers' )->willReturn( array( 'shipengine' ) );
		$this->settings->method( 'is_sandbox_mode_enabled' )->willReturn( false );
		$this->packing_service->method( 'pack_items' )->willReturn( array() );

		$result = $this->service->run( array( $this->make_raw_item() ), $this->make_ship_to() );

		$this->assertFalse( $result['sandbox'] );
	}

	public function test_run_sets_currency_from_last_successful_plan(): void {
		$this->settings->method( 'get_carriers' )->willReturn( array( 'shipengine' ) );
		$this->settings->method( 'is_sandbox_mode_enabled' )->willReturn( false );
		$this->packing_service->method( 'pack_items' )->willReturn( array( $this->make_packed_package() ) );

		$plan              = $this->make_plan();
		$plan['currency']  = 'USD';
		$this->shipengine_service->method( 'build_test_package_plan' )->willReturn( $plan );

		$result = $this->service->run( array( $this->make_raw_item() ), $this->make_ship_to() );

		$this->assertSame( 'USD', $result['currency'] );
	}

	// -------------------------------------------------------------------------
	// run — package numbers are 1-based
	// -------------------------------------------------------------------------

	public function test_run_assigns_sequential_package_numbers(): void {
		$this->settings->method( 'get_carriers' )->willReturn( array( 'shipengine' ) );
		$this->settings->method( 'is_sandbox_mode_enabled' )->willReturn( false );
		$this->packing_service->method( 'pack_items' )->willReturn( array(
			$this->make_packed_package(),
			$this->make_packed_package(),
			$this->make_packed_package(),
		) );
		$this->shipengine_service->method( 'build_test_package_plan' )->willReturnCallback(
			function ( array $package, array $ship_to, int $number ) {
				return $this->make_plan( $number );
			}
		);

		$result = $this->service->run( array( $this->make_raw_item() ), $this->make_ship_to() );

		$numbers = array_column( $result['packages'], 'package_number' );
		$this->assertSame( array( 1, 2, 3 ), $numbers );
	}
}
