<?php
/**
 * Live-API integration tests for ShipStation_Service.
 *
 * These tests hit the REAL ShipStation API and are skipped unless credentials
 * are provided via environment variables — see phpunit.live.xml.dist and
 * phpunit.live.local.xml.example.  Rate requests (getrates) create no labels
 * and are safe to run against production credentials.
 *
 * Required env vars:
 *   FK_USPS_OPTIMIZER_TEST_SHIPSTATION_API_KEY
 *   FK_USPS_OPTIMIZER_TEST_SHIPSTATION_API_SECRET
 * Optional (defaults shown):
 *   FK_USPS_OPTIMIZER_TEST_SHIPSTATION_CARRIER_CODE  stamps_com
 *   FK_USPS_OPTIMIZER_TEST_SHIPSTATION_SERVICE_CODE  usps_priority_mail
 *   FK_USPS_OPTIMIZER_TEST_SHIP_FROM_POSTAL          90210
 *   FK_USPS_OPTIMIZER_TEST_SHIP_TO_POSTAL            78701
 *
 * @package FK_USPS_Optimizer
 */

namespace FK_USPS_Optimizer\Tests\Integration;

use FK_USPS_Optimizer\Settings;
use FK_USPS_Optimizer\ShipStation_Service;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the ShipStation rate pipeline against the live API.
 */
class ShipStationLiveTest extends TestCase {

	/**
	 * ShipStation service under test.
	 *
	 * @var ShipStation_Service
	 */
	private ShipStation_Service $service;

	/**
	 * Configured carrier code.
	 *
	 * @var string
	 */
	private string $carrier_code;

	/**
	 * Configured service code.
	 *
	 * @var string
	 */
	private string $service_code;

	/**
	 * Cached "all services" live response, shared across tests in this run.
	 *
	 * @var array|null
	 */
	private static $cached_all_rates;

	/**
	 * Read an environment variable with $_ENV/$_SERVER fallbacks.
	 *
	 * @param string $name    Variable name.
	 * @param string $default Fallback value.
	 * @return string Resolved value.
	 */
	private function live_env( string $name, string $default = '' ): string {
		$value = getenv( $name );

		if ( false === $value || '' === $value ) {
			$value = $_ENV[ $name ] ?? ( $_SERVER[ $name ] ?? $default );
		}

		return (string) $value;
	}

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['_test_wp_remote_live'] = true;
		$GLOBALS['_test_wp_options']     = array();
		$GLOBALS['_test_wp_filters']     = array();
		$GLOBALS['_test_wp_transients']  = array();
		$GLOBALS['_test_wc_logger']      = new \WC_Test_Logger();
		$GLOBALS['_test_wp_remote_post'] = null;
		$GLOBALS['_test_wp_remote_get']  = null;

		$api_key = $this->live_env( 'FK_USPS_OPTIMIZER_TEST_SHIPSTATION_API_KEY' );

		if ( '' === $api_key ) {
			$this->markTestSkipped(
				'Set FK_USPS_OPTIMIZER_TEST_SHIPSTATION_API_KEY (+ _API_SECRET) to run live ShipStation tests.'
			);
		}

		$api_secret        = $this->live_env( 'FK_USPS_OPTIMIZER_TEST_SHIPSTATION_API_SECRET' );
		$this->carrier_code = $this->live_env( 'FK_USPS_OPTIMIZER_TEST_SHIPSTATION_CARRIER_CODE', 'stamps_com' );
		$this->service_code = $this->live_env( 'FK_USPS_OPTIMIZER_TEST_SHIPSTATION_SERVICE_CODE', 'usps_priority_mail' );
		$from_postal       = $this->live_env( 'FK_USPS_OPTIMIZER_TEST_SHIP_FROM_POSTAL', '90210' );

		if ( '' === $api_secret ) {
			$this->markTestSkipped( 'Set FK_USPS_OPTIMIZER_TEST_SHIPSTATION_API_SECRET to run live ShipStation tests.' );
		}

		$settings = $this->createMock( Settings::class );
		$settings->method( 'get_shipstation_api_key' )->willReturn( $api_key );
		$settings->method( 'get_shipstation_api_secret' )->willReturn( $api_secret );
		$settings->method( 'get_shipstation_carrier_code' )->willReturn( $this->carrier_code );
		$settings->method( 'get_shipstation_service_code' )->willReturn( $this->service_code );
		// Disable caching so every test run hits the API fresh.
		$settings->method( 'is_sandbox_mode_enabled' )->willReturn( true );
		$settings->method( 'is_debug_logging_enabled' )->willReturn( false );
		$settings->method( 'get_ship_from_address' )->willReturn(
			array(
				'postal_code'  => $from_postal,
				'country_code' => 'US',
			)
		);

