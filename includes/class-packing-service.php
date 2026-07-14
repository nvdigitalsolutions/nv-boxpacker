<?php
/**
 * Packing service for the FK USPS Optimizer plugin.
 *
 * Handles order item packing for US and Canadian destination shipments.
 *
 * @package FK_USPS_Optimizer
 */

namespace FK_USPS_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles order packing logic using BoxPacker or a fallback strategy.
 */
class Packing_Service {
	/**
	 * Plugin settings instance.
	 *
	 * @var Settings
	 */
	protected $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Plugin settings instance.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Pack all shippable items in the order into boxes.
	 *
	 * @param \WC_Order $order The WooCommerce order to pack.
	 * @return array Packed packages.
	 */
	public function pack_order( \WC_Order $order ): array {
		return $this->pack_items( $this->get_shippable_items( $order ) );
	}

	/**
	 * Pack a flat list of item arrays into boxes.
	 *
	 * Each item must contain: name, length, width, height (inches) and weight_oz.
	 * Items may also include a 'has_dimensions' boolean flag.  Items without
	 * real dimensions (has_dimensions === false) are always packed individually
	 * via the fallback strategy so that each item gets its own box.
	 *
	 * Additional keys (product_id, item_id, sku, etc.) are preserved and returned
	 * in the packed-package item lists.
	 *
	 * @param array      $items Flat list of item arrays.
	 * @param array|null $boxes Optional box definitions to use instead of settings.
	 * @return array Packed packages.
	 */
	public function pack_items( array $items, ?array $boxes = null ): array {
		if ( empty( $items ) ) {
			return array();
		}

		if ( null === $boxes ) {
			$boxes = $this->settings->get_boxes();
		}

		// Exclude any boxes that have been explicitly disabled (e.g. out of stock).
		// Boxes lacking the `enabled` key are treated as enabled for backward compatibility.
		$boxes = array_values(
			array_filter(
				$boxes,
				static function ( $box ): bool {
					if ( ! is_array( $box ) ) {
						return false;
					}
					if ( ! array_key_exists( 'enabled', $box ) ) {
						return true;
					}
					return (bool) filter_var( $box['enabled'], FILTER_VALIDATE_BOOLEAN );
				}
			)
		);

		// Split items into those with real dimensions (optimise via BoxPacker)
		// and those without (fall back to one-item-per-box).
		$measured   = array();
		$unmeasured = array();

		foreach ( $items as $item ) {
			if ( isset( $item['has_dimensions'] ) && false === $item['has_dimensions'] ) {
				$unmeasured[] = $item;
			} else {
				$measured[] = $item;
			}
		}

		$packages = array();

		// Pack measured items with BoxPacker when available.
		if ( ! empty( $measured ) ) {
			if ( class_exists( '\DVDoug\BoxPacker\Packer' ) ) {
				$packages = $this->pack_with_boxpacker( $measured, $boxes );
			} else {
				$packages = $this->pack_fallback( $measured, $boxes );
			}
		}

		// Unmeasured items always get one-item-per-box via fallback.
		if ( ! empty( $unmeasured ) ) {
			$packages = array_merge( $packages, $this->pack_fallback( $unmeasured, $boxes ) );
		}

		return $packages;
	}

