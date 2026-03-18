<?php
/**
 * Unit tests for PirateShip_Export.
 *
 * @package FK_USPS_Optimizer
 */

namespace FK_USPS_Optimizer\Tests\Unit;

use FK_USPS_Optimizer\Order_Plan_Service;
use FK_USPS_Optimizer\PirateShip_Export;
use FK_USPS_Optimizer\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PirateShip_Export.
 */
class PirateShipExportTest extends TestCase {

	/**
	 * Mocked settings.
	 *
	 * @var Settings|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $settings;

	/**
	 * Mocked order plan service.
	 *
	 * @var Order_Plan_Service|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $plan_service;

	/**
	 * System under test.
	 *
	 * @var PirateShip_Export
	 */
	private PirateShip_Export $export;

	protected function setUp(): void {
		$GLOBALS['_test_current_user_can'] = true;
		$GLOBALS['_test_wc_orders']        = array();

		$this->settings     = $this->createMock( Settings::class );
		$this->plan_service = $this->createMock( Order_Plan_Service::class );
		$this->export       = new PirateShip_Export( $this->settings, $this->plan_service );
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
		$ref = new \ReflectionMethod( $this->export, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $this->export, $args );
	}

	/**
	 * Build a mock WC_Order with shipping address data.
	 *
	 * @param int $id Order ID.
	 * @return \WC_Order|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function make_order( int $id = 100 ) {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( $id );
		$order->method( 'get_order_number' )->willReturn( (string) $id );
		$order->method( 'get_shipping_first_name' )->willReturn( 'Jane' );
		$order->method( 'get_shipping_last_name' )->willReturn( 'Doe' );
		$order->method( 'get_shipping_company' )->willReturn( 'ACME Corp' );
		$order->method( 'get_shipping_address_1' )->willReturn( '123 Main St' );
		$order->method( 'get_shipping_address_2' )->willReturn( 'Ste 4' );
		$order->method( 'get_shipping_city' )->willReturn( 'Tucson' );
		$order->method( 'get_shipping_state' )->willReturn( 'AZ' );
		$order->method( 'get_shipping_postcode' )->willReturn( '85701' );
		$order->method( 'get_shipping_country' )->willReturn( 'US' );
		return $order;
	}

	/**
	 * Build a minimal package plan array.
	 *
	 * @param array $overrides Field overrides.
	 * @return array Package plan.
	 */
	private function make_package_plan( array $overrides = array() ): array {
		return array_merge(
			array(
				'package_number' => 1,
				'package_code'   => 'package',
				'package_name'   => 'Custom Cubic Small',
				'mode'           => 'cubic',
				'rate_amount'    => 8.99,
				'currency'       => 'USD',
				'weight_oz'      => 22.0,
				'dimensions'     => array( 'length' => 8.0, 'width' => 8.0, 'height' => 6.0 ),
				'cubic_tier'     => '0.3',
				'packing_list'   => array( '2x Widget', '1x Gadget' ),
				'items'          => array(),
			),
			$overrides
		);
	}

	// -------------------------------------------------------------------------
	// build_row
	// -------------------------------------------------------------------------

	public function test_build_row_maps_order_shipping_address(): void {
		$order = $this->make_order( 101 );
		$plan  = $this->make_package_plan();
		$row   = $this->export->build_row( $order, $plan );

		$this->assertSame( '101', $row['order_number'] );
		$this->assertSame( 'Jane Doe', $row['recipient_name'] );
		$this->assertSame( 'ACME Corp', $row['company'] );
		$this->assertSame( '123 Main St', $row['address_1'] );
		$this->assertSame( 'Ste 4', $row['address_2'] );
		$this->assertSame( 'Tucson', $row['city'] );
		$this->assertSame( 'AZ', $row['state'] );
		$this->assertSame( '85701', $row['postal_code'] );
		$this->assertSame( 'US', $row['country'] );
	}

	public function test_build_row_sets_carrier_and_service(): void {
		$order = $this->make_order();
		$plan  = $this->make_package_plan();
		$row   = $this->export->build_row( $order, $plan );

		$this->assertSame( 'USPS', $row['carrier'] );
		$this->assertSame( 'Priority Mail', $row['service'] );
	}

