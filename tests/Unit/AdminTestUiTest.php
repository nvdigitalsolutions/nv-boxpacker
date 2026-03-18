<?php
/**
 * Unit tests for Admin_Test_UI.
 *
 * @package FK_USPS_Optimizer
 */

namespace FK_USPS_Optimizer\Tests\Unit;

use FK_USPS_Optimizer\Admin_Test_UI;
use FK_USPS_Optimizer\Settings;
use FK_USPS_Optimizer\Test_Pricing_Service;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Admin_Test_UI.
 */
class AdminTestUiTest extends TestCase {

	/**
	 * Settings mock.
	 *
	 * @var Settings|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $settings;

	/**
	 * Test pricing service mock.
	 *
	 * @var Test_Pricing_Service|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $test_pricing_service;

	/**
	 * System under test.
	 *
	 * @var Admin_Test_UI
	 */
	private Admin_Test_UI $admin_test_ui;

	protected function setUp(): void {
		$GLOBALS['_test_current_user_can'] = true;
		$GLOBALS['_test_wp_filters']       = array();

		$this->settings             = $this->createMock( Settings::class );
		$this->test_pricing_service = $this->createMock( Test_Pricing_Service::class );
		$this->admin_test_ui        = new Admin_Test_UI( $this->settings, $this->test_pricing_service );
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
		$ref = new \ReflectionMethod( $this->admin_test_ui, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $this->admin_test_ui, $args );
	}

	// -------------------------------------------------------------------------
	// register / register_menu
	// -------------------------------------------------------------------------

	public function test_register_runs_without_error(): void {
		$this->admin_test_ui->register();
		$this->assertTrue( true );
	}

	public function test_register_menu_runs_without_error(): void {
		$this->admin_test_ui->register_menu();
		$this->assertTrue( true );
	}

	// -------------------------------------------------------------------------
	// render_page — GET request (no submission)
	// -------------------------------------------------------------------------

	public function test_render_page_outputs_form_on_get_request(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->settings->method( 'is_sandbox_mode_enabled' )->willReturn( false );
		$this->settings->method( 'get_carrier' )->willReturn( 'shipengine' );

		ob_start();
		$this->admin_test_ui->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<form', $output );
		$this->assertStringContainsString( 'fk_usps_test_nonce', $output );
		$this->assertStringContainsString( 'fk-test-items-table', $output );
	}

	public function test_render_page_shows_sandbox_banner_when_active(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->settings->method( 'is_sandbox_mode_enabled' )->willReturn( true );
		$this->settings->method( 'get_carrier' )->willReturn( 'shipengine' );

		ob_start();
		$this->admin_test_ui->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Sandbox', $output );
		$this->assertStringContainsString( 'notice-warning', $output );
	}

