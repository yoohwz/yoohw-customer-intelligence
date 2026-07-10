<?php
defined( 'ABSPATH' ) || exit;

final class YoOhw_COS_Customer_Exporter {

	public static function maybe_handle_request(): void {
		$export_requested = isset( $_REQUEST['yoohw_cos_export_customers'] )
			? sanitize_text_field( wp_unslash( $_REQUEST['yoohw_cos_export_customers'] ) )
			: '';

		if ( '1' !== $export_requested ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to export customers.', 'yoohw-customer-intelligence' ) );
		}

		$nonce = isset( $_REQUEST['yoohw_cos_customers_export_nonce'] )
			? sanitize_text_field( wp_unslash( $_REQUEST['yoohw_cos_customers_export_nonce'] ) )
			: '';

		if ( ! wp_verify_nonce( $nonce, 'yoohw_cos_export_customers' ) ) {
			wp_die( esc_html__( 'Customer export request could not be verified.', 'yoohw-customer-intelligence' ) );
		}

		$source             = wp_unslash( $_REQUEST );
		$args               = YoOhw_COS_Customer_Query::sanitize_args( is_array( $source ) ? $source : array() );
		$args['paged']      = 1;
		$args['offset']     = 0;
		$args['per_page']   = self::get_export_limit();
		$customers          = YoOhw_COS_Customer_Query::query( $args );
		$customer_rows      = $customers['items'];
		$matching_customers = absint( $customers['total_items'] );

		self::send_csv( $customer_rows, $matching_customers );
	}

	public static function get_export_limit(): int {
		/**
		 * Filters the maximum number of customers exported in one CSV request.
		 *
		 * @param int $limit Maximum export rows.
		 */
		$limit = absint( apply_filters( 'yoohw_cos_customer_csv_export_limit', 5000 ) );

		if ( $limit <= 0 ) {
			return 5000;
		}

		return min( $limit, 5000 );
	}

