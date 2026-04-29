<?php
/**
 * Unit tests for Plugin::process_order() order-note functionality.
 *
 * @package FK_USPS_Optimizer
 */

namespace FK_USPS_Optimizer\Tests\Unit;

use FK_USPS_Optimizer\Order_Plan_Service;
use FK_USPS_Optimizer\Packing_Service;
use FK_USPS_Optimizer\PirateShip_Export;
use FK_USPS_Optimizer\Plugin;
use FK_USPS_Optimizer\Settings;
use FK_USPS_Optimizer\ShipEngine_Service;
use FK_USPS_Optimizer\ShipStation_Service;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the add-package-note behaviour in Plugin::process_order().
 */
class PluginProcessOrderTest extends TestCase {

	/**
	 * The singleton Plugin instance.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	protected function setUp(): void {
		$GLOBALS['_test_wp_options']        = array();
		$GLOBALS['_test_wp_filters']        = array();
		$GLOBALS['_test_settings_errors']   = array();
		$GLOBALS['_test_wp_transients']     = array();
		$GLOBALS['_test_wp_json_response']  = null;
		$GLOBALS['_test_wp_remote_post']    = null;
		$GLOBALS['_test_wp_remote_get']     = null;
		$GLOBALS['_test_current_user_can']  = true;
		$GLOBALS['_test_wc_logger']         = null;
		$GLOBALS['_test_wp_safe_redirect']  = null;

		// Reset the singleton so we get a fresh instance.
		$ref = new \ReflectionProperty( Plugin::class, 'instance' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );

		$this->plugin = Plugin::bootstrap();
	}

	// -------------------------------------------------------------------------
	// build_package_note (protected helper, tested via reflection)
	// -------------------------------------------------------------------------

	public function test_build_package_note_formats_single_package(): void {
		$plan = array(
			'total_package_count' => 1,
			'total_rate_amount'   => 8.25,
			'packages'            => array(
				array(
					'package_number' => 1,
					'package_name'   => 'USPS Small Flat Rate Box',
					'mode'           => 'flat_rate_box',
					'rate_amount'    => 8.25,
					'dimensions'     => array( 'length' => 9, 'width' => 6, 'height' => 2 ),
					'weight_oz'      => 14,
					'cubic_tier'     => '',
					'service_label'  => 'USPS Priority Mail',
					'packing_list'   => array( '2x Widget', '1x Gadget' ),
				),
			),
		);

		$note = $this->call_build_package_note( $plan );

		$this->assertStringContainsString( 'USPS Shipping Plan', $note );
		$this->assertStringContainsString( '1 package(s)', $note );
		$this->assertStringContainsString( '$8.25', $note );
		$this->assertStringContainsString( 'USPS Small Flat Rate Box', $note );
		$this->assertStringContainsString( 'Service: USPS Priority Mail', $note );
		$this->assertStringContainsString( '9 x 6 x 2 in', $note );
		$this->assertStringContainsString( '14 oz', $note );
		$this->assertStringContainsString( '2x Widget, 1x Gadget', $note );
		$this->assertStringNotContainsString( 'Cubic Tier', $note );
	}

	public function test_build_package_note_includes_cubic_tier(): void {
		$plan = array(
			'total_package_count' => 1,
			'total_rate_amount'   => 5.00,
			'packages'            => array(
				array(
					'package_number' => 1,
					'package_name'   => 'Custom Cubic Small',
					'mode'           => 'cubic',
					'rate_amount'    => 5.00,
					'dimensions'     => array( 'length' => 8, 'width' => 8, 'height' => 6 ),
					'weight_oz'      => 10,
					'cubic_tier'     => '0.2',
					'packing_list'   => array( '1x Item' ),
				),
			),
		);

		$note = $this->call_build_package_note( $plan );

		$this->assertStringContainsString( 'Cubic Tier: 0.2', $note );
	}

	public function test_build_package_note_formats_multiple_packages(): void {
		$plan = array(
			'total_package_count' => 2,
			'total_rate_amount'   => 18.50,
			'packages'            => array(
				array(
					'package_number' => 1,
					'package_name'   => 'Box A',
					'mode'           => 'flat_rate_box',
					'rate_amount'    => 9.25,
					'dimensions'     => array( 'length' => 10, 'width' => 8, 'height' => 4 ),
					'weight_oz'      => 20,
					'cubic_tier'     => '',
					'service_label'  => 'USPS Priority Mail',
					'packing_list'   => array( '1x Alpha' ),
				),
				array(
					'package_number' => 2,
					'package_name'   => 'Box B',
					'mode'           => 'cubic',
					'rate_amount'    => 9.25,
					'dimensions'     => array( 'length' => 6, 'width' => 6, 'height' => 3 ),
					'weight_oz'      => 12,
					'cubic_tier'     => '0.1',
					'service_label'  => 'UPS Ground',
					'packing_list'   => array( '1x Beta' ),
				),
			),
		);

		$note = $this->call_build_package_note( $plan );

		$this->assertStringContainsString( '2 package(s)', $note );
		$this->assertStringContainsString( '$18.50', $note );
		$this->assertStringContainsString( 'Package 1: Box A', $note );
		$this->assertStringContainsString( 'Package 2: Box B', $note );
		$this->assertStringContainsString( 'Service: USPS Priority Mail', $note );
		$this->assertStringContainsString( 'Service: UPS Ground', $note );
	}

	// -------------------------------------------------------------------------
	// process_order integration with add_order_note
	// -------------------------------------------------------------------------

	public function test_process_order_adds_note_when_setting_enabled(): void {
		// Enable the setting.
		$GLOBALS['_test_wp_options'][ Settings::OPTION_KEY ] = array(
			'add_package_note'      => '1',
			'shipengine_api_key'    => 'test_key',
			'shipengine_carrier_id' => 'se-123',
		);

		// Stub the ShipEngine rate response.
		$GLOBALS['_test_wp_remote_post'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode(
				array(
					'rate_response' => array(
						'rates' => array(
							array(
								'shipping_amount' => array( 'amount' => 7.99, 'currency' => 'USD' ),
								'service_code'    => 'usps_priority_mail',
							),
						),
					),
				)
			),
		);

		$product = $this->createMock( \WC_Product::class );
		$product->method( 'get_length' )->willReturn( '4' );
		$product->method( 'get_width' )->willReturn( '3' );
		$product->method( 'get_height' )->willReturn( '2' );
		$product->method( 'get_weight' )->willReturn( '0.5' );
		$product->method( 'needs_shipping' )->willReturn( true );

		$item = $this->createMock( \WC_Order_Item_Product::class );
		$item->method( 'get_product' )->willReturn( $product );
		$item->method( 'get_quantity' )->willReturn( 1 );
		$item->method( 'get_name' )->willReturn( 'Test Product' );

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 42 );
		$order->method( 'needs_shipping_address' )->willReturn( true );
		$order->method( 'get_shipping_postcode' )->willReturn( '10001' );
		$order->method( 'get_shipping_country' )->willReturn( 'US' );
		$order->method( 'get_items' )->willReturn( array( $item ) );

		// Expect add_order_note to be called once.
		$order->expects( $this->once() )
			->method( 'add_order_note' )
			->with( $this->stringContains( 'USPS Shipping Plan' ) );

		$this->plugin->process_order( 42, array(), $order );
	}

	public function test_process_order_skips_note_when_setting_disabled(): void {
		// Setting is disabled by default (0).

		// Stub the ShipEngine rate response.
		$GLOBALS['_test_wp_remote_post'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode(
				array(
					'rate_response' => array(
						'rates' => array(
							array(
								'shipping_amount' => array( 'amount' => 7.99, 'currency' => 'USD' ),
								'service_code'    => 'usps_priority_mail',
							),
						),
					),
				)
			),
		);

		$product = $this->createMock( \WC_Product::class );
		$product->method( 'get_length' )->willReturn( '4' );
		$product->method( 'get_width' )->willReturn( '3' );
		$product->method( 'get_height' )->willReturn( '2' );
		$product->method( 'get_weight' )->willReturn( '0.5' );
		$product->method( 'needs_shipping' )->willReturn( true );

		$item = $this->createMock( \WC_Order_Item_Product::class );
		$item->method( 'get_product' )->willReturn( $product );
		$item->method( 'get_quantity' )->willReturn( 1 );
		$item->method( 'get_name' )->willReturn( 'Test Product' );

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 42 );
		$order->method( 'needs_shipping_address' )->willReturn( true );
		$order->method( 'get_shipping_postcode' )->willReturn( '10001' );
		$order->method( 'get_shipping_country' )->willReturn( 'US' );
		$order->method( 'get_items' )->willReturn( array( $item ) );

		// Should NOT call add_order_note.
		$order->expects( $this->never() )->method( 'add_order_note' );

		$this->plugin->process_order( 42, array(), $order );
	}

	public function test_process_order_skips_note_when_no_packages(): void {
		// Enable setting but no items → no packages.
		$GLOBALS['_test_wp_options'][ Settings::OPTION_KEY ] = array(
			'add_package_note' => '1',
		);

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 42 );
		$order->method( 'needs_shipping_address' )->willReturn( true );
		$order->method( 'get_items' )->willReturn( array() );

		// No packages, so no note.
		$order->expects( $this->never() )->method( 'add_order_note' );

		$this->plugin->process_order( 42, array(), $order );
	}

	// -------------------------------------------------------------------------
	// process_order integration with customer-note packing plan
	// -------------------------------------------------------------------------

	public function test_process_order_writes_packing_plan_to_customer_note_when_enabled(): void {
		$GLOBALS['_test_wp_options'][ Settings::OPTION_KEY ] = array(
			'add_packing_to_customer_note' => '1',
			'shipengine_api_key'           => 'test_key',
			'shipengine_carrier_id'        => 'se-123',
		);

		$this->stub_successful_rate_response();

		$order = $this->build_packable_order_mock();
		// No prior customer note.
		$order->method( 'get_customer_note' )->willReturn( '' );

		$captured = null;
		$order->expects( $this->once() )
			->method( 'set_customer_note' )
			->with(
				$this->callback(
					function ( $note ) use ( &$captured ) {
						$captured = $note;
						return true;
					}
				)
			);
		$order->expects( $this->atLeastOnce() )->method( 'save' );

		$this->plugin->process_order( 42, array(), $order );

		$this->assertNotNull( $captured, 'set_customer_note should have been called.' );
		$this->assertStringContainsString( '<!-- fk-pack-start -->', $captured );
		$this->assertStringContainsString( '<!-- fk-pack-end -->', $captured );
		$this->assertStringContainsString( 'USPS Shipping Plan', $captured );
	}

	public function test_process_order_does_not_touch_customer_note_when_disabled(): void {
		// Default settings (option disabled) but enable rate fetching.
		$GLOBALS['_test_wp_options'][ Settings::OPTION_KEY ] = array(
			'shipengine_api_key'    => 'test_key',
			'shipengine_carrier_id' => 'se-123',
		);

		$this->stub_successful_rate_response();

		$order = $this->build_packable_order_mock();
		$order->expects( $this->never() )->method( 'set_customer_note' );

		$this->plugin->process_order( 42, array(), $order );
	}

	public function test_process_order_replaces_existing_packing_block_and_preserves_user_note(): void {
		$GLOBALS['_test_wp_options'][ Settings::OPTION_KEY ] = array(
			'add_packing_to_customer_note' => '1',
			'shipengine_api_key'           => 'test_key',
			'shipengine_carrier_id'        => 'se-123',
		);

		$this->stub_successful_rate_response();

		$prior_user_note = 'Please leave at side door.';
		$existing        = $prior_user_note . "\n\n<!-- fk-pack-start -->\nOLD STALE PLAN\n<!-- fk-pack-end -->";

		$order = $this->build_packable_order_mock();
		$order->method( 'get_customer_note' )->willReturn( $existing );

		$captured = null;
		$order->expects( $this->once() )
			->method( 'set_customer_note' )
			->with(
				$this->callback(
					function ( $note ) use ( &$captured ) {
						$captured = $note;
						return true;
					}
				)
			);

		$this->plugin->process_order( 42, array(), $order );

		$this->assertNotNull( $captured );
		$this->assertStringContainsString( $prior_user_note, $captured, 'Customer note prefix must be preserved.' );
		$this->assertStringNotContainsString( 'OLD STALE PLAN', $captured, 'Stale plan block must be replaced.' );
		// Exactly one start/end marker pair after replacement.
		$this->assertSame( 1, substr_count( $captured, '<!-- fk-pack-start -->' ) );
		$this->assertSame( 1, substr_count( $captured, '<!-- fk-pack-end -->' ) );
		$this->assertStringContainsString( 'USPS Shipping Plan', $captured );
	}

	// -------------------------------------------------------------------------
	// filter_customer_note_for_display
	// -------------------------------------------------------------------------

	public function test_filter_customer_note_strips_packing_block_on_non_rest_reads(): void {
		$value = "Hello\n\n<!-- fk-pack-start -->\nUSPS Shipping Plan\nTotal: 1 package(s), $7.99\n<!-- fk-pack-end -->";

		$result = $this->plugin->filter_customer_note_for_display( $value );

		$this->assertSame( 'Hello', $result );
	}

	public function test_filter_customer_note_passes_through_on_rest_request(): void {
		if ( ! defined( 'REST_REQUEST' ) ) {
			define( 'REST_REQUEST', true );
		}

		$value  = "Hello\n\n<!-- fk-pack-start -->\nUSPS Shipping Plan\n<!-- fk-pack-end -->";
		$result = $this->plugin->filter_customer_note_for_display( $value );

		$this->assertSame( $value, $result );
	}

	public function test_filter_customer_note_returns_unchanged_when_no_marker(): void {
		$value  = 'Plain customer note with no packing plan.';
		$result = $this->plugin->filter_customer_note_for_display( $value );

		$this->assertSame( $value, $result );
	}

	public function test_filter_customer_note_handles_empty_string(): void {
		$this->assertSame( '', $this->plugin->filter_customer_note_for_display( '' ) );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Invoke Plugin::build_package_note() via reflection.
	 *
	 * @param array $plan Plan data.
	 * @return string Formatted note.
	 */
	private function call_build_package_note( array $plan ): string {
		$ref = new \ReflectionMethod( $this->plugin, 'build_package_note' );
		$ref->setAccessible( true );
		return $ref->invoke( $this->plugin, $plan );
	}

