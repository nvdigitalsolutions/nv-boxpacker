<?php
/**
 * Unit tests for ShipEngine_Service.
 *
 * @package FK_USPS_Optimizer
 */

namespace FK_USPS_Optimizer\Tests\Unit;

use FK_USPS_Optimizer\Settings;
use FK_USPS_Optimizer\ShipEngine_Service;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ShipEngine_Service.
 */
class ShipEngineServiceTest extends TestCase {

	/**
	 * Mocked settings dependency.
	 *
	 * @var Settings|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $settings;

	/**
	 * System under test.
	 *
	 * @var ShipEngine_Service
	 */
	private ShipEngine_Service $service;

	protected function setUp(): void {
		$GLOBALS['_test_wp_remote_post'] = null;
		$GLOBALS['_test_wp_remote_get']  = null;
		$GLOBALS['_test_wc_logger']      = new \WC_Test_Logger();
		$GLOBALS['_test_wp_filters']     = array();
		$GLOBALS['_test_wp_options']     = array();

		$this->settings = $this->createMock( Settings::class );
		$this->service  = new ShipEngine_Service( $this->settings );
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
	 * Build a minimal box definition.
	 *
	 * @param array $overrides Field overrides.
	 * @return array Box definition.
	 */
	private function make_box( array $overrides = array() ): array {
		return array_merge(
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
			$overrides
		);
	}

	/**
	 * Build a minimal package array.
	 *
	 * @param array $overrides Field overrides.
	 * @return array Package data.
	 */
	private function make_package( array $overrides = array() ): array {
		return array_merge(
			array(
				'dimensions' => array( 'length' => 6.0, 'width' => 6.0, 'height' => 4.0 ),
				'weight_oz'  => 16.0,
				'items'      => array( array( 'name' => 'Widget', 'weight_oz' => 16.0 ) ),
			),
			$overrides
		);
	}

	/**
	 * Build a mock WC_Order for shipping address tests.
	 *
	 * @return \WC_Order|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function make_order() {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 42 );
		$order->method( 'get_shipping_first_name' )->willReturn( 'Jane' );
		$order->method( 'get_shipping_last_name' )->willReturn( 'Doe' );
		$order->method( 'get_shipping_company' )->willReturn( 'Acme' );
		$order->method( 'get_billing_phone' )->willReturn( '555-0100' );
		$order->method( 'get_shipping_address_1' )->willReturn( '1 Main St' );
		$order->method( 'get_shipping_address_2' )->willReturn( 'Apt 2' );
		$order->method( 'get_shipping_city' )->willReturn( 'Springfield' );
		$order->method( 'get_shipping_state' )->willReturn( 'IL' );
		$order->method( 'get_shipping_postcode' )->willReturn( '62701' );
		$order->method( 'get_shipping_country' )->willReturn( 'US' );
		return $order;
	}

	// -------------------------------------------------------------------------
	// is_cubic_eligible
	// -------------------------------------------------------------------------

	public function test_cubic_eligible_standard_small_package(): void {
		// 8×8×6 = 384 in³ = 0.222 ft³ < 0.5; 32 oz < 320; max side 8 < 18.
		$dims   = array( 'length' => 8.0, 'width' => 8.0, 'height' => 6.0 );
		$result = $this->call_protected( 'is_cubic_eligible', array( $dims, 32.0 ) );
		$this->assertTrue( $result );
	}

	public function test_cubic_eligible_exactly_half_cubic_foot(): void {
		// 12×12×12 = 1728 in³ = 1 ft³ > 0.5 → NOT eligible.
		$dims   = array( 'length' => 12.0, 'width' => 12.0, 'height' => 12.0 );
		$result = $this->call_protected( 'is_cubic_eligible', array( $dims, 32.0 ) );
		$this->assertFalse( $result );
	}

	public function test_cubic_eligible_exceeds_half_cubic_foot(): void {
		$dims   = array( 'length' => 18.0, 'width' => 18.0, 'height' => 10.0 );
		$result = $this->call_protected( 'is_cubic_eligible', array( $dims, 32.0 ) );
		$this->assertFalse( $result );
	}

	public function test_cubic_eligible_exceeds_320_oz(): void {
		$dims   = array( 'length' => 6.0, 'width' => 6.0, 'height' => 4.0 );
		$result = $this->call_protected( 'is_cubic_eligible', array( $dims, 321.0 ) );
		$this->assertFalse( $result );
	}

	public function test_cubic_eligible_exactly_320_oz(): void {
		$dims   = array( 'length' => 6.0, 'width' => 6.0, 'height' => 4.0 );
		$result = $this->call_protected( 'is_cubic_eligible', array( $dims, 320.0 ) );
		$this->assertTrue( $result );
	}

	public function test_cubic_eligible_side_exceeds_18_inches(): void {
		// 19-inch side disqualifies.
		$dims   = array( 'length' => 19.0, 'width' => 5.0, 'height' => 3.0 );
		$result = $this->call_protected( 'is_cubic_eligible', array( $dims, 10.0 ) );
		$this->assertFalse( $result );
	}

	public function test_cubic_eligible_exactly_18_inch_side(): void {
		// 18×5×3 = 270 in³ ≈ 0.156 ft³ – side == 18 is allowed.
		$dims   = array( 'length' => 18.0, 'width' => 5.0, 'height' => 3.0 );
		$result = $this->call_protected( 'is_cubic_eligible', array( $dims, 10.0 ) );
		$this->assertTrue( $result );
	}

	// -------------------------------------------------------------------------
	// get_cubic_tier
	// -------------------------------------------------------------------------

	public function test_cubic_tier_at_0_1(): void {
		// 6×6×6 = 216 in³ = 0.125 ft³ → tier 0.2 (> 0.1 but ≤ 0.2).
		// Let's pick something truly ≤ 0.1: need ≤ 172.8 in³.
		// 6×6×4 = 144 in³ = 0.083 ft³ → tier 0.1.
		$dims = array( 'length' => 6.0, 'width' => 6.0, 'height' => 4.0 );
		$this->assertSame( '0.1', $this->call_protected( 'get_cubic_tier', array( $dims ) ) );
	}

	public function test_cubic_tier_at_0_2(): void {
		// 8×8×6 = 384 in³ = 0.222 ft³ → tier 0.3? No: 0.222 ≤ 0.3, so tier '0.3'.
		// Need exactly ≤ 0.2: 345.6 in³ max. Try 8×8×5 = 320 → 0.185 ft³ ≤ 0.2.
		$dims = array( 'length' => 8.0, 'width' => 8.0, 'height' => 5.0 );
		$this->assertSame( '0.2', $this->call_protected( 'get_cubic_tier', array( $dims ) ) );
	}

	public function test_cubic_tier_at_0_3(): void {
		// 8×8×6 = 384 in³ = 0.222 ft³ → ≤ 0.3 → tier '0.3'.
		$dims = array( 'length' => 8.0, 'width' => 8.0, 'height' => 6.0 );
		$this->assertSame( '0.3', $this->call_protected( 'get_cubic_tier', array( $dims ) ) );
	}

	public function test_cubic_tier_at_0_4(): void {
		// 9×9×9 = 729 in³ = 0.422 ft³ → ≤ 0.4? No: 0.422 > 0.4 → tier '0.5'.
		// Need ≤ 0.4: 691.2 in³ max. Try 9×9×8 = 648 → 0.375 ft³ ≤ 0.4.
		$dims = array( 'length' => 9.0, 'width' => 9.0, 'height' => 8.0 );
		$this->assertSame( '0.4', $this->call_protected( 'get_cubic_tier', array( $dims ) ) );
	}

	public function test_cubic_tier_at_0_5(): void {
		// 10×10×10 = 1000 in³ = 0.579 ft³ > 0.5 → still returns '0.5' as max.
		$dims = array( 'length' => 10.0, 'width' => 10.0, 'height' => 10.0 );
		$this->assertSame( '0.5', $this->call_protected( 'get_cubic_tier', array( $dims ) ) );
	}

	// -------------------------------------------------------------------------
	// build_packing_list
	// -------------------------------------------------------------------------

	public function test_build_packing_list_aggregates_same_name(): void {
		$items = array(
			array( 'name' => 'Widget', 'weight_oz' => 4.0 ),
			array( 'name' => 'Widget', 'weight_oz' => 4.0 ),
			array( 'name' => 'Gadget', 'weight_oz' => 8.0 ),
		);
		$list = $this->call_protected( 'build_packing_list', array( $items ) );
		$this->assertContains( '2x Widget', $list );
		$this->assertContains( '1x Gadget', $list );
		$this->assertCount( 2, $list );
	}

	public function test_build_packing_list_single_item(): void {
		$items = array( array( 'name' => 'Lone Item', 'weight_oz' => 5.0 ) );
		$list  = $this->call_protected( 'build_packing_list', array( $items ) );
		$this->assertSame( array( '1x Lone Item' ), $list );
	}

	public function test_build_packing_list_empty_items_returns_empty_array(): void {
		$list = $this->call_protected( 'build_packing_list', array( array() ) );
		$this->assertSame( array(), $list );
	}

	// -------------------------------------------------------------------------
	// package_fits_box
	// -------------------------------------------------------------------------

	public function test_package_fits_box_when_all_dimensions_within_inner(): void {
		$package = $this->make_package( array( 'weight_oz' => 16.0 ) );
		$box     = $this->make_box();
		$result  = $this->call_protected( 'package_fits_box', array( $package, $box ) );
		$this->assertTrue( $result );
	}

	public function test_package_does_not_fit_when_too_long(): void {
		$package = $this->make_package( array( 'dimensions' => array( 'length' => 10.0, 'width' => 6.0, 'height' => 4.0 ) ) );
		$box     = $this->make_box();
		$result  = $this->call_protected( 'package_fits_box', array( $package, $box ) );
		$this->assertFalse( $result );
	}

	public function test_package_does_not_fit_when_too_heavy(): void {
		// max_weight is 20 lbs = 320 oz; 400 > 320.
		$package = $this->make_package( array( 'weight_oz' => 400.0 ) );
		$box     = $this->make_box();
		$result  = $this->call_protected( 'package_fits_box', array( $package, $box ) );
		$this->assertFalse( $result );
	}

	public function test_package_fits_at_exact_boundary(): void {
		$package = $this->make_package(
			array(
				'dimensions' => array( 'length' => 8.0, 'width' => 8.0, 'height' => 6.0 ),
				'weight_oz'  => 320.0,
			)
		);
		$box    = $this->make_box( array( 'max_weight' => 20.0 ) );
		$result = $this->call_protected( 'package_fits_box', array( $package, $box ) );
		$this->assertTrue( $result );
	}

	// -------------------------------------------------------------------------
	// build_candidates
	// -------------------------------------------------------------------------

	public function test_build_candidates_returns_matching_boxes(): void {
		$boxes = array(
			$this->make_box( array( 'reference' => 'Small', 'box_type' => 'cubic' ) ),
		);
		$this->settings->method( 'get_boxes' )->willReturn( $boxes );

		$package    = $this->make_package( array( 'weight_oz' => 16.0 ) );
		$candidates = $this->call_protected( 'build_candidates', array( $package ) );

		$this->assertCount( 1, $candidates );
		$this->assertSame( 'cubic', $candidates[0]['mode'] );
	}

	public function test_build_candidates_excludes_box_when_package_does_not_fit(): void {
		// Very heavy package; won't fit.
		$boxes = array( $this->make_box( array( 'max_weight' => 1.0 ) ) );
		$this->settings->method( 'get_boxes' )->willReturn( $boxes );

		$package    = $this->make_package( array( 'weight_oz' => 400.0 ) );
		$candidates = $this->call_protected( 'build_candidates', array( $package ) );

		$this->assertCount( 0, $candidates );
	}

	public function test_build_candidates_excludes_cubic_box_when_not_cubic_eligible(): void {
		// 18×18×10 cubic box – very large, > 0.5 ft³.
		$boxes = array(
			$this->make_box( array(
				'outer_width'  => 18, 'outer_length' => 18, 'outer_depth' => 10,
				'inner_width'  => 18, 'inner_length' => 18, 'inner_depth' => 10,
				'max_weight'   => 20,
				'box_type'     => 'cubic',
			) ),
		);
		$this->settings->method( 'get_boxes' )->willReturn( $boxes );

		// Package fits in dimensions but box itself exceeds cubic limit.
		$package    = $this->make_package(
			array(
				'dimensions' => array( 'length' => 8.0, 'width' => 8.0, 'height' => 6.0 ),
				'weight_oz'  => 16.0,
			)
		);
		$candidates = $this->call_protected( 'build_candidates', array( $package ) );

		$this->assertCount( 0, $candidates );
	}

	public function test_build_candidates_includes_flat_rate_box_without_cubic_check(): void {
		$boxes = array(
			$this->make_box( array(
				'box_type'     => 'flat_rate',
				'package_code' => 'small_flat_rate_box',
				'max_weight'   => 70,
			) ),
		);
		$this->settings->method( 'get_boxes' )->willReturn( $boxes );

		$package    = $this->make_package( array( 'weight_oz' => 16.0 ) );
		$candidates = $this->call_protected( 'build_candidates', array( $package ) );

		$this->assertCount( 1, $candidates );
		$this->assertSame( 'flat_rate_box', $candidates[0]['mode'] );
	}

	public function test_build_candidates_cubic_tier_is_set_for_cubic_box(): void {
		$boxes = array( $this->make_box() );
		$this->settings->method( 'get_boxes' )->willReturn( $boxes );

		$package    = $this->make_package( array( 'weight_oz' => 16.0 ) );
		$candidates = $this->call_protected( 'build_candidates', array( $package ) );

		$this->assertNotEmpty( $candidates[0]['cubic_tier'] );
	}

	public function test_build_candidates_cubic_tier_empty_for_flat_rate_box(): void {
		$boxes = array( $this->make_box( array( 'box_type' => 'flat_rate' ) ) );
		$this->settings->method( 'get_boxes' )->willReturn( $boxes );

		$package    = $this->make_package( array( 'weight_oz' => 16.0 ) );
		$candidates = $this->call_protected( 'build_candidates', array( $package ) );

		$this->assertSame( '', $candidates[0]['cubic_tier'] );
	}

	public function test_build_candidates_adds_empty_box_weight_to_total(): void {
		// empty_weight = 3 oz.
		$boxes = array( $this->make_box( array( 'empty_weight' => 3.0 ) ) );
		$this->settings->method( 'get_boxes' )->willReturn( $boxes );

		$package    = $this->make_package( array( 'weight_oz' => 10.0 ) );
		$candidates = $this->call_protected( 'build_candidates', array( $package ) );

		$this->assertSame( 13.0, $candidates[0]['weight_oz'] );
	}

	// -------------------------------------------------------------------------
	// get_ship_to_address
	// -------------------------------------------------------------------------

	public function test_get_ship_to_address_maps_order_fields(): void {
		$order  = $this->make_order();
		$result = $this->call_protected( 'get_ship_to_address', array( $order ) );

		$this->assertSame( 'Jane Doe', $result['name'] );
		$this->assertSame( 'Acme', $result['company_name'] );
		$this->assertSame( '555-0100', $result['phone'] );
		$this->assertSame( '1 Main St', $result['address_line1'] );
		$this->assertSame( 'Apt 2', $result['address_line2'] );
		$this->assertSame( 'Springfield', $result['city_locality'] );
		$this->assertSame( 'IL', $result['state_province'] );
		$this->assertSame( '62701', $result['postal_code'] );
		$this->assertSame( 'US', $result['country_code'] );
		$this->assertSame( 'unknown', $result['address_residential_indicator'] );
	}

	public function test_get_ship_to_address_defaults_country_to_us_when_empty(): void {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_shipping_first_name' )->willReturn( 'John' );
		$order->method( 'get_shipping_last_name' )->willReturn( 'Smith' );
		$order->method( 'get_shipping_country' )->willReturn( '' );
		// Other methods return '' by default via the stub class.

		$result = $this->call_protected( 'get_ship_to_address', array( $order ) );
		$this->assertSame( 'US', $result['country_code'] );
	}

	// -------------------------------------------------------------------------
	// request_rate
	// -------------------------------------------------------------------------

	public function test_request_rate_returns_false_when_api_key_missing(): void {
		$this->settings->method( 'get_shipengine_api_key' )->willReturn( '' );
		$this->settings->method( 'get_shipengine_carrier_id' )->willReturn( 'carrier_abc' );
		$this->settings->method( 'is_debug_logging_enabled' )->willReturn( true );

		$order     = $this->make_order();
		$candidate = $this->make_test_candidate();
		$result    = $this->call_protected( 'request_rate', array( $order, $candidate ) );

		$this->assertFalse( $result['success'] );
	}

	public function test_request_rate_returns_false_when_carrier_id_missing(): void {
		$this->settings->method( 'get_shipengine_api_key' )->willReturn( 'key_abc' );
		$this->settings->method( 'get_shipengine_carrier_id' )->willReturn( '' );
		$this->settings->method( 'is_debug_logging_enabled' )->willReturn( false );

		$order     = $this->make_order();
		$candidate = $this->make_test_candidate();
		$result    = $this->call_protected( 'request_rate', array( $order, $candidate ) );

		$this->assertFalse( $result['success'] );
	}

	public function test_request_rate_returns_false_on_wp_error(): void {
		$this->settings->method( 'get_shipengine_api_key' )->willReturn( 'key' );
		$this->settings->method( 'get_shipengine_carrier_id' )->willReturn( 'carrier' );
		$this->settings->method( 'is_debug_logging_enabled' )->willReturn( true );

		$GLOBALS['_test_wp_remote_post'] = new \WP_Error( 'http_request_failed', 'cURL error' );

		$order     = $this->make_order();
		$candidate = $this->make_test_candidate();
		$result    = $this->call_protected( 'request_rate', array( $order, $candidate ) );

		$this->assertFalse( $result['success'] );
		$this->assertNotEmpty( $GLOBALS['_test_wc_logger']->logs );
	}

	public function test_request_rate_returns_false_on_non_success_http_status(): void {
		$this->settings->method( 'get_shipengine_api_key' )->willReturn( 'key' );
		$this->settings->method( 'get_shipengine_carrier_id' )->willReturn( 'carrier' );
		$this->settings->method( 'is_debug_logging_enabled' )->willReturn( true );

		$GLOBALS['_test_wp_remote_post'] = array(
			'response' => array( 'code' => 401 ),
			'body'     => json_encode( array( 'errors' => array( 'Unauthorized' ) ) ),
		);

		$order     = $this->make_order();
		$candidate = $this->make_test_candidate();
		$result    = $this->call_protected( 'request_rate', array( $order, $candidate ) );

		$this->assertFalse( $result['success'] );
	}

	public function test_request_rate_returns_false_when_no_rates_returned(): void {
		$this->settings->method( 'get_shipengine_api_key' )->willReturn( 'key' );
		$this->settings->method( 'get_shipengine_carrier_id' )->willReturn( 'carrier' );
		$this->settings->method( 'is_debug_logging_enabled' )->willReturn( true );

		$GLOBALS['_test_wp_remote_post'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array( 'rate_response' => array( 'rates' => array() ) ) ),
		);

		$order     = $this->make_order();
		$candidate = $this->make_test_candidate();
		$result    = $this->call_protected( 'request_rate', array( $order, $candidate ) );

		$this->assertFalse( $result['success'] );
	}

	public function test_request_rate_returns_cheapest_rate(): void {
		$this->settings->method( 'get_shipengine_api_key' )->willReturn( 'key' );
		$this->settings->method( 'get_shipengine_carrier_id' )->willReturn( 'carrier' );
		$this->settings->method( 'is_debug_logging_enabled' )->willReturn( false );
		$this->settings->method( 'get_shipengine_service_code' )->willReturn( 'usps_priority_mail' );
		$this->settings->method( 'get_ship_from_address' )->willReturn( array(
			'address_line1' => '1 From St', 'city_locality' => 'City',
			'state_province' => 'CA', 'postal_code' => '90210', 'country_code' => 'US',
		) );

		$rates = array(
			array( 'shipping_amount' => array( 'amount' => 9.99,  'currency' => 'USD' ) ),
			array( 'shipping_amount' => array( 'amount' => 7.50,  'currency' => 'USD' ) ),
			array( 'shipping_amount' => array( 'amount' => 12.00, 'currency' => 'USD' ) ),
		);

		$GLOBALS['_test_wp_remote_post'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array( 'rate_response' => array( 'rates' => $rates ) ) ),
		);

		$order     = $this->make_order();
		$candidate = $this->make_test_candidate();
		$result    = $this->call_protected( 'request_rate', array( $order, $candidate ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 7.50, (float) $result['rate']['shipping_amount']['amount'] );
	}

	// -------------------------------------------------------------------------
	// build_package_plan
	// -------------------------------------------------------------------------

	public function test_build_package_plan_returns_empty_when_no_candidates(): void {
		$this->settings->method( 'get_boxes' )->willReturn( array() );

		$order   = $this->make_order();
		$package = $this->make_package();
		$result  = $this->service->build_package_plan( $order, $package, 1 );

		$this->assertSame( array(), $result );
	}

	public function test_build_package_plan_selects_cheapest_candidate(): void {
		// Both boxes must be cubic-eligible (≤ 0.5 ft³). Box A: 8×8×6 (0.222 ft³);
		// Box B: 9×9×7 (0.328 ft³). The second API call returns a cheaper rate.
		$boxes = array(
			$this->make_box( array( 'reference' => 'Box A', 'package_name' => 'Box A' ) ),
			$this->make_box( array( 'reference' => 'Box B', 'package_name' => 'Box B', 'outer_width' => 9, 'outer_length' => 9, 'outer_depth' => 7, 'inner_width' => 9, 'inner_length' => 9, 'inner_depth' => 7 ) ),
		);
		$this->settings->method( 'get_boxes' )->willReturn( $boxes );
		$this->settings->method( 'get_shipengine_api_key' )->willReturn( 'key' );
		$this->settings->method( 'get_shipengine_carrier_id' )->willReturn( 'carrier' );
		$this->settings->method( 'is_debug_logging_enabled' )->willReturn( false );
		$this->settings->method( 'get_shipengine_service_code' )->willReturn( 'usps_priority_mail' );
		$this->settings->method( 'get_ship_from_address' )->willReturn( array(
			'address_line1' => '1 From St', 'city_locality' => 'City',
			'state_province' => 'CA', 'postal_code' => '90210', 'country_code' => 'US',
		) );

		$call_count = 0;
		$GLOBALS['_test_wp_remote_post'] = function () use ( &$call_count ) {
			++$call_count;
			$amount = 1 === $call_count ? 8.99 : 6.50; // Second call cheaper.
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode( array(
					'rate_response' => array(
						'rates' => array( array( 'shipping_amount' => array( 'amount' => $amount, 'currency' => 'USD' ) ) ),
					),
				) ),
			);
		};

		$order   = $this->make_order();
		$package = $this->make_package( array( 'weight_oz' => 16.0 ) );
		$result  = $this->service->build_package_plan( $order, $package, 1 );

		$this->assertSame( 1, $result['package_number'] );
		$this->assertSame( 6.50, $result['rate_amount'] );
		$this->assertSame( 'Box B', $result['package_name'] );
	}

	public function test_build_package_plan_populates_all_required_keys(): void {
		$boxes = array( $this->make_box() );
		$this->settings->method( 'get_boxes' )->willReturn( $boxes );
		$this->settings->method( 'get_shipengine_api_key' )->willReturn( 'key' );
		$this->settings->method( 'get_shipengine_carrier_id' )->willReturn( 'carrier' );
		$this->settings->method( 'is_debug_logging_enabled' )->willReturn( false );
		$this->settings->method( 'get_shipengine_service_code' )->willReturn( 'usps_priority_mail' );
		$this->settings->method( 'get_ship_from_address' )->willReturn( array(
			'address_line1' => '1 From St', 'city_locality' => 'City',
			'state_province' => 'CA', 'postal_code' => '90210', 'country_code' => 'US',
		) );

		$GLOBALS['_test_wp_remote_post'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array(
				'rate_response' => array(
					'rates' => array( array( 'shipping_amount' => array( 'amount' => 7.99, 'currency' => 'USD' ) ) ),
				),
			) ),
		);

		$order   = $this->make_order();
		$package = $this->make_package();
		$result  = $this->service->build_package_plan( $order, $package, 2 );

		$required_keys = array(
			'package_number', 'mode', 'package_code', 'package_name',
			'service_code', 'rate_amount', 'currency', 'weight_oz',
			'dimensions', 'cubic_tier', 'packing_list', 'items',
		);
		foreach ( $required_keys as $key ) {
			$this->assertArrayHasKey( $key, $result, "Missing key: $key" );
		}
		$this->assertSame( 2, $result['package_number'] );
		$this->assertSame( 'usps_priority_mail', $result['service_code'] );
		$this->assertSame( 'USD', $result['currency'] );
	}

	// -------------------------------------------------------------------------
	// build_all_test_package_plans
	// -------------------------------------------------------------------------

	public function test_build_all_test_package_plans_returns_all_candidates_sorted(): void {
		$box_a = $this->make_box( array( 'reference' => 'Box A', 'package_name' => 'Box A' ) );
		$box_b = $this->make_box( array( 'reference' => 'Box B', 'package_name' => 'Box B',
			'outer_width' => 9, 'outer_length' => 9, 'outer_depth' => 7,
			'inner_width' => 9, 'inner_length' => 9, 'inner_depth' => 7 ) );

		$this->settings->method( 'get_boxes' )->willReturn( array( $box_a, $box_b ) );
		$this->settings->method( 'get_shipengine_api_key' )->willReturn( 'key' );
		$this->settings->method( 'get_shipengine_carrier_id' )->willReturn( 'carrier' );
		$this->settings->method( 'is_debug_logging_enabled' )->willReturn( false );
		$this->settings->method( 'get_shipengine_service_code' )->willReturn( 'usps_priority_mail' );
		$this->settings->method( 'get_ship_from_address' )->willReturn( array(
			'address_line1' => '1 From St', 'city_locality' => 'City',
			'state_province' => 'CA', 'postal_code' => '90210', 'country_code' => 'US',
		) );

		$call = 0;
		$GLOBALS['_test_wp_remote_post'] = function () use ( &$call ) {
			++$call;
			$amount = 1 === $call ? 9.00 : 6.00;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode( array(
					'rate_response' => array(
						'rates' => array( array( 'shipping_amount' => array( 'amount' => $amount, 'currency' => 'USD' ) ) ),
					),
				) ),
			);
		};

		$ship_to = array( 'postal_code' => '78701', 'country_code' => 'US' );
		$plans   = $this->service->build_all_test_package_plans( $this->make_package(), $ship_to, 1 );

		$this->assertCount( 2, $plans );
		$this->assertSame( 6.00, $plans[0]['rate_amount'] );
		$this->assertSame( 9.00, $plans[1]['rate_amount'] );
	}

	public function test_build_all_test_package_plans_returns_empty_when_no_candidates(): void {
		$this->settings->method( 'get_boxes' )->willReturn( array() );

		$ship_to = array( 'postal_code' => '78701', 'country_code' => 'US' );
		$plans   = $this->service->build_all_test_package_plans( $this->make_package(), $ship_to, 1 );

		$this->assertSame( array(), $plans );
	}

	public function test_build_all_test_package_plans_uses_configured_service_code(): void {
		$this->settings->method( 'get_boxes' )->willReturn( array( $this->make_box() ) );
		$this->settings->method( 'get_shipengine_api_key' )->willReturn( 'key' );
		$this->settings->method( 'get_shipengine_carrier_id' )->willReturn( 'carrier' );
		$this->settings->method( 'is_debug_logging_enabled' )->willReturn( false );
		$this->settings->method( 'get_shipengine_service_code' )->willReturn( 'usps_ground_advantage' );
		$this->settings->method( 'get_ship_from_address' )->willReturn( array(
			'address_line1' => '1 From St', 'city_locality' => 'City',
			'state_province' => 'CA', 'postal_code' => '90210', 'country_code' => 'US',
		) );

		$GLOBALS['_test_wp_remote_post'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array(
				'rate_response' => array(
					'rates' => array( array( 'shipping_amount' => array( 'amount' => 5.50, 'currency' => 'USD' ) ) ),
				),
			) ),
		);

		$ship_to = array( 'postal_code' => '78701', 'country_code' => 'US' );
		$plans   = $this->service->build_all_test_package_plans( $this->make_package(), $ship_to, 1 );

		$this->assertCount( 1, $plans );
		$this->assertSame( 'usps_ground_advantage', $plans[0]['service_code'] );
	}

	// -------------------------------------------------------------------------
	// log
	// -------------------------------------------------------------------------

	public function test_log_does_nothing_when_debug_disabled(): void {
		$this->settings->method( 'is_debug_logging_enabled' )->willReturn( false );
		$this->service->log( 'Test message', array( 'foo' => 'bar' ) );
		// Logger should have no entries.
		$this->assertCount( 0, $GLOBALS['_test_wc_logger']->logs );
	}

	public function test_log_writes_to_logger_when_debug_enabled(): void {
		$this->settings->method( 'is_debug_logging_enabled' )->willReturn( true );
		$this->service->log( 'Test message', array( 'order_id' => 99 ) );
		$this->assertCount( 1, $GLOBALS['_test_wc_logger']->logs );
		$this->assertStringContainsString( 'Test message', $GLOBALS['_test_wc_logger']->logs[0]['message'] );
	}

	public function test_log_appends_context_to_message(): void {
		$this->settings->method( 'is_debug_logging_enabled' )->willReturn( true );
		$this->service->log( 'Event happened', array( 'key' => 'value' ) );
		$logged = $GLOBALS['_test_wc_logger']->logs[0]['message'];
		$this->assertStringContainsString( 'key', $logged );
		$this->assertStringContainsString( 'value', $logged );
	}

	// -------------------------------------------------------------------------
	// test_connection
	// -------------------------------------------------------------------------

	public function test_connection_returns_error_when_api_key_missing(): void {
		$this->settings->method( 'get_shipengine_api_key' )->willReturn( '' );
		$this->settings->method( 'get_shipengine_carrier_id' )->willReturn( 'se-123' );

		$result = $this->service->test_connection();

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'API key', $result['message'] );
	}

	public function test_connection_returns_error_when_carrier_id_missing(): void {
		$this->settings->method( 'get_shipengine_api_key' )->willReturn( 'TEST_abc' );
		$this->settings->method( 'get_shipengine_carrier_id' )->willReturn( '' );

		$result = $this->service->test_connection();

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Carrier ID', $result['message'] );
	}

	public function test_connection_returns_error_on_wp_error(): void {
		$this->settings->method( 'get_shipengine_api_key' )->willReturn( 'TEST_abc' );
		$this->settings->method( 'get_shipengine_carrier_id' )->willReturn( 'se-123' );

		$GLOBALS['_test_wp_remote_get'] = new \WP_Error( 'http_request_failed', 'cURL error 28' );

		$result = $this->service->test_connection();

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'cURL error 28', $result['message'] );
	}

	public function test_connection_returns_error_on_401_response(): void {
		$this->settings->method( 'get_shipengine_api_key' )->willReturn( 'bad_key' );
		$this->settings->method( 'get_shipengine_carrier_id' )->willReturn( 'se-123' );

		$GLOBALS['_test_wp_remote_get'] = array(
			'response' => array( 'code' => 401 ),
			'body'     => json_encode( array( 'errors' => array( 'Unauthorized' ) ) ),
		);

		$result = $this->service->test_connection();

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Invalid ShipEngine API key', $result['message'] );
	}

	public function test_connection_returns_error_on_non_success_status(): void {
		$this->settings->method( 'get_shipengine_api_key' )->willReturn( 'TEST_abc' );
		$this->settings->method( 'get_shipengine_carrier_id' )->willReturn( 'se-123' );

		$GLOBALS['_test_wp_remote_get'] = array(
			'response' => array( 'code' => 500 ),
			'body'     => '{}',
		);

		$result = $this->service->test_connection();

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( '500', $result['message'] );
	}

	public function test_connection_returns_error_when_carrier_id_not_found(): void {
		$this->settings->method( 'get_shipengine_api_key' )->willReturn( 'TEST_abc' );
		$this->settings->method( 'get_shipengine_carrier_id' )->willReturn( 'se-999' );

		$GLOBALS['_test_wp_remote_get'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array(
				'carriers' => array(
					array( 'carrier_id' => 'se-123', 'carrier_code' => 'stamps_com', 'friendly_name' => 'USPS' ),
				),
			) ),
		);

		$result = $this->service->test_connection();

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'se-999', $result['message'] );
	}

	public function test_connection_returns_error_for_non_usps_carrier(): void {
		$this->settings->method( 'get_shipengine_api_key' )->willReturn( 'TEST_abc' );
		$this->settings->method( 'get_shipengine_carrier_id' )->willReturn( 'se-123' );

		$GLOBALS['_test_wp_remote_get'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array(
				'carriers' => array(
					array( 'carrier_id' => 'se-123', 'carrier_code' => 'fedex', 'friendly_name' => 'FedEx' ),
				),
			) ),
		);

		$result = $this->service->test_connection();

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'USPS carrier', $result['message'] );
		$this->assertSame( 'FedEx', $result['carrier_name'] );
	}

	public function test_connection_succeeds_for_stamps_com_carrier(): void {
		$this->settings->method( 'get_shipengine_api_key' )->willReturn( 'TEST_abc' );
		$this->settings->method( 'get_shipengine_carrier_id' )->willReturn( 'se-123' );

		$GLOBALS['_test_wp_remote_get'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array(
				'carriers' => array(
					array( 'carrier_id' => 'se-123', 'carrier_code' => 'stamps_com', 'friendly_name' => 'USPS' ),
				),
			) ),
		);

		$result = $this->service->test_connection();

		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'USPS', $result['message'] );
		$this->assertSame( 'USPS', $result['carrier_name'] );
	}

	public function test_connection_succeeds_for_usps_carrier_code(): void {
		$this->settings->method( 'get_shipengine_api_key' )->willReturn( 'TEST_abc' );
		$this->settings->method( 'get_shipengine_carrier_id' )->willReturn( 'se-456' );

		$GLOBALS['_test_wp_remote_get'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array(
				'carriers' => array(
					array( 'carrier_id' => 'se-456', 'carrier_code' => 'usps', 'friendly_name' => 'USPS Priority Mail' ),
				),
			) ),
		);

		$result = $this->service->test_connection();

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'USPS Priority Mail', $result['carrier_name'] );
	}

	public function test_connection_result_empty_carrier_name_on_failure(): void {
		$this->settings->method( 'get_shipengine_api_key' )->willReturn( '' );
		$this->settings->method( 'get_shipengine_carrier_id' )->willReturn( '' );

		$result = $this->service->test_connection();

		$this->assertSame( '', $result['carrier_name'] );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a minimal candidate array for request_rate tests.
	 *
	 * @return array Candidate data.
	 */
	private function make_test_candidate(): array {
		return array(
			'mode'         => 'cubic',
			'package_code' => 'package',
			'package_name' => 'Test Cubic',
			'dimensions'   => array( 'length' => 8.0, 'width' => 8.0, 'height' => 6.0 ),
			'weight_oz'    => 19.0,
			'cubic_tier'   => '0.3',
			'box'          => $this->make_box(),
		);
	}

	// -------------------------------------------------------------------------
	// get_service_label
	// -------------------------------------------------------------------------

	public function test_get_service_label_priority(): void {
		$this->settings->method( 'get_shipengine_service_code' )->willReturn( 'usps_priority_mail' );
		$this->assertSame( 'USPS Priority', $this->service->get_service_label() );
	}

	public function test_get_service_label_priority_express(): void {
		$this->settings->method( 'get_shipengine_service_code' )->willReturn( 'usps_priority_mail_express' );
		$this->assertSame( 'USPS Priority Express', $this->service->get_service_label() );
	}

	public function test_get_service_label_first_class(): void {
		$this->settings->method( 'get_shipengine_service_code' )->willReturn( 'usps_first_class_mail' );
		$this->assertSame( 'USPS First Class', $this->service->get_service_label() );
	}

	public function test_get_service_label_unknown_code(): void {
		$this->settings->method( 'get_shipengine_service_code' )->willReturn( 'some_future_service' );
		$this->assertSame( 'USPS Some Future Service', $this->service->get_service_label() );
	}

	public function test_get_service_label_ground_advantage(): void {
		$this->settings->method( 'get_shipengine_service_code' )->willReturn( 'usps_ground_advantage' );
		$this->assertSame( 'USPS Ground Advantage', $this->service->get_service_label() );
	}

	public function test_get_service_label_override_service_code(): void {
		$this->settings->method( 'get_shipengine_service_code' )->willReturn( 'usps_priority_mail' );
		$this->assertSame( 'USPS Ground Advantage', $this->service->get_service_label( 'usps_ground_advantage' ) );
	}

	public function test_get_service_label_override_empty_falls_back_to_settings(): void {
		$this->settings->method( 'get_shipengine_service_code' )->willReturn( 'usps_first_class_mail' );
		$this->assertSame( 'USPS First Class', $this->service->get_service_label( '' ) );
	}

	// -------------------------------------------------------------------------
	// estimated_delivery_date in build_package_plan
	// -------------------------------------------------------------------------

	public function test_build_package_plan_includes_estimated_delivery_date_when_present(): void {
		$boxes = array( $this->make_box() );
		$this->settings->method( 'get_boxes' )->willReturn( $boxes );
		$this->settings->method( 'get_shipengine_api_key' )->willReturn( 'key' );
		$this->settings->method( 'get_shipengine_carrier_id' )->willReturn( 'carrier' );
		$this->settings->method( 'is_debug_logging_enabled' )->willReturn( false );
		$this->settings->method( 'get_shipengine_service_code' )->willReturn( 'usps_priority_mail' );
		$this->settings->method( 'get_ship_from_address' )->willReturn( array(
			'address_line1' => '1 From St', 'city_locality' => 'City',
			'state_province' => 'CA', 'postal_code' => '90210', 'country_code' => 'US',
		) );

		$GLOBALS['_test_wp_remote_post'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array(
				'rate_response' => array(
					'rates' => array(
						array(
							'shipping_amount'         => array( 'amount' => 7.99, 'currency' => 'USD' ),
							'estimated_delivery_date' => '2024-01-15T00:00:00Z',
						),
					),
				),
			) ),
		);

		$order   = $this->make_order();
		$package = $this->make_package();
		$result  = $this->service->build_package_plan( $order, $package, 1 );

		$this->assertArrayHasKey( 'estimated_delivery_date', $result );
		$this->assertSame( '2024-01-15T00:00:00Z', $result['estimated_delivery_date'] );
	}

