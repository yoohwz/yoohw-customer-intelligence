<?php
defined( 'ABSPATH' ) || exit;

final class YoOhw_COS_Tasks {

	public const STATUS_OPEN      = 'open';
	public const STATUS_COMPLETED = 'completed';

	public static function init(): void {
		// Reserved for future hooks.
	}

	public static function get_statuses(): array {
		return array(
			self::STATUS_OPEN      => __( 'Open', 'yoohw-customer-intelligence' ),
			self::STATUS_COMPLETED => __( 'Completed', 'yoohw-customer-intelligence' ),
		);
	}

	public static function get_priorities(): array {
		return array(
			'low'    => __( 'Low', 'yoohw-customer-intelligence' ),
			'normal' => __( 'Normal', 'yoohw-customer-intelligence' ),
			'high'   => __( 'High', 'yoohw-customer-intelligence' ),
			'urgent' => __( 'Urgent', 'yoohw-customer-intelligence' ),
		);
	}

	public static function get_assignable_roles(): array {
		return array( 'administrator', 'shop_manager' );
	}

	public static function get_assignable_users(): array {
		$users = get_users(
			array(
				'fields'   => array( 'ID', 'display_name' ),
				'orderby'  => 'display_name',
				'order'    => 'ASC',
				'number'   => 100,
				'role__in' => self::get_assignable_roles(),
			)
		);

		return is_array( $users ) ? $users : array();
	}

	public static function is_assignable_user( int $user_id ): bool {
		$user_id = absint( $user_id );

		if ( $user_id <= 0 ) {
			return false;
		}

		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User ) {
			return false;
		}