	/**
	 * Configure a stubbed successful ShipEngine rate response.
	 */
	private function stub_successful_rate_response(): void {
		$GLOBALS['_test_wp_remote_post'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode(
				array(
					'rate_response' => array(
						'rates' => array(
							array(
								'shipping_amount' => array( 'amount' => 7.99, 'currency' => 'USD' ),
								'service_code'    => 'usps_priority_mail',
							),
						),
					),
				)
			),
		);
	}

	/**
	 * Build a minimal mocked order with one shippable item suitable for
	 * driving Plugin::process_order() through to a successful packing plan.
	 *
	 * @return \WC_Order&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function build_packable_order_mock() {
		$product = $this->createMock( \WC_Product::class );
		$product->method( 'get_length' )->willReturn( '4' );
		$product->method( 'get_width' )->willReturn( '3' );
		$product->method( 'get_height' )->willReturn( '2' );
		$product->method( 'get_weight' )->willReturn( '0.5' );
		$product->method( 'needs_shipping' )->willReturn( true );

		$item = $this->createMock( \WC_Order_Item_Product::class );
		$item->method( 'get_product' )->willReturn( $product );
		$item->method( 'get_quantity' )->willReturn( 1 );
		$item->method( 'get_name' )->willReturn( 'Test Product' );

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 42 );
		$order->method( 'needs_shipping_address' )->willReturn( true );
		$order->method( 'get_shipping_postcode' )->willReturn( '10001' );
		$order->method( 'get_shipping_country' )->willReturn( 'US' );
		$order->method( 'get_items' )->willReturn( array( $item ) );

		return $order;
	}
}