	public function test_build_package_plan_estimated_delivery_date_defaults_to_empty_string(): void {
		$boxes = array( $this->make_box() );
		$this->settings->method( 'get_boxes' )->willReturn( $boxes );
		$this->settings->method( 'get_shipengine_api_key' )->willReturn( 'key' );
		$this->settings->method( 'get_shipengine_carrier_id' )->willReturn( 'carrier' );
		$this->settings->method( 'is_debug_logging_enabled' )->willReturn( false );
		$this->settings->method( 'get_shipengine_service_code' )->willReturn( 'usps_priority_mail' );
		$this->settings->method( 'get_ship_from_address' )->willReturn( array(
			'address_line1' => '1 From St', 'city_locality' => 'City',
			'state_province' => 'CA', 'postal_code' => '90210', 'country_code' => 'US',
		) );

		$GLOBALS['_test_wp_remote_post'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array(
				'rate_response' => array(
					'rates' => array(
						array( 'shipping_amount' => array( 'amount' => 7.99, 'currency' => 'USD' ) ),
					),
				),
			) ),
		);

		$order   = $this->make_order();
		$package = $this->make_package();
		$result  = $this->service->build_package_plan( $order, $package, 1 );

		$this->assertSame( '', $result['estimated_delivery_date'] );
	}

	public function test_build_all_test_package_plans_includes_estimated_delivery_date(): void {
		$this->settings->method( 'get_boxes' )->willReturn( array( $this->make_box() ) );
		$this->settings->method( 'get_shipengine_api_key' )->willReturn( 'key' );
		$this->settings->method( 'get_shipengine_carrier_id' )->willReturn( 'carrier' );
		$this->settings->method( 'is_debug_logging_enabled' )->willReturn( false );
		$this->settings->method( 'get_shipengine_service_code' )->willReturn( 'usps_priority_mail' );
		$this->settings->method( 'get_ship_from_address' )->willReturn( array(
			'address_line1' => '1 From St', 'city_locality' => 'City',
			'state_province' => 'CA', 'postal_code' => '90210', 'country_code' => 'US',
		) );

		$GLOBALS['_test_wp_remote_post'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array(
				'rate_response' => array(
					'rates' => array(
						array(
							'shipping_amount'         => array( 'amount' => 7.99, 'currency' => 'USD' ),
							'estimated_delivery_date' => '2024-01-20T00:00:00Z',
						),
					),
				),
			) ),
		);

		$ship_to = array( 'postal_code' => '78701', 'country_code' => 'US' );
		$plans   = $this->service->build_all_test_package_plans( $this->make_package(), $ship_to, 1 );

		$this->assertCount( 1, $plans );
		$this->assertSame( '2024-01-20T00:00:00Z', $plans[0]['estimated_delivery_date'] );
	}

	// -------------------------------------------------------------------------
	// extract_delivery_date (protected)
	// -------------------------------------------------------------------------

	public function test_extract_delivery_date_returns_iso_string_when_present(): void {
		$rate   = array( 'estimated_delivery_date' => '2024-02-10T00:00:00Z' );
		$result = $this->call_protected( 'extract_delivery_date', array( $rate ) );
		$this->assertSame( '2024-02-10T00:00:00Z', $result );
	}

	public function test_extract_delivery_date_falls_back_to_delivery_days(): void {
		// current_time() stub returns '2024-01-01 00:00:00'.
		$rate   = array( 'estimated_delivery_date' => null, 'delivery_days' => 3 );
		$result = $this->call_protected( 'extract_delivery_date', array( $rate ) );
		$this->assertSame( '2024-01-04', $result );
	}

	public function test_extract_delivery_date_returns_empty_when_no_fields(): void {
		$rate   = array( 'shipping_amount' => array( 'amount' => 7.99 ) );
		$result = $this->call_protected( 'extract_delivery_date', array( $rate ) );
		$this->assertSame( '', $result );
	}

	public function test_extract_delivery_date_returns_empty_when_both_null(): void {
		$rate   = array( 'estimated_delivery_date' => null, 'delivery_days' => null );
		$result = $this->call_protected( 'extract_delivery_date', array( $rate ) );
		$this->assertSame( '', $result );
	}

	// -------------------------------------------------------------------------
	// compute_delivery_date (protected)
	// -------------------------------------------------------------------------

	public function test_compute_delivery_date_returns_empty_for_zero_days(): void {
		$result = $this->call_protected( 'compute_delivery_date', array( 0 ) );
		$this->assertSame( '', $result );
	}

	public function test_compute_delivery_date_returns_empty_for_negative_days(): void {
		$result = $this->call_protected( 'compute_delivery_date', array( -1 ) );
		$this->assertSame( '', $result );
	}

	public function test_compute_delivery_date_adds_days_from_current_time(): void {
		// current_time() stub returns '2024-01-01 00:00:00'.
		$result = $this->call_protected( 'compute_delivery_date', array( 3 ) );
		$this->assertSame( '2024-01-04', $result );
	}

	public function test_build_package_plan_uses_delivery_days_fallback(): void {
		$boxes = array( $this->make_box() );
		$this->settings->method( 'get_boxes' )->willReturn( $boxes );
		$this->settings->method( 'get_shipengine_api_key' )->willReturn( 'key' );
		$this->settings->method( 'get_shipengine_carrier_id' )->willReturn( 'carrier' );
		$this->settings->method( 'is_debug_logging_enabled' )->willReturn( false );
		$this->settings->method( 'get_shipengine_service_code' )->willReturn( 'usps_priority_mail' );
		$this->settings->method( 'get_ship_from_address' )->willReturn( array(
			'address_line1' => '1 From St', 'city_locality' => 'City',
			'state_province' => 'CA', 'postal_code' => '90210', 'country_code' => 'US',
		) );

		$GLOBALS['_test_wp_remote_post'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array(
				'rate_response' => array(
					'rates' => array(
						array(
							'shipping_amount'         => array( 'amount' => 7.99, 'currency' => 'USD' ),
							'estimated_delivery_date' => null,
							'delivery_days'           => 2,
						),
					),
				),
			) ),
		);

		$order   = $this->make_order();
		$package = $this->make_package();
		$result  = $this->service->build_package_plan( $order, $package, 1 );

		$this->assertArrayHasKey( 'estimated_delivery_date', $result );
		// current_time() stub returns '2024-01-01 00:00:00', so 2 days → '2024-01-03'.
		$this->assertSame( '2024-01-03', $result['estimated_delivery_date'] );
	}

	// -------------------------------------------------------------------------
	// get_default_transit_days (protected)
	// -------------------------------------------------------------------------

	public function test_get_default_transit_days_returns_days_for_usps_priority(): void {
		$result = $this->call_protected( 'get_default_transit_days', array( 'usps_priority_mail' ) );
		$this->assertSame( 3, $result );
	}

	public function test_get_default_transit_days_returns_zero_for_unknown_code(): void {
		$result = $this->call_protected( 'get_default_transit_days', array( 'unknown_service' ) );
		$this->assertSame( 0, $result );
	}

	public function test_extract_delivery_date_falls_back_to_service_code_default(): void {
		$this->settings->method( 'is_use_default_transit_days_enabled' )->willReturn( true );
		// No delivery date fields, but service_code is a known USPS service.
		$rate   = array( 'service_code' => 'usps_priority_mail', 'shipping_amount' => array( 'amount' => 7.99 ) );
		$result = $this->call_protected( 'extract_delivery_date', array( $rate ) );
		// current_time() stub returns '2024-01-01 00:00:00'; priority_mail defaults to 3 days.
		$this->assertSame( '2024-01-04', $result );
	}

	public function test_extract_delivery_date_returns_empty_for_unknown_service_code(): void {
		$this->settings->method( 'is_use_default_transit_days_enabled' )->willReturn( true );
		$rate   = array( 'service_code' => 'some_unknown_service' );
		$result = $this->call_protected( 'extract_delivery_date', array( $rate ) );
		$this->assertSame( '', $result );
	}

	public function test_extract_delivery_date_skips_fallback_when_setting_disabled(): void {
		$this->settings->method( 'is_use_default_transit_days_enabled' )->willReturn( false );
		// Known service code but setting is off — should return empty.
		$rate   = array( 'service_code' => 'usps_priority_mail' );
		$result = $this->call_protected( 'extract_delivery_date', array( $rate ) );
		$this->assertSame( '', $result );
	}

	public function test_compute_delivery_date_adds_buffer_days(): void {
		$this->settings->method( 'get_transit_days_buffer' )->willReturn( 2 );
		// current_time() stub returns '2024-01-01 00:00:00' (Monday).
		// 3 transit calendar days → Thu Jan 4; + 2 business days (Fri, Mon) → 2024-01-08.
		$result = $this->call_protected( 'compute_delivery_date', array( 3 ) );
		$this->assertSame( '2024-01-08', $result );
	}
}