	public function test_build_row_includes_package_dimensions_and_weight(): void {
		$order = $this->make_order();
		$plan  = $this->make_package_plan( array( 'weight_oz' => 18.5, 'dimensions' => array( 'length' => 10.0, 'width' => 8.0, 'height' => 5.0 ) ) );
		$row   = $this->export->build_row( $order, $plan );

		$this->assertSame( 18.5, $row['weight_oz'] );
		$this->assertSame( 10.0, $row['length'] );
		$this->assertSame( 8.0, $row['width'] );
		$this->assertSame( 5.0, $row['height'] );
	}

	public function test_build_row_combines_packing_list_as_semicolon_string(): void {
		$order = $this->make_order();
		$plan  = $this->make_package_plan( array( 'packing_list' => array( '2x Widget', '1x Gadget' ) ) );
		$row   = $this->export->build_row( $order, $plan );

		$this->assertSame( '2x Widget; 1x Gadget', $row['packing_list'] );
	}

	public function test_build_row_references_field_contains_order_and_package_number(): void {
		$order = $this->make_order( 202 );
		$plan  = $this->make_package_plan( array( 'package_number' => 3 ) );
		$row   = $this->export->build_row( $order, $plan );

		$this->assertStringContainsString( '202', $row['references'] );
		$this->assertStringContainsString( '3', $row['references'] );
	}

	public function test_build_row_sets_package_type_and_name(): void {
		$order = $this->make_order();
		$plan  = $this->make_package_plan( array(
			'package_code' => 'small_flat_rate_box',
			'package_name' => 'USPS Small Flat Rate Box',
		) );
		$row   = $this->export->build_row( $order, $plan );

		$this->assertSame( 'small_flat_rate_box', $row['package_type'] );
		$this->assertSame( 'USPS Small Flat Rate Box', $row['package_name'] );
	}

	public function test_build_row_trims_recipient_name(): void {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_order_number' )->willReturn( '1' );
		$order->method( 'get_shipping_first_name' )->willReturn( 'Bob' );
		$order->method( 'get_shipping_last_name' )->willReturn( 'Smith' );
		// Other address fields return '' from the stub.

		$row = $this->export->build_row( $order, $this->make_package_plan() );
		$this->assertSame( 'Bob Smith', $row['recipient_name'] );
	}

	// -------------------------------------------------------------------------
	// build_csv_headers (protected)
	// -------------------------------------------------------------------------

	public function test_build_csv_headers_returns_twenty_columns(): void {
		$headers = $this->call_protected( 'build_csv_headers' );
		$this->assertCount( 20, $headers );
	}

	public function test_build_csv_headers_contains_required_column_names(): void {
		$headers  = $this->call_protected( 'build_csv_headers' );
		$expected = array(
			'order_number', 'package_number', 'recipient_name', 'company',
			'address_1', 'address_2', 'city', 'state', 'postal_code', 'country',
			'carrier', 'service', 'package_type', 'package_name',
			'weight_oz', 'length', 'width', 'height', 'packing_list', 'references',
		);
		foreach ( $expected as $col ) {
			$this->assertArrayHasKey( $col, $headers, "CSV header missing column: $col" );
		}
	}

	public function test_build_row_keys_match_csv_headers(): void {
		$headers = $this->call_protected( 'build_csv_headers' );
		$order   = $this->make_order();
		$plan    = $this->make_package_plan();
		$row     = $this->export->build_row( $order, $plan );

		$header_keys = array_keys( $headers );
		$row_keys    = array_keys( $row );

		// Every header column must have a corresponding key in the row.
		foreach ( $header_keys as $col ) {
			$this->assertContains( $col, $row_keys, "Row missing CSV column: $col" );
		}
	}

	// -------------------------------------------------------------------------
	// handle_export – permission check
	// -------------------------------------------------------------------------

	public function test_handle_export_calls_wp_die_when_user_lacks_capability(): void {
		$GLOBALS['_test_current_user_can'] = false;

		$this->expectException( \RuntimeException::class );
		$this->export->handle_export();
	}

	// -------------------------------------------------------------------------
	// register (smoke test)
	// -------------------------------------------------------------------------

	public function test_register_runs_without_error(): void {
		$this->export->register();
		$this->assertTrue( true );
	}
}
