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
		$GLOBALS['_test_wp_remote_get']  = null;
		$GLOBALS['_test_wp_remote_post'] = null;
		$GLOBALS['_test_wc_logger']      = new \WC_Test_Logger();
		$GLOBALS['_test_wp_filters']     = array();
		$GLOBALS['_test_wp_options']     = array();

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
		$this->settings->method( 'get_boxes' )->willReturn( array( $this->make_box() ) );
		$candidates = $this->call_protected( 'build_candidates', array( $this->make_package() ) );
		$this->assertCount( 1, $candidates );
		$this->assertSame( 'cubic', $candidates[0]['mode'] );
	}

	public function test_build_candidates_excludes_box_when_package_too_heavy(): void {
		$this->settings->method( 'get_boxes' )->willReturn( array( $this->make_box( array( 'max_weight' => 1.0 ) ) ) );
		$candidates = $this->call_protected( 'build_candidates', array( $this->make_package( array( 'weight_oz' => 400.0 ) ) ) );
		$this->assertCount( 0, $candidates );
	}

	public function test_build_candidates_includes_flat_rate_without_cubic_check(): void {
		$box = $this->make_box( array( 'box_type' => 'flat_rate', 'package_code' => 'small_flat_rate_box', 'max_weight' => 70 ) );
		$this->settings->method( 'get_boxes' )->willReturn( array( $box ) );
		$candidates = $this->call_protected( 'build_candidates', array( $this->make_package() ) );
		$this->assertCount( 1, $candidates );
		$this->assertSame( 'flat_rate_box', $candidates[0]['mode'] );
	}

	public function test_build_candidates_adds_box_empty_weight_to_total(): void {
		$box = $this->make_box( array( 'empty_weight' => 4.0 ) );
		$this->settings->method( 'get_boxes' )->willReturn( array( $box ) );
		$candidates = $this->call_protected( 'build_candidates', array( $this->make_package( array( 'weight_oz' => 10.0 ) ) ) );
		$this->assertSame( 14.0, $candidates[0]['weight_oz'] );
	}

	public function test_build_candidates_cubic_tier_empty_for_flat_rate(): void {
		$box = $this->make_box( array( 'box_type' => 'flat_rate' ) );
		$this->settings->method( 'get_boxes' )->willReturn( array( $box ) );
		$candidates = $this->call_protected( 'build_candidates', array( $this->make_package() ) );
		$this->assertSame( '', $candidates[0]['cubic_tier'] );
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
		$this->settings->method( 'get_boxes' )->willReturn( array() );
		$result = $this->service->build_test_package_plan( $this->make_package(), $this->make_ship_to(), 1 );
		$this->assertSame( array(), $result );
	}

	public function test_build_test_package_plan_returns_empty_when_rate_fails(): void {
		$box = $this->make_box();
		$this->settings->method( 'get_boxes' )->willReturn( array( $box ) );
		$this->settings->method( 'get_shipstation_api_key' )->willReturn( '' );
		$this->settings->method( 'get_shipstation_api_secret' )->willReturn( '' );
		$this->settings->method( 'is_debug_logging_enabled' )->willReturn( false );
		$this->settings->method( 'is_sandbox_mode_enabled' )->willReturn( false );

		$result = $this->service->build_test_package_plan( $this->make_package(), $this->make_ship_to(), 1 );
		$this->assertSame( array(), $result );
	}

	public function test_build_test_package_plan_returns_populated_plan(): void {
		$this->settings->method( 'get_boxes' )->willReturn( array( $this->make_box() ) );
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

		$this->settings->method( 'get_boxes' )->willReturn( array( $box_a, $box_b ) );
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

		$this->settings->method( 'get_boxes' )->willReturn( array( $this->make_box() ) );
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

		$this->settings->method( 'get_boxes' )->willReturn( array( $box_a, $box_b ) );
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
		$this->settings->method( 'get_boxes' )->willReturn( array() );

		$plans = $this->service->build_all_test_package_plans( $this->make_package(), $this->make_ship_to(), 1 );

		$this->assertSame( array(), $plans );
	}

	public function test_build_all_test_package_plans_uses_configured_service_code(): void {
		$this->settings->method( 'get_boxes' )->willReturn( array( $this->make_box() ) );
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
}
