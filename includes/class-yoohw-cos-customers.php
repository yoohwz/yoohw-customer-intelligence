<?php
defined( 'ABSPATH' ) || exit;

final class YoOhw_COS_Customers {

	public const SYNC_ORDER = 'oldest_first';
	public const ORDER_CUSTOMER_META_KEY = '_yoohw_cos_customer_id';
	public const RISK_SCORE_REFRESH_HOOK = 'yoohw_cos_refresh_risk_score_cache';
	public const ORDER_SYNC_RETRY_HOOK = 'yoohw_cos_retry_order_sync';
	private const RISK_SCORE_REFRESH_BATCH_SIZE = 250;

	public static function init(): void {
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'sync_from_order_id' ), 20 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'sync_from_order_id' ), 20 );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'sync_from_order_id' ), 20, 1 );
		add_action( 'woocommerce_refund_deleted', array( __CLASS__, 'sync_after_refund_deleted' ), 20, 2 );
		add_action( 'woocommerce_before_delete_order', array( __CLASS__, 'remove_deleted_order_contribution' ), 20, 2 );
		add_action( 'yoohw_cos_commerce_aggregate_error', array( __CLASS__, 'schedule_failed_order_sync' ), 10, 3 );
		add_action( self::ORDER_SYNC_RETRY_HOOK, array( __CLASS__, 'sync_from_order_id' ), 10, 1 );
		add_action( 'yoohw_cos_recalculate_activity_semantics', array( __CLASS__, 'process_activity_semantics_recalculation' ) );
		add_action( self::RISK_SCORE_REFRESH_HOOK, array( __CLASS__, 'process_risk_score_cache_refresh' ), 10, 1 );
		self::maybe_schedule_activity_semantics_recalculation();
		self::maybe_schedule_risk_score_cache_refresh();
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

	public static function sync_after_refund_deleted( int $refund_id, int $order_id ): int {
		unset( $refund_id );

		return self::sync_from_order_id( $order_id );
	}

	public static function remove_deleted_order_contribution( int $order_id, $order ): void {
		if ( $order instanceof WC_Order_Refund ) {
			return;
		}

		$customer_id = YoOhw_COS_Commerce_Aggregates::remove_order( $order_id );

		if ( $customer_id > 0 ) {
			self::mark_customer_data_updated();
		}
	}

	public static function schedule_failed_order_sync( Throwable $exception, int $order_id, int $customer_id ): void {
		unset( $exception, $customer_id );

		$args = array( absint( $order_id ) );

		if ( $args[0] > 0 && ! wp_next_scheduled( self::ORDER_SYNC_RETRY_HOOK, $args ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::ORDER_SYNC_RETRY_HOOK, $args );
		}
	}

	public static function sync_from_order( WC_Order $order ): int {
		$identity   = YoOhw_COS_Customer_Identity::from_order( $order );
		$wp_user_id = absint( $identity['wp_user_id'] );
		$email      = (string) $identity['email'];
		$phone      = (string) $identity['phone'];
		$order_id   = absint( $order->get_id() );
		$order_date = $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : YoOhw_COS_DB::now();
		$resolution = YoOhw_COS_Customer_Identity::resolve( $identity );
		$customer_id = absint( $resolution['customer_id'] ?? 0 );
		$identity_lock = '';

		if ( $customer_id <= 0 && empty( $resolution['conflicts'] ) ) {
			$identity_lock = YoOhw_COS_Customer_Identity::acquire_creation_lock( $identity );

			if ( '' === $identity_lock ) {
				return 0;
			}

			$resolution  = YoOhw_COS_Customer_Identity::resolve( $identity );
			$customer_id = absint( $resolution['customer_id'] ?? 0 );
		}

		$existing     = $customer_id > 0 ? self::get_customer( $customer_id ) : array();
		$last_activity_date = self::get_last_activity_date_for_sync( $customer_id, $order_date );

		if ( $customer_id <= 0 && ! empty( $resolution['conflicts'] ) ) {
			YoOhw_COS_Customer_Identity::release_creation_lock( $identity_lock );
			return 0;
		}

		$data = array(
			'first_name'          => sanitize_text_field( $order->get_billing_first_name() ),
			'last_name'           => sanitize_text_field( $order->get_billing_last_name() ),
			'display_name'        => trim( $order->get_formatted_billing_full_name() ),
			'last_activity_date'  => $last_activity_date,
			'updated_at'          => YoOhw_COS_DB::now(),
		);

		$data = array_merge( $data, self::get_identity_updates_for_sync( $existing, $identity, (string) ( $resolution['matched_by'] ?? '' ) ) );

		$data = apply_filters( 'yoohw_cos_customer_sync_data', $data, $order, $customer_id );
		$data = is_array( $data ) ? $data : array();

		if ( $customer_id > 0 ) {
			self::update_customer( $customer_id, $data );
		} else {
			$customer_id = self::create_customer( $data );
		}

		YoOhw_COS_Customer_Identity::release_creation_lock( $identity_lock );

		if ( $customer_id <= 0 ) {
			return 0;
		}

		$metrics = YoOhw_COS_Commerce_Aggregates::sync_order( $order, $customer_id );

		if ( empty( $metrics ) ) {
			return 0;
		}

		$data = array_merge( self::get_customer( $customer_id ), $data, $metrics );

		$intelligence_data = array_merge(
			array(
				'risk_score'       => 0,
				'trust_score'      => 0,
				'customer_status'  => 'active',
				'vip_status'       => 'none',
			),
			$data
		);

		$intelligence_data = apply_filters( 'yoohw_cos_customer_intelligence_data', $intelligence_data, $order, $customer_id, $data );

		if ( isset( $intelligence_data['loyalty_score'] ) ) {
			$data['loyalty_score']              = self::normalize_score( $intelligence_data['loyalty_score'] );
			$intelligence_data['loyalty_score'] = $data['loyalty_score'];
		}

		if ( array_key_exists( 'loyalty_level', $intelligence_data ) ) {
			$data['loyalty_level'] = sanitize_key( (string) ( $intelligence_data['loyalty_level'] ?? '' ) );
		}

		if ( array_key_exists( 'available_points', $intelligence_data ) ) {
			$data['available_points'] = (int) ( $intelligence_data['available_points'] ?? 0 );
		}

		if ( array_key_exists( 'earned_points', $intelligence_data ) ) {
			$data['earned_points'] = (int) ( $intelligence_data['earned_points'] ?? 0 );
		}

		$data['customer_status'] = YoOhw_COS_Intelligence::calculate_customer_status( $intelligence_data );
		$data['lifecycle_stage'] = YoOhw_COS_Intelligence::calculate_lifecycle_stage( $intelligence_data );
		$data['vip_status']      = YoOhw_COS_Intelligence::calculate_vip_status( $intelligence_data );
		$data['trust_score']     = YoOhw_COS_Intelligence::calculate_trust_score( $intelligence_data );
		$data['risk_score']      = YoOhw_COS_Intelligence::calculate_risk_score( $intelligence_data );

		self::update_customer( $customer_id, $data );

		self::maybe_link_order_to_customer( $order, $customer_id );
		self::invalidate_order_object_cache( $order_id );

		if ( $customer_id ) {
			YoOhw_COS_Events::record( array(
				'event_key'    => YoOhw_COS_Events::make_event_key( 'woocommerce', 'order_synced', 'order', $order_id, $customer_id ),
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

		if ( $customer_id > 0 ) {
			self::mark_customer_data_updated();
		}

		return $customer_id;
	}

	private static function get_identity_updates_for_sync( array $existing, array $identity, string $matched_by ): array {
		$updates = array();
		$current_user_id = absint( $existing['wp_user_id'] ?? 0 );
		$current_email   = YoOhw_COS_Customer_Identity::normalize_email( (string) ( $existing['email'] ?? '' ) );
		$current_phone   = YoOhw_COS_Customer_Identity::normalize_phone( (string) ( $existing['phone'] ?? '' ) );

		if ( absint( $identity['wp_user_id'] ?? 0 ) > 0 && ( 0 === $current_user_id || $current_user_id === absint( $identity['wp_user_id'] ) ) ) {
			$updates['wp_user_id'] = absint( $identity['wp_user_id'] );
		} elseif ( empty( $existing ) ) {
			$updates['wp_user_id'] = null;
		}

		if ( ! empty( $identity['email'] ) && ( '' === $current_email || in_array( $matched_by, array( 'wp_user_id', 'email' ), true ) ) ) {
			$updates['email'] = (string) $identity['email'];
		} elseif ( empty( $existing ) ) {
			$updates['email'] = null;
		}

		if ( ! empty( $identity['phone'] ) && ( '' === $current_phone || in_array( $matched_by, array( 'wp_user_id', 'email', 'phone' ), true ) ) ) {
			$updates['phone'] = (string) $identity['phone'];
		} elseif ( empty( $existing ) ) {
			$updates['phone'] = null;
		}

		return $updates;
	}

	private static function maybe_link_order_to_customer( WC_Order $order, int $customer_id ): void {
		$customer_id = absint( $customer_id );

		if ( $customer_id <= 0 ) {
			return;
		}

		$persisted_customer_ids = YoOhw_COS_Customer_Identity::get_persisted_order_customer_ids( $order );

		if ( 1 === count( $persisted_customer_ids ) && $customer_id === absint( $persisted_customer_ids[0] ) ) {
			return;
		}

		self::invalidate_order_object_cache( $order->get_id() );
		$canonical_order = wc_get_order( $order->get_id() );

		if ( ! $canonical_order instanceof WC_Order ) {
			$canonical_order = $order;
		}

		$canonical_order->delete_meta_data( self::ORDER_CUSTOMER_META_KEY );
		$canonical_order->add_meta_data( self::ORDER_CUSTOMER_META_KEY, $customer_id, true );
		$canonical_order->save();
	}

	private static function invalidate_order_object_cache( int $order_id ): void {
		$order_id = absint( $order_id );

		if ( $order_id <= 0 ) {
			return;
		}

		clean_post_cache( $order_id );

		if ( ! function_exists( 'wc_get_container' ) ) {
			return;
		}

		$cache_class = '\\Automattic\\WooCommerce\\Caches\\OrderCache';

		if ( ! class_exists( $cache_class ) ) {
			return;
		}

		try {
			$order_cache = wc_get_container()->get( $cache_class );

			if ( is_object( $order_cache ) && method_exists( $order_cache, 'remove' ) ) {
				$order_cache->remove( $order_id );
			}
		} catch ( Throwable $exception ) {
			do_action( 'yoohw_cos_order_cache_invalidation_error', $exception, $order_id );
		}
	}

	private static function get_last_activity_date_for_sync( int $customer_id, string $last_order_date ): string {
		$last_activity_date = $last_order_date;

		if ( $customer_id > 0 ) {
			$customer          = self::get_customer( $customer_id );
			$existing_activity = sanitize_text_field( (string) ( $customer['last_activity_date'] ?? '' ) );

			if (
				YoOhw_COS_DB::date_timestamp( $existing_activity )
				> YoOhw_COS_DB::date_timestamp( $last_activity_date )
			) {
				$last_activity_date = $existing_activity;
			}
		}

		return sanitize_text_field(
			(string) apply_filters(
				'yoohw_cos_customer_last_activity_date',
				$last_activity_date,
				$customer_id,
				$last_order_date
			)
		);
	}

	public static function find_customer_id_from_order( WC_Order $order, int $wp_user_id = 0, string $email = '', string $phone = '' ): int {
		$identity = YoOhw_COS_Customer_Identity::from_order( $order );

		if ( $wp_user_id > 0 ) {
			$identity['wp_user_id'] = $wp_user_id;
		}
		if ( '' !== $email ) {
			$identity['email'] = $email;
		}
		if ( '' !== $phone ) {
			$identity['phone'] = $phone;
		}

		$result = YoOhw_COS_Customer_Identity::resolve( $identity );

		return absint( $result['customer_id'] ?? 0 );
	}

	public static function find_customer_id( array $args ): int {
		$result = YoOhw_COS_Customer_Identity::resolve( $args );

		return absint( $result['customer_id'] ?? 0 );
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
		if ( array_key_exists( 'email', $data ) && null !== $data['email'] ) {
			$data['email'] = YoOhw_COS_Customer_Identity::normalize_email( (string) $data['email'] ) ?: null;
		}

		if ( array_key_exists( 'phone', $data ) && null !== $data['phone'] ) {
			$data['phone'] = YoOhw_COS_Customer_Identity::normalize_phone( (string) $data['phone'] ) ?: null;
		}

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
			'commerce_metrics_version' => '%d',
			'risk_score'          => '%f',
			'trust_score'         => '%f',
			'loyalty_score'       => '%f',
			'loyalty_level'       => '%s',
			'available_points'    => '%d',
			'earned_points'       => '%d',
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

	private static function normalize_score( $score ): float {
		return max( 0.0, min( 100.0, (float) $score ) );
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
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', YoOhw_COS_DB::order_facts_table() ) );
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', YoOhw_COS_DB::notification_log_table() ) );

		delete_option( 'yoohw_cos_last_sync_page' );
		delete_option( 'yoohw_cos_last_sync_at' );
		delete_option( 'yoohw_cos_sync_state' );
		delete_option( 'yoohw_cos_operation_sync_state_recalculate_intelligence' );
		delete_option( 'yoohw_cos_operation_sync_state_backfill_first_orders' );
		delete_option( 'yoohw_cos_operation_sync_state_blacklist_signals' );
		delete_option( 'yoohw_cos_activity_semantics_recalculation' );
		delete_option( 'yoohw_cos_customer_data_updated_at' );
		delete_option( 'yoohw_cos_loyalty_backfill_state' );
		delete_option( 'yoohw_cos_premium_reassociation_state' );
		delete_option( 'yoohw_cos_data_migrations' );
		delete_option( 'yoohw_cos_data_migration_lock' );
		wp_clear_scheduled_hook( YoOhw_COS_Migration_Runner::HOOK );
		wp_clear_scheduled_hook( self::ORDER_SYNC_RETRY_HOOK );
		wp_clear_scheduled_hook( 'yoohw_cos_recalculate_activity_semantics' );
		wp_clear_scheduled_hook( 'yoohw_cos_backfill_loyalty_history' );
		wp_clear_scheduled_hook( 'yoohw_cos_reassociate_premium_checkout_events' );
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
				'scanned'   => 0,
				'has_more'  => false,
				'next_page' => $page,
			);
		}

		$updated_count = 0;

		foreach ( $customers as $customer ) {
			$customer_id = absint( $customer['id'] );
			$previous_customer = $customer;
			$customer    = apply_filters( 'yoohw_cos_customer_recalculate_intelligence_data', $customer, $customer_id );
			$customer    = apply_filters( 'yoohw_cos_customer_intelligence_data', $customer, null, $customer_id, $customer );

			$new_status    = YoOhw_COS_Intelligence::calculate_customer_status( $customer );
			$new_lifecycle = YoOhw_COS_Intelligence::calculate_lifecycle_stage( $customer );
			$new_vip       = YoOhw_COS_Intelligence::calculate_vip_status( $customer );
			$new_trust     = YoOhw_COS_Intelligence::calculate_trust_score( $customer );
			$new_risk      = YoOhw_COS_Intelligence::calculate_risk_score( $customer );
			$new_loyalty   = self::normalize_score( $customer['loyalty_score'] ?? 0 );
			$loyalty_level = sanitize_key( (string) ( $customer['loyalty_level'] ?? '' ) );

			$recalculated_data = array(
				'customer_status' => $new_status,
				'lifecycle_stage' => $new_lifecycle,
				'vip_status'      => $new_vip,
				'trust_score'     => $new_trust,
				'risk_score'      => $new_risk,
				'loyalty_score'    => $new_loyalty,
				'loyalty_level'    => $loyalty_level,
				'available_points' => (int) ( $customer['available_points'] ?? 0 ),
				'earned_points'    => (int) ( $customer['earned_points'] ?? 0 ),
			);

			$updated = self::update_customer( $customer_id, $recalculated_data );

			if ( $updated ) {
				$updated_count++;
			}

			do_action(
				'yoohw_cos_customer_intelligence_recalculated',
				$customer_id,
				array_merge( $customer, $recalculated_data ),
				$previous_customer,
				(bool) $updated
			);
		}

		return array(
			'updated'   => $updated_count,
			'scanned'   => count( $customers ),
			'has_more'  => count( $customers ) >= $limit,
			'next_page' => $page + 1,
		);
	}

	public static function refresh_risk_score_cache_batch( int $after_customer_id = 0, int $limit = self::RISK_SCORE_REFRESH_BATCH_SIZE ): array {
		global $wpdb;

		$table = YoOhw_COS_DB::customers_table();
		$limit = min( 1000, max( 1, absint( $limit ) ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT *
				FROM %i
				WHERE id > %d
					AND archived_at IS NULL
				ORDER BY id ASC
				LIMIT %d",
				$table,
				absint( $after_customer_id ),
				$limit
			),
			ARRAY_A
		);

		$updated = 0;
		$last_id = absint( $after_customer_id );

		foreach ( is_array( $rows ) ? $rows : array() as $customer ) {
			$customer_id = absint( $customer['id'] ?? 0 );
			$last_id     = max( $last_id, $customer_id );
			$current     = YoOhw_COS_Intelligence::calculate_risk_score( $customer );
			$cached      = (float) ( $customer['risk_score'] ?? 0 );

			if ( abs( $current - $cached ) < 0.01 ) {
				continue;
			}

			if ( self::update_customer( $customer_id, array( 'risk_score' => $current ) ) ) {
				$updated++;
			}
		}

		return array(
			'scanned'          => count( $rows ),
			'updated'          => $updated,
			'last_customer_id' => $last_id,
			'has_more'         => count( $rows ) >= $limit,
		);
	}

	public static function process_risk_score_cache_refresh( int $after_customer_id = 0 ): void {
		$result = self::refresh_risk_score_cache_batch( $after_customer_id );

		if ( empty( $result['has_more'] ) ) {
			return;
		}

		$next_customer_id = absint( $result['last_customer_id'] ?? 0 );
		$args             = array( $next_customer_id );

		if ( $next_customer_id > 0 && ! wp_next_scheduled( self::RISK_SCORE_REFRESH_HOOK, $args ) ) {
			wp_schedule_single_event( time() + 5, self::RISK_SCORE_REFRESH_HOOK, $args );
		}
	}

	private static function maybe_schedule_risk_score_cache_refresh(): void {
		if ( ! wp_next_scheduled( self::RISK_SCORE_REFRESH_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::RISK_SCORE_REFRESH_HOOK );
		}
	}

	public static function process_activity_semantics_recalculation(): void {
		$state = get_option( 'yoohw_cos_activity_semantics_recalculation', array() );
		$state = is_array( $state ) ? $state : array();

		if ( empty( $state ) || 'completed' === (string) ( $state['status'] ?? '' ) ) {
			return;
		}

		$page   = max( 1, absint( $state['next_page'] ?? 1 ) );
		$result = self::recalculate_intelligence( 250, $page );

		$state['total_scanned'] = absint( $state['total_scanned'] ?? 0 ) + absint( $result['scanned'] ?? 0 );
		$state['total_updated'] = absint( $state['total_updated'] ?? 0 ) + absint( $result['updated'] ?? 0 );

		if ( ! empty( $result['has_more'] ) ) {
			$state['status']        = 'in_progress';
			$state['next_page']     = max( $page + 1, absint( $result['next_page'] ?? 0 ) );
			$state['last_batch_at'] = YoOhw_COS_DB::now();

			update_option( 'yoohw_cos_activity_semantics_recalculation', $state, false );

			if ( ! wp_next_scheduled( 'yoohw_cos_recalculate_activity_semantics' ) ) {
				wp_schedule_single_event( time() + 5, 'yoohw_cos_recalculate_activity_semantics' );
			}

			return;
		}

		$state['status']       = 'completed';
		$state['next_page']    = $page;
		$state['completed_at'] = YoOhw_COS_DB::now();

		update_option( 'yoohw_cos_activity_semantics_recalculation', $state, false );
		self::mark_customer_data_updated( true );
	}

	private static function maybe_schedule_activity_semantics_recalculation(): void {
		$state  = get_option( 'yoohw_cos_activity_semantics_recalculation', array() );
		$status = is_array( $state ) ? sanitize_key( (string) ( $state['status'] ?? '' ) ) : '';

		if (
			in_array( $status, array( 'pending', 'in_progress' ), true )
			&& ! wp_next_scheduled( 'yoohw_cos_recalculate_activity_semantics' )
		) {
			wp_schedule_single_event( time() + 5, 'yoohw_cos_recalculate_activity_semantics' );
		}
	}

	private static function mark_customer_data_updated( bool $force = false ): void {
		$last_updated = sanitize_text_field( (string) get_option( 'yoohw_cos_customer_data_updated_at', '' ) );
		$last_time    = YoOhw_COS_DB::date_timestamp( $last_updated );

		if ( ! $force && $last_time > 0 && abs( current_time( 'timestamp' ) - $last_time ) < MINUTE_IN_SECONDS ) {
			return;
		}

		update_option( 'yoohw_cos_customer_data_updated_at', YoOhw_COS_DB::now(), false );
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
		global $wpdb;

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$customer_id = absint( $customer['id'] ?? 0 );
		$limit       = min( 100, max( 1, absint( $limit ) ) );

		if ( $customer_id <= 0 ) {
			return array();
		}

		$order_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT order_id FROM %i WHERE customer_id = %d ORDER BY order_date DESC, order_id DESC LIMIT %d',
				YoOhw_COS_DB::order_facts_table(),
				$customer_id,
				$limit
			)
		);

		if ( empty( $order_ids ) ) {
			return array();
		}

		$orders = wc_get_orders(
			array(
				'type'    => 'shop_order',
				'limit'   => $limit,
				'include' => array_map( 'absint', $order_ids ),
				'return'  => 'objects',
				'status'  => array_keys( wc_get_order_statuses() ),
			)
		);

		$orders_by_id = array();

		foreach ( is_array( $orders ) ? $orders : array() as $order ) {
			if ( $order instanceof WC_Order ) {
				$orders_by_id[ $order->get_id() ] = $order;
			}
		}

		$ordered = array();

		foreach ( $order_ids as $order_id ) {
			$order_id = absint( $order_id );

			if ( isset( $orders_by_id[ $order_id ] ) ) {
				$ordered[] = $orders_by_id[ $order_id ];
			}
		}

		return $ordered;
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
				'scanned'   => 0,
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
			'scanned'   => count( $customers ),
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
