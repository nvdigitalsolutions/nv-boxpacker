<?php
/**
 * ShipEngine service for the FK USPS Optimizer plugin.
 *
 * @package FK_USPS_Optimizer
 */

namespace FK_USPS_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles ShipEngine API communication and rate building.
 */
class ShipEngine_Service {
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
	 * Build the best shipping package plan for a packed package.
	 *
	 * @param \WC_Order $order          The order.
	 * @param array     $package        Packed package data.
	 * @param int       $package_number Package sequence number.
	 * @return array Best shipping plan found.
	 */
	public function build_package_plan( \WC_Order $order, array $package, int $package_number ): array {
		$candidates = $this->build_candidates( $package );
		$best_plan  = array();

		foreach ( $candidates as $candidate ) {
			$response = $this->request_rate( $order, $candidate );

			if ( ! $response['success'] ) {
				continue;
			}

			$rate = $response['rate'];

			if ( empty( $best_plan ) || (float) $rate['shipping_amount']['amount'] < (float) $best_plan['rate_amount'] ) {
				$dimensions = $candidate['dimensions'];
				$best_plan  = array(
					'package_number' => $package_number,
					'mode'           => $candidate['mode'],
					'package_code'   => $candidate['package_code'],
					'package_name'   => $candidate['package_name'],
					'service_code'   => 'usps_priority_mail',
					'rate_amount'    => (float) $rate['shipping_amount']['amount'],
					'currency'       => (string) ( $rate['shipping_amount']['currency'] ?? 'USD' ),
					'weight_oz'      => (float) $candidate['weight_oz'],
					'dimensions'     => $dimensions,
					'cubic_tier'     => $candidate['cubic_tier'],
					'packing_list'   => $this->build_packing_list( $package['items'] ),
					'items'          => $package['items'],
				);
			}
		}

		return $best_plan;
	}

	/**
	 * Build candidate shipments from available boxes for a package.
	 *
	 * @param array $package Packed package data.
	 * @return array Candidate shipments.
	 */
	protected function build_candidates( array $package ): array {
		$candidates = array();

		foreach ( $this->settings->get_boxes() as $box ) {
			if ( ! $this->package_fits_box( $package, $box ) ) {
				continue;
			}

			$dimensions = array(
				'length' => (float) $box['outer_length'],
				'width'  => (float) $box['outer_width'],
				'height' => (float) $box['outer_depth'],
			);
			$weight_oz  = (float) $package['weight_oz'] + (float) $box['empty_weight'];

			if ( 'cubic' === $box['box_type'] ) {
				if ( ! $this->is_cubic_eligible( $dimensions, $weight_oz ) ) {
					continue;
				}
			}

			$candidates[] = array(
				'mode'         => 'cubic' === $box['box_type'] ? 'cubic' : 'flat_rate_box',
				'package_code' => $box['package_code'],
				'package_name' => $box['package_name'],
				'dimensions'   => $dimensions,
				'weight_oz'    => $weight_oz,
				'cubic_tier'   => 'cubic' === $box['box_type'] ? $this->get_cubic_tier( $dimensions ) : '',
				'box'          => $box,
			);
		}

		return $candidates;
	}

	/**
	 * Check whether the package fits in the given box.
	 *
	 * @param array $package Package data.
	 * @param array $box     Box definition.
	 * @return bool True if the package fits.
	 */
	protected function package_fits_box( array $package, array $box ): bool {
		$dimensions = $package['dimensions'];

		return (float) $dimensions['length'] <= (float) $box['inner_length'] &&
			(float) $dimensions['width'] <= (float) $box['inner_width'] &&
			(float) $dimensions['height'] <= (float) $box['inner_depth'] &&
			(float) $package['weight_oz'] <= ( (float) $box['max_weight'] * 16 );
	}

