<?php
defined( 'ABSPATH' ) || exit;

/**
 * Atomic, incremental customer commerce aggregates backed by per-order facts.
 */
final class YoOhw_COS_Commerce_Aggregates {

	public static function sync_order( WC_Order $order, int $customer_id ): array {
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
					-1 * absint( $old_fact['counts_as_order'] ?? 0 ),
					-1 * (float) ( $old_fact['revenue_amount'] ?? 0 )
				);
				self::apply_delta(
					$customer_id,
					absint( $new_fact['counts_as_order'] ),
					(float) $new_fact['revenue_amount']
				);
			} else {
				self::apply_delta(
					$customer_id,
					absint( $new_fact['counts_as_order'] ) - absint( $old_fact['counts_as_order'] ?? 0 ),
					(float) $new_fact['revenue_amount'] - (float) ( $old_fact['revenue_amount'] ?? 0 )
				);
			}

			$sql = $wpdb->prepare(
				"INSERT INTO %i
					(order_id, customer_id, order_status, order_total, revenue_amount, counts_as_order, counts_as_revenue, order_date, policy_version, updated_at)
				VALUES (%d, %d, %s, %f, %f, %d, %d, %s, %d, %s)
				ON DUPLICATE KEY UPDATE
					customer_id = VALUES(customer_id),
					order_status = VALUES(order_status),
					order_total = VALUES(order_total),
					revenue_amount = VALUES(revenue_amount),
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
					'SELECT customer_id, counts_as_order, revenue_amount FROM %i WHERE order_id = %d FOR UPDATE',
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
				-1 * absint( $fact['counts_as_order'] ?? 0 ),
				-1 * (float) ( $fact['revenue_amount'] ?? 0 )
			);

			$deleted = $wpdb->delete( $facts_table, array( 'order_id' => $order_id ), array( '%d' ) );

			if ( false === $deleted ) {
				throw new RuntimeException( 'Unable to remove customer order fact.' );
			}

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
				'SELECT total_orders, total_spent, average_order_value FROM %i WHERE id = %d',
				YoOhw_COS_DB::customers_table(),
				absint( $customer_id )
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : array();
	}

	public static function rebuild_customer( int $customer_id ): bool {
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

			$aggregate = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT
						COALESCE(SUM(counts_as_order), 0) AS total_orders,
						COALESCE(SUM(revenue_amount), 0) AS total_spent
					FROM %i
					WHERE customer_id = %d",
					YoOhw_COS_DB::order_facts_table(),
					$customer_id
				),
				ARRAY_A
			);

			$total_orders = absint( $aggregate['total_orders'] ?? 0 );
			$total_spent  = max( 0.0, (float) ( $aggregate['total_spent'] ?? 0 ) );
			$updated = $wpdb->update(
				YoOhw_COS_DB::customers_table(),
				array(
					'total_orders'             => $total_orders,
					'total_spent'              => $total_spent,
					'average_order_value'      => $total_orders > 0 ? $total_spent / $total_orders : 0.0,
					'commerce_metrics_version' => YoOhw_COS_Commerce_Metrics_Policy::VERSION,
					'updated_at'               => YoOhw_COS_DB::now(),
				),
				array( 'id' => $customer_id ),
				array( '%d', '%f', '%f', '%d', '%s' ),
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

		if ( absint( $customer['commerce_metrics_version'] ?? 0 ) >= YoOhw_COS_Commerce_Metrics_Policy::VERSION ) {
			return;
		}

		$initialized = $wpdb->update(
			YoOhw_COS_DB::customers_table(),
			array(
				'total_orders'             => 0,
				'total_spent'              => 0,
				'average_order_value'      => 0,
				'commerce_metrics_version' => YoOhw_COS_Commerce_Metrics_Policy::VERSION,
			),
			array( 'id' => $customer_id ),
			array( '%d', '%f', '%f', '%d' ),
			array( '%d' )
		);

		if ( false === $initialized ) {
			throw new RuntimeException( 'Unable to initialize customer commerce metrics.' );
		}
	}

	private static function apply_delta( int $customer_id, int $order_delta, float $revenue_delta ): void {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT total_orders, total_spent FROM %i WHERE id = %d FOR UPDATE',
				YoOhw_COS_DB::customers_table(),
				$customer_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			throw new RuntimeException( 'Customer aggregate row disappeared.' );
		}

		$total_orders = max( 0, absint( $row['total_orders'] ?? 0 ) + $order_delta );
		$total_spent  = max( 0.0, (float) ( $row['total_spent'] ?? 0 ) + $revenue_delta );
		$updated      = $wpdb->update(
			YoOhw_COS_DB::customers_table(),
			array(
				'total_orders'        => $total_orders,
				'total_spent'         => $total_spent,
				'average_order_value' => $total_orders > 0 ? $total_spent / $total_orders : 0.0,
				'updated_at'          => YoOhw_COS_DB::now(),
			),
			array( 'id' => $customer_id ),
			array( '%d', '%f', '%f', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			throw new RuntimeException( 'Unable to update customer aggregate.' );
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