	/**
	 * Retrieve shippable items from the order.
	 *
	 * @param \WC_Order $order The WooCommerce order.
	 * @return array Array of shippable item data.
	 */
	protected function get_shippable_items( \WC_Order $order ): array {
		$items = array();

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();

			if ( ! $product || ! $product->needs_shipping() ) {
				continue;
			}

			$raw_length = $product->get_length( 'edit' );
			$raw_width  = $product->get_width( 'edit' );
			$raw_height = $product->get_height( 'edit' );
			$raw_weight = $product->get_weight( 'edit' );

			$has_dimensions = ( '' !== $raw_length && '' !== $raw_width && '' !== $raw_height );
			$length         = (float) wc_get_dimension( $raw_length ? $raw_length : 1, 'in' );
			$width          = (float) wc_get_dimension( $raw_width ? $raw_width : 1, 'in' );
			$height         = (float) wc_get_dimension( $raw_height ? $raw_height : 1, 'in' );
			$weight         = (float) wc_get_weight( $raw_weight ? $raw_weight : 0.1, 'oz' );
			$qty            = max( 1, (int) $item->get_quantity() );

			for ( $i = 0; $i < $qty; $i++ ) {
				$items[] = array(
					'item_id'        => $item_id,
					'product_id'     => $product->get_id(),
					'name'           => $item->get_name(),
					'length'         => $length,
					'width'          => $width,
					'height'         => $height,
					'weight_oz'      => $weight,
					'has_dimensions' => $has_dimensions,
					'sku'            => $product->get_sku(),
				);
			}
		}