	private static function send_csv( array $customers, int $matching_customers ): void {
		if ( headers_sent() ) {
			wp_die( esc_html__( 'Customer export could not start because output was already sent.', 'yoohw-customer-intelligence' ) );
		}

		$customer_ids = array_map(
			static function( array $customer ): int {
				return absint( $customer['id'] ?? 0 );
			},
			$customers
		);
		$customer_ids = array_values( array_filter( array_unique( $customer_ids ) ) );
		$tags         = self::get_relationship_names( $customer_ids, 'tags' );
		$segments     = self::get_relationship_names( $customer_ids, 'segments' );
		$filename     = 'yoohw-customers-' . gmdate( 'Y-m-d-His' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-YoOhw-COS-Export-Limit: ' . self::get_export_limit() );
		header( 'X-YoOhw-COS-Export-Matching-Customers: ' . $matching_customers );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- CSV is streamed directly to the HTTP response.
		$output = fopen( 'php://output', 'w' );

		if ( ! is_resource( $output ) ) {
			wp_die( esc_html__( 'Customer export file could not be opened.', 'yoohw-customer-intelligence' ) );
		}

		// UTF-8 BOM keeps spreadsheet apps from misreading non-ASCII customer data.
		fwrite( $output, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

		self::write_csv_row(
			$output,
			array(
				__( 'Name', 'yoohw-customer-intelligence' ),
				__( 'Email', 'yoohw-customer-intelligence' ),
				__( 'Phone', 'yoohw-customer-intelligence' ),
				__( 'Orders', 'yoohw-customer-intelligence' ),
				__( 'Spent', 'yoohw-customer-intelligence' ),
				__( 'AOV', 'yoohw-customer-intelligence' ),
				__( 'Risk score', 'yoohw-customer-intelligence' ),
				__( 'Trust score', 'yoohw-customer-intelligence' ),
				__( 'Value tier', 'yoohw-customer-intelligence' ),
				__( 'Lifecycle', 'yoohw-customer-intelligence' ),
				__( 'Tags', 'yoohw-customer-intelligence' ),
				__( 'Segments', 'yoohw-customer-intelligence' ),
			)
		);

		foreach ( $customers as $customer ) {
			$customer_id = absint( $customer['id'] ?? 0 );

			self::write_csv_row(
				$output,
				array(
					self::get_customer_name( $customer ),
					sanitize_email( (string) ( $customer['email'] ?? '' ) ),
					sanitize_text_field( (string) ( $customer['phone'] ?? '' ) ),
					absint( $customer['total_orders'] ?? 0 ),
					self::format_decimal( $customer['total_spent'] ?? 0 ),
					self::format_decimal( $customer['average_order_value'] ?? 0 ),
					self::format_decimal( $customer['risk_score'] ?? 0 ),
					self::format_decimal( $customer['trust_score'] ?? 0 ),
					self::get_vip_label( (string) ( $customer['vip_status'] ?? 'none' ) ),
					self::get_lifecycle_label( (string) ( $customer['lifecycle_stage'] ?? 'new' ) ),
					implode( '; ', $tags[ $customer_id ] ?? array() ),
					implode( '; ', $segments[ $customer_id ] ?? array() ),
				)
			);
		}

		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	private static function write_csv_row( $output, array $row ): void {
		fputcsv( $output, $row, ',', '"', '\\' );
	}

	private static function get_relationship_names( array $customer_ids, string $relationship ): array {
		global $wpdb;

		$names = array();

		if ( empty( $customer_ids ) ) {
			return $names;
		}

		if ( 'tags' === $relationship ) {
			$item_table    = YoOhw_COS_DB::tags_table();
			$join_table    = YoOhw_COS_DB::customer_tags_table();
		} elseif ( 'segments' === $relationship ) {
			$item_table    = YoOhw_COS_DB::segments_table();
			$join_table    = YoOhw_COS_DB::customer_segments_table();
		} else {
			return $names;
		}

		foreach ( array_chunk( $customer_ids, 500 ) as $chunk ) {
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );

			if ( 'tags' === $relationship ) {
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- IN placeholders are generated from absint customer IDs.
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT rel.customer_id, item.name
						FROM %i item
						INNER JOIN %i rel ON rel.tag_id = item.id
						WHERE rel.customer_id IN ({$placeholders})
						ORDER BY rel.customer_id ASC, item.name ASC",
						...array_merge( array( $item_table, $join_table ), $chunk )
					),
					ARRAY_A
				);
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			} else {
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- IN placeholders are generated from absint customer IDs.
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT rel.customer_id, item.name
						FROM %i item
						INNER JOIN %i rel ON rel.segment_id = item.id
						WHERE rel.customer_id IN ({$placeholders})
						ORDER BY rel.customer_id ASC, item.name ASC",
						...array_merge( array( $item_table, $join_table ), $chunk )
					),
					ARRAY_A
				);
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			}

			if ( ! is_array( $rows ) ) {
				continue;
			}

			foreach ( $rows as $row ) {
				$customer_id = absint( $row['customer_id'] ?? 0 );
				$name        = sanitize_text_field( (string) ( $row['name'] ?? '' ) );

				if ( $customer_id > 0 && '' !== $name ) {
					$names[ $customer_id ][] = $name;
				}
			}
		}

		return $names;
	}

	private static function get_customer_name( array $customer ): string {
		$name = sanitize_text_field( (string) ( $customer['display_name'] ?? '' ) );

		if ( '' !== $name ) {
			return $name;
		}

		$name = trim(
			sanitize_text_field( (string) ( $customer['first_name'] ?? '' ) ) . ' ' .
			sanitize_text_field( (string) ( $customer['last_name'] ?? '' ) )
		);

		return '' !== $name ? $name : __( '(No name)', 'yoohw-customer-intelligence' );
	}

	private static function format_decimal( $value ): string {
		return number_format( (float) $value, 2, '.', '' );
	}

	private static function get_vip_label( string $vip_status ): string {
		return YoOhw_COS_Intelligence::get_value_tier_label( $vip_status );
	}

	private static function get_lifecycle_label( string $stage ): string {
		$stage = sanitize_key( $stage );

		$labels = array(
			'new'     => __( 'New', 'yoohw-customer-intelligence' ),
			'repeat'  => __( 'Repeat', 'yoohw-customer-intelligence' ),
			'loyal'   => __( 'Loyal', 'yoohw-customer-intelligence' ),
			'vip'     => __( 'VIP', 'yoohw-customer-intelligence' ),
			'dormant' => __( 'Dormant', 'yoohw-customer-intelligence' ),
		);

		return $labels[ $stage ] ?? ucfirst( str_replace( '_', ' ', $stage ) );
	}
}
