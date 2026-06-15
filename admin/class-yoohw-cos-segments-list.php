<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class YoOhw_COS_Segments_List extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'yoohw_cos_segment',
				'plural'   => 'yoohw_cos_segments',
				'ajax'     => false,
			)
		);
	}

	public function get_columns(): array {
		return array(
			'cb'            => '<input type="checkbox" />',
			'name'          => __( 'Segment', 'yoohw-customer-intelligence' ),
			'segment_type'  => __( 'Type', 'yoohw-customer-intelligence' ),
			'customer_count' => __( 'Customers', 'yoohw-customer-intelligence' ),
			'description'   => __( 'Description', 'yoohw-customer-intelligence' ),
			'created_at'    => __( 'Created', 'yoohw-customer-intelligence' ),
		);
	}

	public function column_cb( $item ): string {
		return '<input type="checkbox" name="segment_ids[]" value="' . esc_attr( absint( $item['id'] ) ) . '" />';
	}

	protected function get_bulk_actions(): array {
		return array(
			'delete' => __( 'Delete', 'yoohw-customer-intelligence' ),
		);
	}

	public function prepare_items(): void {
		global $wpdb;

		$segments_table          = YoOhw_COS_DB::segments_table();
		$customer_segments_table = YoOhw_COS_DB::customer_segments_table();

		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * $per_page;
		$search       = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';

		$where  = 'WHERE 1=1';
		$params = array();

		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';

			$where .= ' AND (s.name LIKE %s OR s.slug LIKE %s OR s.description LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Search SQL fragments are hardcoded; values are passed through placeholders.
		$total_items = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i s {$where}",
				...array_merge( array( $segments_table ), $params )
			)
		);

		$this->items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*,
					COUNT(cs.customer_id) AS customer_count
				FROM %i s
				LEFT JOIN %i cs ON cs.segment_id = s.id
				{$where}
				GROUP BY s.id
				ORDER BY s.name ASC
				LIMIT %d OFFSET %d",
				...array_merge( array( $segments_table, $customer_segments_table ), $params, array( $per_page, $offset ) )
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

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'segment_type':
				return esc_html( ucfirst( $item['segment_type'] ?? 'static' ) );

			case 'customer_count':
				return esc_html( number_format_i18n( absint( $item['customer_count'] ?? 0 ) ) );

			case 'description':
				return ! empty( $item['description'] ) ? esc_html( $item['description'] ) : '&mdash;';

			case 'created_at':
				return $this->format_date( $item['created_at'] ?? '' );

			default:
				return '';
		}
	}

	public function column_name( array $item ): string {
		$name = $item['name'] ?? '';

		$url = add_query_arg(
			array(
				'page'             => 'yoohw-customer-intelligence',
				'customer_segment' => absint( $item['id'] ),
			),
			admin_url( 'admin.php' )
		);

		$edit_url = add_query_arg(
			array(
				'page'         => 'yoohw-customer-intelligence-segments',
				'edit_segment' => absint( $item['id'] ),
			),
			admin_url( 'admin.php' )
		);

		$output  = '<strong><a class="row-title" href="' . esc_url( $edit_url ) . '">' . esc_html( $name ) . '</a></strong>';
		$output .= '<div class="row-actions">';
		$output .= '<span><a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'yoohw-customer-intelligence' ) . '</a></span>';
		$output .= ' | ';
		$output .= '<span><a href="' . esc_url( $url ) . '">' . esc_html__( 'View customers', 'yoohw-customer-intelligence' ) . '</a></span>';

		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'     => 'yoohw_cos_delete_segment',
					'segment_id' => absint( $item['id'] ),
				),
				admin_url( 'admin-post.php' )
			),
			'yoohw_cos_delete_segment'
		);

		$output .= ' | ';
		$output .= '<span class="delete"><a class="submitdelete" href="' . esc_url( $delete_url ) . '" data-yoohw-cos-confirm="' . esc_attr__( 'Delete this segment?', 'yoohw-customer-intelligence' ) . '">';
		$output .= esc_html__( 'Delete', 'yoohw-customer-intelligence' );
		$output .= '</a></span>';
		$output .= '</div>';

		return $output;
	}

	public function no_items(): void {
		if ( YoOhw_COS_Admin_UI::has_request_filters( array( 's' ) ) ) {
			YoOhw_COS_Admin_UI::render_empty_state(
				__( 'No segments match your search.', 'yoohw-customer-intelligence' ),
				__( 'Clear the search or create a new segment.', 'yoohw-customer-intelligence' )
			);
			return;
		}

		YoOhw_COS_Admin_UI::render_empty_state(
			__( 'No segments yet.', 'yoohw-customer-intelligence' ),
			__( 'Create static segments to group customers for filters and operations.', 'yoohw-customer-intelligence' )
		);
	}

	private function format_date( ?string $date ): string {
		return YoOhw_COS_DB::format_admin_date( $date, '&mdash;' );
	}
}
