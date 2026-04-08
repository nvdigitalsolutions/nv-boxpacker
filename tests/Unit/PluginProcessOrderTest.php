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
					'packing_list'   => array( '2x Widget', '1x Gadget' ),
				),
			),
		);

		$note = $this->call_build_package_note( $plan );

		$this->assertStringContainsString( 'USPS Shipping Plan', $note );
		$this->assertStringContainsString( '1 package(s)', $note );
		$this->assertStringContainsString( '$8.25', $note );
		$this->assertStringContainsString( 'USPS Small Flat Rate Box', $note );
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
					'packing_list'   => array( '1x Beta' ),
				),
			),
		);

		$note = $this->call_build_package_note( $plan );

		$this->assertStringContainsString( '2 package(s)', $note );
		$this->assertStringContainsString( '$18.50', $note );
		$this->assertStringContainsString( 'Package 1: Box A', $note );
		$this->assertStringContainsString( 'Package 2: Box B', $note );
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
}
