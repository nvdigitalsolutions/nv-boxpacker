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
require_once FK_USPS_OPTIMIZER_PATH . 'includes/class-shipstation-service.php';
require_once FK_USPS_OPTIMIZER_PATH . 'includes/class-test-pricing-service.php';
require_once FK_USPS_OPTIMIZER_PATH . 'includes/class-pirateship-export.php';
require_once FK_USPS_OPTIMIZER_PATH . 'includes/class-admin-ui.php';
require_once FK_USPS_OPTIMIZER_PATH . 'includes/class-admin-test-ui.php';

/**
 * Main plugin class responsible for bootstrapping all services.
 */
class Plugin {
	/**
	 * Order meta key under which the rendered packing-plan note text is
	 * stored. This is the canonical home for the plan; it is rendered in
	 * the admin "USPS Priority Shipping Plan" metabox and injected into
	 * the `customer_note` field of WooCommerce REST API responses so
	 * PirateShip continues to receive it without the value ever being
	 * persisted in the order's customer-note column.
	 *
	 * @var string
	 */
	const PACKING_NOTE_META_KEY = '_fk_packing_plan_note';

	/**
	 * Legacy opening marker that previous versions wrote into the order's
	 * customer note. Kept only so we can clean any pre-upgrade orders by
	 * stripping the marker block on first re-process. Not written by new
	 * code.
	 *
	 * @var string
	 */
	const PACKING_NOTE_START_MARKER = '<!-- fk-pack-start -->';

	/**
	 * Legacy closing marker (see PACKING_NOTE_START_MARKER).
	 *
	 * @var string
	 */
	const PACKING_NOTE_END_MARKER = '<!-- fk-pack-end -->';

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
	 * ShipStation service instance.
	 *
	 * @var ShipStation_Service
	 */
	protected $shipstation_service;

	/**
	 * Test pricing service instance.
	 *
	 * @var Test_Pricing_Service
	 */
	protected $test_pricing_service;

	/**
	 * Admin test UI instance.
	 *
	 * @var Admin_Test_UI
	 */
	protected $admin_test_ui;

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
		$this->settings             = new Settings();
		$this->order_plan_service   = new Order_Plan_Service();
		$this->packing_service      = new Packing_Service( $this->settings );
		$this->shipengine_service   = new ShipEngine_Service( $this->settings );
		$this->shipstation_service  = new ShipStation_Service( $this->settings );
		$this->test_pricing_service = new Test_Pricing_Service( $this->settings, $this->packing_service, $this->shipengine_service, $this->shipstation_service );
		$this->export_service       = new PirateShip_Export( $this->settings, $this->order_plan_service );
		$this->admin_ui             = new Admin_UI( $this->settings, $this->order_plan_service, $this->export_service );
		$this->admin_test_ui        = new Admin_Test_UI( $this->settings, $this->test_pricing_service );

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
		$this->admin_test_ui->register();
		$this->export_service->register();

		// Register as a WooCommerce shipping method so it appears in shipping zones.
		require_once FK_USPS_OPTIMIZER_PATH . 'includes/class-shipping-method.php';
		add_filter( 'woocommerce_shipping_methods', array( $this, 'register_shipping_method' ) );

		// Append estimated delivery date on its own line in the cart/checkout label.
		add_filter( 'woocommerce_cart_shipping_method_full_label', array( $this, 'append_delivery_date_to_label' ), 10, 2 );

		add_action( 'woocommerce_checkout_order_processed', array( $this, 'process_order' ), 20, 3 );
		add_action( 'wp_ajax_fk_usps_test_connection', array( $this, 'handle_test_connection_ajax' ) );

		// Inject the stored packing-plan text into the `customer_note` field
		// of WooCommerce REST API order responses so PirateShip (and other
		// REST consumers) receive the plan alongside the order. The plan is
		// never written into the order's stored customer-note column, so it
		// cannot leak into customer emails, the My Account page, the admin
		// order screen or invoices.
		add_filter( 'woocommerce_rest_prepare_shop_order_object', array( $this, 'inject_packing_plan_into_rest_response' ), 10, 3 );
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

