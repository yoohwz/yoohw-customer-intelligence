<?php
defined( 'ABSPATH' ) || exit;

/**
 * Atomic, incremental customer commerce aggregates backed by per-order facts.
 */
final class YoOhw_COS_Commerce_Aggregates {

	public static function sync_order( WC_Order $order, int $customer_id ): array {
		if ( ! YoOhw_COS_Reset_Guard::enter() ) {
			return array();
		}
		try {
			return self::sync_order_guarded( $order, $customer_id );
		} finally {
			YoOhw_COS_Reset_Guard::leave();
		}
	}

	private static function sync_order_guarded( WC_Order $order, int $customer_id ): array {
		global $wpdb;

		$order_id   = absint( $order->get_id() );
		$customer_id = absint( $customer_id );

		if ( $order_id <= 0 || $customer_id <= 0 ) {
			return array();
		}

		$facts_table     = YoOhw_COS_DB::order_facts_table();
		$new_fact        = YoOhw_COS_Commerce_Metrics_Policy::get_contribution( $order, $customer_id );
		$affected_ids    = array( $customer_id );
		$transaction_ok  = false;

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.TransactionQuery

		try {
			$old_fact = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE order_id = %d FOR UPDATE',
					$facts_table,
					$order_id
				),
				ARRAY_A
			);

			$old_fact = is_array( $old_fact ) ? $old_fact : array();
			$old_customer_id = absint( $old_fact['customer_id'] ?? 0 );

			if ( $old_customer_id > 0 ) {
				$affected_ids[] = $old_customer_id;
			}

			$affected_ids = array_values( array_unique( array_map( 'absint', $affected_ids ) ) );
			sort( $affected_ids, SORT_NUMERIC );

			foreach ( $affected_ids as $affected_id ) {
				self::lock_and_initialize_customer( $affected_id );
			}

			if ( $old_customer_id > 0 && $old_customer_id !== $customer_id ) {
				self::apply_delta(
					$old_customer_id,
					-1 * absint( $old_fact['counts_as_order'] ?? 0 )
				);
				self::apply_delta(
					$customer_id,
					absint( $new_fact['counts_as_order'] )
				);
			} else {
				self::apply_delta(
					$customer_id,
					absint( $new_fact['counts_as_order'] ) - absint( $old_fact['counts_as_order'] ?? 0 )
				);
			}

			$sql = $wpdb->prepare(
				"INSERT INTO %i
					(order_id, customer_id, order_status, order_total, revenue_amount, currency, counts_as_order, counts_as_revenue, order_date, policy_version, updated_at)
				VALUES (%d, %d, %s, %f, %f, %s, %d, %d, %s, %d, %s)
				ON DUPLICATE KEY UPDATE
					customer_id = VALUES(customer_id),
					order_status = VALUES(order_status),
					order_total = VALUES(order_total),
					revenue_amount = VALUES(revenue_amount),
					currency = VALUES(currency),
					counts_as_order = VALUES(counts_as_order),
					counts_as_revenue = VALUES(counts_as_revenue),
					order_date = VALUES(order_date),
					policy_version = VALUES(policy_version),
					updated_at = VALUES(updated_at)",
				$facts_table,
				$new_fact['order_id'],
				$new_fact['customer_id'],
				$new_fact['order_status'],
				$new_fact['order_total'],
				$new_fact['revenue_amount'],
				$new_fact['currency'],
				$new_fact['counts_as_order'],
				$new_fact['counts_as_revenue'],
				$new_fact['order_date'],
				$new_fact['policy_version'],
				$new_fact['updated_at']
			);

			$written = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.

			if ( false === $written ) {
				throw new RuntimeException( 'Unable to persist customer order fact.' );
			}

