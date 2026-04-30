<?php
/**
 * Unit tests for ShipStation_Service.
 *
 * @package FK_USPS_Optimizer
 */

namespace FK_USPS_Optimizer\Tests\Unit;

use FK_USPS_Optimizer\Settings;
use FK_USPS_Optimizer\ShipStation_Service;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ShipStation_Service.
 */
class ShipStationServiceTest extends TestCase {

	/**
	 * Mocked settings dependency.
	 *
	 * @var Settings|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $settings;

	/**
	 * System under test.
	 *
	 * @var ShipStation_Service
	 */
	private ShipStation_Service $service;

	protected function setUp(): void {
		$GLOBALS['_test_wp_remote_get']        = null;
		$GLOBALS['_test_wp_remote_post']       = null;
		$GLOBALS['_test_wc_logger']            = new \WC_Test_Logger();
		$GLOBALS['_test_wp_filters']           = array();
		$GLOBALS['_test_wp_options']           = array();
		$GLOBALS['_test_wp_transients']        = array();
		$GLOBALS['_test_wp_requests_multiple'] = null;

		$this->settings = $this->createMock( Settings::class );
		$this->service  = new ShipStation_Service( $this->settings );
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
	 * Minimal box definition for tests.
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
	 * Minimal packed package for tests.
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
	 * Minimal ship-to address for tests.
	 *
	 * @return array ShipStation-compatible address.
	 */
	private function make_ship_to(): array {
		return array(
			'name'            => 'Jane Doe',
			'address_line1'   => '123 Main St',
			'city_locality'   => 'Austin',
			'state_province'  => 'TX',
			'postal_code'     => '78701',
			'country_code'    => 'US',
		);
	}

	/**
	 * Configure settings mock for a successful rate request.
	 *
	 * @return void
	 */
	private function configure_credentials(): void {
		$this->settings->method( 'get_shipstation_api_key' )->willReturn( 'test_key' );
		$this->settings->method( 'get_shipstation_api_secret' )->willReturn( 'test_secret' );
		$this->settings->method( 'get_shipstation_carrier_code' )->willReturn( 'stamps_com' );
		$this->settings->method( 'get_shipstation_service_code' )->willReturn( 'usps_priority_mail' );
		$this->settings->method( 'get_ship_from_address' )->willReturn( array(
			'postal_code'    => '90210',
			'city_locality'  => 'Beverly Hills',
			'state_province' => 'CA',
			'country_code'   => 'US',
		) );
		$this->settings->method( 'is_debug_logging_enabled' )->willReturn( false );
		$this->settings->method( 'is_sandbox_mode_enabled' )->willReturn( false );
	}

	/**
	 * Simulate a successful ShipStation rate response.
	 *
	 * @param float $cost Shipment cost in USD.
	 * @return void
	 */
	private function mock_rate_response( float $cost = 7.99 ): void {
		$GLOBALS['_test_wp_remote_post'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array(
				array(
					'serviceName'  => 'USPS Priority Mail',
					'serviceCode'  => 'usps_priority_mail',
					'shipmentCost' => $cost,
					'otherCost'    => 0.00,
				),
			) ),
		);
	}

	// -------------------------------------------------------------------------
	// is_cubic_eligible
	// -------------------------------------------------------------------------

	public function test_cubic_eligible_small_package(): void {
		$dims   = array( 'length' => 8.0, 'width' => 8.0, 'height' => 6.0 );
		$result = $this->call_protected( 'is_cubic_eligible', array( $dims, 32.0 ) );
		$this->assertTrue( $result );
	}

	public function test_cubic_eligible_exceeds_half_cubic_foot(): void {
		$dims   = array( 'length' => 12.0, 'width' => 12.0, 'height' => 12.0 );
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
		$dims   = array( 'length' => 19.0, 'width' => 5.0, 'height' => 3.0 );
		$result = $this->call_protected( 'is_cubic_eligible', array( $dims, 10.0 ) );
		$this->assertFalse( $result );
	}

	public function test_cubic_eligible_exactly_18_inch_side(): void {
		$dims   = array( 'length' => 18.0, 'width' => 5.0, 'height' => 3.0 );
		$result = $this->call_protected( 'is_cubic_eligible', array( $dims, 10.0 ) );
		$this->assertTrue( $result );
	}

	// -------------------------------------------------------------------------
	// get_cubic_tier
	// -------------------------------------------------------------------------

	public function test_cubic_tier_0_1(): void {
		// 6×6×4 = 144 in³ = 0.083 ft³ → tier 0.1.
		$dims = array( 'length' => 6.0, 'width' => 6.0, 'height' => 4.0 );
		$this->assertSame( '0.1', $this->call_protected( 'get_cubic_tier', array( $dims ) ) );
	}

	public function test_cubic_tier_0_2(): void {
		// 8×8×5 = 320 in³ = 0.185 ft³ → tier 0.2.
		$dims = array( 'length' => 8.0, 'width' => 8.0, 'height' => 5.0 );
		$this->assertSame( '0.2', $this->call_protected( 'get_cubic_tier', array( $dims ) ) );
	}

	public function test_cubic_tier_0_3(): void {
		// 8×8×6 = 384 in³ = 0.222 ft³ → tier 0.3.
		$dims = array( 'length' => 8.0, 'width' => 8.0, 'height' => 6.0 );
		$this->assertSame( '0.3', $this->call_protected( 'get_cubic_tier', array( $dims ) ) );
	}

	public function test_cubic_tier_0_4(): void {
		// 9×9×8 = 648 in³ = 0.375 ft³ → tier 0.4.
		$dims = array( 'length' => 9.0, 'width' => 9.0, 'height' => 8.0 );
		$this->assertSame( '0.4', $this->call_protected( 'get_cubic_tier', array( $dims ) ) );
	}