		$carrier_services = $this->get_carrier_services();

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

			// Prefer the plan that was attached to the rate the customer
			// actually selected at checkout.  Shipping_Method stores the
			// per-package plan on each WC rate as hidden meta; WooCommerce
			// copies that meta onto the order's shipping line item when the
			// customer picks the rate.  Reusing it guarantees the stored
			// plan matches the chosen carrier/service (e.g. UPS Ground)
			// instead of being recomputed via "cheapest across all carriers"
			// which can land on a different service such as Media Mail.
			$chosen_plans = $this->extract_chosen_plan_packages( $order );

			if ( ! empty( $chosen_plans ) ) {
				foreach ( $chosen_plans as $chosen_plan ) {
					$plan['packages'][]         = $chosen_plan;
					$plan['total_rate_amount'] += (float) ( $chosen_plan['rate_amount'] ?? 0 );
					$plan['currency']           = (string) ( $chosen_plan['currency'] ?? $plan['currency'] );
					$plan['pirateship_rows'][]  = $this->export_service->build_row( $order, $chosen_plan );
				}

				$plan['total_package_count'] = count( $plan['packages'] );
			} else {
				foreach ( $packed_packages as $index => $package ) {
					$best_plan = array();
					$best_cost = PHP_FLOAT_MAX;

					foreach ( $carrier_services as $carrier_svc ) {
						$candidate_plan = $carrier_svc->build_package_plan( $order, $package, $index + 1 );

						if ( empty( $candidate_plan ) ) {
							continue;
						}

						// Never offer disallowed services (e.g. Media Mail) as the
						// rate-shopping winner, even when they are the cheapest.
						$candidate_service = (string) ( $candidate_plan['service_code'] ?? '' );
						if ( ! Shipping_Method::is_service_code_allowed( $candidate_service ) ) {
							continue;
						}

						if ( (float) $candidate_plan['rate_amount'] < $best_cost ) {
							$best_plan = $candidate_plan;
							$best_cost = (float) $candidate_plan['rate_amount'];
						}
					}

					if ( empty( $best_plan ) ) {
						$plan['warnings'][] = sprintf(
							/* translators: %d package number. */
							__( 'Unable to produce a shipping plan for package %d.', 'fk-usps-optimizer' ),
							$index + 1
						);
						continue;
					}

					$plan['packages'][]         = $best_plan;
					$plan['total_rate_amount'] += (float) $best_plan['rate_amount'];
					$plan['currency']           = $best_plan['currency'];
					$plan['pirateship_rows'][]  = $this->export_service->build_row( $order, $best_plan );
				}

				$plan['total_package_count'] = count( $plan['packages'] );
			}

