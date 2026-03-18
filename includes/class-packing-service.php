<?php

namespace FK_USPS_Optimizer;

if (! defined('ABSPATH')) {
	exit;
}

class Packing_Service {
	/**
	 * @var Settings
	 */
	protected $settings;

	public function __construct(Settings $settings) {
		$this->settings = $settings;
	}

	public function pack_order(\WC_Order $order): array {
		$items = $this->get_shippable_items($order);

		if (empty($items)) {
			return array();
		}

		if (class_exists('\DVDoug\BoxPacker\Packer')) {
			return $this->pack_with_boxpacker($items);
		}

		return $this->pack_fallback($items);
	}

	protected function get_shippable_items(\WC_Order $order): array {
		$items = array();

		foreach ($order->get_items() as $item_id => $item) {
			if (! $item instanceof \WC_Order_Item_Product) {
				continue;
			}

			$product = $item->get_product();

			if (! $product || ! $product->needs_shipping()) {
				continue;
			}

			$length = (float) wc_get_dimension($product->get_length('edit') ?: 1, 'in');
			$width  = (float) wc_get_dimension($product->get_width('edit') ?: 1, 'in');
			$height = (float) wc_get_dimension($product->get_height('edit') ?: 1, 'in');
			$weight = (float) wc_get_weight($product->get_weight('edit') ?: 0.1, 'oz');
			$qty    = max(1, (int) $item->get_quantity());

			for ($i = 0; $i < $qty; $i++) {
				$items[] = array(
					'item_id'      => $item_id,
					'product_id'   => $product->get_id(),
					'name'         => $item->get_name(),
					'length'       => $length,
					'width'        => $width,
					'height'       => $height,
					'weight_oz'    => $weight,
					'sku'          => $product->get_sku(),
				);
			}
		}

		return $items;
	}

	protected function pack_with_boxpacker(array $items): array {
		$packer = new \DVDoug\BoxPacker\Packer();

		foreach ($this->settings->get_boxes() as $box_definition) {
			$packer->addBox(new BoxPacker_Box($this->convert_box_to_boxpacker_units($box_definition)));
		}

		foreach ($items as $index => $item) {
			$packer->addItem(new BoxPacker_Item(
				(string) ($item['product_id'] . '-' . $index),
				$item['name'],
				$this->to_mm($item['width']),
				$this->to_mm($item['length']),
				$this->to_mm($item['height']),
				$this->to_g($item['weight_oz']),
				false,
				$item
			));
		}

		$packed_boxes = $packer->pack();
		$packages     = array();

		foreach ($packed_boxes as $packed_box) {
			$box_meta = method_exists($packed_box->getBox(), 'getMeta') ? $packed_box->getBox()->getMeta() : array();
			$display_box = $box_meta['source_definition'] ?? $box_meta;
			$packed_items = array();
			$item_weight  = 0.0;

			foreach ($packed_box->getItems() as $packed_item) {
				$source_item    = $packed_item->getItem()->getSourceData();
				$packed_items[] = $source_item;
				$item_weight   += (float) $source_item['weight_oz'];
			}

			$packages[] = array(
				'packed_box'      => $display_box,
				'items'           => $packed_items,
				'weight_oz'       => $item_weight,
				'dimensions'      => array(
					'length' => (float) ($display_box['outer_length'] ?? 0),
					'width'  => (float) ($display_box['outer_width'] ?? 0),
					'height' => (float) ($display_box['outer_depth'] ?? 0),
				),
			);
		}

		return $packages;
	}

	protected function pack_fallback(array $items): array {
		$packages = array();
		$boxes    = $this->settings->get_boxes();

		foreach ($items as $item) {
			$selected_box = $this->match_item_to_box($item, $boxes);

			$packages[] = array(
				'packed_box' => $selected_box,
				'items'      => array($item),
				'weight_oz'  => $item['weight_oz'],
				'dimensions' => array(
					'length' => (float) ($selected_box['outer_length'] ?? $item['length']),
					'width'  => (float) ($selected_box['outer_width'] ?? $item['width']),
					'height' => (float) ($selected_box['outer_depth'] ?? $item['height']),
				),
			);
		}

		return $packages;
	}

	protected function match_item_to_box(array $item, array $boxes): array {
		foreach ($boxes as $box) {
			if (
				$item['length'] <= (float) $box['inner_length'] &&
				$item['width'] <= (float) $box['inner_width'] &&
				$item['height'] <= (float) $box['inner_depth'] &&
				$item['weight_oz'] <= ((float) $box['max_weight'] * 16)
			) {
				return $box;
			}
		}

		return array(
			'reference'    => 'Fallback Package',
			'package_code' => 'package',
			'package_name' => 'Fallback Package',
			'box_type'     => 'cubic',
			'outer_width'  => max(1, (int) ceil($item['width'])),
			'outer_length' => max(1, (int) ceil($item['length'])),
			'outer_depth'  => max(1, (int) ceil($item['height'])),
			'empty_weight' => 0,
			'max_weight'   => 20,
		);
	}

	protected function convert_box_to_boxpacker_units(array $box): array {
		$original = $box;
		$box['outer_width']  = $this->to_mm((float) $box['outer_width']);
		$box['outer_length'] = $this->to_mm((float) $box['outer_length']);
		$box['outer_depth']  = $this->to_mm((float) $box['outer_depth']);
		$box['inner_width']  = $this->to_mm((float) $box['inner_width']);
		$box['inner_length'] = $this->to_mm((float) $box['inner_length']);
		$box['inner_depth']  = $this->to_mm((float) $box['inner_depth']);
		$box['empty_weight'] = $this->to_g((float) $box['empty_weight']);
		$box['max_weight']   = $this->to_g((float) $box['max_weight'] * 16);
		$box['source_definition'] = $original;

		return $box;
	}

	protected function to_mm(float $inches): int {
		return (int) round($inches * 25.4);
	}

	protected function to_g(float $ounces): int {
		return (int) round($ounces * 28.3495);
	}
}