	public function test_render_page_does_not_show_sandbox_banner_when_inactive(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->settings->method( 'is_sandbox_mode_enabled' )->willReturn( false );
		$this->settings->method( 'get_carrier' )->willReturn( 'shipengine' );

		ob_start();
		$this->admin_test_ui->render_page();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'Sandbox Mode Active', $output );
	}

	public function test_render_page_shows_carrier_name(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->settings->method( 'is_sandbox_mode_enabled' )->willReturn( false );
		$this->settings->method( 'get_carrier' )->willReturn( 'shipstation' );

		ob_start();
		$this->admin_test_ui->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'ShipStation', $output );
	}

	public function test_render_page_shows_shipengine_carrier_name(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->settings->method( 'is_sandbox_mode_enabled' )->willReturn( false );
		$this->settings->method( 'get_carrier' )->willReturn( 'shipengine' );

		ob_start();
		$this->admin_test_ui->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'ShipEngine', $output );
	}

	public function test_render_page_includes_add_item_button(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->settings->method( 'is_sandbox_mode_enabled' )->willReturn( false );
		$this->settings->method( 'get_carrier' )->willReturn( 'shipengine' );

		ob_start();
		$this->admin_test_ui->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'fk-add-item', $output );
	}

	public function test_render_page_includes_javascript_for_dynamic_rows(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->settings->method( 'is_sandbox_mode_enabled' )->willReturn( false );
		$this->settings->method( 'get_carrier' )->willReturn( 'shipengine' );

		ob_start();
		$this->admin_test_ui->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<script>', $output );
		$this->assertStringContainsString( 'fk-remove-item', $output );
	}

	public function test_render_page_calls_wp_die_when_user_lacks_capability(): void {
		$GLOBALS['_test_current_user_can'] = false;
		$_SERVER['REQUEST_METHOD']         = 'GET';
		$this->settings->method( 'is_sandbox_mode_enabled' )->willReturn( false );
		$this->settings->method( 'get_carrier' )->willReturn( 'shipengine' );

		$this->expectException( \RuntimeException::class );
		$this->admin_test_ui->render_page();
	}

	// -------------------------------------------------------------------------
	// render_page — POST submission
	// -------------------------------------------------------------------------

	public function test_render_page_shows_results_after_valid_post(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'fk_usps_test_nonce' => 'test_nonce',
			'items'              => array(
				array( 'name' => 'Widget', 'qty' => '1', 'length' => '6', 'width' => '4', 'height' => '3', 'weight_oz' => '8' ),
			),
			'ship_to'            => array(
				'name'           => 'Test User',
				'company_name'   => '',
				'address_line1'  => '1 Main St',
				'address_line2'  => '',
				'city_locality'  => 'Austin',
				'state_province' => 'TX',
				'postal_code'    => '78701',
				'country_code'   => 'US',
			),
		);

		$this->settings->method( 'is_sandbox_mode_enabled' )->willReturn( false );
		$this->settings->method( 'get_carrier' )->willReturn( 'shipengine' );
		$this->test_pricing_service->method( 'run' )->willReturn( array(
			'packages'          => array(
				array(
					'package_number' => 1,
					'mode'           => 'cubic',
					'package_code'   => 'package',
					'package_name'   => 'Small Box',
					'service_code'   => 'usps_priority_mail',
					'rate_amount'    => 8.99,
					'currency'       => 'USD',
					'weight_oz'      => 11.0,
					'dimensions'     => array( 'length' => 8.0, 'width' => 8.0, 'height' => 6.0 ),
					'cubic_tier'     => '0.3',
					'packing_list'   => array( '1x Widget' ),
					'items'          => array(),
				),
			),
			'total_rate_amount' => 8.99,
			'currency'          => 'USD',
			'warnings'          => array(),
			'carrier'           => 'shipengine',
			'sandbox'           => false,
		) );

		ob_start();
		$this->admin_test_ui->render_page();
		$output = ob_get_clean();

		$_POST = array();

		$this->assertStringContainsString( 'Test Pricing Results', $output );
		$this->assertStringContainsString( 'Small Box', $output );
		$this->assertStringContainsString( '1x Widget', $output );
	}

	public function test_render_page_shows_warnings_from_result(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'fk_usps_test_nonce' => 'test_nonce',
			'items'              => array(
				array( 'name' => 'Big Item', 'qty' => '1', 'length' => '30', 'width' => '30', 'height' => '30', 'weight_oz' => '500' ),
			),
			'ship_to'            => array(
				'postal_code' => '78701',
				'country_code' => 'US',
			),
		);

		$this->settings->method( 'is_sandbox_mode_enabled' )->willReturn( false );
		$this->settings->method( 'get_carrier' )->willReturn( 'shipengine' );
		$this->test_pricing_service->method( 'run' )->willReturn( array(
			'packages'          => array(),
			'total_rate_amount' => 0.0,
			'currency'          => 'USD',
			'warnings'          => array( 'No rate found for package 1.' ),
			'carrier'           => 'shipengine',
			'sandbox'           => false,
		) );

		ob_start();
		$this->admin_test_ui->render_page();
		$output = ob_get_clean();

		$_POST = array();

		$this->assertStringContainsString( 'notice-warning', $output );
		$this->assertStringContainsString( 'No rate found', $output );
	}

	public function test_render_page_shows_sandbox_note_in_results_when_sandbox_active(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'fk_usps_test_nonce' => 'test_nonce',
			'items'              => array(),
			'ship_to'            => array(),
		);

		$this->settings->method( 'is_sandbox_mode_enabled' )->willReturn( true );
		$this->settings->method( 'get_carrier' )->willReturn( 'shipengine' );
		$this->test_pricing_service->method( 'run' )->willReturn( array(
			'packages'          => array(),
			'total_rate_amount' => 0.0,
			'currency'          => 'USD',
			'warnings'          => array( 'No valid items.' ),
			'carrier'           => 'shipengine',
			'sandbox'           => true,
		) );

		ob_start();
		$this->admin_test_ui->render_page();
		$output = ob_get_clean();

		$_POST = array();

		$this->assertStringContainsString( 'sandbox environment', $output );
	}

	// -------------------------------------------------------------------------
	// parse_posted_data
	// -------------------------------------------------------------------------

	public function test_parse_posted_data_returns_items_and_ship_to(): void {
		$post   = array(
			'items'   => array(
				array( 'name' => 'Widget', 'qty' => '2', 'length' => '6', 'width' => '4', 'height' => '3', 'weight_oz' => '8' ),
			),
			'ship_to' => array(
				'name'           => 'John Doe',
				'company_name'   => 'ACME',
				'address_line1'  => '1 Main St',
				'address_line2'  => '',
				'city_locality'  => 'Austin',
				'state_province' => 'TX',
				'postal_code'    => '78701',
				'country_code'   => 'US',
			),
		);
		$result = $this->call_protected( 'parse_posted_data', array( $post ) );

		$this->assertCount( 1, $result['items'] );
		$this->assertSame( 'Widget', $result['items'][0]['name'] );
		$this->assertSame( 2, $result['items'][0]['qty'] );
		$this->assertSame( 'John Doe', $result['ship_to']['name'] );
		$this->assertSame( 'TX', $result['ship_to']['state_province'] );
	}

	public function test_parse_posted_data_defaults_country_to_us_when_absent(): void {
		$post   = array(
			'items'   => array(),
			'ship_to' => array( 'postal_code' => '78701' ),
		);
		$result = $this->call_protected( 'parse_posted_data', array( $post ) );

		$this->assertSame( 'US', $result['ship_to']['country_code'] );
	}

	public function test_parse_posted_data_strips_html_from_name(): void {
		$post   = array(
			'items'   => array( array( 'name' => '<b>Item</b>', 'qty' => '1', 'length' => '5', 'width' => '4', 'height' => '3', 'weight_oz' => '8' ) ),
			'ship_to' => array(),
		);
		$result = $this->call_protected( 'parse_posted_data', array( $post ) );

		$this->assertSame( 'Item', $result['items'][0]['name'] );
	}

	public function test_parse_posted_data_skips_non_array_items(): void {
		$post   = array(
			'items'   => array( 'string_item', array( 'name' => 'Real', 'qty' => '1', 'length' => '5', 'width' => '4', 'height' => '3', 'weight_oz' => '8' ) ),
			'ship_to' => array(),
		);
		$result = $this->call_protected( 'parse_posted_data', array( $post ) );

		$this->assertCount( 1, $result['items'] );
		$this->assertSame( 'Real', $result['items'][0]['name'] );
	}

	public function test_parse_posted_data_sets_residential_indicator_unknown(): void {
		$post   = array( 'items' => array(), 'ship_to' => array() );
		$result = $this->call_protected( 'parse_posted_data', array( $post ) );

		$this->assertSame( 'unknown', $result['ship_to']['address_residential_indicator'] );
	}

	public function test_parse_posted_data_handles_missing_ship_to(): void {
		$post   = array( 'items' => array() );
		$result = $this->call_protected( 'parse_posted_data', array( $post ) );

		$this->assertArrayHasKey( 'ship_to', $result );
		$this->assertSame( 'US', $result['ship_to']['country_code'] );
	}

	public function test_parse_posted_data_preserves_raw_dimension_strings_for_expand_items(): void {
		$post   = array(
			'items'   => array(
				array( 'name' => 'X', 'qty' => '3', 'length' => '7.5', 'width' => '5.5', 'height' => '4.25', 'weight_oz' => '12.75' ),
			),
			'ship_to' => array(),
		);
		$result = $this->call_protected( 'parse_posted_data', array( $post ) );

		// Dimensions are preserved as strings so expand_items can apply min(0.1) clamping.
		$this->assertSame( '7.5', $result['items'][0]['length'] );
		$this->assertSame( '12.75', $result['items'][0]['weight_oz'] );
	}
}