			if ( 0 === $plan['total_package_count'] ) {
				$plan['warnings'][] = __( 'All rate-shopping attempts failed. Review carrier API configuration and box setup.', 'fk-usps-optimizer' );
			}
		} catch ( \Throwable $throwable ) {
			$plan['warnings'][] = $throwable->getMessage();
			$this->log(
				'Order planning failed',
				array(
					'order_id' => $order->get_id(),
					'error'    => $throwable->getMessage(),
				)
			);
		}

		$this->order_plan_service->save( $order, $plan );

		if ( $this->settings->is_add_package_note_enabled() && ! empty( $plan['packages'] ) ) {
			$order->add_order_note( $this->build_package_note( $plan ) );
		}

		if ( $this->settings->is_add_packing_to_customer_note_enabled() && ! empty( $plan['packages'] ) ) {
			$this->store_packing_plan_note( $order, $plan );
		}

		// Email the packing plan + PirateShip CSV to the configured
		// recipients (if any) so shipping staff can import the CSV
		// directly into PirateShip without first opening the order.
		if ( ! empty( $plan['packages'] ) && ! empty( $this->settings->get_pirateship_notification_emails() ) ) {
			try {
				$this->export_service->send_order_notification( $order, $plan );
			} catch ( \Throwable $throwable ) {
				$this->log(
					'PirateShip notification email failed',
					array(
						'order_id' => $order->get_id(),
						'error'    => $throwable->getMessage(),
					)
				);
			}
		}
	}

	/**
	 * Extract the per-package shipping plan persisted on the customer's
	 * chosen shipping line item.
	 *
	 * Shipping_Method attaches the per-package plan behind each rate as
	 * hidden meta (`_fk_plan_packages`).  WooCommerce copies a chosen rate's
	 * meta_data onto the order's shipping line item, so reading it back
	 * lets us build the order plan from the exact rate the customer paid
	 * for — keeping the stored plan aligned with the carrier/service shown
	 * at checkout.
	 *
	 * Returns an empty array when no plan is stored (e.g. the customer
	 * picked a third-party shipping method that did not originate from
	 * this plugin), in which case the caller should fall back to its own
	 * rate-shopping logic.
	 *
	 * @param \WC_Order $order The WooCommerce order.
	 * @return array Per-package shipping plans, or empty array.
	 */
	protected function extract_chosen_plan_packages( \WC_Order $order ): array {
		if ( ! method_exists( $order, 'get_shipping_methods' ) ) {
			return array();
		}

		$shipping_items = $order->get_shipping_methods();
		if ( ! is_array( $shipping_items ) || empty( $shipping_items ) ) {
			return array();
		}

		foreach ( $shipping_items as $shipping_item ) {
			if ( ! is_object( $shipping_item ) || ! method_exists( $shipping_item, 'get_meta' ) ) {
				continue;
			}

			$raw = $shipping_item->get_meta( '_fk_plan_packages', true );

			if ( is_string( $raw ) && '' !== $raw ) {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) && ! empty( $decoded ) ) {
					return $decoded;
				}
			} elseif ( is_array( $raw ) && ! empty( $raw ) ) {
				// Defensive fallback: WC stores rate meta as serialized PHP and
				// usually returns the JSON string we saved, but third-party
				// extensions or future WC changes could return an already-
				// decoded array here.  Accept either shape so the chosen-plan
				// path keeps working.
				return $raw;
			}
		}

		return array();
	}

	/**
	 * Handle the "Test Connection" AJAX action from the settings page.
	 *
	 * Verifies the nonce, determines which carrier to test from the POSTed
	 * carrier value (falling back to the saved setting), uses credentials
	 * POSTed from the current form (so the test works before saving), and
	 * returns the result as a JSON response so the settings page can display
	 * it inline without a full page reload.
	 *
	 * @return void
	 */
	public function handle_test_connection_ajax(): void {
		check_ajax_referer( 'fk_usps_test_connection', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to perform this action.', 'fk-usps-optimizer' ) ),
				403
			);
			return;
		}

		// Prefer the carrier from the POST request (current dropdown value) so
		// the test reflects the UI even before settings are saved.
		$posted_carrier = sanitize_text_field( wp_unslash( $_POST['carrier'] ?? '' ) );
		$carrier        = in_array( $posted_carrier, array( 'shipengine', 'shipstation' ), true )
			? $posted_carrier
			: $this->settings->get_carrier();

		if ( 'shipstation' === $carrier ) {
			$api_key    = sanitize_text_field( wp_unslash( $_POST['shipstation_api_key'] ?? '' ) );
			$api_secret = sanitize_text_field( wp_unslash( $_POST['shipstation_api_secret'] ?? '' ) );
			$result     = $this->shipstation_service->test_connection( $api_key, $api_secret );
		} else {
			$api_key    = sanitize_text_field( wp_unslash( $_POST['shipengine_api_key'] ?? '' ) );
			$carrier_id = sanitize_text_field( wp_unslash( $_POST['shipengine_carrier_id'] ?? '' ) );
			$result     = $this->shipengine_service->test_connection( $api_key, $carrier_id );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Register the plugin's shipping method with WooCommerce.
	 *
	 * @param array $methods Registered shipping method classes.
	 * @return array Updated shipping methods.
	 */
	public function register_shipping_method( array $methods ): array {
		$methods['fk_usps_optimizer'] = '\FK_USPS_Optimizer\Shipping_Method';
		return $methods;
	}

	/**
	 * Append estimated delivery date on a separate line in the cart/checkout
	 * shipping label.
	 *
	 * WooCommerce sanitises the rate label, stripping HTML. This filter runs
	 * after sanitisation and its output is rendered through wp_kses_post(),
	 * which allows <br> tags.
	 *
	 * @param string            $label  The full shipping method label (name + price).
	 * @param \WC_Shipping_Rate $method The shipping rate object.
	 * @return string Modified label with delivery date on a new line.
	 */
	public function append_delivery_date_to_label( string $label, $method ): string {
		if ( 'fk_usps_optimizer' !== $method->get_method_id() ) {
			return $label;
		}

		$meta = $method->get_meta_data();

		if ( ! empty( $meta['est_delivery_display'] ) ) {
			$label .= '<br>' . esc_html( $meta['est_delivery_display'] );
		}

		return $label;
	}

	/**
	 * Get the packing service instance.
	 *
	 * @return Packing_Service
	 */
	public function get_packing_service(): Packing_Service {
		return $this->packing_service;
	}

	/**
	 * Get the plugin settings instance.
	 *
	 * @return Settings
	 */
	public function get_settings(): Settings {
		return $this->settings;
	}

	/**
	 * Return the carrier service selected in settings.
	 *
	 * Returns the first enabled carrier service for backward compatibility.
	 *
	 * @return ShipEngine_Service|ShipStation_Service Active carrier service.
	 */
	public function get_carrier_service() {
		if ( 'shipstation' === $this->settings->get_carrier() ) {
			return $this->shipstation_service;
		}

		return $this->shipengine_service;
	}

	/**
	 * Return all carrier services enabled in settings.
	 *
	 * When ShipStation is enabled and multiple carrier+service pairs are
	 * configured, one ShipStation_Service instance is created per pair so
	 * that rates from all pairs (e.g. UPS + USPS) are compared.
	 * The primary pair uses the singleton instance; additional pairs get
	 * new instances with carrier/service code overrides.
	 *
	 * @return array<ShipEngine_Service|ShipStation_Service> Active carrier services.
	 */
	public function get_carrier_services(): array {
		$carriers = $this->settings->get_carriers();
		$services = array();

		foreach ( $carriers as $carrier ) {
			if ( 'shipengine' === $carrier ) {
				$services[] = $this->shipengine_service;
			} elseif ( 'shipstation' === $carrier ) {
				$pairs = $this->settings->get_shipstation_service_pairs();

				if ( empty( $pairs ) ) {
					// No pairs configured; use the default singleton.
					$services[] = $this->shipstation_service;
					continue;
				}

				// Group pairs by carrier_code so we can issue one API call per
				// carrier instead of one per (carrier, service) pair.  When a
				// carrier has multiple configured services we ask the API for
				// "all services" (empty service_code) and filter the response
				// to the configured services via the allow-list.
				$grouped = array();
				foreach ( $pairs as $pair ) {
					$carrier_code = (string) ( $pair['carrier_code'] ?? '' );
					$service_code = (string) ( $pair['service_code'] ?? '' );

					if ( '' === $carrier_code ) {
						continue;
					}

					if ( ! isset( $grouped[ $carrier_code ] ) ) {
						$grouped[ $carrier_code ] = array();
					}

					if ( ! in_array( $service_code, $grouped[ $carrier_code ], true ) ) {
						$grouped[ $carrier_code ][] = $service_code;
					}
				}

				$is_primary_used = false;

				foreach ( $grouped as $carrier_code => $service_codes ) {
					$has_all_services = in_array( '', $service_codes, true );
					$specific_codes   = array_values(
						array_filter(
							$service_codes,
							static function ( string $code ): bool {
								return '' !== $code;
							}
						)
					);

					// If the admin configured "all services" for this carrier,
					// drop any specific codes — the wildcard supersedes them.
					if ( $has_all_services ) {
						$effective_service_code = '';
						$allow_list             = null;
					} elseif ( count( $specific_codes ) === 1 ) {
						// Single specific service: keep the explicit code so
						// the API request is as narrow as possible.
						$effective_service_code = $specific_codes[0];
						$allow_list             = null;
					} else {
						// Multiple specific services: ask for all and filter.
						$effective_service_code = '';
						$allow_list             = $specific_codes;
					}

					// Reuse the singleton for the first carrier that matches
					// its configured carrier code; spin up new instances for
					// the rest.  This keeps existing test/mock seams working.
					$primary_carrier = $this->settings->get_shipstation_carrier_code();
					$primary_service = $this->settings->get_shipstation_service_code();

					if ( ! $is_primary_used
						&& $carrier_code === $primary_carrier
						&& $effective_service_code === $primary_service
						&& null === $allow_list
					) {
						$services[]      = $this->shipstation_service;
						$is_primary_used = true;
						continue;
					}

					$services[] = new ShipStation_Service(
						$this->settings,
						$carrier_code,
						$effective_service_code,
						$allow_list
					);
				}
			}
		}

		// Fallback to ShipEngine if nothing enabled.
		return ! empty( $services ) ? $services : array( $this->shipengine_service );
	}

	/**
	 * Build a human-readable order note summarizing the shipping plan.
	 *
	 * @param array $plan Shipping plan data.
	 * @return string Formatted note text.
	 */
	protected function build_package_note( array $plan ): string {
		$lines   = array();
		$lines[] = __( 'USPS Shipping Plan', 'fk-usps-optimizer' );
		$lines[] = sprintf(
			/* translators: 1: number of packages, 2: currency symbol and total rate. */
			__( 'Total: %1$d package(s), $%2$s', 'fk-usps-optimizer' ),
			(int) ( $plan['total_package_count'] ?? 0 ),
			number_format( (float) ( $plan['total_rate_amount'] ?? 0 ), 2 )
		);

		foreach ( $plan['packages'] ?? array() as $package ) {
			$lines[] = '';
			$lines[] = sprintf(
				/* translators: 1: package number, 2: package name, 3: mode. */
				__( 'Package %1$d: %2$s (%3$s)', 'fk-usps-optimizer' ),
				(int) $package['package_number'],
				$package['package_name'],
				$package['mode']
			);

			if ( ! empty( $package['service_label'] ) ) {
				$lines[] = sprintf(
					/* translators: %s shipping service label (e.g., "USPS Priority Mail", "UPS Ground"). */
					__( 'Service: %s', 'fk-usps-optimizer' ),
					$package['service_label']
				);
			}

			$lines[] = sprintf(
				/* translators: %s rate amount. */
				__( 'Rate: $%s', 'fk-usps-optimizer' ),
				number_format( (float) $package['rate_amount'], 2 )
			);
			$lines[] = sprintf(
				/* translators: 1: length, 2: width, 3: height. */
				__( 'Dims: %1$s x %2$s x %3$s in', 'fk-usps-optimizer' ),
				$package['dimensions']['length'],
				$package['dimensions']['width'],
				$package['dimensions']['height']
			);
			$lines[] = sprintf(
				/* translators: %s weight in ounces. */
				__( 'Weight: %s oz', 'fk-usps-optimizer' ),
				$package['weight_oz']
			);

			if ( ! empty( $package['cubic_tier'] ) ) {
				$lines[] = sprintf(
					/* translators: %s cubic tier value. */
					__( 'Cubic Tier: %s', 'fk-usps-optimizer' ),
					$package['cubic_tier']
				);
			}

			if ( ! empty( $package['packing_list'] ) ) {
				$lines[] = __( 'Items:', 'fk-usps-optimizer' ) . ' ' . implode( ', ', $package['packing_list'] );
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Store the rendered packing-plan note text on the order as private
	 * meta so it can be displayed in the admin metabox and injected into
	 * the WooCommerce REST API response (which is how PirateShip receives
	 * it). The order's stored `customer_note` column is intentionally left
	 * untouched so the plan cannot leak into customer-facing surfaces.
	 *
	 * Idempotent: each call overwrites any previously stored value.
	 *
	 * As a one-time migration for orders processed by earlier versions of
	 * this plugin, any legacy `<!-- fk-pack-start --> ... <!-- fk-pack-end -->`
	 * block found in the existing customer note is stripped and the
	 * cleaned customer note is written back.
	 *
	 * @param \WC_Order $order Order to update.
	 * @param array     $plan  Shipping plan data.
	 * @return void
	 */
	protected function store_packing_plan_note( \WC_Order $order, array $plan ): void {
		$note_text = $this->build_package_note( $plan );

		$order->update_meta_data( self::PACKING_NOTE_META_KEY, $note_text );

		// Migrate legacy data: if a previous version persisted the plan
		// inside the customer-note column wrapped in marker comments,
		// strip it now so the column holds only the customer-entered text.
		$existing = (string) $order->get_customer_note( 'edit' );
		if ( '' !== $existing && false !== strpos( $existing, self::PACKING_NOTE_START_MARKER ) ) {
			$cleaned = $this->strip_packing_plan_block( $existing );
			if ( $cleaned !== $existing ) {
				$order->set_customer_note( $cleaned );
			}
		}

		$order->save();
	}

	/**
	 * Filter callback for `woocommerce_rest_prepare_shop_order_object` that
	 * appends the stored packing-plan note text to the response's
	 * `customer_note` field so PirateShip and other REST consumers receive
	 * the plan alongside the order.
	 *
	 * The plan is appended to (not substituted for) any customer-entered
	 * note. If the setting is disabled or no plan is stored, the response
	 * is returned unchanged.
	 *
	 * @param mixed           $response Response object (typically
	 *                                  \WP_REST_Response).
	 * @param \WC_Order|mixed $order    Order object being serialised.
	 * @param mixed           $request  Originating REST request
	 *                                  (unused).
	 * @return mixed The (possibly modified) response.
	 */
	public function inject_packing_plan_into_rest_response( $response, $order, $request = null ) {
		unset( $request );

		if ( ! $this->settings->is_add_packing_to_customer_note_enabled() ) {
			return $response;
		}

		if ( ! $order instanceof \WC_Order ) {
			return $response;
		}

		if ( ! is_object( $response ) || ! isset( $response->data ) || ! is_array( $response->data ) ) {
			return $response;
		}

		$note_text = (string) $order->get_meta( self::PACKING_NOTE_META_KEY, true );
		if ( '' === trim( $note_text ) ) {
			return $response;
		}

		$existing_note = isset( $response->data['customer_note'] ) ? (string) $response->data['customer_note'] : '';

		$response->data['customer_note'] = '' === trim( $existing_note )
			? $note_text
			: rtrim( $existing_note ) . "\n\n" . $note_text;

		return $response;
	}

	/**
	 * Remove a legacy `<!-- fk-pack-start --> ... <!-- fk-pack-end -->`
	 * block from a string and tidy up the surrounding whitespace.
	 *
	 * Retained so we can clean customer-note values written by earlier
	 * versions of the plugin; new code does not produce these markers.
	 *
	 * @param string $value Source string.
	 * @return string String with the block removed.
	 */
	protected function strip_packing_plan_block( string $value ): string {
		if ( '' === $value || false === strpos( $value, self::PACKING_NOTE_START_MARKER ) ) {
			return $value;
		}

		$pattern = '/\s*' . preg_quote( self::PACKING_NOTE_START_MARKER, '/' )
			. '.*?' . preg_quote( self::PACKING_NOTE_END_MARKER, '/' ) . '\s*/s';

		$stripped = preg_replace( $pattern, '', $value );

		if ( null === $stripped ) {
			// On regex failure, fall back to the original value rather than
			// nuking the customer note.
			return $value;
		}

		return trim( $stripped );
	}

	/**
	 * Log a debug message via the WooCommerce logger.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context.
	 * @return void
	 */
	protected function log( string $message, array $context = array() ): void {
		if ( ! $this->settings->is_debug_logging_enabled() ) {
			return;
		}

		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->debug( $message . ' ' . wp_json_encode( $context ), array( 'source' => 'fk-usps-optimizer' ) );
		}
	}
}
