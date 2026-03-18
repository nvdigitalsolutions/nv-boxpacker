<?php
/**
 * Uninstall handler for FunnelKit USPS Priority Shipping Optimizer.
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

delete_option('fk_usps_optimizer_settings');
