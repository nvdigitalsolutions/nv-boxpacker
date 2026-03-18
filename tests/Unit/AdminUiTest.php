<?php
/**
 * Unit tests for Admin_UI.
 *
 * @package FK_USPS_Optimizer
 */

namespace FK_USPS_Optimizer\Tests\Unit;

use FK_USPS_Optimizer\Admin_UI;
use FK_USPS_Optimizer\Order_Plan_Service;
use FK_USPS_Optimizer\PirateShip_Export;
use FK_USPS_Optimizer\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Admin_UI.
 */
class AdminUiTest extends TestCase {

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
	 * Mocked PirateShip export service.
	 *
	 * @var PirateShip_Export|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $export_service;

	/**
	 * System under test.
	 *
	 * @var Admin_UI
	 */
	private Admin_UI $admin_ui;

	protected function setUp(): void {
		$this->settings       = $this->createMock( Settings::class );
		$this->plan_service   = $this->createMock( Order_Plan_Service::class );
		$this->export_service = $this->createMock( PirateShip_Export::class );
		$this->admin_ui       = new Admin_UI( $this->settings, $this->plan_service, $this->export_service );
	}

	// -------------------------------------------------------------------------
	// register
	// -------------------------------------------------------------------------

	public function test_register_runs_without_error(): void {
		$this->admin_ui->register();
		$this->assertTrue( true );
	}

	// -------------------------------------------------------------------------
	// register_meta_box
	// -------------------------------------------------------------------------

	public function test_register_meta_box_runs_without_error(): void {
		$this->admin_ui->register_meta_box();
		$this->assertTrue( true );
	}

	// -------------------------------------------------------------------------
	// render_meta_box — no plan stored
	// -------------------------------------------------------------------------

	public function test_render_meta_box_shows_no_plan_message_when_plan_empty(): void {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 1 );

		$this->plan_service->method( 'get' )->willReturn( array() );

