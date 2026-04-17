<?php
/**
 * ShipStation service for the FK USPS Optimizer plugin.
 *
 * Provides USPS Priority Mail rate-shopping via the ShipStation REST API.
 * Supports sandbox mode: when enabled all requests are logged with a [SANDBOX]
 * prefix so they can be distinguished from live production calls.
 *
 * Authentication uses HTTP Basic Auth (API Key : API Secret), which differs
 * from the ShipEngine header-based scheme used by ShipEngine_Service.
 *
 * @package FK_USPS_Optimizer
 */

namespace FK_USPS_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles ShipStation API communication and USPS rate building.
 */
class ShipStation_Service {

	/**
	 * ShipStation production API base URL.
	 */
	const API_BASE_URL = 'https://ssapi.shipstation.com';

	/**
	 * Plugin settings instance.
	 *
	 * @var Settings
	 */
	protected $settings;

	/**
	 * Optional carrier code override.
	 *
	 * When non-null, this value is used instead of the Settings getter.
	 * An explicit empty string means "all carrier services".
	 *
	 * @var string|null
	 */
	protected $carrier_code_override;

	/**
	 * Optional service code override.
	 *
	 * When non-null, this value is used instead of the Settings getter.
	 * An explicit empty string means "all services for the carrier".
	 *
	 * @var string|null
	 */
	protected $service_code_override;

	/**
	 * Constructor.
	 *
	 * @param Settings    $settings     Plugin settings instance.
	 * @param string|null $carrier_code Optional carrier code override (e.g. 'stamps_com', 'ups_walleted').
	 *                                  Pass null (default) to fall back to Settings; pass '' for all carriers.
	 * @param string|null $service_code Optional service code override (e.g. 'usps_priority_mail', 'ups_ground').
	 *                                  Pass null (default) to fall back to Settings; pass '' for all services.
	 */
	public function __construct( Settings $settings, ?string $carrier_code = null, ?string $service_code = null ) {
		$this->settings              = $settings;
		$this->carrier_code_override = $carrier_code;
		$this->service_code_override = $service_code;
	}

	/**
	 * Get the effective carrier code, preferring the override.
	 *
	 * @return string Carrier code.
	 */
	public function get_carrier_code(): string {
		return null !== $this->carrier_code_override
			? $this->carrier_code_override
			: $this->settings->get_shipstation_carrier_code();
	}

	/**
	 * Get the effective service code, preferring the override.
	 *
	 * @return string Service code.
	 */
	public function get_service_code(): string {
		return null !== $this->service_code_override
			? $this->service_code_override
			: $this->settings->get_shipstation_service_code();
	}

	/**
	 * Map the ShipStation carrier code to a box-restriction keyword.
	 *
	 * The returned keyword matches the values stored in each box definition's
	 * `carrier_restriction` field (e.g. 'usps', 'ups', 'fedex') so that
	 * `Settings::get_boxes_for_carrier()` can filter out boxes that belong
	 * to a different carrier.
	 *
	 * @return string Carrier keyword (e.g. 'usps', 'ups', 'fedex'), or ''
	 *                when the carrier code is unknown.
	 */
	public function get_carrier_keyword(): string {
		$carrier_code = $this->get_carrier_code();

		$map = array(
			'stamps_com'   => 'usps',
			'usps'         => 'usps',
			'endicia'      => 'usps',
			'ups_walleted' => 'ups',
			'ups'          => 'ups',
			'fedex'        => 'fedex',
			'dhl_express'  => 'dhl',
		);

		return $map[ $carrier_code ] ?? '';
	}

