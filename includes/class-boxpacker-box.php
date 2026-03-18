<?php
/**
 * BoxPacker box adapter for the FK USPS Optimizer plugin.
 *
 * @package FK_USPS_Optimizer
 */

namespace FK_USPS_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adapts plugin box definitions to the DVDoug BoxPacker Box interface.
 */
class BoxPacker_Box implements \DVDoug\BoxPacker\Box {
	/**
	 * Box reference name.
	 *
	 * @var string
	 */
	protected $reference;

	/**
	 * Outer width in millimetres.
	 *
	 * @var int
	 */
	protected $outer_width;

	/**
	 * Outer length in millimetres.
	 *
	 * @var int
	 */
	protected $outer_length;

	/**
	 * Outer depth in millimetres.
	 *
	 * @var int
	 */
	protected $outer_depth;

	/**
	 * Empty box weight in grams.
	 *
	 * @var int
	 */
	protected $empty_weight;

	/**
	 * Inner width in millimetres.
	 *
	 * @var int
	 */
	protected $inner_width;

	/**
	 * Inner length in millimetres.
	 *
	 * @var int
	 */
	protected $inner_length;

	/**
	 * Inner depth in millimetres.
	 *
	 * @var int
	 */
	protected $inner_depth;

	/**
	 * Maximum weight the box can hold in grams.
	 *
	 * @var int
	 */
	protected $max_weight;

	/**
	 * Full box definition array.
	 *
	 * @var array
	 */
	protected $meta;

	/**
	 * Constructor.
	 *
	 * @param array $definition Box definition data.
	 */
	public function __construct( array $definition ) {
		$this->reference    = (string) $definition['reference'];
		$this->outer_width  = (int) $definition['outer_width'];
		$this->outer_length = (int) $definition['outer_length'];
		$this->outer_depth  = (int) $definition['outer_depth'];
		$this->empty_weight = (int) $definition['empty_weight'];
		$this->inner_width  = (int) $definition['inner_width'];
		$this->inner_length = (int) $definition['inner_length'];
		$this->inner_depth  = (int) $definition['inner_depth'];
		$this->max_weight   = (int) $definition['max_weight'];
		$this->meta         = $definition;
	}

	/**
	 * Get the box reference name.
	 *
	 * @return string Box reference name.
	 */
	public function getReference(): string {
		return $this->reference;
	}

	/**
	 * Get the outer width.
	 *
	 * @return int Outer width in mm.
	 */
	public function getOuterWidth(): int {
		return $this->outer_width;
	}

	/**
	 * Get the outer length.
	 *
	 * @return int Outer length in mm.
	 */
	public function getOuterLength(): int {
		return $this->outer_length;
	}

	/**
	 * Get the outer depth.
	 *
	 * @return int Outer depth in mm.
	 */
	public function getOuterDepth(): int {
		return $this->outer_depth;
	}

	/**
	 * Get the empty box weight.
	 *
	 * @return int Empty box weight in grams.
	 */
	public function getEmptyWeight(): int {
		return $this->empty_weight;
	}

	/**
	 * Get the inner width.
	 *
	 * @return int Inner width in mm.
	 */
	public function getInnerWidth(): int {
		return $this->inner_width;
	}

	/**
	 * Get the inner length.
	 *
	 * @return int Inner length in mm.
	 */
	public function getInnerLength(): int {
		return $this->inner_length;
	}

	/**
	 * Get the inner depth.
	 *
	 * @return int Inner depth in mm.
	 */
	public function getInnerDepth(): int {
		return $this->inner_depth;
	}

	/**
	 * Get the maximum weight.
	 *
	 * @return int Maximum weight in grams.
	 */
	public function getMaxWeight(): int {
		return $this->max_weight;
	}

	/**
	 * Get the full box definition array.
	 *
	 * @return array Full box definition array.
	 */
	public function getMeta(): array {
		return $this->meta;
	}
}
