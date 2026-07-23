<?php
defined( 'ABSPATH' ) || exit;

final class YoOhw_COS_Overview {

	private static $table_exists_cache = array();

	public static function get_summary(): array {
		global $wpdb;

		$table = YoOhw_COS_DB::customers_table();

		if ( ! self::table_exists( $table ) ) {
			return self::empty_summary();
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS total_customers,
					COALESCE(SUM(total_orders), 0) AS total_orders,
					COALESCE(SUM(total_spent), 0) AS total_spent,
					SUM(CASE WHEN total_orders > 0 THEN 1 ELSE 0 END) AS purchasing_customers,
					SUM(CASE WHEN total_orders >= 2 THEN 1 ELSE 0 END) AS repeat_customers,
					SUM(CASE WHEN vip_status <> %s THEN 1 ELSE 0 END) AS high_value_customers
				FROM %i
				WHERE archived_at IS NULL",
				'none',
				$table
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return self::empty_summary();
		}

		$total_customers      = absint( $row['total_customers'] ?? 0 );
		$purchasing_customers = absint( $row['purchasing_customers'] ?? 0 );
		$total_orders         = absint( $row['total_orders'] ?? 0 );
		$total_spent          = (float) ( $row['total_spent'] ?? 0 );

		return array(
			'total_customers'      => $total_customers,
			'total_orders'         => $total_orders,
			'total_spent'          => $total_spent,
			'average_order_value'  => $total_orders > 0 ? $total_spent / $total_orders : 0.0,
			'purchasing_customers' => $purchasing_customers,
			'repeat_customers'     => absint( $row['repeat_customers'] ?? 0 ),
			'repeat_rate'          => $purchasing_customers > 0
				? ( (float) absint( $row['repeat_customers'] ?? 0 ) / $purchasing_customers ) * 100
				: 0.0,
			'high_value_customers' => absint( $row['high_value_customers'] ?? 0 ),
		);
	}

	public static function get_attention_counts(): array {
		global $wpdb;

		$counts = array(
			'overdue_tasks'              => 0,
			'due_soon_tasks'             => 0,
			'high_value_retention_risk'  => 0,
			'high_risk_customers'        => 0,
			'missing_contact_customers'  => 0,
		);

		$customers_table = YoOhw_COS_DB::customers_table();
		$tasks_table     = YoOhw_COS_DB::tasks_table();

		if ( self::table_exists( $customers_table ) ) {
			$customer_row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT
						SUM(
							CASE
								WHEN vip_status <> %s AND customer_status IN (%s, %s)
								THEN 1 ELSE 0
							END
						) AS high_value_retention_risk,
						SUM(CASE WHEN risk_score >= 70 THEN 1 ELSE 0 END) AS high_risk_customers,
						SUM(
							CASE
								WHEN email IS NULL OR email = '' OR phone IS NULL OR phone = ''
								THEN 1 ELSE 0
							END
						) AS missing_contact_customers
					FROM %i
					WHERE archived_at IS NULL",
					'none',
					'at_risk',
					'inactive',
					$customers_table
				),
				ARRAY_A
			);

