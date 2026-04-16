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
			'carrier'                   => __( 'Enabled Carrier APIs', 'fk-usps-optimizer' ),
			'shipengine_api_key'        => __( 'ShipEngine API Key', 'fk-usps-optimizer' ),
			'shipengine_carrier_id'     => __( 'ShipEngine Carrier ID', 'fk-usps-optimizer' ),
			'shipengine_service_code'   => __( 'ShipEngine Service Code', 'fk-usps-optimizer' ),
			'shipstation_api_key'       => __( 'ShipStation API Key', 'fk-usps-optimizer' ),
			'shipstation_api_secret'    => __( 'ShipStation API Secret', 'fk-usps-optimizer' ),
			'shipstation_carrier_code'  => __( 'ShipStation Carrier Code', 'fk-usps-optimizer' ),
			'shipstation_service_code'  => __( 'ShipStation Service Code', 'fk-usps-optimizer' ),
			'shipstation_services_json' => __( 'ShipStation Additional Services', 'fk-usps-optimizer' ),
			'sandbox_mode'              => __( 'Enable Sandbox Mode', 'fk-usps-optimizer' ),
			'show_all_options'          => __( 'Show All Options', 'fk-usps-optimizer' ),
			'show_package_count'        => __( 'Show Package Count', 'fk-usps-optimizer' ),
			'add_package_note'          => __( 'Add Package Suggestion to Order Notes', 'fk-usps-optimizer' ),
			'show_estimated_delivery'   => __( 'Show Estimated Delivery Date', 'fk-usps-optimizer' ),
			'use_default_transit_days'  => __( 'Use Default Transit Day Estimates', 'fk-usps-optimizer' ),
			'transit_days_buffer'       => __( 'Additional Business Days', 'fk-usps-optimizer' ),
			'ship_from_name'            => __( 'Ship From Name', 'fk-usps-optimizer' ),
			'ship_from_company'         => __( 'Ship From Company', 'fk-usps-optimizer' ),
			'ship_from_phone'           => __( 'Ship From Phone', 'fk-usps-optimizer' ),
			'ship_from_address1'        => __( 'Ship From Address 1', 'fk-usps-optimizer' ),
			'ship_from_address2'        => __( 'Ship From Address 2', 'fk-usps-optimizer' ),
			'ship_from_city'            => __( 'Ship From City', 'fk-usps-optimizer' ),
			'ship_from_state'           => __( 'Ship From State', 'fk-usps-optimizer' ),
			'ship_from_postal_code'     => __( 'Ship From Postal Code', 'fk-usps-optimizer' ),
			'ship_from_country'         => __( 'Ship From Country', 'fk-usps-optimizer' ),
			'debug_logging'             => __( 'Enable Debug Logging', 'fk-usps-optimizer' ),
			'boxes_table'               => __( 'Box Definitions', 'fk-usps-optimizer' ),
		);

		// Fields that belong exclusively to one carrier — the settings page JS
		// uses these CSS classes to show or hide rows when the carrier changes.
		$shipengine_only  = array( 'shipengine_api_key', 'shipengine_carrier_id', 'shipengine_service_code' );
		$shipstation_only = array( 'shipstation_api_key', 'shipstation_api_secret', 'shipstation_carrier_code', 'shipstation_service_code', 'shipstation_services_json' );

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
			$enabled = $this->get_carriers();
			printf(
				'<fieldset id="%1$s_%2$s">' .
				'<label><input type="checkbox" name="%1$s[%2$s][]" value="shipengine" %3$s /> %4$s</label><br />' .
				'<label><input type="checkbox" name="%1$s[%2$s][]" value="shipstation" %5$s /> %6$s</label>' .
				'<p class="description">%7$s</p>' .
				'</fieldset>',
				esc_attr( self::OPTION_KEY ),
				esc_attr( $key ),
				checked( in_array( 'shipengine', $enabled, true ), true, false ),
				esc_html__( 'ShipEngine (e.g. USPS via stamps_com)', 'fk-usps-optimizer' ),
				checked( in_array( 'shipstation', $enabled, true ), true, false ),
				esc_html__( 'ShipStation', 'fk-usps-optimizer' ),
				esc_html__( 'Enable one or more carrier APIs. Rates from all enabled carriers are compared and the cheapest option is used.', 'fk-usps-optimizer' )
			);
			return;
		}

		$checkbox_fields = array(
			'debug_logging'            => esc_html__( 'Write API and packing errors to WooCommerce logger.', 'fk-usps-optimizer' ),
			'sandbox_mode'             => esc_html__( 'Use sandbox / test credentials. Enter a TEST_-prefixed ShipEngine API key to route requests to the sandbox environment.', 'fk-usps-optimizer' ),
			'show_all_options'         => esc_html__( 'Display all rated box candidates as separate shipping options (cartesian product of packages).', 'fk-usps-optimizer' ),
			'show_package_count'       => esc_html__( 'Append the package count to each shipping option label.', 'fk-usps-optimizer' ),
			'add_package_note'         => esc_html__( 'Add the suggested package plan to the WooCommerce order notes after checkout.', 'fk-usps-optimizer' ),
			'show_estimated_delivery'  => esc_html__( 'Display the carrier-provided estimated delivery date on the checkout shipping options (including FunnelKit Checkout).', 'fk-usps-optimizer' ),
			'use_default_transit_days' => esc_html__( 'When the carrier API does not return delivery-date information, use built-in service-code estimates (e.g. Priority Mail = 3 days). When unchecked, shows "(No Estimate)".', 'fk-usps-optimizer' ),
		);

		if ( isset( $checkbox_fields[ $key ] ) ) {
			printf(
				'<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /> %4$s</label>',
				esc_attr( self::OPTION_KEY ),
				esc_attr( $key ),
				checked( '1', (string) $value, false ),
				$checkbox_fields[ $key ] // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already sanitized by esc_html__() above.
			);
			return;
		}

		if ( 'transit_days_buffer' === $key ) {
			printf(
				'<input type="number" min="0" max="30" step="1" name="%1$s[%2$s]" value="%3$s" class="small-text" />' .
				'<p class="description">%4$s</p>',
				esc_attr( self::OPTION_KEY ),
				esc_attr( $key ),
				esc_attr( $value ),
				esc_html__( 'Extra business days added to every estimated delivery date (e.g. for order processing / handling time). Applies to both carrier-returned and default transit-day estimates.', 'fk-usps-optimizer' )
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

		if ( 'shipengine_service_code' === $key ) {
			printf(
				'<input class="regular-text" type="text" name="%1$s[%2$s]" value="%3$s" />' .
				'<p class="description">%4$s</p>',
				esc_attr( self::OPTION_KEY ),
				esc_attr( $key ),
				esc_attr( $value ),
				esc_html__( 'ShipEngine service code (e.g. usps_priority_mail). Supports flat-rate and cubic pricing.', 'fk-usps-optimizer' )
			);
			return;
		}

		if ( 'shipstation_service_code' === $key ) {
			printf(
				'<input class="regular-text" type="text" name="%1$s[%2$s]" value="%3$s" />' .
				'<p class="description">%4$s</p>',
				esc_attr( self::OPTION_KEY ),
				esc_attr( $key ),
				esc_attr( $value ),
				esc_html__( 'ShipStation service code (e.g. usps_priority_mail). Leave empty to match any service from the carrier.', 'fk-usps-optimizer' )
			);
			return;
		}

		if ( 'shipstation_services_json' === $key ) {
			$default_services = wp_json_encode(
				array(
					array(
						'carrier_code' => 'stamps_com',
						'service_code' => 'usps_priority_mail',
					),
				),
				JSON_PRETTY_PRINT
			);
			printf(
				'<textarea class="large-text code" rows="8" name="%1$s[%2$s]">%3$s</textarea>' .
				'<p class="description">%4$s</p>',
				esc_attr( self::OPTION_KEY ),
				esc_attr( $key ),
				esc_textarea( $value ? $value : $default_services ),
				esc_html__( 'Optional JSON array of additional ShipStation carrier+service pairs to rate-shop. Each entry needs "carrier_code" and "service_code". Example: [{"carrier_code":"ups_walleted","service_code":"ups_ground"},{"carrier_code":"stamps_com","service_code":"usps_priority_mail"}]. Rates from all pairs plus the primary pair above are compared.', 'fk-usps-optimizer' )
			);
			return;
		}

		if ( 'boxes_table' === $key ) {
			// The UI key is 'boxes_table' but data is stored as 'boxes_json'.
			$json  = $settings['boxes_json'] ?? '';
			$boxes = $json ? json_decode( $json, true ) : null;
			if ( ! is_array( $boxes ) ) {
				$boxes = $this->get_default_boxes();
			}
			$opt_key = self::OPTION_KEY;
			?>
			<table class="widefat fk-boxes-table" id="fk-boxes-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Reference', 'fk-usps-optimizer' ); ?></th>
						<th><?php esc_html_e( 'Package Code', 'fk-usps-optimizer' ); ?></th>
						<th><?php esc_html_e( 'Name', 'fk-usps-optimizer' ); ?></th>
						<th><?php esc_html_e( 'Type', 'fk-usps-optimizer' ); ?></th>
						<th><?php esc_html_e( 'L', 'fk-usps-optimizer' ); ?></th>
						<th><?php esc_html_e( 'W', 'fk-usps-optimizer' ); ?></th>
						<th><?php esc_html_e( 'H', 'fk-usps-optimizer' ); ?></th>
						<th><?php esc_html_e( 'Inner L', 'fk-usps-optimizer' ); ?></th>
						<th><?php esc_html_e( 'Inner W', 'fk-usps-optimizer' ); ?></th>
						<th><?php esc_html_e( 'Inner H', 'fk-usps-optimizer' ); ?></th>
						<th><?php esc_html_e( 'Tare (oz)', 'fk-usps-optimizer' ); ?></th>
						<th><?php esc_html_e( 'Max Wt (lbs)', 'fk-usps-optimizer' ); ?></th>
						<th><?php esc_html_e( 'Carrier', 'fk-usps-optimizer' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
			<?php foreach ( $boxes as $i => $box ) : ?>
					<tr>
						<td><input type="text" name="<?php echo esc_attr( $opt_key ); ?>[boxes][<?php echo (int) $i; ?>][reference]" value="<?php echo esc_attr( $box['reference'] ?? '' ); ?>" class="regular-text" /></td>
						<td><input type="text" name="<?php echo esc_attr( $opt_key ); ?>[boxes][<?php echo (int) $i; ?>][package_code]" value="<?php echo esc_attr( $box['package_code'] ?? 'package' ); ?>" class="small-text" /></td>
						<td><input type="text" name="<?php echo esc_attr( $opt_key ); ?>[boxes][<?php echo (int) $i; ?>][package_name]" value="<?php echo esc_attr( $box['package_name'] ?? '' ); ?>" class="regular-text" /></td>
						<td>
							<select name="<?php echo esc_attr( $opt_key ); ?>[boxes][<?php echo (int) $i; ?>][box_type]">
								<option value="cubic" <?php selected( $box['box_type'] ?? 'cubic', 'cubic' ); ?>><?php esc_html_e( 'Cubic', 'fk-usps-optimizer' ); ?></option>
								<option value="flat_rate" <?php selected( $box['box_type'] ?? '', 'flat_rate' ); ?>><?php esc_html_e( 'Flat Rate', 'fk-usps-optimizer' ); ?></option>
							</select>
						</td>
						<td><input type="number" step="0.01" min="0" name="<?php echo esc_attr( $opt_key ); ?>[boxes][<?php echo (int) $i; ?>][outer_length]" value="<?php echo esc_attr( $box['outer_length'] ?? 0 ); ?>" class="small-text" /></td>
						<td><input type="number" step="0.01" min="0" name="<?php echo esc_attr( $opt_key ); ?>[boxes][<?php echo (int) $i; ?>][outer_width]" value="<?php echo esc_attr( $box['outer_width'] ?? 0 ); ?>" class="small-text" /></td>
						<td><input type="number" step="0.01" min="0" name="<?php echo esc_attr( $opt_key ); ?>[boxes][<?php echo (int) $i; ?>][outer_depth]" value="<?php echo esc_attr( $box['outer_depth'] ?? 0 ); ?>" class="small-text" /></td>
						<td><input type="number" step="0.01" min="0" name="<?php echo esc_attr( $opt_key ); ?>[boxes][<?php echo (int) $i; ?>][inner_length]" value="<?php echo esc_attr( $box['inner_length'] ?? 0 ); ?>" class="small-text" /></td>
						<td><input type="number" step="0.01" min="0" name="<?php echo esc_attr( $opt_key ); ?>[boxes][<?php echo (int) $i; ?>][inner_width]" value="<?php echo esc_attr( $box['inner_width'] ?? 0 ); ?>" class="small-text" /></td>
						<td><input type="number" step="0.01" min="0" name="<?php echo esc_attr( $opt_key ); ?>[boxes][<?php echo (int) $i; ?>][inner_depth]" value="<?php echo esc_attr( $box['inner_depth'] ?? 0 ); ?>" class="small-text" /></td>
						<td><input type="number" step="0.01" min="0" name="<?php echo esc_attr( $opt_key ); ?>[boxes][<?php echo (int) $i; ?>][empty_weight]" value="<?php echo esc_attr( $box['empty_weight'] ?? 0 ); ?>" class="small-text" /></td>
						<td><input type="number" step="0.01" min="0" name="<?php echo esc_attr( $opt_key ); ?>[boxes][<?php echo (int) $i; ?>][max_weight]" value="<?php echo esc_attr( $box['max_weight'] ?? 0 ); ?>" class="small-text" /></td>
						<td>
							<select name="<?php echo esc_attr( $opt_key ); ?>[boxes][<?php echo (int) $i; ?>][carrier_restriction]">
								<option value="" <?php selected( $box['carrier_restriction'] ?? '', '' ); ?>><?php esc_html_e( 'Any', 'fk-usps-optimizer' ); ?></option>
								<option value="usps" <?php selected( $box['carrier_restriction'] ?? '', 'usps' ); ?>>USPS</option>
								<option value="ups" <?php selected( $box['carrier_restriction'] ?? '', 'ups' ); ?>>UPS</option>
								<option value="fedex" <?php selected( $box['carrier_restriction'] ?? '', 'fedex' ); ?>>FedEx</option>
							</select>
						</td>
						<td><button type="button" class="button fk-remove-box">&times;</button></td>
					</tr>
			<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr>
						<td colspan="14">
							<button type="button" class="button button-secondary" id="fk-add-box"><?php esc_html_e( 'Add Box', 'fk-usps-optimizer' ); ?></button>
						</td>
					</tr>
				</tfoot>
			</table>
			<p class="description"><?php esc_html_e( 'Add, edit or remove box definitions. Dimensions are in inches, tare weight in ounces, max weight in pounds. Use the Carrier column to restrict a box to a specific carrier (e.g. USPS Flat Rate boxes).', 'fk-usps-optimizer' ); ?></p>
			<?php
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
			<div class="notice <?php echo esc_attr( $test_result['success'] ? 'notice-success' : 'notice-error' ); ?> is-dismissible">
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
			'shipengine_api_key',
			'shipengine_carrier_id',
			'shipengine_service_code',
			'shipstation_api_key',
			'shipstation_api_secret',
			'shipstation_carrier_code',
			'shipstation_service_code',
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

		// Carrier now supports multiple values as an array of checkboxes.
		$valid_carriers    = array( 'shipengine', 'shipstation' );
		$raw_carrier       = $input['carrier'] ?? array();
		$selected_carriers = array();

		if ( is_array( $raw_carrier ) ) {
			foreach ( $raw_carrier as $c ) {
				if ( in_array( (string) $c, $valid_carriers, true ) ) {
					$selected_carriers[] = (string) $c;
				}
			}
		} elseif ( is_string( $raw_carrier ) ) {
			// Backward compat: accept a single string value or comma-separated.
			foreach ( explode( ',', $raw_carrier ) as $c ) {
				$c = trim( $c );
				if ( in_array( $c, $valid_carriers, true ) ) {
					$selected_carriers[] = $c;
				}
			}
		}

		$output['carrier']                   = ! empty( $selected_carriers ) ? implode( ',', array_unique( $selected_carriers ) ) : 'shipengine';
		$output['debug_logging']             = empty( $input['debug_logging'] ) ? '0' : '1';
		$output['sandbox_mode']              = empty( $input['sandbox_mode'] ) ? '0' : '1';
		$output['show_all_options']          = empty( $input['show_all_options'] ) ? '0' : '1';
		$output['show_package_count']        = empty( $input['show_package_count'] ) ? '0' : '1';
		$output['add_package_note']          = empty( $input['add_package_note'] ) ? '0' : '1';
		$output['show_estimated_delivery']   = empty( $input['show_estimated_delivery'] ) ? '0' : '1';
		$output['use_default_transit_days']  = empty( $input['use_default_transit_days'] ) ? '0' : '1';
		$output['transit_days_buffer']       = max( 0, min( 30, (int) ( $input['transit_days_buffer'] ?? 0 ) ) );
		$output['shipstation_services_json'] = $this->sanitize_shipstation_services_json( $input['shipstation_services_json'] ?? '' );

		// Accept boxes from the new table UI (array of rows) or fall back to
		// the legacy JSON textarea value for backward compatibility.
		if ( ! empty( $input['boxes'] ) && is_array( $input['boxes'] ) ) {
			$output['boxes_json'] = $this->sanitize_boxes_array( $input['boxes'] );
		} else {
			$output['boxes_json'] = $this->sanitize_boxes_json( $input['boxes_json'] ?? '' );
		}

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
				'reference'           => sanitize_text_field( (string) ( $box['reference'] ?? '' ) ),
				'package_code'        => sanitize_text_field( (string) ( $box['package_code'] ?? 'package' ) ),
				'package_name'        => sanitize_text_field( (string) ( $box['package_name'] ?? '' ) ),
				'box_type'            => in_array( ( $box['box_type'] ?? '' ), array( 'cubic', 'flat_rate' ), true ) ? $box['box_type'] : 'cubic',
				'outer_width'         => abs( (float) ( $box['outer_width'] ?? 0 ) ),
				'outer_length'        => abs( (float) ( $box['outer_length'] ?? 0 ) ),
				'outer_depth'         => abs( (float) ( $box['outer_depth'] ?? 0 ) ),
				'inner_width'         => abs( (float) ( $box['inner_width'] ?? 0 ) ),
				'inner_length'        => abs( (float) ( $box['inner_length'] ?? 0 ) ),
				'inner_depth'         => abs( (float) ( $box['inner_depth'] ?? 0 ) ),
				'empty_weight'        => abs( (float) ( $box['empty_weight'] ?? 0 ) ),
				'max_weight'          => abs( (float) ( $box['max_weight'] ?? 0 ) ),
				'carrier_restriction' => sanitize_text_field( (string) ( $box['carrier_restriction'] ?? '' ) ),
			);
		}

		return wp_json_encode( $boxes );
	}

	/**
	 * Sanitize boxes submitted from the table-based UI.
	 *
	 * Each element in the array corresponds to a row from the HTML table.
	 * The format is identical to the JSON schema but arrives pre-decoded.
	 *
	 * @param array $rows Array of box row arrays from form POST data.
	 * @return string Sanitized JSON string.
	 */
	protected function sanitize_boxes_array( array $rows ): string {
		$boxes = array();

		foreach ( $rows as $box ) {
			if ( ! is_array( $box ) ) {
				continue;
			}

			// Skip rows where the user blanked out the reference (treated as deleted).
			$reference = sanitize_text_field( (string) ( $box['reference'] ?? '' ) );
			if ( '' === $reference ) {
				continue;
			}

			$boxes[] = array(
				'reference'           => $reference,
				'package_code'        => sanitize_text_field( (string) ( $box['package_code'] ?? 'package' ) ),
				'package_name'        => sanitize_text_field( (string) ( $box['package_name'] ?? '' ) ),
				'box_type'            => in_array( ( $box['box_type'] ?? '' ), array( 'cubic', 'flat_rate' ), true ) ? $box['box_type'] : 'cubic',
				'outer_width'         => abs( (float) ( $box['outer_width'] ?? 0 ) ),
				'outer_length'        => abs( (float) ( $box['outer_length'] ?? 0 ) ),
				'outer_depth'         => abs( (float) ( $box['outer_depth'] ?? 0 ) ),
				'inner_width'         => abs( (float) ( $box['inner_width'] ?? 0 ) ),
				'inner_length'        => abs( (float) ( $box['inner_length'] ?? 0 ) ),
				'inner_depth'         => abs( (float) ( $box['inner_depth'] ?? 0 ) ),
				'empty_weight'        => abs( (float) ( $box['empty_weight'] ?? 0 ) ),
				'max_weight'          => abs( (float) ( $box['max_weight'] ?? 0 ) ),
				'carrier_restriction' => sanitize_text_field( (string) ( $box['carrier_restriction'] ?? '' ) ),
			);
		}

		if ( empty( $boxes ) ) {
			return wp_json_encode( $this->get_default_boxes() );
		}

		return wp_json_encode( $boxes );
	}

	/**
	 * Sanitize the ShipStation additional services JSON string.
	 *
	 * Accepts a JSON array of objects with 'carrier_code' and 'service_code'.
	 * Returns an empty string when the input is empty or only whitespace.
	 *
	 * @param string $raw_json Raw JSON string.
	 * @return string Sanitized JSON string or empty string.
	 */
	protected function sanitize_shipstation_services_json( string $raw_json ): string {
		$raw_json = trim( wp_unslash( $raw_json ) );

		if ( '' === $raw_json ) {
			return '';
		}

		$decoded = json_decode( $raw_json, true );

		if ( ! is_array( $decoded ) ) {
			add_settings_error( self::OPTION_KEY, 'invalid_shipstation_services_json', __( 'ShipStation additional services JSON is invalid. The field was cleared.', 'fk-usps-optimizer' ) );
			return '';
		}

		$services = array();

		foreach ( $decoded as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$carrier_code = sanitize_text_field( (string) ( $entry['carrier_code'] ?? '' ) );
			$service_code = sanitize_text_field( (string) ( $entry['service_code'] ?? '' ) );

			if ( '' === $carrier_code ) {
				continue;
			}

			$services[] = array(
				'carrier_code' => $carrier_code,
				'service_code' => $service_code,
			);
		}

		return ! empty( $services ) ? wp_json_encode( $services ) : '';
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
				'carrier'                   => 'shipengine',
				'shipengine_api_key'        => '',
				'shipengine_carrier_id'     => '',
				'shipengine_service_code'   => 'usps_priority_mail',
				'shipstation_api_key'       => '',
				'shipstation_api_secret'    => '',
				'shipstation_carrier_code'  => 'stamps_com',
				'shipstation_service_code'  => 'usps_priority_mail',
				'shipstation_services_json' => '',
				'service_code'              => 'usps_priority_mail',
				'sandbox_mode'              => '0',
				'show_all_options'          => '0',
				'show_package_count'        => '0',
				'add_package_note'          => '0',
				'show_estimated_delivery'   => '0',
				'use_default_transit_days'  => '1', // ON by default — preserves existing behaviour of falling back to built-in transit-day estimates.
				'transit_days_buffer'       => 0,
				'ship_from_name'            => '',
				'ship_from_company'         => '',
				'ship_from_phone'           => '',
				'ship_from_address1'        => '',
				'ship_from_address2'        => '',
				'ship_from_city'            => '',
				'ship_from_state'           => '',
				'ship_from_postal_code'     => '',
				'ship_from_country'         => 'US',
				'debug_logging'             => '0',
				'boxes_json'                => wp_json_encode( $this->get_default_boxes() ),
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
	 * Get box definitions filtered for a specific carrier.
	 *
	 * Returns only boxes whose carrier_restriction is empty (available to all)
	 * or matches the given carrier keyword.  The carrier keyword is matched
	 * case-insensitively against the stored restriction.
	 *
	 * @param string $carrier Carrier keyword (e.g. 'usps', 'ups', 'fedex').
	 * @return array Filtered array of box definitions.
	 */
	public function get_boxes_for_carrier( string $carrier ): array {
		$boxes   = $this->get_boxes();
		$carrier = strtolower( trim( $carrier ) );

		if ( '' === $carrier ) {
			return $boxes;
		}

		$filtered = array();

		foreach ( $boxes as $box ) {
			$restriction = strtolower( trim( (string) ( $box['carrier_restriction'] ?? '' ) ) );

			if ( '' === $restriction || $restriction === $carrier ) {
				$filtered[] = $box;
			}
		}

		return $filtered;
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
	 * Returns the first enabled carrier for backward compatibility.
	 *
	 * @return string 'shipengine' or 'shipstation'.
	 */
	public function get_carrier(): string {
		$carriers = $this->get_carriers();
		return ! empty( $carriers ) ? $carriers[0] : 'shipengine';
	}

	/**
	 * Get all enabled carrier APIs.
	 *
	 * The carrier setting is stored as a comma-separated string.
	 * Legacy single-value strings (e.g. 'shipengine') are also supported.
	 *
	 * @return string[] Array of enabled carrier identifiers.
	 */
	public function get_carriers(): array {
		$settings       = $this->get_settings();
		$raw            = (string) $settings['carrier'];
		$valid_carriers = array( 'shipengine', 'shipstation' );
		$carriers       = array();

		foreach ( explode( ',', $raw ) as $c ) {
			$c = trim( $c );
			if ( in_array( $c, $valid_carriers, true ) ) {
				$carriers[] = $c;
			}
		}

		$carriers = array_unique( $carriers );

		return (array) apply_filters( 'fk_usps_optimizer_carriers', ! empty( $carriers ) ? $carriers : array( 'shipengine' ) );
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
	 * Check whether "Show All Options" is enabled.
	 *
	 * When active, calculate_shipping() displays all rated box candidates as
	 * separate shipping options via cartesian product instead of summing to a
	 * single cheapest rate.
	 *
	 * @return bool Whether "Show All Options" is enabled.
	 */
	public function is_show_all_options_enabled(): bool {
		$settings = $this->get_settings();
		return '1' === (string) $settings['show_all_options'];
	}

	/**
	 * Check whether "Show Package Count" is enabled.
	 *
	 * When active, the package count is appended to the shipping label
	 * displayed during cart and checkout.
	 *
	 * @return bool Whether "Show Package Count" is enabled.
	 */
	public function is_show_package_count_enabled(): bool {
		$settings = $this->get_settings();
		return '1' === (string) $settings['show_package_count'];
	}

	/**
	 * Check whether "Add Package Note" is enabled.
	 *
	 * When active, the suggested package plan is added as a private
	 * WooCommerce order note after checkout.
	 *
	 * @return bool Whether "Add Package Note" is enabled.
	 */
	public function is_add_package_note_enabled(): bool {
		$settings = $this->get_settings();
		return '1' === (string) $settings['add_package_note'];
	}

	/**
	 * Check whether "Show Estimated Delivery Date" is enabled.
	 *
	 * When active, the carrier-provided estimated delivery date is appended
	 * to each shipping option label displayed during cart and checkout,
	 * including FunnelKit Checkout pages.
	 *
	 * @return bool Whether "Show Estimated Delivery Date" is enabled.
	 */
	public function is_show_estimated_delivery_enabled(): bool {
		$settings = $this->get_settings();
		return '1' === (string) $settings['show_estimated_delivery'];
	}

	/**
	 * Whether the "Use Default Transit Day Estimates" option is active.
	 *
	 * When enabled, the carrier services will use built-in service-code-based
	 * transit-day estimates as a fallback when the API does not return
	 * delivery-date information.  When disabled the checkout shows
	 * "(No Estimate)" instead.
	 *
	 * @return bool Whether "Use Default Transit Day Estimates" is enabled.
	 */
	public function is_use_default_transit_days_enabled(): bool {
		$settings = $this->get_settings();
		return '1' === (string) ( $settings['use_default_transit_days'] ?? '1' );
	}

	/**
	 * Get the extra business-day buffer added to delivery estimates.
	 *
	 * This value accounts for order processing / handling time and is added
	 * to every computed delivery date (both carrier-returned day counts and
	 * built-in default transit-day estimates).
	 *
	 * @return int Non-negative number of extra business days (0–30).
	 */
	public function get_transit_days_buffer(): int {
		$settings = $this->get_settings();
		return max( 0, (int) ( $settings['transit_days_buffer'] ?? 0 ) );
	}

	/**
	 * Get the configured USPS service code.
	 *
	 * This value is sent to the carrier API as the service_code parameter and
	 * supports all USPS services including flat-rate and cubic pricing.
	 *
	 * Kept for backward compatibility — prefer the per-carrier methods
	 * get_shipengine_service_code() and get_shipstation_service_code().
	 *
	 * @return string USPS service code (e.g. 'usps_priority_mail').
	 */
	public function get_service_code(): string {
		$settings     = $this->get_settings();
		$service_code = (string) $settings['service_code'];
		return '' !== $service_code ? $service_code : 'usps_priority_mail';
	}

	/**
	 * Get the ShipEngine-specific service code.
	 *
	 * Falls back to the legacy shared service_code for backward compatibility.
	 *
	 * @return string Service code (e.g. 'usps_priority_mail').
	 */
	public function get_shipengine_service_code(): string {
		$settings     = $this->get_settings();
		$service_code = (string) ( $settings['shipengine_service_code'] ?? '' );

		if ( '' === $service_code ) {
			// Backward compat: use the legacy shared service_code.
			return $this->get_service_code();
		}

		return (string) apply_filters( 'fk_usps_optimizer_shipengine_service_code', $service_code );
	}

	/**
	 * Get the ShipStation-specific service code.
	 *
	 * Falls back to the legacy shared service_code for backward compatibility.
	 *
	 * @return string Service code (e.g. 'usps_priority_mail').
	 */
	public function get_shipstation_service_code(): string {
		$settings     = $this->get_settings();
		$service_code = (string) ( $settings['shipstation_service_code'] ?? '' );

		if ( '' === $service_code ) {
			// Backward compat: use the legacy shared service_code.
			return $this->get_service_code();
		}

		return (string) apply_filters( 'fk_usps_optimizer_shipstation_service_code', $service_code );
	}

	/**
	 * Get all ShipStation carrier+service pairs to rate-shop.
	 *
	 * The primary pair (from the single carrier_code + service_code fields) is
	 * always included as the first entry.  Additional pairs from the
	 * shipstation_services_json field are appended and de-duplicated.
	 *
	 * Each pair is an associative array with 'carrier_code' and 'service_code'.
	 *
	 * @return array[] Array of carrier+service pairs.
	 */
	public function get_shipstation_service_pairs(): array {
		$primary = array(
			'carrier_code' => $this->get_shipstation_carrier_code(),
			'service_code' => $this->get_shipstation_service_code(),
		);

		$pairs = array( $primary );

		$settings = $this->get_settings();
		$json     = (string) ( $settings['shipstation_services_json'] ?? '' );

		if ( '' !== $json ) {
			$decoded = json_decode( $json, true );

			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $entry ) {
					if ( ! is_array( $entry ) || empty( $entry['carrier_code'] ) ) {
						continue;
					}

					$pair = array(
						'carrier_code' => (string) $entry['carrier_code'],
						'service_code' => (string) ( $entry['service_code'] ?? '' ),
					);

					// Avoid duplicating the primary pair.
					if ( $pair['carrier_code'] === $primary['carrier_code'] && $pair['service_code'] === $primary['service_code'] ) {
						continue;
					}

					$pairs[] = $pair;
				}
			}
		}

		return (array) apply_filters( 'fk_usps_optimizer_shipstation_service_pairs', $pairs );
	}

	/**
	 * Get the default box definitions.
	 *
	 * @return array Default box definitions.
	 */
	protected function get_default_boxes(): array {
		return array(
			array(
				'reference'           => '1 Bag',
				'package_code'        => 'package',
				'package_name'        => '1 Bag',
				'box_type'            => 'cubic',
				'outer_width'         => 8,
				'outer_length'        => 6,
				'outer_depth'         => 6,
				'inner_width'         => 8,
				'inner_length'        => 6,
				'inner_depth'         => 6,
				'empty_weight'        => 3,
				'max_weight'          => 5,
				'carrier_restriction' => '',
			),
			array(
				'reference'           => '2 Bag',
				'package_code'        => 'package',
				'package_name'        => '2 Bag',
				'box_type'            => 'cubic',
				'outer_width'         => 11,
				'outer_length'        => 8,
				'outer_depth'         => 7,
				'inner_width'         => 11,
				'inner_length'        => 8,
				'inner_depth'         => 7,
				'empty_weight'        => 5,
				'max_weight'          => 9,
				'carrier_restriction' => '',
			),
			array(
				'reference'           => '3 Bag',
				'package_code'        => 'package',
				'package_name'        => '3 Bag',
				'box_type'            => 'cubic',
				'outer_width'         => 12,
				'outer_length'        => 11,
				'outer_depth'         => 6,
				'inner_width'         => 12,
				'inner_length'        => 11,
				'inner_depth'         => 6,
				'empty_weight'        => 7,
				'max_weight'          => 13,
				'carrier_restriction' => '',
			),
			array(
				'reference'           => '4 Bag',
				'package_code'        => 'package',
				'package_name'        => '4 Bag',
				'box_type'            => 'cubic',
				'outer_width'         => 12,
				'outer_length'        => 12,
				'outer_depth'         => 7,
				'inner_width'         => 12,
				'inner_length'        => 12,
				'inner_depth'         => 7,
				'empty_weight'        => 5,
				'max_weight'          => 17,
				'carrier_restriction' => '',
			),
			array(
				'reference'           => 'USPS Medium Flat Rate',
				'package_code'        => 'medium_flat_rate_box',
				'package_name'        => 'Medium Flat Rate Box',
				'box_type'            => 'flat_rate',
				'outer_width'         => 14,
				'outer_length'        => 12,
				'outer_depth'         => 3,
				'inner_width'         => 14,
				'inner_length'        => 12,
				'inner_depth'         => 3,
				'empty_weight'        => 6,
				'max_weight'          => 70,
				'carrier_restriction' => 'usps',
			),
			array(
				'reference'           => 'USPS Large Flat Rate',
				'package_code'        => 'large_flat_rate_box',
				'package_name'        => 'Large Flat Rate Box',
				'box_type'            => 'flat_rate',
				'outer_width'         => 12,
				'outer_length'        => 12,
				'outer_depth'         => 6,
				'inner_width'         => 12,
				'inner_length'        => 12,
				'inner_depth'         => 6,
				'empty_weight'        => 8,
				'max_weight'          => 70,
				'carrier_restriction' => 'usps',
			),
		);
	}
}
