<?php
/**
 * Unit tests for Packing_Service.
 *
 * @package FK_USPS_Optimizer
 */

namespace FK_USPS_Optimizer\Tests\Unit;

use FK_USPS_Optimizer\Packing_Service;
use FK_USPS_Optimizer\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Packing_Service.
 */
class PackingServiceTest extends TestCase {

	/**
	 * Mocked settings dependency.
	 *
	 * @var Settings|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $settings;

	/**
	 * System under test.
	 *
	 * @var Packing_Service
	 */
	private Packing_Service $service;

	protected function setUp(): void {
		$GLOBALS['_test_wp_filters'] = array();
		$this->settings              = $this->createMock( Settings::class );
		$this->service               = new Packing_Service( $this->settings );
	}

	// -------------------------------------------------------------------------
	// Helper utilities
	// -------------------------------------------------------------------------

	/**
	 * Invoke a protected/private method via reflection.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Arguments.
	 * @return mixed Method return value.
	 */
	private function call_protected( string $method, array $args = array() ) {
		$ref = new \ReflectionMethod( $this->service, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $this->service, $args );
	}

	/**
	 * Build a set of two box definitions (small + medium).
	 *
	 * @return array Box definitions.
	 */
	private function make_boxes(): array {
		return array(
			array(
				'reference'    => 'Small',
				'package_code' => 'package',
				'package_name' => 'Small Box',
				'box_type'     => 'cubic',
				'outer_width'  => 8,
				'outer_length' => 8,
				'outer_depth'  => 6,
				'inner_width'  => 8,
				'inner_length' => 8,
				'inner_depth'  => 6,
				'empty_weight' => 3,
				'max_weight'   => 20,
			),
			array(
				'reference'    => 'Medium',
				'package_code' => 'package',
				'package_name' => 'Medium Box',
				'box_type'     => 'cubic',
				'outer_width'  => 12,
				'outer_length' => 12,
				'outer_depth'  => 10,
				'inner_width'  => 12,
				'inner_length' => 12,
				'inner_depth'  => 10,
				'empty_weight' => 5,
				'max_weight'   => 20,
			),
		);
	}

	// -------------------------------------------------------------------------
	// to_mm
	// -------------------------------------------------------------------------

	public function test_to_mm_converts_one_inch(): void {
		$this->assertSame( 25, $this->call_protected( 'to_mm', array( 1.0 ) ) );
	}

	public function test_to_mm_converts_ten_inches(): void {
		$this->assertSame( 254, $this->call_protected( 'to_mm', array( 10.0 ) ) );
	}

	public function test_to_mm_zero_returns_zero(): void {
		$this->assertSame( 0, $this->call_protected( 'to_mm', array( 0.0 ) ) );
	}

	public function test_to_mm_half_inch_rounds_to_13(): void {
		// 0.5 × 25.4 = 12.7 → rounds to 13.
		$this->assertSame( 13, $this->call_protected( 'to_mm', array( 0.5 ) ) );
	}

	public function test_to_mm_eight_inches(): void {
		$this->assertSame( 203, $this->call_protected( 'to_mm', array( 8.0 ) ) );
	}

	// -------------------------------------------------------------------------
	// to_g
	// -------------------------------------------------------------------------

	public function test_to_g_converts_one_ounce(): void {
		// 1 oz × 28.3495 = 28.3495 → rounds to 28.
		$this->assertSame( 28, $this->call_protected( 'to_g', array( 1.0 ) ) );
	}

	public function test_to_g_converts_sixteen_ounces_to_one_pound(): void {
		// 16 × 28.3495 = 453.592 → rounds to 454.
		$this->assertSame( 454, $this->call_protected( 'to_g', array( 16.0 ) ) );
	}

	public function test_to_g_zero_returns_zero(): void {
		$this->assertSame( 0, $this->call_protected( 'to_g', array( 0.0 ) ) );
	}

	public function test_to_g_fractional_ounce(): void {
		// 0.5 × 28.3495 = 14.17475 → rounds to 14.
		$this->assertSame( 14, $this->call_protected( 'to_g', array( 0.5 ) ) );
	}

