<?php
defined( 'ABSPATH' ) || exit;

/**
 * Bounded, resumable data migrations. Schema changes remain in Install.
 */
final class YoOhw_COS_Migration_Runner {

	public const HOOK = 'yoohw_cos_run_data_migrations';
	private const STATE_OPTION = 'yoohw_cos_data_migrations';
	private const LOCK_OPTION = 'yoohw_cos_data_migration_lock';
	private const BATCH_SIZE = 100;

	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'run_next_batch' ) );
		self::maybe_schedule();
	}

	public static function register_upgrade( string $from_version, string $to_version ): void {
		$state = self::get_state();

		if ( ! isset( $state['commerce_facts_v1'] ) ) {
			$state['commerce_facts_v1'] = self::new_migration_state(
				array(
					'phase'            => 'orders',
					'next_page'        => 1,
					'last_customer_id' => 0,
				)
			);
		}

		if ( ! isset( $state['identity_normalization_v1'] ) ) {
			$state['identity_normalization_v1'] = self::new_migration_state(
				array( 'last_customer_id' => 0 )
			);
		}

		if ( '' !== $from_version && version_compare( $from_version, '0.1.10', '<' ) && ! isset( $state['activity_semantics_v2'] ) ) {
			$state['activity_semantics_v2'] = self::new_migration_state(
				array( 'last_customer_id' => 0 )
			);
		}

		$state['_schema'] = array(
			'from'       => sanitize_text_field( $from_version ),
			'to'         => sanitize_text_field( $to_version ),
			'updated_at' => YoOhw_COS_DB::now(),
		);

		update_option( self::STATE_OPTION, $state, false );
		self::maybe_schedule();
	}

	public static function run_next_batch(): void {
		if ( ! self::acquire_lock() ) {
			self::schedule_next();
			return;
		}

		try {
			$state        = self::get_state();
			$migration_id = self::next_pending_migration( $state );

			if ( '' === $migration_id ) {
				return;
			}

			$migration = $state[ $migration_id ];
			$migration['status'] = 'in_progress';
			$migration['attempts'] = absint( $migration['attempts'] ?? 0 ) + 1;
			$migration['last_batch_at'] = YoOhw_COS_DB::now();

			if ( 'commerce_facts_v1' === $migration_id ) {
				$migration = self::run_commerce_facts_batch( $migration );
			} elseif ( 'identity_normalization_v1' === $migration_id ) {
				$migration = self::run_identity_normalization_batch( $migration );
			} else {
				$migration = self::run_activity_semantics_batch( $migration );
			}

			$state[ $migration_id ] = $migration;
			update_option( self::STATE_OPTION, $state, false );
		} catch ( Throwable $exception ) {
			$state = self::get_state();

			if ( isset( $migration_id, $state[ $migration_id ] ) ) {
				$state[ $migration_id ]['status']       = 'pending';
				$state[ $migration_id ]['last_error']   = sanitize_text_field( $exception->getMessage() );
				$state[ $migration_id ]['last_error_at'] = YoOhw_COS_DB::now();
				update_option( self::STATE_OPTION, $state, false );
			}

			do_action( 'yoohw_cos_data_migration_error', $exception, $migration_id ?? '' );
		} finally {
			self::release_lock();
		}

		self::maybe_schedule();
	}

	public static function get_state(): array {
		$state = get_option( self::STATE_OPTION, array() );

		return is_array( $state ) ? $state : array();
	}

	private static function run_commerce_facts_batch( array $migration ): array {
		$phase = sanitize_key( (string) ( $migration['phase'] ?? 'orders' ) );

		if ( 'orders' === $phase ) {
			$page   = max( 1, absint( $migration['next_page'] ?? 1 ) );
			$result = YoOhw_COS_Customers::sync_existing_orders( self::BATCH_SIZE, $page );
			$migration['processed'] = absint( $migration['processed'] ?? 0 ) + absint( $result['scanned'] ?? 0 );

			if ( ! empty( $result['has_more'] ) ) {
				$migration['next_page'] = max( $page + 1, absint( $result['next_page'] ?? 0 ) );
				return $migration;
			}

			$migration['phase'] = 'customers';
			$migration['last_customer_id'] = 0;
			return $migration;
		}

		global $wpdb;

		$last_id = absint( $migration['last_customer_id'] ?? 0 );
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d',
				YoOhw_COS_DB::customers_table(),
				$last_id,
				self::BATCH_SIZE
			)
		);

		foreach ( is_array( $ids ) ? $ids : array() as $customer_id ) {
			$customer_id = absint( $customer_id );
			YoOhw_COS_Commerce_Aggregates::rebuild_customer( $customer_id );
			$last_id = max( $last_id, $customer_id );
		}

		$migration['last_customer_id'] = $last_id;
		$migration['processed_customers'] = absint( $migration['processed_customers'] ?? 0 ) + count( $ids );

		if ( count( $ids ) < self::BATCH_SIZE ) {
			$migration = self::complete( $migration );
		}

		return $migration;
	}

	private static function run_identity_normalization_batch( array $migration ): array {
		global $wpdb;

		$last_id = absint( $migration['last_customer_id'] ?? 0 );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, email, phone FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d',
				YoOhw_COS_DB::customers_table(),
				$last_id,
				self::BATCH_SIZE
			),
			ARRAY_A
		);

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$customer_id = absint( $row['id'] ?? 0 );
			YoOhw_COS_Customers::update_customer(
				$customer_id,
				array(
					'email' => YoOhw_COS_Customer_Identity::normalize_email( (string) ( $row['email'] ?? '' ) ) ?: null,
					'phone' => YoOhw_COS_Customer_Identity::normalize_phone( (string) ( $row['phone'] ?? '' ) ) ?: null,
				)
			);
			$last_id = max( $last_id, $customer_id );
		}

		$migration['last_customer_id'] = $last_id;
		$migration['processed'] = absint( $migration['processed'] ?? 0 ) + count( $rows );

		if ( count( $rows ) < self::BATCH_SIZE ) {
			$migration = self::complete( $migration );
		}

		return $migration;
	}

	private static function run_activity_semantics_batch( array $migration ): array {
		global $wpdb;

		$last_id = absint( $migration['last_customer_id'] ?? 0 );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, last_order_date FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d',
				YoOhw_COS_DB::customers_table(),
				$last_id,
				self::BATCH_SIZE
			),
			ARRAY_A
		);

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$customer_id = absint( $row['id'] ?? 0 );
			$loyalty_date = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT MAX(created_at) FROM %i WHERE customer_id = %d AND event_source = %s',
					YoOhw_COS_DB::events_table(),
					$customer_id,
					'wc_loyalty'
				)
			);
			$last_order = sanitize_text_field( (string) ( $row['last_order_date'] ?? '' ) );
			$activity   = YoOhw_COS_DB::date_timestamp( (string) $loyalty_date ) > YoOhw_COS_DB::date_timestamp( $last_order )
				? (string) $loyalty_date
				: $last_order;

			YoOhw_COS_Customers::update_customer( $customer_id, array( 'last_activity_date' => $activity ?: null ) );
			$last_id = max( $last_id, $customer_id );
		}

		$migration['last_customer_id'] = $last_id;
		$migration['processed'] = absint( $migration['processed'] ?? 0 ) + count( $rows );

		if ( count( $rows ) < self::BATCH_SIZE ) {
			$migration = self::complete( $migration );
		}

		return $migration;
	}

	private static function new_migration_state( array $extra = array() ): array {
		return array_merge(
			array(
				'status'     => 'pending',
				'processed'  => 0,
				'attempts'   => 0,
				'started_at' => YoOhw_COS_DB::now(),
			),
			$extra
		);
	}

	private static function complete( array $migration ): array {
		$migration['status']       = 'completed';
		$migration['completed_at'] = YoOhw_COS_DB::now();

		return $migration;
	}

	private static function next_pending_migration( array $state ): string {
		foreach ( array( 'commerce_facts_v1', 'identity_normalization_v1', 'activity_semantics_v2' ) as $migration_id ) {
			$status = sanitize_key( (string) ( $state[ $migration_id ]['status'] ?? '' ) );

			if ( in_array( $status, array( 'pending', 'in_progress' ), true ) ) {
				return $migration_id;
			}
		}

		return '';
	}

	private static function maybe_schedule(): void {
		if ( '' !== self::next_pending_migration( self::get_state() ) && ! wp_next_scheduled( self::HOOK ) ) {
			self::schedule_next();
		}
	}

	private static function schedule_next(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::HOOK );
		}
	}

	private static function acquire_lock(): bool {
		$expires = absint( get_option( self::LOCK_OPTION, 0 ) );

		if ( $expires > 0 && $expires < time() ) {
			delete_option( self::LOCK_OPTION );
		}

		return add_option( self::LOCK_OPTION, time() + 5 * MINUTE_IN_SECONDS, '', false );
	}

	private static function release_lock(): void {
		delete_option( self::LOCK_OPTION );
	}
}
