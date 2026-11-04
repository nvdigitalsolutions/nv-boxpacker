<?php
/**
 * Live-API integration tests for ShipEngine_Service.
 *
 * These tests hit the REAL ShipEngine API (sandbox) and are skipped unless
 * credentials are provided via environment variables — see phpunit.live.xml.dist
 * and phpunit.live.local.xml.example.  They never run as part of the default
 * unit suite (composer run test) because they require network access.
 *
 * Required env vars:
 *   FK_USPS_OPTIMIZER_TEST_SHIPENGINE_API_KEY     TEST_-prefixed sandbox key
 * Optional (defaults shown):
 *   FK_USPS_OPTIMIZER_TEST_SHIPENGINE_CARRIER_ID  se-423887 (sandbox stamps_com)
 *   FK_USPS_OPTIMIZER_TEST_SHIP_FROM_POSTAL       90210
 *   FK_USPS_OPTIMIZER_TEST_SHIP_TO_POSTAL         78701
 *
 * @package FK_USPS_Optimizer
 */

namespace FK_USPS_Optimizer\Tests\Integration;

use FK_USPS_Optimizer\Settings;
use FK_USPS_Optimizer\ShipEngine_Service;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the ShipEngine rate pipeline against the live sandbox API.
 */
class ShipEngineLiveTest extends TestCase {

	/**
	 * ShipEngine service under test.
	 *
	 * @var ShipEngine_Service
	 */
	private ShipEngine_Service $service;

	/**
	 * Configured sandbox API key (empty => tests skip).
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * Read an environment variable with $_ENV/$_SERVER fallbacks.
	 *
	 * PHPUnit <env> config entries are exposed via putenv()/$_ENV/$_SERVER;
	 * this helper covers all three so the suite works under PHPUnit configs,
	 * CI secrets, and OS-level environment variables alike.
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

		$this->api_key = $this->live_env( 'FK_USPS_OPTIMIZER_TEST_SHIPENGINE_API_KEY' );

		if ( '' === $this->api_key ) {
			$this->markTestSkipped(
				'Set FK_USPS_OPTIMIZER_TEST_SHIPENGINE_API_KEY to run live ShipEngine tests.'
			);
		}

		$carrier_id = $this->live_env( 'FK_USPS_OPTIMIZER_TEST_SHIPENGINE_CARRIER_ID', 'se-423887' );
		$from_postal = $this->live_env( 'FK_USPS_OPTIMIZER_TEST_SHIP_FROM_POSTAL', '90210' );

		$settings = $this->createMock( Settings::class );
		$settings->method( 'get_shipengine_api_key' )->willReturn( $this->api_key );
		$settings->method( 'get_shipengine_carrier_id' )->willReturn( $carrier_id );
		// Sandbox credentials + disabled cache so every test hits the API.
		$settings->method( 'is_sandbox_mode_enabled' )->willReturn( true );
		$settings->method( 'is_debug_logging_enabled' )->willReturn( false );
		$settings->method( 'get_ship_from_address' )->willReturn(
			array(
				'name'                          => 'FK Optimizer Test',
				'address_line1'                 => '123 Test St',
				'city_locality'                 => 'Beverly Hills',
				'state_province'                => 'CA',
				'postal_code'                   => $from_postal,
				'country_code'                  => 'US',
				'address_residential_indicator' => 'no',
			)
		);

		$this->service = new ShipEngine_Service( $settings );
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
	 * Build a candidate shipment matching the sandbox "package" code.
	 *
	 * @return array Candidate shipment.
	 */
	private function make_candidate(): array {
		return array(
			'mode'         => 'cubic',
			'package_code' => 'package',
			'package_name' => 'Live Test Box',
			'dimensions'   => array( 'length' => 8.0, 'width' => 8.0, 'height' => 6.0 ),
			'weight_oz'    => 16.0,
			'cubic_tier'   => '0.3',
			'box'          => array(),
		);
	}

	/**
	 * Build a ShipEngine-formatted ship-to address.
	 *
	 * @return array Ship-to address.
	 */
	private function make_ship_to(): array {
		return array(
			'name'                          => 'Live Test Recipient',
			'address_line1'                 => '123 Main St',
			'city_locality'                 => 'Austin',
			'state_province'                => 'TX',
			'postal_code'                   => $this->live_env( 'FK_USPS_OPTIMIZER_TEST_SHIP_TO_POSTAL', '78701' ),
			'country_code'                  => 'US',
			'address_residential_indicator' => 'no',
		);
	}

	/**
	 * A specific service request must come back labeled with that service.
	 */
	public function test_priority_rate_request_returns_matching_service_code(): void {
		$result = $this->call_protected(
			'request_rate_for_address',
			array( $this->make_ship_to(), $this->make_candidate(), 0, 'usps_priority_mail' )
		);

		$this->assertTrue( $result['success'], 'Sandbox rate request failed.' );
		$this->assertSame( 'usps_priority_mail', $result['rate']['service_code'] );
		$this->assertGreaterThan( 0, (float) $result['rate']['shipping_amount']['amount'] );
	}

	/**
	 * An all-services request prices the cheapest service — and the plan must
	 * carry THAT service's code, not the configured one.
	 */
	public function test_empty_service_code_prices_cheapest_rate_with_its_own_code(): void {
		$result = $this->call_protected(
			'request_rate_for_address',
			array( $this->make_ship_to(), $this->make_candidate(), 0, '' )
		);

		$this->assertTrue( $result['success'], 'Sandbox rate request failed.' );
		$this->assertNotEmpty( $result['rate']['service_code'] );
		$this->assertGreaterThan( 0, (float) $result['rate']['shipping_amount']['amount'] );
	}

	/**
	 * Media Mail must round-trip as Media Mail, never Priority.
	 */
	public function test_media_mail_rate_round_trips_its_own_service_code(): void {
		$result = $this->call_protected(
			'request_rate_for_address',
			array( $this->make_ship_to(), $this->make_candidate(), 0, 'usps_media_mail' )
		);

		$this->assertTrue( $result['success'], 'Sandbox could not rate Media Mail.' );
		$this->assertSame( 'usps_media_mail', $result['rate']['service_code'] );
	}
}
