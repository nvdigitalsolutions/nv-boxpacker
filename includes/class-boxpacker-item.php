<?php
/**
 * BoxPacker item adapter for the FK USPS Optimizer plugin.
 *
 * @package FK_USPS_Optimizer
 */

namespace FK_USPS_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adapts plugin item data to the DVDoug BoxPacker Item interface.
 */
class BoxPacker_Item implements \DVDoug\BoxPacker\Item {
	/**
	 * Unique item identifier.
	 *
	 * @var string
	 */
	protected $id;

	/**
	 * Item description.
	 *
	 * @var string
	 */
	protected $description;

	/**
	 * Item width in millimetres.
	 *
	 * @var int
	 */
	protected $width;

	/**
	 * Item length in millimetres.
	 *
	 * @var int
	 */
	protected $length;

	/**
	 * Item depth in millimetres.
	 *
	 * @var int
	 */
	protected $depth;

	/**
	 * Item weight in grams.
	 *
	 * @var int
	 */
	protected $weight;

	/**
	 * Whether the item must be kept flat.
	 *
	 * @var bool
	 */
	protected $keep_flat;

	/**
	 * Original item source data.
	 *
	 * @var array
	 */
	protected $source_data;

	/**
	 * Constructor.
	 *
	 * @param string $id          Unique identifier.
	 * @param string $description Item description.
	 * @param int    $width       Width in mm.
	 * @param int    $length      Length in mm.
	 * @param int    $depth       Depth in mm.
	 * @param int    $weight      Weight in grams.
	 * @param bool   $keep_flat   Whether to keep flat.
	 * @param array  $source_data Original source data.
	 */
	public function __construct( string $id, string $description, int $width, int $length, int $depth, int $weight, bool $keep_flat = false, array $source_data = array() ) {
		$this->id          = $id;
		$this->description = $description;
		$this->width       = $width;
		$this->length      = $length;
		$this->depth       = $depth;
		$this->weight      = $weight;
		$this->keep_flat   = $keep_flat;
		$this->source_data = $source_data;
	}

	/**
	 * Get the item description.
	 *
	 * @return string Item description.
	 */
	public function getDescription(): string {
		return $this->description;
	}

	/**
	 * Get the item width.
	 *
	 * @return int Width in mm.
	 */
	public function getWidth(): int {
		return $this->width;
	}

	/**
	 * Get the item length.
	 *
	 * @return int Length in mm.
	 */
	public function getLength(): int {
		return $this->length;
	}

	/**
	 * Get the item depth.
	 *
	 * @return int Depth in mm.
	 */
	public function getDepth(): int {
		return $this->depth;
	}

	/**
	 * Get the item weight.
	 *
	 * @return int Weight in grams.
	 */
	public function getWeight(): int {
		return $this->weight;
	}

	/**
	 * Whether the item must remain flat during packing.
	 *
	 * @return bool True if the item must be kept flat.
	 */
	public function keepFlat(): bool {
		return $this->keep_flat;
	}

	/**
	 * Get the original source data.
	 *
	 * @return array Original source data.
	 */
	public function getSourceData(): array {
		return $this->source_data;
	}

	/**
	 * Get the unique identifier.
	 *
	 * @return string Unique identifier.
	 */
	public function getId(): string {
		return $this->id;
	}
}
