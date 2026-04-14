<?php
/**
 * Admin test pricing UI for the FK USPS Optimizer plugin.
 *
 * Provides a WooCommerce submenu page that lets store managers test box
 * packing and live USPS rate-shopping without placing a real order.
 *
 * @package FK_USPS_Optimizer
 */

namespace FK_USPS_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the admin "Test Pricing" page and handles form submissions.
 */
class Admin_Test_UI {

	/**
	 * Plugin settings instance.
	 *
	 * @var Settings
	 */
	protected $settings;

	/**
	 * Test pricing service instance.
	 *
	 * @var Test_Pricing_Service
	 */
	protected $test_pricing_service;

	/**
	 * Constructor.
	 *
	 * @param Settings             $settings             Plugin settings.
	 * @param Test_Pricing_Service $test_pricing_service Test pricing service.
	 */
	public function __construct( Settings $settings, Test_Pricing_Service $test_pricing_service ) {
		$this->settings             = $settings;
		$this->test_pricing_service = $test_pricing_service;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	/**
	 * Add the "USPS Test Pricing" submenu under WooCommerce.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'USPS Test Pricing', 'fk-usps-optimizer' ),
			__( 'USPS Test Pricing', 'fk-usps-optimizer' ),
			'manage_woocommerce',
			'fk-usps-optimizer-test',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the test pricing admin page.
	 *
	 * Handles a POST submission (nonce-verified), runs the test pricing cycle,
	 * and displays both the input form and the results on the same page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'fk-usps-optimizer' ) );
		}

		$result = null;
		$posted = array(
			'items'   => array(),
			'ship_to' => array(),
		);
		$errors = array();

		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			// Verify nonce before accessing any other POST data.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce is verified on the next line via wp_verify_nonce.
			$raw_nonce = sanitize_key( wp_unslash( $_POST['fk_usps_test_nonce'] ?? '' ) );

			if ( '' === $raw_nonce || ! wp_verify_nonce( $raw_nonce, 'fk_usps_test_pricing' ) ) {
				$errors[] = __( 'Security check failed. Please try again.', 'fk-usps-optimizer' );
			} else {
				$posted = $this->parse_posted_data( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parse_posted_data sanitizes internally.
				$result = $this->test_pricing_service->run( $posted['items'], $posted['ship_to'] );
			}
		}

		$is_sandbox    = $this->settings->is_sandbox_mode_enabled();
		$carriers      = $this->settings->get_carriers();
		$carrier_names = array();
		foreach ( $carriers as $c ) {
			$carrier_names[] = 'shipstation' === $c ? 'ShipStation' : 'ShipEngine';
		}
		$carrier_label = implode( ' + ', $carrier_names );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'USPS Priority Test Pricing', 'fk-usps-optimizer' ); ?></h1>

			<?php if ( $is_sandbox ) : ?>
			<div class="notice notice-warning">
				<p>
					<strong><?php echo esc_html__( '⚠ Sandbox Mode Active', 'fk-usps-optimizer' ); ?></strong>
					&mdash;
					<?php echo esc_html__( 'Rates are fetched from a sandbox/test environment and are not production prices.', 'fk-usps-optimizer' ); ?>
				</p>
			</div>
			<?php endif; ?>

			<?php foreach ( $errors as $error ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endforeach; ?>

			<p class="description">
				<?php
				printf(
					/* translators: %s: carrier name(s). */
					esc_html__( 'Enter items and a destination address to preview box packing and rates via %s.', 'fk-usps-optimizer' ),
					'<strong>' . esc_html( $carrier_label ) . '</strong>'
				);
				?>
			</p>

			<form method="post">
				<?php wp_nonce_field( 'fk_usps_test_pricing', 'fk_usps_test_nonce' ); ?>

				<h2><?php echo esc_html__( 'Items to Ship', 'fk-usps-optimizer' ); ?></h2>
				<p class="description"><?php echo esc_html__( 'Enter dimensions in inches and weight in ounces. Empty rows are skipped.', 'fk-usps-optimizer' ); ?></p>

