<?php
defined( 'ABSPATH' ) || exit;

final class YoOhw_COS_Customers {

	public const SYNC_ORDER = 'oldest_first';
	public const ORDER_CUSTOMER_META_KEY = '_yoohw_cos_customer_id';

	public static function init(): void {
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'sync_from_order_id' ), 20 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'sync_from_order_id' ), 20 );
	}

	public static function sync_from_order_id( int $order_id ): int {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return 0;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order || ! $order instanceof WC_Order ) {
			return 0;
		}

		if ( $order instanceof WC_Order_Refund ) {
			return 0;
		}

		return self::sync_from_order( $order );
	}

	public static function sync_from_order( WC_Order $order ): int {
		$wp_user_id = absint( $order->get_customer_id() );
		$email      = sanitize_email( $order->get_billing_email() );
		$phone      = sanitize_text_field( $order->get_billing_phone() );
		$metrics    = self::get_customer_order_metrics( $wp_user_id, $email );
		$order_id   = absint( $order->get_id() );
		$order_date = $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : YoOhw_COS_DB::now();

		$customer_id = self::find_customer_id_from_order( $order, $wp_user_id, $email, $phone );
		$last_order_data = self::get_last_order_data_for_sync( $customer_id, $order_id, $order_date );

		$data = array(
			'wp_user_id'          => $wp_user_id ?: null,
			'email'               => $email ?: null,
			'phone'               => $phone ?: null,
			'first_name'          => sanitize_text_field( $order->get_billing_first_name() ),
			'last_name'           => sanitize_text_field( $order->get_billing_last_name() ),
			'display_name'        => trim( $order->get_formatted_billing_full_name() ),
			'total_orders'        => $metrics['total_orders'],
			'total_spent'         => $metrics['total_spent'],
			'average_order_value' => 0,
			'last_order_id'       => $last_order_data['last_order_id'],
			'last_order_date'     => $last_order_data['last_order_date'],
			'last_activity_date'  => YoOhw_COS_DB::now(),
			'updated_at'          => YoOhw_COS_DB::now(),
		);

		$first_order_data = self::get_customer_first_order_data( $wp_user_id, $email );

		$data['first_order_id']   = $first_order_data['first_order_id'];
		$data['first_order_date'] = $first_order_data['first_order_date'];

		if ( $data['total_orders'] > 0 ) {
			$data['average_order_value'] = (float) $data['total_spent'] / (int) $data['total_orders'];
		}

		$intelligence_data = array_merge(
			is_array( $data ) ? $data : array(),
			array(
				'risk_score'      => 0,
				'trust_score'     => 0,
				'loyalty_score'   => 0,
				'customer_status' => 'active',
				'vip_status'      => 'none',
			)
		);

		$data['customer_status'] = YoOhw_COS_Intelligence::calculate_customer_status( $intelligence_data );
		$data['lifecycle_stage'] = YoOhw_COS_Intelligence::calculate_lifecycle_stage( $intelligence_data );
		$data['vip_status']      = YoOhw_COS_Intelligence::calculate_vip_status( $intelligence_data );
		$data['trust_score']     = YoOhw_COS_Intelligence::calculate_trust_score( $intelligence_data );
		$data['risk_score']      = YoOhw_COS_Intelligence::calculate_risk_score( $intelligence_data );

		if ( $customer_id ) {
			self::update_customer( $customer_id, $data );
		} else {
			$customer_id = self::create_customer( $data );
		}

		self::maybe_link_order_to_customer( $order, $customer_id );

		if (
			$customer_id
			&& ! YoOhw_COS_Events::event_exists(
				'order_synced',
				'order',
				$order_id,
				$customer_id
			)
		) {
			YoOhw_COS_Events::record( array(
				'customer_id'  => $customer_id,
				'wp_user_id'   => $wp_user_id,
				'event_type'   => 'order_synced',
				'event_source' => 'woocommerce',
				'severity'     => 'info',
				'object_type'  => 'order',
				'object_id'    => $order_id,
				'description'  => sprintf(
					/* translators: %s: order number */
					__( 'Order #%s synced to customer profile.', 'yoohw-customer-intelligence' ),
					$order->get_order_number()
				),
				'metadata'     => array(
					'order_status' => $order->get_status(),
					'order_total'  => $order->get_total(),
					'currency'     => $order->get_currency(),
				),
			) );
		}

		return $customer_id;
	}

	private static function maybe_link_order_to_customer( WC_Order $order, int $customer_id ): void {
		$customer_id = absint( $customer_id );

		if ( $customer_id <= 0 ) {
			return;
		}

		$current_customer_id = absint( $order->get_meta( self::ORDER_CUSTOMER_META_KEY, true ) );

		if ( $current_customer_id === $customer_id ) {
			return;
		}

		$order->update_meta_data( self::ORDER_CUSTOMER_META_KEY, $customer_id );
		$order->save();
	}

	private static function get_last_order_data_for_sync( int $customer_id, int $order_id, string $order_date ): array {
		$last_order_id   = $order_id;
		$last_order_date = $order_date;

		if ( $customer_id <= 0 ) {
			return array(
				'last_order_id'   => $last_order_id,
				'last_order_date' => $last_order_date,
			);
		}

		$customer = self::get_customer( $customer_id );

		if ( empty( $customer ) ) {
			return array(
				'last_order_id'   => $last_order_id,
				'last_order_date' => $last_order_date,
			);
		}

		$existing_last_order_id   = absint( $customer['last_order_id'] ?? 0 );
		$existing_last_order_date = sanitize_text_field( (string) ( $customer['last_order_date'] ?? '' ) );
		$existing_timestamp       = YoOhw_COS_DB::date_timestamp( $existing_last_order_date );
		$current_timestamp        = YoOhw_COS_DB::date_timestamp( $order_date );

		if (
			$existing_last_order_id > 0
			&& (
				$existing_timestamp > $current_timestamp
				|| ( $existing_timestamp === $current_timestamp && $existing_last_order_id > $order_id )
			)
		) {
			$last_order_id   = $existing_last_order_id;
			$last_order_date = $existing_last_order_date;
		}

		return array(
			'last_order_id'   => $last_order_id,
			'last_order_date' => $last_order_date,
		);
	}

	public static function find_customer_id_from_order( WC_Order $order, int $wp_user_id = 0, string $email = '', string $phone = '' ): int {
		$linked_customer_id = absint( $order->get_meta( self::ORDER_CUSTOMER_META_KEY, true ) );

		if ( $linked_customer_id > 0 && self::customer_exists( $linked_customer_id ) ) {
			return $linked_customer_id;
		}

		return self::find_customer_id(
			array(
				'wp_user_id' => $wp_user_id,
				'email'      => $email,
				'phone'      => $phone,
			)
		);
	}

	public static function find_customer_id( array $args ): int {
		global $wpdb;

		$table = YoOhw_COS_DB::customers_table();

		$wp_user_id = ! empty( $args['wp_user_id'] ) ? absint( $args['wp_user_id'] ) : 0;
		$email      = ! empty( $args['email'] ) ? sanitize_email( $args['email'] ) : '';
		$phone      = ! empty( $args['phone'] ) ? sanitize_text_field( $args['phone'] ) : '';

		if ( $wp_user_id ) {
			$id = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM %i WHERE wp_user_id = %d LIMIT 1',
					$table,
					$wp_user_id
				)
			);

			if ( $id ) {
				return absint( $id );
			}
		}

		if ( $email ) {
			$id = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM %i WHERE email = %s LIMIT 1',
					$table,
					$email
				)
			);

			if ( $id ) {
				return absint( $id );
			}
		}

		if ( $phone ) {
			$id = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM %i WHERE phone = %s LIMIT 1',
					$table,
					$phone
				)
			);

			if ( $id ) {
				return absint( $id );
			}
		}

		return 0;
	}

	public static function create_customer( array $data ): int {
		global $wpdb;

		$table = YoOhw_COS_DB::customers_table();

		$data['created_at'] = $data['created_at'] ?? YoOhw_COS_DB::now();
		$data['updated_at'] = $data['updated_at'] ?? YoOhw_COS_DB::now();
		$prepared           = self::prepare_customer_data( $data );

		$inserted = $wpdb->insert(
			$table,
			$prepared,
			self::customer_data_formats( $prepared )
		);

		return $inserted ? absint( $wpdb->insert_id ) : 0;
	}

	public static function update_customer( int $customer_id, array $data ): bool {
		global $wpdb;

		$table = YoOhw_COS_DB::customers_table();

		$data['updated_at'] = YoOhw_COS_DB::now();
		$prepared           = self::prepare_customer_data( $data );

		$updated = $wpdb->update(
			$table,
			$prepared,
			array( 'id' => absint( $customer_id ) ),
			self::customer_data_formats( $prepared ),
			array( '%d' )
		);

		return false !== $updated;
	}

	private static function prepare_customer_data( array $data ): array {
		$prepared = array();

		foreach ( self::customer_data_format_map() as $key => $format ) {
			if ( array_key_exists( $key, $data ) ) {
				$prepared[ $key ] = $data[ $key ];
			}
		}

		return $prepared;
	}

	private static function customer_data_formats( array $prepared_data ): array {
		$formats    = array();
		$format_map = self::customer_data_format_map();

		foreach ( array_keys( $prepared_data ) as $key ) {
			if ( isset( $format_map[ $key ] ) ) {
				$formats[] = $format_map[ $key ];
			}
		}

		return $formats;
	}

	private static function customer_data_format_map(): array {
		return array(
			'wp_user_id'          => '%d',
			'email'               => '%s',
			'phone'               => '%s',
			'first_name'          => '%s',
			'last_name'           => '%s',
			'display_name'        => '%s',
			'total_orders'        => '%d',
			'total_spent'         => '%f',
			'average_order_value' => '%f',
			'risk_score'          => '%f',
			'trust_score'         => '%f',
			'loyalty_score'       => '%f',
			'customer_status'     => '%s',
			'vip_status'          => '%s',
			'first_order_id'      => '%d',
			'first_order_date'    => '%s',
			'last_order_id'       => '%d',
			'last_order_date'     => '%s',
			'last_activity_date'  => '%s',
			'lifecycle_stage'     => '%s',
			'archived_at'         => '%s',
			'archived_by'         => '%d',
			'archive_reason'      => '%s',
			'created_at'          => '%s',
			'updated_at'          => '%s',
		);
	}

	public static function archive_customer( int $customer_id, int $archived_by = 0, string $reason = '' ): bool {
		$customer_id = absint( $customer_id );
		$customer    = self::get_customer( $customer_id );

		if ( empty( $customer ) || self::is_archived( $customer ) ) {
			return false;
		}

		$updated = self::update_customer(
			$customer_id,
			array(
				'archived_at'    => YoOhw_COS_DB::now(),
				'archived_by'    => absint( $archived_by ?: get_current_user_id() ),
				'archive_reason' => sanitize_textarea_field( $reason ),
			)
		);

		if ( $updated ) {
			YoOhw_COS_Events::record(
				array(
					'customer_id'  => $customer_id,
					'event_type'   => 'customer_archived',
					'event_source' => 'customer_os',
					'severity'     => 'warning',
					'description'  => __( 'Customer archived.', 'yoohw-customer-intelligence' ),
				)
			);
		}

		return $updated;
	}

	public static function restore_customer( int $customer_id ): bool {
		$customer_id = absint( $customer_id );
		$customer    = self::get_customer( $customer_id );

		if ( empty( $customer ) || ! self::is_archived( $customer ) ) {
			return false;
		}

		$updated = self::update_customer(
			$customer_id,
			array(
				'archived_at'    => null,
				'archived_by'    => null,
				'archive_reason' => null,
			)
		);

		if ( $updated ) {
			YoOhw_COS_Events::record(
				array(
					'customer_id'  => $customer_id,
					'event_type'   => 'customer_restored',
					'event_source' => 'customer_os',
					'severity'     => 'success',
					'description'  => __( 'Customer restored from archive.', 'yoohw-customer-intelligence' ),
				)
			);
		}

		return $updated;
	}

	public static function is_archived( array $customer ): bool {
		return ! empty( $customer['archived_at'] );
	}

	public static function get_archived_count(): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE archived_at IS NOT NULL',
				YoOhw_COS_DB::customers_table()
			)
		);
	}

	private static function get_customer_order_metrics( int $wp_user_id, string $email ): array {
		return array(
			'total_orders' => self::get_customer_order_count( $wp_user_id, $email ),
			'total_spent'  => self::get_customer_total_spent( $wp_user_id, $email ),
		);
	}

	private static function get_customer_order_count( int $wp_user_id, string $email ): int {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}

		$args = array(
			'type'     => 'shop_order',
			'limit'    => 1,
			'page'     => 1,
			'paginate' => true,
			'return'   => 'ids',
			'status'   => array_keys( wc_get_order_statuses() ),
		);

		if ( $wp_user_id ) {
			$args['customer_id'] = $wp_user_id;
		} elseif ( $email ) {
			$args['billing_email'] = $email;
		} else {
			return 0;
		}

		$orders = wc_get_orders( $args );

		if ( is_object( $orders ) && isset( $orders->total ) ) {
			return absint( $orders->total );
		}

		return is_array( $orders ) ? count( $orders ) : 0;
	}

	private static function get_customer_total_spent( int $wp_user_id, string $email ): float {
		if ( ! function_exists( 'wc_get_orders' ) || ! function_exists( 'wc_get_order' ) ) {
			return 0.0;
		}

		$args = array(
			'type'     => 'shop_order',
			'limit'    => 100,
			'page'     => 1,
			'paginate' => true,
			'return'   => 'ids',
			'status'   => array( 'wc-completed', 'wc-processing' ),
		);

		if ( $wp_user_id ) {
			$args['customer_id'] = $wp_user_id;
		} elseif ( $email ) {
			$args['billing_email'] = $email;
		} else {
			return 0.0;
		}

		$total     = 0.0;
		$max_pages = 1;

		do {
			$result = wc_get_orders( $args );
			$orders = array();

			if ( is_object( $result ) && isset( $result->orders ) ) {
				$orders    = is_array( $result->orders ) ? $result->orders : array();
				$max_pages = isset( $result->max_num_pages ) ? max( 1, absint( $result->max_num_pages ) ) : 1;
			} elseif ( is_array( $result ) ) {
				$orders    = $result;
				$max_pages = 1;
			}

			foreach ( $orders as $order_id ) {
				$order = wc_get_order( absint( $order_id ) );

				if ( $order instanceof WC_Order ) {
					$total += (float) $order->get_total();
				}
			}

			$args['page']++;
		} while ( $args['page'] <= $max_pages );

		return $total;
	}

	public static function get_customer( int $customer_id ): array {
		global $wpdb;

		$table = YoOhw_COS_DB::customers_table();

		$customer = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d LIMIT 1',
				$table,
				absint( $customer_id )
			),
			ARRAY_A
		);

		return is_array( $customer ) ? $customer : array();
	}

	public static function customer_exists( int $customer_id ): bool {
		return ! empty( self::get_customer( $customer_id ) );
	}

	public static function sync_existing_orders( int $limit = 200, int $page = 1 ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array(
				'processed' => 0,
				'scanned'   => 0,
				'has_more'  => false,
				'next_page' => 1,
			);
		}

		$limit = max( 1, absint( $limit ) );
		$page  = max( 1, absint( $page ) );

		// Process oldest orders first so newer order data wins at the end of a full sync.
		$order_ids = wc_get_orders(
			array(
				'type'    => 'shop_order',
				'limit'   => $limit,
				'page'    => $page,
				'paginate' => false,
				'orderby' => 'date',
				'order'   => 'ASC',
				'return'  => 'ids',
				'status'  => array_keys( wc_get_order_statuses() ),
			)
		);

		if ( empty( $order_ids ) || ! is_array( $order_ids ) ) {
			return array(
				'processed' => 0,
				'scanned'   => 0,
				'has_more'  => false,
				'next_page' => $page,
			);
		}

		$processed = 0;

		foreach ( $order_ids as $order_id ) {
			$customer_id = self::sync_from_order_id( absint( $order_id ) );

			if ( $customer_id ) {
				$processed++;
			}
		}

		return array(
			'processed' => $processed,
			'scanned'   => count( $order_ids ),
			'has_more'  => count( $order_ids ) >= $limit,
			'next_page' => $page + 1,
		);
	}

	public static function get_sync_order_count(): int {
		if ( ! function_exists( 'wc_get_orders' ) || ! function_exists( 'wc_get_order_statuses' ) ) {
			return 0;
		}

		$result = wc_get_orders(
			array(
				'type'     => 'shop_order',
				'limit'    => 1,
				'page'     => 1,
				'paginate' => true,
				'orderby'  => 'date',
				'order'    => 'DESC',
				'return'   => 'ids',
				'status'   => array_keys( wc_get_order_statuses() ),
			)
		);

		if ( is_object( $result ) && isset( $result->total ) ) {
			return absint( $result->total );
		}

		return is_array( $result ) ? count( $result ) : 0;
	}

	public static function get_stats(): array {
		global $wpdb;

		$table = YoOhw_COS_DB::customers_table();

		$total_customers = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE archived_at IS NULL', $table )
		);

		$total_orders = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT SUM(total_orders) FROM %i WHERE archived_at IS NULL', $table )
		);

		$total_spent = (float) $wpdb->get_var(
			$wpdb->prepare( 'SELECT SUM(total_spent) FROM %i WHERE archived_at IS NULL', $table )
		);

		return array(
			'total_customers' => $total_customers,
			'total_orders'    => $total_orders,
			'total_spent'     => $total_spent,
		);
	}

	public static function reset_data(): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', YoOhw_COS_DB::customers_table() ) );
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', YoOhw_COS_DB::events_table() ) );
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', YoOhw_COS_DB::notes_table() ) );
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', YoOhw_COS_DB::tasks_table() ) );
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', YoOhw_COS_DB::customer_tags_table() ) );
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', YoOhw_COS_DB::customer_segments_table() ) );

		delete_option( 'yoohw_cos_last_sync_page' );
		delete_option( 'yoohw_cos_last_sync_at' );
		delete_option( 'yoohw_cos_sync_state' );
	}

	public static function recalculate_intelligence( int $limit = 500, int $page = 1 ): array {
		global $wpdb;

		$table  = YoOhw_COS_DB::customers_table();
		$limit  = max( 1, absint( $limit ) );
		$page   = max( 1, absint( $page ) );
		$offset = ( $page - 1 ) * $limit;

		$customers = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT *
				FROM %i
				ORDER BY id ASC
				LIMIT %d OFFSET %d",
				$table,
				$limit,
				$offset
			),
			ARRAY_A
		);

		if ( empty( $customers ) ) {
			return array(
				'updated'   => 0,
				'has_more'  => false,
				'next_page' => $page,
			);
		}

		$updated_count = 0;

		foreach ( $customers as $customer ) {
			$customer_id = absint( $customer['id'] );

			$new_status = YoOhw_COS_Intelligence::calculate_customer_status( $customer );
			$new_lifecycle = YoOhw_COS_Intelligence::calculate_lifecycle_stage( $customer );
			$new_vip    = YoOhw_COS_Intelligence::calculate_vip_status( $customer );
			$new_trust  = YoOhw_COS_Intelligence::calculate_trust_score( $customer );
			$new_risk   = YoOhw_COS_Intelligence::calculate_risk_score( $customer );

			$updated = self::update_customer(
				$customer_id,
				array(
					'customer_status' => $new_status,
					'lifecycle_stage' => $new_lifecycle,
					'vip_status'      => $new_vip,
					'trust_score'     => $new_trust,
					'risk_score'      => $new_risk,
				)
			);

			if ( $updated ) {
				$updated_count++;
			}
		}

		return array(
			'updated'   => $updated_count,
			'has_more'  => count( $customers ) >= $limit,
			'next_page' => $page + 1,
		);
	}

	public static function get_status_counts(): array {
		global $wpdb;

		$table = YoOhw_COS_DB::customers_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT customer_status, COUNT(*) as total
				FROM %i
				WHERE archived_at IS NULL
				GROUP BY customer_status",
				$table
			),
			ARRAY_A
		);

		$counts = array(
			'new'      => 0,
			'active'   => 0,
			'at_risk'  => 0,
			'inactive' => 0,
			'vip'      => 0,
		);

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$status = sanitize_key( $row['customer_status'] ?? '' );

				if ( isset( $counts[ $status ] ) ) {
					$counts[ $status ] = absint( $row['total'] ?? 0 );
				}
			}
		}

		return $counts;
	}

	public static function get_vip_counts(): array {
		global $wpdb;

		$table = YoOhw_COS_DB::customers_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT vip_status, COUNT(*) as total
				FROM %i
				WHERE archived_at IS NULL
				GROUP BY vip_status",
				$table
			),
			ARRAY_A
		);

		$counts = array(
			'none'     => 0,
			'silver'   => 0,
			'gold'     => 0,
			'platinum' => 0,
		);

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$status = sanitize_key( $row['vip_status'] ?? 'none' );

				if ( isset( $counts[ $status ] ) ) {
					$counts[ $status ] = absint( $row['total'] ?? 0 );
				}
			}
		}

		return $counts;
	}

	public static function get_risk_counts(): array {
		global $wpdb;

		$table = YoOhw_COS_DB::customers_table();

		$counts = array(
			'none'   => 0,
			'low'    => 0,
			'medium' => 0,
			'high'   => 0,
		);

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
				SUM(CASE WHEN risk_score < 15 THEN 1 ELSE 0 END) AS none_count,
				SUM(CASE WHEN risk_score >= 15 AND risk_score < 40 THEN 1 ELSE 0 END) AS low_count,
				SUM(CASE WHEN risk_score >= 40 AND risk_score < 70 THEN 1 ELSE 0 END) AS medium_count,
				SUM(CASE WHEN risk_score >= 70 THEN 1 ELSE 0 END) AS high_count
				FROM %i
				WHERE archived_at IS NULL",
				$table
			),
			ARRAY_A
		);

		if ( empty( $row ) ) {
			return $counts;
		}

		$counts['none']   = absint( $row['none_count'] ?? 0 );
		$counts['low']    = absint( $row['low_count'] ?? 0 );
		$counts['medium'] = absint( $row['medium_count'] ?? 0 );
		$counts['high']   = absint( $row['high_count'] ?? 0 );

		return $counts;
	}

	public static function get_customer_orders( array $customer, int $limit = 10 ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$args = array(
			'type'    => 'shop_order',
			'limit'   => absint( $limit ),
			'orderby' => 'date',
			'order'   => 'DESC',
			'return'  => 'objects',
			'status'  => array_keys( wc_get_order_statuses() ),
		);

		$has_identity = false;

		if ( ! empty( $customer['wp_user_id'] ) ) {
			$args['customer_id'] = absint( $customer['wp_user_id'] );
			$has_identity        = true;
		} elseif ( ! empty( $customer['email'] ) ) {
			$args['billing_email'] = sanitize_email( $customer['email'] );
			$has_identity          = true;
		}

		$orders = $has_identity ? wc_get_orders( $args ) : array();
		$orders = is_array( $orders ) ? $orders : array();
		$customer_id = absint( $customer['id'] ?? 0 );

		if ( $customer_id > 0 ) {
			$linked_orders = wc_get_orders(
				array(
					'type'       => 'shop_order',
					'limit'      => absint( $limit ),
					'orderby'    => 'date',
					'order'      => 'DESC',
					'return'     => 'objects',
					'status'     => array_keys( wc_get_order_statuses() ),
					'meta_key'   => self::ORDER_CUSTOMER_META_KEY,
					'meta_value' => $customer_id,
				)
			);

			if ( is_array( $linked_orders ) ) {
				$orders = array_merge( $orders, $linked_orders );
			}
		}

		$orders_by_id = array();

		foreach ( $orders as $order ) {
			if ( $order instanceof WC_Order ) {
				$orders_by_id[ absint( $order->get_id() ) ] = $order;
			}
		}

		$orders = array_values( $orders_by_id );

		usort(
			$orders,
			static function( WC_Order $a, WC_Order $b ): int {
				$a_time = $a->get_date_created() ? $a->get_date_created()->getTimestamp() : 0;
				$b_time = $b->get_date_created() ? $b->get_date_created()->getTimestamp() : 0;

				return $b_time <=> $a_time;
			}
		);

		return array_slice( $orders, 0, absint( $limit ) );
	}

	public static function find_customer_id_by_search( string $search ): int {
		global $wpdb;

		$search = trim( $search );

		if ( '' === $search ) {
			return 0;
		}

		$table = YoOhw_COS_DB::customers_table();

		$normalized_id = 0;

		if ( preg_match( '/^#?(\d+)$/', $search, $matches ) ) {
			$normalized_id = absint( $matches[1] );
		} elseif ( preg_match( '/^order[:\s#-]*(\d+)$/i', $search, $matches ) ) {
			$normalized_id = absint( $matches[1] );
		} elseif ( preg_match( '/^customer[:\s#-]*(\d+)$/i', $search, $matches ) ) {
			$normalized_id = absint( $matches[1] );
		} elseif ( preg_match( '/^user[:\s#-]*(\d+)$/i', $search, $matches ) ) {
			$normalized_id = absint( $matches[1] );
		}

		$where  = 'WHERE 1=1';
		$params = array();

		$like = '%' . $wpdb->esc_like( $search ) . '%';

		$where .= ' AND (
			display_name LIKE %s
			OR first_name LIKE %s
			OR last_name LIKE %s
			OR email LIKE %s
			OR phone LIKE %s
		';

		$params[] = $like;
		$params[] = $like;
		$params[] = $like;
		$params[] = $like;
		$params[] = $like;

		if ( $normalized_id > 0 ) {
			$where .= '
				OR id = %d
				OR wp_user_id = %d
				OR last_order_id = %d
			';

			$params[] = $normalized_id;
			$params[] = $normalized_id;
			$params[] = $normalized_id;

			if ( function_exists( 'wc_get_order' ) ) {
				$order = wc_get_order( $normalized_id );

				if ( $order instanceof WC_Order ) {
					$order_customer_id = absint( $order->get_customer_id() );
					$order_email       = sanitize_email( $order->get_billing_email() );

					if ( $order_customer_id > 0 ) {
						$where .= ' OR wp_user_id = %d';
						$params[] = $order_customer_id;
					}

					if ( $order_email ) {
						$where .= ' OR email = %s';
						$params[] = $order_email;
					}
				}
			}
		}

		$where .= ')';
		$where .= ' AND archived_at IS NULL';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Search SQL fragments are hardcoded; values are passed through placeholders.
		$matches = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM %i {$where} LIMIT 2",
				...array_merge( array( $table ), $params )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( is_array( $matches ) && 1 === count( $matches ) ) {
			return absint( $matches[0] );
		}

		return 0;
	}

	public static function get_customer_first_order_data( int $wp_user_id, string $email ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array(
				'first_order_id'   => null,
				'first_order_date' => null,
			);
		}

		$args = array(
			'type'    => 'shop_order',
			'limit'   => 1,
			'orderby' => 'date',
			'order'   => 'ASC',
			'return'  => 'objects',
			'status'  => array_keys( wc_get_order_statuses() ),
		);

		if ( $wp_user_id ) {
			$args['customer_id'] = $wp_user_id;
		} elseif ( $email ) {
			$args['billing_email'] = $email;
		} else {
			return array(
				'first_order_id'   => null,
				'first_order_date' => null,
			);
		}

		$orders = wc_get_orders( $args );

		if ( empty( $orders ) || ! $orders[0] instanceof WC_Order ) {
			return array(
				'first_order_id'   => null,
				'first_order_date' => null,
			);
		}

		$order = $orders[0];

		return array(
			'first_order_id'   => absint( $order->get_id() ),
			'first_order_date' => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : null,
		);
	}

	public static function backfill_first_order_data( int $limit = 500, int $page = 1 ): array {
		global $wpdb;

		$table  = YoOhw_COS_DB::customers_table();
		$limit  = max( 1, absint( $limit ) );
		$page   = max( 1, absint( $page ) );
		$offset = ( $page - 1 ) * $limit;

		$customers = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, wp_user_id, email
				FROM %i
				ORDER BY id ASC
				LIMIT %d OFFSET %d",
				$table,
				$limit,
				$offset
			),
			ARRAY_A
		);

		if ( empty( $customers ) ) {
			return array(
				'updated'   => 0,
				'has_more'  => false,
				'next_page' => $page,
			);
		}

		$updated_count = 0;

		foreach ( $customers as $customer ) {
			$first_order = self::get_customer_first_order_data(
				absint( $customer['wp_user_id'] ?? 0 ),
				sanitize_email( $customer['email'] ?? '' )
			);

			$updated = self::update_customer(
				absint( $customer['id'] ),
				array(
					'first_order_id'   => $first_order['first_order_id'],
					'first_order_date' => $first_order['first_order_date'],
				)
			);

			if ( $updated ) {
				$updated_count++;
			}
		}

		return array(
			'updated'   => $updated_count,
			'has_more'  => count( $customers ) >= $limit,
			'next_page' => $page + 1,
		);
	}

	public static function get_lifecycle_counts(): array {
		global $wpdb;

		$table = YoOhw_COS_DB::customers_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT lifecycle_stage, COUNT(*) as total
				FROM %i
				WHERE archived_at IS NULL
				GROUP BY lifecycle_stage",
				$table
			),
			ARRAY_A
		);

		$counts = array(
			'new'     => 0,
			'repeat'  => 0,
			'loyal'   => 0,
			'vip'     => 0,
			'dormant' => 0,
		);

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$stage = sanitize_key( $row['lifecycle_stage'] ?? 'new' );

				if ( isset( $counts[ $stage ] ) ) {
					$counts[ $stage ] = absint( $row['total'] ?? 0 );
				}
			}
		}

		return $counts;
	}
}
