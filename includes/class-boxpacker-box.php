<?php

namespace FK_USPS_Optimizer;

if (! defined('ABSPATH')) {
	exit;
}

class BoxPacker_Box implements \DVDoug\BoxPacker\Box {
	protected $reference;
	protected $outer_width;
	protected $outer_length;
	protected $outer_depth;
	protected $empty_weight;
	protected $inner_width;
	protected $inner_length;
	protected $inner_depth;
	protected $max_weight;
	protected $meta;

	public function __construct(array $definition) {
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

	public function getReference(): string {
		return $this->reference;
	}

	public function getOuterWidth(): int {
		return $this->outer_width;
	}

	public function getOuterLength(): int {
		return $this->outer_length;
	}

	public function getOuterDepth(): int {
		return $this->outer_depth;
	}

	public function getEmptyWeight(): int {
		return $this->empty_weight;
	}

	public function getInnerWidth(): int {
		return $this->inner_width;
	}

	public function getInnerLength(): int {
		return $this->inner_length;
	}

	public function getInnerDepth(): int {
		return $this->inner_depth;
	}

	public function getMaxWeight(): int {
		return $this->max_weight;
	}

	public function getMeta(): array {
		return $this->meta;
	}
}