	// -------------------------------------------------------------------------
	// match_item_to_box
	// -------------------------------------------------------------------------

	public function test_match_returns_first_fitting_box(): void {
		$item   = array( 'length' => 5.0, 'width' => 5.0, 'height' => 4.0, 'weight_oz' => 8.0 );
		$result = $this->call_protected( 'match_item_to_box', array( $item, $this->make_boxes() ) );
		$this->assertSame( 'Small', $result['reference'] );
	}

	public function test_match_skips_box_too_short_and_uses_next(): void {
		$item   = array( 'length' => 10.0, 'width' => 10.0, 'height' => 8.0, 'weight_oz' => 8.0 );
		$result = $this->call_protected( 'match_item_to_box', array( $item, $this->make_boxes() ) );
		$this->assertSame( 'Medium', $result['reference'] );
	}

	public function test_match_returns_fallback_when_no_box_fits_by_dimension(): void {
		$item   = array( 'length' => 24.0, 'width' => 24.0, 'height' => 24.0, 'weight_oz' => 8.0 );
		$result = $this->call_protected( 'match_item_to_box', array( $item, $this->make_boxes() ) );
		$this->assertSame( 'Fallback Package', $result['reference'] );
	}

	public function test_match_returns_fallback_when_item_too_heavy(): void {
		// 400 oz > 20 lb × 16 oz/lb = 320 oz.
		$item   = array( 'length' => 4.0, 'width' => 4.0, 'height' => 3.0, 'weight_oz' => 400.0 );
		$result = $this->call_protected( 'match_item_to_box', array( $item, $this->make_boxes() ) );
		$this->assertSame( 'Fallback Package', $result['reference'] );
	}

	public function test_match_fallback_uses_item_dimensions_as_box_dimensions(): void {
		$item   = array( 'length' => 22.5, 'width' => 18.3, 'height' => 15.7, 'weight_oz' => 5.0 );
		$result = $this->call_protected( 'match_item_to_box', array( $item, $this->make_boxes() ) );
		$this->assertSame( 23, $result['outer_length'] );
		$this->assertSame( 19, $result['outer_width'] );
		$this->assertSame( 16, $result['outer_depth'] );
	}

	public function test_match_fallback_has_cubic_box_type(): void {
		$item   = array( 'length' => 30.0, 'width' => 30.0, 'height' => 30.0, 'weight_oz' => 5.0 );
		$result = $this->call_protected( 'match_item_to_box', array( $item, $this->make_boxes() ) );
		$this->assertSame( 'cubic', $result['box_type'] );
	}

	public function test_match_considers_exact_boundary_as_fitting(): void {
		// Item exactly matches inner dimensions of the small box.
		$item   = array( 'length' => 8.0, 'width' => 8.0, 'height' => 6.0, 'weight_oz' => 4.0 );
		$result = $this->call_protected( 'match_item_to_box', array( $item, $this->make_boxes() ) );
		$this->assertSame( 'Small', $result['reference'] );
	}

	// -------------------------------------------------------------------------
	// convert_box_to_boxpacker_units
	// -------------------------------------------------------------------------

	public function test_convert_box_translates_dimensions_to_mm(): void {
		$box    = array_merge(
			$this->make_boxes()[0],
			array( 'empty_weight' => 3, 'max_weight' => 20 )
		);
		$result = $this->call_protected( 'convert_box_to_boxpacker_units', array( $box ) );

		// 8 in × 25.4 = 203.2 → 203 mm.
		$this->assertSame( 203, $result['outer_width'] );
		$this->assertSame( 203, $result['outer_length'] );
		// 6 in × 25.4 = 152.4 → 152 mm.
		$this->assertSame( 152, $result['outer_depth'] );
		$this->assertSame( 203, $result['inner_width'] );
		$this->assertSame( 203, $result['inner_length'] );
		$this->assertSame( 152, $result['inner_depth'] );
	}