		$this->service = new ShipStation_Service( $settings );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_test_wp_remote_live'] );
		parent::tearDown();
	}

	/**
	 * Invoke a protected method via reflection.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Arguments.
	 * @return mixed Return value.
	 */
	private function call_protected( string $method, array $args = array() ) {
		$ref = new \ReflectionMethod( $this->service, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $this->service, $args );
	}

	/**
	 * Build a candidate shipment.
	 *
	 * @return array Candidate shipment.
	 */
	private function make_candidate(): array {
		return array(
			'mode'         => 'cubic',
			'package_code' => 'package',
			'package_name' => 'Live Test Box',
			'dimensions'   => array( 'length' => 8.0, 'width' => 8.0, 'height' => 6.0 ),
			'weight_oz'    => 19.0,
			'cubic_tier'   => '0.3',
			'box'          => array(),
		);
	}

	/**
	 * Build a ShipStation-compatible ship-to address.
	 *
	 * @return array Ship-to address.
	 */
	private function make_ship_to(): array {
		return array(
			'name'           => 'Live Test Recipient',
			'address_line1'  => '123 Main St',
			'city_locality'  => 'Austin',
			'state_province' => 'TX',
			'postal_code'    => $this->live_env( 'FK_USPS_OPTIMIZER_TEST_SHIP_TO_POSTAL', '78701' ),
			'country_code'   => 'US',
		);
	}

	/**
	 * The configured service must round-trip: the priced rate's serviceCode
	 * matches the requested one, at the requested service's price.
	 */
	public function test_request_rate_round_trips_configured_service(): void {
		$result = $this->call_protected(
			'request_rate',
			array( $this->make_ship_to(), $this->make_candidate(), 0, $this->service_code )
		);

		$this->assertTrue( $result['success'], 'Live getrates request failed.' );
		$this->assertSame( $this->service_code, $result['rate']['serviceCode'] );
		$this->assertGreaterThan( 0, (float) $result['rate']['shipmentCost'] );
	}

	/**
	 * A wildcard (empty service code) request must return every available
	 * service with its own code — the data the checkout rate-shopping builds
	 * on.  The response is fetched once and shared with the filter test.
	 */
	public function test_wildcard_request_returns_services_with_their_own_codes(): void {
		$rates = $this->fetch_all_rates();

		$this->assertNotEmpty( $rates, 'Wildcard getrates returned no services.' );
		foreach ( $rates as $rate ) {
			$this->assertNotEmpty( $rate['serviceCode'], 'Every live rate must carry a serviceCode.' );
			$this->assertGreaterThan( 0, (float) $rate['shipmentCost'] );
		}
	}

	/**
	 * filter_rates_by_service() must reduce a real multi-service response to
	 * exactly the requested service — the rule that prevents label/price
	 * mismatches in production.
	 */
	public function test_filter_rates_by_service_keeps_only_requested_code(): void {
		$rates = $this->fetch_all_rates();
		$this->assertNotEmpty( $rates );

		$filtered = $this->call_protected(
			'filter_rates_by_service',
			array( $rates, $this->service_code, $this->make_ship_to() )
		);

		$this->assertNotEmpty( $filtered, 'The configured service must be present in the live response.' );
		foreach ( $filtered as $rate ) {
			$this->assertSame( $this->service_code, $rate['serviceCode'] );
		}
	}

	/**
	 * Fetch all rates for the carrier with an empty service code, once per run.
	 *
	 * Uses a fresh wildcard service instance so the request omits serviceCode
	 * entirely (the primary instance is bound to the configured service).
	 *
	 * @return array Raw rates from the live API (cheapest-first).
	 */
	private function fetch_all_rates(): array {
		if ( is_array( self::$cached_all_rates ) ) {
			return self::$cached_all_rates;
		}

		$settings = $this->createMock( Settings::class );
		$settings->method( 'get_shipstation_api_key' )->willReturn( $this->live_env( 'FK_USPS_OPTIMIZER_TEST_SHIPSTATION_API_KEY' ) );
		$settings->method( 'get_shipstation_api_secret' )->willReturn( $this->live_env( 'FK_USPS_OPTIMIZER_TEST_SHIPSTATION_API_SECRET' ) );
		$settings->method( 'get_shipstation_carrier_code' )->willReturn( $this->carrier_code );
		$settings->method( 'get_shipstation_service_code' )->willReturn( '' );
		$settings->method( 'is_sandbox_mode_enabled' )->willReturn( true );
		$settings->method( 'is_debug_logging_enabled' )->willReturn( false );
		$settings->method( 'get_ship_from_address' )->willReturn(
			array(
				'postal_code'  => $this->live_env( 'FK_USPS_OPTIMIZER_TEST_SHIP_FROM_POSTAL', '90210' ),
				'country_code' => 'US',
			)
		);

		$wildcard = new ShipStation_Service( $settings, $this->carrier_code, '' );

		$ref = new \ReflectionMethod( $wildcard, 'request_all_rates' );
		$ref->setAccessible( true );
		$result = $ref->invokeArgs( $wildcard, array( $this->make_ship_to(), $this->make_candidate(), 0 ) );

		if ( empty( $result['success'] ) ) {
			return array();
		}

		self::$cached_all_rates = $result['rates'];

		return self::$cached_all_rates;
	}
}
