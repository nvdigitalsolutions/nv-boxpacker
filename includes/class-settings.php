<?php
/**
 * Settings management for the FK USPS Optimizer plugin.
 *
 * @package FK_USPS_Optimizer
 */

namespace FK_USPS_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles plugin settings registration, rendering, and retrieval.
 */
class Settings {
	const OPTION_KEY = 'fk_usps_optimizer_settings';

	/**
	 * Registers WordPress settings hooks.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue admin scripts and pass localised data on the settings page.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_scripts( string $hook_suffix ): void {
		if ( 'woocommerce_page_fk-usps-optimizer' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_script(
			'fk-usps-optimizer-settings',
			FK_USPS_OPTIMIZER_URL . 'assets/js/settings.js',
			array(),
			FK_USPS_OPTIMIZER_VERSION,
			true
		);

		wp_localize_script(
			'fk-usps-optimizer-settings',
			'fkUspsOptimizer',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'fk_usps_test_connection' ),
				'settingsKey' => self::OPTION_KEY,
				'testing'     => __( 'Testing connection\u2026', 'fk-usps-optimizer' ),
				'error'       => __( 'An unexpected error occurred. Please try again.', 'fk-usps-optimizer' ),
			)
		);
	}

	/**
	 * Registers plugin settings fields and sections.
	 */
	public function register_settings(): void {
		register_setting(
			'fk_usps_optimizer',
			self::OPTION_KEY,
			array( $this, 'sanitize_settings' )
		);

		add_settings_section(
			'fk_usps_optimizer_api',
			__( 'ShipEngine and Shipping Origin', 'fk-usps-optimizer' ),
			'__return_false',
			'fk-usps-optimizer'
		);

		$fields = array(
			'carrier'                  => __( 'Shipping Carrier API', 'fk-usps-optimizer' ),
			'service_code'             => __( 'Service Code', 'fk-usps-optimizer' ),
			'shipengine_api_key'       => __( 'ShipEngine API Key', 'fk-usps-optimizer' ),
			'shipengine_carrier_id'    => __( 'ShipEngine Carrier ID', 'fk-usps-optimizer' ),
			'shipstation_api_key'      => __( 'ShipStation API Key', 'fk-usps-optimizer' ),
			'shipstation_api_secret'   => __( 'ShipStation API Secret', 'fk-usps-optimizer' ),
			'shipstation_carrier_code' => __( 'ShipStation Carrier Code', 'fk-usps-optimizer' ),
			'sandbox_mode'             => __( 'Enable Sandbox Mode', 'fk-usps-optimizer' ),
			'show_all_options'         => __( 'Show All Package Options', 'fk-usps-optimizer' ),
			'show_package_count'       => __( 'Show Package Count', 'fk-usps-optimizer' ),
			'ship_from_name'           => __( 'Ship From Name', 'fk-usps-optimizer' ),
			'ship_from_company'        => __( 'Ship From Company', 'fk-usps-optimizer' ),
			'ship_from_phone'          => __( 'Ship From Phone', 'fk-usps-optimizer' ),
			'ship_from_address1'       => __( 'Ship From Address 1', 'fk-usps-optimizer' ),
			'ship_from_address2'       => __( 'Ship From Address 2', 'fk-usps-optimizer' ),
			'ship_from_city'           => __( 'Ship From City', 'fk-usps-optimizer' ),
			'ship_from_state'          => __( 'Ship From State', 'fk-usps-optimizer' ),
			'ship_from_postal_code'    => __( 'Ship From Postal Code', 'fk-usps-optimizer' ),
			'ship_from_country'        => __( 'Ship From Country', 'fk-usps-optimizer' ),
			'debug_logging'            => __( 'Enable Debug Logging', 'fk-usps-optimizer' ),
			'boxes_json'               => __( 'Box Definitions JSON', 'fk-usps-optimizer' ),
		);

		// Fields that belong exclusively to one carrier — the settings page JS
		// uses these CSS classes to show or hide rows when the carrier changes.
		$shipengine_only  = array( 'shipengine_api_key', 'shipengine_carrier_id' );
		$shipstation_only = array( 'shipstation_api_key', 'shipstation_api_secret', 'shipstation_carrier_code' );

		foreach ( $fields as $key => $label ) {
			$args = array( 'key' => $key );

			if ( in_array( $key, $shipengine_only, true ) ) {
				$args['class'] = 'fk-shipengine-field';
			} elseif ( in_array( $key, $shipstation_only, true ) ) {
				$args['class'] = 'fk-shipstation-field';
			}

			add_settings_field(
				$key,
				$label,
				array( $this, 'render_field' ),
				'fk-usps-optimizer',
				'fk_usps_optimizer_api',
				$args
			);
		}
	}