	public function test_convert_box_translates_weights_to_grams(): void {
		$box    = array_merge( $this->make_boxes()[0], array( 'empty_weight' => 3, 'max_weight' => 20 ) );
		$result = $this->call_protected( 'convert_box_to_boxpacker_units', array( $box ) );

		// empty_weight: 3 oz → round(3 × 28.3495) = round(85.0485) = 85 g.
		$this->assertSame( 85, $result['empty_weight'] );
		// max_weight: 20 lbs × 16 = 320 oz → round(320 × 28.3495) = round(9071.84) = 9072 g.
		$this->assertSame( 9072, $result['max_weight'] );
	}

	public function test_convert_box_preserves_source_definition(): void {
		$box    = $this->make_boxes()[0];
		$result = $this->call_protected( 'convert_box_to_boxpacker_units', array( $box ) );
		$this->assertSame( $box, $result['source_definition'] );
	}

	// -------------------------------------------------------------------------
	// pack_fallback
	// -------------------------------------------------------------------------

	public function test_pack_fallback_produces_one_package_per_item(): void {
		$this->settings->method( 'get_boxes' )->willReturn( $this->make_boxes() );

		$items = array(
			array( 'name' => 'A', 'length' => 4.0, 'width' => 4.0, 'height' => 3.0, 'weight_oz' => 5.0, 'product_id' => 1, 'item_id' => 1, 'sku' => 'A' ),
			array( 'name' => 'B', 'length' => 4.0, 'width' => 4.0, 'height' => 3.0, 'weight_oz' => 6.0, 'product_id' => 2, 'item_id' => 2, 'sku' => 'B' ),
		);

		$result = $this->call_protected( 'pack_fallback', array( $items ) );

		$this->assertCount( 2, $result );
	}

	public function test_pack_fallback_each_package_contains_correct_weight(): void {
		$this->settings->method( 'get_boxes' )->willReturn( $this->make_boxes() );

		$items = array(
			array( 'name' => 'A', 'length' => 4.0, 'width' => 4.0, 'height' => 3.0, 'weight_oz' => 8.0, 'product_id' => 1, 'item_id' => 1, 'sku' => 'A' ),
			array( 'name' => 'B', 'length' => 4.0, 'width' => 4.0, 'height' => 3.0, 'weight_oz' => 12.0, 'product_id' => 2, 'item_id' => 2, 'sku' => 'B' ),
		);

		$result = $this->call_protected( 'pack_fallback', array( $items ) );

		$this->assertSame( 8.0, $result[0]['weight_oz'] );
		$this->assertSame( 12.0, $result[1]['weight_oz'] );
	}

	public function test_pack_fallback_sets_dimensions_from_box(): void {
		$this->settings->method( 'get_boxes' )->willReturn( $this->make_boxes() );

		$items = array(
			array( 'name' => 'A', 'length' => 4.0, 'width' => 4.0, 'height' => 3.0, 'weight_oz' => 5.0, 'product_id' => 1, 'item_id' => 1, 'sku' => 'A' ),
		);

		$result = $this->call_protected( 'pack_fallback', array( $items ) );

		// Item fits in Small box (inner 8×8×6); dimensions should use inner dims.
		$this->assertSame( 8.0, $result[0]['dimensions']['length'] );
		$this->assertSame( 8.0, $result[0]['dimensions']['width'] );
		$this->assertSame( 6.0, $result[0]['dimensions']['height'] );
	}

	public function test_pack_fallback_uses_inner_not_outer_dimensions(): void {
		$boxes = array(
			array(
				'reference'    => 'Thick',
				'package_code' => 'package',
				'package_name' => 'Thick Box',
				'box_type'     => 'cubic',
				'outer_width'  => 10,
				'outer_length' => 10,
				'outer_depth'  => 8,
				'inner_width'  => 9,
				'inner_length' => 9,
				'inner_depth'  => 7,
				'empty_weight' => 4,
				'max_weight'   => 20,
			),
		);
		$this->settings->method( 'get_boxes' )->willReturn( $boxes );

		$items = array(
			array( 'name' => 'A', 'length' => 5.0, 'width' => 5.0, 'height' => 4.0, 'weight_oz' => 5.0, 'product_id' => 1, 'item_id' => 1, 'sku' => 'A' ),
		);

		$result = $this->call_protected( 'pack_fallback', array( $items ) );

		// Should use inner dimensions, not outer.
		$this->assertSame( 9.0, $result[0]['dimensions']['length'] );
		$this->assertSame( 9.0, $result[0]['dimensions']['width'] );
		$this->assertSame( 7.0, $result[0]['dimensions']['height'] );
	}

