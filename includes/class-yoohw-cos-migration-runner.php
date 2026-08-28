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
	private const MAX_ITEM_ATTEMPTS = 3;

	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'run_next_batch' ) );
		self::maybe_schedule();
	}

	public static function register_upgrade( string $from_version, string $to_version ): void {
		$state = self::get_state();

		foreach ( array( 'commerce_facts_v1', 'identity_normalization_v1' ) as $superseded_id ) {
			if ( isset( $state[ $superseded_id ] ) && in_array( (string) ( $state[ $superseded_id ]['status'] ?? '' ), array( 'pending', 'in_progress' ), true ) ) {
				$state[ $superseded_id ]['status']        = 'superseded';
				$state[ $superseded_id ]['superseded_by'] = '0.2.1';
			}
		}

		if ( ! isset( $state['identity_normalization_v2'] ) ) {
			$state['identity_normalization_v2'] = self::new_migration_state(
				array(
					'phase'            => 'scan',
					'last_customer_id' => 0,
				)
			);
		}

		if ( ! isset( $state['commerce_facts_v2'] ) ) {
			$state['commerce_facts_v2'] = self::new_migration_state(
				array(
					'phase'            => 'orders',
					'next_page'        => 1,
					'last_customer_id' => 0,
				)
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

			if ( 'identity_normalization_v2' === $migration_id ) {
				$migration = self::run_identity_normalization_batch( $migration );
			} elseif ( 'commerce_facts_v2' === $migration_id ) {
				$migration = self::run_commerce_facts_batch( $migration );
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
			$result = YoOhw_COS_Customers::sync_existing_orders_with_outcomes( self::BATCH_SIZE, $page );
			$migration['scanned']   = absint( $migration['scanned'] ?? 0 ) + absint( $result['scanned'] ?? 0 );
			$migration['processed'] = absint( $migration['processed'] ?? 0 ) + absint( $result['processed'] ?? 0 );

			foreach ( (array) ( $result['outcomes'] ?? array() ) as $order_id => $outcome ) {
				$status = sanitize_key( (string) ( $outcome['status'] ?? 'retry' ) );
				$code   = sanitize_key( (string) ( $outcome['code'] ?? 'sync_failed' ) );

				if ( 'success' === $status ) {
					self::resolve_issue( 'commerce_facts_v2', 'order', absint( $order_id ) );
					$migration['successful'] = absint( $migration['successful'] ?? 0 ) + 1;
				} else {
					self::record_issue(
						'commerce_facts_v2',
						'order',
						absint( $order_id ),
						$code ?: 'sync_failed',
						(string) ( $outcome['message'] ?? $code ),
						'unresolved' === $status ? 'unresolved' : 'pending'
					);
					$migration['failed'] = absint( $migration['failed'] ?? 0 ) + 1;
				}
			}

			if ( ! empty( $result['has_more'] ) ) {
				$migration['next_page'] = max( $page + 1, absint( $result['next_page'] ?? 0 ) );
				return $migration;
			}

			$migration['phase'] = 'order_retries';
			return $migration;
		}

		if ( 'order_retries' === $phase ) {
			$pending = self::retry_order_issues();

			if ( $pending > 0 ) {
				$migration['pending_issues'] = $pending;
				return $migration;
			}

			$migration['phase'] = 'customers';
			$migration['last_customer_id'] = 0;
			return $migration;
		}

		if ( 'rebuild_retries' === $phase ) {
			$pending = self::retry_customer_rebuild_issues();

			if ( $pending > 0 ) {
				$migration['pending_issues'] = $pending;
				return $migration;
			}

			return self::complete_with_issue_accounting( $migration, 'commerce_facts_v2' );
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

			if ( YoOhw_COS_Commerce_Aggregates::rebuild_customer( $customer_id ) ) {
				self::resolve_issue( 'commerce_facts_v2', 'customer', $customer_id );
			} else {
				self::record_issue( 'commerce_facts_v2', 'customer', $customer_id, 'rebuild_failed', 'Customer aggregate rebuild failed.', 'pending' );
			}

			$last_id = max( $last_id, $customer_id );
		}

		$migration['last_customer_id'] = $last_id;
		$migration['processed_customers'] = absint( $migration['processed_customers'] ?? 0 ) + count( $ids );

		if ( count( $ids ) < self::BATCH_SIZE ) {
			$migration['phase'] = 'rebuild_retries';
		}

		return $migration;
	}

	private static function run_identity_normalization_batch( array $migration ): array {
		global $wpdb;
		$phase = sanitize_key( (string) ( $migration['phase'] ?? 'scan' ) );

		if ( 'retries' === $phase ) {
			$pending = self::retry_identity_issues();

			if ( $pending > 0 ) {
				$migration['pending_issues'] = $pending;
				return $migration;
			}

			return self::complete_with_issue_accounting( $migration, 'identity_normalization_v2' );
		}

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
			$updated = YoOhw_COS_Customers::update_customer(
				$customer_id,
				array(
					'email' => YoOhw_COS_Customer_Identity::normalize_email( (string) ( $row['email'] ?? '' ) ) ?: null,
					'phone' => YoOhw_COS_Customer_Identity::normalize_phone( (string) ( $row['phone'] ?? '' ) ) ?: null,
				)
			);

			if ( $updated ) {
				self::resolve_issue( 'identity_normalization_v2', 'customer', $customer_id );
			} else {
				self::record_issue( 'identity_normalization_v2', 'customer', $customer_id, 'normalization_failed', 'Canonical identity update failed.', 'pending' );
			}

			$last_id = max( $last_id, $customer_id );
		}

		$migration['last_customer_id'] = $last_id;
		$migration['processed'] = absint( $migration['processed'] ?? 0 ) + count( $rows );

		if ( count( $rows ) < self::BATCH_SIZE ) {
			$migration['phase'] = 'retries';
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

	private static function retry_order_issues(): int {
		return self::retry_issues(
			'commerce_facts_v2',
			'order',
			static function( int $order_id ): array {
				return YoOhw_COS_Customers::sync_order_for_migration( $order_id );
			}
		);
	}

	private static function retry_customer_rebuild_issues(): int {
		return self::retry_issues(
			'commerce_facts_v2',
			'customer',
			static function( int $customer_id ): array {
				return YoOhw_COS_Commerce_Aggregates::rebuild_customer( $customer_id )
					? array( 'status' => 'success', 'code' => '' )
					: array( 'status' => 'retry', 'code' => 'rebuild_failed' );
			}
		);
	}

	private static function retry_identity_issues(): int {
		return self::retry_issues(
			'identity_normalization_v2',
			'customer',
			static function( int $customer_id ): array {
				$customer = YoOhw_COS_Customers::get_customer( $customer_id );

				if ( empty( $customer ) ) {
					return array( 'status' => 'unresolved', 'code' => 'customer_missing' );
				}

				$updated = YoOhw_COS_Customers::update_customer(
					$customer_id,
					array(
						'email' => YoOhw_COS_Customer_Identity::normalize_email( (string) ( $customer['email'] ?? '' ) ) ?: null,
						'phone' => YoOhw_COS_Customer_Identity::normalize_phone( (string) ( $customer['phone'] ?? '' ) ) ?: null,
					)
				);

				return $updated
					? array( 'status' => 'success', 'code' => '' )
					: array( 'status' => 'retry', 'code' => 'normalization_failed' );
			}
		);
	}

	private static function retry_issues( string $migration_id, string $object_type, callable $callback ): int {
		global $wpdb;

		$issues = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, object_id, attempts FROM %i
				WHERE migration_id = %s AND object_type = %s AND status = 'pending'
				ORDER BY id ASC LIMIT %d",
				YoOhw_COS_DB::migration_issues_table(),
				$migration_id,
				$object_type,
				self::BATCH_SIZE
			),
			ARRAY_A
		);

		foreach ( is_array( $issues ) ? $issues : array() as $issue ) {
			$object_id = absint( $issue['object_id'] ?? 0 );
			$outcome   = $callback( $object_id );
			$status    = sanitize_key( (string) ( $outcome['status'] ?? 'retry' ) );
			$code      = sanitize_key( (string) ( $outcome['code'] ?? 'retry_failed' ) );

			if ( 'success' === $status ) {
				self::resolve_issue( $migration_id, $object_type, $object_id );
				continue;
			}

			if ( 'unresolved' === $status ) {
				self::record_issue( $migration_id, $object_type, $object_id, $code, $code, 'unresolved' );
				continue;
			}

			$attempts = self::record_issue( $migration_id, $object_type, $object_id, $code, $code, 'pending' );

			if ( $attempts >= self::MAX_ITEM_ATTEMPTS ) {
				self::mark_issue_unresolved( $migration_id, $object_type, $object_id, 'retry_exhausted' );
			}
		}

		return self::count_issues( $migration_id, 'pending' );
	}

	private static function record_issue(
		string $migration_id,
		string $object_type,
		int $object_id,
		string $error_code,
		string $message,
		string $status
	): int {
		global $wpdb;

		$status = in_array( $status, array( 'pending', 'unresolved' ), true ) ? $status : 'pending';
		$sql = $wpdb->prepare(
			"INSERT INTO %i
				(migration_id, object_type, object_id, error_code, last_error, status, attempts, created_at, updated_at)
			VALUES (%s, %s, %d, %s, %s, %s, 1, %s, %s)
			ON DUPLICATE KEY UPDATE
				attempts = IF(status = 'resolved', 1, attempts + 1),
				error_code = VALUES(error_code),
				last_error = VALUES(last_error),
				status = VALUES(status),
				updated_at = VALUES(updated_at),
				resolved_at = NULL",
			YoOhw_COS_DB::migration_issues_table(),
			sanitize_key( $migration_id ),
			sanitize_key( $object_type ),
			absint( $object_id ),
			sanitize_key( $error_code ),
			sanitize_text_field( $message ),
			$status,
			YoOhw_COS_DB::now(),
			YoOhw_COS_DB::now()
		);
		$written = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.

		if ( false === $written ) {
			throw new RuntimeException( 'Unable to persist migration issue.' );
		}

		$attempts = absint(
			$wpdb->get_var(
				$wpdb->prepare(
					'SELECT attempts FROM %i WHERE migration_id = %s AND object_type = %s AND object_id = %d',
					YoOhw_COS_DB::migration_issues_table(),
					sanitize_key( $migration_id ),
					sanitize_key( $object_type ),
					absint( $object_id )
				)
			)
		);

		if ( $attempts <= 0 ) {
			throw new RuntimeException( 'Unable to read persisted migration issue.' );
		}

		return $attempts;
	}

	private static function resolve_issue( string $migration_id, string $object_type, int $object_id ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'resolved', resolved_at = %s, updated_at = %s
				WHERE migration_id = %s AND object_type = %s AND object_id = %d AND status <> 'resolved'",
				YoOhw_COS_DB::migration_issues_table(),
				YoOhw_COS_DB::now(),
				YoOhw_COS_DB::now(),
				sanitize_key( $migration_id ),
				sanitize_key( $object_type ),
				absint( $object_id )
			)
		);
	}

	private static function mark_issue_unresolved( string $migration_id, string $object_type, int $object_id, string $error_code ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'unresolved', error_code = %s, updated_at = %s
				WHERE migration_id = %s AND object_type = %s AND object_id = %d",
				YoOhw_COS_DB::migration_issues_table(),
				sanitize_key( $error_code ),
				YoOhw_COS_DB::now(),
				sanitize_key( $migration_id ),
				sanitize_key( $object_type ),
				absint( $object_id )
			)
		);
	}

	private static function count_issues( string $migration_id, string $status ): int {
		global $wpdb;

		return absint(
			$wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE migration_id = %s AND status = %s',
					YoOhw_COS_DB::migration_issues_table(),
					sanitize_key( $migration_id ),
					sanitize_key( $status )
				)
			)
		);
	}

	private static function complete_with_issue_accounting( array $migration, string $migration_id ): array {
		$pending    = self::count_issues( $migration_id, 'pending' );
		$unresolved = self::count_issues( $migration_id, 'unresolved' );

		$migration['pending_issues']    = $pending;
		$migration['unresolved_issues'] = $unresolved;

		if ( $pending > 0 ) {
			return $migration;
		}

		$migration['status']       = $unresolved > 0 ? 'completed_with_issues' : 'completed';
		$migration['completed_at'] = YoOhw_COS_DB::now();

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
		foreach ( array( 'identity_normalization_v2', 'commerce_facts_v2', 'activity_semantics_v2' ) as $migration_id ) {
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
