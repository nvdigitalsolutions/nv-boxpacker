<?php
/**
 * Unit tests for BoxPacker_Box.
 *
 * @package FK_USPS_Optimizer
 */

namespace FK_USPS_Optimizer\Tests\Unit;

use FK_USPS_Optimizer\BoxPacker_Box;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the BoxPacker_Box adapter.
 */
class BoxPackerBoxTest extends TestCase {

	/**
	 * Full box definition used across tests.
	 *
	 * @var array
	 */
	private array $definition;

	/**
	 * System under test.
	 *
	 * @var BoxPacker_Box
	 */
	private BoxPacker_Box $box;

	protected function setUp(): void {
		$this->definition = array(
			'reference'    => 'Test Box',
			'package_code' => 'package',
			'package_name' => 'Test Box Name',
			'box_type'     => 'cubic',
			'outer_width'  => 304,
			'outer_length' => 254,
			'outer_depth'  => 152,
			'inner_width'  => 279,
			'inner_length' => 229,
			'inner_depth'  => 127,
			'empty_weight' => 85,
			'max_weight'   => 9072,
		);
		$this->box = new BoxPacker_Box( $this->definition );
	}

	public function test_get_reference(): void {
		$this->assertSame( 'Test Box', $this->box->getReference() );
	}

	public function test_get_outer_width(): void {
		$this->assertSame( 304, $this->box->getOuterWidth() );
	}

	public function test_get_outer_length(): void {
		$this->assertSame( 254, $this->box->getOuterLength() );
	}

	public function test_get_outer_depth(): void {
		$this->assertSame( 152, $this->box->getOuterDepth() );
	}

	public function test_get_empty_weight(): void {
		$this->assertSame( 85, $this->box->getEmptyWeight() );
	}

	public function test_get_inner_width(): void {
		$this->assertSame( 279, $this->box->getInnerWidth() );
	}

	public function test_get_inner_length(): void {
		$this->assertSame( 229, $this->box->getInnerLength() );
	}

	public function test_get_inner_depth(): void {
		$this->assertSame( 127, $this->box->getInnerDepth() );
	}

	public function test_get_max_weight(): void {
		$this->assertSame( 9072, $this->box->getMaxWeight() );
	}

	public function test_get_meta_returns_full_definition(): void {
		$this->assertSame( $this->definition, $this->box->getMeta() );
	}

	public function test_constructor_casts_string_values_to_int(): void {
		$def = array(
			'reference'    => 'Cast Box',
			'package_code' => 'package',
			'package_name' => 'Cast',
			'box_type'     => 'cubic',
			'outer_width'  => '100',
			'outer_length' => '200',
			'outer_depth'  => '50',
			'inner_width'  => '90',
			'inner_length' => '190',
			'inner_depth'  => '40',
			'empty_weight' => '30',
			'max_weight'   => '5000',
		);
		$box = new BoxPacker_Box( $def );
		$this->assertSame( 100, $box->getOuterWidth() );
		$this->assertSame( 200, $box->getOuterLength() );
		$this->assertSame( 50, $box->getOuterDepth() );
		$this->assertSame( 30, $box->getEmptyWeight() );
	}

	public function test_implements_boxpacker_interface(): void {
		$this->assertInstanceOf( \DVDoug\BoxPacker\Box::class, $this->box );
	}
}