	/**
	 * Derive a human-readable service label from the carrier and service codes.
	 *
	 * Maps well-known carrier codes (e.g. 'stamps_com', 'ups_walleted') and
	 * service codes (e.g. 'usps_priority_mail', 'ups_ground') to friendly
	 * names like "USPS Priority" or "UPS Ground".
	 *
	 * When $override_service_code is provided (e.g. the serviceCode returned
	 * by the ShipStation API), it is used instead of the instance's
	 * configured service code.  This ensures the label matches the actual
	 * service that was rated, not the one that was requested.
	 *
	 * @param string $override_service_code Optional service code from the API response.
	 * @return string Human-readable label such as "USPS Priority" or "UPS Ground".
	 */
	public function get_service_label( string $override_service_code = '' ): string {
		$carrier_code = $this->get_carrier_code();
		$service_code = '' !== $override_service_code ? $override_service_code : $this->get_service_code();

		$carrier_names = array(
			'stamps_com'   => 'USPS',
			'usps'         => 'USPS',
			'endicia'      => 'USPS',
			'ups_walleted' => 'UPS',
			'ups'          => 'UPS',
			'fedex'        => 'FedEx',
			'dhl_express'  => 'DHL Express',
		);

		$service_names = array(
			'usps_priority_mail'         => 'Priority',
			'usps_priority_mail_express' => 'Priority Express',
			'usps_first_class_mail'      => 'First Class',
			'usps_ground_advantage'      => 'Ground Advantage',
			'usps_parcel_select'         => 'Parcel Select',
			'usps_media_mail'            => 'Media Mail',
			'ups_ground'                 => 'Ground',
			'ups_next_day_air'           => 'Next Day Air',
			'ups_next_day_air_saver'     => 'Next Day Air Saver',
			'ups_2nd_day_air'            => '2nd Day Air',
			'ups_3_day_select'           => '3 Day Select',
			'ups_ground_saver'           => 'Ground Saver',
			'fedex_ground'               => 'Ground',
			'fedex_home_delivery'        => 'Home Delivery',
			'fedex_2day'                 => '2Day',
			'fedex_express_saver'        => 'Express Saver',
		);

		$carrier_name = $carrier_names[ $carrier_code ]
			?? ucwords( str_replace( '_', ' ', $carrier_code ) );

		if ( isset( $service_names[ $service_code ] ) ) {
			$service_name = $service_names[ $service_code ];
		} else {
			// Strip carrier-code prefix from unknown service codes to avoid
			// redundant labels like "FedEx Fedex Ground".
			$stripped = $service_code;
			foreach ( array_keys( $carrier_names ) as $prefix ) {
				if ( 0 === strpos( $service_code, $prefix . '_' ) ) {
					$stripped = substr( $service_code, strlen( $prefix ) + 1 );
					break;
				}
			}
			// Also strip common short prefixes (e.g. "fedex_" from "fedex_ground").
			if ( $stripped === $service_code && '' !== $carrier_code ) {
				$short_prefix = $carrier_code . '_';
				if ( 0 === strpos( $service_code, $short_prefix ) ) {
					$stripped = substr( $service_code, strlen( $short_prefix ) );
				}
			}
			$service_name = ucwords( str_replace( '_', ' ', $stripped ) );
		}

		return $carrier_name . ' ' . $service_name;
	}

	// -------------------------------------------------------------------------
	// Public entry points
	// -------------------------------------------------------------------------

	/**
	 * Build the best USPS Priority shipping plan for a packed package.
	 *
	 * @param \WC_Order $order          The WooCommerce order.
	 * @param array     $package        Packed package data from Packing_Service.
	 * @param int       $package_number 1-based package sequence number.
	 * @return array Best shipping plan or empty array if no rate was found.
	 */
	public function build_package_plan( \WC_Order $order, array $package, int $package_number ): array {
		return $this->build_package_plan_for_address(
			$package,
			$this->get_ship_to_address( $order ),
			$package_number,
			$order->get_id()
		);
	}

	/**
	 * Build the best USPS Priority shipping plan using an explicit ship-to address.
	 *
	 * Suitable for the admin test pricing suite where no real order exists.
	 *
	 * @param array $package        Packed package data.
	 * @param array $ship_to        ShipStation-compatible destination address.
	 * @param int   $package_number 1-based package sequence number.
	 * @return array Best shipping plan or empty array.
	 */
	public function build_test_package_plan( array $package, array $ship_to, int $package_number ): array {
		return $this->build_package_plan_for_address( $package, $ship_to, $package_number );
	}

	/**
	 * Build ALL rated shipping plans for a packed package, sorted cheapest-first.
	 *
	 * Unlike build_test_package_plan() which returns only the single cheapest
	 * plan, this method returns every successfully rated candidate so the caller
	 * can present all options to the customer.
	 *
	 * @param array $package        Packed package data.
	 * @param array $ship_to        ShipStation-compatible destination address.
	 * @param int   $package_number 1-based package sequence number.
	 * @return array[] Array of shipping plans sorted by rate_amount ascending.
	 */
	public function build_all_test_package_plans( array $package, array $ship_to, int $package_number ): array {
		return $this->build_all_plans_for_address( $package, $ship_to, $package_number );
	}

