<?php
/**
 * Main plugin bootstrap class for the FK USPS Optimizer plugin.
 *
 * @package FK_USPS_Optimizer
 */

namespace FK_USPS_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once FK_USPS_OPTIMIZER_PATH . 'includes/class-boxpacker-item.php';
require_once FK_USPS_OPTIMIZER_PATH . 'includes/class-boxpacker-box.php';
require_once FK_USPS_OPTIMIZER_PATH . 'includes/class-settings.php';
require_once FK_USPS_OPTIMIZER_PATH . 'includes/class-order-plan-service.php';
require_once FK_USPS_OPTIMIZER_PATH . 'includes/class-packing-service.php';
require_once FK_USPS_OPTIMIZER_PATH . 'includes/class-shipengine-service.php';
require_once FK_USPS_OPTIMIZER_PATH . 'includes/class-pirateship-export.php';
require_once FK_USPS_OPTIMIZER_PATH . 'includes/class-admin-ui.php';

/**
 * Main plugin class responsible for bootstrapping all services.
 */
class Plugin {
	/**
	 * Singleton plugin instance.
	 *
	 * @var Plugin|null
	 */
	protected static $instance;

	/**
	 * Plugin settings instance.
	 *
	 * @var Settings
	 */
	protected $settings;

	/**
	 * Order plan service instance.
	 *
	 * @var Order_Plan_Service
	 */
	protected $order_plan_service;

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
	 * PirateShip export service instance.
	 *
	 * @var PirateShip_Export
	 */
	protected $export_service;

	/**
	 * Admin UI instance.
	 *
	 * @var Admin_UI
	 */
	protected $admin_ui;

	/**
	 * Bootstrap and return the singleton plugin instance.
	 *
	 * @return Plugin The plugin instance.
	 */
	public static function bootstrap(): Plugin {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor. Instantiates all services and registers the init hook.
	 */
	protected function __construct() {
		$this->settings           = new Settings();
		$this->order_plan_service = new Order_Plan_Service();
		$this->packing_service    = new Packing_Service( $this->settings );
		$this->shipengine_service = new ShipEngine_Service( $this->settings );
		$this->export_service     = new PirateShip_Export( $this->settings, $this->order_plan_service );
		$this->admin_ui           = new Admin_UI( $this->settings, $this->order_plan_service, $this->export_service );

		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	/**
	 * Initialise the plugin after WooCommerce is loaded.
	 *
	 * @return void
	 */
	public function init(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		$this->settings->register();
		$this->admin_ui->register();
		$this->export_service->register();

		add_action( 'woocommerce_checkout_order_processed', array( $this, 'process_order' ), 20, 3 );
	}

	/**
	 * Process an order after checkout to build its shipping plan.
	 *
	 * @param int       $order_id    The order ID.
	 * @param array     $posted_data Posted checkout data.
	 * @param \WC_Order $order       The WooCommerce order object.
	 * @return void
	 */
	public function process_order( $order_id, $posted_data, $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( ! $order->needs_shipping_address() ) {
			return;
		}

		$plan = array(
			'created_at'          => current_time( 'mysql', true ),
			'total_package_count' => 0,
			'total_rate_amount'   => 0,
			'currency'            => 'USD',
			'packages'            => array(),
			'pirateship_rows'     => array(),
			'warnings'            => array(),
		);

		try {
			$packed_packages = $this->packing_service->pack_order( $order );

			if ( empty( $packed_packages ) ) {
				$plan['warnings'][] = __( 'No shippable items were found for packing.', 'fk-usps-optimizer' );
				$this->order_plan_service->save( $order, $plan );
				return;
			}

			foreach ( $packed_packages as $index => $package ) {
				$package_plan = $this->shipengine_service->build_package_plan( $order, $package, $index + 1 );

				if ( empty( $package_plan ) ) {
					$plan['warnings'][] = sprintf(
						/* translators: %d package number. */
						__( 'Unable to produce a USPS plan for package %d.', 'fk-usps-optimizer' ),
						$index + 1
					);
					continue;
				}

				$plan['packages'][]         = $package_plan;
				$plan['total_rate_amount'] += (float) $package_plan['rate_amount'];
				$plan['currency']           = $package_plan['currency'];
				$plan['pirateship_rows'][]  = $this->export_service->build_row( $order, $package_plan );
			}

			$plan['total_package_count'] = count( $plan['packages'] );

			if ( 0 === $plan['total_package_count'] ) {
				$plan['warnings'][] = __( 'All rate-shopping attempts failed. Review ShipEngine configuration and box setup.', 'fk-usps-optimizer' );
			}
		} catch ( \Throwable $throwable ) {
			$plan['warnings'][] = $throwable->getMessage();
			$this->shipengine_service->log(
				'Order planning failed',
				array(
					'order_id' => $order->get_id(),
					'error'    => $throwable->getMessage(),
				)
			);
		}

		$this->order_plan_service->save( $order, $plan );
	}
}