	public function test_cubic_tier_0_5_for_large_package(): void {
		// 10×10×10 = 0.579 ft³ > 0.5 → max tier '0.5'.
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

	public function test_build_packing_list_empty_returns_empty_array(): void {
		$list = $this->call_protected( 'build_packing_list', array( array() ) );
		$this->assertSame( array(), $list );
	}

	// -------------------------------------------------------------------------
	// package_fits_box
	// -------------------------------------------------------------------------

	public function test_package_fits_when_dimensions_within_inner(): void {
		$package = $this->make_package( array( 'weight_oz' => 16.0 ) );
		$box     = $this->make_box();
		$this->assertTrue( $this->call_protected( 'package_fits_box', array( $package, $box ) ) );
	}

	public function test_package_does_not_fit_when_too_long(): void {
		$package = $this->make_package( array( 'dimensions' => array( 'length' => 10.0, 'width' => 6.0, 'height' => 4.0 ) ) );
		$box     = $this->make_box();
		$this->assertFalse( $this->call_protected( 'package_fits_box', array( $package, $box ) ) );
	}

	public function test_package_does_not_fit_when_too_heavy(): void {
		// max_weight=20 lbs = 320 oz; 400 > 320.
		$package = $this->make_package( array( 'weight_oz' => 400.0 ) );
		$box     = $this->make_box();
		$this->assertFalse( $this->call_protected( 'package_fits_box', array( $package, $box ) ) );
	}

	public function test_package_fits_at_exact_boundary(): void {
		$package = $this->make_package( array(
			'dimensions' => array( 'length' => 8.0, 'width' => 8.0, 'height' => 6.0 ),
			'weight_oz'  => 320.0,
		) );
		$box = $this->make_box( array( 'max_weight' => 20.0 ) );
		$this->assertTrue( $this->call_protected( 'package_fits_box', array( $package, $box ) ) );
	}

	// -------------------------------------------------------------------------
	// build_candidates
	// -------------------------------------------------------------------------

	public function test_build_candidates_returns_matching_cubic_box(): void {
		$this->settings->method( 'get_boxes_for_carrier' )->willReturn( array( $this->make_box() ) );
		$this->settings->method( 'get_shipstation_carrier_code' )->willReturn( 'stamps_com' );
		$candidates = $this->call_protected( 'build_candidates', array( $this->make_package() ) );
		$this->assertCount( 1, $candidates );
		$this->assertSame( 'cubic', $candidates[0]['mode'] );
	}

	public function test_build_candidates_excludes_box_when_package_too_heavy(): void {
		$this->settings->method( 'get_boxes_for_carrier' )->willReturn( array( $this->make_box( array( 'max_weight' => 1.0 ) ) ) );
		$candidates = $this->call_protected( 'build_candidates', array( $this->make_package( array( 'weight_oz' => 400.0 ) ) ) );
		$this->assertCount( 0, $candidates );
	}

	public function test_build_candidates_includes_flat_rate_without_cubic_check(): void {
		$box = $this->make_box( array( 'box_type' => 'flat_rate', 'package_code' => 'small_flat_rate_box', 'max_weight' => 70 ) );
		$this->settings->method( 'get_boxes_for_carrier' )->willReturn( array( $box ) );
		$candidates = $this->call_protected( 'build_candidates', array( $this->make_package() ) );
		$this->assertCount( 1, $candidates );
		$this->assertSame( 'flat_rate_box', $candidates[0]['mode'] );
	}

	public function test_build_candidates_adds_box_empty_weight_to_total(): void {
		$box = $this->make_box( array( 'empty_weight' => 4.0 ) );
		$this->settings->method( 'get_boxes_for_carrier' )->willReturn( array( $box ) );
		$candidates = $this->call_protected( 'build_candidates', array( $this->make_package( array( 'weight_oz' => 10.0 ) ) ) );
		$this->assertSame( 14.0, $candidates[0]['weight_oz'] );
	}

	public function test_build_candidates_cubic_tier_empty_for_flat_rate(): void {
		$box = $this->make_box( array( 'box_type' => 'flat_rate' ) );
		$this->settings->method( 'get_boxes_for_carrier' )->willReturn( array( $box ) );
		$candidates = $this->call_protected( 'build_candidates', array( $this->make_package() ) );
		$this->assertSame( '', $candidates[0]['cubic_tier'] );
	}

	// -------------------------------------------------------------------------
	// get_carrier_keyword
	// -------------------------------------------------------------------------

	public function test_get_carrier_keyword_maps_stamps_com_to_usps(): void {
		$service = new ShipStation_Service( $this->settings, 'stamps_com', 'usps_priority_mail' );
		$this->assertSame( 'usps', $service->get_carrier_keyword() );
	}

	public function test_get_carrier_keyword_maps_ups_walleted_to_ups(): void {
		$service = new ShipStation_Service( $this->settings, 'ups_walleted', 'ups_ground' );
		$this->assertSame( 'ups', $service->get_carrier_keyword() );
	}

	public function test_get_carrier_keyword_maps_fedex_to_fedex(): void {
		$service = new ShipStation_Service( $this->settings, 'fedex', 'fedex_ground' );
		$this->assertSame( 'fedex', $service->get_carrier_keyword() );
	}

	public function test_get_carrier_keyword_returns_empty_for_unknown_carrier(): void {
		$service = new ShipStation_Service( $this->settings, 'some_unknown_carrier', 'some_service' );
		$this->assertSame( '', $service->get_carrier_keyword() );
	}

	public function test_build_candidates_uses_carrier_filtered_boxes(): void {
		// Create a UPS service instance.
		$service = new ShipStation_Service( $this->settings, 'ups_walleted', 'ups_ground' );

		// get_boxes_for_carrier('ups') should be called — mock returns a single box.
		$this->settings->expects( $this->once() )
			->method( 'get_boxes_for_carrier' )
			->with( 'ups' )
			->willReturn( array( $this->make_box() ) );

		$ref = new \ReflectionMethod( $service, 'build_candidates' );
		$ref->setAccessible( true );
		$candidates = $ref->invokeArgs( $service, array( $this->make_package() ) );

		$this->assertCount( 1, $candidates );
	}

	public function test_build_candidates_skips_usps_cubic_check_for_ups_carrier(): void {
		// Box 4 from the bug report: 12.25×12×7, carrier UPS, type cubic.
		// Volume = 1029 in³ = 0.596 ft³ — exceeds USPS cubic limit of 0.5 ft³.
		// For UPS, the USPS cubic eligibility check should NOT be applied.
		$box = $this->make_box( array(
			'reference'    => '4 Bag',
			'box_type'     => 'cubic',
			'outer_width'  => 12.0,
			'outer_length' => 12.25,
			'outer_depth'  => 7.0,
			'inner_width'  => 12.0,
			'inner_length' => 12.25,
			'inner_depth'  => 7.0,
			'max_weight'   => 70.0,
		) );

		$settings = $this->createMock( Settings::class );
		$settings->method( 'get_boxes_for_carrier' )->willReturn( array( $box ) );
		$service = new ShipStation_Service( $settings, 'ups_walleted', '' );

		$ref = new \ReflectionMethod( $service, 'build_candidates' );
		$ref->setAccessible( true );
		$candidates = $ref->invokeArgs( $service, array( $this->make_package() ) );

		$this->assertCount( 1, $candidates );
		$this->assertSame( 'package', $candidates[0]['mode'] );
		$this->assertSame( '', $candidates[0]['cubic_tier'] );
	}

	public function test_build_candidates_applies_usps_cubic_check_for_usps_carrier(): void {
		// Same oversized cubic box — but now under a USPS carrier.
		// Volume exceeds 0.5 ft³ so the box should be excluded.
		$box = $this->make_box( array(
			'box_type'     => 'cubic',
			'outer_width'  => 12.0,
			'outer_length' => 12.25,
			'outer_depth'  => 7.0,
			'inner_width'  => 12.0,
			'inner_length' => 12.25,
			'inner_depth'  => 7.0,
			'max_weight'   => 70.0,
		) );

		$settings = $this->createMock( Settings::class );
		$settings->method( 'get_boxes_for_carrier' )->willReturn( array( $box ) );
		$service = new ShipStation_Service( $settings, 'stamps_com', 'usps_priority_mail' );

		$ref = new \ReflectionMethod( $service, 'build_candidates' );
		$ref->setAccessible( true );
		$candidates = $ref->invokeArgs( $service, array( $this->make_package() ) );

		$this->assertCount( 0, $candidates );
	}

	public function test_build_candidates_ups_cubic_box_mode_is_package(): void {
		// A cubic box that IS within USPS cubic limits — verify that for
		// UPS the mode is 'package' (not 'cubic') and cubic_tier is empty.
		$box = $this->make_box( array( 'box_type' => 'cubic' ) );

		$settings = $this->createMock( Settings::class );
		$settings->method( 'get_boxes_for_carrier' )->willReturn( array( $box ) );
		$service = new ShipStation_Service( $settings, 'ups_walleted', 'ups_ground' );

		$ref = new \ReflectionMethod( $service, 'build_candidates' );
		$ref->setAccessible( true );
		$candidates = $ref->invokeArgs( $service, array( $this->make_package() ) );

		$this->assertCount( 1, $candidates );
		$this->assertSame( 'package', $candidates[0]['mode'] );
		$this->assertSame( '', $candidates[0]['cubic_tier'] );
	}

	public function test_build_candidates_usps_cubic_box_mode_is_cubic(): void {
		// Same small cubic box under USPS — mode should be 'cubic' with tier.
		$box = $this->make_box( array( 'box_type' => 'cubic' ) );

		$settings = $this->createMock( Settings::class );
		$settings->method( 'get_boxes_for_carrier' )->willReturn( array( $box ) );
		$service = new ShipStation_Service( $settings, 'stamps_com', 'usps_priority_mail' );

		$ref = new \ReflectionMethod( $service, 'build_candidates' );
		$ref->setAccessible( true );
		$candidates = $ref->invokeArgs( $service, array( $this->make_package() ) );

		$this->assertCount( 1, $candidates );
		$this->assertSame( 'cubic', $candidates[0]['mode'] );
		$this->assertNotEmpty( $candidates[0]['cubic_tier'] );
	}

	// -------------------------------------------------------------------------
	// request_rate — credentials check
	// -------------------------------------------------------------------------

	public function test_request_rate_fails_when_api_key_missing(): void {
		$this->settings->method( 'get_shipstation_api_key' )->willReturn( '' );
		$this->settings->method( 'get_shipstation_api_secret' )->willReturn( 'secret' );
		$this->settings->method( 'is_debug_logging_enabled' )->willReturn( true );
		$this->settings->method( 'is_sandbox_mode_enabled' )->willReturn( false );

		$candidate = $this->make_candidate();
		$result    = $this->call_protected( 'request_rate', array( $this->make_ship_to(), $candidate ) );

		$this->assertFalse( $result['success'] );
	}

	public function test_request_rate_fails_when_api_secret_missing(): void {
		$this->settings->method( 'get_shipstation_api_key' )->willReturn( 'key' );
		$this->settings->method( 'get_shipstation_api_secret' )->willReturn( '' );
		$this->settings->method( 'is_debug_logging_enabled' )->willReturn( false );
		$this->settings->method( 'is_sandbox_mode_enabled' )->willReturn( false );

		$candidate = $this->make_candidate();
		$result    = $this->call_protected( 'request_rate', array( $this->make_ship_to(), $candidate ) );

		$this->assertFalse( $result['success'] );
	}

	// -------------------------------------------------------------------------
	// request_rate — wp_error
	// -------------------------------------------------------------------------

	public function test_request_rate_returns_false_on_wp_error(): void {
		$this->configure_credentials();
		$GLOBALS['_test_wp_remote_post'] = new \WP_Error( 'http_error', 'Connection refused' );

		$result = $this->call_protected( 'request_rate', array( $this->make_ship_to(), $this->make_candidate() ) );
		$this->assertFalse( $result['success'] );
	}

	// -------------------------------------------------------------------------
	// request_rate — HTTP status codes
	// -------------------------------------------------------------------------

	public function test_request_rate_returns_false_on_non_200(): void {
		$this->configure_credentials();
		$this->settings->method( 'is_debug_logging_enabled' )->willReturn( true );

		$GLOBALS['_test_wp_remote_post'] = array(
			'response' => array( 'code' => 401 ),
			'body'     => json_encode( array( 'message' => 'Unauthorized' ) ),
		);

		$result = $this->call_protected( 'request_rate', array( $this->make_ship_to(), $this->make_candidate() ) );
		$this->assertFalse( $result['success'] );
	}

	// -------------------------------------------------------------------------
	// request_rate — empty rates array
	// -------------------------------------------------------------------------

	public function test_request_rate_returns_false_when_no_rates(): void {
		$this->configure_credentials();
		$this->settings->method( 'is_debug_logging_enabled' )->willReturn( true );

		$GLOBALS['_test_wp_remote_post'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array() ),
		);

		$result = $this->call_protected( 'request_rate', array( $this->make_ship_to(), $this->make_candidate() ) );
		$this->assertFalse( $result['success'] );
	}

	// -------------------------------------------------------------------------
	// request_rate — success and cheapest rate selection
	// -------------------------------------------------------------------------

	public function test_request_rate_returns_cheapest_rate(): void {
		$this->configure_credentials();

		$GLOBALS['_test_wp_remote_post'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array(
				array( 'serviceName' => 'USPS Express', 'serviceCode' => 'usps_express', 'shipmentCost' => 25.00, 'otherCost' => 0.00 ),
				array( 'serviceName' => 'USPS Priority', 'serviceCode' => 'usps_priority_mail', 'shipmentCost' => 8.50, 'otherCost' => 0.00 ),
				array( 'serviceName' => 'USPS First', 'serviceCode' => 'usps_first', 'shipmentCost' => 4.00, 'otherCost' => 1.00 ),
			) ),
		);

		$result = $this->call_protected( 'request_rate', array( $this->make_ship_to(), $this->make_candidate() ) );

