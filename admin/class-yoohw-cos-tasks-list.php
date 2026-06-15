<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class YoOhw_COS_Tasks_List extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'yoohw_cos_task',
				'plural'   => 'yoohw_cos_tasks',
				'ajax'     => false,
			)
		);
	}

	public function get_columns(): array {
		return array(
			'cb'       => '<input type="checkbox" />',
			'title'    => __( 'Task', 'yoohw-customer-intelligence' ),
			'customer' => __( 'Customer', 'yoohw-customer-intelligence' ),
			'due_date' => __( 'Due', 'yoohw-customer-intelligence' ),
			'status'   => __( 'Status', 'yoohw-customer-intelligence' ),
			'priority' => __( 'Priority', 'yoohw-customer-intelligence' ),
			'assignee' => __( 'Assignee', 'yoohw-customer-intelligence' ),
			'order'    => __( 'Order', 'yoohw-customer-intelligence' ),
			'updated'  => __( 'Updated', 'yoohw-customer-intelligence' ),
		);
	}

	public function column_cb( $item ): string {
		return '<input type="checkbox" name="task_ids[]" value="' . esc_attr( absint( $item['id'] ) ) . '" />';
	}

	protected function get_bulk_actions(): array {
		return array(
			'complete' => __( 'Mark complete', 'yoohw-customer-intelligence' ),
			'reopen'   => __( 'Reopen', 'yoohw-customer-intelligence' ),
			'delete'   => __( 'Delete', 'yoohw-customer-intelligence' ),
		);
	}

	public function prepare_items(): void {
		global $wpdb;

		$tasks_table     = YoOhw_COS_DB::tasks_table();
		$customers_table = YoOhw_COS_DB::customers_table();
		$users_table     = $wpdb->users;

		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * $per_page;
		$view         = isset( $_REQUEST['task_view'] ) ? sanitize_key( wp_unslash( $_REQUEST['task_view'] ) ) : 'open';
		$priority     = isset( $_REQUEST['priority'] ) ? sanitize_key( wp_unslash( $_REQUEST['priority'] ) ) : '';
		$assignee     = isset( $_REQUEST['assigned_user_id'] ) ? absint( wp_unslash( $_REQUEST['assigned_user_id'] ) ) : 0;
		$customer_id  = isset( $_REQUEST['customer_id'] ) ? absint( wp_unslash( $_REQUEST['customer_id'] ) ) : 0;
		$order_id     = isset( $_REQUEST['order_id'] ) ? absint( wp_unslash( $_REQUEST['order_id'] ) ) : 0;
		$search       = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		$now          = YoOhw_COS_DB::now();

		$where  = 'WHERE 1=1';
		$params = array();

		switch ( $view ) {
			case 'all':
				break;

			case 'overdue':
				$where   .= ' AND t.status <> %s AND t.due_date IS NOT NULL AND t.due_date < %s';
				$params[] = YoOhw_COS_Tasks::STATUS_COMPLETED;
				$params[] = $now;
				break;

			case 'completed':
				$where   .= ' AND t.status = %s';
				$params[] = YoOhw_COS_Tasks::STATUS_COMPLETED;
				break;

			case 'assigned_to_me':
				$where   .= ' AND t.status <> %s AND t.assigned_user_id = %d';
				$params[] = YoOhw_COS_Tasks::STATUS_COMPLETED;
				$params[] = get_current_user_id();
				break;

			case 'open':
			default:
				$where   .= ' AND t.status <> %s';
				$params[] = YoOhw_COS_Tasks::STATUS_COMPLETED;
				break;
		}

		if ( '' !== $priority && isset( YoOhw_COS_Tasks::get_priorities()[ $priority ] ) ) {
			$where   .= ' AND t.priority = %s';
			$params[] = $priority;
		}

		if ( $assignee > 0 ) {
			$where   .= ' AND t.assigned_user_id = %d';
			$params[] = $assignee;
		}

		if ( $customer_id > 0 ) {
			$where   .= ' AND t.customer_id = %d';
			$params[] = $customer_id;
		}

		if ( $order_id > 0 ) {
			$where   .= ' AND t.order_id = %d';
			$params[] = $order_id;
		}

		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';

			$where .= ' AND (
				t.title LIKE %s
				OR t.description LIKE %s
				OR c.display_name LIKE %s
				OR c.email LIKE %s
				OR c.phone LIKE %s
			)';

			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Filter SQL fragments are hardcoded; values are passed through placeholders.
		$total_items = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM %i t
				LEFT JOIN %i c ON c.id = t.customer_id
				LEFT JOIN %i u ON u.ID = t.assigned_user_id
				{$where}",
				...array_merge( array( $tasks_table, $customers_table, $users_table ), $params )
			)
		);

		$this->items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					t.*,
					c.display_name AS customer_name,
					c.email AS customer_email,
					c.phone AS customer_phone,
					u.display_name AS assignee_name
				FROM %i t
				LEFT JOIN %i c ON c.id = t.customer_id
				LEFT JOIN %i u ON u.ID = t.assigned_user_id
				{$where}
				ORDER BY
					CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END ASC,
					CASE WHEN t.due_date IS NULL THEN 1 ELSE 0 END ASC,
					t.due_date ASC,
					t.updated_at DESC,
					t.id DESC
				LIMIT %d OFFSET %d",
				...array_merge( array( $tasks_table, $customers_table, $users_table ), $params, array( $per_page, $offset ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			array(),
		);

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => ceil( $total_items / $per_page ),
			)
		);
	}

	protected function get_views(): array {
		$current = isset( $_GET['task_view'] ) ? sanitize_key( wp_unslash( $_GET['task_view'] ) ) : 'open';
		$counts  = YoOhw_COS_Tasks::get_counts();

		$labels = array(
			'all'            => __( 'All', 'yoohw-customer-intelligence' ),
			'open'           => __( 'Open', 'yoohw-customer-intelligence' ),
			'overdue'        => __( 'Overdue', 'yoohw-customer-intelligence' ),
			'completed'      => __( 'Completed', 'yoohw-customer-intelligence' ),
			'assigned_to_me' => __( 'Assigned to me', 'yoohw-customer-intelligence' ),
		);

		$views = array();

		foreach ( $labels as $view => $label ) {
			$args = array(
				'page'      => 'yoohw-customer-intelligence-tasks',
				'task_view' => $view,
			);

			foreach ( array( 'priority', 'assigned_user_id', 'customer_id', 'order_id', 's' ) as $key ) {
				if ( ! empty( $_GET[ $key ] ) ) {
					$args[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
				}
			}

			$class = $current === $view ? ' class="current" aria-current="page"' : '';

			$views[ $view ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( add_query_arg( $args, admin_url( 'admin.php' ) ) ),
				$class,
				esc_html( $label ),
				esc_html( number_format_i18n( absint( $counts[ $view ] ?? 0 ) ) )
			);
		}

		return $views;
	}

	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		$current_priority = isset( $_GET['priority'] ) ? sanitize_key( wp_unslash( $_GET['priority'] ) ) : '';
		$current_assignee = isset( $_GET['assigned_user_id'] ) ? absint( wp_unslash( $_GET['assigned_user_id'] ) ) : 0;

		echo '<div class="alignleft actions">';
		echo '<select name="priority">';
		echo '<option value="">' . esc_html__( 'All priorities', 'yoohw-customer-intelligence' ) . '</option>';

		foreach ( YoOhw_COS_Tasks::get_priorities() as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $current_priority, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}

		echo '</select>';

		$this->render_assignee_filter( $current_assignee );

		submit_button(
			__( 'Filter', 'yoohw-customer-intelligence' ),
			'secondary',
			'filter_action',
			false
		);

		echo '</div>';
	}

	private function render_assignee_filter( int $current_assignee ): void {
		$users = YoOhw_COS_Tasks::get_assignable_users();

		echo '<select name="assigned_user_id">';
		echo '<option value="0">' . esc_html__( 'All assignees', 'yoohw-customer-intelligence' ) . '</option>';

		foreach ( $users as $user ) {
			echo '<option value="' . esc_attr( absint( $user->ID ) ) . '" ' . selected( $current_assignee, absint( $user->ID ), false ) . '>';
			echo esc_html( $user->display_name );
			echo '</option>';
		}

		echo '</select>';
	}

	public function column_title( array $item ): string {
		$task_id  = absint( $item['id'] );
		$edit_url = add_query_arg(
			array(
				'page'    => 'yoohw-customer-intelligence-tasks',
				'task_id' => $task_id,
			),
			admin_url( 'admin.php' )
		);

		$status_action = YoOhw_COS_Tasks::STATUS_COMPLETED === (string) ( $item['status'] ?? '' ) ? 'reopen' : 'complete';
		$status_label  = 'reopen' === $status_action
			? __( 'Reopen', 'yoohw-customer-intelligence' )
			: __( 'Complete', 'yoohw-customer-intelligence' );

		$status_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'      => 'yoohw_cos_' . $status_action . '_task',
					'task_id'     => $task_id,
					'_redirect'   => rawurlencode( $this->get_current_url() ),
				),
				admin_url( 'admin-post.php' )
			),
			'yoohw_cos_' . $status_action . '_task'
		);

		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'    => 'yoohw_cos_delete_task',
					'task_id'   => $task_id,
					'_redirect' => rawurlencode( $this->get_current_url() ),
				),
				admin_url( 'admin-post.php' )
			),
			'yoohw_cos_delete_task'
		);

		$output  = '<strong><a class="row-title" href="' . esc_url( $edit_url ) . '">' . esc_html( $item['title'] ?? '' ) . '</a></strong>';
		$output .= '<div class="row-actions">';
		$output .= '<span><a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'yoohw-customer-intelligence' ) . '</a></span>';
		$output .= ' | ';
		$output .= '<span><a href="' . esc_url( $status_url ) . '">' . esc_html( $status_label ) . '</a></span>';
		$output .= ' | ';
		$output .= '<span class="delete"><a class="submitdelete" href="' . esc_url( $delete_url ) . '" data-yoohw-cos-confirm="' . esc_attr__( 'Delete this task?', 'yoohw-customer-intelligence' ) . '">' . esc_html__( 'Delete', 'yoohw-customer-intelligence' ) . '</a></span>';
		$output .= '</div>';

		return $output;
	}

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'customer':
				return $this->format_customer( $item );

			case 'due_date':
				return $this->format_due_date( $item );

			case 'status':
				return $this->format_status( (string) ( $item['status'] ?? '' ) );

			case 'priority':
				return $this->format_priority( (string) ( $item['priority'] ?? '' ) );

			case 'assignee':
				return ! empty( $item['assignee_name'] ) ? esc_html( $item['assignee_name'] ) : '&mdash;';

			case 'order':
				return $this->format_order( $item );

			case 'updated':
				return YoOhw_COS_DB::format_admin_date( $item['updated_at'] ?? '', '&mdash;' );

			default:
				return '';
		}
	}

	public function no_items(): void {
		$view = isset( $_REQUEST['task_view'] ) ? sanitize_key( wp_unslash( $_REQUEST['task_view'] ) ) : 'open';

		if ( YoOhw_COS_Admin_UI::has_request_filters( array( 's', 'priority', 'assigned_user_id', 'customer_id', 'order_id' ) ) ) {
			YoOhw_COS_Admin_UI::render_empty_state(
				__( 'No tasks match the current filters.', 'yoohw-customer-intelligence' ),
				__( 'Adjust the filters or clear the search to see more tasks.', 'yoohw-customer-intelligence' )
			);
			return;
		}

		$states = array(
			'all'            => array(
				__( 'No tasks yet.', 'yoohw-customer-intelligence' ),
				__( 'Create follow-up tasks from a customer profile or the task form.', 'yoohw-customer-intelligence' ),
			),
			'open'           => array(
				__( 'No open tasks.', 'yoohw-customer-intelligence' ),
				__( 'Create a follow-up task when a customer needs manual action.', 'yoohw-customer-intelligence' ),
			),
			'overdue'        => array(
				__( 'No overdue tasks.', 'yoohw-customer-intelligence' ),
				__( 'Tasks past their due date will appear here.', 'yoohw-customer-intelligence' ),
			),
			'completed'      => array(
				__( 'No completed tasks yet.', 'yoohw-customer-intelligence' ),
				__( 'Completed follow-ups will appear here.', 'yoohw-customer-intelligence' ),
			),
			'assigned_to_me' => array(
				__( 'No tasks assigned to you.', 'yoohw-customer-intelligence' ),
				__( 'Open tasks assigned to your account will appear here.', 'yoohw-customer-intelligence' ),
			),
		);

		$state = $states[ $view ] ?? $states['open'];

		YoOhw_COS_Admin_UI::render_empty_state( $state[0], $state[1] );
	}

	private function format_customer( array $item ): string {
		$customer_id = absint( $item['customer_id'] ?? 0 );

		if ( $customer_id <= 0 ) {
			return '&mdash;';
		}

		$name = ! empty( $item['customer_name'] )
			? $item['customer_name']
			: ( $item['customer_email'] ?? __( '(No name)', 'yoohw-customer-intelligence' ) );

		$url = add_query_arg(
			array(
				'page'        => 'yoohw-customer-intelligence',
				'customer_id' => $customer_id,
			),
			admin_url( 'admin.php' )
		);

		return '<a href="' . esc_url( $url ) . '"><strong>' . esc_html( $name ) . '</strong></a>';
	}

	private function format_due_date( array $item ): string {
		$date = YoOhw_COS_DB::format_admin_date( $item['due_date'] ?? '', '&mdash;' );

		if (
			YoOhw_COS_Tasks::STATUS_COMPLETED !== (string) ( $item['status'] ?? '' )
			&& YoOhw_COS_DB::date_timestamp( $item['due_date'] ?? '' ) > 0
			&& YoOhw_COS_DB::date_timestamp( $item['due_date'] ?? '' ) < current_time( 'timestamp' )
		) {
			return '<span class="yoohw-cos-task-overdue">' . wp_kses_post( $date ) . '</span>';
		}

		return $date;
	}

	private function format_status( string $status ): string {
		$status = YoOhw_COS_Tasks::normalize_status( $status );
		$label  = YoOhw_COS_Tasks::get_statuses()[ $status ] ?? ucfirst( $status );

		return '<span class="yoohw-cos-badge yoohw-cos-badge--task-status-' . esc_attr( sanitize_html_class( $status ) ) . '">' . esc_html( $label ) . '</span>';
	}

	private function format_priority( string $priority ): string {
		$priority = YoOhw_COS_Tasks::normalize_priority( $priority );
		$label    = YoOhw_COS_Tasks::get_priorities()[ $priority ] ?? ucfirst( $priority );

		return '<span class="yoohw-cos-badge yoohw-cos-badge--task-priority-' . esc_attr( sanitize_html_class( $priority ) ) . '">' . esc_html( $label ) . '</span>';
	}

	private function format_order( array $item ): string {
		$order_id = absint( $item['order_id'] ?? 0 );

		if ( $order_id <= 0 ) {
			return '&mdash;';
		}

		if ( function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );

			if ( $order instanceof WC_Order ) {
				return '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a>';
			}
		}

		return '#' . esc_html( $order_id );
	}

	private function get_current_url(): string {
		$args = array(
			'page' => 'yoohw-customer-intelligence-tasks',
		);

		foreach ( array( 'task_view', 'priority', 'assigned_user_id', 'customer_id', 'order_id', 's', 'paged' ) as $key ) {
			if ( ! empty( $_REQUEST[ $key ] ) ) {
				$args[ $key ] = sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) );
			}
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}
}