	/**
	 * Test the ShipStation API connection by fetching the list of carriers.
	 *
	 * When $api_key or $api_secret are provided (e.g. passed directly from the
	 * settings form before saving), those values are used.  Empty strings cause
	 * the method to fall back to the values stored in settings.
	 *
	 * @param string $api_key    Optional API key override.
	 * @param string $api_secret Optional API secret override.
	 * @return array {
	 *   success: bool   Whether the connection test passed.
	 *   message: string Human-readable result message.
	 * }
	 */
	public function test_connection( string $api_key = '', string $api_secret = '' ): array {
		if ( '' === $api_key ) {
			$api_key = $this->settings->get_shipstation_api_key();
		}
		if ( '' === $api_secret ) {
			$api_secret = $this->settings->get_shipstation_api_secret();
		}

		if ( '' === $api_key || '' === $api_secret ) {
			return array(
				'success' => false,
				'message' => __( 'ShipStation API key and secret are not configured.', 'fk-usps-optimizer' ),
			);
		}

		$auth     = base64_encode( $api_key . ':' . $api_secret ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Standard Basic-Auth encoding, not obfuscation.
		$api_url  = (string) apply_filters( 'fk_usps_optimizer_shipstation_api_url', self::API_BASE_URL );
		$endpoint = trailingslashit( $api_url ) . 'carriers';

		$response = wp_remote_get(
			$endpoint,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Basic ' . $auth,
					'Content-Type'  => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: error message. */
					__( 'Connection failed: %s', 'fk-usps-optimizer' ),
					$response->get_error_message()
				),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 401 === $code || 403 === $code ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid ShipStation API credentials. Please check your API key and secret.', 'fk-usps-optimizer' ),
			);
		}