	/**
	 * Request a shipping rate from ShipEngine for a candidate shipment.
	 *
	 * @param \WC_Order $order     The order.
	 * @param array     $candidate Candidate shipment.
	 * @return array Result with 'success' key and optionally 'rate'.
	 */
	protected function request_rate( \WC_Order $order, array $candidate ): array {
		$api_key    = $this->settings->get_shipengine_api_key();
		$carrier_id = $this->settings->get_shipengine_carrier_id();

		if ( '' === $api_key || '' === $carrier_id ) {
			$this->log( 'Missing ShipEngine credentials.', array( 'order_id' => $order->get_id() ) );
			return array( 'success' => false );
		}

		$payload = array(
			'rate_options' => array(
				'carrier_ids' => array( $carrier_id ),
			),
			'shipment'     => array(
				'validate_address' => 'no_validation',
				'ship_to'          => $this->get_ship_to_address( $order ),
				'ship_from'        => $this->settings->get_ship_from_address(),
				'packages'         => array(
					array(
						'package_code' => $candidate['package_code'],
						'weight'       => array(
							'value' => round( $candidate['weight_oz'], 2 ),
							'unit'  => 'ounce',
						),
						'dimensions'   => array(
							'unit'   => 'inch',
							'length' => $candidate['dimensions']['length'],
							'width'  => $candidate['dimensions']['width'],
							'height' => $candidate['dimensions']['height'],
						),
					),
				),
				'service_code'     => 'usps_priority_mail',
			),
		);

		$response = wp_remote_post(
			'https://api.shipengine.com/v1/rates',
			array(
				'timeout' => 15,
				'headers' => array(
					'API-Key'      => $api_key,
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log(
				'ShipEngine request failed.',
				array(
					'order_id' => $order->get_id(),
					'error'    => $response->get_error_message(),
				)
			);

			return array( 'success' => false );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$this->log(
				'ShipEngine returned a non-success response.',
				array(
					'order_id' => $order->get_id(),
					'status'   => $code,
					'body'     => $body,
				)
			);

			return array( 'success' => false );
		}

		$rates = $body['rate_response']['rates'] ?? array();

		if ( empty( $rates ) ) {
			$this->log(
				'ShipEngine returned no rates.',
				array(
					'order_id' => $order->get_id(),
					'body'     => $body,
				)
			);

			return array( 'success' => false );
		}

		usort(
			$rates,
			static function ( array $left, array $right ): int {
				return (float) $left['shipping_amount']['amount'] <=> (float) $right['shipping_amount']['amount'];
			}
		);

		return array(
			'success' => true,
			'rate'    => $rates[0],
		);
	}

	/**
	 * Build a ShipEngine-formatted ship-to address from the order.
	 *
	 * @param \WC_Order $order The order.
	 * @return array ShipEngine-formatted ship-to address.
	 */
	protected function get_ship_to_address( \WC_Order $order ): array {
		return array(
			'name'                          => trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ),
			'company_name'                  => $order->get_shipping_company(),
			'phone'                         => $order->get_billing_phone(),
			'address_line1'                 => $order->get_shipping_address_1(),
			'address_line2'                 => $order->get_shipping_address_2(),
			'city_locality'                 => $order->get_shipping_city(),
			'state_province'                => $order->get_shipping_state(),
			'postal_code'                   => $order->get_shipping_postcode(),
			'country_code'                  => $order->get_shipping_country() ? $order->get_shipping_country() : 'US',
			'address_residential_indicator' => 'unknown',
		);
	}

	/**
	 * Build a human-readable packing list from packed items.
	 *
	 * @param array $items Packed items.
	 * @return array Human-readable packing list lines.
	 */
	protected function build_packing_list( array $items ): array {
		$list = array();

		foreach ( $items as $item ) {
			$key = $item['name'];

			if ( ! isset( $list[ $key ] ) ) {
				$list[ $key ] = 0;
			}

			++$list[ $key ];
		}

		$output = array();

		foreach ( $list as $name => $qty ) {
			$output[] = sprintf( '%dx %s', $qty, $name );
		}

		return $output;
	}

	/**
	 * Check whether a package is eligible for USPS cubic pricing.
	 *
	 * @param array $dimensions  Package dimensions.
	 * @param float $weight_oz   Package weight in ounces.
	 * @return bool Whether eligible for cubic pricing.
	 */
	protected function is_cubic_eligible( array $dimensions, float $weight_oz ): bool {
		$sides = array_values( $dimensions );
		rsort( $sides );
		$cubic_feet = ( $dimensions['length'] * $dimensions['width'] * $dimensions['height'] ) / 1728;

		return $cubic_feet <= 0.5 && $weight_oz <= 320 && $sides[0] <= 18;
	}

	/**
	 * Determine the USPS cubic tier for the given dimensions.
	 *
	 * @param array $dimensions Package dimensions.
	 * @return string Cubic tier value.
	 */
	protected function get_cubic_tier( array $dimensions ): string {
		$cubic_feet = ( $dimensions['length'] * $dimensions['width'] * $dimensions['height'] ) / 1728;

		if ( $cubic_feet <= 0.1 ) {
			return '0.1';
		}

		if ( $cubic_feet <= 0.2 ) {
			return '0.2';
		}

		if ( $cubic_feet <= 0.3 ) {
			return '0.3';
		}

		if ( $cubic_feet <= 0.4 ) {
			return '0.4';
		}

		return '0.5';
	}

	/**
	 * Log a debug message via the WooCommerce logger.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context.
	 * @return void
	 */
	public function log( string $message, array $context = array() ): void {
		if ( ! $this->settings->is_debug_logging_enabled() ) {
			return;
		}

		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->debug( $message . ' ' . wp_json_encode( $context ), array( 'source' => 'fk-usps-optimizer' ) );
		}
	}
}
