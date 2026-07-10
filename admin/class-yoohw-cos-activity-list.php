<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class YoOhw_COS_Activity_List extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'yoohw_cos_event',
				'plural'   => 'yoohw_cos_events',
				'ajax'     => false,
			)
		);
	}

	public function get_columns(): array {
		return array(
			'created_at'   => __( 'Date', 'yoohw-customer-intelligence' ),
			'customer'     => __( 'Customer', 'yoohw-customer-intelligence' ),
			'event_type'   => __( 'Event', 'yoohw-customer-intelligence' ),
			'description'  => __( 'Description', 'yoohw-customer-intelligence' ),
			'event_source' => __( 'Source', 'yoohw-customer-intelligence' ),
			'severity'     => __( 'Severity', 'yoohw-customer-intelligence' ),
			'object'       => __( 'Object', 'yoohw-customer-intelligence' ),
		);
	}

	public function prepare_items(): void {
		global $wpdb;

		$events_table    = YoOhw_COS_DB::events_table();
		$customers_table = YoOhw_COS_DB::customers_table();

		$per_page     = 30;
		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * $per_page;

		$event_type   = isset( $_REQUEST['event_type'] ) ? sanitize_key( wp_unslash( $_REQUEST['event_type'] ) ) : '';
		$event_source = isset( $_REQUEST['event_source'] ) ? sanitize_key( wp_unslash( $_REQUEST['event_source'] ) ) : '';
		$severity     = isset( $_REQUEST['severity'] ) ? sanitize_key( wp_unslash( $_REQUEST['severity'] ) ) : '';
		$customer_id  = isset( $_REQUEST['customer_id'] ) ? absint( wp_unslash( $_REQUEST['customer_id'] ) ) : 0;
		$search       = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';

		$where  = 'WHERE 1=1';
		$params = array();

		if ( '' !== $event_type ) {
			$where   .= ' AND e.event_type = %s';
			$params[] = $event_type;
		}

		if ( '' !== $event_source ) {
			$where   .= ' AND e.event_source = %s';
			$params[] = $event_source;
		}

		if ( '' !== $severity ) {
			$where   .= ' AND e.severity = %s';
			$params[] = $severity;
		}

		if ( $customer_id > 0 ) {
			$where   .= ' AND e.customer_id = %d';
			$params[] = $customer_id;
		}

		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';

			$where .= ' AND (
				e.event_type LIKE %s
				OR e.description LIKE %s
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
				FROM %i e
				LEFT JOIN %i c ON c.id = e.customer_id
				{$where}",
				...array_merge( array( $events_table, $customers_table ), $params )
			)
		);

		$this->items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.*, c.display_name, c.email, c.phone
				FROM %i e
				LEFT JOIN %i c ON c.id = e.customer_id
				{$where}
				ORDER BY e.created_at DESC, e.id DESC
				LIMIT %d OFFSET %d",
				...array_merge( array( $events_table, $customers_table ), $params, array( $per_page, $offset ) )
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
		$current = isset( $_GET['severity'] ) ? sanitize_key( wp_unslash( $_GET['severity'] ) ) : '';
		$counts  = $this->get_severity_view_counts();

		$labels = array(
			''        => __( 'All', 'yoohw-customer-intelligence' ),
			'info'    => __( 'Info', 'yoohw-customer-intelligence' ),
			'success' => __( 'Success', 'yoohw-customer-intelligence' ),
			'warning' => __( 'Warning', 'yoohw-customer-intelligence' ),
			'error'   => __( 'Error', 'yoohw-customer-intelligence' ),
		);

		$views = array();

		foreach ( $labels as $severity => $label ) {
			$args = array(
				'page' => 'yoohw-customer-intelligence-activity',
			);

			foreach ( array( 'event_type', 'event_source', 'customer_id', 's' ) as $key ) {
				if ( ! empty( $_GET[ $key ] ) ) {
					$args[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
				}
			}

			if ( '' !== $severity ) {
				$args['severity'] = $severity;
			}

			$count = '' === $severity ? ( $counts['all'] ?? 0 ) : ( $counts[ $severity ] ?? 0 );
			$class = $current === $severity ? ' class="current" aria-current="page"' : '';
			$key   = '' === $severity ? 'all' : $severity;

			$views[ $key ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( add_query_arg( $args, admin_url( 'admin.php' ) ) ),
				$class,
				esc_html( $label ),
				esc_html( number_format_i18n( $count ) )
			);
		}

		return $views;
	}

	private function get_severity_view_counts(): array {
		global $wpdb;

		$events_table    = YoOhw_COS_DB::events_table();
		$customers_table = YoOhw_COS_DB::customers_table();

		$where  = 'WHERE 1=1';
		$params = array();

		if ( ! empty( $_GET['event_type'] ) ) {
			$where   .= ' AND e.event_type = %s';
			$params[] = sanitize_key( wp_unslash( $_GET['event_type'] ) );
		}

		if ( ! empty( $_GET['event_source'] ) ) {
			$where   .= ' AND e.event_source = %s';
			$params[] = sanitize_key( wp_unslash( $_GET['event_source'] ) );
		}

		if ( ! empty( $_GET['customer_id'] ) ) {
			$where   .= ' AND e.customer_id = %d';
			$params[] = absint( wp_unslash( $_GET['customer_id'] ) );
		}

		if ( ! empty( $_GET['s'] ) ) {
			$search = sanitize_text_field( wp_unslash( $_GET['s'] ) );
			$like   = '%' . $wpdb->esc_like( $search ) . '%';

			$where .= ' AND (
				e.event_type LIKE %s
				OR e.description LIKE %s
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
		$counts = array(
			'all'     => (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*)
					FROM %i e
					LEFT JOIN %i c ON c.id = e.customer_id
					{$where}",
					...array_merge( array( $events_table, $customers_table ), $params )
				)
			),
			'info'    => 0,
			'success' => 0,
			'warning' => 0,
			'error'   => 0,
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.severity AS label, COUNT(*) AS total
				FROM %i e
				LEFT JOIN %i c ON c.id = e.customer_id
				{$where}
				GROUP BY e.severity",
				...array_merge( array( $events_table, $customers_table ), $params )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$key = sanitize_key( $row['label'] ?? '' );

				if ( isset( $counts[ $key ] ) ) {
					$counts[ $key ] = absint( $row['total'] ?? 0 );
				}
			}
		}

		return $counts;
	}

	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		$current_type   = isset( $_GET['event_type'] ) ? sanitize_key( wp_unslash( $_GET['event_type'] ) ) : '';
		$current_source = isset( $_GET['event_source'] ) ? sanitize_key( wp_unslash( $_GET['event_source'] ) ) : '';

		echo '<div class="alignleft actions">';

		$event_type_options = array(
			''                => __( 'All event types', 'yoohw-customer-intelligence' ),
			'order_synced'    => __( 'Order synced', 'yoohw-customer-intelligence' ),
			'tag_assigned'    => __( 'Tag assigned', 'yoohw-customer-intelligence' ),
			'tag_removed'     => __( 'Tag removed', 'yoohw-customer-intelligence' ),
			'note_added'      => __( 'Note added', 'yoohw-customer-intelligence' ),
			'note_updated'    => __( 'Note updated', 'yoohw-customer-intelligence' ),
			'note_deleted'    => __( 'Note deleted', 'yoohw-customer-intelligence' ),
			'task_created'    => __( 'Task created', 'yoohw-customer-intelligence' ),
			'task_completed'  => __( 'Task completed', 'yoohw-customer-intelligence' ),
			'bulk_customer_action' => __( 'Bulk customer action', 'yoohw-customer-intelligence' ),
		);

		if ( self::is_blacklist_manager_integration_active() ) {
			$event_type_options += array(
				'blacklist_blocked'        => __( 'Blacklist blocked', 'yoohw-customer-intelligence' ),
				'blacklist_match_detected' => __( 'Blacklist match', 'yoohw-customer-intelligence' ),
				'blacklist_removed'        => __( 'Blacklist cleared', 'yoohw-customer-intelligence' ),
				'blacklist_suspect'        => __( 'Blacklist suspect', 'yoohw-customer-intelligence' ),
			);
		}

		if ( self::is_blacklist_manager_premium_integration_active() ) {
			$event_type_options += array(
				'premium_order_risk_scored' => __( 'Premium order risk scored', 'yoohw-customer-intelligence' ),
				'premium_risk_rule_matched' => __( 'Premium risk rule matched', 'yoohw-customer-intelligence' ),
				'premium_antibot_blocked' => __( 'Premium anti-bot blocked', 'yoohw-customer-intelligence' ),
				'premium_antibot_would_block' => __( 'Premium anti-bot challenge', 'yoohw-customer-intelligence' ),
				'premium_payment_abuse_detected' => __( 'Premium payment abuse', 'yoohw-customer-intelligence' ),
				'premium_device_signal_detected' => __( 'Premium device signal', 'yoohw-customer-intelligence' ),
				'premium_gateway_fraud_signal' => __( 'Premium gateway fraud signal', 'yoohw-customer-intelligence' ),
			);
		}

		$this->render_select(
			'event_type',
			$current_type,
			$event_type_options
		);

		$source_options = array(
			''            => __( 'All sources', 'yoohw-customer-intelligence' ),
			'system'      => __( 'System', 'yoohw-customer-intelligence' ),
			'woocommerce' => __( 'WooCommerce', 'yoohw-customer-intelligence' ),
			'customer_os' => __( 'Customer', 'yoohw-customer-intelligence' ),
		);

		if ( self::is_blacklist_manager_integration_active() ) {
			$source_options['wc_blacklist_manager'] = __( 'Blacklist Manager', 'yoohw-customer-intelligence' );
		}

		if ( self::is_blacklist_manager_premium_integration_active() ) {
			$source_options['wc_blacklist_manager_premium'] = __( 'Blacklist Manager Premium', 'yoohw-customer-intelligence' );
		}

		$this->render_select(
			'event_source',
			$current_source,
			$source_options
		);

		submit_button(
			__( 'Filter', 'yoohw-customer-intelligence' ),
			'secondary',
			'filter_action',
			false
		);

		echo '</div>';
	}

	private function render_select( string $name, string $current, array $options ): void {
		echo '<select name="' . esc_attr( $name ) . '">';

		foreach ( $options as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $current, $value, false ) . '>';
			echo esc_html( $label );
			echo '</option>';
		}

		echo '</select>';
	}

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'created_at':
				return $this->format_date( $item['created_at'] ?? '' );

			case 'customer':
				return $this->format_customer( $item );

			case 'event_type':
				return '<code>' . esc_html( $item['event_type'] ?? '' ) . '</code>';

			case 'description':
				return wp_kses_post( $item['description'] ?? '' );

			case 'event_source':
				return esc_html( $this->format_event_source_label( (string) ( $item['event_source'] ?? '' ) ) );

			case 'severity':
				return $this->format_severity( $item['severity'] ?? 'info' );

			case 'object':
				return $this->format_object( $item );

			default:
				return '';
		}
	}

	public function no_items(): void {
		if ( YoOhw_COS_Admin_UI::has_request_filters( array( 's', 'event_type', 'event_source', 'severity', 'customer_id' ) ) ) {
			YoOhw_COS_Admin_UI::render_empty_state(
				__( 'No activity matches the current filters.', 'yoohw-customer-intelligence' ),
				__( 'Adjust filters or clear search to see more activity.', 'yoohw-customer-intelligence' )
			);
			return;
		}

		YoOhw_COS_Admin_UI::render_empty_state(
			__( 'No activity recorded yet.', 'yoohw-customer-intelligence' ),
			__( 'Customer sync and team actions will appear here.', 'yoohw-customer-intelligence' )
		);
	}

	private function format_customer( array $item ): string {
		if ( empty( $item['customer_id'] ) ) {
			return '&mdash;';
		}

		$name = ! empty( $item['display_name'] )
			? $item['display_name']
			: ( $item['email'] ?? __( '(No name)', 'yoohw-customer-intelligence' ) );

		$url = add_query_arg(
			array(
				'page'        => 'yoohw-customer-intelligence',
				'customer_id' => absint( $item['customer_id'] ),
			),
			admin_url( 'admin.php' )
		);

		return '<a href="' . esc_url( $url ) . '"><strong>' . esc_html( $name ) . '</strong></a>';
	}

	private function format_severity( string $severity ): string {
		return '<span class="yoohw-cos-badge yoohw-cos-badge--severity-' . esc_attr( sanitize_html_class( $severity ) ) . '">' . esc_html( ucfirst( $severity ) ) . '</span>';
	}

	private function format_object( array $item ): string {
		if ( empty( $item['object_type'] ) || empty( $item['object_id'] ) ) {
			return '&mdash;';
		}

		if ( 'order' === $item['object_type'] && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( absint( $item['object_id'] ) );

			if ( $order instanceof WC_Order ) {
				return '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a>';
			}
		}

		return esc_html( $item['object_type'] . ':' . $item['object_id'] );
	}

	private function format_event_source_label( string $source ): string {
		$source = sanitize_key( $source );

		$labels = array(
			'customer_os'                  => __( 'Customer', 'yoohw-customer-intelligence' ),
			'system'                       => __( 'System', 'yoohw-customer-intelligence' ),
			'woocommerce'                  => __( 'WooCommerce', 'yoohw-customer-intelligence' ),
			'wc_blacklist_manager'         => __( 'Blacklist Manager', 'yoohw-customer-intelligence' ),
			'wc_blacklist_manager_premium' => __( 'Blacklist Manager Premium', 'yoohw-customer-intelligence' ),
		);

		return $labels[ $source ] ?? ( '' !== $source ? ucwords( str_replace( '_', ' ', $source ) ) : '—' );
	}

	private static function is_blacklist_manager_integration_active(): bool {
		return class_exists( 'YoOhw_COS_Blacklist_Manager_Integration' )
			&& is_callable( array( 'YoOhw_COS_Blacklist_Manager_Integration', 'is_active' ) )
			&& YoOhw_COS_Blacklist_Manager_Integration::is_active();
	}

	private static function is_blacklist_manager_premium_integration_active(): bool {
		return self::is_blacklist_manager_integration_active()
			&& class_exists( 'YoOhw_COS_Blacklist_Manager_Premium_Integration' )
			&& is_callable( array( 'YoOhw_COS_Blacklist_Manager_Premium_Integration', 'is_active' ) )
			&& YoOhw_COS_Blacklist_Manager_Premium_Integration::is_active();
	}

	private function format_date( ?string $date ): string {
		return YoOhw_COS_DB::format_admin_date( $date, '&mdash;' );
	}
}
