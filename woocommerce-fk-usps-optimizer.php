<?php
/**
 * Plugin Name: FunnelKit USPS Priority Shipping Optimizer
 * Description: Optimizes WooCommerce and FunnelKit orders for USPS Priority cubic custom boxes and USPS Priority flat rate boxes.
 * Version: 1.0.0
 * Author: OpenAI
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: fk-usps-optimizer
 *
 * @package FK_USPS_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FK_USPS_OPTIMIZER_VERSION', '1.0.0' );
define( 'FK_USPS_OPTIMIZER_FILE', __FILE__ );
define( 'FK_USPS_OPTIMIZER_PATH', plugin_dir_path( __FILE__ ) );
define( 'FK_USPS_OPTIMIZER_URL', plugin_dir_url( __FILE__ ) );

$autoload = FK_USPS_OPTIMIZER_PATH . 'vendor/autoload.php';

if ( file_exists( $autoload ) ) {
	require_once $autoload;
}

require_once FK_USPS_OPTIMIZER_PATH . 'includes/class-plugin.php';

\FK_USPS_Optimizer\Plugin::bootstrap();