	public function test_pack_fallback_uses_item_dims_when_no_box_matches(): void {
		$this->settings->method( 'get_boxes' )->willReturn( $this->make_boxes() );

		$items = array(
			array( 'name' => 'Big', 'length' => 24.0, 'width' => 18.0, 'height' => 15.0, 'weight_oz' => 5.0, 'product_id' => 1, 'item_id' => 1, 'sku' => 'BIG' ),
		);

		$result = $this->call_protected( 'pack_fallback', array( $items ) );

		// Fallback box has no inner_* keys, so item dimensions are used.
		$this->assertSame( 24.0, $result[0]['dimensions']['length'] );
		$this->assertSame( 18.0, $result[0]['dimensions']['width'] );
		$this->assertSame( 15.0, $result[0]['dimensions']['height'] );
	}

	// -------------------------------------------------------------------------
	// pack_order (end-to-end with BoxPacker available)
	// -------------------------------------------------------------------------

	public function test_pack_order_returns_empty_array_when_order_has_no_items(): void {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_items' )->willReturn( array() );

		$this->assertSame( array(), $this->service->pack_order( $order ) );
	}

	public function test_pack_order_skips_non_product_order_items(): void {
		// Return a plain object that is NOT an instance of WC_Order_Item_Product.
		$plain = new \stdClass();

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_items' )->willReturn( array( $plain ) );

		$this->settings->method( 'get_boxes' )->willReturn( $this->make_boxes() );

		$this->assertSame( array(), $this->service->pack_order( $order ) );
	}

	public function test_pack_order_skips_non_shippable_products(): void {
		$product = $this->createMock( \WC_Product::class );
		$product->method( 'needs_shipping' )->willReturn( false );

		$item = $this->createMock( \WC_Order_Item_Product::class );
		$item->method( 'get_product' )->willReturn( $product );

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_items' )->willReturn( array( $item ) );

		$this->settings->method( 'get_boxes' )->willReturn( $this->make_boxes() );

		$this->assertSame( array(), $this->service->pack_order( $order ) );
	}

	public function test_pack_order_skips_null_product(): void {
		$item = $this->createMock( \WC_Order_Item_Product::class );
		$item->method( 'get_product' )->willReturn( false );

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_items' )->willReturn( array( $item ) );

		$this->settings->method( 'get_boxes' )->willReturn( $this->make_boxes() );

		$this->assertSame( array(), $this->service->pack_order( $order ) );
	}

	public function test_pack_order_returns_packages_for_shippable_item(): void {
		$product = $this->createMock( \WC_Product::class );
		$product->method( 'needs_shipping' )->willReturn( true );
		$product->method( 'get_id' )->willReturn( 1 );
		$product->method( 'get_sku' )->willReturn( 'WIDGET' );
		$product->method( 'get_length' )->willReturn( '6' );
		$product->method( 'get_width' )->willReturn( '4' );
		$product->method( 'get_height' )->willReturn( '3' );
		$product->method( 'get_weight' )->willReturn( '8' );

		$item = $this->createMock( \WC_Order_Item_Product::class );
		$item->method( 'get_product' )->willReturn( $product );
		$item->method( 'get_name' )->willReturn( 'Widget' );
		$item->method( 'get_quantity' )->willReturn( 1 );

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_items' )->willReturn( array( 1 => $item ) );

		$this->settings->method( 'get_boxes' )->willReturn( $this->make_boxes() );

		$result = $this->service->pack_order( $order );

		$this->assertNotEmpty( $result );
		$this->assertArrayHasKey( 'packed_box', $result[0] );
		$this->assertArrayHasKey( 'items', $result[0] );
		$this->assertArrayHasKey( 'weight_oz', $result[0] );
		$this->assertArrayHasKey( 'dimensions', $result[0] );
	}

