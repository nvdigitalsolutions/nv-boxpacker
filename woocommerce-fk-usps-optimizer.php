<?php
/**
 * Plugin Name: FunnelKit USPS Priority Shipping Optimizer
 * Description: Optimizes WooCommerce and FunnelKit orders for USPS Priority cubic custom boxes and USPS Priority flat rate boxes.
 * Version: 1.2.0
 * Author: OpenAI
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Text Domain: fk-usps-optimizer
 *
 * @package FK_USPS_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FK_USPS_OPTIMIZER_VERSION', '1.2.0' );
define( 'FK_USPS_OPTIMIZER_FILE', __FILE__ );
define( 'FK_USPS_OPTIMIZER_PATH', plugin_dir_path( __FILE__ ) );
define( 'FK_USPS_OPTIMIZER_URL', plugin_dir_url( __FILE__ ) );

$autoload = FK_USPS_OPTIMIZER_PATH . 'vendor/autoload.php';

if ( ! file_exists( $autoload ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>' .
				esc_html__( 'FunnelKit USPS Priority Optimizer: Composer dependencies are missing. Please run', 'fk-usps-optimizer' ) .
				' <code>composer install</code> ' .
				esc_html__( 'in the plugin directory or reinstall using the built ZIP.', 'fk-usps-optimizer' ) .
				'</p></div>';
		}
	);
	return;
}

require_once $autoload;

require_once FK_USPS_OPTIMIZER_PATH . 'includes/class-plugin.php';

\FK_USPS_Optimizer\Plugin::bootstrap();