		$this->assertTrue( $result['success'] );
		// "USPS First" has shipmentCost(4.00) + otherCost(1.00) = 5.00
		// "USPS Priority" has 8.50 + 0.00 = 8.50  → "USPS First" wins.
		$this->assertSame( 'usps_first', $result['rate']['serviceCode'] );
	}

	/**
	 * Verify the API payload includes the configured service code and candidate package code.
	 */
	public function test_request_rate_sends_service_code_and_package_code_in_payload(): void {
		$this->configure_credentials();

		$captured_body = null;

		$GLOBALS['_test_wp_remote_post'] = function ( string $url, array $args ) use ( &$captured_body ) {
			$captured_body = json_decode( $args['body'], true );
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						array(
							'serviceCode'  => 'usps_priority_mail',
							'shipmentCost' => 7.50,
							'otherCost'    => 0.00,
						),
					)
				),
			);
		};

		$this->call_protected( 'request_rate', array( $this->make_ship_to(), $this->make_candidate() ) );

		$this->assertNotNull( $captured_body, 'Payload was not captured.' );
		$this->assertSame( 'usps_priority_mail', $captured_body['serviceCode'] );
		$this->assertSame( 'package', $captured_body['packageCode'] );
	}

	public function test_request_rate_success_returns_rate_array(): void {
		$this->configure_credentials();
		$this->mock_rate_response( 9.99 );

		$result = $this->call_protected( 'request_rate', array( $this->make_ship_to(), $this->make_candidate() ) );

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'shipmentCost', $result['rate'] );
		$this->assertSame( 9.99, (float) $result['rate']['shipmentCost'] );
	}

	// -------------------------------------------------------------------------
	// request_rate — sandbox prefix in log
	// -------------------------------------------------------------------------

	public function test_request_rate_logs_sandbox_prefix_when_sandbox_enabled(): void {
		$this->settings->method( 'get_shipstation_api_key' )->willReturn( '' );
		$this->settings->method( 'get_shipstation_api_secret' )->willReturn( '' );
		$this->settings->method( 'is_debug_logging_enabled' )->willReturn( true );
		$this->settings->method( 'is_sandbox_mode_enabled' )->willReturn( true );

		$this->call_protected( 'request_rate', array( $this->make_ship_to(), $this->make_candidate() ) );

		$this->assertNotEmpty( $GLOBALS['_test_wc_logger']->logs );
		$this->assertStringContainsString( '[SANDBOX]', $GLOBALS['_test_wc_logger']->logs[0]['message'] );
	}

	// -------------------------------------------------------------------------
	// build_test_package_plan
	// -------------------------------------------------------------------------

	public function test_build_test_package_plan_returns_empty_when_no_boxes(): void {
		$this->settings->method( 'get_boxes_for_carrier' )->willReturn( array() );
		$result = $this->service->build_test_package_plan( $this->make_package(), $this->make_ship_to(), 1 );
		$this->assertSame( array(), $result );
	}

	public function test_build_test_package_plan_returns_empty_when_rate_fails(): void {
		$box = $this->make_box();
		$this->settings->method( 'get_boxes_for_carrier' )->willReturn( array( $box ) );
		$this->settings->method( 'get_shipstation_api_key' )->willReturn( '' );
		$this->settings->method( 'get_shipstation_api_secret' )->willReturn( '' );
		$this->settings->method( 'is_debug_logging_enabled' )->willReturn( false );
		$this->settings->method( 'is_sandbox_mode_enabled' )->willReturn( false );

		$result = $this->service->build_test_package_plan( $this->make_package(), $this->make_ship_to(), 1 );
		$this->assertSame( array(), $result );
	}

	public function test_build_test_package_plan_returns_populated_plan(): void {
		$this->settings->method( 'get_boxes_for_carrier' )->willReturn( array( $this->make_box() ) );
		$this->configure_credentials();
		$this->mock_rate_response( 8.50 );

		$result = $this->service->build_test_package_plan( $this->make_package(), $this->make_ship_to(), 2 );

		$this->assertSame( 2, $result['package_number'] );
		$this->assertSame( 8.50, $result['rate_amount'] );
		$this->assertSame( 'USD', $result['currency'] );
		$this->assertArrayHasKey( 'packing_list', $result );
		$this->assertArrayHasKey( 'dimensions', $result );
		$this->assertArrayHasKey( 'weight_oz', $result );
	}

	public function test_build_test_package_plan_selects_cheapest_box(): void {
		$box_a = $this->make_box( array( 'reference' => 'Box A', 'package_name' => 'Box A' ) );
		$box_b = $this->make_box( array( 'reference' => 'Box B', 'package_name' => 'Box B',
			'outer_width' => 9, 'outer_length' => 9, 'outer_depth' => 7,
			'inner_width' => 9, 'inner_length' => 9, 'inner_depth' => 7 ) );

		$this->settings->method( 'get_boxes_for_carrier' )->willReturn( array( $box_a, $box_b ) );
		$this->configure_credentials();

		$call = 0;
		$GLOBALS['_test_wp_remote_post'] = function () use ( &$call ) {
			++$call;
			$cost = 1 === $call ? 10.00 : 6.50;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode( array(
					array( 'serviceCode' => 'usps_priority_mail', 'shipmentCost' => $cost, 'otherCost' => 0.00 ),
				) ),
			);
		};

		$result = $this->service->build_test_package_plan( $this->make_package(), $this->make_ship_to(), 1 );

		$this->assertSame( 6.50, $result['rate_amount'] );
		$this->assertSame( 'Box B', $result['package_name'] );
	}

	// -------------------------------------------------------------------------
	// build_package_plan (via WC_Order)
	// -------------------------------------------------------------------------

	public function test_build_package_plan_accepts_wc_order(): void {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 10 );
		$order->method( 'get_shipping_first_name' )->willReturn( 'Test' );
		$order->method( 'get_shipping_last_name' )->willReturn( 'User' );
		$order->method( 'get_shipping_company' )->willReturn( '' );
		$order->method( 'get_billing_phone' )->willReturn( '' );
		$order->method( 'get_shipping_address_1' )->willReturn( '1 Test St' );
		$order->method( 'get_shipping_address_2' )->willReturn( '' );
		$order->method( 'get_shipping_city' )->willReturn( 'Austin' );
		$order->method( 'get_shipping_state' )->willReturn( 'TX' );
		$order->method( 'get_shipping_postcode' )->willReturn( '78701' );
		$order->method( 'get_shipping_country' )->willReturn( 'US' );

		$this->settings->method( 'get_boxes_for_carrier' )->willReturn( array( $this->make_box() ) );
		$this->configure_credentials();
		$this->mock_rate_response( 7.50 );

		$result = $this->service->build_package_plan( $order, $this->make_package(), 1 );

		$this->assertSame( 1, $result['package_number'] );
		$this->assertSame( 7.50, $result['rate_amount'] );
	}

	// -------------------------------------------------------------------------
	// build_all_test_package_plans
	// -------------------------------------------------------------------------

	public function test_build_all_test_package_plans_returns_all_sorted(): void {
		$box_a = $this->make_box( array( 'reference' => 'Box A', 'package_name' => 'Box A' ) );
		$box_b = $this->make_box( array( 'reference' => 'Box B', 'package_name' => 'Box B',
			'outer_width' => 9, 'outer_length' => 9, 'outer_depth' => 7,
			'inner_width' => 9, 'inner_length' => 9, 'inner_depth' => 7 ) );

		$this->settings->method( 'get_boxes_for_carrier' )->willReturn( array( $box_a, $box_b ) );
		$this->configure_credentials();

		$call = 0;
		$GLOBALS['_test_wp_remote_post'] = function () use ( &$call ) {
			++$call;
			$cost = 1 === $call ? 11.00 : 7.00;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode( array(
					array( 'serviceCode' => 'usps_priority_mail', 'shipmentCost' => $cost, 'otherCost' => 0.00 ),
				) ),
			);
		};

		$plans = $this->service->build_all_test_package_plans( $this->make_package(), $this->make_ship_to(), 1 );

		$this->assertCount( 2, $plans );
		$this->assertSame( 7.00, $plans[0]['rate_amount'] );
		$this->assertSame( 11.00, $plans[1]['rate_amount'] );
	}

	public function test_build_all_test_package_plans_returns_empty_when_no_boxes(): void {
		$this->settings->method( 'get_boxes_for_carrier' )->willReturn( array() );

		$plans = $this->service->build_all_test_package_plans( $this->make_package(), $this->make_ship_to(), 1 );

		$this->assertSame( array(), $plans );
	}

	public function test_build_all_test_package_plans_uses_configured_service_code(): void {
		$this->settings->method( 'get_boxes_for_carrier' )->willReturn( array( $this->make_box() ) );
		$this->configure_credentials();

		$GLOBALS['_test_wp_remote_post'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array(
				array( 'serviceCode' => 'usps_ground_advantage', 'shipmentCost' => 4.99, 'otherCost' => 0.00 ),
			) ),
		);

		$plans = $this->service->build_all_test_package_plans( $this->make_package(), $this->make_ship_to(), 1 );

		$this->assertCount( 1, $plans );
		$this->assertSame( 'usps_ground_advantage', $plans[0]['service_code'] );
	}

	// -------------------------------------------------------------------------
	// test_connection
	// -------------------------------------------------------------------------

	public function test_connection_returns_error_when_api_key_missing(): void {
		$this->settings->method( 'get_shipstation_api_key' )->willReturn( '' );
		$this->settings->method( 'get_shipstation_api_secret' )->willReturn( 'secret' );

		$result = $this->service->test_connection();

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'API key', $result['message'] );
	}

	public function test_connection_returns_error_when_api_secret_missing(): void {
		$this->settings->method( 'get_shipstation_api_key' )->willReturn( 'key' );
		$this->settings->method( 'get_shipstation_api_secret' )->willReturn( '' );

		$result = $this->service->test_connection();

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'API key', $result['message'] );
	}

	public function test_connection_returns_error_when_both_credentials_missing(): void {
		$this->settings->method( 'get_shipstation_api_key' )->willReturn( '' );
		$this->settings->method( 'get_shipstation_api_secret' )->willReturn( '' );

		$result = $this->service->test_connection();

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'not configured', $result['message'] );
	}

	public function test_connection_uses_provided_credentials_instead_of_settings(): void {
		// Settings have no credentials, but explicit args are provided.
		$this->settings->expects( $this->never() )->method( 'get_shipstation_api_key' );
		$this->settings->expects( $this->never() )->method( 'get_shipstation_api_secret' );

		$GLOBALS['_test_wp_remote_get'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{}',
		);

		$result = $this->service->test_connection( 'explicit-key', 'explicit-secret' );

		$this->assertTrue( $result['success'] );
	}

	public function test_connection_falls_back_to_settings_when_args_empty(): void {
		$this->settings->method( 'get_shipstation_api_key' )->willReturn( 'saved-key' );
		$this->settings->method( 'get_shipstation_api_secret' )->willReturn( 'saved-secret' );

		$captured_headers = null;
		$GLOBALS['_test_wp_remote_get'] = function ( string $url, array $args ) use ( &$captured_headers ) {
			$captured_headers = $args['headers'];
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => '{}',
			);
		};

		$this->service->test_connection();

		$this->assertNotNull( $captured_headers );
		$this->assertStringContainsString( 'Basic ', $captured_headers['Authorization'] );
		$this->assertStringContainsString(
			base64_encode( 'saved-key:saved-secret' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			$captured_headers['Authorization']
		);
	}

	public function test_connection_returns_error_on_wp_error(): void {
		$GLOBALS['_test_wp_remote_get'] = new \WP_Error( 'http_request_failed', 'cURL error 28' );

		$result = $this->service->test_connection( 'key', 'secret' );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'cURL error 28', $result['message'] );
	}

	public function test_connection_returns_error_on_401_response(): void {
		$GLOBALS['_test_wp_remote_get'] = array(
			'response' => array( 'code' => 401 ),
			'body'     => '{}',
		);

		$result = $this->service->test_connection( 'bad-key', 'bad-secret' );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Invalid ShipStation', $result['message'] );
	}

	public function test_connection_returns_error_on_403_response(): void {
		$GLOBALS['_test_wp_remote_get'] = array(
			'response' => array( 'code' => 403 ),
			'body'     => '{}',
		);

		$result = $this->service->test_connection( 'key', 'secret' );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Invalid ShipStation', $result['message'] );
	}

	public function test_connection_returns_error_on_non_success_status(): void {
		$GLOBALS['_test_wp_remote_get'] = array(
			'response' => array( 'code' => 500 ),
			'body'     => '{}',
		);

		$result = $this->service->test_connection( 'key', 'secret' );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( '500', $result['message'] );
	}

	public function test_connection_succeeds_on_200_response(): void {
		$GLOBALS['_test_wp_remote_get'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{}',
		);

		$result = $this->service->test_connection( 'valid-key', 'valid-secret' );

		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'successful', $result['message'] );
	}

	// -------------------------------------------------------------------------
	// get_ship_to_address
	// -------------------------------------------------------------------------

	public function test_get_ship_to_address_defaults_country_to_us(): void {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_shipping_first_name' )->willReturn( 'A' );
		$order->method( 'get_shipping_last_name' )->willReturn( 'B' );
		$order->method( 'get_shipping_country' )->willReturn( '' );
		$order->method( 'get_shipping_company' )->willReturn( '' );
		$order->method( 'get_billing_phone' )->willReturn( '' );
		$order->method( 'get_shipping_address_1' )->willReturn( '' );
		$order->method( 'get_shipping_address_2' )->willReturn( '' );
		$order->method( 'get_shipping_city' )->willReturn( '' );
		$order->method( 'get_shipping_state' )->willReturn( '' );
		$order->method( 'get_shipping_postcode' )->willReturn( '' );

		$result = $this->call_protected( 'get_ship_to_address', array( $order ) );
		$this->assertSame( 'US', $result['country_code'] );
	}

	// -------------------------------------------------------------------------
	// log
	// -------------------------------------------------------------------------

	public function test_log_writes_when_debug_enabled(): void {
		$this->settings->method( 'is_debug_logging_enabled' )->willReturn( true );
		$this->settings->method( 'is_sandbox_mode_enabled' )->willReturn( false );

		$this->service->log( 'Test entry', array( 'key' => 'value' ) );

		$this->assertCount( 1, $GLOBALS['_test_wc_logger']->logs );
		$this->assertStringContainsString( 'Test entry', $GLOBALS['_test_wc_logger']->logs[0]['message'] );
	}

	public function test_log_is_silent_when_debug_disabled(): void {
		$this->settings->method( 'is_debug_logging_enabled' )->willReturn( false );

		$this->service->log( 'Silent', array() );

		$this->assertCount( 0, $GLOBALS['_test_wc_logger']->logs );
	}

	public function test_log_prepends_sandbox_prefix_when_sandbox_active(): void {
		$this->settings->method( 'is_debug_logging_enabled' )->willReturn( true );
		$this->settings->method( 'is_sandbox_mode_enabled' )->willReturn( true );

		$this->service->log( 'Sandbox test', array() );

		$this->assertStringContainsString( '[SANDBOX]', $GLOBALS['_test_wc_logger']->logs[0]['message'] );
	}

	// -------------------------------------------------------------------------
	// API URL filter
	// -------------------------------------------------------------------------

	public function test_api_url_can_be_overridden_via_filter(): void {
		$GLOBALS['_test_wp_filters']['fk_usps_optimizer_shipstation_api_url'][] = function () {
			return 'https://sandbox.example.com';
		};

		$this->settings->method( 'get_shipstation_api_key' )->willReturn( 'k' );
		$this->settings->method( 'get_shipstation_api_secret' )->willReturn( 's' );
		$this->settings->method( 'get_shipstation_carrier_code' )->willReturn( 'stamps_com' );
		$this->settings->method( 'get_ship_from_address' )->willReturn( array( 'postal_code' => '12345' ) );
		$this->settings->method( 'is_debug_logging_enabled' )->willReturn( false );
		$this->settings->method( 'is_sandbox_mode_enabled' )->willReturn( false );

		$captured_url = null;
		$GLOBALS['_test_wp_remote_post'] = function ( string $url ) use ( &$captured_url ) {
			$captured_url = $url;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode( array(
					array( 'serviceCode' => 'usps_priority_mail', 'shipmentCost' => 5.00, 'otherCost' => 0.00 ),
				) ),
			);
		};

		$this->call_protected( 'request_rate', array( $this->make_ship_to(), $this->make_candidate() ) );

		$this->assertStringStartsWith( 'https://sandbox.example.com', (string) $captured_url );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a minimal rate candidate array.
	 *
	 * @return array Candidate data.
	 */
	private function make_candidate(): array {
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
	// Constructor overrides for carrier_code / service_code
	// -------------------------------------------------------------------------

	public function test_get_carrier_code_uses_override_when_provided(): void {
		$service = new ShipStation_Service( $this->settings, 'ups_walleted', '' );
		$this->assertSame( 'ups_walleted', $service->get_carrier_code() );
	}

	public function test_get_carrier_code_falls_back_to_settings(): void {
		$this->settings->method( 'get_shipstation_carrier_code' )->willReturn( 'stamps_com' );
		$service = new ShipStation_Service( $this->settings );
		$this->assertSame( 'stamps_com', $service->get_carrier_code() );
	}

	public function test_get_service_code_uses_override_when_provided(): void {
		$service = new ShipStation_Service( $this->settings, '', 'ups_ground' );
		$this->assertSame( 'ups_ground', $service->get_service_code() );
	}

	public function test_get_service_code_falls_back_to_settings(): void {
		$this->settings->method( 'get_shipstation_service_code' )->willReturn( 'usps_priority_mail' );
		$service = new ShipStation_Service( $this->settings );
		$this->assertSame( 'usps_priority_mail', $service->get_service_code() );
	}

	public function test_constructor_with_both_overrides(): void {
		$service = new ShipStation_Service( $this->settings, 'ups_walleted', 'ups_ground' );
		$this->assertSame( 'ups_walleted', $service->get_carrier_code() );
		$this->assertSame( 'ups_ground', $service->get_service_code() );
	}

	public function test_get_service_code_returns_empty_when_override_is_empty_string(): void {
		// Even when Settings has a non-empty default, an explicit '' override
		// should be respected so the API returns rates for ALL services.
		$this->settings->method( 'get_shipstation_service_code' )->willReturn( 'usps_priority_mail' );
		$service = new ShipStation_Service( $this->settings, 'stamps_com', '' );
		$this->assertSame( '', $service->get_service_code() );
	}

	public function test_get_carrier_code_returns_empty_when_override_is_empty_string(): void {
		$this->settings->method( 'get_shipstation_carrier_code' )->willReturn( 'stamps_com' );
		$service = new ShipStation_Service( $this->settings, '', 'usps_priority_mail' );
		$this->assertSame( '', $service->get_carrier_code() );
	}

	public function test_get_service_code_falls_back_to_settings_when_override_is_null(): void {
		// When constructed without explicit overrides, should fall back to settings.
		$this->settings->method( 'get_shipstation_service_code' )->willReturn( 'usps_priority_mail' );
		$service = new ShipStation_Service( $this->settings );
		$this->assertSame( 'usps_priority_mail', $service->get_service_code() );
	}

	// -------------------------------------------------------------------------
	// get_service_label
	// -------------------------------------------------------------------------

	public function test_get_service_label_usps_priority(): void {
		$service = new ShipStation_Service( $this->settings, 'stamps_com', 'usps_priority_mail' );
		$this->assertSame( 'USPS Priority', $service->get_service_label() );
	}

	public function test_get_service_label_ups_ground(): void {
		$service = new ShipStation_Service( $this->settings, 'ups_walleted', 'ups_ground' );
		$this->assertSame( 'UPS Ground', $service->get_service_label() );
	}

	public function test_get_service_label_ups_next_day_air(): void {
		$service = new ShipStation_Service( $this->settings, 'ups', 'ups_next_day_air' );
		$this->assertSame( 'UPS Next Day Air', $service->get_service_label() );
	}

	public function test_get_service_label_fedex(): void {
		$service = new ShipStation_Service( $this->settings, 'fedex', 'fedex_ground' );
		$this->assertSame( 'FedEx Ground', $service->get_service_label() );
	}

	public function test_get_service_label_unknown_carrier(): void {
		$service = new ShipStation_Service( $this->settings, 'some_new_carrier', 'some_new_carrier_express' );
		$this->assertSame( 'Some New Carrier Express', $service->get_service_label() );
	}

	public function test_get_service_label_endicia_usps(): void {
		$service = new ShipStation_Service( $this->settings, 'endicia', 'usps_first_class_mail' );
		$this->assertSame( 'USPS First Class', $service->get_service_label() );
	}

	public function test_get_service_label_usps_ground_advantage(): void {
		$service = new ShipStation_Service( $this->settings, 'stamps_com', 'usps_ground_advantage' );
		$this->assertSame( 'USPS Ground Advantage', $service->get_service_label() );
	}

	public function test_get_service_label_override_service_code(): void {
		// Instance configured with usps_priority_mail but override with ups_ground.
		$service = new ShipStation_Service( $this->settings, 'ups_walleted', 'usps_priority_mail' );
		$this->assertSame( 'UPS Ground', $service->get_service_label( 'ups_ground' ) );
	}

	public function test_get_service_label_override_empty_falls_back_to_instance(): void {
		$service = new ShipStation_Service( $this->settings, 'stamps_com', 'usps_priority_mail' );
		$this->assertSame( 'USPS Priority', $service->get_service_label( '' ) );
	}

	public function test_get_service_label_ups_2nd_day_air(): void {
		$service = new ShipStation_Service( $this->settings, 'ups_walleted', 'ups_2nd_day_air' );
		$this->assertSame( 'UPS 2nd Day Air', $service->get_service_label() );
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

	public function test_compute_delivery_date_adds_transit_days_from_current_time(): void {
		// current_time() stub returns '2024-01-01 00:00:00'.
		$result = $this->call_protected( 'compute_delivery_date', array( 2 ) );
		$this->assertSame( '2024-01-03', $result );
	}

	public function test_compute_delivery_date_returns_iso_date_string(): void {
		$result = $this->call_protected( 'compute_delivery_date', array( 5 ) );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $result );
	}

	// -------------------------------------------------------------------------
	// estimated_delivery_date in build_test_package_plan
	// -------------------------------------------------------------------------

	public function test_build_test_package_plan_includes_estimated_delivery_date_when_transit_days_present(): void {
		$this->settings->method( 'get_boxes_for_carrier' )->willReturn( array( $this->make_box() ) );
		$this->configure_credentials();

		$GLOBALS['_test_wp_remote_post'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array(
				array(
					'serviceCode'  => 'usps_priority_mail',
					'shipmentCost' => 7.99,
					'otherCost'    => 0.00,
					'transitDays'  => 2,
				),
			) ),
		);

		$result = $this->service->build_test_package_plan( $this->make_package(), $this->make_ship_to(), 1 );

		$this->assertArrayHasKey( 'estimated_delivery_date', $result );
		// current_time() stub returns '2024-01-01 00:00:00', so 2 days → '2024-01-03'.
		$this->assertSame( '2024-01-03', $result['estimated_delivery_date'] );
	}

	public function test_build_test_package_plan_estimated_delivery_date_uses_fallback_when_no_transit_days(): void {
		$this->settings->method( 'get_boxes_for_carrier' )->willReturn( array( $this->make_box() ) );
		$this->settings->method( 'is_use_default_transit_days_enabled' )->willReturn( true );
		$this->configure_credentials();
		$this->mock_rate_response( 7.99 ); // No transitDays field, but serviceCode is usps_priority_mail.

		$result = $this->service->build_test_package_plan( $this->make_package(), $this->make_ship_to(), 1 );

		$this->assertArrayHasKey( 'estimated_delivery_date', $result );
		// current_time() stub returns '2024-01-01 00:00:00'; usps_priority_mail defaults to 3 days.
		$this->assertSame( '2024-01-04', $result['estimated_delivery_date'] );
	}

	public function test_build_all_test_package_plans_includes_estimated_delivery_date(): void {
		$this->settings->method( 'get_boxes_for_carrier' )->willReturn( array( $this->make_box() ) );
		$this->configure_credentials();

		$GLOBALS['_test_wp_remote_post'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array(
				array(
					'serviceCode'  => 'usps_priority_mail',
					'shipmentCost' => 7.99,
					'otherCost'    => 0.00,
					'transitDays'  => 3,
				),
			) ),
		);

		$plans = $this->service->build_all_test_package_plans( $this->make_package(), $this->make_ship_to(), 1 );

		$this->assertCount( 1, $plans );
		$this->assertSame( '2024-01-04', $plans[0]['estimated_delivery_date'] );
	}

	// -------------------------------------------------------------------------
	// extract_delivery_date (protected)
	// -------------------------------------------------------------------------

	public function test_extract_delivery_date_prefers_estimated_delivery_date_field(): void {
		$rate   = array(
			'estimatedDeliveryDate' => '2024-02-10T00:00:00Z',
			'deliveryDays'          => 5,
			'transitDays'           => 3,
		);
		$result = $this->call_protected( 'extract_delivery_date', array( $rate ) );
		$this->assertSame( '2024-02-10', $result );
	}

	public function test_extract_delivery_date_falls_back_to_delivery_days(): void {
		// current_time() stub returns '2024-01-01 00:00:00'.
		$rate   = array( 'deliveryDays' => 4 );
		$result = $this->call_protected( 'extract_delivery_date', array( $rate ) );
		$this->assertSame( '2024-01-05', $result );
	}

	public function test_extract_delivery_date_falls_back_to_transit_days(): void {
		// current_time() stub returns '2024-01-01 00:00:00'.
		$rate   = array( 'transitDays' => 2 );
		$result = $this->call_protected( 'extract_delivery_date', array( $rate ) );
		$this->assertSame( '2024-01-03', $result );
	}

	public function test_extract_delivery_date_returns_empty_when_no_fields(): void {
		$rate   = array( 'shipmentCost' => 7.99 );
		$result = $this->call_protected( 'extract_delivery_date', array( $rate ) );
		$this->assertSame( '', $result );
	}

	public function test_extract_delivery_date_skips_invalid_estimated_delivery_date(): void {
		// Invalid ISO string should fall through to deliveryDays.
		$rate   = array(
			'estimatedDeliveryDate' => 'not-a-date',
			'deliveryDays'          => 3,
		);
		$result = $this->call_protected( 'extract_delivery_date', array( $rate ) );
		$this->assertSame( '2024-01-04', $result );
	}

	public function test_build_test_package_plan_uses_estimated_delivery_date_field(): void {
		$this->settings->method( 'get_boxes_for_carrier' )->willReturn( array( $this->make_box() ) );
		$this->configure_credentials();

		$GLOBALS['_test_wp_remote_post'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array(
				array(
					'serviceCode'             => 'usps_priority_mail',
					'shipmentCost'            => 7.99,
					'otherCost'               => 0.00,
					'estimatedDeliveryDate'   => '2024-03-15T00:00:00Z',
				),
			) ),
		);

		$result = $this->service->build_test_package_plan( $this->make_package(), $this->make_ship_to(), 1 );

		$this->assertArrayHasKey( 'estimated_delivery_date', $result );
		$this->assertSame( '2024-03-15', $result['estimated_delivery_date'] );
	}

	public function test_build_test_package_plan_uses_delivery_days_field(): void {
		$this->settings->method( 'get_boxes_for_carrier' )->willReturn( array( $this->make_box() ) );
		$this->configure_credentials();

		$GLOBALS['_test_wp_remote_post'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array(
				array(
					'serviceCode'  => 'usps_priority_mail',
					'shipmentCost' => 7.99,
					'otherCost'    => 0.00,
					'deliveryDays' => 3,
				),
			) ),
		);

		$result = $this->service->build_test_package_plan( $this->make_package(), $this->make_ship_to(), 1 );

		$this->assertArrayHasKey( 'estimated_delivery_date', $result );
		// current_time() stub returns '2024-01-01 00:00:00', so 3 days → '2024-01-04'.
		$this->assertSame( '2024-01-04', $result['estimated_delivery_date'] );
	}

	// -------------------------------------------------------------------------
	// get_default_transit_days (protected)
	// -------------------------------------------------------------------------

	public function test_get_default_transit_days_returns_days_for_usps_priority(): void {
		$result = $this->call_protected( 'get_default_transit_days', array( 'usps_priority_mail' ) );
		$this->assertSame( 3, $result );
	}

	public function test_get_default_transit_days_returns_days_for_ups_ground(): void {
		$result = $this->call_protected( 'get_default_transit_days', array( 'ups_ground' ) );
		$this->assertSame( 5, $result );
	}

	public function test_get_default_transit_days_returns_zero_for_unknown_code(): void {
		$result = $this->call_protected( 'get_default_transit_days', array( 'unknown_service' ) );
		$this->assertSame( 0, $result );
	}

	public function test_extract_delivery_date_falls_back_to_service_code_default(): void {
		$this->settings->method( 'is_use_default_transit_days_enabled' )->willReturn( true );
		// No delivery date fields, but serviceCode is a known USPS service.
		$rate   = array( 'serviceCode' => 'usps_priority_mail', 'shipmentCost' => 7.99 );
		$result = $this->call_protected( 'extract_delivery_date', array( $rate ) );
		// current_time() stub returns '2024-01-01 00:00:00'; priority_mail defaults to 3 days.
		$this->assertSame( '2024-01-04', $result );
	}

	public function test_extract_delivery_date_returns_empty_for_unknown_service_code(): void {
		$this->settings->method( 'is_use_default_transit_days_enabled' )->willReturn( true );
		$rate   = array( 'serviceCode' => 'some_unknown_service', 'shipmentCost' => 7.99 );
		$result = $this->call_protected( 'extract_delivery_date', array( $rate ) );
		$this->assertSame( '', $result );
	}

	public function test_extract_delivery_date_skips_fallback_when_setting_disabled(): void {
		$this->settings->method( 'is_use_default_transit_days_enabled' )->willReturn( false );
		// Known service code but setting is off — should return empty.
		$rate   = array( 'serviceCode' => 'usps_priority_mail', 'shipmentCost' => 7.99 );
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

	// -------------------------------------------------------------------------
	// request_all_rates
	// -------------------------------------------------------------------------

	public function test_request_all_rates_returns_all_rates_sorted(): void {
		$this->configure_credentials();

		$GLOBALS['_test_wp_remote_post'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array(
				array( 'serviceCode' => 'ups_next_day_air', 'shipmentCost' => 25.00, 'otherCost' => 0.00 ),
				array( 'serviceCode' => 'ups_ground', 'shipmentCost' => 9.00, 'otherCost' => 0.00 ),
				array( 'serviceCode' => 'ups_2nd_day_air', 'shipmentCost' => 15.00, 'otherCost' => 0.00 ),
			) ),
		);

		$result = $this->call_protected( 'request_all_rates', array( $this->make_ship_to(), $this->make_candidate() ) );

		$this->assertTrue( $result['success'] );
		$this->assertCount( 3, $result['rates'] );
		// Sorted cheapest-first.
		$this->assertSame( 'ups_ground', $result['rates'][0]['serviceCode'] );
		$this->assertSame( 'ups_2nd_day_air', $result['rates'][1]['serviceCode'] );
		$this->assertSame( 'ups_next_day_air', $result['rates'][2]['serviceCode'] );
	}

	public function test_request_all_rates_fails_when_no_credentials(): void {
		$this->settings->method( 'get_shipstation_api_key' )->willReturn( '' );
		$this->settings->method( 'get_shipstation_api_secret' )->willReturn( '' );
		$this->settings->method( 'is_debug_logging_enabled' )->willReturn( false );
		$this->settings->method( 'is_sandbox_mode_enabled' )->willReturn( false );

		$result = $this->call_protected( 'request_all_rates', array( $this->make_ship_to(), $this->make_candidate() ) );

		$this->assertFalse( $result['success'] );
	}

	public function test_request_all_rates_fails_when_empty_response(): void {
		$this->configure_credentials();

		$GLOBALS['_test_wp_remote_post'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array() ),
		);

		$result = $this->call_protected( 'request_all_rates', array( $this->make_ship_to(), $this->make_candidate() ) );

		$this->assertFalse( $result['success'] );
	}

	// -------------------------------------------------------------------------
	// build_all_test_package_plans with empty service_code (multi-service)
	// -------------------------------------------------------------------------

	public function test_build_all_plans_expands_multiple_services_when_service_code_empty(): void {
		$settings = $this->createMock( Settings::class );
		$settings->method( 'get_boxes_for_carrier' )->willReturn( array( $this->make_box() ) );
		$settings->method( 'get_shipstation_api_key' )->willReturn( 'test_key' );
		$settings->method( 'get_shipstation_api_secret' )->willReturn( 'test_secret' );
		$settings->method( 'get_shipstation_carrier_code' )->willReturn( 'ups_walleted' );
		$settings->method( 'get_shipstation_service_code' )->willReturn( '' );
		$settings->method( 'get_ship_from_address' )->willReturn( array( 'postal_code' => '90210' ) );
		$settings->method( 'is_debug_logging_enabled' )->willReturn( false );
		$settings->method( 'is_sandbox_mode_enabled' )->willReturn( false );
		$settings->method( 'is_use_default_transit_days_enabled' )->willReturn( false );

		// Create a service with empty service_code override.
		$service = new ShipStation_Service( $settings, 'ups_walleted', '' );

		// API returns multiple services.
		$GLOBALS['_test_wp_remote_post'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array(
				array( 'serviceCode' => 'ups_ground', 'shipmentCost' => 9.00, 'otherCost' => 0.00 ),
				array( 'serviceCode' => 'ups_next_day_air', 'shipmentCost' => 25.00, 'otherCost' => 0.00 ),
				array( 'serviceCode' => 'ups_2nd_day_air', 'shipmentCost' => 15.00, 'otherCost' => 0.00 ),
			) ),
		);

		$plans = $service->build_all_test_package_plans( $this->make_package(), $this->make_ship_to(), 1 );

		// Should have 3 plans (one per service), not 1 (cheapest only).
		$this->assertCount( 3, $plans );

		// Sorted cheapest-first.
		$this->assertSame( 'ups_ground', $plans[0]['service_code'] );
		$this->assertSame( 9.00, $plans[0]['rate_amount'] );
		$this->assertSame( 'UPS Ground', $plans[0]['service_label'] );

		$this->assertSame( 'ups_2nd_day_air', $plans[1]['service_code'] );
		$this->assertSame( 15.00, $plans[1]['rate_amount'] );

		$this->assertSame( 'ups_next_day_air', $plans[2]['service_code'] );
		$this->assertSame( 25.00, $plans[2]['rate_amount'] );
	}

	public function test_build_all_plans_single_service_when_service_code_set(): void {
		$this->settings->method( 'get_boxes_for_carrier' )->willReturn( array( $this->make_box() ) );
		$this->configure_credentials();

		// API returns a single rate for the specific service.
		$GLOBALS['_test_wp_remote_post'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array(
				array( 'serviceCode' => 'usps_priority_mail', 'shipmentCost' => 8.50, 'otherCost' => 0.00 ),
			) ),
		);

		$plans = $this->service->build_all_test_package_plans( $this->make_package(), $this->make_ship_to(), 1 );

		// Single service configured → 1 plan per box candidate.
		$this->assertCount( 1, $plans );
		$this->assertSame( 'usps_priority_mail', $plans[0]['service_code'] );
	}

	public function test_build_all_plans_empty_service_code_multiple_candidates(): void {
		$settings = $this->createMock( Settings::class );

		$box_a = $this->make_box( array( 'reference' => 'Box A', 'package_name' => 'Box A' ) );
		$box_b = $this->make_box( array(
			'reference'    => 'Box B',
			'package_name' => 'Box B',
			'outer_width'  => 9,
			'outer_length' => 9,
			'outer_depth'  => 7,
			'inner_width'  => 9,
			'inner_length' => 9,
			'inner_depth'  => 7,
		) );

		$settings->method( 'get_boxes_for_carrier' )->willReturn( array( $box_a, $box_b ) );
		$settings->method( 'get_shipstation_api_key' )->willReturn( 'test_key' );
		$settings->method( 'get_shipstation_api_secret' )->willReturn( 'test_secret' );
		$settings->method( 'get_shipstation_carrier_code' )->willReturn( 'ups_walleted' );
		$settings->method( 'get_shipstation_service_code' )->willReturn( '' );
		$settings->method( 'get_ship_from_address' )->willReturn( array( 'postal_code' => '90210' ) );
		$settings->method( 'is_debug_logging_enabled' )->willReturn( false );
		$settings->method( 'is_sandbox_mode_enabled' )->willReturn( false );
		$settings->method( 'is_use_default_transit_days_enabled' )->willReturn( false );

		$service = new ShipStation_Service( $settings, 'ups_walleted', '' );

		$call = 0;
		$GLOBALS['_test_wp_remote_post'] = function () use ( &$call ) {
			++$call;
			// First candidate (Box A): 2 services.
			// Second candidate (Box B): 2 services.
			$cost_offset = ( 1 === $call ) ? 0 : 1;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode( array(
					array( 'serviceCode' => 'ups_ground', 'shipmentCost' => 9.00 + $cost_offset, 'otherCost' => 0.00 ),
					array( 'serviceCode' => 'ups_next_day_air', 'shipmentCost' => 25.00 + $cost_offset, 'otherCost' => 0.00 ),
				) ),
			);
		};

		$plans = $service->build_all_test_package_plans( $this->make_package(), $this->make_ship_to(), 1 );

		// 2 services × 2 candidates = 4 plans.
		$this->assertCount( 4, $plans );
		// Sorted cheapest-first: UPS Ground Box A (9), UPS Ground Box B (10), UPS Next Day Box A (25), UPS Next Day Box B (26).
		$this->assertSame( 9.00, $plans[0]['rate_amount'] );
		$this->assertSame( 'ups_ground', $plans[0]['service_code'] );
		$this->assertSame( 10.00, $plans[1]['rate_amount'] );
		$this->assertSame( 'ups_ground', $plans[1]['service_code'] );
		$this->assertSame( 25.00, $plans[2]['rate_amount'] );
		$this->assertSame( 'ups_next_day_air', $plans[2]['service_code'] );
		$this->assertSame( 26.00, $plans[3]['rate_amount'] );
		$this->assertSame( 'ups_next_day_air', $plans[3]['service_code'] );
	}

	public function test_build_all_plans_expands_usps_services_when_service_code_empty(): void {
		// Simulate the real-world scenario: settings has a non-empty default
		// service code, but the pair override is explicitly empty.
		$settings = $this->createMock( Settings::class );
		$settings->method( 'get_boxes_for_carrier' )->willReturn( array( $this->make_box() ) );
		$settings->method( 'get_shipstation_api_key' )->willReturn( 'test_key' );
		$settings->method( 'get_shipstation_api_secret' )->willReturn( 'test_secret' );
		$settings->method( 'get_shipstation_carrier_code' )->willReturn( 'stamps_com' );
		// Settings has a non-empty default — the bug was that this value was used
		// instead of the explicit '' override.
		$settings->method( 'get_shipstation_service_code' )->willReturn( 'usps_priority_mail' );
		$settings->method( 'get_ship_from_address' )->willReturn( array( 'postal_code' => '90210' ) );
		$settings->method( 'is_debug_logging_enabled' )->willReturn( false );
		$settings->method( 'is_sandbox_mode_enabled' )->willReturn( false );
		$settings->method( 'is_use_default_transit_days_enabled' )->willReturn( false );

		// Create service with explicit empty service_code override.
		$service = new ShipStation_Service( $settings, 'stamps_com', '' );

		// API returns multiple USPS services when serviceCode is empty.
		$GLOBALS['_test_wp_remote_post'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array(
				array( 'serviceCode' => 'usps_priority_mail', 'shipmentCost' => 8.69, 'otherCost' => 0.00 ),
				array( 'serviceCode' => 'usps_ground_advantage', 'shipmentCost' => 5.50, 'otherCost' => 0.00 ),
				array( 'serviceCode' => 'usps_priority_mail_express', 'shipmentCost' => 28.00, 'otherCost' => 0.00 ),
			) ),
		);

		$plans = $service->build_all_test_package_plans( $this->make_package(), $this->make_ship_to(), 1 );

		// All 3 USPS services should appear, not just Priority.
		$this->assertCount( 3, $plans );

		// Sorted cheapest-first.
		$this->assertSame( 'usps_ground_advantage', $plans[0]['service_code'] );
		$this->assertSame( 5.50, $plans[0]['rate_amount'] );
		$this->assertSame( 'USPS Ground Advantage', $plans[0]['service_label'] );

		$this->assertSame( 'usps_priority_mail', $plans[1]['service_code'] );
		$this->assertSame( 8.69, $plans[1]['rate_amount'] );
		$this->assertSame( 'USPS Priority', $plans[1]['service_label'] );

		$this->assertSame( 'usps_priority_mail_express', $plans[2]['service_code'] );
		$this->assertSame( 28.00, $plans[2]['rate_amount'] );
	}

	// -------------------------------------------------------------------------
	// Phase 1 optimizations: transient cache, candidate cap, timeout filter,
	// allowed-service-codes allow-list.
	// -------------------------------------------------------------------------

	public function test_request_all_rates_caches_successful_response_in_transient(): void {
		$this->configure_credentials();

		$call_count                       = 0;
		$GLOBALS['_test_wp_remote_post'] = function () use ( &$call_count ) {
			++$call_count;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode( array(
					array( 'serviceCode' => 'usps_priority_mail', 'shipmentCost' => 7.99, 'otherCost' => 0.00 ),
				) ),
			);
		};

		// First call: hits the API.
		$first = $this->call_protected( 'request_all_rates', array( $this->make_ship_to(), $this->make_candidate() ) );
		$this->assertTrue( $first['success'] );
		$this->assertSame( 1, $call_count );

		// Second identical call: served from transient cache, no second API call.
		$second = $this->call_protected( 'request_all_rates', array( $this->make_ship_to(), $this->make_candidate() ) );
		$this->assertTrue( $second['success'] );
		$this->assertSame( 1, $call_count );
		$this->assertSame( $first['rates'], $second['rates'] );
	}

	public function test_request_all_rates_does_not_cache_failed_response(): void {
		$this->configure_credentials();

		$call_count                       = 0;
		$GLOBALS['_test_wp_remote_post'] = function () use ( &$call_count ) {
			++$call_count;
			return array(
				'response' => array( 'code' => 500 ),
				'body'     => json_encode( array( 'message' => 'oops' ) ),
			);
		};

		$first  = $this->call_protected( 'request_all_rates', array( $this->make_ship_to(), $this->make_candidate() ) );
		$second = $this->call_protected( 'request_all_rates', array( $this->make_ship_to(), $this->make_candidate() ) );

		$this->assertFalse( $first['success'] );
		$this->assertFalse( $second['success'] );
		// Both calls must hit the API; failures are never cached.
		$this->assertSame( 2, $call_count );
	}

	public function test_request_all_rates_bypasses_cache_in_sandbox_mode(): void {
		$this->settings->method( 'get_shipstation_api_key' )->willReturn( 'test_key' );
		$this->settings->method( 'get_shipstation_api_secret' )->willReturn( 'test_secret' );
		$this->settings->method( 'get_shipstation_carrier_code' )->willReturn( 'stamps_com' );
		$this->settings->method( 'get_shipstation_service_code' )->willReturn( 'usps_priority_mail' );
		$this->settings->method( 'get_ship_from_address' )->willReturn( array( 'postal_code' => '90210' ) );
		$this->settings->method( 'is_debug_logging_enabled' )->willReturn( false );
		// Sandbox enabled — must skip the transient cache.
		$this->settings->method( 'is_sandbox_mode_enabled' )->willReturn( true );

		$call_count                       = 0;
		$GLOBALS['_test_wp_remote_post'] = function () use ( &$call_count ) {
			++$call_count;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode( array(
					array( 'serviceCode' => 'usps_priority_mail', 'shipmentCost' => 7.99, 'otherCost' => 0.00 ),
				) ),
			);
		};

		$this->call_protected( 'request_all_rates', array( $this->make_ship_to(), $this->make_candidate() ) );
		$this->call_protected( 'request_all_rates', array( $this->make_ship_to(), $this->make_candidate() ) );

		$this->assertSame( 2, $call_count );
	}

	public function test_request_all_rates_uses_filtered_timeout(): void {
		$this->configure_credentials();

		$captured_timeout                = null;
		$GLOBALS['_test_wp_remote_post'] = function ( string $url, array $args ) use ( &$captured_timeout ) {
			$captured_timeout = $args['timeout'] ?? null;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode( array(
					array( 'serviceCode' => 'usps_priority_mail', 'shipmentCost' => 7.99, 'otherCost' => 0.00 ),
				) ),
			);
		};

		$this->call_protected( 'request_all_rates', array( $this->make_ship_to(), $this->make_candidate() ) );

		// Default timeout is now 8 seconds (down from 30).
		$this->assertSame( 8, $captured_timeout );

		// Filter override applies.
		$GLOBALS['_test_wp_filters']['fk_usps_optimizer_api_timeout'][] = static function () {
			return 12;
		};
		$captured_timeout = null;
		// New cache key — pass a different candidate to dodge the previous cache entry.
		$other = $this->make_candidate();
		$other['package_code'] = 'large_package';
		$this->call_protected( 'request_all_rates', array( $this->make_ship_to(), $other ) );
		$this->assertSame( 12, $captured_timeout );
	}

	public function test_cap_candidates_keeps_smallest_n_by_volume(): void {
		// Default cap is 3.  Provide 5 candidates with distinct volumes.
		$candidates = array(
			array( 'package_code' => 'huge',   'dimensions' => array( 'length' => 20, 'width' => 20, 'height' => 20 ) ),
			array( 'package_code' => 'tiny',   'dimensions' => array( 'length' => 2,  'width' => 2,  'height' => 2  ) ),
			array( 'package_code' => 'small',  'dimensions' => array( 'length' => 4,  'width' => 4,  'height' => 4  ) ),
			array( 'package_code' => 'big',    'dimensions' => array( 'length' => 12, 'width' => 12, 'height' => 12 ) ),
			array( 'package_code' => 'medium', 'dimensions' => array( 'length' => 8,  'width' => 8,  'height' => 8  ) ),
		);

		$capped = $this->call_protected( 'cap_candidates', array( $candidates ) );

		$this->assertCount( 3, $capped );
		$codes = array_map( static fn( array $c ) => $c['package_code'], $capped );
		$this->assertSame( array( 'tiny', 'small', 'medium' ), $codes );
	}

	public function test_cap_candidates_filter_can_disable_cap(): void {
		$candidates = array(
			array( 'package_code' => 'a', 'dimensions' => array( 'length' => 1, 'width' => 1, 'height' => 1 ) ),
			array( 'package_code' => 'b', 'dimensions' => array( 'length' => 2, 'width' => 2, 'height' => 2 ) ),
			array( 'package_code' => 'c', 'dimensions' => array( 'length' => 3, 'width' => 3, 'height' => 3 ) ),
			array( 'package_code' => 'd', 'dimensions' => array( 'length' => 4, 'width' => 4, 'height' => 4 ) ),
			array( 'package_code' => 'e', 'dimensions' => array( 'length' => 5, 'width' => 5, 'height' => 5 ) ),
		);

		$GLOBALS['_test_wp_filters']['fk_usps_optimizer_max_candidates'][] = static function () {
			return 0;
		};

		$capped = $this->call_protected( 'cap_candidates', array( $candidates ) );
		$this->assertCount( 5, $capped );
	}

	public function test_build_all_plans_filters_to_allowed_service_codes(): void {
		$settings = $this->createMock( Settings::class );
		$settings->method( 'get_boxes_for_carrier' )->willReturn( array( $this->make_box() ) );
		$settings->method( 'get_shipstation_api_key' )->willReturn( 'test_key' );
		$settings->method( 'get_shipstation_api_secret' )->willReturn( 'test_secret' );
		$settings->method( 'get_shipstation_carrier_code' )->willReturn( 'ups_walleted' );
		$settings->method( 'get_shipstation_service_code' )->willReturn( '' );
		$settings->method( 'get_ship_from_address' )->willReturn( array( 'postal_code' => '90210' ) );
		$settings->method( 'is_debug_logging_enabled' )->willReturn( false );
		$settings->method( 'is_sandbox_mode_enabled' )->willReturn( false );
		$settings->method( 'is_use_default_transit_days_enabled' )->willReturn( false );

		// API returns 4 services for UPS, but the admin only asked for 2.
		$GLOBALS['_test_wp_remote_post'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array(
				array( 'serviceCode' => 'ups_ground',         'shipmentCost' => 9.00,  'otherCost' => 0 ),
				array( 'serviceCode' => 'ups_3_day_select',   'shipmentCost' => 12.00, 'otherCost' => 0 ),
				array( 'serviceCode' => 'ups_2nd_day_air',    'shipmentCost' => 15.00, 'otherCost' => 0 ),
				array( 'serviceCode' => 'ups_next_day_air',   'shipmentCost' => 25.00, 'otherCost' => 0 ),
			) ),
		);

		$service = new ShipStation_Service(
			$settings,
			'ups_walleted',
			'',
			array( 'ups_ground', 'ups_next_day_air' )
		);

		$plans = $service->build_all_test_package_plans( $this->make_package(), $this->make_ship_to(), 1 );

		// Only the configured services should remain.
		$this->assertCount( 2, $plans );

		$service_codes = array_map( static fn( array $p ) => $p['service_code'], $plans );
		sort( $service_codes );
		$this->assertSame( array( 'ups_ground', 'ups_next_day_air' ), $service_codes );

		// Allow-list is exposed via getter.
		$this->assertSame( array( 'ups_ground', 'ups_next_day_air' ), $service->get_allowed_service_codes() );
	}

	public function test_build_rate_cache_key_changes_with_inputs(): void {
		$payload_a = array(
			'carrierCode'    => 'stamps_com',
			'serviceCode'    => 'usps_priority_mail',
			'packageCode'    => 'package',
			'fromPostalCode' => '90210',
			'toCountry'      => 'US',
			'toState'        => 'TX',
			'toPostalCode'   => '78701',
			'dimensions'     => array( 'length' => 8, 'width' => 8, 'height' => 6 ),
			'weight'         => array( 'value' => 19.0, 'units' => 'ounces' ),
		);
		$payload_b              = $payload_a;
		$payload_b['toPostalCode'] = '78702';

		$key_a = $this->call_protected( 'build_rate_cache_key', array( $payload_a ) );
		$key_b = $this->call_protected( 'build_rate_cache_key', array( $payload_b ) );

		$this->assertNotSame( $key_a, $key_b );
		$this->assertStringStartsWith( 'fk_usps_opt_ss_', $key_a );

		// Same payload — same key.
		$key_a2 = $this->call_protected( 'build_rate_cache_key', array( $payload_a ) );
		$this->assertSame( $key_a, $key_a2 );
	}

	// -------------------------------------------------------------------------
	// Phase 2 optimizations: parallel HTTP dispatch via Requests::request_multiple.
	// -------------------------------------------------------------------------

	/**
	 * Build N boxes with distinct package_codes / dimensions so each yields
	 * a separate cache key and a separate parallel request.
	 *
	 * @param int $count Number of boxes to build.
	 * @return array[]
	 */
	private function make_distinct_boxes( int $count ): array {
		$boxes = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$dim     = 6 + $i; // 6, 7, 8, …
			$boxes[] = $this->make_box(
				array(
					'reference'    => 'Box ' . $i,
					'package_code' => 'package_' . $i,
					'package_name' => 'Box ' . $i,
					'outer_width'  => (float) $dim,
					'outer_length' => (float) $dim,
					'outer_depth'  => (float) $dim,
					'inner_width'  => (float) $dim,
					'inner_length' => (float) $dim,
					'inner_depth'  => (float) $dim,
				)
			);
		}
		return $boxes;
	}

	public function test_build_all_plans_dispatches_a_single_parallel_batch(): void {
		$settings = $this->createMock( Settings::class );
		$settings->method( 'get_boxes_for_carrier' )->willReturn( $this->make_distinct_boxes( 3 ) );
		$settings->method( 'get_shipstation_api_key' )->willReturn( 'test_key' );
		$settings->method( 'get_shipstation_api_secret' )->willReturn( 'test_secret' );
		$settings->method( 'get_shipstation_carrier_code' )->willReturn( 'stamps_com' );
		$settings->method( 'get_shipstation_service_code' )->willReturn( 'usps_priority_mail' );
		$settings->method( 'get_ship_from_address' )->willReturn( array( 'postal_code' => '90210' ) );
		$settings->method( 'is_debug_logging_enabled' )->willReturn( false );
		$settings->method( 'is_sandbox_mode_enabled' )->willReturn( false );
		$settings->method( 'is_use_default_transit_days_enabled' )->willReturn( false );

		$batch_calls    = 0;
		$last_batch_size = 0;

		$GLOBALS['_test_wp_requests_multiple'] = function ( array $requests ) use ( &$batch_calls, &$last_batch_size ) {
			++$batch_calls;
			$last_batch_size = count( $requests );

			$responses = array();
			$cost      = 5.00;
			foreach ( $requests as $key => $req ) {
				$responses[ $key ] = array(
					'response' => array( 'code' => 200 ),
					'body'     => json_encode( array(
						array(
							'serviceCode'  => 'usps_priority_mail',
							'shipmentCost' => $cost,
							'otherCost'    => 0.00,
						),
					) ),
				);
				$cost += 1.00;
			}
			return $responses;
		};

		// If anything sneaks through to the per-call wp_remote_post path, fail
		// the test by returning a 500 so plans don't materialise.
		$GLOBALS['_test_wp_remote_post'] = array(
			'response' => array( 'code' => 500 ),
			'body'     => '',
		);

		$service = new ShipStation_Service( $settings );
		$plans   = $service->build_all_test_package_plans( $this->make_package(), $this->make_ship_to(), 1 );

		$this->assertSame( 1, $batch_calls, 'Parallel dispatch should be invoked exactly once.' );
		$this->assertSame( 3, $last_batch_size, 'Batch should contain one spec per candidate.' );
		$this->assertCount( 3, $plans, 'One plan per candidate should be produced from the batch.' );

		// Sorted cheapest-first.
		$this->assertSame( 5.00, $plans[0]['rate_amount'] );
		$this->assertSame( 6.00, $plans[1]['rate_amount'] );
		$this->assertSame( 7.00, $plans[2]['rate_amount'] );
	}

	public function test_build_all_plans_excludes_cache_hits_from_parallel_batch(): void {
		$settings = $this->createMock( Settings::class );
		$settings->method( 'get_boxes_for_carrier' )->willReturn( $this->make_distinct_boxes( 3 ) );
		$settings->method( 'get_shipstation_api_key' )->willReturn( 'test_key' );
		$settings->method( 'get_shipstation_api_secret' )->willReturn( 'test_secret' );
		$settings->method( 'get_shipstation_carrier_code' )->willReturn( 'stamps_com' );
		$settings->method( 'get_shipstation_service_code' )->willReturn( 'usps_priority_mail' );
		$settings->method( 'get_ship_from_address' )->willReturn( array( 'postal_code' => '90210' ) );
		$settings->method( 'is_debug_logging_enabled' )->willReturn( false );
		$settings->method( 'is_sandbox_mode_enabled' )->willReturn( false );
		$settings->method( 'is_use_default_transit_days_enabled' )->willReturn( false );

		$service = new ShipStation_Service( $settings );

		// Pre-warm the cache for two of the three candidates.  These hits
		// should bypass the parallel batch entirely.
		$ship_to    = $this->make_ship_to();
		$candidates = $this->call_protected_on( $service, 'build_candidates', array( $this->make_package() ) );
		$this->assertCount( 3, $candidates );

		foreach ( array( 0, 2 ) as $idx ) {
			$descriptor = $this->call_protected_on( $service, 'build_rate_request_descriptor', array( $ship_to, $candidates[ $idx ], 0 ) );
			$this->assertNotNull( $descriptor );
			set_transient(
				$descriptor['cache_key'],
				array(
					array(
						'serviceCode'  => 'usps_priority_mail',
						'shipmentCost' => 1.23 + $idx,
						'otherCost'    => 0.00,
					),
				),
				300
			);
		}

		$batch_specs                          = array();
		$GLOBALS['_test_wp_requests_multiple'] = function ( array $requests ) use ( &$batch_specs ) {
			$batch_specs = $requests;
			$responses   = array();
			foreach ( $requests as $key => $req ) {
				$responses[ $key ] = array(
					'response' => array( 'code' => 200 ),
					'body'     => json_encode( array(
						array(
							'serviceCode'  => 'usps_priority_mail',
							'shipmentCost' => 9.99,
							'otherCost'    => 0.00,
						),
					) ),
				);
			}
			return $responses;
		};

		$plans = $service->build_all_test_package_plans( $this->make_package(), $ship_to, 1 );

		// Only the single uncached candidate (index 1) should be in the batch.
		$this->assertCount( 1, $batch_specs );
		$this->assertSame( array( 1 ), array_keys( $batch_specs ) );

		// Three plans total: two from the cache, one from the batch.
		$this->assertCount( 3, $plans );

		$rates = array_map( static fn( array $p ) => $p['rate_amount'], $plans );
		sort( $rates );
		$this->assertSame( array( 1.23, 3.23, 9.99 ), $rates );
	}

	public function test_build_all_plans_skips_failed_parallel_response(): void {
		$settings = $this->createMock( Settings::class );
		$settings->method( 'get_boxes_for_carrier' )->willReturn( $this->make_distinct_boxes( 2 ) );
		$settings->method( 'get_shipstation_api_key' )->willReturn( 'test_key' );
		$settings->method( 'get_shipstation_api_secret' )->willReturn( 'test_secret' );
		$settings->method( 'get_shipstation_carrier_code' )->willReturn( 'stamps_com' );
		$settings->method( 'get_shipstation_service_code' )->willReturn( 'usps_priority_mail' );
		$settings->method( 'get_ship_from_address' )->willReturn( array( 'postal_code' => '90210' ) );
		$settings->method( 'is_debug_logging_enabled' )->willReturn( false );
		$settings->method( 'is_sandbox_mode_enabled' )->willReturn( false );
		$settings->method( 'is_use_default_transit_days_enabled' )->willReturn( false );

		$GLOBALS['_test_wp_requests_multiple'] = function ( array $requests ) {
			$responses = array();
			$first     = true;
			foreach ( $requests as $key => $req ) {
				if ( $first ) {
					$responses[ $key ] = new \WP_Error( 'http_request_failed', 'cURL error 28' );
					$first             = false;
				} else {
					$responses[ $key ] = array(
						'response' => array( 'code' => 200 ),
						'body'     => json_encode( array(
							array(
								'serviceCode'  => 'usps_priority_mail',
								'shipmentCost' => 4.50,
								'otherCost'    => 0.00,
							),
						) ),
					);
				}
			}
			return $responses;
		};

		$service = new ShipStation_Service( $settings );
		$plans   = $service->build_all_test_package_plans( $this->make_package(), $this->make_ship_to(), 1 );

		// Only the second candidate produced a plan; the first (failed) was skipped.
		$this->assertCount( 1, $plans );
		$this->assertSame( 4.50, $plans[0]['rate_amount'] );
	}

	public function test_dispatch_requests_multi_falls_back_to_sequential_wp_remote_post(): void {
		// No _test_wp_requests_multiple stub: should hit the sequential
		// fallback (WpOrg\Requests\Requests is not loaded under the unit
		// test bootstrap).  We assert each request hits wp_remote_post.
		$call_count                       = 0;
		$urls                             = array();
		$GLOBALS['_test_wp_remote_post'] = function ( string $url ) use ( &$call_count, &$urls ) {
			++$call_count;
			$urls[] = $url;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => '{}',
			);
		};

		$out = $this->call_protected(
			'dispatch_requests_multi',
			array(
				array(
					'a' => array( 'url' => 'https://a.example/x', 'headers' => array(), 'data' => '{}', 'timeout' => 8 ),
					'b' => array( 'url' => 'https://b.example/y', 'headers' => array(), 'data' => '{}', 'timeout' => 8 ),
				),
			)
		);

		$this->assertSame( 2, $call_count );
		$this->assertSame( array( 'https://a.example/x', 'https://b.example/y' ), $urls );
		$this->assertArrayHasKey( 'a', $out );
		$this->assertArrayHasKey( 'b', $out );
	}

	/**
	 * Reflection helper that invokes a protected method on a *specific*
	 * service instance (the standard {@see call_protected()} only operates
	 * on `$this->service`).
	 *
	 * @param object $instance Target instance.
	 * @param string $method   Method name.
	 * @param array  $args     Arguments.
	 * @return mixed
	 */
	// -------------------------------------------------------------------------
	// Pair-level error detection + bad-pair short-circuit (A1, A2, A3)
	// -------------------------------------------------------------------------

	public function test_parse_rate_response_logs_payload_and_api_message_on_500(): void {
		$this->settings->method( 'is_debug_logging_enabled' )->willReturn( true );
		$this->settings->method( 'is_sandbox_mode_enabled' )->willReturn( false );

		$payload  = array(
			'carrierCode' => 'stamps_com',
			'serviceCode' => 'usps_ground_advantage',
			'packageCode' => 'package',
			'weight'      => array( 'value' => 18, 'units' => 'ounces' ),
			'dimensions'  => array( 'length' => 8, 'width' => 8, 'height' => 6 ),
		);
		$response = array(
			'response' => array( 'code' => 500 ),
			'body'     => json_encode( array( 'Message' => 'One or more providers reported an error' ) ),
		);

		$result = $this->call_protected( 'parse_rate_response', array( $response, 0, $payload ) );

		$this->assertFalse( $result['success'] );
		$this->assertTrue( ! empty( $result['pair_level_err'] ) );

		$logged = $GLOBALS['_test_wc_logger']->logs[0]['message'] ?? '';
		$this->assertStringContainsString( 'stamps_com', $logged );
		$this->assertStringContainsString( 'usps_ground_advantage', $logged );
		$this->assertStringContainsString( 'One or more providers reported an error', $logged );
	}

	public function test_is_pair_level_error_recognizes_unsupported_service(): void {
		$result = $this->call_protected( 'is_pair_level_error', array( 400, "service code 'usps_ground_advantage' is not supported", null ) );
		$this->assertTrue( $result );
	}

	public function test_is_pair_level_error_treats_generic_500_as_transient(): void {
		$result = $this->call_protected( 'is_pair_level_error', array( 500, '', null ) );
		$this->assertFalse( $result );
	}

	public function test_request_all_rates_short_circuits_after_pair_level_failure(): void {
		$this->configure_credentials();

		$call_count                      = 0;
		$GLOBALS['_test_wp_remote_post'] = function () use ( &$call_count ) {
			++$call_count;
			return array(
				'response' => array( 'code' => 500 ),
				'body'     => json_encode( array( 'Message' => 'One or more providers reported an error' ) ),
			);
		};

		$first  = $this->call_protected( 'request_all_rates', array( $this->make_ship_to(), $this->make_candidate() ) );
		$second = $this->call_protected( 'request_all_rates', array( $this->make_ship_to(), $this->make_candidate() ) );

		$this->assertFalse( $first['success'] );
		$this->assertFalse( $second['success'] );
		// First call hits the API; second is short-circuited by the bad-pair flag.
		$this->assertSame( 1, $call_count );
	}

	public function test_request_all_rates_short_circuit_uses_negative_cache(): void {
		$this->configure_credentials();

		// Pre-populate the negative-cache transient as if a prior request had
		// already flagged this pair as bad.
		$transient_key = $this->call_protected( 'bad_pair_transient_key', array( 'stamps_com', 'usps_priority_mail' ) );
		set_transient( $transient_key, 1, 60 );

		$call_count                      = 0;
		$GLOBALS['_test_wp_remote_post'] = function () use ( &$call_count ) {
			++$call_count;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode( array( array( 'serviceCode' => 'usps_priority_mail', 'shipmentCost' => 5.0, 'otherCost' => 0.0 ) ) ),
			);
		};

		$result = $this->call_protected( 'request_all_rates', array( $this->make_ship_to(), $this->make_candidate() ) );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 0, $call_count, 'Negative-cache hit should bypass the API entirely.' );
	}

	public function test_negative_cache_bypassed_in_sandbox_mode(): void {
		$this->settings->method( 'get_shipstation_api_key' )->willReturn( 'test_key' );
		$this->settings->method( 'get_shipstation_api_secret' )->willReturn( 'test_secret' );
		$this->settings->method( 'get_shipstation_carrier_code' )->willReturn( 'stamps_com' );
		$this->settings->method( 'get_shipstation_service_code' )->willReturn( 'usps_priority_mail' );
		$this->settings->method( 'get_ship_from_address' )->willReturn( array( 'postal_code' => '90210' ) );
		$this->settings->method( 'is_debug_logging_enabled' )->willReturn( false );
		$this->settings->method( 'is_sandbox_mode_enabled' )->willReturn( true );

		$transient_key = $this->call_protected( 'bad_pair_transient_key', array( 'stamps_com', 'usps_priority_mail' ) );
		set_transient( $transient_key, 1, 60 );

		$call_count                      = 0;
		$GLOBALS['_test_wp_remote_post'] = function () use ( &$call_count ) {
			++$call_count;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode( array( array( 'serviceCode' => 'usps_priority_mail', 'shipmentCost' => 5.0, 'otherCost' => 0.0 ) ) ),
			);
		};

		$result = $this->call_protected( 'request_all_rates', array( $this->make_ship_to(), $this->make_candidate() ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $call_count, 'Sandbox mode should bypass the negative cache.' );
	}

	public function test_extract_api_error_message_handles_errors_array(): void {
		$body = array( 'errors' => array( array( 'message' => 'svc not allowed' ), 'flat-string-error' ) );
		$msg  = $this->call_protected( 'extract_api_error_message', array( $body ) );
		$this->assertStringContainsString( 'svc not allowed', $msg );
		$this->assertStringContainsString( 'flat-string-error', $msg );
	}

	// -------------------------------------------------------------------------
	// A5 — graceful per-pair failure (one bad pair doesn't break the others)
	// -------------------------------------------------------------------------

	public function test_one_pair_500_does_not_suppress_other_pair_via_plugin(): void {
		// Two ShipStation_Service instances stand in for two configured pairs.
		// The first instance always returns a pair-level 500; the second
		// returns valid rates.  Verifies that calculate_all_options-style
		// iteration in Plugin still surfaces the good pair's rates.
		$bad_settings = $this->createMock( Settings::class );
		$bad_settings->method( 'get_boxes_for_carrier' )->willReturn( array( $this->make_box() ) );
		$bad_settings->method( 'get_shipstation_api_key' )->willReturn( 'k' );
		$bad_settings->method( 'get_shipstation_api_secret' )->willReturn( 's' );
		$bad_settings->method( 'get_shipstation_carrier_code' )->willReturn( 'stamps_com' );
		$bad_settings->method( 'get_shipstation_service_code' )->willReturn( 'usps_ground_advantage' );
		$bad_settings->method( 'get_ship_from_address' )->willReturn( array( 'postal_code' => '90210' ) );
		$bad_settings->method( 'is_debug_logging_enabled' )->willReturn( false );
		$bad_settings->method( 'is_sandbox_mode_enabled' )->willReturn( false );
		$bad_settings->method( 'is_use_default_transit_days_enabled' )->willReturn( false );

		$good_settings = $this->createMock( Settings::class );
		$good_settings->method( 'get_boxes_for_carrier' )->willReturn( array( $this->make_box() ) );
		$good_settings->method( 'get_shipstation_api_key' )->willReturn( 'k' );
		$good_settings->method( 'get_shipstation_api_secret' )->willReturn( 's' );
		$good_settings->method( 'get_shipstation_carrier_code' )->willReturn( 'ups_walleted' );
		$good_settings->method( 'get_shipstation_service_code' )->willReturn( 'ups_ground' );
		$good_settings->method( 'get_ship_from_address' )->willReturn( array( 'postal_code' => '90210' ) );
		$good_settings->method( 'is_debug_logging_enabled' )->willReturn( false );
		$good_settings->method( 'is_sandbox_mode_enabled' )->willReturn( false );
		$good_settings->method( 'is_use_default_transit_days_enabled' )->willReturn( false );

		$bad_service  = new ShipStation_Service( $bad_settings );
		$good_service = new ShipStation_Service( $good_settings );

		$GLOBALS['_test_wp_remote_post'] = function ( string $url, array $args ) {
			$body = json_decode( (string) ( $args['body'] ?? '' ), true );
			if ( 'stamps_com' === ( $body['carrierCode'] ?? '' ) ) {
				return array(
					'response' => array( 'code' => 500 ),
					'body'     => json_encode( array( 'Message' => 'One or more providers reported an error' ) ),
				);
			}
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode( array(
					array( 'serviceCode' => 'ups_ground', 'shipmentCost' => 8.49, 'otherCost' => 0 ),
				) ),
			);
		};

		$bad_plans  = $bad_service->build_all_test_package_plans( $this->make_package(), $this->make_ship_to(), 1 );
		$good_plans = $good_service->build_all_test_package_plans( $this->make_package(), $this->make_ship_to(), 1 );

		$this->assertSame( array(), $bad_plans, 'Bad pair should produce no plans.' );
		$this->assertNotEmpty( $good_plans, 'Good pair should still produce rates despite the bad pair failing.' );
		$this->assertSame( 8.49, $good_plans[0]['rate_amount'] );
	}

	// -------------------------------------------------------------------------
	// A4 — test_connection validates configured service codes
	// -------------------------------------------------------------------------

	public function test_connection_flags_unknown_service_code_for_carrier(): void {
		$this->settings->method( 'get_shipstation_carrier_code' )->willReturn( 'stamps_com' );
		$this->settings->method( 'get_shipstation_service_code' )->willReturn( 'usps_ground_advantage' );
		$this->settings->method( 'get_shipstation_service_pairs' )->willReturn(
			array(
				array( 'carrier_code' => 'stamps_com', 'service_code' => 'usps_ground_advantage' ),
			)
		);

		$GLOBALS['_test_wp_remote_get'] = function ( string $url ) {
			if ( false !== strpos( $url, '/services' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => json_encode( array(
						array( 'code' => 'usps_priority_mail', 'name' => 'USPS Priority Mail' ),
						array( 'code' => 'usps_first_class_mail', 'name' => 'USPS First Class Mail' ),
					) ),
				);
			}
			// /carriers
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode( array(
					array( 'code' => 'stamps_com', 'name' => 'USPS' ),
				) ),
			);
		};

		$result = $this->service->test_connection( 'k', 's' );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'usps_ground_advantage', $result['message'] );
		$this->assertStringContainsString( 'stamps_com', $result['message'] );
		$this->assertStringContainsString( 'usps_priority_mail', $result['message'] );
	}

	public function test_connection_passes_when_all_configured_services_exist(): void {
		$this->settings->method( 'get_shipstation_carrier_code' )->willReturn( 'stamps_com' );
		$this->settings->method( 'get_shipstation_service_code' )->willReturn( 'usps_priority_mail' );
		$this->settings->method( 'get_shipstation_service_pairs' )->willReturn(
			array(
				array( 'carrier_code' => 'stamps_com',  'service_code' => 'usps_priority_mail' ),
				array( 'carrier_code' => 'ups_walleted', 'service_code' => 'ups_ground' ),
			)
		);

		$GLOBALS['_test_wp_remote_get'] = function ( string $url ) {
			if ( false !== strpos( $url, 'carriers/stamps_com/services' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => json_encode( array(
						array( 'code' => 'usps_priority_mail' ),
					) ),
				);
			}
			if ( false !== strpos( $url, 'carriers/ups_walleted/services' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => json_encode( array(
						array( 'code' => 'ups_ground' ),
					) ),
				);
			}
			// /carriers
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode( array(
					array( 'code' => 'stamps_com' ),
					array( 'code' => 'ups_walleted' ),
				) ),
			);
		};

		$result = $this->service->test_connection( 'k', 's' );

		$this->assertTrue( $result['success'], $result['message'] ?? '' );
	}

	public function test_connection_skips_service_validation_for_empty_service_code(): void {
		// An empty service_code is a valid configuration meaning "all services
		// for this carrier"; it must not trigger a "service not found" error.
		$this->settings->method( 'get_shipstation_carrier_code' )->willReturn( 'stamps_com' );
		$this->settings->method( 'get_shipstation_service_code' )->willReturn( '' );
		$this->settings->method( 'get_shipstation_service_pairs' )->willReturn(
			array(
				array( 'carrier_code' => 'stamps_com', 'service_code' => '' ),
			)
		);

		$GLOBALS['_test_wp_remote_get'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array( array( 'code' => 'stamps_com' ) ) ),
		);

		$result = $this->service->test_connection( 'k', 's' );

		$this->assertTrue( $result['success'] );
	}

	// -------------------------------------------------------------------------
	// B2 / B3 — package_fits_box uses content_dimensions; smaller box reachable
	// -------------------------------------------------------------------------

	public function test_package_fits_box_uses_content_dimensions_when_present(): void {
		// Even though `dimensions` matches a 12×12×12 box, content_dimensions
		// of the actual item (4×4×4) lets a smaller 6×6×6 box be considered.
		$package = array(
			'dimensions'         => array( 'length' => 12.0, 'width' => 12.0, 'height' => 12.0 ),
			'content_dimensions' => array( 'length' => 4.0, 'width' => 4.0, 'height' => 4.0 ),
			'weight_oz'          => 16.0,
			'items'              => array(),
		);
		$smaller_box = $this->make_box( array(
			'inner_length' => 6.0,
			'inner_width'  => 6.0,
			'inner_depth'  => 6.0,
		) );

		$this->assertTrue( $this->call_protected( 'package_fits_box', array( $package, $smaller_box ) ) );
	}

	public function test_package_fits_box_falls_back_to_dimensions_without_content_dimensions(): void {
		$package = array(
			'dimensions' => array( 'length' => 12.0, 'width' => 12.0, 'height' => 12.0 ),
			'weight_oz'  => 16.0,
			'items'      => array(),
		);
		$smaller_box = $this->make_box( array(
			'inner_length' => 6.0,
			'inner_width'  => 6.0,
			'inner_depth'  => 6.0,
		) );

		$this->assertFalse( $this->call_protected( 'package_fits_box', array( $package, $smaller_box ) ) );
	}

	public function test_build_candidates_includes_smaller_box_when_content_fits(): void {
		// build_candidates should consider the smaller 6×6×6 box too.
		$big_box = $this->make_box( array(
			'reference'    => 'Big',
			'package_code' => 'pkg_big',
			'package_name' => 'Big',
			'box_type'     => 'package',
			'outer_length' => 12.0, 'outer_width' => 12.0, 'outer_depth' => 12.0,
			'inner_length' => 12.0, 'inner_width' => 12.0, 'inner_depth' => 12.0,
		) );
		$small_box = $this->make_box( array(
			'reference'    => 'Small',
			'package_code' => 'pkg_small',
			'package_name' => 'Small',
			'box_type'     => 'package',
			'outer_length' => 6.0, 'outer_width' => 6.0, 'outer_depth' => 6.0,
			'inner_length' => 6.0, 'inner_width' => 6.0, 'inner_depth' => 6.0,
		) );

		$this->settings->method( 'get_boxes_for_carrier' )->willReturn( array( $small_box, $big_box ) );
		$this->settings->method( 'get_shipstation_carrier_code' )->willReturn( 'stamps_com' );

		$package = array(
			'packed_box'         => $big_box,
			'dimensions'         => array( 'length' => 12.0, 'width' => 12.0, 'height' => 12.0 ),
			'content_dimensions' => array( 'length' => 4.0, 'width' => 4.0, 'height' => 4.0 ),
			'weight_oz'          => 16.0,
			'items'              => array(
				array(
					'name'           => 'Widget',
					'product_id'     => 1,
					'length'         => 4.0,
					'width'          => 4.0,
					'height'         => 4.0,
					'weight_oz'      => 16.0,
					'has_dimensions' => true,
				),
			),
		);

		$candidates = $this->call_protected( 'build_candidates', array( $package ) );

		$package_codes = array_map(
			static function ( array $c ): string {
				return (string) $c['package_code'];
			},
			$candidates
		);

		$this->assertContains( 'pkg_small', $package_codes, 'Smaller box should be a candidate.' );
		$this->assertContains( 'pkg_big', $package_codes, 'Original box should still be a candidate.' );
	}

	/**
	 * Invoke a protected method via reflection on a specific instance (rather than
	 * on `$this->service`).
	 *
	 * @param object $instance Target instance.
	 * @param string $method   Method name.
	 * @param array  $args     Arguments.
	 * @return mixed
	 */
	private function call_protected_on( object $instance, string $method, array $args = array() ) {
		$ref = new \ReflectionMethod( $instance, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $instance, $args );
	}
}
