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
	 * Build the CSV body for the given order/plan as an in-memory string.
	 *
	 * Mirrors the format produced by {@see handle_export()} so the output
	 * is directly importable into PirateShip.
	 *
	 * @param \WC_Order $order Order to export.
	 * @param array     $plan  Order plan (as produced by Plugin::process_order()).
	 * @return string CSV file contents (header row + one row per package).
	 */
	public function build_csv_string( \WC_Order $order, array $plan ): string {
		$rows = $plan['pirateship_rows'] ?? array();

		// If the saved plan does not include pre-built rows (e.g. older
		// orders), regenerate them on the fly from the package list.
		if ( empty( $rows ) && ! empty( $plan['packages'] ) ) {
			foreach ( $plan['packages'] as $package ) {
				$rows[] = $this->build_row( $order, $package );
			}
		}

		$buffer = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- In-memory buffer required for fputcsv().

		if ( false === $buffer ) {
			return '';
		}

		fputcsv( $buffer, array_keys( $this->build_csv_headers() ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv -- Writing CSV to in-memory buffer requires direct PHP functions.

		foreach ( $rows as $row ) {
			fputcsv( $buffer, $row ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv -- Writing CSV to in-memory buffer requires direct PHP functions.
		}

		rewind( $buffer );
		$contents = stream_get_contents( $buffer );
		fclose( $buffer ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing in-memory buffer requires direct PHP functions.

		return false === $contents ? '' : $contents;
	}

	/**
	 * Build the human-readable plain-text email body summarising the plan.
	 *
	 * Includes the order number, the recipient shipping address, and a
	 * per-package summary (service, dimensions, weight, packing list) so
	 * the recipient has the same information that gets stored in the
	 * order note even before opening the CSV attachment.
	 *
	 * @param \WC_Order $order Order being notified about.
	 * @param array     $plan  Order plan.
	 * @return string Email body (plain text, line-separated by "\n").
	 */
	public function build_email_body( \WC_Order $order, array $plan ): string {
		$lines = array();

		$lines[] = sprintf(
			/* translators: %s: order number. */
			__( 'A new order is ready for shipping via PirateShip — Order #%s', 'fk-usps-optimizer' ),
			$order->get_order_number()
		);
		$lines[] = '';

		$lines[] = __( 'Ship To:', 'fk-usps-optimizer' );
		$lines[] = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );

		$company = (string) $order->get_shipping_company();
		if ( '' !== $company ) {
			$lines[] = $company;
		}

		$lines[] = (string) $order->get_shipping_address_1();

		$address2 = (string) $order->get_shipping_address_2();
		if ( '' !== $address2 ) {
			$lines[] = $address2;
		}

		$lines[] = sprintf(
			'%s, %s %s',
			(string) $order->get_shipping_city(),
			(string) $order->get_shipping_state(),
			(string) $order->get_shipping_postcode()
		);
		$lines[] = (string) $order->get_shipping_country();
		$lines[] = '';

		$lines[] = sprintf(
			/* translators: 1: package count, 2: total rate amount. */
			__( 'Suggested packages: %1$d total — estimated $%2$s', 'fk-usps-optimizer' ),
			(int) ( $plan['total_package_count'] ?? 0 ),
			number_format( (float) ( $plan['total_rate_amount'] ?? 0 ), 2 )
		);

		foreach ( $plan['packages'] ?? array() as $package ) {
			$lines[] = '';
			$lines[] = sprintf(
				/* translators: 1: package number, 2: package name, 3: mode. */
				__( 'Package %1$d: %2$s (%3$s)', 'fk-usps-optimizer' ),
				(int) $package['package_number'],
				(string) $package['package_name'],
				(string) $package['mode']
			);

			if ( ! empty( $package['service_label'] ) ) {
				$lines[] = sprintf(
					/* translators: %s: service label. */
					__( 'Service: %s', 'fk-usps-optimizer' ),
					(string) $package['service_label']
				);
			}

			$lines[] = sprintf(
				/* translators: 1: length, 2: width, 3: height. */
				__( 'Dimensions: %1$s x %2$s x %3$s in', 'fk-usps-optimizer' ),
				$package['dimensions']['length'] ?? 0,
				$package['dimensions']['width'] ?? 0,
				$package['dimensions']['height'] ?? 0
			);
			$lines[] = sprintf(
				/* translators: %s: weight in ounces. */
				__( 'Weight: %s oz', 'fk-usps-optimizer' ),
				$package['weight_oz'] ?? 0
			);

			if ( ! empty( $package['packing_list'] ) ) {
				$lines[] = __( 'Items:', 'fk-usps-optimizer' ) . ' ' . implode( ', ', (array) $package['packing_list'] );
			}
		}

		$lines[] = '';
		$lines[] = __( 'A CSV file is attached that can be imported directly into PirateShip.', 'fk-usps-optimizer' );

		return implode( "\n", $lines );
	}

	/**
	 * Send the PirateShip notification email for an order.
	 *
	 * Sends a plain-text email summarising the packing plan together with
	 * a CSV attachment that can be imported directly into PirateShip. The
	 * recipient list comes from the plugin's "PirateShip Notification
	 * Emails" setting; if that list is empty, this method is a no-op and
	 * returns false. The email file is written to a temporary path
	 * obtained via wp_tempnam() and removed after wp_mail() returns.
	 *
	 * @param \WC_Order $order Order to notify about.
	 * @param array     $plan  Saved order plan.
	 * @return bool True when wp_mail() reports success, false otherwise (including when no recipients are configured).
	 */
	public function send_order_notification( \WC_Order $order, array $plan ): bool {
		$recipients = $this->settings->get_pirateship_notification_emails();

		if ( empty( $recipients ) ) {
			return false;
		}

		// Skip plans that have no usable packages — nothing to import.
		if ( empty( $plan['packages'] ) && empty( $plan['pirateship_rows'] ) ) {
			return false;
		}

		$csv = $this->build_csv_string( $order, $plan );

		if ( '' === $csv ) {
			return false;
		}

		$filename     = sprintf( 'pirateship-order-%s.csv', $order->get_order_number() );
		$attachment   = '';
		$tmp_path     = wp_tempnam( $filename );
		$has_tmp_file = false;

		// `wp_tempnam()` always returns a path ending in `.tmp` (e.g.
		// `/tmp/wp-pirateship-order-123abcd.tmp`). If we passed that path
		// straight to `wp_mail()` the recipient would see the attachment
		// named with a `.tmp` extension instead of `.csv`. Rename the
		// temp file to the desired CSV filename in the same directory so
		// the email attachment uses the correct extension.
		if ( $tmp_path ) {
			$dir          = trailingslashit( dirname( $tmp_path ) );
			$desired_path = $dir . $filename;

			// Avoid clobbering an existing file (e.g. concurrent retry
			// for the same order number) — fall back to a unique name
			// in the same directory.
			if ( file_exists( $desired_path ) ) {
				$desired_path = $dir . wp_unique_filename( $dir, $filename );
			}

			if ( @rename( $tmp_path, $desired_path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort rename; fall back to original `.tmp` path on failure.
				$tmp_path = $desired_path;
			}
		}

		if ( $tmp_path && false !== file_put_contents( $tmp_path, $csv ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing temp attachment for wp_mail().
			$attachment   = $tmp_path;
			$has_tmp_file = true;
		}

		$site_name = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';
		$subject   = sprintf(
			/* translators: 1: site name (or fallback), 2: order number. */
			__( '[%1$s] PirateShip shipping plan for Order #%2$s', 'fk-usps-optimizer' ),
			'' !== $site_name ? $site_name : __( 'Store', 'fk-usps-optimizer' ),
			$order->get_order_number()
		);

		$body = $this->build_email_body( $order, $plan );

		/**
		 * Filter the PirateShip notification email arguments before sending.
		 *
		 * @param array     $args  {
		 *     @type string[] $to          Recipient email addresses.
		 *     @type string   $subject     Email subject line.
		 *     @type string   $body        Plain-text email body.
		 *     @type string[] $attachments File paths to attach (CSV file).
		 *     @type string[] $headers     Email headers.
		 * }
		 * @param \WC_Order $order Order being notified.
		 * @param array     $plan  Order plan.
		 */
		$args = (array) apply_filters(
			'fk_usps_optimizer_pirateship_notification_email_args',
			array(
				'to'          => $recipients,
				'subject'     => $subject,
				'body'        => $body,
				'attachments' => '' !== $attachment ? array( $attachment ) : array(),
				'headers'     => array( 'Content-Type: text/plain; charset=UTF-8' ),
			),
			$order,
			$plan
		);

		$sent = (bool) wp_mail(
			$args['to'] ?? $recipients,
			(string) ( $args['subject'] ?? $subject ),
			(string) ( $args['body'] ?? $body ),
			(array) ( $args['headers'] ?? array() ),
			(array) ( $args['attachments'] ?? array() )
		);

		if ( $has_tmp_file && file_exists( $tmp_path ) ) {
			@unlink( $tmp_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort cleanup of temp CSV attachment.
		}

		return $sent;
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
