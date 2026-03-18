<?php
/**
 * Unit tests for Order_Plan_Service.
 *
 * @package FK_USPS_Optimizer
 */

namespace FK_USPS_Optimizer\Tests\Unit;

use FK_USPS_Optimizer\Order_Plan_Service;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Order_Plan_Service.
 */
class OrderPlanServiceTest extends TestCase {

	/**
	 * System under test.
	 *
	 * @var Order_Plan_Service
	 */
	private Order_Plan_Service $service;

	protected function setUp(): void {
		$this->service = new Order_Plan_Service();
	}

	public function test_meta_key_constant_value(): void {
		$this->assertSame( '_fk_usps_optimizer_plan', Order_Plan_Service::META_KEY );
	}

	public function test_save_calls_update_meta_data_with_correct_key_and_plan(): void {
		$plan = array(
			'total_package_count' => 2,
			'total_rate_amount'   => 15.98,
			'currency'            => 'USD',
			'packages'            => array(),
		);

		$order = $this->createMock( \WC_Order::class );
		$order->expects( $this->once() )
			->method( 'update_meta_data' )
			->with( Order_Plan_Service::META_KEY, $plan );
		$order->expects( $this->once() )->method( 'save' );

		$this->service->save( $order, $plan );
	}

	public function test_save_calls_order_save(): void {
		$order = $this->createMock( \WC_Order::class );
		$order->expects( $this->once() )->method( 'save' );

		$this->service->save( $order, array() );
	}

	public function test_get_returns_plan_array_from_meta(): void {
		$plan = array( 'total_package_count' => 3, 'currency' => 'USD' );

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_meta' )
			->with( Order_Plan_Service::META_KEY, true )
			->willReturn( $plan );

		$this->assertSame( $plan, $this->service->get( $order ) );
	}

	public function test_get_returns_empty_array_when_meta_is_empty_string(): void {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_meta' )->willReturn( '' );

		$this->assertSame( array(), $this->service->get( $order ) );
	}

	public function test_get_returns_empty_array_when_meta_is_null(): void {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_meta' )->willReturn( null );

		$this->assertSame( array(), $this->service->get( $order ) );
	}

	public function test_get_returns_empty_array_when_meta_is_false(): void {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_meta' )->willReturn( false );

		$this->assertSame( array(), $this->service->get( $order ) );
	}

	public function test_get_returns_empty_array_when_meta_is_zero(): void {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_meta' )->willReturn( 0 );

		$this->assertSame( array(), $this->service->get( $order ) );
	}

	public function test_save_and_get_round_trip(): void {
		$plan   = array( 'total_package_count' => 1, 'packages' => array( array( 'package_number' => 1 ) ) );
		$stored = null;

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'update_meta_data' )->willReturnCallback(
			function ( $key, $value ) use ( &$stored ) {
				$stored = $value;
			}
		);
		$order->method( 'get_meta' )->willReturnCallback(
			function () use ( &$stored ) {
				return $stored;
			}
		);

		$this->service->save( $order, $plan );
		$retrieved = $this->service->get( $order );

		$this->assertSame( $plan, $retrieved );
	}
}
