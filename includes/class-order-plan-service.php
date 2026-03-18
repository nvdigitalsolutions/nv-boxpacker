<?php
/**
 * Order plan service for the FK USPS Optimizer plugin.
 *
 * @package FK_USPS_Optimizer
 */

namespace FK_USPS_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores and retrieves shipping plans for WooCommerce orders.
 */
class Order_Plan_Service {
	const META_KEY = '_fk_usps_optimizer_plan';

	/**
	 * Save the shipping plan as order meta data.
	 *
	 * @param \WC_Order $order The WooCommerce order.
	 * @param array     $plan  Shipping plan data.
	 * @return void
	 */
	public function save( \WC_Order $order, array $plan ): void {
		$order->update_meta_data( self::META_KEY, $plan );
		$order->save();
	}

	/**
	 * Retrieve the stored shipping plan for an order.
	 *
	 * @param \WC_Order $order The WooCommerce order.
	 * @return array Shipping plan data, or empty array if not found.
	 */
	public function get( \WC_Order $order ): array {
		$plan = $order->get_meta( self::META_KEY, true );
		return is_array( $plan ) ? $plan : array();
	}
}