		return $items;
	}

	/**
	 * Pack items using the DVDoug BoxPacker library.
	 *
	 * @param array $items Shippable items to pack.
	 * @param array $boxes Box definitions to use.
	 * @return array Packed packages.
	 */
	protected function pack_with_boxpacker( array $items, array $boxes ): array {
		$packer = new \DVDoug\BoxPacker\Packer();

		foreach ( $boxes as $box_definition ) {
			$packer->addBox( new BoxPacker_Box( $this->convert_box_to_boxpacker_units( $box_definition ) ) );
		}

		foreach ( $items as $index => $item ) {
			$packer->addItem(
				new BoxPacker_Item(
					(string) ( $item['product_id'] . '-' . $index ),
					$item['name'],
					$this->to_mm( $item['width'] ),
					$this->to_mm( $item['length'] ),
					$this->to_mm( $item['height'] ),
					$this->to_g( $item['weight_oz'] ),
					false,
					$item
				)
			);
		}

		try {
			$packed_boxes = $packer->pack();
			$packages     = array();

			foreach ( $packed_boxes as $packed_box ) {
				$box_meta     = method_exists( $packed_box->getBox(), 'getMeta' ) ? $packed_box->getBox()->getMeta() : array();
				$display_box  = $box_meta['source_definition'] ?? $box_meta;
				$packed_items = array();
				$item_weight  = 0.0;

				foreach ( $packed_box->getItems() as $packed_item ) {
					$source_item    = $packed_item->getItem()->getSourceData();
					$packed_items[] = $source_item;
					$item_weight   += (float) $source_item['weight_oz'];
				}

				$packages[] = array(
					'packed_box' => $display_box,
					'items'      => $packed_items,
					'weight_oz'  => $item_weight,
					'dimensions' => array(
						'length' => (float) ( $display_box['inner_length'] ?? 0 ),
						'width'  => (float) ( $display_box['inner_width'] ?? 0 ),
						'height' => (float) ( $display_box['inner_depth'] ?? 0 ),
					),
				);
			}

			// Post-process: BoxPacker's heuristic search can miss optimal
			// box assignments.  Try to downsize each package to the smallest
			// box that can actually hold its items.
			return $this->optimize_packed_boxes( $packages, $boxes );
		} catch ( \DVDoug\BoxPacker\ItemTooLargeException | \DVDoug\BoxPacker\NoBoxesAvailableException $e ) {
			return $this->pack_fallback( $items, $boxes );
		}
	}

	/**
	 * Post-process BoxPacker packages to use the smallest box that
	 * fits each item group.
	 *
	 * BoxPacker's heuristic search minimises box count first, but
	 * within that goal it can pick a larger box than necessary
	 * (e.g. a 2 Bag for a single small item when a 1 Bag fits).
	 * This pass checks each package and downsizes when possible.
	 *
	 * Single-item packages use the simple dimensional match
	 * (match_item_to_box) which is guaranteed to find the smallest
	 * fitting box.  Multi-item packages are re-packed via BoxPacker
	 * against only the smaller candidate boxes.
	 *
	 * @param array $packages Packages produced by BoxPacker.
	 * @param array $boxes    Available box definitions.
	 * @return array Optimised packages.
	 */
	protected function optimize_packed_boxes( array $packages, array $boxes ): array {
		// Pre-sort by inner volume ascending so we try smallest boxes first.
		$sorted_boxes = $boxes;
		usort(
			$sorted_boxes,
			static function ( array $a, array $b ): int {
				$va = (float) $a['inner_length'] * (float) $a['inner_width'] * (float) $a['inner_depth'];
				$vb = (float) $b['inner_length'] * (float) $b['inner_width'] * (float) $b['inner_depth'];
				return $va <=> $vb;
			}
		);

		foreach ( $packages as &$package ) {
			$current_box = $package['packed_box'];
			$current_vol = (float) $current_box['inner_length']
				* (float) $current_box['inner_width']
				* (float) $current_box['inner_depth'];

			// Single item — exact dimensional match finds the smallest box.
			if ( 1 === count( $package['items'] ) ) {
				$best     = $this->match_item_to_box( $package['items'][0], $sorted_boxes );
				$best_vol = (float) $best['inner_length']
					* (float) $best['inner_width']
					* (float) $best['inner_depth'];

				if ( $best_vol < $current_vol ) {
					$package = $this->rebind_package_to_box( $package, $best );
				}
				continue;
			}

			// Multiple items — try each successively larger candidate box.
			foreach ( $sorted_boxes as $candidate ) {
				$cand_vol = (float) $candidate['inner_length']
					* (float) $candidate['inner_width']
					* (float) $candidate['inner_depth'];

				if ( $cand_vol >= $current_vol ) {
					break; // No smaller boxes remain.
				}

				// Quick weight gate — skip boxes whose max weight is too low.
				if ( $package['weight_oz'] > (float) $candidate['max_weight'] * 16 ) {
					continue;
				}

				// Ask BoxPacker whether the items fit in this single candidate box.
				if ( $this->items_fit_in_box( $package['items'], $candidate ) ) {
					$package = $this->rebind_package_to_box( $package, $candidate );
					break;
				}
			}
		}
		unset( $package );

		return $packages;
	}

	/**
	 * Check whether a group of items all fit into a single specific box.
	 *
	 * Creates a fresh BoxPacker instance with only the candidate box and
	 * the given items.  Returns true when pack() succeeds with exactly
	 * one packed box (all items placed).
	 *
	 * @param array $items Item data arrays.
	 * @param array $box   Single box definition.
	 * @return bool True when the items fit in the box.
	 */
	protected function items_fit_in_box( array $items, array $box ): bool {
		try {
			$packer = new \DVDoug\BoxPacker\Packer();
			$packer->addBox( new BoxPacker_Box( $this->convert_box_to_boxpacker_units( $box ) ) );

			foreach ( $items as $index => $item ) {
				$packer->addItem(
					new BoxPacker_Item(
						(string) ( $item['product_id'] . '-' . $index ),
						$item['name'],
						$this->to_mm( $item['width'] ),
						$this->to_mm( $item['length'] ),
						$this->to_mm( $item['height'] ),
						$this->to_g( $item['weight_oz'] ),
						false,
						$item
					)
				);
			}

			$result = $packer->pack();
			return 1 === $result->count();
		} catch ( \DVDoug\BoxPacker\ItemTooLargeException | \DVDoug\BoxPacker\NoBoxesAvailableException $e ) {
			return false;
		}
	}

	/**
	 * Create a new package array bound to a different box definition.
	 *
	 * @param array $package Original package data.
	 * @param array $box     New box definition to bind.
	 * @return array Updated package data.
	 */
	protected function rebind_package_to_box( array $package, array $box ): array {
		$package['packed_box'] = $box;
		$package['dimensions'] = array(
			'length' => (float) ( $box['inner_length'] ?? 0 ),
			'width'  => (float) ( $box['inner_width'] ?? 0 ),
			'height' => (float) ( $box['inner_depth'] ?? 0 ),
		);
		return $package;
	}

	/**
	 * Fallback packing strategy when BoxPacker is not available.
	 *
	 * @param array $items Shippable items to pack.
	 * @param array $boxes Box definitions to use.
	 * @return array Packed packages.
	 */
	protected function pack_fallback( array $items, array $boxes ): array {
		$packages = array();

		foreach ( $items as $item ) {
			$selected_box = $this->match_item_to_box( $item, $boxes );

			$packages[] = array(
				'packed_box' => $selected_box,
				'items'      => array( $item ),
				'weight_oz'  => $item['weight_oz'],
				'dimensions' => array(
					'length' => (float) ( $selected_box['inner_length'] ?? $item['length'] ),
					'width'  => (float) ( $selected_box['inner_width'] ?? $item['width'] ),
					'height' => (float) ( $selected_box['inner_depth'] ?? $item['height'] ),
				),
			);
		}

		return $packages;
	}

	/**
	 * Match a single item to the best-fitting box definition.
	 *
	 * @param array $item  Item data.
	 * @param array $boxes Available box definitions.
	 * @return array Matched box definition or a fallback box.
	 */
	protected function match_item_to_box( array $item, array $boxes ): array {
		foreach ( $boxes as $box ) {
			if (
				$item['length'] <= (float) $box['inner_length'] &&
				$item['width'] <= (float) $box['inner_width'] &&
				$item['height'] <= (float) $box['inner_depth'] &&
				$item['weight_oz'] <= ( (float) $box['max_weight'] * 16 )
			) {
				return $box;
			}
		}

		return array(
			'reference'    => 'Fallback Package',
			'package_code' => 'package',
			'package_name' => 'Fallback Package',
			'box_type'     => 'cubic',
			'outer_width'  => max( 1, (int) ceil( $item['width'] ) ),
			'outer_length' => max( 1, (int) ceil( $item['length'] ) ),
			'outer_depth'  => max( 1, (int) ceil( $item['height'] ) ),
			'empty_weight' => 0,
			'max_weight'   => 20,
		);
	}

	/**
	 * Convert box dimensions from inches/ounces to millimetres/grams for BoxPacker.
	 *
	 * @param array $box Box definition with dimensions in inches and weight in ounces.
	 * @return array Box definition with dimensions in mm and weight in grams.
	 */
	protected function convert_box_to_boxpacker_units( array $box ): array {
		$original                 = $box;
		$box['outer_width']       = $this->to_mm( (float) $box['outer_width'] );
		$box['outer_length']      = $this->to_mm( (float) $box['outer_length'] );
		$box['outer_depth']       = $this->to_mm( (float) $box['outer_depth'] );
		$box['inner_width']       = $this->to_mm( (float) $box['inner_width'] );
		$box['inner_length']      = $this->to_mm( (float) $box['inner_length'] );
		$box['inner_depth']       = $this->to_mm( (float) $box['inner_depth'] );
		$box['empty_weight']      = $this->to_g( (float) $box['empty_weight'] );
		$box['max_weight']        = $this->to_g( (float) $box['max_weight'] * 16 );
		$box['source_definition'] = $original;

		return $box;
	}

	/**
	 * Convert inches to millimetres.
	 *
	 * @param float $inches Value in inches.
	 * @return int Value in millimetres.
	 */
	protected function to_mm( float $inches ): int {
		return (int) round( $inches * 25.4 );
	}

	/**
	 * Convert ounces to grams.
	 *
	 * @param float $ounces Value in ounces.
	 * @return int Value in grams.
	 */
	protected function to_g( float $ounces ): int {
		return (int) round( $ounces * 28.3495 );
	}
}
