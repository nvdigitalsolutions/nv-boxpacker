<?php
/**
 * Unit tests for BoxPacker_Item.
 *
 * @package FK_USPS_Optimizer
 */

namespace FK_USPS_Optimizer\Tests\Unit;

use FK_USPS_Optimizer\BoxPacker_Item;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the BoxPacker_Item adapter.
 */
class BoxPackerItemTest extends TestCase {

	/**
	 * Source item data used across tests.
	 *
	 * @var array
	 */
	private array $source_data;

	/**
	 * System under test.
	 *
	 * @var BoxPacker_Item
	 */
	private BoxPacker_Item $item;

	protected function setUp(): void {
		$this->source_data = array(
			'item_id'    => 123,
			'product_id' => 456,
			'name'       => 'Widget A',
			'weight_oz'  => 8.0,
		);

		$this->item = new BoxPacker_Item(
			'prod-456-0',
			'Widget A',
			152,  // width  mm  (6 in)
			203,  // length mm  (8 in)
			76,   // depth  mm  (3 in)
			227,  // weight g   (8 oz)
			false,
			$this->source_data
		);
	}

	public function test_get_id(): void {
		$this->assertSame( 'prod-456-0', $this->item->getId() );
	}

	public function test_get_description(): void {
		$this->assertSame( 'Widget A', $this->item->getDescription() );
	}

	public function test_get_width(): void {
		$this->assertSame( 152, $this->item->getWidth() );
	}

	public function test_get_length(): void {
		$this->assertSame( 203, $this->item->getLength() );
	}

	public function test_get_depth(): void {
		$this->assertSame( 76, $this->item->getDepth() );
	}

	public function test_get_weight(): void {
		$this->assertSame( 227, $this->item->getWeight() );
	}

	public function test_keep_flat_defaults_to_false(): void {
		$this->assertFalse( $this->item->getKeepFlat() );
	}

	public function test_keep_flat_true_when_set(): void {
		$item = new BoxPacker_Item( 'id', 'Flat Item', 100, 200, 10, 50, true );
		$this->assertTrue( $item->getKeepFlat() );
	}

	public function test_get_source_data(): void {
		$this->assertSame( $this->source_data, $this->item->getSourceData() );
	}

	public function test_get_source_data_defaults_to_empty_array(): void {
		$item = new BoxPacker_Item( 'id', 'desc', 1, 1, 1, 1 );
		$this->assertSame( array(), $item->getSourceData() );
	}

	public function test_implements_boxpacker_item_interface(): void {
		$this->assertInstanceOf( \DVDoug\BoxPacker\Item::class, $this->item );
	}
}