		if ( $code < 200 || $code >= 300 ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %d: HTTP status code. */
					__( 'ShipStation returned an unexpected response (HTTP %d).', 'fk-usps-optimizer' ),
					$code
				),
			);
		}

		// Validate the configured carrier code against the account's carriers.
		$carrier_code = $this->get_carrier_code();
		$body         = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$carriers     = is_array( $body ) ? $body : array();

		if ( '' !== $carrier_code && ! empty( $carriers ) ) {
			$found = false;
			foreach ( $carriers as $carrier ) {
				if ( isset( $carrier['code'] ) && $carrier['code'] === $carrier_code ) {
					$found = true;
					break;
				}
			}

			if ( ! $found ) {
				$valid_codes = array_filter(
					array_map(
						static function ( $carrier ) {
							return is_array( $carrier ) && isset( $carrier['code'] ) ? $carrier['code'] : null;
						},
						$carriers
					)
				);
				return array(
					'success' => false,
					'message' => sprintf(
						/* translators: 1: configured carrier code, 2: list of valid carrier codes. */
						__( 'Carrier code "%1$s" was not found in your ShipStation account. Available carrier codes: %2$s', 'fk-usps-optimizer' ),
						$carrier_code,
						implode( ', ', $valid_codes )
					),
				);
			}
		}

		return array(
			'success' => true,
			'message' => __( 'Connection successful! ShipStation credentials are valid.', 'fk-usps-optimizer' ),
		);
	}

	// -------------------------------------------------------------------------
	// Core plan building
	// -------------------------------------------------------------------------

	/**
	 * Core rate-shopping logic shared by build_package_plan and build_test_package_plan.
	 *
	 * @param array $package        Packed package data.
	 * @param array $ship_to        ShipStation-compatible destination address.
	 * @param int   $package_number 1-based package sequence number.
	 * @param int   $order_id       Order ID for log context; 0 for test runs.
	 * @return array Best shipping plan or empty array.
	 */
	protected function build_package_plan_for_address( array $package, array $ship_to, int $package_number, int $order_id = 0 ): array {
		$candidates   = $this->build_candidates( $package );
		$service_code = $this->get_service_code();
		$best_plan    = array();

		foreach ( $candidates as $candidate ) {
			$response = $this->request_rate( $ship_to, $candidate, $order_id );

			if ( ! $response['success'] ) {
				continue;
			}

			$plan = $this->build_plan_from_rate( $response['rate'], $candidate, $package, $package_number, $service_code );

			if ( empty( $best_plan ) || $plan['rate_amount'] < (float) $best_plan['rate_amount'] ) {
				$best_plan = $plan;
			}
		}

		return $best_plan;
	}

	/**
	 * Build ALL rated plans for a packed package, sorted cheapest-first.
	 *
	 * @param array $package        Packed package data.
	 * @param array $ship_to        ShipStation-compatible destination address.
	 * @param int   $package_number 1-based package sequence number.
	 * @param int   $order_id       Order ID for log context; 0 for test runs.
	 * @return array[] All successful plans sorted by rate_amount ascending.
	 */
	protected function build_all_plans_for_address( array $package, array $ship_to, int $package_number, int $order_id = 0 ): array {
		$candidates   = $this->build_candidates( $package );
		$service_code = $this->get_service_code();
		$plans        = array();

		foreach ( $candidates as $candidate ) {
			// When service_code is empty the API returns rates for every
			// available service; expand each rate into its own plan.
			if ( '' === $service_code ) {
				$response = $this->request_all_rates( $ship_to, $candidate, $order_id );

				if ( ! $response['success'] ) {
					continue;
				}

				foreach ( $response['rates'] as $rate ) {
					$plans[] = $this->build_plan_from_rate( $rate, $candidate, $package, $package_number, '' );
				}
			} else {
				$response = $this->request_rate( $ship_to, $candidate, $order_id );

				if ( ! $response['success'] ) {
					continue;
				}

				$plans[] = $this->build_plan_from_rate( $response['rate'], $candidate, $package, $package_number, $service_code );
			}
		}

		usort(
			$plans,
			static function ( array $a, array $b ): int {
				return (float) $a['rate_amount'] <=> (float) $b['rate_amount'];
			}
		);

		return $plans;
	}

	/**
	 * Build a shipping plan array from a single ShipStation rate entry.
	 *
	 * Shared helper used by build_package_plan_for_address and
	 * build_all_plans_for_address to avoid duplicating plan construction.
	 *
	 * @param array  $rate                 Single rate entry from the ShipStation API response.
	 * @param array  $candidate            Candidate shipment (mode, package_code, etc.).
	 * @param array  $package              Packed package data (items, weight_oz, etc.).
	 * @param int    $package_number       1-based package sequence number.
	 * @param string $fallback_service_code Service code to use when the rate does not include one.
	 * @return array Shipping plan data.
	 */
	protected function build_plan_from_rate( array $rate, array $candidate, array $package, int $package_number, string $fallback_service_code ): array {
		$total_cost        = (float) $rate['shipmentCost'] + (float) ( $rate['otherCost'] ?? 0 );
		$rate_service_code = (string) ( $rate['serviceCode'] ?? $fallback_service_code );

		return array(
			'package_number'          => $package_number,
			'mode'                    => $candidate['mode'],
			'package_code'            => $candidate['package_code'],
			'package_name'            => $candidate['package_name'],
			'service_code'            => $rate_service_code,
			'service_label'           => $this->get_service_label( $rate_service_code ),
			'rate_amount'             => $total_cost,
			'currency'                => 'USD',
			'weight_oz'               => (float) $candidate['weight_oz'],
			'dimensions'              => $candidate['dimensions'],
			'cubic_tier'              => $candidate['cubic_tier'],
			'packing_list'            => $this->build_packing_list( $package['items'] ),
			'items'                   => $package['items'],
			'estimated_delivery_date' => $this->extract_delivery_date( $rate ),
		);
	}

	// -------------------------------------------------------------------------
	// Candidate building (shared logic mirrored from ShipEngine_Service)
	// -------------------------------------------------------------------------

	/**
	 * Build candidate shipments from boxes that fit the packed package.
	 *
	 * @param array $package Packed package data.
	 * @return array Candidate shipment arrays.
	 */
	protected function build_candidates( array $package ): array {
		$candidates      = array();
		$carrier_keyword = $this->get_carrier_keyword();
		$is_usps         = 'usps' === $carrier_keyword;

		foreach ( $this->settings->get_boxes_for_carrier( $carrier_keyword ) as $box ) {
			if ( ! $this->package_fits_box( $package, $box ) ) {
				continue;
			}

			$dimensions = array(
				'length' => (float) $box['outer_length'],
				'width'  => (float) $box['outer_width'],
				'height' => (float) $box['outer_depth'],
			);
			$weight_oz  = (float) $package['weight_oz'] + (float) $box['empty_weight'];

			// USPS cubic eligibility rules (≤0.5 ft³, ≤320 oz, longest side ≤18″)
			// only apply to USPS carriers.  Non-USPS carriers (UPS, FedEx, etc.)
			// treat cubic-type boxes as regular packages.
			$use_cubic = 'cubic' === $box['box_type'] && $is_usps;

			if ( $use_cubic && ! $this->is_cubic_eligible( $dimensions, $weight_oz ) ) {
				continue;
			}

			if ( $use_cubic ) {
				$mode = 'cubic';
			} elseif ( 'flat_rate' === $box['box_type'] ) {
				$mode = 'flat_rate_box';
			} else {
				$mode = 'package';
			}

			$candidates[] = array(
				'mode'         => $mode,
				'package_code' => $box['package_code'],
				'package_name' => $box['package_name'],
				'dimensions'   => $dimensions,
				'weight_oz'    => $weight_oz,
				'cubic_tier'   => $use_cubic ? $this->get_cubic_tier( $dimensions ) : '',
				'box'          => $box,
			);
		}

		return $candidates;
	}

	/**
	 * Check whether a packed package fits inside a box.
	 *
	 * @param array $package Package data including dimensions and weight.
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

	// -------------------------------------------------------------------------
	// ShipStation API request
	// -------------------------------------------------------------------------

	/**
	 * Request a USPS Priority rate from the ShipStation API.
	 *
	 * @param array $ship_to   ShipStation-compatible destination address.
	 * @param array $candidate Candidate shipment (mode, package_code, dimensions, weight_oz).
	 * @param int   $order_id  Order ID for log context; 0 for test runs.
	 * @return array ['success' => bool, 'rate' => array|null].
	 */
	protected function request_rate( array $ship_to, array $candidate, int $order_id = 0 ): array {
		$result = $this->request_all_rates( $ship_to, $candidate, $order_id );

		if ( ! $result['success'] ) {
			return array( 'success' => false );
		}

		return array(
			'success' => true,
			'rate'    => $result['rates'][0],
		);
	}

	/**
	 * Request ALL rates from the ShipStation API for a candidate shipment.
	 *
	 * Unlike request_rate() which returns only the cheapest rate, this method
	 * returns every rate from the API response sorted cheapest-first.  This is
	 * especially useful when the service code is empty and the API returns
	 * rates for all available services of the carrier (e.g. UPS Ground, UPS
	 * Next Day, etc.).
	 *
	 * @param array $ship_to   ShipStation-compatible destination address.
	 * @param array $candidate Candidate shipment (mode, package_code, dimensions, weight_oz).
	 * @param int   $order_id  Order ID for log context; 0 for test runs.
	 * @return array ['success' => bool, 'rates' => array[]].
	 */
	protected function request_all_rates( array $ship_to, array $candidate, int $order_id = 0 ): array {
		$api_key      = $this->settings->get_shipstation_api_key();
		$api_secret   = $this->settings->get_shipstation_api_secret();
		$carrier_code = $this->get_carrier_code();

		if ( '' === $api_key || '' === $api_secret ) {
			$this->log( 'Missing ShipStation credentials.', array( 'order_id' => $order_id ) );
			return array( 'success' => false );
		}

		if ( '' === $carrier_code ) {
			$this->log( 'ShipStation carrier code is not configured.', array( 'order_id' => $order_id ) );
			return array( 'success' => false );
		}

		$ship_from = $this->settings->get_ship_from_address();

		$payload = array(
			'carrierCode'    => $carrier_code,
			'serviceCode'    => $this->get_service_code(),
			'packageCode'    => $candidate['package_code'],
			'fromPostalCode' => $ship_from['postal_code'] ?? '',
			'toState'        => $ship_to['state_province'] ?? '',
			'toCountry'      => $ship_to['country_code'] ?? 'US',
			'toPostalCode'   => $ship_to['postal_code'] ?? '',
			'toCity'         => $ship_to['city_locality'] ?? '',
			'weight'         => array(
				'value' => round( $candidate['weight_oz'], 2 ),
				'units' => 'ounces',
			),
			'dimensions'     => array(
				'units'  => 'inches',
				'length' => $candidate['dimensions']['length'],
				'width'  => $candidate['dimensions']['width'],
				'height' => $candidate['dimensions']['height'],
			),
			'confirmation'   => 'none',
			'residential'    => false,
		);

		$auth     = base64_encode( $api_key . ':' . $api_secret ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Standard Basic-Auth encoding, not obfuscation.
		$api_url  = (string) apply_filters( 'fk_usps_optimizer_shipstation_api_url', self::API_BASE_URL );
		$endpoint = trailingslashit( $api_url ) . 'shipments/getrates';

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Basic ' . $auth,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log(
				'ShipStation request failed.',
				array(
					'order_id' => $order_id,
					'error'    => $response->get_error_message(),
				)
			);
			return array( 'success' => false );
		}

		$code  = (int) wp_remote_retrieve_response_code( $response );
		$body  = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$rates = is_array( $body ) ? $body : array();

		if ( $code < 200 || $code >= 300 ) {
			$this->log(
				'ShipStation returned a non-success response.',
				array(
					'order_id' => $order_id,
					'status'   => $code,
					'body'     => $body,
				)
			);
			return array( 'success' => false );
		}

		if ( empty( $rates ) ) {
			$this->log(
				'ShipStation returned no rates.',
				array(
					'order_id' => $order_id,
					'body'     => $body,
				)
			);
			return array( 'success' => false );
		}

		// Sort cheapest-first.
		usort(
			$rates,
			static function ( array $a, array $b ): int {
				$cost_a = (float) $a['shipmentCost'] + (float) ( $a['otherCost'] ?? 0 );
				$cost_b = (float) $b['shipmentCost'] + (float) ( $b['otherCost'] ?? 0 );
				return $cost_a <=> $cost_b;
			}
		);

		return array(
			'success' => true,
			'rates'   => $rates,
		);
	}

	// -------------------------------------------------------------------------
	// Address helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a ShipStation-compatible ship-to address from a WooCommerce order.
	 *
	 * @param \WC_Order $order The order.
	 * @return array ShipStation-compatible address.
	 */
	protected function get_ship_to_address( \WC_Order $order ): array {
		return array(
			'name'           => trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ),
			'company_name'   => $order->get_shipping_company(),
			'phone'          => $order->get_billing_phone(),
			'address_line1'  => $order->get_shipping_address_1(),
			'address_line2'  => $order->get_shipping_address_2(),
			'city_locality'  => $order->get_shipping_city(),
			'state_province' => $order->get_shipping_state(),
			'postal_code'    => $order->get_shipping_postcode(),
			'country_code'   => $order->get_shipping_country() ? $order->get_shipping_country() : 'US',
		);
	}

	// -------------------------------------------------------------------------
	// USPS cubic helpers (mirrored from ShipEngine_Service)
	// -------------------------------------------------------------------------

	/**
	 * Check whether dimensions and weight qualify for USPS cubic pricing.
	 *
	 * @param array $dimensions Package dimensions in inches.
	 * @param float $weight_oz  Package weight in ounces.
	 * @return bool True if cubic-eligible.
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
	 * @param array $dimensions Package dimensions in inches.
	 * @return string Cubic tier string (e.g. '0.1' … '0.5').
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
	 * Build a human-readable packing list aggregated by item name.
	 *
	 * @param array $items Packed item arrays.
	 * @return array Strings of the form "2x Widget".
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
	 * Extract an estimated delivery date from a ShipStation rate response.
	 *
	 * The ShipStation getrates API may return delivery information under
	 * several field names depending on the carrier and API version:
	 *   - 'estimatedDeliveryDate' — ISO 8601 datetime string (preferred).
	 *   - 'deliveryDays'          — integer transit-day count.
	 *   - 'transitDays'           — legacy field name for transit-day count.
	 *
	 * The method checks each field in order and returns the first usable
	 * value as a YYYY-MM-DD date string, or '' when no data is available.
	 *
	 * @param array $rate Rate entry from ShipStation response.
	 * @return string ISO 8601 date string (e.g. '2024-01-15'), or ''.
	 */
	protected function extract_delivery_date( array $rate ): string {
		// 1. Direct ISO datetime string from the API.
		$iso = (string) ( $rate['estimatedDeliveryDate'] ?? '' );
		if ( '' !== $iso ) {
			try {
				$date = new \DateTime( $iso );
				return $date->format( 'Y-m-d' );
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Intentional: invalid date falls through to day-count fallbacks.
			}
		}

		// 2. Day-count fields — try both names the API may use.
		foreach ( array( 'deliveryDays', 'transitDays' ) as $key ) {
			if ( isset( $rate[ $key ] ) && (int) $rate[ $key ] > 0 ) {
				return $this->compute_delivery_date( (int) $rate[ $key ] );
			}
		}

		// 3. Fallback: estimate from the service code's typical transit days
		// (only when the admin has enabled the setting).
		if ( $this->settings->is_use_default_transit_days_enabled() ) {
			$service_code = (string) ( $rate['serviceCode'] ?? '' );
			$default_days = $this->get_default_transit_days( $service_code );
			if ( $default_days > 0 ) {
				return $this->compute_delivery_date( $default_days );
			}
		}

		return '';
	}

	/**
	 * Get default transit days for a carrier service code.
	 *
	 * The ShipStation getrates endpoint does not always return delivery-day
	 * or estimated-delivery-date information.  When those fields are absent
	 * this method provides a reasonable worst-case estimate based on
	 * carrier-published transit times so that the checkout still displays
	 * an estimated delivery date.
	 *
	 * @param string $service_code ShipStation service code.
	 * @return int Estimated transit days, or 0 when unknown.
	 */
	protected function get_default_transit_days( string $service_code ): int {
		$map = array(
			// USPS services.
			'usps_priority_mail'         => 3,
			'usps_priority_mail_express' => 2,
			'usps_first_class_mail'      => 5,
			'usps_ground_advantage'      => 5,
			'usps_parcel_select'         => 8,
			'usps_media_mail'            => 8,
			// UPS services.
			'ups_ground'                 => 5,
			'ups_ground_saver'           => 5,
			'ups_3_day_select'           => 3,
			'ups_2nd_day_air'            => 2,
			'ups_next_day_air_saver'     => 1,
			'ups_next_day_air'           => 1,
			// FedEx services.
			'fedex_ground'               => 5,
			'fedex_home_delivery'        => 5,
			'fedex_express_saver'        => 3,
			'fedex_2day'                 => 2,
		);

		return $map[ $service_code ] ?? 0;
	}

	/**
	 * Compute an estimated delivery date string from a transit-day count.
	 *
	 * Uses the current WordPress site time as the start date, adds the
	 * given number of calendar transit days, then adds the configured
	 * buffer as **business days** (Monday–Friday only).  Returns an empty
	 * string when $transit_days is zero or negative.
	 *
	 * @param int $transit_days Number of transit days (calendar).
	 * @return string ISO 8601 date string (e.g. '2024-01-15'), or ''.
	 */
	protected function compute_delivery_date( int $transit_days ): string {
		if ( $transit_days <= 0 ) {
			return '';
		}

		try {
			$date = new \DateTime( current_time( 'mysql' ) );
			$date->modify( '+' . $transit_days . ' days' );

			$buffer = $this->settings->get_transit_days_buffer();
			$added  = 0;
			while ( $added < $buffer ) {
				$date->modify( '+1 day' );
				$dow = (int) $date->format( 'N' ); // 1=Mon … 7=Sun.
				if ( $dow <= 5 ) {
					++$added;
				}
			}

			return $date->format( 'Y-m-d' );
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	// -------------------------------------------------------------------------
	// Logging
	// -------------------------------------------------------------------------

	/**
	 * Log a debug message via the WooCommerce logger.
	 *
	 * Prefixes the message with '[SANDBOX]' when sandbox mode is active so test
	 * and production calls are easily distinguished in the WooCommerce log viewer.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context.
	 * @return void
	 */
	public function log( string $message, array $context = array() ): void {
		if ( ! $this->settings->is_debug_logging_enabled() ) {
			return;
		}

		if ( $this->settings->is_sandbox_mode_enabled() ) {
			$message = '[SANDBOX] ' . $message;
		}

		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->debug( $message . ' ' . wp_json_encode( $context ), array( 'source' => 'fk-usps-optimizer' ) );
		}
	}
}