	/**
	 * Registers the plugin settings submenu page.
	 */
	public function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'USPS Optimizer', 'fk-usps-optimizer' ),
			__( 'USPS Optimizer', 'fk-usps-optimizer' ),
			'manage_woocommerce',
			'fk-usps-optimizer',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render a settings field.
	 *
	 * @param array $args Settings field arguments.
	 */
	public function render_field( array $args ): void {
		$key      = $args['key'];
		$settings = $this->get_settings();
		$value    = $settings[ $key ] ?? '';

		if ( 'carrier' === $key ) {
			printf(
				'<select id="%1$s_%2$s" name="%1$s[%2$s]">' .
				'<option value="shipengine"%3$s>%4$s</option>' .
				'<option value="shipstation"%5$s>%6$s</option>' .
				'</select>',
				esc_attr( self::OPTION_KEY ),
				esc_attr( $key ),
				selected( 'shipengine', (string) $value, false ),
				esc_html__( 'ShipEngine (primary — USPS via stamps_com)', 'fk-usps-optimizer' ),
				selected( 'shipstation', (string) $value, false ),
				esc_html__( 'ShipStation', 'fk-usps-optimizer' )
			);
			return;
		}

		$checkbox_fields = array( 'debug_logging', 'sandbox_mode', 'show_all_options', 'show_package_count' );
		if ( in_array( $key, $checkbox_fields, true ) ) {
			if ( 'debug_logging' === $key ) {
				$label = esc_html__( 'Write API and packing errors to WooCommerce logger.', 'fk-usps-optimizer' );
			} elseif ( 'show_all_options' === $key ) {
				$label = esc_html__( 'Display all available shipping options at checkout instead of only the cheapest.', 'fk-usps-optimizer' );
			} elseif ( 'show_package_count' === $key ) {
				$label = esc_html__( 'Append the number of packages to the shipping label, e.g. "Packages (2)".', 'fk-usps-optimizer' );
			} else {
				$label = esc_html__( 'Use sandbox / test credentials. Enter a TEST_-prefixed ShipEngine API key to route requests to the sandbox environment.', 'fk-usps-optimizer' );
			}

			printf(
				'<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /> %4$s</label>',
				esc_attr( self::OPTION_KEY ),
				esc_attr( $key ),
				checked( '1', (string) $value, false ),
				$label // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already sanitized by esc_html__() above.
			);
			return;
		}

		if ( 'shipengine_carrier_id' === $key ) {
			printf(
				'<input class="regular-text" type="text" name="%1$s[%2$s]" value="%3$s" />' .
				'<p class="description">%4$s</p>',
				esc_attr( self::OPTION_KEY ),
				esc_attr( $key ),
				esc_attr( $value ),
				esc_html__( 'Your ShipEngine USPS carrier ID (e.g. se-123456). Use the "Test Connection" button below to verify.', 'fk-usps-optimizer' )
			);
			return;
		}

		if ( 'service_code' === $key ) {
			printf(
				'<input class="regular-text" type="text" name="%1$s[%2$s]" value="%3$s" />' .
				'<p class="description">%4$s</p>',
				esc_attr( self::OPTION_KEY ),
				esc_attr( $key ),
				esc_attr( $value ),
				esc_html__( 'Carrier service code used for rate requests (e.g. usps_priority_mail, usps_first_class_mail, usps_ground_advantage). Leave empty to retrieve all available services.', 'fk-usps-optimizer' )
			);
			return;
		}

		if ( 'boxes_json' === $key ) {
			printf(
				'<textarea class="large-text code" rows="16" name="%1$s[%2$s]">%3$s</textarea><p class="description">%4$s</p>',
				esc_attr( self::OPTION_KEY ),
				esc_attr( $key ),
				esc_textarea( $value ? $value : wp_json_encode( $this->get_default_boxes(), JSON_PRETTY_PRINT ) ),
				esc_html__( 'Configure custom cubic boxes and USPS flat rate boxes. Use box_type values of cubic or flat_rate.', 'fk-usps-optimizer' )
			);
			return;
		}

		printf(
			'<input class="regular-text" type="text" name="%1$s[%2$s]" value="%3$s" />',
			esc_attr( self::OPTION_KEY ),
			esc_attr( $key ),
			esc_attr( $value )
		);
	}

	/**
	 * Renders the plugin settings admin page.
	 */
	public function render_page(): void {
		$test_result = get_transient( 'fk_usps_test_connection_result' );
		if ( false !== $test_result ) {
			delete_transient( 'fk_usps_test_connection_result' );
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'FunnelKit USPS Priority Shipping Optimizer', 'fk-usps-optimizer' ); ?></h1>

			<?php if ( is_array( $test_result ) ) : ?>
			<div class="notice <?php echo $test_result['success'] ? 'notice-success' : 'notice-error'; ?> is-dismissible">
				<p><?php echo esc_html( $test_result['message'] ); ?></p>
			</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'fk_usps_optimizer' );
				do_settings_sections( 'fk-usps-optimizer' );
				submit_button();
				?>
			</form>

			<div id="fk-usps-test-connection" style="display:none">
			<hr />
			<h2><?php echo esc_html__( 'Test Carrier API Connection', 'fk-usps-optimizer' ); ?></h2>
			<p class="description">
				<?php echo esc_html__( 'Verifies your carrier API credentials are valid and that the configured carrier account is active. Save your settings before running this test.', 'fk-usps-optimizer' ); ?>
			</p>
			<div id="fk-usps-test-result" class="notice inline" style="display:none"><p></p></div>
			<button type="button" id="fk-usps-test-btn" class="button button-secondary">
				<?php echo esc_html__( 'Test Connection', 'fk-usps-optimizer' ); ?>
			</button>
		</div>
		</div>
		<?php
	}

	/**
	 * Sanitize settings input.
	 *
	 * @param array $input Raw settings input.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( array $input ): array {
		$output = array();

		$string_fields = array(
			'service_code',
			'shipengine_api_key',
			'shipengine_carrier_id',
			'shipstation_api_key',
			'shipstation_api_secret',
			'shipstation_carrier_code',
			'ship_from_name',
			'ship_from_company',
			'ship_from_phone',
			'ship_from_address1',
			'ship_from_address2',
			'ship_from_city',
			'ship_from_state',
			'ship_from_postal_code',
			'ship_from_country',
		);

		foreach ( $string_fields as $field ) {
			$output[ $field ] = isset( $input[ $field ] ) ? sanitize_text_field( (string) $input[ $field ] ) : '';
		}

		$output['carrier']            = in_array( ( $input['carrier'] ?? '' ), array( 'shipengine', 'shipstation' ), true ) ? $input['carrier'] : 'shipengine';
		$output['debug_logging']      = empty( $input['debug_logging'] ) ? '0' : '1';
		$output['sandbox_mode']       = empty( $input['sandbox_mode'] ) ? '0' : '1';
		$output['show_all_options']   = empty( $input['show_all_options'] ) ? '0' : '1';
		$output['show_package_count'] = empty( $input['show_package_count'] ) ? '0' : '1';
		$output['boxes_json']         = $this->sanitize_boxes_json( $input['boxes_json'] ?? '' );

		return $output;
	}

	/**
	 * Sanitize the boxes JSON string.
	 *
	 * @param string $raw_json Raw JSON string.
	 * @return string Sanitized JSON string.
	 */
	protected function sanitize_boxes_json( string $raw_json ): string {
		$decoded = json_decode( wp_unslash( $raw_json ), true );

		if ( ! is_array( $decoded ) ) {
			add_settings_error( self::OPTION_KEY, 'invalid_boxes_json', __( 'Box definitions JSON is invalid. Keeping default boxes.', 'fk-usps-optimizer' ) );
			return wp_json_encode( $this->get_default_boxes() );
		}

		$boxes = array();

		foreach ( $decoded as $box ) {
			if ( ! is_array( $box ) ) {
				continue;
			}

			$boxes[] = array(
				'reference'    => sanitize_text_field( (string) ( $box['reference'] ?? '' ) ),
				'package_code' => sanitize_text_field( (string) ( $box['package_code'] ?? 'package' ) ),
				'package_name' => sanitize_text_field( (string) ( $box['package_name'] ?? '' ) ),
				'box_type'     => in_array( ( $box['box_type'] ?? '' ), array( 'cubic', 'flat_rate' ), true ) ? $box['box_type'] : 'cubic',
				'outer_width'  => absint( $box['outer_width'] ?? 0 ),
				'outer_length' => absint( $box['outer_length'] ?? 0 ),
				'outer_depth'  => absint( $box['outer_depth'] ?? 0 ),
				'inner_width'  => absint( $box['inner_width'] ?? 0 ),
				'inner_length' => absint( $box['inner_length'] ?? 0 ),
				'inner_depth'  => absint( $box['inner_depth'] ?? 0 ),
				'empty_weight' => absint( $box['empty_weight'] ?? 0 ),
				'max_weight'   => absint( $box['max_weight'] ?? 0 ),
			);
		}

		return wp_json_encode( $boxes );
	}

	/**
	 * Get plugin settings merged with defaults.
	 *
	 * @return array Plugin settings merged with defaults.
	 */
	public function get_settings(): array {
		$saved = get_option( self::OPTION_KEY, array() );

		return wp_parse_args(
			$saved,
			array(
				'carrier'                  => 'shipengine',
				'service_code'             => 'usps_priority_mail',
				'shipengine_api_key'       => '',
				'shipengine_carrier_id'    => '',
				'shipstation_api_key'      => '',
				'shipstation_api_secret'   => '',
				'shipstation_carrier_code' => 'stamps_com',
				'sandbox_mode'             => '0',
				'show_all_options'         => '0',
				'show_package_count'       => '0',
				'ship_from_name'           => '',
				'ship_from_company'        => '',
				'ship_from_phone'          => '',
				'ship_from_address1'       => '',
				'ship_from_address2'       => '',
				'ship_from_city'           => '',
				'ship_from_state'          => '',
				'ship_from_postal_code'    => '',
				'ship_from_country'        => 'US',
				'debug_logging'            => '0',
				'boxes_json'               => wp_json_encode( $this->get_default_boxes() ),
			)
		);
	}

	/**
	 * Get configured box definitions.
	 *
	 * @return array Array of box definitions.
	 */
	public function get_boxes(): array {
		$settings = $this->get_settings();
		$boxes    = json_decode( $settings['boxes_json'], true );

		return is_array( $boxes ) ? apply_filters( 'fk_usps_optimizer_boxes', $boxes ) : $this->get_default_boxes();
	}

	/**
	 * Get the ship-from address in ShipEngine format.
	 *
	 * @return array ShipEngine-formatted ship-from address.
	 */
	public function get_ship_from_address(): array {
		$settings = $this->get_settings();

		return apply_filters(
			'fk_usps_optimizer_ship_from_address',
			array(
				'name'                          => $settings['ship_from_name'],
				'company_name'                  => $settings['ship_from_company'],
				'phone'                         => $settings['ship_from_phone'],
				'address_line1'                 => $settings['ship_from_address1'],
				'address_line2'                 => $settings['ship_from_address2'],
				'city_locality'                 => $settings['ship_from_city'],
				'state_province'                => $settings['ship_from_state'],
				'postal_code'                   => $settings['ship_from_postal_code'],
				'country_code'                  => $settings['ship_from_country'],
				'address_residential_indicator' => 'no',
			)
		);
	}

	/**
	 * Get the ShipEngine API key.
	 *
	 * @return string ShipEngine API key.
	 */
	public function get_shipengine_api_key(): string {
		$settings = $this->get_settings();
		return (string) apply_filters( 'fk_usps_optimizer_shipengine_api_key', $settings['shipengine_api_key'] );
	}

	/**
	 * Get the ShipEngine carrier ID.
	 *
	 * @return string ShipEngine carrier ID.
	 */
	public function get_shipengine_carrier_id(): string {
		$settings = $this->get_settings();
		return (string) apply_filters( 'fk_usps_optimizer_shipengine_carrier_id', $settings['shipengine_carrier_id'] );
	}

	/**
	 * Check whether debug logging is enabled.
	 *
	 * @return bool Whether debug logging is enabled.
	 */
	public function is_debug_logging_enabled(): bool {
		$settings = $this->get_settings();
		return '1' === (string) $settings['debug_logging'];
	}

	/**
	 * Get the configured shipping carrier API.
	 *
	 * @return string 'shipengine' or 'shipstation'.
	 */
	public function get_carrier(): string {
		$settings = $this->get_settings();
		return (string) apply_filters( 'fk_usps_optimizer_carrier', $settings['carrier'] );
	}

	/**
	 * Get the configured shipping service code.
	 *
	 * @return string Service code (e.g. 'usps_priority_mail').
	 */
	public function get_service_code(): string {
		$settings = $this->get_settings();
		return (string) apply_filters( 'fk_usps_optimizer_service_code', $settings['service_code'] );
	}

	/**
	 * Get the ShipStation API key.
	 *
	 * @return string ShipStation API key.
	 */
	public function get_shipstation_api_key(): string {
		$settings = $this->get_settings();
		return (string) apply_filters( 'fk_usps_optimizer_shipstation_api_key', $settings['shipstation_api_key'] );
	}

	/**
	 * Get the ShipStation API secret.
	 *
	 * @return string ShipStation API secret.
	 */
	public function get_shipstation_api_secret(): string {
		$settings = $this->get_settings();
		return (string) apply_filters( 'fk_usps_optimizer_shipstation_api_secret', $settings['shipstation_api_secret'] );
	}

	/**
	 * Get the ShipStation carrier code.
	 *
	 * @return string ShipStation carrier code (e.g. 'stamps_com').
	 */
	public function get_shipstation_carrier_code(): string {
		$settings = $this->get_settings();
		return (string) apply_filters( 'fk_usps_optimizer_shipstation_carrier_code', $settings['shipstation_carrier_code'] );
	}

	/**
	 * Check whether sandbox mode is enabled.
	 *
	 * When active, rate requests are marked as sandbox in logs and the admin UI
	 * displays a warning banner.
	 *
	 * @return bool Whether sandbox mode is enabled.
	 */
	public function is_sandbox_mode_enabled(): bool {
		$settings = $this->get_settings();
		return '1' === (string) $settings['sandbox_mode'];
	}

	/**
	 * Check whether all shipping options should be displayed at checkout.
	 *
	 * When enabled, all rated box candidates are shown as separate shipping
	 * options instead of only the cheapest.
	 *
	 * @return bool Whether to show all package options.
	 */
	public function is_show_all_options_enabled(): bool {
		$settings = $this->get_settings();
		return '1' === (string) $settings['show_all_options'];
	}

	/**
	 * Check whether the package count should be appended to the shipping label.
	 *
	 * When enabled, labels display the number of packages, e.g. "Packages (2)".
	 *
	 * @return bool Whether to show the package count.
	 */
	public function is_show_package_count_enabled(): bool {
		$settings = $this->get_settings();
		return '1' === (string) $settings['show_package_count'];
	}

	/**
	 * Get the default box definitions.
	 *
	 * @return array Default box definitions.
	 */
	protected function get_default_boxes(): array {
		return array(
			array(
				'reference'    => 'Cubic Small',
				'package_code' => 'package',
				'package_name' => 'Custom Cubic Small',
				'box_type'     => 'cubic',
				'outer_width'  => 8,
				'outer_length' => 8,
				'outer_depth'  => 6,
				'inner_width'  => 8,
				'inner_length' => 8,
				'inner_depth'  => 6,
				'empty_weight' => 3,
				'max_weight'   => 20,
			),
			array(
				'reference'    => 'Cubic Medium',
				'package_code' => 'package',
				'package_name' => 'Custom Cubic Medium',
				'box_type'     => 'cubic',
				'outer_width'  => 12,
				'outer_length' => 10,
				'outer_depth'  => 8,
				'inner_width'  => 12,
				'inner_length' => 10,
				'inner_depth'  => 8,
				'empty_weight' => 5,
				'max_weight'   => 20,
			),
			array(
				'reference'    => 'USPS Small Flat Rate',
				'package_code' => 'small_flat_rate_box',
				'package_name' => 'USPS Small Flat Rate Box',
				'box_type'     => 'flat_rate',
				'outer_width'  => 9,
				'outer_length' => 6,
				'outer_depth'  => 2,
				'inner_width'  => 9,
				'inner_length' => 6,
				'inner_depth'  => 2,
				'empty_weight' => 4,
				'max_weight'   => 70,
			),
			array(
				'reference'    => 'USPS Medium Flat Rate',
				'package_code' => 'medium_flat_rate_box',
				'package_name' => 'USPS Medium Flat Rate Box',
				'box_type'     => 'flat_rate',
				'outer_width'  => 14,
				'outer_length' => 12,
				'outer_depth'  => 3,
				'inner_width'  => 14,
				'inner_length' => 12,
				'inner_depth'  => 3,
				'empty_weight' => 6,
				'max_weight'   => 70,
			),
			array(
				'reference'    => 'USPS Large Flat Rate',
				'package_code' => 'large_flat_rate_box',
				'package_name' => 'USPS Large Flat Rate Box',
				'box_type'     => 'flat_rate',
				'outer_width'  => 12,
				'outer_length' => 12,
				'outer_depth'  => 6,
				'inner_width'  => 12,
				'inner_length' => 12,
				'inner_depth'  => 6,
				'empty_weight' => 8,
				'max_weight'   => 70,
			),
		);
	}
}
