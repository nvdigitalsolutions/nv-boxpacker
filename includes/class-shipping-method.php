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
		// Cheap short-circuits first — these run before any plugin bootstrap
		// or rate work so partial-checkout keystrokes don't churn the API.
		if ( $this->should_skip_rate_calculation( $package ) ) {
			return;
		}

		$items = $this->extract_items( $package );

		if ( empty( $items ) ) {
			return;
		}

		$plugin   = Plugin::bootstrap();
		$settings = $plugin->get_settings();

		// Optional debug timer — only the boolean check runs when debug is off.
		$timing_enabled = $settings->is_debug_logging_enabled();
		$started_at     = $timing_enabled ? microtime( true ) : 0.0;

		$packed_packages = $plugin->get_packing_service()->pack_items( $items );

		if ( empty( $packed_packages ) ) {
			return;
		}

		$ship_to = $this->build_ship_to( $package['destination'] ?? array() );

		if ( $settings->is_show_all_options_enabled() ) {
			$rates = $this->calculate_all_options( $plugin, $packed_packages, $ship_to, $settings );
		} else {
			$rates = $this->calculate_cheapest_option( $plugin, $packed_packages, $ship_to, $settings );
		}

		if ( $timing_enabled ) {
			$this->log_calculate_shipping_timing( $started_at, $rates, $packed_packages, $ship_to );
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
	 * Decide whether to short-circuit a `calculate_shipping()` call.
	 *
	 * Returns true when one of the following is true:
	 *  1. The caller has explicitly opted out via the
	 *     `fk_usps_optimizer_skip_rates` filter (boolean, receives the WC
	 *     package as context).  Useful as a feature flag or debug toggle.
	 *  2. The destination country is empty.
	 *  3. The destination postcode is shorter than the country's minimum
	 *     length (US/PR=5, CA=3, others=3 by default).  The minimum is
	 *     filterable via `fk_usps_optimizer_min_postcode_length`, which
	 *     receives the default int and the uppercased country code.
	 *
	 * Pulled out of `calculate_shipping()` so the cheap pre-checks can be
	 * unit-tested without bootstrapping the plugin.
	 *
	 * @param array $package WooCommerce shipping package.
	 * @return bool True to skip, false to proceed with rate calculation.
	 */
	protected function should_skip_rate_calculation( array $package ): bool {
		// Extension hook: third-party can hard-skip the rate work entirely.
		if ( (bool) apply_filters( 'fk_usps_optimizer_skip_rates', false, $package ) ) {
			return true;
		}

		$destination = $package['destination'] ?? array();
		$country     = strtoupper( (string) ( $destination['country'] ?? '' ) );
		$postcode    = trim( (string) ( $destination['postcode'] ?? '' ) );

		if ( '' === $country ) {
			return true;
		}

		// Per-country defaults — postcodes shorter than this are almost
		// certainly partial input from a checkout field still being typed,
		// so requesting rates would just burn API quota.
		$defaults   = array(
			'US' => 5,
			'PR' => 5,
			'CA' => 3,
		);
		$min_length = (int) apply_filters(
			'fk_usps_optimizer_min_postcode_length',
			$defaults[ $country ] ?? 3,
			$country
		);

		if ( $min_length > 0 && strlen( $postcode ) < $min_length ) {
			return true;
		}

		return false;
	}

	/**
	 * Emit a debug timing record for one `calculate_shipping()` invocation.
	 *
	 * Only invoked when `Settings::is_debug_logging_enabled()` is true; the
	 * caller guards on that flag so we don't pay even the array-construction
	 * cost on a cold checkout.
	 *
	 * @param float $started_at      Wall-clock time captured before rate work.
	 * @param array $rates           Final rates returned from the calc helpers.
	 * @param array $packed_packages Packed packages that were rated.
	 * @param array $ship_to         Carrier-formatted destination address.
	 * @return void
	 */
	protected function log_calculate_shipping_timing( float $started_at, array $rates, array $packed_packages, array $ship_to ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$elapsed_ms = (int) round( ( microtime( true ) - $started_at ) * 1000 );
		$context    = array(
			'elapsed_ms'    => $elapsed_ms,
			'rate_count'    => count( $rates ),
			'package_count' => count( $packed_packages ),
			'postal_code'   => (string) ( $ship_to['postal_code'] ?? '' ),
			'country_code'  => (string) ( $ship_to['country_code'] ?? '' ),
		);

		wc_get_logger()->debug(
			'calculate_shipping completed ' . wp_json_encode( $context ),
			array( 'source' => 'fk-usps-optimizer' )
		);
	}

	/**
	 * Calculate the cheapest combined shipping option per carrier service.
	 *
	 * For each carrier service, picks the cheapest rated candidate for every
	 * packed package and sums their rates into one shipping option.  When
	 * multiple services are configured, each service is offered as a
	 * separate shipping option so the customer can compare, e.g.
	 * "USPS Priority $7.25" vs "UPS Ground $8.50".
	 *
	 * @param Plugin   $plugin          Plugin instance.
	 * @param array    $packed_packages Packed packages from Packing_Service.
	 * @param array    $ship_to         Carrier-compatible destination address.
	 * @param Settings $settings        Plugin settings.
	 * @return array Array of rate entries, or empty on failure.
	 */
	protected function calculate_cheapest_option( Plugin $plugin, array $packed_packages, array $ship_to, Settings $settings ): array {
		$carrier_services = $plugin->get_carrier_services();
		$package_count    = count( $packed_packages );
		$rates            = array();

		foreach ( $carrier_services as $carrier_service ) {
			// Collect ALL plans for every package so we can group by service_code.
			// When a specific service_code is configured each plan will share the
			// same code and the downstream grouping produces a single rate (same
			// behaviour as before).  When service_code is empty the API returns
			// rates for every available service and we create one rate per service.
			$per_package_service_best = array(); // service_code => package_index => cheapest plan.
			$all_rated                = true;

			foreach ( $packed_packages as $index => $packed ) {
				$plans = $carrier_service->build_all_test_package_plans( $packed, $ship_to, $index + 1 );

				if ( empty( $plans ) ) {
					$all_rated = false;
					break;
				}

				// Keep the cheapest plan per service_code for this package.
				foreach ( $plans as $plan ) {
					$sc = $plan['service_code'] ?? '';
					if ( ! isset( $per_package_service_best[ $sc ][ $index ] )
						|| (float) $plan['rate_amount'] < (float) $per_package_service_best[ $sc ][ $index ]['rate_amount']
					) {
						$per_package_service_best[ $sc ][ $index ] = $plan;
					}
				}
			}

			if ( ! $all_rated ) {
				continue;
			}

			// Build one rate per service_code that has plans for ALL packages.
			foreach ( $per_package_service_best as $sc => $plans_by_index ) {
				if ( count( $plans_by_index ) !== $package_count ) {
					continue;
				}

				$total_cost     = 0.0;
				$service_labels = array();
				$delivery_dates = array();

				foreach ( $plans_by_index as $plan ) {
					$total_cost      += (float) $plan['rate_amount'];
					$service_labels[] = $plan['service_label'] ?? '';

					$date = (string) ( $plan['estimated_delivery_date'] ?? '' );
					if ( '' !== $date ) {
						$delivery_dates[] = $date;
					}
				}

				if ( $total_cost <= 0 ) {
					continue;
				}

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
							$meta_data[ __( 'Est. Delivery', 'fk-usps-optimizer' ) ] = $formatted;
							/* translators: %s: formatted estimated delivery date, e.g. "Mon, Jan 15". */
							$meta_data['est_delivery_display'] = sprintf( __( 'Est. delivery: %s', 'fk-usps-optimizer' ), $formatted );
						}
					} else {
						$no_estimate = __( '(No Estimate)', 'fk-usps-optimizer' );
						$meta_data[ __( 'Est. Delivery', 'fk-usps-optimizer' ) ] = $no_estimate;
						/* translators: %s: "(No Estimate)" placeholder. */
						$meta_data['est_delivery_display'] = sprintf( __( 'Est. delivery: %s', 'fk-usps-optimizer' ), $no_estimate );
					}
				}

				$rates[] = array(
					'label'     => $label,
					'cost'      => $total_cost,
					'meta_data' => $meta_data,
				);
			}
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
	 * Calculate all shipping option combinations per carrier service.
	 *
	 * For each carrier service, all rated candidates are collected per
	 * packed package.  The cartesian product of every package's candidates
	 * within that service produces all possible shipping plans; each plan
	 * is offered as a separate WooCommerce rate.  Services are not mixed
	 * across packages so each option represents a single carrier/service.
	 *
	 * @param Plugin   $plugin          Plugin instance.
	 * @param array    $packed_packages Packed packages from Packing_Service.
	 * @param array    $ship_to         Carrier-compatible destination address.
	 * @param Settings $settings        Plugin settings.
	 * @return array Array of rate entries (label + cost), or empty on failure.
	 */
	protected function calculate_all_options( Plugin $plugin, array $packed_packages, array $ship_to, Settings $settings ): array {
		$carrier_services = $plugin->get_carrier_services();
		$package_count    = count( $packed_packages );
		$rates            = array();
		$seen_labels      = array();

		foreach ( $carrier_services as $carrier_service ) {
			// Collect all plans per package, then group by service_code so
			// that the cartesian product only combines plans that share the
			// same service (avoids mixing e.g. UPS Ground with UPS Next Day).
			$per_package_plans = array();

			foreach ( $packed_packages as $index => $packed ) {
				$plans = $carrier_service->build_all_test_package_plans( $packed, $ship_to, $index + 1 );

				if ( empty( $plans ) ) {
					// This service cannot rate all packages; skip it entirely.
					continue 2;
				}

				$per_package_plans[] = $plans;
			}

			// Determine all unique service_codes across every package.
			$all_service_codes = array();
			foreach ( $per_package_plans as $plans ) {
				foreach ( $plans as $plan ) {
					$sc                       = $plan['service_code'] ?? '';
					$all_service_codes[ $sc ] = true;
				}
			}

			// For each service_code, filter plans per package and build combos.
			foreach ( array_keys( $all_service_codes ) as $sc ) {
				$filtered_per_package = array();
				$skip_service         = false;

				foreach ( $per_package_plans as $plans ) {
					$filtered = array_values(
						array_filter(
							$plans,
							static function ( array $p ) use ( $sc ): bool {
								return ( $p['service_code'] ?? '' ) === $sc;
							}
						)
					);

					if ( empty( $filtered ) ) {
						$skip_service = true;
						break;
					}

					$filtered_per_package[] = $filtered;
				}

				if ( $skip_service ) {
					continue;
				}

				$combos = $this->cartesian_product( $filtered_per_package );

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
								$meta_data[ __( 'Est. Delivery', 'fk-usps-optimizer' ) ] = $formatted;
								/* translators: %s: formatted estimated delivery date, e.g. "Mon, Jan 15". */
								$meta_data['est_delivery_display'] = sprintf( __( 'Est. delivery: %s', 'fk-usps-optimizer' ), $formatted );
							}
						} else {
							$no_estimate = __( '(No Estimate)', 'fk-usps-optimizer' );
							$meta_data[ __( 'Est. Delivery', 'fk-usps-optimizer' ) ] = $no_estimate;
							/* translators: %s: "(No Estimate)" placeholder. */
							$meta_data['est_delivery_display'] = sprintf( __( 'Est. delivery: %s', 'fk-usps-optimizer' ), $no_estimate );
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
			}
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
