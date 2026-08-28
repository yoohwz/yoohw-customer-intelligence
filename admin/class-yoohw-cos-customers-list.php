<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class YoOhw_COS_Customers_List extends WP_List_Table {

	private $tags_by_customer = array();

	private $segments_by_customer = array();

	private $relationships_primed = false;

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'yoohw_cos_customer',
				'plural'   => 'yoohw_cos_customers',
				'ajax'     => false,
			)
		);
	}

	public function get_columns(): array {
		$columns = array(
			'cb'                 => '<input type="checkbox" />',
			'customer'           => __( 'Customer', 'yoohw-customer-intelligence' ),
			'contact'            => __( 'Contact', 'yoohw-customer-intelligence' ),
			'labels'             => __( 'Labels', 'yoohw-customer-intelligence' ),
			'commerce'           => __( 'Commerce', 'yoohw-customer-intelligence' ),
			'health'             => __( 'Health', 'yoohw-customer-intelligence' ),
			'last_activity_date' => __( 'Last active', 'yoohw-customer-intelligence' ),
		);

		if ( self::is_loyalty_integration_active() ) {
			$columns = self::insert_column_before(
				$columns,
				'last_activity_date',
				'loyalty_level',
				__( 'Loyalty', 'yoohw-customer-intelligence' )
			);
		}

		return $columns;
	}

	public function column_cb( $item ): string {
		return '<input type="checkbox" name="customer_ids[]" value="' . esc_attr( absint( $item['id'] ) ) . '" />';
	}

	protected function get_bulk_actions(): array {
		$customer_view = isset( $_REQUEST['customer_view'] ) ? sanitize_key( wp_unslash( $_REQUEST['customer_view'] ) ) : '';

		if ( 'archived' === $customer_view ) {
			return array(
				'bulk_restore_customer' => __( 'Restore customers', 'yoohw-customer-intelligence' ),
			);
		}

		return array(
			'bulk_assign_tag'      => __( 'Assign tag', 'yoohw-customer-intelligence' ),
			'bulk_remove_tag'      => __( 'Remove tag', 'yoohw-customer-intelligence' ),
			'bulk_assign_segment'  => __( 'Assign segment', 'yoohw-customer-intelligence' ),
			'bulk_remove_segment'  => __( 'Remove segment', 'yoohw-customer-intelligence' ),
			'bulk_create_task'      => __( 'Create follow-up task', 'yoohw-customer-intelligence' ),
			'bulk_archive_customer' => __( 'Archive customers', 'yoohw-customer-intelligence' ),
		);
	}

	protected function get_sortable_columns(): array {
		$columns = array(
			'commerce'           => array( 'total_spent', false ),
			'health'             => array( 'risk_score', false ),
			'last_activity_date' => array( 'last_activity_date', true ),
		);

		if ( self::is_loyalty_integration_active() ) {
			$columns['loyalty_level'] = array( 'loyalty_level', false );
		}

		return $columns;
	}

	public function prepare_items(): void {
		$per_page   = 20;
		$query_args = YoOhw_COS_Customer_Query::sanitize_args( wp_unslash( $_REQUEST ) );

		$query_args['per_page'] = $per_page;
		$query_args['paged']    = $this->get_pagenum();

		$results      = YoOhw_COS_Customer_Query::query( $query_args );
		$total_items  = absint( $results['total_items'] ?? 0 );
		$this->items  = is_array( $results['items'] ?? null ) ? $results['items'] : array();

		$this->prime_customer_relationships();

		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			$this->get_sortable_columns(),
		);

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => ceil( $total_items / $per_page ),
			)
		);
	}

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'contact':
				return $this->format_contact( $item );

			case 'labels':
				return $this->format_labels( $item );

			case 'commerce':
				return $this->format_commerce( $item );

			case 'health':
				return $this->format_health( $item );

			case 'loyalty_level':
				return $this->format_loyalty_level_badge( $item );

			case 'last_activity_date':
				return $this->format_date( $item[ $column_name ] ?? '' );

			default:
				return '';
		}
	}

	public function single_row( $item ) {
		$name = $this->get_customer_name( $item );
		$url  = $this->get_customer_profile_url( $item );

		echo '<tr class="yoohw-cos-clickable-row" data-yoohw-cos-row-url="' . esc_url( $url ) . '" tabindex="0" role="link" aria-label="' . esc_attr( sprintf(
			/* translators: %s: customer display name. */
			__( 'Open customer profile: %s', 'yoohw-customer-intelligence' ),
			$name
		) ) . '">';
		$this->single_row_columns( $item );
		echo '</tr>';
	}

	public function column_customer( array $item ): string {
		$name        = $this->get_customer_name( $item );
		$profile_url = $this->get_customer_profile_url( $item );

		$output = '<strong><a href="' . esc_url( $profile_url ) . '">' . esc_html( $name ) . '</a></strong>';

		if ( ! empty( $item['wp_user_id'] ) ) {
			$user_url = get_edit_user_link( absint( $item['wp_user_id'] ) );

			if ( $user_url ) {
				$output .= '<div class="row-actions">';
				$output .= '<span class="edit-user"><a href="' . esc_url( $user_url ) . '">' . esc_html__( 'Edit user', 'yoohw-customer-intelligence' ) . '</a></span>';
				$output .= '</div>';
			}
		}

		return $output;
	}

	private function get_customer_name( array $item ): string {
		return ! empty( $item['display_name'] ) ? (string) $item['display_name'] : __( '(No name)', 'yoohw-customer-intelligence' );
	}

	private function get_customer_profile_url( array $item ): string {
		return add_query_arg(
			array(
				'page'        => 'yoohw-customer-intelligence',
				'customer_id' => absint( $item['id'] ?? 0 ),
			),
			admin_url( 'admin.php' )
		);
	}

	public function no_items(): void {
		$customer_view = isset( $_REQUEST['customer_view'] ) ? sanitize_key( wp_unslash( $_REQUEST['customer_view'] ) ) : '';

		if ( 'archived' === $customer_view ) {
			YoOhw_COS_Admin_UI::render_empty_state(
				__( 'No archived customers.', 'yoohw-customer-intelligence' ),
				__( 'Archived customer profiles will appear here after you archive them from the customers list.', 'yoohw-customer-intelligence' )
			);
			return;
		}

		$filter_keys = array( 's', 'customer_status', 'customer_tag', 'customer_segment', 'vip_status', 'risk_level', 'lifecycle_stage', 'customer_cohort', 'customer_attention' );

		if ( self::is_loyalty_integration_active() ) {
			$filter_keys[] = 'loyalty_level';
		}

		if ( YoOhw_COS_Admin_UI::has_request_filters( $filter_keys ) ) {
			YoOhw_COS_Admin_UI::render_empty_state(
				__( 'No customers match the current filters.', 'yoohw-customer-intelligence' ),
				__( 'Adjust the filters or clear the search to broaden the list.', 'yoohw-customer-intelligence' )
			);
			return;
		}

		YoOhw_COS_Admin_UI::render_empty_state(
			__( 'No customers synced yet.', 'yoohw-customer-intelligence' ),
			__( 'Sync existing WooCommerce orders to create customer profiles.', 'yoohw-customer-intelligence' ),
			array(
				array(
					'url'   => admin_url( 'admin.php?page=yoohw-customer-intelligence-settings#yoohw-cos-sync-center' ),
					'label' => __( 'Open sync center', 'yoohw-customer-intelligence' ),
					'class' => 'button button-primary',
				),
			)
		);
	}

	protected function get_views(): array {
		$status_counts = YoOhw_COS_Customers::get_status_counts();
		$current_view  = isset( $_GET['customer_view'] ) ? sanitize_key( wp_unslash( $_GET['customer_view'] ) ) : '';
		$current       = 'archived' === $current_view
			? 'archived'
			: ( isset( $_GET['customer_status'] ) ? sanitize_key( wp_unslash( $_GET['customer_status'] ) ) : '' );

		$labels = array(
			''         => __( 'All', 'yoohw-customer-intelligence' ),
			'new'      => __( 'New', 'yoohw-customer-intelligence' ),
			'active'   => __( 'Active', 'yoohw-customer-intelligence' ),
			'at_risk'  => __( 'At risk', 'yoohw-customer-intelligence' ),
			'inactive' => __( 'Inactive', 'yoohw-customer-intelligence' ),
			'vip'      => __( 'VIP', 'yoohw-customer-intelligence' ),
		);

		$views = array();

		foreach ( $labels as $status => $label ) {
			$args = array(
				'page' => 'yoohw-customer-intelligence',
			);

			if ( '' !== $status ) {
				$args['customer_status'] = $status;
			}

			$count = '' === $status
				? array_sum( $status_counts )
				: ( $status_counts[ $status ] ?? 0 );

			$class = $current === $status ? ' class="current" aria-current="page"' : '';
			$key   = '' === $status ? 'all' : $status;

			$views[ $key ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( add_query_arg( $args, admin_url( 'admin.php' ) ) ),
				$class,
				esc_html( $label ),
				esc_html( number_format_i18n( $count ) )
			);
		}

		$archived_count = YoOhw_COS_Customers::get_archived_count();
		$archived_url   = add_query_arg(
			array(
				'page'          => 'yoohw-customer-intelligence',
				'customer_view' => 'archived',
			),
			admin_url( 'admin.php' )
		);
		$archived_class = 'archived' === $current ? ' class="current" aria-current="page"' : '';

		$views['archived'] = sprintf(
			'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
			esc_url( $archived_url ),
			$archived_class,
			esc_html__( 'Archived', 'yoohw-customer-intelligence' ),
			esc_html( number_format_i18n( $archived_count ) )
		);

		return $views;
	}

	private function format_date( ?string $date ): string {
		return YoOhw_COS_DB::format_admin_date( $date, '&mdash;' );
	}

	private function format_contact( array $item ): string {
		$email = sanitize_email( (string) ( $item['email'] ?? '' ) );
		$phone = sanitize_text_field( (string) ( $item['phone'] ?? '' ) );

		if ( '' === $email && '' === $phone ) {
			return '&mdash;';
		}

		$output = '<div class="yoohw-cos-customer-list-stack">';

		if ( $email ) {
			$output .= '<span><a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a></span>';
		}

		if ( $phone ) {
			$output .= '<span class="yoohw-cos-muted"><a href="tel:' . esc_attr( $phone ) . '">' . esc_html( $phone ) . '</a></span>';
		}

		$output .= '</div>';

		return $output;
	}

	private function format_labels( array $item ): string {
		$tags     = $this->column_tags( $item );
		$segments = $this->column_segments( $item );

		if ( '&mdash;' === $tags && '&mdash;' === $segments ) {
			return '&mdash;';
		}

		$output = '<div class="yoohw-cos-customer-labels">';

		if ( '&mdash;' !== $tags ) {
			$output .= '<div>' . $tags . '</div>';
		}

		if ( '&mdash;' !== $segments ) {
			$output .= '<div>' . $segments . '</div>';
		}

		$output .= '</div>';

		return $output;
	}

	private function format_commerce( array $item ): string {
		$orders = absint( $item['total_orders'] ?? 0 );
		$spent  = function_exists( 'wc_price' )
			? wc_price( (float) ( $item['total_spent'] ?? 0 ) )
			: number_format_i18n( (float) ( $item['total_spent'] ?? 0 ), 2 );
		$orders_label = sprintf(
			/* translators: %s: number of customer orders. */
			_n( '%s order', '%s orders', $orders, 'yoohw-customer-intelligence' ),
			number_format_i18n( $orders )
		);

		$output  = '<div class="yoohw-cos-customer-list-stack">';
		$output .= '<strong>';
		$output .= esc_html( $orders_label );
		$output .= '</strong>';
		$output .= '<span class="yoohw-cos-muted">' . wp_kses_post( $spent ) . '</span>';
		$output .= '</div>';

		return $output;
	}

	private function format_health( array $item ): string {
		$status       = sanitize_key( (string) ( $item['customer_status'] ?? '' ) );
		$vip_status   = sanitize_key( (string) ( $item['vip_status'] ?? 'none' ) );
		$has_vip_tier = '' !== $vip_status && 'none' !== $vip_status;

		$output = '<div class="yoohw-cos-customer-health">';

		if ( ! ( $has_vip_tier && 'vip' === $status ) ) {
			$output .= $this->format_status_badge( $status );
		}

		$output .= $this->format_risk_badge( (float) ( $item['risk_score'] ?? 0 ) );

		if ( $has_vip_tier ) {
			$output .= $this->format_vip_badge( $vip_status );
		}

		$output .= '</div>';

		return $output;
	}

	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		$tags     = YoOhw_COS_Tags::get_all_tags();
		$segments = YoOhw_COS_Segments::get_all_segments();
		$users    = YoOhw_COS_Tasks::get_assignable_users();
		$default_assignee_id = YoOhw_COS_Tasks::is_assignable_user( get_current_user_id() )
			? get_current_user_id()
			: 0;

		echo '<div class="alignleft actions yoohw-cos-bulk-targets" data-yoohw-cos-bulk-targets hidden>';

		echo '<span class="yoohw-cos-bulk-target" data-yoohw-cos-bulk-target="tag" hidden>';
		echo '<label class="screen-reader-text" for="yoohw-cos-bulk-tag-id">';
		echo esc_html__( 'Tag for bulk action', 'yoohw-customer-intelligence' );
		echo '</label>';
		echo '<select name="bulk_tag_id" id="yoohw-cos-bulk-tag-id">';
		echo '<option value="0">' . esc_html__( 'Tag for bulk action', 'yoohw-customer-intelligence' ) . '</option>';
		foreach ( $tags as $tag ) {
			echo '<option value="' . esc_attr( absint( $tag['id'] ) ) . '">' . esc_html( $tag['name'] ) . '</option>';
		}
		echo '</select>';
		echo '</span>';

		echo '<span class="yoohw-cos-bulk-target" data-yoohw-cos-bulk-target="segment" hidden>';
		echo '<label class="screen-reader-text" for="yoohw-cos-bulk-segment-id">';
		echo esc_html__( 'Segment for bulk action', 'yoohw-customer-intelligence' );
		echo '</label>';
		echo '<select name="bulk_segment_id" id="yoohw-cos-bulk-segment-id">';
		echo '<option value="0">' . esc_html__( 'Segment for bulk action', 'yoohw-customer-intelligence' ) . '</option>';
		foreach ( $segments as $segment ) {
			echo '<option value="' . esc_attr( absint( $segment['id'] ) ) . '">' . esc_html( $segment['name'] ) . '</option>';
		}
		echo '</select>';
		echo '</span>';

		echo '<span class="yoohw-cos-bulk-target yoohw-cos-bulk-target--task" data-yoohw-cos-bulk-target="task" hidden>';

		echo '<label class="screen-reader-text" for="yoohw-cos-bulk-task-title">';
		echo esc_html__( 'Follow-up task title', 'yoohw-customer-intelligence' );
		echo '</label>';
		echo '<input type="text" name="bulk_task_title" id="yoohw-cos-bulk-task-title" class="regular-text" value="" placeholder="' . esc_attr__( 'Task title', 'yoohw-customer-intelligence' ) . '" data-yoohw-cos-reset-value="" />';

		echo '<label class="screen-reader-text" for="yoohw-cos-bulk-task-priority">';
		echo esc_html__( 'Task priority', 'yoohw-customer-intelligence' );
		echo '</label>';
		echo '<select name="bulk_task_priority" id="yoohw-cos-bulk-task-priority" data-yoohw-cos-reset-value="normal">';
		foreach ( YoOhw_COS_Tasks::get_priorities() as $priority => $label ) {
			echo '<option value="' . esc_attr( $priority ) . '" ' . selected( 'normal', $priority, false ) . '>';
			echo esc_html( $label );
			echo '</option>';
		}
		echo '</select>';

		echo '<label class="screen-reader-text" for="yoohw-cos-bulk-task-due-date">';
		echo esc_html__( 'Task due date', 'yoohw-customer-intelligence' );
		echo '</label>';
		echo '<input type="datetime-local" name="bulk_task_due_date" id="yoohw-cos-bulk-task-due-date" value="" data-yoohw-cos-reset-value="" />';

		echo '<label class="screen-reader-text" for="yoohw-cos-bulk-task-assignee">';
		echo esc_html__( 'Task assignee', 'yoohw-customer-intelligence' );
		echo '</label>';
		echo '<select name="bulk_task_assigned_user_id" id="yoohw-cos-bulk-task-assignee" data-yoohw-cos-reset-value="' . esc_attr( $default_assignee_id ) . '">';
		echo '<option value="0">' . esc_html__( 'Unassigned', 'yoohw-customer-intelligence' ) . '</option>';
		foreach ( $users as $user ) {
			echo '<option value="' . esc_attr( absint( $user->ID ) ) . '" ' . selected( $default_assignee_id, absint( $user->ID ), false ) . '>';
			echo esc_html( $user->display_name );
			echo '</option>';
		}
		echo '</select>';

		echo '</span>';

		echo '</div>';

		echo '<div class="alignleft actions">';

		if ( ! empty( $tags ) ) {
			$current_tag = isset( $_GET['customer_tag'] ) ? absint( wp_unslash( $_GET['customer_tag'] ) ) : 0;

			echo '<label class="screen-reader-text" for="yoohw-cos-customer-tag-filter">';
			echo esc_html__( 'Filter by tag', 'yoohw-customer-intelligence' );
			echo '</label>';

			echo '<select name="customer_tag" id="yoohw-cos-customer-tag-filter">';
			echo '<option value="0">' . esc_html__( 'All tags', 'yoohw-customer-intelligence' ) . '</option>';

			foreach ( $tags as $tag ) {
				echo '<option value="' . esc_attr( absint( $tag['id'] ) ) . '" ' . selected( $current_tag, absint( $tag['id'] ), false ) . '>';
				echo esc_html( $tag['name'] );
				echo '</option>';
			}

			echo '</select>';
		}

		if ( ! empty( $segments ) ) {
			$current_segment = isset( $_GET['customer_segment'] ) ? absint( wp_unslash( $_GET['customer_segment'] ) ) : 0;

			echo '<select name="customer_segment" id="yoohw-cos-customer-segment-filter">';
			echo '<option value="0">' . esc_html__( 'All segments', 'yoohw-customer-intelligence' ) . '</option>';

			foreach ( $segments as $segment ) {
				echo '<option value="' . esc_attr( absint( $segment['id'] ) ) . '" ' . selected( $current_segment, absint( $segment['id'] ), false ) . '>';
				echo esc_html( $segment['name'] );
				echo '</option>';
			}

			echo '</select>';
		}

		$current_vip = isset( $_GET['vip_status'] ) ? sanitize_key( wp_unslash( $_GET['vip_status'] ) ) : '';

		$vip_statuses = array_merge(
			array(
				''           => __( 'All value tiers', 'yoohw-customer-intelligence' ),
				'high_value' => __( 'Any high-value tier', 'yoohw-customer-intelligence' ),
			),
			YoOhw_COS_Intelligence::get_value_tier_labels()
		);

		echo '<select name="vip_status">';
		foreach ( $vip_statuses as $vip_key => $vip_label ) {
			echo '<option value="' . esc_attr( $vip_key ) . '" ' . selected( $current_vip, $vip_key, false ) . '>';
			echo esc_html( $vip_label );
			echo '</option>';
		}
		echo '</select>';

		$current_risk = isset( $_GET['risk_level'] ) ? sanitize_key( wp_unslash( $_GET['risk_level'] ) ) : '';

		$risk_levels = array(
			''       => __( 'All risk levels', 'yoohw-customer-intelligence' ),
			'none'   => __( 'No risk', 'yoohw-customer-intelligence' ),
			'low'    => __( 'Low risk', 'yoohw-customer-intelligence' ),
			'medium' => __( 'Medium risk', 'yoohw-customer-intelligence' ),
			'high'   => __( 'High risk', 'yoohw-customer-intelligence' ),
		);

		echo '<select name="risk_level">';
		foreach ( $risk_levels as $risk_key => $risk_label ) {
			echo '<option value="' . esc_attr( $risk_key ) . '" ' . selected( $current_risk, $risk_key, false ) . '>';
			echo esc_html( $risk_label );
			echo '</option>';
		}
		echo '</select>';

		if ( self::is_loyalty_integration_active() ) {
			$current_loyalty_level = isset( $_GET['loyalty_level'] ) ? sanitize_key( wp_unslash( $_GET['loyalty_level'] ) ) : '';
			$loyalty_levels        = $this->get_loyalty_level_filter_options( $current_loyalty_level );

			echo '<select name="loyalty_level">';
			foreach ( $loyalty_levels as $level_key => $level_label ) {
				echo '<option value="' . esc_attr( $level_key ) . '" ' . selected( $current_loyalty_level, $level_key, false ) . '>';
				echo esc_html( $level_label );
				echo '</option>';
			}
			echo '</select>';
		}

		$current_lifecycle = isset( $_GET['lifecycle_stage'] ) ? sanitize_key( wp_unslash( $_GET['lifecycle_stage'] ) ) : '';

		$lifecycle_options = array(
			''        => __( 'All lifecycle stages', 'yoohw-customer-intelligence' ),
			'new'     => __( 'New', 'yoohw-customer-intelligence' ),
			'repeat'  => __( 'Repeat', 'yoohw-customer-intelligence' ),
			'loyal'   => __( 'Loyal', 'yoohw-customer-intelligence' ),
			'vip'     => __( 'VIP', 'yoohw-customer-intelligence' ),
			'dormant' => __( 'Dormant', 'yoohw-customer-intelligence' ),
		);

		echo '<select name="lifecycle_stage">';
		foreach ( $lifecycle_options as $stage_key => $stage_label ) {
			echo '<option value="' . esc_attr( $stage_key ) . '" ' . selected( $current_lifecycle, $stage_key, false ) . '>';
			echo esc_html( $stage_label );
			echo '</option>';
		}
		echo '</select>';

		$current_cohort = isset( $_GET['customer_cohort'] ) ? sanitize_key( wp_unslash( $_GET['customer_cohort'] ) ) : '';
		$cohort_options = array(
			''           => __( 'All purchase cohorts', 'yoohw-customer-intelligence' ),
			'first_time' => __( 'First-time customers', 'yoohw-customer-intelligence' ),
			'repeat'     => __( 'Repeat customers', 'yoohw-customer-intelligence' ),
		);

		echo '<select name="customer_cohort">';
		foreach ( $cohort_options as $cohort_key => $cohort_label ) {
			echo '<option value="' . esc_attr( $cohort_key ) . '" ' . selected( $current_cohort, $cohort_key, false ) . '>';
			echo esc_html( $cohort_label );
			echo '</option>';
		}
		echo '</select>';

		$current_attention = isset( $_GET['customer_attention'] ) ? sanitize_key( wp_unslash( $_GET['customer_attention'] ) ) : '';
		$attention_options = array(
			''                     => __( 'All attention states', 'yoohw-customer-intelligence' ),
			'high_value_retention' => __( 'High-value retention risk', 'yoohw-customer-intelligence' ),
			'missing_contact'      => __( 'Missing contact details', 'yoohw-customer-intelligence' ),
		);

		echo '<select name="customer_attention">';
		foreach ( $attention_options as $attention_key => $attention_label ) {
			echo '<option value="' . esc_attr( $attention_key ) . '" ' . selected( $current_attention, $attention_key, false ) . '>';
			echo esc_html( $attention_label );
			echo '</option>';
		}
		echo '</select>';

		submit_button(
			__( 'Filter', 'yoohw-customer-intelligence' ),
			'secondary',
			'filter_action',
			false
		);

		echo ' <button type="submit" class="button" name="yoohw_cos_export_customers" value="1">';
		echo esc_html__( 'Export CSV', 'yoohw-customer-intelligence' );
		echo '</button>';

		echo '</div>';
	}

	private function prime_customer_relationships(): void {
		global $wpdb;

		$this->tags_by_customer     = array();
		$this->segments_by_customer = array();
		$this->relationships_primed = true;

		if ( empty( $this->items ) || ! is_array( $this->items ) ) {
			return;
		}

		$customer_ids = array();

		foreach ( $this->items as $item ) {
			if ( ! empty( $item['id'] ) ) {
				$customer_ids[] = absint( $item['id'] );
			}
		}

		$customer_ids = array_values( array_unique( array_filter( $customer_ids ) ) );

		if ( empty( $customer_ids ) ) {
			return;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $customer_ids ), '%d' ) );

		$tags_table          = YoOhw_COS_DB::tags_table();
		$customer_tags_table = YoOhw_COS_DB::customer_tags_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- IN placeholders are generated from absint customer IDs.
		$tag_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ct.customer_id, t.*
				FROM %i t
				INNER JOIN %i ct ON ct.tag_id = t.id
				WHERE ct.customer_id IN ({$placeholders})
				ORDER BY t.name ASC",
				...array_merge( array( $tags_table, $customer_tags_table ), $customer_ids )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		if ( is_array( $tag_rows ) ) {
			foreach ( $tag_rows as $row ) {
				$customer_id = absint( $row['customer_id'] ?? 0 );
				unset( $row['customer_id'] );

				if ( $customer_id > 0 ) {
					$this->tags_by_customer[ $customer_id ][] = $row;
				}
			}
		}

		$segments_table          = YoOhw_COS_DB::segments_table();
		$customer_segments_table = YoOhw_COS_DB::customer_segments_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- IN placeholders are generated from absint customer IDs.
		$segment_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT cs.customer_id, s.*
				FROM %i s
				INNER JOIN %i cs ON cs.segment_id = s.id
				WHERE cs.customer_id IN ({$placeholders})
				ORDER BY s.name ASC",
				...array_merge( array( $segments_table, $customer_segments_table ), $customer_ids )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		if ( is_array( $segment_rows ) ) {
			foreach ( $segment_rows as $row ) {
				$customer_id = absint( $row['customer_id'] ?? 0 );
				unset( $row['customer_id'] );

				if ( $customer_id > 0 ) {
					$this->segments_by_customer[ $customer_id ][] = $row;
				}
			}
		}
	}

	public function column_tags( array $item ): string {
		$customer_id = absint( $item['id'] );
		$tags        = $this->relationships_primed
			? ( $this->tags_by_customer[ $customer_id ] ?? array() )
			: YoOhw_COS_Tags::get_customer_tags( $customer_id );

		if ( empty( $tags ) ) {
			return '&mdash;';
		}

		$output = '';

		foreach ( $tags as $tag ) {
			$color = ! empty( $tag['color'] ) ? sanitize_hex_color( $tag['color'] ) : '#f0f0f1';

			$output .= '<span class="yoohw-cos-chip" style="' . esc_attr( YoOhw_COS_Admin_UI::get_readable_chip_style( $color ) ) . '">';
			$output .= esc_html( $tag['name'] );
			$output .= '</span>';
		}

		return $output;
	}

	private function format_status_badge( string $status ): string {
		$labels = array(
			'new'      => __( 'New', 'yoohw-customer-intelligence' ),
			'active'   => __( 'Active', 'yoohw-customer-intelligence' ),
			'at_risk'  => __( 'At risk', 'yoohw-customer-intelligence' ),
			'inactive' => __( 'Inactive', 'yoohw-customer-intelligence' ),
			'vip'      => __( 'VIP', 'yoohw-customer-intelligence' ),
		);

		$label = $labels[ $status ] ?? ucfirst( str_replace( '_', ' ', $status ) );

		return '<span class="yoohw-cos-badge yoohw-cos-badge--status-' . esc_attr( sanitize_html_class( $status ) ) . '">' . esc_html( $label ) . '</span>';
	}

	private function format_vip_badge( string $vip_status ): string {
		$label = YoOhw_COS_Intelligence::get_value_tier_label( $vip_status );
		$class = YoOhw_COS_Intelligence::get_value_tier_badge_class( $vip_status );

		return '<span class="yoohw-cos-badge yoohw-cos-badge--value-tier-' . esc_attr( $class ) . '">' . esc_html( $label ) . '</span>';
	}

	private function format_risk_badge( float $risk_score ): string {
		$level = YoOhw_COS_Intelligence::calculate_risk_level( $risk_score );

		$labels = array(
			'none'   => __( 'No risk', 'yoohw-customer-intelligence' ),
			'low'    => __( 'Low', 'yoohw-customer-intelligence' ),
			'medium' => __( 'Medium', 'yoohw-customer-intelligence' ),
			'high'   => __( 'High', 'yoohw-customer-intelligence' ),
		);

		return '<span class="yoohw-cos-badge yoohw-cos-badge--risk-' . esc_attr( sanitize_html_class( $level ) ) . '">' . esc_html( $labels[ $level ] ?? $level ) . ' · ' . esc_html( number_format_i18n( $risk_score, 0 ) ) . '</span>';
	}

	private function format_loyalty_level_badge( array $item ): string {
		$level = $this->get_loyalty_level_for_display( $item );

		if ( '' === $level ) {
			return '&mdash;';
		}

		$style      = $this->get_loyalty_level_badge_style( $level );
		$style_attr = '' !== $style ? ' style="' . esc_attr( $style ) . '"' : '';

		return '<span class="yoohw-cos-badge yoohw-cos-badge--loyalty-level yoohw-cos-badge--loyalty-level-' . esc_attr( sanitize_html_class( $level ) ) . '"' . $style_attr . '>' . esc_html( $this->format_loyalty_level_label( $level ) ) . '</span>';
	}

	private function get_loyalty_level_badge_style( string $level ): string {
		if (
			class_exists( 'YoOhw_COS_Loyalty_Integration' )
			&& is_callable( array( 'YoOhw_COS_Loyalty_Integration', 'is_loyalty_plugin_active' ) )
			&& YoOhw_COS_Loyalty_Integration::is_loyalty_plugin_active()
			&& is_callable( array( 'YoOhw_COS_Loyalty_Integration', 'get_loyalty_level_badge_style' ) )
		) {
			return YoOhw_COS_Loyalty_Integration::get_loyalty_level_badge_style( $level );
		}

		return '';
	}

	private function get_loyalty_level_for_display( array $item ): string {
		if ( ! self::is_loyalty_integration_active() ) {
			return '';
		}

		$level   = sanitize_key( (string) ( $item['loyalty_level'] ?? '' ) );
		$user_id = absint( $item['wp_user_id'] ?? 0 );

		if (
			$user_id > 0
			&& class_exists( 'YoOhw_COS_Loyalty_Integration' )
			&& is_callable( array( 'YoOhw_COS_Loyalty_Integration', 'get_user_loyalty_customer_data' ) )
		) {
			$loyalty_data = YoOhw_COS_Loyalty_Integration::get_user_loyalty_customer_data( $user_id );
			$live_level   = sanitize_key( (string) ( $loyalty_data['loyalty_level'] ?? '' ) );

			if ( '' !== $live_level ) {
				$level = $live_level;
			}
		}

		return $level;
	}

	private function get_loyalty_level_filter_options( string $current_level = '' ): array {
		global $wpdb;

		if ( ! self::is_loyalty_integration_active() ) {
			return array();
		}

		$options = array(
			''     => __( 'All loyalty levels', 'yoohw-customer-intelligence' ),
			'none' => __( 'No loyalty level', 'yoohw-customer-intelligence' ),
		);
		$levels  = array();

		$levels = array_merge( $levels, YoOhw_COS_Integrations::loyalty_roles() );

		$stored_levels = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT loyalty_level
				FROM %i
				WHERE loyalty_level <> ''
				ORDER BY loyalty_level ASC",
				YoOhw_COS_DB::customers_table()
			)
		);
		$levels = array_merge( $levels, (array) $stored_levels );

		if ( '' !== $current_level && 'none' !== $current_level ) {
			$levels[] = $current_level;
		}

		foreach ( array_values( array_unique( array_filter( array_map( 'sanitize_key', $levels ) ) ) ) as $level ) {
			$options[ $level ] = $this->format_loyalty_level_label( $level );
		}

		return $options;
	}

	private function format_loyalty_level_label( string $level ): string {
		$level = sanitize_key( $level );

		if ( class_exists( 'YoOhw_COS_Loyalty_Integration' ) && is_callable( array( 'YoOhw_COS_Loyalty_Integration', 'format_role_label' ) ) ) {
			return YoOhw_COS_Loyalty_Integration::format_role_label( $level );
		}

		if ( '' === $level || 'none' === $level ) {
			return __( 'No loyalty level', 'yoohw-customer-intelligence' );
		}

		if ( function_exists( 'wp_roles' ) ) {
			$wp_roles = wp_roles();

			if ( isset( $wp_roles->roles[ $level ]['name'] ) ) {
				return translate_user_role( $wp_roles->roles[ $level ]['name'] );
			}
		}

		return ucwords( str_replace( array( '_', '-' ), ' ', $level ) );
	}

	private static function is_loyalty_integration_active(): bool {
		return YoOhw_COS_Integrations::loyalty_active();
	}

	private static function insert_column_before( array $columns, string $before_key, string $new_key, string $new_label ): array {
		$ordered = array();

		foreach ( $columns as $key => $label ) {
			if ( $before_key === $key ) {
				$ordered[ $new_key ] = $new_label;
			}

			$ordered[ $key ] = $label;
		}

		if ( ! isset( $ordered[ $new_key ] ) ) {
			$ordered[ $new_key ] = $new_label;
		}

		return $ordered;
	}

	private function format_lifecycle_badge( string $stage ): string {
		$labels = array(
			'new'     => __( 'New', 'yoohw-customer-intelligence' ),
			'repeat'  => __( 'Repeat', 'yoohw-customer-intelligence' ),
			'loyal'   => __( 'Loyal', 'yoohw-customer-intelligence' ),
			'vip'     => __( 'VIP', 'yoohw-customer-intelligence' ),
			'dormant' => __( 'Dormant', 'yoohw-customer-intelligence' ),
		);

		$label = $labels[ $stage ] ?? ucfirst( str_replace( '_', ' ', $stage ) );

		return '<span class="yoohw-cos-badge yoohw-cos-badge--lifecycle-' . esc_attr( sanitize_html_class( $stage ) ) . '">' . esc_html( $label ) . '</span>';
	}

	public function column_segments( array $item ): string {
		$customer_id = absint( $item['id'] );
		$segments    = $this->relationships_primed
			? ( $this->segments_by_customer[ $customer_id ] ?? array() )
			: YoOhw_COS_Segments::get_customer_segments( $customer_id );

		if ( empty( $segments ) ) {
			return '&mdash;';
		}

		$output = '';

		foreach ( $segments as $segment ) {
			$output .= '<span class="yoohw-cos-chip yoohw-cos-chip--segment">';

			$output .= esc_html( $segment['name'] );
			$output .= '</span>';
		}

		return $output;
	}
}
