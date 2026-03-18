<?php
/**
 * Admin UI for the FK USPS Optimizer plugin.
 *
 * @package FK_USPS_Optimizer
 */

namespace FK_USPS_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the WooCommerce order meta box for shipping plans.
 */
class Admin_UI {
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
	 * PirateShip export service instance.
	 *
	 * @var PirateShip_Export
	 */
	protected $export_service;

	/**
	 * Constructor.
	 *
	 * @param Settings           $settings           Plugin settings.
	 * @param Order_Plan_Service $order_plan_service Order plan service.
	 * @param PirateShip_Export  $export_service     PirateShip export service.
	 */
	public function __construct( Settings $settings, Order_Plan_Service $order_plan_service, PirateShip_Export $export_service ) {
		$this->settings           = $settings;
		$this->order_plan_service = $order_plan_service;
		$this->export_service     = $export_service;
	}

	/**
	 * Registers WordPress action hooks.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
	}

	/**
	 * Registers the shipping plan meta box for WooCommerce orders.
	 */
	public function register_meta_box(): void {
		add_meta_box(
			'fk-usps-optimizer-plan',
			__( 'USPS Priority Shipping Plan', 'fk-usps-optimizer' ),
			array( $this, 'render_meta_box' ),
			'shop_order',
			'side',
			'default'
		);

		// WooCommerce High-Performance Order Storage (HPOS) compatibility.
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			add_meta_box(
				'fk-usps-optimizer-plan',
				__( 'USPS Priority Shipping Plan', 'fk-usps-optimizer' ),
				array( $this, 'render_meta_box' ),
				wc_get_page_screen_id( 'shop-order' ),
				'side',
				'default'
			);
		}
	}

	/**
	 * Render the shipping plan meta box content.
	 *
	 * @param \WP_Post|\WC_Order $post_or_order Post or order object.
	 */
	public function render_meta_box( $post_or_order ): void {
		if ( $post_or_order instanceof \WC_Order ) {
			$order = $post_or_order;
		} else {
			$order = wc_get_order( $post_or_order->ID );
		}

		if ( ! $order instanceof \WC_Order ) {
			echo esc_html__( 'Order not found.', 'fk-usps-optimizer' );
			return;
		}

		$plan = $this->order_plan_service->get( $order );

		if ( empty( $plan ) ) {
			echo esc_html__( 'No shipping plan has been stored for this order yet.', 'fk-usps-optimizer' );
			return;
		}

		echo '<p><strong>' . esc_html__( 'Packages:', 'fk-usps-optimizer' ) . '</strong> ' . esc_html( (string) ( $plan['total_package_count'] ?? 0 ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Total USPS Rate:', 'fk-usps-optimizer' ) . '</strong> ' . wp_kses_post( wc_price( (float) ( $plan['total_rate_amount'] ?? 0 ), array( 'currency' => $plan['currency'] ?? 'USD' ) ) ) . '</p>';

		foreach ( $plan['packages'] ?? array() as $package ) {
			echo '<hr />';
			echo '<p><strong>' . esc_html(
				sprintf(
					/* translators: %d is the package number. */
					__( 'Package %d', 'fk-usps-optimizer' ),
					(int) $package['package_number']
				)
			) . '</strong></p>';
			echo '<p>' . esc_html( sprintf( '%s (%s)', $package['package_name'], $package['mode'] ) ) . '</p>';
			echo '<p>' . esc_html(
				sprintf(
					/* translators: 1: currency code, 2: rate amount. */
					__( 'Rate: %1$s %2$s', 'fk-usps-optimizer' ),
					$package['currency'],
					$package['rate_amount']
				)
			) . '</p>';
			echo '<p>' . esc_html(
				sprintf(
					/* translators: 1: length, 2: width, 3: height in inches. */
					__( 'Dims: %1$s x %2$s x %3$s in', 'fk-usps-optimizer' ),
					$package['dimensions']['length'],
					$package['dimensions']['width'],
					$package['dimensions']['height']
				)
			) . '</p>';
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %s is the weight in ounces. */
					__( 'Weight: %s oz', 'fk-usps-optimizer' ),
					$package['weight_oz']
				)
			) . '</p>';

			if ( ! empty( $package['cubic_tier'] ) ) {
				echo '<p>' . esc_html(
					sprintf(
						/* translators: %s is the cubic tier value. */
						__( 'Cubic Tier: %s', 'fk-usps-optimizer' ),
						$package['cubic_tier']
					)
				) . '</p>';
			}

			echo '<ul>';
			foreach ( $package['packing_list'] as $line ) {
				echo '<li>' . esc_html( $line ) . '</li>';
			}
			echo '</ul>';
		}

		if ( ! empty( $plan['warnings'] ) ) {
			echo '<div class="notice notice-warning inline"><ul>';
			foreach ( $plan['warnings'] as $warning ) {
				echo '<li>' . esc_html( $warning ) . '</li>';
			}
			echo '</ul></div>';
		}

		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=fk_usps_optimizer_export_csv&order_ids=' . $order->get_id() ),
			'fk_usps_optimizer_export_csv'
		);

		echo '<p><a class="button button-secondary" href="' . esc_url( $url ) . '">' . esc_html__( 'Export PirateShip CSV', 'fk-usps-optimizer' ) . '</a></p>';
	}
}
