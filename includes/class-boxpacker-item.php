<?php

namespace FK_USPS_Optimizer;

if (! defined('ABSPATH')) {
	exit;
}

class BoxPacker_Item implements \DVDoug\BoxPacker\Item {
	protected $id;
	protected $description;
	protected $width;
	protected $length;
	protected $depth;
	protected $weight;
	protected $keep_flat;
	protected $source_data;

	public function __construct(string $id, string $description, int $width, int $length, int $depth, int $weight, bool $keep_flat = false, array $source_data = array()) {
		$this->id          = $id;
		$this->description = $description;
		$this->width       = $width;
		$this->length      = $length;
		$this->depth       = $depth;
		$this->weight      = $weight;
		$this->keep_flat   = $keep_flat;
		$this->source_data = $source_data;
	}

	public function getDescription(): string {
		return $this->description;
	}

	public function getWidth(): int {
		return $this->width;
	}

	public function getLength(): int {
		return $this->length;
	}

	public function getDepth(): int {
		return $this->depth;
	}

	public function getWeight(): int {
		return $this->weight;
	}

	public function getAllowedRotation(): int {
		return $this->keep_flat ? \DVDoug\BoxPacker\Rotation::KeepFlat : \DVDoug\BoxPacker\Rotation::BestFit;
	}

	public function getSourceData(): array {
		return $this->source_data;
	}

	public function getId(): string {
		return $this->id;
	}
}