	public function test_pack_order_uses_fallback_dimensions_when_product_missing_data(): void {
		$product = $this->createMock( \WC_Product::class );
		$product->method( 'needs_shipping' )->willReturn( true );
		$product->method( 'get_id' )->willReturn( 2 );
		$product->method( 'get_sku' )->willReturn( '' );
		$product->method( 'get_length' )->willReturn( '' );  // no dimension
		$product->method( 'get_width' )->willReturn( '' );
		$product->method( 'get_height' )->willReturn( '' );
		$product->method( 'get_weight' )->willReturn( '' );

		$item = $this->createMock( \WC_Order_Item_Product::class );
		$item->method( 'get_product' )->willReturn( $product );
		$item->method( 'get_name' )->willReturn( 'Mystery Item' );
		$item->method( 'get_quantity' )->willReturn( 1 );

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_items' )->willReturn( array( 1 => $item ) );

		$this->settings->method( 'get_boxes' )->willReturn( $this->make_boxes() );

		// Default dims are 1×1×1 in and weight 0.1 oz – must still produce a result.
		$result = $this->service->pack_order( $order );
		$this->assertNotEmpty( $result );
	}

	public function test_pack_order_expands_quantity_to_separate_item_entries(): void {
		$product = $this->createMock( \WC_Product::class );
		$product->method( 'needs_shipping' )->willReturn( true );
		$product->method( 'get_id' )->willReturn( 3 );
		$product->method( 'get_sku' )->willReturn( 'QTY3' );
		$product->method( 'get_length' )->willReturn( '4' );
		$product->method( 'get_width' )->willReturn( '3' );
		$product->method( 'get_height' )->willReturn( '2' );
		$product->method( 'get_weight' )->willReturn( '4' );

		$item = $this->createMock( \WC_Order_Item_Product::class );
		$item->method( 'get_product' )->willReturn( $product );
		$item->method( 'get_name' )->willReturn( 'Small Widget' );
		$item->method( 'get_quantity' )->willReturn( 3 );

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_items' )->willReturn( array( 1 => $item ) );

		$this->settings->method( 'get_boxes' )->willReturn( $this->make_boxes() );

		$result       = $this->service->pack_order( $order );
		$total_items  = array_sum( array_map( fn( $p ) => count( $p['items'] ), $result ) );

		$this->assertSame( 3, $total_items );
	}

	public function test_pack_items_with_boxpacker_uses_inner_dimensions(): void {
		$boxes = array(
			array(
				'reference'    => 'ThickWall',
				'package_code' => 'package',
				'package_name' => 'Thick Wall Box',
				'box_type'     => 'cubic',
				'outer_width'  => 10,
				'outer_length' => 10,
				'outer_depth'  => 8,
				'inner_width'  => 9,
				'inner_length' => 9,
				'inner_depth'  => 7,
				'empty_weight' => 4,
				'max_weight'   => 20,
			),
		);
		$this->settings->method( 'get_boxes' )->willReturn( $boxes );

		$items = array(
			array(
				'product_id' => 1,
				'name'       => 'Widget',
				'length'     => 5.0,
				'width'      => 5.0,
				'height'     => 4.0,
				'weight_oz'  => 5.0,
				'sku'        => 'W',
			),
		);

		$result = $this->service->pack_items( $items );

		$this->assertCount( 1, $result );
		// Packed package dimensions should use the box's inner dimensions,
		// not outer, so that candidate matching works correctly.
		$this->assertSame( 9.0, $result[0]['dimensions']['length'] );
		$this->assertSame( 9.0, $result[0]['dimensions']['width'] );
		$this->assertSame( 7.0, $result[0]['dimensions']['height'] );
	}
}
