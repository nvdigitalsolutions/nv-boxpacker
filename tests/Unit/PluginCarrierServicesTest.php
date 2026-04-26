<?php
/**
 * Unit tests for Plugin::get_carrier_services() de-duplication behaviour.
 *
 * @package FK_USPS_Optimizer
 */

namespace FK_USPS_Optimizer\Tests\Unit;

use FK_USPS_Optimizer\Plugin;
use FK_USPS_Optimizer\Settings;
use FK_USPS_Optimizer\ShipStation_Service;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that ShipStation carrier+service pairs collapse to one
 * ShipStation_Service per unique carrier_code, with the configured
 * services exposed via the allow-list filter.
 */
class PluginCarrierServicesTest extends TestCase {

	/**
	 * Plugin singleton.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	protected function setUp(): void {
		$GLOBALS['_test_wp_options']       = array();
		$GLOBALS['_test_wp_filters']       = array();
		$GLOBALS['_test_wp_transients']    = array();
		$GLOBALS['_test_wp_remote_post']   = null;
		$GLOBALS['_test_wp_remote_get']    = null;
		$GLOBALS['_test_wc_logger']        = null;

		$ref = new \ReflectionProperty( Plugin::class, 'instance' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );

		$this->plugin = Plugin::bootstrap();
	}

	/**
	 * Replace the Plugin's settings dependency with a mock.
	 *
	 * @param Settings $settings Mocked settings instance.
	 * @return void
	 */
	private function inject_settings( Settings $settings ): void {
		$ref = new \ReflectionProperty( Plugin::class, 'settings' );
		$ref->setAccessible( true );
		$ref->setValue( $this->plugin, $settings );

		// Recreate ShipStation_Service so it uses the mocked settings.
		$ss_ref = new \ReflectionProperty( Plugin::class, 'shipstation_service' );
		$ss_ref->setAccessible( true );
		$ss_ref->setValue( $this->plugin, new ShipStation_Service( $settings ) );
	}

	public function test_groups_pairs_by_carrier_code_and_filters_to_configured_services(): void {
		$settings = $this->createMock( Settings::class );
		$settings->method( 'get_carriers' )->willReturn( array( 'shipstation' ) );
		$settings->method( 'get_shipstation_carrier_code' )->willReturn( 'stamps_com' );
		$settings->method( 'get_shipstation_service_code' )->willReturn( 'usps_priority_mail' );
		$settings->method( 'get_shipstation_service_pairs' )->willReturn(
			array(
				array( 'carrier_code' => 'stamps_com',   'service_code' => 'usps_priority_mail' ),
				array( 'carrier_code' => 'stamps_com',   'service_code' => 'usps_ground_advantage' ),
				array( 'carrier_code' => 'ups_walleted', 'service_code' => 'ups_ground' ),
				array( 'carrier_code' => 'ups_walleted', 'service_code' => 'ups_2nd_day_air' ),
				array( 'carrier_code' => 'ups_walleted', 'service_code' => 'ups_next_day_air' ),
			)
		);

		$this->inject_settings( $settings );

		$services = $this->plugin->get_carrier_services();

		// Expect ONE service per unique carrier_code (2 in total), not one
		// per pair (5).
		$this->assertCount( 2, $services );

		$carrier_codes = array();
		foreach ( $services as $service ) {
			$this->assertInstanceOf( ShipStation_Service::class, $service );
			$carrier_codes[] = $service->get_carrier_code();
		}
		sort( $carrier_codes );
		$this->assertSame( array( 'stamps_com', 'ups_walleted' ), $carrier_codes );

		// Each de-duplicated service should ask for ALL services (empty
		// service_code) and filter via the allow-list to the admin's choices.
		foreach ( $services as $service ) {
			$this->assertSame( '', $service->get_service_code() );

			$allow = $service->get_allowed_service_codes();
			$this->assertIsArray( $allow );

			if ( 'stamps_com' === $service->get_carrier_code() ) {
				sort( $allow );
				$this->assertSame( array( 'usps_ground_advantage', 'usps_priority_mail' ), $allow );
			} else {
				sort( $allow );
				$this->assertSame(
					array( 'ups_2nd_day_air', 'ups_ground', 'ups_next_day_air' ),
					$allow
				);
			}
		}
	}

	public function test_single_pair_keeps_specific_service_code_without_allow_list(): void {
		$settings = $this->createMock( Settings::class );
		$settings->method( 'get_carriers' )->willReturn( array( 'shipstation' ) );
		$settings->method( 'get_shipstation_carrier_code' )->willReturn( 'stamps_com' );
		$settings->method( 'get_shipstation_service_code' )->willReturn( 'usps_priority_mail' );
		$settings->method( 'get_shipstation_service_pairs' )->willReturn(
			array(
				array( 'carrier_code' => 'stamps_com', 'service_code' => 'usps_priority_mail' ),
			)
		);

		$this->inject_settings( $settings );

		$services = $this->plugin->get_carrier_services();

		$this->assertCount( 1, $services );
		$this->assertSame( 'stamps_com', $services[0]->get_carrier_code() );
		$this->assertSame( 'usps_priority_mail', $services[0]->get_service_code() );
		$this->assertNull( $services[0]->get_allowed_service_codes() );
	}

	public function test_wildcard_service_supersedes_specific_codes_for_same_carrier(): void {
		$settings = $this->createMock( Settings::class );
		$settings->method( 'get_carriers' )->willReturn( array( 'shipstation' ) );
		$settings->method( 'get_shipstation_carrier_code' )->willReturn( 'stamps_com' );
		$settings->method( 'get_shipstation_service_code' )->willReturn( '' );
		$settings->method( 'get_shipstation_service_pairs' )->willReturn(
			array(
				array( 'carrier_code' => 'stamps_com', 'service_code' => '' ),
				array( 'carrier_code' => 'stamps_com', 'service_code' => 'usps_priority_mail' ),
			)
		);

		$this->inject_settings( $settings );

		$services = $this->plugin->get_carrier_services();

		$this->assertCount( 1, $services );
		$this->assertSame( 'stamps_com', $services[0]->get_carrier_code() );
		$this->assertSame( '', $services[0]->get_service_code() );
		$this->assertNull( $services[0]->get_allowed_service_codes() );
	}
}
