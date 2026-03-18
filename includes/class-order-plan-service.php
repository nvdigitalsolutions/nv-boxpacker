<?php

namespace FK_USPS_Optimizer;

if (! defined('ABSPATH')) {
	exit;
}

class Order_Plan_Service {
	const META_KEY = '_fk_usps_optimizer_plan';

	public function save(\WC_Order $order, array $plan): void {
		$order->update_meta_data(self::META_KEY, $plan);
		$order->save();
	}

	public function get(\WC_Order $order): array {
		$plan = $order->get_meta(self::META_KEY, true);
		return is_array($plan) ? $plan : array();
	}
}