		ob_start();
		$this->admin_ui->render_meta_box( $order );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'No shipping plan', $output );
	}

	// -------------------------------------------------------------------------
	// render_meta_box — with WP_Post (classic editor)
	// -------------------------------------------------------------------------

	public function test_render_meta_box_accepts_wp_post_and_looks_up_order(): void {
		$post     = new \stdClass();
		$post->ID = 5;

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 5 );

		// Set up wc_get_order stub.
		$GLOBALS['_test_wc_orders'][5] = $order;
		$this->plan_service->method( 'get' )->willReturn( array() );

		ob_start();
		$this->admin_ui->render_meta_box( $post );
		$output = ob_get_clean();

		// Should render (even if just "no plan" message) without fatal errors.
		$this->assertNotEmpty( $output );

		unset( $GLOBALS['_test_wc_orders'][5] );
	}

	public function test_render_meta_box_shows_order_not_found_when_wc_get_order_returns_false(): void {
		$post     = new \stdClass();
		$post->ID = 9999;

		ob_start();
		$this->admin_ui->render_meta_box( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Order not found', $output );
	}

	// -------------------------------------------------------------------------
	// render_meta_box — with plan data
	// -------------------------------------------------------------------------

	public function test_render_meta_box_shows_package_count(): void {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 10 );

		$plan = $this->make_full_plan( 2, 17.98 );
		$this->plan_service->method( 'get' )->willReturn( $plan );

		ob_start();
		$this->admin_ui->render_meta_box( $order );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Packages:', $output );
		$this->assertStringContainsString( '2', $output );
	}

	public function test_render_meta_box_shows_total_rate(): void {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 11 );

		$plan = $this->make_full_plan( 1, 9.99 );
		$this->plan_service->method( 'get' )->willReturn( $plan );

		ob_start();
		$this->admin_ui->render_meta_box( $order );
		$output = ob_get_clean();

		// wc_price() stub returns e.g. "$9.99".
		$this->assertStringContainsString( '9.99', $output );
	}

	public function test_render_meta_box_shows_each_package_detail(): void {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 12 );

		$plan = $this->make_full_plan( 1, 8.50 );
		$this->plan_service->method( 'get' )->willReturn( $plan );

		ob_start();
		$this->admin_ui->render_meta_box( $order );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Package 1', $output );
		$this->assertStringContainsString( '8.50', $output );        // rate
		$this->assertStringContainsString( '8', $output );           // dimension
		$this->assertStringContainsString( 'oz', $output );          // weight unit
	}

	public function test_render_meta_box_shows_cubic_tier_when_present(): void {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 13 );

		$plan = $this->make_full_plan( 1, 8.50 );
		$this->plan_service->method( 'get' )->willReturn( $plan );

		ob_start();
		$this->admin_ui->render_meta_box( $order );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Cubic Tier', $output );
		$this->assertStringContainsString( '0.3', $output );
	}

	public function test_render_meta_box_shows_packing_list_items(): void {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 14 );

		$plan = $this->make_full_plan( 1, 7.99 );
		$this->plan_service->method( 'get' )->willReturn( $plan );

		ob_start();
		$this->admin_ui->render_meta_box( $order );
		$output = ob_get_clean();

		$this->assertStringContainsString( '2x Widget', $output );
		$this->assertStringContainsString( '1x Gadget', $output );
	}

	public function test_render_meta_box_shows_warnings(): void {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 15 );

		$plan = array(
			'total_package_count' => 0,
			'total_rate_amount'   => 0,
			'currency'            => 'USD',
			'packages'            => array(),
			'warnings'            => array( 'Unable to produce a plan.', 'API key missing.' ),
		);
		$this->plan_service->method( 'get' )->willReturn( $plan );

		ob_start();
		$this->admin_ui->render_meta_box( $order );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Unable to produce a plan.', $output );
		$this->assertStringContainsString( 'API key missing.', $output );
		$this->assertStringContainsString( 'notice-warning', $output );
	}

	public function test_render_meta_box_shows_export_button(): void {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 16 );

		$this->plan_service->method( 'get' )->willReturn( $this->make_full_plan( 1, 5.00 ) );

		ob_start();
		$this->admin_ui->render_meta_box( $order );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Export PirateShip CSV', $output );
		$this->assertStringContainsString( 'button', $output );
	}

	public function test_render_meta_box_omits_cubic_tier_line_when_empty(): void {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_id' )->willReturn( 17 );

		$plan = $this->make_full_plan( 1, 6.50, 'flat_rate_box', '' );
		$this->plan_service->method( 'get' )->willReturn( $plan );

		ob_start();
		$this->admin_ui->render_meta_box( $order );
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'Cubic Tier', $output );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a shipping plan with the given number of identical packages.
	 *
	 * @param int    $count       Number of packages.
	 * @param float  $total_rate  Total rate amount.
	 * @param string $mode        Package mode (cubic or flat_rate_box).
	 * @param string $cubic_tier  Cubic tier string (empty for flat rate).
	 * @return array Shipping plan array.
	 */
	private function make_full_plan( int $count, float $total_rate, string $mode = 'cubic', string $cubic_tier = '0.3' ): array {
		$packages = array();
		$per_pkg  = $count > 0 ? round( $total_rate / $count, 2 ) : 0;

		for ( $i = 1; $i <= $count; $i++ ) {
			$packages[] = array(
				'package_number' => $i,
				'mode'           => $mode,
				'package_code'   => 'package',
				'package_name'   => 'Custom Cubic Small',
				'service_code'   => 'usps_priority_mail',
				'rate_amount'    => $per_pkg,
				'currency'       => 'USD',
				'weight_oz'      => 22.0,
				'dimensions'     => array( 'length' => 8.0, 'width' => 8.0, 'height' => 6.0 ),
				'cubic_tier'     => $cubic_tier,
				'packing_list'   => array( '2x Widget', '1x Gadget' ),
				'items'          => array(),
			);
		}

		return array(
			'total_package_count' => $count,
			'total_rate_amount'   => $total_rate,
			'currency'            => 'USD',
			'packages'            => $packages,
			'pirateship_rows'     => array(),
			'warnings'            => array(),
		);
	}
}