				<table class="widefat striped" id="fk-test-items-table">
					<thead>
						<tr>
							<th><?php echo esc_html__( 'Item Name', 'fk-usps-optimizer' ); ?></th>
							<th><?php echo esc_html__( 'Qty', 'fk-usps-optimizer' ); ?></th>
							<th><?php echo esc_html__( 'Length (in)', 'fk-usps-optimizer' ); ?></th>
							<th><?php echo esc_html__( 'Width (in)', 'fk-usps-optimizer' ); ?></th>
							<th><?php echo esc_html__( 'Height (in)', 'fk-usps-optimizer' ); ?></th>
							<th><?php echo esc_html__( 'Weight (oz)', 'fk-usps-optimizer' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody id="fk-test-items-body">
						<?php
						$item_rows = ! empty( $posted['items'] ) ? $posted['items'] : array_fill( 0, 3, array() );
						foreach ( $item_rows as $i => $item ) :
							$i = (int) $i;
							?>
						<tr>
							<td><input type="text" name="items[<?php echo esc_attr( $i ); ?>][name]" value="<?php echo esc_attr( $item['name'] ?? '' ); ?>" class="regular-text" /></td>
							<td><input type="number" name="items[<?php echo esc_attr( $i ); ?>][qty]" value="<?php echo esc_attr( $item['qty'] ?? 1 ); ?>" min="1" max="99" style="width:60px" /></td>
							<td><input type="number" step="0.1" min="0.1" name="items[<?php echo esc_attr( $i ); ?>][length]" value="<?php echo esc_attr( $item['length'] ?? '' ); ?>" style="width:75px" /></td>
							<td><input type="number" step="0.1" min="0.1" name="items[<?php echo esc_attr( $i ); ?>][width]" value="<?php echo esc_attr( $item['width'] ?? '' ); ?>" style="width:75px" /></td>
							<td><input type="number" step="0.1" min="0.1" name="items[<?php echo esc_attr( $i ); ?>][height]" value="<?php echo esc_attr( $item['height'] ?? '' ); ?>" style="width:75px" /></td>
							<td><input type="number" step="0.1" min="0.1" name="items[<?php echo esc_attr( $i ); ?>][weight_oz]" value="<?php echo esc_attr( $item['weight_oz'] ?? '' ); ?>" style="width:75px" /></td>
							<td><button type="button" class="button fk-remove-item"><?php echo esc_html__( 'Remove', 'fk-usps-optimizer' ); ?></button></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p>
					<button type="button" id="fk-add-item" class="button">
						<?php echo esc_html__( '+ Add Item', 'fk-usps-optimizer' ); ?>
					</button>
				</p>

				<h2><?php echo esc_html__( 'Ship-To Address', 'fk-usps-optimizer' ); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row"><?php echo esc_html__( 'Full Name', 'fk-usps-optimizer' ); ?></th>
						<td><input type="text" name="ship_to[name]" value="<?php echo esc_attr( $posted['ship_to']['name'] ?? '' ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Company', 'fk-usps-optimizer' ); ?></th>
						<td><input type="text" name="ship_to[company_name]" value="<?php echo esc_attr( $posted['ship_to']['company_name'] ?? '' ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Address Line 1', 'fk-usps-optimizer' ); ?></th>
						<td><input type="text" name="ship_to[address_line1]" value="<?php echo esc_attr( $posted['ship_to']['address_line1'] ?? '' ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Address Line 2', 'fk-usps-optimizer' ); ?></th>
						<td><input type="text" name="ship_to[address_line2]" value="<?php echo esc_attr( $posted['ship_to']['address_line2'] ?? '' ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'City', 'fk-usps-optimizer' ); ?></th>
						<td><input type="text" name="ship_to[city_locality]" value="<?php echo esc_attr( $posted['ship_to']['city_locality'] ?? '' ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'State', 'fk-usps-optimizer' ); ?></th>
						<td><input type="text" name="ship_to[state_province]" value="<?php echo esc_attr( $posted['ship_to']['state_province'] ?? '' ); ?>" style="width:60px" /></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Postal Code', 'fk-usps-optimizer' ); ?></th>
						<td><input type="text" name="ship_to[postal_code]" value="<?php echo esc_attr( $posted['ship_to']['postal_code'] ?? '' ); ?>" style="width:120px" /></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Country Code', 'fk-usps-optimizer' ); ?></th>
						<td><input type="text" name="ship_to[country_code]" value="<?php echo esc_attr( $posted['ship_to']['country_code'] ?? 'US' ); ?>" style="width:60px" maxlength="2" /></td>
					</tr>
				</table>

				<?php submit_button( __( 'Run Test Pricing', 'fk-usps-optimizer' ) ); ?>
			</form>

			<?php if ( null !== $result ) : ?>
			<hr />
			<h2><?php echo esc_html__( 'Test Pricing Results', 'fk-usps-optimizer' ); ?></h2>

				<?php if ( $result['sandbox'] ) : ?>
			<p><em><?php echo esc_html__( '(Results from sandbox environment — not live production rates)', 'fk-usps-optimizer' ); ?></em></p>
			<?php endif; ?>

				<?php if ( ! empty( $result['warnings'] ) ) : ?>
			<div class="notice notice-warning inline">
				<ul>
					<?php foreach ( $result['warnings'] as $warning ) : ?>
					<li><?php echo esc_html( $warning ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php endif; ?>

				<?php if ( ! empty( $result['packages'] ) ) : ?>
			<p>
				<strong><?php echo esc_html__( 'Total estimated USPS rate:', 'fk-usps-optimizer' ); ?></strong>
					<?php echo wp_kses_post( wc_price( (float) $result['total_rate_amount'], array( 'currency' => $result['currency'] ) ) ); ?>
			</p>

					<?php foreach ( $result['packages'] as $pkg ) : ?>
			<table class="widefat" style="margin-bottom:1.5em;max-width:680px">
				<thead>
					<tr>
						<th colspan="2">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: package number. */
									__( 'Package %d', 'fk-usps-optimizer' ),
									(int) $pkg['package_number']
								)
							);
							echo ' &mdash; ';
							echo esc_html( $pkg['package_name'] );
							echo ' (' . esc_html( $pkg['mode'] ) . ')';
							?>
						</th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Estimated Rate', 'fk-usps-optimizer' ); ?></th>
						<td><?php echo wp_kses_post( wc_price( (float) $pkg['rate_amount'], array( 'currency' => $pkg['currency'] ) ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Service', 'fk-usps-optimizer' ); ?></th>
						<td><?php echo esc_html( $pkg['service_code'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Dimensions (L×W×H)', 'fk-usps-optimizer' ); ?></th>
						<td>
							<?php
							echo esc_html(
								sprintf(
									'%s × %s × %s in',
									$pkg['dimensions']['length'],
									$pkg['dimensions']['width'],
									$pkg['dimensions']['height']
								)
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Weight', 'fk-usps-optimizer' ); ?></th>
						<td><?php echo esc_html( $pkg['weight_oz'] . ' oz' ); ?></td>
					</tr>
						<?php if ( ! empty( $pkg['cubic_tier'] ) ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Cubic Tier', 'fk-usps-optimizer' ); ?></th>
						<td><?php echo esc_html( $pkg['cubic_tier'] ); ?></td>
					</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Packing List', 'fk-usps-optimizer' ); ?></th>
						<td><?php echo esc_html( implode( '; ', $pkg['packing_list'] ) ); ?></td>
					</tr>
				</tbody>
			</table>
			<?php endforeach; ?>
			<?php endif; ?>
			<?php endif; ?>
		</div>

		<script>
		(function () {
			'use strict';
			var tbody  = document.getElementById( 'fk-test-items-body' );
			var addBtn = document.getElementById( 'fk-add-item' );

			function makeRow( idx ) {
				var tr = document.createElement( 'tr' );
				tr.innerHTML =
					'<td><input type="text" name="items[' + idx + '][name]" class="regular-text" /></td>' +
					'<td><input type="number" name="items[' + idx + '][qty]" value="1" min="1" max="99" style="width:60px" /></td>' +
					'<td><input type="number" step="0.1" min="0.1" name="items[' + idx + '][length]" style="width:75px" /></td>' +
					'<td><input type="number" step="0.1" min="0.1" name="items[' + idx + '][width]" style="width:75px" /></td>' +
					'<td><input type="number" step="0.1" min="0.1" name="items[' + idx + '][height]" style="width:75px" /></td>' +
					'<td><input type="number" step="0.1" min="0.1" name="items[' + idx + '][weight_oz]" style="width:75px" /></td>' +
					'<td><button type="button" class="button fk-remove-item"><?php echo esc_js( __( 'Remove', 'fk-usps-optimizer' ) ); ?></button></td>';
				return tr;
			}

			function reindex() {
				var rows = tbody.querySelectorAll( 'tr' );
				rows.forEach( function ( tr, i ) {
					tr.querySelectorAll( 'input' ).forEach( function ( inp ) {
						inp.name = inp.name.replace( /items\[\d+\]/, 'items[' + i + ']' );
					} );
				} );
			}

			function attachRemove() {
				tbody.querySelectorAll( '.fk-remove-item' ).forEach( function ( btn ) {
					btn.onclick = function () {
						btn.closest( 'tr' ).remove();
						reindex();
					};
				} );
			}

			addBtn.addEventListener( 'click', function () {
				var idx = tbody.querySelectorAll( 'tr' ).length;
				tbody.appendChild( makeRow( idx ) );
				attachRemove();
			} );

			attachRemove();
		}());
		</script>
		<?php
	}

	/**
	 * Parse and sanitise posted form data.
	 *
	 * @param array $post Raw $_POST data.
	 * @return array Parsed data with keys 'items' and 'ship_to'.
	 */
	public function parse_posted_data( array $post ): array {
		$items = array();

		if ( ! empty( $post['items'] ) && is_array( $post['items'] ) ) {
			foreach ( $post['items'] as $raw ) {
				if ( ! is_array( $raw ) ) {
					continue;
				}

				$items[] = array(
					'name'      => sanitize_text_field( wp_unslash( $raw['name'] ?? '' ) ),
					'qty'       => max( 1, (int) ( $raw['qty'] ?? 1 ) ),
					'length'    => sanitize_text_field( wp_unslash( (string) ( $raw['length'] ?? '' ) ) ),
					'width'     => sanitize_text_field( wp_unslash( (string) ( $raw['width'] ?? '' ) ) ),
					'height'    => sanitize_text_field( wp_unslash( (string) ( $raw['height'] ?? '' ) ) ),
					'weight_oz' => sanitize_text_field( wp_unslash( (string) ( $raw['weight_oz'] ?? '' ) ) ),
				);
			}
		}

		$raw_ship_to = is_array( $post['ship_to'] ?? null ) ? $post['ship_to'] : array();

		$ship_to = array(
			'name'                          => sanitize_text_field( wp_unslash( $raw_ship_to['name'] ?? '' ) ),
			'company_name'                  => sanitize_text_field( wp_unslash( $raw_ship_to['company_name'] ?? '' ) ),
			'address_line1'                 => sanitize_text_field( wp_unslash( $raw_ship_to['address_line1'] ?? '' ) ),
			'address_line2'                 => sanitize_text_field( wp_unslash( $raw_ship_to['address_line2'] ?? '' ) ),
			'city_locality'                 => sanitize_text_field( wp_unslash( $raw_ship_to['city_locality'] ?? '' ) ),
			'state_province'                => sanitize_text_field( wp_unslash( $raw_ship_to['state_province'] ?? '' ) ),
			'postal_code'                   => sanitize_text_field( wp_unslash( $raw_ship_to['postal_code'] ?? '' ) ),
			'country_code'                  => sanitize_text_field( wp_unslash( $raw_ship_to['country_code'] ?? 'US' ) ),
			'address_residential_indicator' => 'unknown',
		);

		return array(
			'items'   => $items,
			'ship_to' => $ship_to,
		);
	}
}