			foreach ( $affected_ids as $affected_id ) {
				self::refresh_money( $affected_id );
				self::refresh_order_bounds( $affected_id );
			}

			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.TransactionQuery
			$transaction_ok = true;
		} catch ( Throwable $exception ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.TransactionQuery
			do_action( 'yoohw_cos_commerce_aggregate_error', $exception, $order_id, $customer_id );
		}

		if ( ! $transaction_ok ) {
			return array();
		}
		YoOhw_COS_Migration_Runner::note_currency_reconciled( 'order', $order_id );

		$metrics = self::get_customer_metrics( $customer_id );
		$metrics['_affected_customer_ids'] = $affected_ids;

		return $metrics;
	}

	/**
	 * Remove a deleted order's persisted contribution.
	 *
	 * @return int Affected customer ID, or 0 when no fact existed/the update failed.
	 */
	public static function remove_order( int $order_id ): int {
		if ( ! YoOhw_COS_Reset_Guard::enter() ) {
			return 0;
		}
		try {
			return self::remove_order_guarded( $order_id );
		} finally {
			YoOhw_COS_Reset_Guard::leave();
		}
	}

	private static function remove_order_guarded( int $order_id ): int {
		global $wpdb;

		$order_id = absint( $order_id );

		if ( $order_id <= 0 ) {
			return 0;
		}

		$facts_table = YoOhw_COS_DB::order_facts_table();
		$customer_id = 0;
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.TransactionQuery

		try {
			$fact = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT customer_id, counts_as_order FROM %i WHERE order_id = %d FOR UPDATE',
					$facts_table,
					$order_id
				),
				ARRAY_A
			);

			if ( ! is_array( $fact ) ) {
				$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.TransactionQuery
				return 0;
			}

			$customer_id = absint( $fact['customer_id'] ?? 0 );
			self::lock_and_initialize_customer( $customer_id );
			self::apply_delta(
					$customer_id,
					-1 * absint( $fact['counts_as_order'] ?? 0 )
			);

			$deleted = $wpdb->delete( $facts_table, array( 'order_id' => $order_id ), array( '%d' ) );

			if ( false === $deleted ) {
				throw new RuntimeException( 'Unable to remove customer order fact.' );
			}

			self::refresh_money( $customer_id );
			self::refresh_order_bounds( $customer_id );
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.TransactionQuery
		} catch ( Throwable $exception ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.TransactionQuery
			do_action( 'yoohw_cos_commerce_aggregate_delete_error', $exception, $order_id, $customer_id );

			return 0;
		}

		YoOhw_COS_Customers::refresh_derived_intelligence( $customer_id );

		return $customer_id;
	}

	public static function get_customer_metrics( int $customer_id ): array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT total_orders, total_spent, average_order_value, money_state, money_currency, commerce_metrics_version FROM %i WHERE id = %d',
				YoOhw_COS_DB::customers_table(),
				absint( $customer_id )
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : array();
	}

	public static function rebuild_customer( int $customer_id, bool $trusted_backfill = false ): bool {
		if ( ! YoOhw_COS_Reset_Guard::enter() ) {
			return false;
		}
		try {
			return self::rebuild_customer_guarded( $customer_id, $trusted_backfill );
		} finally {
			YoOhw_COS_Reset_Guard::leave();
		}
	}

	private static function rebuild_customer_guarded( int $customer_id, bool $trusted_backfill ): bool {
		global $wpdb;

		$customer_id = absint( $customer_id );

		if ( $customer_id <= 0 ) {
			return false;
		}

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.TransactionQuery

		try {
			$customer_exists = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM %i WHERE id = %d FOR UPDATE',
					YoOhw_COS_DB::customers_table(),
					$customer_id
				)
			);

			if ( ! $customer_exists ) {
				throw new RuntimeException( 'Customer aggregate rebuild target does not exist.' );
			}

			$aggregate = self::fact_summary( $customer_id );
			$total_orders = absint( $aggregate['total_orders'] ?? 0 );
			$previous_version = absint( $wpdb->get_var( $wpdb->prepare( 'SELECT commerce_metrics_version FROM %i WHERE id = %d', YoOhw_COS_DB::customers_table(), $customer_id ) ) );
			$trusted = $trusted_backfill || $previous_version >= YoOhw_COS_Commerce_Metrics_Policy::VERSION || 0 === $previous_version;
			$money = self::money_values( $aggregate, $trusted );
			$updated = $wpdb->update(
				YoOhw_COS_DB::customers_table(),
				array(
					'total_orders'             => $total_orders,
					'total_spent'              => $money['total_spent'],
					'average_order_value'      => $money['average_order_value'],
					'money_state'              => $money['money_state'],
					'money_currency'           => $money['money_currency'],
					'commerce_metrics_version' => $trusted ? YoOhw_COS_Commerce_Metrics_Policy::VERSION : $previous_version,
					'updated_at'               => YoOhw_COS_DB::now(),
				),
				array( 'id' => $customer_id ),
				array( '%d', '%f', '%f', '%s', '%s', '%d', '%s' ),
				array( '%d' )
			);

			if ( false === $updated ) {
				throw new RuntimeException( 'Unable to persist rebuilt customer aggregate.' );
			}

			self::refresh_order_bounds( $customer_id );
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.TransactionQuery
		} catch ( Throwable $exception ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.TransactionQuery
			do_action( 'yoohw_cos_commerce_rebuild_error', $exception, $customer_id );

			return false;
		}

		YoOhw_COS_Customers::refresh_derived_intelligence( $customer_id );
		YoOhw_COS_Migration_Runner::note_currency_reconciled( 'customer', $customer_id );

		return true;
	}

	private static function lock_and_initialize_customer( int $customer_id ): void {
		global $wpdb;

		$customer = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT commerce_metrics_version FROM %i WHERE id = %d FOR UPDATE',
				YoOhw_COS_DB::customers_table(),
				$customer_id
			),
			ARRAY_A
		);

		if ( ! is_array( $customer ) ) {
			throw new RuntimeException( 'Customer aggregate target does not exist.' );
		}

		if ( absint( $customer['commerce_metrics_version'] ?? 0 ) > 0 ) {
			return;
		}

		$initialized = $wpdb->update(
			YoOhw_COS_DB::customers_table(),
			array(
				'total_orders'             => 0,
				'total_spent'              => 0,
				'average_order_value'      => 0,
				'money_state'              => 'none',
				'money_currency'           => null,
				'commerce_metrics_version' => YoOhw_COS_Commerce_Metrics_Policy::VERSION,
			),
			array( 'id' => $customer_id ),
			array( '%d', '%f', '%f', '%s', '%s', '%d' ),
			array( '%d' )
		);

		if ( false === $initialized ) {
			throw new RuntimeException( 'Unable to initialize customer commerce metrics.' );
		}
	}

	private static function apply_delta( int $customer_id, int $order_delta ): void {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT total_orders FROM %i WHERE id = %d FOR UPDATE',
				YoOhw_COS_DB::customers_table(),
				$customer_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			throw new RuntimeException( 'Customer aggregate row disappeared.' );
		}

		$total_orders = max( 0, absint( $row['total_orders'] ?? 0 ) + $order_delta );
		$updated      = $wpdb->update(
			YoOhw_COS_DB::customers_table(),
			array(
				'total_orders'        => $total_orders,
				'updated_at'          => YoOhw_COS_DB::now(),
			),
			array( 'id' => $customer_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			throw new RuntimeException( 'Unable to update customer aggregate.' );
		}
	}

	private static function fact_summary( int $customer_id ): array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(counts_as_order), 0) AS total_orders,
					COALESCE(SUM(CASE WHEN counts_as_order = 1 THEN revenue_amount ELSE 0 END), 0) AS total_spent,
					SUM(CASE WHEN counts_as_order = 1 AND (currency IS NULL OR currency = '') THEN 1 ELSE 0 END) AS unknown_count,
					MIN(CASE WHEN counts_as_order = 1 THEN NULLIF(currency, '') END) AS min_currency,
					MAX(CASE WHEN counts_as_order = 1 THEN NULLIF(currency, '') END) AS max_currency
				FROM %i WHERE customer_id = %d",
				YoOhw_COS_DB::order_facts_table(),
				$customer_id
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			throw new RuntimeException( 'Unable to summarize customer currency facts.' );
		}
		return $row;
	}

	private static function money_values( array $summary, bool $trusted ): array {
		$orders = absint( $summary['total_orders'] ?? 0 );
		$min = (string) ( $summary['min_currency'] ?? '' );
		$max = (string) ( $summary['max_currency'] ?? '' );
		$state = ! $trusted || absint( $summary['unknown_count'] ?? 0 ) > 0 ? 'unknown'
			: ( 0 === $orders ? 'none' : ( $min === $max && '' !== $min ? 'comparable' : 'mixed' ) );
		$spent = 'comparable' === $state ? max( 0.0, (float) ( $summary['total_spent'] ?? 0 ) ) : 0.0;
		return array(
			'total_spent' => $spent,
			'average_order_value' => 'comparable' === $state && $orders > 0 ? $spent / $orders : 0.0,
			'money_state' => $state,
			'money_currency' => 'comparable' === $state ? $min : null,
		);
	}

	private static function refresh_money( int $customer_id ): void {
		global $wpdb;
		$version = absint( $wpdb->get_var( $wpdb->prepare( 'SELECT commerce_metrics_version FROM %i WHERE id = %d', YoOhw_COS_DB::customers_table(), $customer_id ) ) );
		$money = self::money_values( self::fact_summary( $customer_id ), $version >= YoOhw_COS_Commerce_Metrics_Policy::VERSION );
		$updated = $wpdb->update(
			YoOhw_COS_DB::customers_table(),
			$money,
			array( 'id' => $customer_id ),
			array( '%f', '%f', '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $updated ) {
			throw new RuntimeException( 'Unable to persist customer currency state.' );
		}
	}

	private static function refresh_order_bounds( int $customer_id ): void {
		global $wpdb;

		$facts_table = YoOhw_COS_DB::order_facts_table();
		$first = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT order_id, order_date FROM %i WHERE customer_id = %d AND counts_as_order = 1 ORDER BY order_date ASC, order_id ASC LIMIT 1',
				$facts_table,
				$customer_id
			),
			ARRAY_A
		);
		$last = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT order_id, order_date FROM %i WHERE customer_id = %d AND counts_as_order = 1 ORDER BY order_date DESC, order_id DESC LIMIT 1',
				$facts_table,
				$customer_id
			),
			ARRAY_A
		);

		$updated = $wpdb->update(
			YoOhw_COS_DB::customers_table(),
			array(
				'first_order_id'   => ! empty( $first ) ? absint( $first['order_id'] ) : null,
				'first_order_date' => ! empty( $first ) ? $first['order_date'] : null,
				'last_order_id'    => ! empty( $last ) ? absint( $last['order_id'] ) : null,
				'last_order_date'  => ! empty( $last ) ? $last['order_date'] : null,
			),
			array( 'id' => $customer_id ),
			array( '%d', '%s', '%d', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			throw new RuntimeException( 'Unable to update customer order bounds.' );
		}
	}
}