		return ! empty( array_intersect( self::get_assignable_roles(), (array) $user->roles ) );
	}

	public static function normalize_status( string $status ): string {
		$status = sanitize_key( $status );

		return isset( self::get_statuses()[ $status ] ) ? $status : self::STATUS_OPEN;
	}

	public static function normalize_priority( string $priority ): string {
		$priority = sanitize_key( $priority );

		return isset( self::get_priorities()[ $priority ] ) ? $priority : 'normal';
	}

	public static function create_task( array $data ): int {
		global $wpdb;

		$customer_id = absint( $data['customer_id'] ?? 0 );
		$title       = sanitize_text_field( (string) ( $data['title'] ?? '' ) );

		if ( $customer_id <= 0 || '' === $title || ! YoOhw_COS_Customers::customer_exists( $customer_id ) ) {
			return 0;
		}

		$created_by = absint( $data['created_by'] ?? get_current_user_id() );
		$assignee   = self::normalize_assignee_id( $data['assigned_user_id'] ?? 0 );
		$status     = self::normalize_status( (string) ( $data['status'] ?? self::STATUS_OPEN ) );
		$now        = YoOhw_COS_DB::now();

		$inserted = $wpdb->insert(
			YoOhw_COS_DB::tasks_table(),
			array(
				'customer_id'       => $customer_id,
				'order_id'          => self::normalize_optional_id( $data['order_id'] ?? 0 ),
				'assigned_user_id'  => $assignee,
				'created_by'        => $created_by > 0 ? $created_by : null,
				'title'             => $title,
				'description'       => sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ),
				'status'            => $status,
				'priority'          => self::normalize_priority( (string) ( $data['priority'] ?? 'normal' ) ),
				'due_date'          => self::normalize_due_date( (string) ( $data['due_date'] ?? '' ) ),
				'completed_at'      => self::STATUS_COMPLETED === $status ? $now : null,
				'completed_by'      => self::STATUS_COMPLETED === $status && $created_by > 0 ? $created_by : null,
				'created_at'        => $now,
				'updated_at'        => $now,
			),
			array( '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return 0;
		}

		$task_id = absint( $wpdb->insert_id );

		self::record_task_event( $task_id, 'task_created' );

		if ( self::STATUS_COMPLETED === $status ) {
			self::record_task_event( $task_id, 'task_completed' );
		}

		return $task_id;
	}

	public static function update_task( int $task_id, array $data ): bool {
		global $wpdb;

		$task = self::get_task( $task_id );

		if ( empty( $task ) ) {
			return false;
		}

		$customer_id = absint( $data['customer_id'] ?? $task['customer_id'] );
		$title       = sanitize_text_field( (string) ( $data['title'] ?? $task['title'] ) );
		$status      = self::normalize_status( (string) ( $data['status'] ?? $task['status'] ) );
		$was_open    = self::STATUS_COMPLETED !== (string) ( $task['status'] ?? '' );
		$will_close  = self::STATUS_COMPLETED === $status && $was_open;
		$now         = YoOhw_COS_DB::now();

		if ( $customer_id <= 0 || '' === $title || ! YoOhw_COS_Customers::customer_exists( $customer_id ) ) {
			return false;
		}

		$completed_at = $task['completed_at'] ?? null;
		$completed_by = $task['completed_by'] ?? null;

		if ( $will_close ) {
			$completed_at = $now;
			$completed_by = get_current_user_id() ?: null;
		} elseif ( self::STATUS_COMPLETED !== $status ) {
			$completed_at = null;
			$completed_by = null;
		}

		$assigned_user_id = array_key_exists( 'assigned_user_id', $data )
			? self::normalize_assignee_id( $data['assigned_user_id'] )
			: self::normalize_optional_id( $task['assigned_user_id'] ?? 0 );

		$updated = $wpdb->update(
			YoOhw_COS_DB::tasks_table(),
			array(
				'customer_id'       => $customer_id,
				'order_id'          => self::normalize_optional_id( $data['order_id'] ?? $task['order_id'] ?? 0 ),
				'assigned_user_id'  => $assigned_user_id,
				'title'             => $title,
				'description'       => sanitize_textarea_field( (string) ( $data['description'] ?? $task['description'] ?? '' ) ),
				'status'            => $status,
				'priority'          => self::normalize_priority( (string) ( $data['priority'] ?? $task['priority'] ?? 'normal' ) ),
				'due_date'          => self::normalize_due_date( (string) ( $data['due_date'] ?? $task['due_date'] ?? '' ) ),
				'completed_at'      => $completed_at,
				'completed_by'      => $completed_by ? absint( $completed_by ) : null,
				'updated_at'        => $now,
			),
			array( 'id' => absint( $task_id ) ),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return false;
		}

		if ( $will_close ) {
			self::record_task_event( $task_id, 'task_completed' );
		}

		return true;
	}

	public static function complete_task( int $task_id, int $completed_by = 0 ): bool {
		return self::set_task_status( $task_id, self::STATUS_COMPLETED, $completed_by );
	}

	public static function reopen_task( int $task_id ): bool {
		return self::set_task_status( $task_id, self::STATUS_OPEN );
	}

	public static function set_task_status( int $task_id, string $status, int $user_id = 0 ): bool {
		global $wpdb;

		$task = self::get_task( $task_id );

		if ( empty( $task ) ) {
			return false;
		}

		$status     = self::normalize_status( $status );
		$was_open   = self::STATUS_COMPLETED !== (string) ( $task['status'] ?? '' );
		$will_close = self::STATUS_COMPLETED === $status && $was_open;
		$now        = YoOhw_COS_DB::now();

		$data = array(
			'status'       => $status,
			'completed_at' => self::STATUS_COMPLETED === $status ? ( $task['completed_at'] ?: $now ) : null,
			'completed_by' => self::STATUS_COMPLETED === $status ? absint( $user_id ?: get_current_user_id() ) : null,
			'updated_at'   => $now,
		);

		$updated = $wpdb->update(
			YoOhw_COS_DB::tasks_table(),
			$data,
			array( 'id' => absint( $task_id ) ),
			array( '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return false;
		}

		if ( $will_close ) {
			self::record_task_event( $task_id, 'task_completed' );
		}

		return true;
	}

	public static function delete_task( int $task_id ): bool {
		global $wpdb;

		if ( $task_id <= 0 ) {
			return false;
		}

		$deleted = $wpdb->delete(
			YoOhw_COS_DB::tasks_table(),
			array( 'id' => absint( $task_id ) ),
			array( '%d' )
		);

		return (bool) $deleted;
	}

	public static function get_task( int $task_id ): array {
		global $wpdb;

		$task = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d LIMIT 1',
				YoOhw_COS_DB::tasks_table(),
				absint( $task_id )
			),
			ARRAY_A
		);

		return is_array( $task ) ? $task : array();
	}

	public static function get_customer_tasks( int $customer_id, array $args = array() ): array {
		global $wpdb;

		$defaults = array(
			'limit'  => 10,
			'status' => 'open',
		);

		$args = wp_parse_args( $args, $defaults );

		$customer_id = absint( $customer_id );
		$limit       = max( 1, absint( $args['limit'] ) );
		$status      = sanitize_key( (string) $args['status'] );

		if ( $customer_id <= 0 ) {
			return array();
		}

		if ( self::STATUS_COMPLETED === $status ) {
			$prepared_sql = $wpdb->prepare(
				"SELECT t.*, u.display_name AS assignee_name
				FROM %i t
				LEFT JOIN %i u ON u.ID = t.assigned_user_id
				WHERE t.customer_id = %d
					AND t.status = %s
				ORDER BY
					CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END ASC,
					CASE WHEN t.due_date IS NULL THEN 1 ELSE 0 END ASC,
					t.due_date ASC,
					t.updated_at DESC,
					t.id DESC
				LIMIT %d",
				YoOhw_COS_DB::tasks_table(),
				$wpdb->users,
				$customer_id,
				self::STATUS_COMPLETED,
				$limit
			);
		} elseif ( 'all' === $status ) {
			$prepared_sql = $wpdb->prepare(
				"SELECT t.*, u.display_name AS assignee_name
				FROM %i t
				LEFT JOIN %i u ON u.ID = t.assigned_user_id
				WHERE t.customer_id = %d
				ORDER BY
					CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END ASC,
					CASE WHEN t.due_date IS NULL THEN 1 ELSE 0 END ASC,
					t.due_date ASC,
					t.updated_at DESC,
					t.id DESC
				LIMIT %d",
				YoOhw_COS_DB::tasks_table(),
				$wpdb->users,
				$customer_id,
				$limit
			);
		} elseif ( 'all' !== $status ) {
			$prepared_sql = $wpdb->prepare(
				"SELECT t.*, u.display_name AS assignee_name
				FROM %i t
				LEFT JOIN %i u ON u.ID = t.assigned_user_id
				WHERE t.customer_id = %d
					AND t.status <> %s
				ORDER BY
					CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END ASC,
					CASE WHEN t.due_date IS NULL THEN 1 ELSE 0 END ASC,
					t.due_date ASC,
					t.updated_at DESC,
					t.id DESC
				LIMIT %d",
				YoOhw_COS_DB::tasks_table(),
				$wpdb->users,
				$customer_id,
				self::STATUS_COMPLETED,
				$limit
			);
		}

		$tasks = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is prepared in every status branch above.
			$prepared_sql,
			ARRAY_A
		);

		return is_array( $tasks ) ? $tasks : array();
	}

	public static function get_order_tasks( int $order_id, array $args = array() ): array {
		global $wpdb;

		$defaults = array(
			'limit'  => 10,
			'status' => 'open',
		);

		$args     = wp_parse_args( $args, $defaults );
		$order_id = absint( $order_id );
		$limit    = max( 1, absint( $args['limit'] ) );
		$status   = sanitize_key( (string) $args['status'] );

		if ( $order_id <= 0 ) {
			return array();
		}

		if ( self::STATUS_COMPLETED === $status ) {
			$prepared_sql = $wpdb->prepare(
				"SELECT t.*, u.display_name AS assignee_name
				FROM %i t
				LEFT JOIN %i u ON u.ID = t.assigned_user_id
				WHERE t.order_id = %d
					AND t.status = %s
				ORDER BY
					CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END ASC,
					CASE WHEN t.due_date IS NULL THEN 1 ELSE 0 END ASC,
					t.due_date ASC,
					t.updated_at DESC,
					t.id DESC
				LIMIT %d",
				YoOhw_COS_DB::tasks_table(),
				$wpdb->users,
				$order_id,
				self::STATUS_COMPLETED,
				$limit
			);
		} elseif ( 'all' === $status ) {
			$prepared_sql = $wpdb->prepare(
				"SELECT t.*, u.display_name AS assignee_name
				FROM %i t
				LEFT JOIN %i u ON u.ID = t.assigned_user_id
				WHERE t.order_id = %d
				ORDER BY
					CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END ASC,
					CASE WHEN t.due_date IS NULL THEN 1 ELSE 0 END ASC,
					t.due_date ASC,
					t.updated_at DESC,
					t.id DESC
				LIMIT %d",
				YoOhw_COS_DB::tasks_table(),
				$wpdb->users,
				$order_id,
				$limit
			);
		} else {
			$prepared_sql = $wpdb->prepare(
				"SELECT t.*, u.display_name AS assignee_name
				FROM %i t
				LEFT JOIN %i u ON u.ID = t.assigned_user_id
				WHERE t.order_id = %d
					AND t.status <> %s
				ORDER BY
					CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END ASC,
					CASE WHEN t.due_date IS NULL THEN 1 ELSE 0 END ASC,
					t.due_date ASC,
					t.updated_at DESC,
					t.id DESC
				LIMIT %d",
				YoOhw_COS_DB::tasks_table(),
				$wpdb->users,
				$order_id,
				self::STATUS_COMPLETED,
				$limit
			);
		}

		$tasks = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is prepared in every status branch above.
			$prepared_sql,
			ARRAY_A
		);

		return is_array( $tasks ) ? $tasks : array();
	}

	public static function get_assigned_tasks( int $user_id, array $args = array() ): array {
		global $wpdb;

		$defaults = array(
			'limit'  => 10,
			'status' => 'open',
		);

		$args    = wp_parse_args( $args, $defaults );
		$user_id = absint( $user_id );
		$limit   = min( 20, max( 1, absint( $args['limit'] ) ) );
		$status  = sanitize_key( (string) $args['status'] );

		if ( $user_id <= 0 ) {
			return array();
		}

		if ( self::STATUS_COMPLETED === $status ) {
			$prepared_sql = $wpdb->prepare(
				"SELECT t.*, c.display_name AS customer_name, c.email AS customer_email, c.phone AS customer_phone, u.display_name AS assignee_name
				FROM %i t
				LEFT JOIN %i c ON c.id = t.customer_id
				LEFT JOIN %i u ON u.ID = t.assigned_user_id
				WHERE t.assigned_user_id = %d
					AND t.status = %s
				ORDER BY
					CASE WHEN t.due_date IS NULL THEN 1 ELSE 0 END ASC,
					t.due_date ASC,
					t.updated_at DESC,
					t.id DESC
				LIMIT %d",
				YoOhw_COS_DB::tasks_table(),
				YoOhw_COS_DB::customers_table(),
				$wpdb->users,
				$user_id,
				self::STATUS_COMPLETED,
				$limit
			);
		} elseif ( 'all' === $status ) {
			$prepared_sql = $wpdb->prepare(
				"SELECT t.*, c.display_name AS customer_name, c.email AS customer_email, c.phone AS customer_phone, u.display_name AS assignee_name
				FROM %i t
				LEFT JOIN %i c ON c.id = t.customer_id
				LEFT JOIN %i u ON u.ID = t.assigned_user_id
				WHERE t.assigned_user_id = %d
				ORDER BY
					CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END ASC,
					CASE WHEN t.due_date IS NULL THEN 1 ELSE 0 END ASC,
					t.due_date ASC,
					t.updated_at DESC,
					t.id DESC
				LIMIT %d",
				YoOhw_COS_DB::tasks_table(),
				YoOhw_COS_DB::customers_table(),
				$wpdb->users,
				$user_id,
				$limit
			);
		} else {
			$prepared_sql = $wpdb->prepare(
				"SELECT t.*, c.display_name AS customer_name, c.email AS customer_email, c.phone AS customer_phone, u.display_name AS assignee_name
				FROM %i t
				LEFT JOIN %i c ON c.id = t.customer_id
				LEFT JOIN %i u ON u.ID = t.assigned_user_id
				WHERE t.assigned_user_id = %d
					AND t.status <> %s
				ORDER BY
					CASE WHEN t.due_date IS NULL THEN 1 ELSE 0 END ASC,
					t.due_date ASC,
					t.updated_at DESC,
					t.id DESC
				LIMIT %d",
				YoOhw_COS_DB::tasks_table(),
				YoOhw_COS_DB::customers_table(),
				$wpdb->users,
				$user_id,
				self::STATUS_COMPLETED,
				$limit
			);
		}

		$tasks = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is prepared in every status branch above.
			$prepared_sql,
			ARRAY_A
		);

		return is_array( $tasks ) ? $tasks : array();
	}

	public static function get_order_task_count( int $order_id, string $status = 'open' ): int {
		global $wpdb;

		$order_id = absint( $order_id );
		$status   = sanitize_key( $status );

		if ( $order_id <= 0 ) {
			return 0;
		}

		if ( self::STATUS_COMPLETED === $status ) {
			$prepared_sql = $wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE order_id = %d AND status = %s',
				YoOhw_COS_DB::tasks_table(),
				$order_id,
				self::STATUS_COMPLETED
			);
		} elseif ( 'all' === $status ) {
			$prepared_sql = $wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE order_id = %d',
				YoOhw_COS_DB::tasks_table(),
				$order_id
			);
		} else {
			$prepared_sql = $wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE order_id = %d AND status <> %s',
				YoOhw_COS_DB::tasks_table(),
				$order_id,
				self::STATUS_COMPLETED
			);
		}

		return (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is prepared in every status branch above.
			$prepared_sql
		);
	}

	public static function get_customer_task_count( int $customer_id, string $status = 'open' ): int {
		global $wpdb;

		$customer_id = absint( $customer_id );
		$status      = sanitize_key( $status );

		if ( $customer_id <= 0 ) {
			return 0;
		}

		if ( self::STATUS_COMPLETED === $status ) {
			$prepared_sql = $wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE customer_id = %d AND status = %s',
				YoOhw_COS_DB::tasks_table(),
				$customer_id,
				self::STATUS_COMPLETED
			);
		} elseif ( 'all' === $status ) {
			$prepared_sql = $wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE customer_id = %d',
				YoOhw_COS_DB::tasks_table(),
				$customer_id
			);
		} else {
			$prepared_sql = $wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE customer_id = %d AND status <> %s',
				YoOhw_COS_DB::tasks_table(),
				$customer_id,
				self::STATUS_COMPLETED
			);
		}

		return (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is prepared in every status branch above.
			$prepared_sql
		);
	}

	public static function get_counts(): array {
		global $wpdb;

		$table = YoOhw_COS_DB::tasks_table();
		$now   = YoOhw_COS_DB::now();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS total_count,
					SUM(CASE WHEN status <> %s THEN 1 ELSE 0 END) AS open_count,
					SUM(CASE WHEN status <> %s AND due_date IS NOT NULL AND due_date < %s THEN 1 ELSE 0 END) AS overdue_count,
					SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS completed_count,
					SUM(CASE WHEN status <> %s AND assigned_user_id = %d THEN 1 ELSE 0 END) AS assigned_to_me_count
				FROM %i",
				self::STATUS_COMPLETED,
				self::STATUS_COMPLETED,
				$now,
				self::STATUS_COMPLETED,
				self::STATUS_COMPLETED,
				get_current_user_id(),
				$table
			),
			ARRAY_A
		);

		return array(
			'all'            => absint( $row['total_count'] ?? 0 ),
			'open'           => absint( $row['open_count'] ?? 0 ),
			'overdue'        => absint( $row['overdue_count'] ?? 0 ),
			'completed'      => absint( $row['completed_count'] ?? 0 ),
			'assigned_to_me' => absint( $row['assigned_to_me_count'] ?? 0 ),
		);
	}

	private static function normalize_optional_id( $value ): ?int {
		$value = absint( $value );

		return $value > 0 ? $value : null;
	}

	private static function normalize_assignee_id( $value ): ?int {
		$user_id = absint( $value );

		if ( $user_id <= 0 || ! self::is_assignable_user( $user_id ) ) {
			return null;
		}

		return $user_id;
	}

	public static function normalize_due_date( string $date ): ?string {
		$date = trim( sanitize_text_field( $date ) );

		if ( '' === $date ) {
			return null;
		}

		$timestamp = strtotime( $date );

		if ( ! $timestamp ) {
			return null;
		}

		if ( function_exists( 'wp_date' ) ) {
			return wp_date( 'Y-m-d H:i:s', $timestamp );
		}

		return date_i18n( 'Y-m-d H:i:s', $timestamp );
	}

	public static function format_due_date_for_input( ?string $date ): string {
		$timestamp = YoOhw_COS_DB::date_timestamp( $date );

		return $timestamp ? date_i18n( 'Y-m-d\TH:i', $timestamp ) : '';
	}

	private static function record_task_event( int $task_id, string $event_type ): void {
		$task = self::get_task( $task_id );

		if ( empty( $task ) ) {
			return;
		}

		$is_completed = 'task_completed' === $event_type;
		$title        = (string) ( $task['title'] ?? '' );
		$description  = $is_completed
			? sprintf(
				/* translators: %s: task title */
				__( 'Task completed: %s', 'yoohw-customer-intelligence' ),
				$title
			)
			: sprintf(
				/* translators: %s: task title */
				__( 'Task created: %s', 'yoohw-customer-intelligence' ),
				$title
			);

		YoOhw_COS_Events::record(
			array(
				'customer_id'  => absint( $task['customer_id'] ?? 0 ),
				'event_type'   => $event_type,
				'event_source' => 'customer_os',
				'severity'     => $is_completed ? 'success' : 'info',
				'object_type'  => 'task',
				'object_id'    => $task_id,
				'description'  => $description,
				'metadata'     => array(
					'task_id'  => $task_id,
					'title'    => $title,
					'priority' => $task['priority'] ?? '',
					'status'   => $task['status'] ?? '',
				),
			)
		);
	}
}
