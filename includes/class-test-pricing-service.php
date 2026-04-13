<?php
/**
 * Test pricing service for the FK USPS Optimizer plugin.
 *
 * Packs arbitrary test items and fetches live USPS rates from the configured
 * carrier API (ShipEngine or ShipStation) without requiring a real WooCommerce
 * order.  Used exclusively by the admin test pricing UI.
 *
 * @package FK_USPS_Optimizer
 */

namespace FK_USPS_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Packs test items and rate-shops them against the active carrier API.
 */
class Test_Pricing_Service {

	/**
	 * Plugin settings instance.
	 *
	 * @var Settings
	 */
	protected $settings;

	/**
	 * Packing service instance.
	 *
	 * @var Packing_Service
	 */
	protected $packing_service;

	/**
	 * ShipEngine service instance.
	 *
	 * @var ShipEngine_Service
	 */
	protected $shipengine_service;

	/**
	 * ShipStation service instance.
	 *
	 * @var ShipStation_Service
	 */
	protected $shipstation_service;

	/**
	 * Constructor.
	 *
	 * @param Settings            $settings            Plugin settings.
	 * @param Packing_Service     $packing_service     Packing service.
	 * @param ShipEngine_Service  $shipengine_service  ShipEngine service.
	 * @param ShipStation_Service $shipstation_service ShipStation service.
	 */
	public function __construct(
		Settings $settings,
		Packing_Service $packing_service,
		ShipEngine_Service $shipengine_service,
		ShipStation_Service $shipstation_service
	) {
		$this->settings            = $settings;
		$this->packing_service     = $packing_service;
		$this->shipengine_service  = $shipengine_service;
		$this->shipstation_service = $shipstation_service;
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Run a full pack-and-rate cycle for the given test items and destination.
	 *
	 * @param array $raw_items Each entry: name, qty, length, width, height (inches), weight_oz.
	 * @param array $ship_to   Carrier-compatible destination address array.
	 * @return array {
	 *   packages:          array  Rated package plans.
	 *   total_rate_amount: float  Sum of all package rates.
	 *   currency:          string ISO currency code.
	 *   warnings:          array  Non-fatal notices.
	 *   carrier:           string Active carrier ('shipengine'|'shipstation').
	 *   sandbox:           bool   Whether sandbox mode was active.
	 * }
	 */
	public function run( array $raw_items, array $ship_to ): array {
		$result = array(
			'packages'          => array(),
			'total_rate_amount' => 0.0,
			'currency'          => 'USD',
			'warnings'          => array(),
			'carriers'          => $this->settings->get_carriers(),
			'sandbox'           => $this->settings->is_sandbox_mode_enabled(),
		);

		$items = $this->expand_items( $raw_items );

		if ( empty( $items ) ) {
			$result['warnings'][] = __( 'No valid items were provided for packing.', 'fk-usps-optimizer' );
			return $result;
		}

		$packed = $this->packing_service->pack_items( $items );

		if ( empty( $packed ) ) {
			$result['warnings'][] = __( 'No packages could be formed from the provided items.', 'fk-usps-optimizer' );
			return $result;
		}

		$carrier_services = $this->get_carrier_services();

		foreach ( $packed as $index => $package ) {
			$package_number = $index + 1;
			$best_plan      = array();
			$best_cost      = PHP_FLOAT_MAX;

			foreach ( $carrier_services as $carrier_svc ) {
				$candidate = $carrier_svc->build_test_package_plan( $package, $ship_to, $package_number );

				if ( ! empty( $candidate ) && (float) $candidate['rate_amount'] < $best_cost ) {
					$best_plan = $candidate;
					$best_cost = (float) $candidate['rate_amount'];
				}
			}

			if ( empty( $best_plan ) ) {
				$result['warnings'][] = sprintf(
					/* translators: %d: package number. */
					__( 'No rate found for package %d. Check carrier credentials and box configuration.', 'fk-usps-optimizer' ),
					$package_number
				);
				continue;
			}

			$result['packages'][]         = $best_plan;
			$result['total_rate_amount'] += (float) $best_plan['rate_amount'];
			$result['currency']           = $best_plan['currency'];
		}

		if ( ! empty( $packed ) && empty( $result['packages'] ) ) {
			$result['warnings'][] = __( 'All rate-shopping attempts failed. Verify carrier credentials and box setup.', 'fk-usps-optimizer' );
		}

		return $result;
	}

	/**
	 * Expand raw form items into a flat list of individual item arrays, one per
	 * unit (qty > 1 creates multiple entries).
	 *
	 * Rows where name, length, and weight_oz are all empty are silently skipped.
	 *
	 * @param array $raw_items Raw item rows from the admin form.
	 * @return array Expanded flat list of item arrays ready for Packing_Service::pack_items().
	 */
	public function expand_items( array $raw_items ): array {
		$items = array();

		foreach ( $raw_items as $index => $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$name      = sanitize_text_field( (string) ( $raw['name'] ?? '' ) );
			$length    = (string) ( $raw['length'] ?? '' );
			$weight_oz = (string) ( $raw['weight_oz'] ?? '' );

			// Skip rows where all identifying fields are blank.
			if ( '' === $name && '' === $length && '' === $weight_oz ) {
				continue;
			}

			if ( '' === $name ) {
				/* translators: %d: item row number (1-based). */
				$name = sprintf( __( 'Item %d', 'fk-usps-optimizer' ), $index + 1 );
			}

			$qty = max( 1, (int) ( $raw['qty'] ?? 1 ) );

			$item = array(
				'name'      => $name,
				'length'    => max( 0.1, (float) ( '' !== $length ? $length : 1 ) ),
				'width'     => max( 0.1, (float) ( '' !== ( $raw['width'] ?? '' ) ? $raw['width'] : 1 ) ),
				'height'    => max( 0.1, (float) ( '' !== ( $raw['height'] ?? '' ) ? $raw['height'] : 1 ) ),
				'weight_oz' => max( 0.1, (float) ( '' !== $weight_oz ? $weight_oz : 0.1 ) ),
			);

			for ( $i = 0; $i < $qty; $i++ ) {
				$items[] = $item;
			}
		}

		return $items;
	}

	// -------------------------------------------------------------------------
	// Internals
	// -------------------------------------------------------------------------

	/**
	 * Return all carrier services enabled in settings.
	 *
	 * When ShipStation is enabled and additional carrier+service pairs are
	 * configured beyond the primary pair, extra ShipStation_Service instances
	 * are created per additional pair.  The primary pair uses the injected
	 * ShipStation_Service instance (so mocks work in tests).
	 *
	 * @return array<ShipEngine_Service|ShipStation_Service> Active carrier services.
	 */
	protected function get_carrier_services(): array {
		$carriers = $this->settings->get_carriers();
		$services = array();

		foreach ( $carriers as $carrier ) {
			if ( 'shipengine' === $carrier ) {
				$services[] = $this->shipengine_service;
			} elseif ( 'shipstation' === $carrier ) {
				// Use the injected instance for the primary pair.
				$services[] = $this->shipstation_service;

				// Create extra instances for any additional pairs.
				$pairs   = $this->settings->get_shipstation_service_pairs();
				$primary = ! empty( $pairs ) ? $pairs[0] : array();

				foreach ( array_slice( $pairs, 1 ) as $pair ) {
					$services[] = new ShipStation_Service(
						$this->settings,
						$pair['carrier_code'],
						$pair['service_code']
					);
				}
			}
		}

		return ! empty( $services ) ? $services : array( $this->shipengine_service );
	}
}
