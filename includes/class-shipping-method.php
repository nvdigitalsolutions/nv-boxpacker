<?php
/**
 * WooCommerce Shipping Method for the FK USPS Optimizer plugin.
 *
 * Registers the USPS Priority Shipping Optimizer as a shipping method
 * that can be added to WooCommerce shipping zones and provides live
 * optimized USPS Priority rates during cart and checkout.
 *
 * @package FK_USPS_Optimizer
 */

namespace FK_USPS_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce shipping method providing optimized USPS Priority Mail rates.
 */
class Shipping_Method extends \WC_Shipping_Method {

	/**
	 * Constructor.
	 *
	 * @param int $instance_id Shipping method instance ID.
	 */
	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'fk_usps_optimizer';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'USPS Priority Optimizer', 'fk-usps-optimizer' );
		$this->method_description = __( 'Optimized USPS Priority Mail rates using cubic and flat rate box packing.', 'fk-usps-optimizer' );
		$this->supports           = array(
			'shipping-zones',
			'instance-settings',
			'instance-settings-modal',
		);

		$this->init_form_fields();
		$this->init_settings();

		$this->title   = $this->get_option( 'title', $this->method_title );
		$this->enabled = $this->get_option( 'enabled', 'yes' );

		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Define settings fields for shipping method instances.
	 */
	public function init_form_fields(): void {
		$this->instance_form_fields = array(
			'title' => array(
				'title'   => __( 'Method Title', 'fk-usps-optimizer' ),
				'type'    => 'text',
				'default' => __( 'USPS Priority Mail (Optimized)', 'fk-usps-optimizer' ),
			),
		);
	}

	/**
	 * Calculate shipping rates for a package.
	 *
	 * Packs the cart items using BoxPacker, fetches USPS rates from the
	 * configured carrier API (ShipEngine or ShipStation), and adds the
	 * combined optimized rate.  When "Show All Options" is enabled, every
	 * combination (cartesian product) of rated box candidates is offered as
	 * a separate shipping option.
	 *
	 * @param array $package WooCommerce shipping package.
	 */
	public function calculate_shipping( $package = array() ) {
		$destination = $package['destination'] ?? array();

		if ( empty( $destination['country'] ) || empty( $destination['postcode'] ) ) {
			return;
		}

		$items = $this->extract_items( $package );

		if ( empty( $items ) ) {
			return;
		}

		$plugin   = Plugin::bootstrap();
		$settings = $plugin->get_settings();

		$packed_packages = $plugin->get_packing_service()->pack_items( $items );

		if ( empty( $packed_packages ) ) {
			return;
		}

		$ship_to = $this->build_ship_to( $destination );

		if ( $settings->is_show_all_options_enabled() ) {
			$rates = $this->calculate_all_options( $plugin, $packed_packages, $ship_to, $settings );
		} else {
			$rates = $this->calculate_cheapest_option( $plugin, $packed_packages, $ship_to, $settings );
		}

		if ( empty( $rates ) ) {
			return;
		}

		foreach ( $rates as $idx => $rate ) {
			$rate_args = array(
				'id'    => $this->get_rate_id() . ( $idx > 0 ? ':' . $idx : '' ),
				'label' => $rate['label'],
				'cost'  => (float) $rate['cost'],
			);

			if ( ! empty( $rate['meta_data'] ) ) {
				$rate_args['meta_data'] = $rate['meta_data'];
			}

			$this->add_rate( $rate_args );
		}
	}

	/**
	 * Calculate the single cheapest combined shipping option.
	 *
	 * Picks the cheapest rated candidate for every packed package and sums
	 * their rates into one shipping option.
	 *
	 * @param Plugin   $plugin          Plugin instance.
	 * @param array    $packed_packages Packed packages from Packing_Service.
	 * @param array    $ship_to         Carrier-compatible destination address.
	 * @param Settings $settings        Plugin settings.
	 * @return array Array with a single rate entry, or empty on failure.
	 */
	protected function calculate_cheapest_option( Plugin $plugin, array $packed_packages, array $ship_to, Settings $settings ): array {
		$carrier_services = $plugin->get_carrier_services();
		$total_cost       = 0.0;
		$all_rated        = true;
		$package_count    = count( $packed_packages );
		$service_labels   = array();
		$delivery_dates   = array();

		foreach ( $packed_packages as $index => $packed ) {
			$best_plan = array();
			$best_cost = PHP_FLOAT_MAX;

			foreach ( $carrier_services as $carrier_service ) {
				$plan = $carrier_service->build_test_package_plan( $packed, $ship_to, $index + 1 );

				if ( ! empty( $plan ) && (float) $plan['rate_amount'] < $best_cost ) {
					$best_plan = $plan;
					$best_cost = (float) $plan['rate_amount'];
				}
			}

			if ( empty( $best_plan ) ) {
				$all_rated = false;
				break;
			}

			$total_cost      += $best_cost;
			$service_labels[] = $best_plan['service_label'] ?? '';

			$date = (string) ( $best_plan['estimated_delivery_date'] ?? '' );
			if ( '' !== $date ) {
				$delivery_dates[] = $date;
			}
		}

		if ( ! $all_rated || $total_cost <= 0 ) {
			return array();
		}

		// Use the carrier service label when all packages share the same one;
		// fall back to the method title for backward compatibility.
		$unique_labels = array_unique( array_filter( $service_labels ) );
		$title         = 1 === count( $unique_labels )
			? reset( $unique_labels )
			: $this->title;

		$label = $title;
		if ( $settings->is_show_package_count_enabled() && $package_count > 0 ) {
			$label = sprintf(
				/* translators: 1: method title, 2: package count. */
				_n( '%1$s (%2$d package)', '%1$s (%2$d packages)', $package_count, 'fk-usps-optimizer' ),
				$title,
				$package_count
			);
		}

		$meta_data = array();
		if ( $settings->is_show_estimated_delivery_enabled() ) {
			if ( ! empty( $delivery_dates ) ) {
				sort( $delivery_dates );
				$formatted = $this->format_estimated_delivery( end( $delivery_dates ) );
				if ( '' !== $formatted ) {
					/* translators: %s: formatted estimated delivery date, e.g. "Mon, Jan 15". */
					$label .= ' — ' . sprintf( __( 'Est. delivery: %s', 'fk-usps-optimizer' ), $formatted );
					$meta_data[ __( 'Est. Delivery', 'fk-usps-optimizer' ) ] = $formatted;
				}
			} else {
				$no_estimate = __( '(No Estimate)', 'fk-usps-optimizer' );
				/* translators: %s: "(No Estimate)" placeholder. */
				$label .= ' — ' . sprintf( __( 'Est. delivery: %s', 'fk-usps-optimizer' ), $no_estimate );
				$meta_data[ __( 'Est. Delivery', 'fk-usps-optimizer' ) ] = $no_estimate;
			}
		}

		return array(
			array(
				'label'     => $label,
				'cost'      => $total_cost,
				'meta_data' => $meta_data,
			),
		);
	}

	/**
	 * Calculate all shipping option combinations via cartesian product.
	 *
	 * For each packed package, all rated candidates are collected.  The
	 * cartesian product of every package's candidates produces all possible
	 * shipping plans; each plan is offered as a separate WooCommerce rate.
	 *
	 * @param Plugin   $plugin          Plugin instance.
	 * @param array    $packed_packages Packed packages from Packing_Service.
	 * @param array    $ship_to         Carrier-compatible destination address.
	 * @param Settings $settings        Plugin settings.
	 * @return array Array of rate entries (label + cost), or empty on failure.
	 */
	protected function calculate_all_options( Plugin $plugin, array $packed_packages, array $ship_to, Settings $settings ): array {
		$carrier_services  = $plugin->get_carrier_services();
		$per_package_plans = array();

		foreach ( $packed_packages as $index => $packed ) {
			$plans = array();

			foreach ( $carrier_services as $carrier_service ) {
				$carrier_plans = $carrier_service->build_all_test_package_plans( $packed, $ship_to, $index + 1 );
				$plans         = array_merge( $plans, $carrier_plans );
			}

			if ( empty( $plans ) ) {
				return array();
			}

			$per_package_plans[] = $plans;
		}

		$combos        = $this->cartesian_product( $per_package_plans );
		$package_count = count( $packed_packages );
		$rates         = array();
		$seen_labels   = array();

		foreach ( $combos as $combo ) {
			$total          = 0.0;
			$names          = array();
			$service_labels = array();
			$delivery_dates = array();

			foreach ( $combo as $plan ) {
				$total           += (float) $plan['rate_amount'];
				$names[]          = $plan['package_name'];
				$service_labels[] = $plan['service_label'] ?? '';

				$date = (string) ( $plan['estimated_delivery_date'] ?? '' );
				if ( '' !== $date ) {
					$delivery_dates[] = $date;
				}
			}

			if ( $total <= 0 ) {
				continue;
			}

			// Consolidate repeated box names: "Small + Small + Large" → "2× Small + Large".
			$grouped = array_count_values( $names );
			$parts   = array();
			foreach ( $grouped as $name => $count ) {
				$parts[] = $count > 1
					? sprintf( '%d× %s', $count, $name )
					: $name;
			}

			// Use the carrier service label when available; fall back to the
			// method title for backward compatibility.
			$unique_labels = array_unique( array_filter( $service_labels ) );
			$title_prefix  = 1 === count( $unique_labels )
				? reset( $unique_labels )
				: $this->title;

			$label = $title_prefix . ' — ' . implode( ' + ', $parts );

			if ( $settings->is_show_package_count_enabled() && $package_count > 0 ) {
				$label = sprintf(
					/* translators: 1: combined label, 2: package count. */
					_n( '%1$s (%2$d package)', '%1$s (%2$d packages)', $package_count, 'fk-usps-optimizer' ),
					$label,
					$package_count
				);
			}

			$meta_data = array();
			if ( $settings->is_show_estimated_delivery_enabled() ) {
				if ( ! empty( $delivery_dates ) ) {
					sort( $delivery_dates );
					$formatted = $this->format_estimated_delivery( end( $delivery_dates ) );
					if ( '' !== $formatted ) {
						/* translators: %s: formatted estimated delivery date, e.g. "Mon, Jan 15". */
						$label .= ' — ' . sprintf( __( 'Est. delivery: %s', 'fk-usps-optimizer' ), $formatted );
						$meta_data[ __( 'Est. Delivery', 'fk-usps-optimizer' ) ] = $formatted;
					}
				} else {
					$no_estimate = __( '(No Estimate)', 'fk-usps-optimizer' );
					/* translators: %s: "(No Estimate)" placeholder. */
					$label .= ' — ' . sprintf( __( 'Est. delivery: %s', 'fk-usps-optimizer' ), $no_estimate );
					$meta_data[ __( 'Est. Delivery', 'fk-usps-optimizer' ) ] = $no_estimate;
				}
			}

			// Deduplicate equivalent combos (permutations of the same set of box types).
			if ( isset( $seen_labels[ $label ] ) ) {
				continue;
			}
			$seen_labels[ $label ] = true;

			$rates[] = array(
				'label'     => $label,
				'cost'      => $total,
				'meta_data' => $meta_data,
			);
		}

		// Sort cheapest-first.
		usort(
			$rates,
			static function ( array $a, array $b ): int {
				return (float) $a['cost'] <=> (float) $b['cost'];
			}
		);

		return $rates;
	}

	/**
	 * Compute the cartesian product of multiple arrays of plans.
	 *
	 * Given [[A1, A2], [B1, B2]] returns [[A1, B1], [A1, B2], [A2, B1], [A2, B2]].
	 *
	 * @param array $sets Array of arrays, one per package.
	 * @return array Array of combinations.
	 */
	protected function cartesian_product( array $sets ): array {
		$result = array( array() );

		foreach ( $sets as $set ) {
			$new_result = array();

			foreach ( $result as $combo ) {
				foreach ( $set as $item ) {
					$new_result[] = array_merge( $combo, array( $item ) );
				}
			}

			$result = $new_result;
		}

		return $result;
	}

	/**
	 * Extract shippable items from a WooCommerce shipping package.
	 *
	 * Converts cart contents into the flat item array expected by
	 * Packing_Service::pack_items().
	 *
	 * @param array $package WooCommerce shipping package.
	 * @return array Flat list of item arrays.
	 */
	protected function extract_items( array $package ): array {
		$items = array();

		foreach ( $package['contents'] ?? array() as $cart_item ) {
			$product = $cart_item['data'] ?? null;

			if ( ! $product instanceof \WC_Product || ! $product->needs_shipping() ) {
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
			$qty            = max( 1, (int) ( $cart_item['quantity'] ?? 1 ) );

			for ( $i = 0; $i < $qty; $i++ ) {
				$items[] = array(
					'product_id'     => $product->get_id(),
					'name'           => $product->get_name(),
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
	 * Build a ship-to address array from a WooCommerce destination.
	 *
	 * The returned format is compatible with both ShipEngine_Service and
	 * ShipStation_Service build_test_package_plan() methods.
	 *
	 * @param array $destination WooCommerce shipping destination.
	 * @return array Carrier-compatible ship-to address.
	 */
	protected function build_ship_to( array $destination ): array {
		return array(
			'name'                          => '',
			'company_name'                  => '',
			'phone'                         => '',
			'address_line1'                 => $destination['address'] ?? ( $destination['address_1'] ?? '' ),
			'address_line2'                 => $destination['address_2'] ?? '',
			'city_locality'                 => $destination['city'] ?? '',
			'state_province'                => $destination['state'] ?? '',
			'postal_code'                   => $destination['postcode'] ?? '',
			'country_code'                  => $destination['country'] ?? 'US',
			'address_residential_indicator' => 'unknown',
		);
	}

	/**
	 * Format a raw estimated-delivery date string for display.
	 *
	 * Accepts either an ISO 8601 datetime string (as returned by ShipEngine,
	 * e.g. "2024-01-15T00:00:00Z") or a plain YYYY-MM-DD date string (as
	 * computed from ShipStation transit days) and returns a short label such
	 * as "Mon, Jan 15".  Returns an empty string when the input is empty or
	 * cannot be parsed.
	 *
	 * @param string $iso_date ISO 8601 datetime or YYYY-MM-DD date string.
	 * @return string Formatted date string (e.g. "Mon, Jan 15"), or ''.
	 */
	protected function format_estimated_delivery( string $iso_date ): string {
		if ( '' === $iso_date ) {
			return '';
		}

		try {
			$date = new \DateTime( $iso_date );
			return $date->format( 'D, M j' );
		} catch ( \Throwable $e ) {
			return '';
		}
	}
}