			if ( is_array( $customer_row ) ) {
				$counts['high_value_retention_risk'] = absint( $customer_row['high_value_retention_risk'] ?? 0 );
				$counts['high_risk_customers']       = absint( $customer_row['high_risk_customers'] ?? 0 );
				$counts['missing_contact_customers'] = absint( $customer_row['missing_contact_customers'] ?? 0 );
			}
		}

		if ( self::table_exists( $tasks_table ) ) {
			$now      = YoOhw_COS_DB::now();
			$due_soon = date_i18n( 'Y-m-d H:i:s', current_time( 'timestamp' ) + ( 7 * DAY_IN_SECONDS ) );
			$task_row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT
						SUM(
							CASE
								WHEN status <> %s AND due_date IS NOT NULL AND due_date < %s
								THEN 1 ELSE 0
							END
						) AS overdue_tasks,
						SUM(
							CASE
								WHEN status <> %s
									AND due_date IS NOT NULL
									AND due_date >= %s
									AND due_date <= %s
								THEN 1 ELSE 0
							END
						) AS due_soon_tasks
					FROM %i",
					YoOhw_COS_Tasks::STATUS_COMPLETED,
					$now,
					YoOhw_COS_Tasks::STATUS_COMPLETED,
					$now,
					$due_soon,
					$tasks_table
				),
				ARRAY_A
			);

			if ( is_array( $task_row ) ) {
				$counts['overdue_tasks']  = absint( $task_row['overdue_tasks'] ?? 0 );
				$counts['due_soon_tasks'] = absint( $task_row['due_soon_tasks'] ?? 0 );
			}
		}

		return $counts;
	}

	public static function get_priority_customers( int $limit = 5 ): array {
		global $wpdb;

		$table = YoOhw_COS_DB::customers_table();
		$limit = min( 10, max( 1, absint( $limit ) ) );

		if ( ! self::table_exists( $table ) ) {
			return array();
		}

		$customers = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					id,
					display_name,
					email,
					phone,
					total_orders,
					total_spent,
					customer_status,
					vip_status,
					risk_score,
					last_activity_date
				FROM %i
				WHERE archived_at IS NULL
					AND (
						( vip_status <> %s AND customer_status IN (%s, %s) )
						OR risk_score >= 70
						OR email IS NULL
						OR email = ''
						OR phone IS NULL
						OR phone = ''
					)
				ORDER BY
					CASE
						WHEN vip_status <> %s AND customer_status = %s THEN 0
						WHEN vip_status <> %s AND customer_status = %s THEN 1
						WHEN risk_score >= 70 THEN 2
						WHEN email IS NULL OR email = '' OR phone IS NULL OR phone = '' THEN 3
						ELSE 4
					END ASC,
					total_spent DESC,
					id DESC
				LIMIT %d",
				$table,
				'none',
				'at_risk',
				'inactive',
				'none',
				'inactive',
				'none',
				'at_risk',
				$limit
			),
			ARRAY_A
		);

		return is_array( $customers ) ? $customers : array();
	}

	public static function get_priority_tasks( int $limit = 5 ): array {
		global $wpdb;

		$tasks_table     = YoOhw_COS_DB::tasks_table();
		$customers_table = YoOhw_COS_DB::customers_table();
		$limit           = min( 10, max( 1, absint( $limit ) ) );

		if ( ! self::table_exists( $tasks_table ) || ! self::table_exists( $customers_table ) ) {
			return array();
		}

		$tasks = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					t.*,
					c.display_name AS customer_name,
					c.email AS customer_email,
					u.display_name AS assignee_name
				FROM %i t
				LEFT JOIN %i c ON c.id = t.customer_id
				LEFT JOIN %i u ON u.ID = t.assigned_user_id
				WHERE t.status <> %s
				ORDER BY
					CASE
						WHEN t.due_date IS NOT NULL AND t.due_date < %s THEN 0
						ELSE 1
					END ASC,
					CASE
						WHEN t.assigned_user_id = %d THEN 0
						WHEN t.assigned_user_id IS NULL THEN 1
						ELSE 2
					END ASC,
					CASE t.priority
						WHEN 'urgent' THEN 0
						WHEN 'high' THEN 1
						WHEN 'normal' THEN 2
						ELSE 3
					END ASC,
					CASE WHEN t.due_date IS NULL THEN 1 ELSE 0 END ASC,
					t.due_date ASC,
					t.updated_at DESC,
					t.id DESC
				LIMIT %d",
				$tasks_table,
				$customers_table,
				$wpdb->users,
				YoOhw_COS_Tasks::STATUS_COMPLETED,
				YoOhw_COS_DB::now(),
				get_current_user_id(),
				$limit
			),
			ARRAY_A
		);

		return is_array( $tasks ) ? $tasks : array();
	}

	private static function empty_summary(): array {
		return array(
			'total_customers'      => 0,
			'total_orders'         => 0,
			'total_spent'          => 0.0,
			'average_order_value'  => 0.0,
			'purchasing_customers' => 0,
			'repeat_customers'     => 0,
			'repeat_rate'          => 0.0,
			'high_value_customers' => 0,
		);
	}

	private static function table_exists( string $table ): bool {
		global $wpdb;

		if ( isset( self::$table_exists_cache[ $table ] ) ) {
			return self::$table_exists_cache[ $table ];
		}

		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$table
			)
		);

		self::$table_exists_cache[ $table ] = $exists === $table;

		return self::$table_exists_cache[ $table ];
	}
}
