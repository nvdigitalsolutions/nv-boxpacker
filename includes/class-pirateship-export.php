<?php
/**
 * PirateShip CSV export for the FK USPS Optimizer plugin.
 *
 * @package FK_USPS_Optimizer
 */

namespace FK_USPS_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles CSV export of shipping plans for PirateShip.
 */
class PirateShip_Export {
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
	 * Constructor.
	 *
	 * @param Settings           $settings           Plugin settings.
	 * @param Order_Plan_Service $order_plan_service Order plan service.
	 */
	public function __construct( Settings $settings, Order_Plan_Service $order_plan_service ) {
		$this->settings           = $settings;
		$this->order_plan_service = $order_plan_service;
	}

	/**
	 * Registers WordPress action hooks.
	 */
	public function register(): void {
		add_action( 'admin_post_fk_usps_optimizer_export_csv', array( $this, 'handle_export' ) );
	}

	/**
	 * Build a CSV row for the given order and package plan.
	 *
	 * @param \WC_Order $order        The order.
	 * @param array     $package_plan Package plan data.
	 * @return array CSV row data.
	 */
	public function build_row( \WC_Order $order, array $package_plan ): array {
		return array(
			'order_number'   => $order->get_order_number(),
			'package_number' => $package_plan['package_number'],
			'recipient_name' => trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ),
			'company'        => $order->get_shipping_company(),
			'address_1'      => $order->get_shipping_address_1(),
			'address_2'      => $order->get_shipping_address_2(),
			'city'           => $order->get_shipping_city(),
			'state'          => $order->get_shipping_state(),
			'postal_code'    => $order->get_shipping_postcode(),
			'country'        => $order->get_shipping_country(),
			'carrier'        => 'USPS',
			'service'        => 'Priority Mail',
			'package_type'   => $package_plan['package_code'],
			'package_name'   => $package_plan['package_name'],
			'weight_oz'      => $package_plan['weight_oz'],
			'length'         => $package_plan['dimensions']['length'],
			'width'          => $package_plan['dimensions']['width'],
			'height'         => $package_plan['dimensions']['height'],
			'packing_list'   => implode( '; ', $package_plan['packing_list'] ),
			'references'     => sprintf( 'Order #%s / Package %d', $order->get_order_number(), $package_plan['package_number'] ),
		);
	}

	/**
	 * Handles the CSV export admin-post request.
	 */
	public function handle_export(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to export shipping plans.', 'fk-usps-optimizer' ) );
		}

		check_admin_referer( 'fk_usps_optimizer_export_csv' );

		$order_ids = isset( $_GET['order_ids'] ) ? array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET['order_ids'] ) ) ) ) : array();

		header( 'Content-Type: text/csv' );
		header( 'Content-Disposition: attachment; filename="pirateship-export.csv"' );

		$output = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming CSV to php://output requires direct PHP functions.
		fputcsv( $output, array_keys( $this->build_csv_headers() ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv -- Streaming CSV to php://output requires direct PHP functions.

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order instanceof \WC_Order ) {
				continue;
			}

			$plan = $this->order_plan_service->get( $order );

			foreach ( $plan['pirateship_rows'] ?? array() as $row ) {
				fputcsv( $output, $row ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv -- Streaming CSV to php://output requires direct PHP functions.
			}
		}

		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming CSV to php://output requires direct PHP functions.
		exit;
	}

	/**
	 * Get the CSV header keys.
	 *
	 * @return array CSV header keys.
	 */
	protected function build_csv_headers(): array {
		return array(
			'order_number'   => '',
			'package_number' => '',
			'recipient_name' => '',
			'company'        => '',
			'address_1'      => '',
			'address_2'      => '',
			'city'           => '',
			'state'          => '',
			'postal_code'    => '',
			'country'        => '',
			'carrier'        => '',
			'service'        => '',
			'package_type'   => '',
			'package_name'   => '',
			'weight_oz'      => '',
			'length'         => '',
			'width'          => '',
			'height'         => '',
			'packing_list'   => '',
			'references'     => '',
		);
	}
}
