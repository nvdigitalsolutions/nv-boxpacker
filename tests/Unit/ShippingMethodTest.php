<?php
/**
 * Unit tests for Shipping_Method.
 *
 * @package FK_USPS_Optimizer
 */

namespace FK_USPS_Optimizer\Tests\Unit;

use FK_USPS_Optimizer\Shipping_Method;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Shipping_Method.
 */
class ShippingMethodTest extends TestCase {

	/**
	 * System under test.
	 *
	 * @var Shipping_Method
	 */
	private Shipping_Method $method;

	protected function setUp(): void {
		$GLOBALS['_test_wp_options']    = array();
		$GLOBALS['_test_wp_filters']    = array();
		$GLOBALS['_test_wp_transients'] = array();

		$this->method = new Shipping_Method();
	}

	// -------------------------------------------------------------------------
	// Helper utilities
	// -------------------------------------------------------------------------

	/**
	 * Invoke a protected method via reflection.
	 *
	 * @param string $name Method name.
	 * @param array  $args Arguments.
	 * @return mixed Return value.
	 */
	private function call_protected( string $name, array $args = array() ) {
		$ref = new \ReflectionMethod( $this->method, $name );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $this->method, $args );
	}

	// -------------------------------------------------------------------------
	// build_ship_to
	// -------------------------------------------------------------------------

	public function test_build_ship_to_maps_destination_fields(): void {
		$dest   = array(
			'address'   => '123 Main St',
			'address_2' => 'Apt 4',
			'city'      => 'Austin',
			'state'     => 'TX',
			'postcode'  => '78701',
			'country'   => 'US',
		);
		$result = $this->call_protected( 'build_ship_to', array( $dest ) );

		$this->assertSame( '123 Main St', $result['address_line1'] );
		$this->assertSame( 'Apt 4', $result['address_line2'] );
		$this->assertSame( 'Austin', $result['city_locality'] );
		$this->assertSame( 'TX', $result['state_province'] );
		$this->assertSame( '78701', $result['postal_code'] );
		$this->assertSame( 'US', $result['country_code'] );
	}

	// -------------------------------------------------------------------------
	// get_rate_cache_key
	// -------------------------------------------------------------------------

	public function test_cache_key_changes_when_carrier_changes(): void {
		$items = array(
			array(
				'product_id' => 1,
				'length'     => 6,
				'width'      => 6,
				'height'     => 4,
				'weight_oz'  => 16,
			),
		);
		$dest  = array(
			'country'  => 'US',
			'state'    => 'TX',
			'postcode' => '78701',
			'city'     => 'Austin',
		);

		$GLOBALS['_test_wp_options']['fk_usps_optimizer_settings'] = array( 'carrier' => 'shipengine' );
		$key1 = $this->call_protected( 'get_rate_cache_key', array( $items, $dest ) );

		$GLOBALS['_test_wp_options']['fk_usps_optimizer_settings'] = array( 'carrier' => 'shipstation' );
		$key2 = $this->call_protected( 'get_rate_cache_key', array( $items, $dest ) );

		$this->assertNotSame( $key1, $key2 );
	}

	/**
	 * Cache key must change when box configuration changes.
	 */
	public function test_cache_key_changes_when_boxes_json_changes(): void {
		$items = array(
			array(
				'product_id' => 1,
				'length'     => 6,
				'width'      => 6,
				'height'     => 4,
				'weight_oz'  => 16,
			),
		);
		$dest  = array(
			'country'  => 'US',
			'state'    => 'TX',
			'postcode' => '78701',
			'city'     => 'Austin',
		);

		$GLOBALS['_test_wp_options']['fk_usps_optimizer_settings'] = array(
			'carrier'    => 'shipengine',
			'boxes_json' => '[{"reference":"BoxA"}]',
		);
		$key1 = $this->call_protected( 'get_rate_cache_key', array( $items, $dest ) );

		$GLOBALS['_test_wp_options']['fk_usps_optimizer_settings'] = array(
			'carrier'    => 'shipengine',
			'boxes_json' => '[{"reference":"BoxB"}]',
		);
		$key2 = $this->call_protected( 'get_rate_cache_key', array( $items, $dest ) );

		$this->assertNotSame( $key1, $key2, 'Changing box configuration must invalidate the rate cache.' );
	}

	/**
	 * Cache key must change when the show-all-options toggle changes.
	 */
	public function test_cache_key_changes_when_show_all_options_changes(): void {
		$items = array(
			array(
				'product_id' => 1,
				'length'     => 6,
				'width'      => 6,
				'height'     => 4,
				'weight_oz'  => 16,
			),
		);
		$dest  = array(
			'country'  => 'US',
			'state'    => 'TX',
			'postcode' => '78701',
			'city'     => 'Austin',
		);

		$GLOBALS['_test_wp_options']['fk_usps_optimizer_settings'] = array(
			'carrier'          => 'shipengine',
			'show_all_options' => '0',
		);
		$key1 = $this->call_protected( 'get_rate_cache_key', array( $items, $dest ) );

		$GLOBALS['_test_wp_options']['fk_usps_optimizer_settings'] = array(
			'carrier'          => 'shipengine',
			'show_all_options' => '1',
		);
		$key2 = $this->call_protected( 'get_rate_cache_key', array( $items, $dest ) );

		$this->assertNotSame( $key1, $key2, 'Toggling "show all options" must invalidate the rate cache.' );
	}

	/**
	 * Cache key must change when the show-package-count toggle changes.
	 */
	public function test_cache_key_changes_when_show_package_count_changes(): void {
		$items = array(
			array(
				'product_id' => 1,
				'length'     => 6,
				'width'      => 6,
				'height'     => 4,
				'weight_oz'  => 16,
			),
		);
		$dest  = array(
			'country'  => 'US',
			'state'    => 'TX',
			'postcode' => '78701',
			'city'     => 'Austin',
		);

		$GLOBALS['_test_wp_options']['fk_usps_optimizer_settings'] = array(
			'carrier'            => 'shipengine',
			'show_package_count' => '0',
		);
		$key1 = $this->call_protected( 'get_rate_cache_key', array( $items, $dest ) );

		$GLOBALS['_test_wp_options']['fk_usps_optimizer_settings'] = array(
			'carrier'            => 'shipengine',
			'show_package_count' => '1',
		);
		$key2 = $this->call_protected( 'get_rate_cache_key', array( $items, $dest ) );

		$this->assertNotSame( $key1, $key2, 'Toggling "show package count" must invalidate the rate cache.' );
	}

	/**
	 * Cache key must change when the service code changes.
	 */
	public function test_cache_key_changes_when_service_code_changes(): void {
		$items = array(
			array(
				'product_id' => 1,
				'length'     => 6,
				'width'      => 6,
				'height'     => 4,
				'weight_oz'  => 16,
			),
		);
		$dest  = array(
			'country'  => 'US',
			'state'    => 'TX',
			'postcode' => '78701',
			'city'     => 'Austin',
		);

		$GLOBALS['_test_wp_options']['fk_usps_optimizer_settings'] = array(
			'carrier'      => 'shipengine',
			'service_code' => 'usps_priority_mail',
		);
		$key1 = $this->call_protected( 'get_rate_cache_key', array( $items, $dest ) );

		$GLOBALS['_test_wp_options']['fk_usps_optimizer_settings'] = array(
			'carrier'      => 'shipengine',
			'service_code' => 'usps_ground_advantage',
		);
		$key2 = $this->call_protected( 'get_rate_cache_key', array( $items, $dest ) );

		$this->assertNotSame( $key1, $key2, 'Changing service code must invalidate the rate cache.' );
	}
}
