<?php
defined( 'ABSPATH' ) || exit;

final class YoOhw_COS_Admin_Menu {

	private const DASHBOARD_TASKS_WIDGET_ID = 'yoohw_cos_dashboard_followup_tasks';
	private const MENU_SLUG = 'yoohw-customer-intelligence-overview';
	private const EMAIL_SETTINGS_SLUG = 'yoohw-customer-intelligence-email-settings';
	private const CRM_EMAIL_GROUP = 'crm';
	private const WOOCOMMERCE_MENU_SLUG = 'woocommerce';
	private const PRODUCTS_MENU_SLUG = 'edit.php?post_type=product';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_menu', array( __CLASS__, 'move_menu_before_products' ), 999 );
		add_filter( 'menu_order', array( __CLASS__, 'order_menu_before_products' ), 2000 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register_dashboard_widgets' ), 100 );
		add_action( 'wp_ajax_yoohw_cos_json_search_assignable_users', array( __CLASS__, 'handle_assignable_user_search' ) );
		add_filter( 'get_user_option_meta-box-order_dashboard', array( __CLASS__, 'prioritize_dashboard_tasks_widget_order' ) );
	}

	public static function enqueue_admin_assets( string $hook ): void {
		$screen_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		$plugin_pages = array(
			'yoohw-customer-intelligence-overview',
			'yoohw-customer-intelligence',
			'yoohw-customer-intelligence-tasks',
			'yoohw-customer-intelligence-tags',
			'yoohw-customer-intelligence-segments',
			'yoohw-customer-intelligence-activity',
			'yoohw-customer-intelligence-settings',
		);

		$is_plugin_page = in_array( $screen_page, $plugin_pages, true );
		$is_dashboard   = 'index.php' === $hook;

		if ( ! $is_plugin_page && ! $is_dashboard ) {
			return;
		}

		$admin_css_path = YOOHW_COS_PATH . 'assets/css/admin.css';
		$admin_js_path  = YOOHW_COS_PATH . 'assets/js/admin.js';
		$admin_css_ver  = file_exists( $admin_css_path ) ? (string) filemtime( $admin_css_path ) : YOOHW_COS_VERSION;
		$admin_js_ver   = file_exists( $admin_js_path ) ? (string) filemtime( $admin_js_path ) : YOOHW_COS_VERSION;

		if ( $is_plugin_page ) {
			self::enqueue_select2_styles();
		}

		wp_enqueue_style(
			'yoohw-cos-admin',
			YOOHW_COS_URL . 'assets/css/admin.css',
			array(),
			$admin_css_ver
		);

		if ( ! $is_plugin_page ) {
			return;
		}

		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_script(
			'yoohw-cos-admin',
			YOOHW_COS_URL . 'assets/js/admin.js',
			array( 'jquery', 'jquery-ui-autocomplete', 'wc-enhanced-select' ),
			$admin_js_ver,
			true
		);

		wp_localize_script(
			'yoohw-cos-admin',
			'yoohwCosAdmin',
			array(
				'ajaxUrl'                   => admin_url( 'admin-ajax.php' ),
				'assigneeNoResultsText'     => __( 'No assignable users found', 'yoohw-customer-intelligence' ),
				'assigneePlaceholderText'   => __( 'Search assignee', 'yoohw-customer-intelligence' ),
				'assigneeSearchNonce'       => wp_create_nonce( 'yoohw_cos_search_assignable_users' ),
				'copiedText'                => __( 'Copied!', 'yoohw-customer-intelligence' ),
				'customerNoResultsText'     => __( 'No customer profiles found', 'yoohw-customer-intelligence' ),
				'customerPlaceholderText'   => __( 'Search customer profile', 'yoohw-customer-intelligence' ),
				'customerSearchNonce'       => wp_create_nonce( 'yoohw_cos_search_customers' ),
				'emailSendErrorText'        => __( 'The email could not be sent. Please try again.', 'yoohw-customer-intelligence' ),
				'syncErrorText'             => __( 'Sync could not be completed. Please try again.', 'yoohw-customer-intelligence' ),
				'syncNonce'                 => wp_create_nonce( 'yoohw_cos_sync_customers' ),
				'syncRunningText'           => __( 'Syncing orders...', 'yoohw-customer-intelligence' ),
				'syncCompleteText'          => __( 'Sync complete.', 'yoohw-customer-intelligence' ),
			)
		);
	}

	private static function enqueue_select2_styles(): void {
		if ( wp_style_is( 'select2', 'registered' ) ) {
			wp_enqueue_style( 'select2' );
			return;
		}

		if ( function_exists( 'WC' ) && WC() && method_exists( WC(), 'plugin_url' ) ) {
			wp_enqueue_style(
				'select2',
				WC()->plugin_url() . '/assets/css/select2.css',
				array(),
				defined( 'WC_VERSION' ) ? WC_VERSION : YOOHW_COS_VERSION
			);
		}
	}

	public static function handle_assignable_user_search(): void {
		check_ajax_referer( 'yoohw_cos_search_assignable_users', 'security' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json( array() );
		}

		$term    = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
		$results = array();

		if ( '' === $term || false !== stripos( __( 'Unassigned', 'yoohw-customer-intelligence' ), $term ) ) {
			$results[0] = __( 'Unassigned', 'yoohw-customer-intelligence' );
		}

		$args = array(
			'fields'         => array( 'ID', 'display_name', 'user_email' ),
			'orderby'        => 'display_name',
			'order'          => 'ASC',
			'number'         => 20,
			'role__in'       => YoOhw_COS_Tasks::get_assignable_roles(),
			'search_columns' => array( 'user_login', 'user_nicename', 'user_email', 'display_name' ),
		);

		if ( '' !== $term ) {
			$args['search'] = '*' . $term . '*';
		}

		$users = get_users( $args );

		foreach ( is_array( $users ) ? $users : array() as $user ) {
			$user_id = absint( $user->ID ?? 0 );

			if ( $user_id <= 0 ) {
				continue;
			}

			$label = sanitize_text_field( (string) ( $user->display_name ?? '' ) );
			$email = sanitize_email( (string) ( $user->user_email ?? '' ) );

			if ( '' === $label ) {
				$label = $email ?: sprintf(
					/* translators: %d: user ID. */
					__( 'User #%d', 'yoohw-customer-intelligence' ),
					$user_id
				);
			}

			if ( '' !== $email && false === stripos( $label, $email ) ) {
				$label = sprintf(
					/* translators: 1: user display name, 2: user email. */
					__( '%1$s (%2$s)', 'yoohw-customer-intelligence' ),
					$label,
					$email
				);
			}

			$results[ $user_id ] = $label;
		}

		wp_send_json( $results );
	}

	public static function register_dashboard_widgets(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			self::DASHBOARD_TASKS_WIDGET_ID,
			__( 'Follow-up tasks', 'yoohw-customer-intelligence' ),
			array( __CLASS__, 'render_dashboard_tasks_widget' ),
			null,
			null,
			'normal',
			'high'
		);

		self::move_dashboard_tasks_widget_to_top();
	}

	public static function prioritize_dashboard_tasks_widget_order( $order ) {
		if ( ! is_array( $order ) ) {
			return $order;
		}

		foreach ( $order as $context => $ids ) {
			if ( ! is_string( $ids ) || '' === $ids ) {
				continue;
			}

			$ordered_ids = array_values(
				array_filter(
					array_map( 'trim', explode( ',', $ids ) ),
					static function( string $id ): bool {
						return self::DASHBOARD_TASKS_WIDGET_ID !== $id;
					}
				)
			);

			$order[ $context ] = implode( ',', $ordered_ids );
		}

		return $order;
	}

	private static function move_dashboard_tasks_widget_to_top(): void {
		global $wp_meta_boxes;

		$screen = get_current_screen();

		if ( ! $screen || empty( $screen->id ) ) {
			return;
		}

		$screen_id = $screen->id;
		$widget_id = self::DASHBOARD_TASKS_WIDGET_ID;

		if ( empty( $wp_meta_boxes[ $screen_id ]['normal']['high'][ $widget_id ] ) ) {
			return;
		}

		$widget = $wp_meta_boxes[ $screen_id ]['normal']['high'][ $widget_id ];
		unset( $wp_meta_boxes[ $screen_id ]['normal']['high'][ $widget_id ] );

		$wp_meta_boxes[ $screen_id ]['normal']['high'] = array( $widget_id => $widget ) + $wp_meta_boxes[ $screen_id ]['normal']['high'];
	}

	public static function render_dashboard_tasks_widget(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$user_id     = get_current_user_id();
		$tasks       = YoOhw_COS_Tasks::get_assigned_tasks(
			$user_id,
			array(
				'limit'  => 5,
				'status' => 'open',
			)
		);
		$task_counts = YoOhw_COS_Tasks::get_counts();
		$total       = absint( $task_counts['assigned_to_me'] ?? 0 );
		$list_url    = add_query_arg(
			array(
				'page'      => 'yoohw-customer-intelligence-tasks',
				'task_view' => 'assigned_to_me',
			),
			admin_url( 'admin.php' )
		);

		echo '<div class="yoohw-cos-admin yoohw-cos-dashboard-widget">';

		if ( empty( $tasks ) ) {
			YoOhw_COS_Admin_UI::render_empty_state(
				__( 'No follow-up tasks assigned to you.', 'yoohw-customer-intelligence' ),
				__( 'Open customer follow-ups assigned to your account will appear here.', 'yoohw-customer-intelligence' ),
				array(
					array(
						'label' => __( 'Open tasks', 'yoohw-customer-intelligence' ),
						'url'   => admin_url( 'admin.php?page=yoohw-customer-intelligence-tasks' ),
						'class' => 'button',
					),
				),
				'compact'
			);
			echo '</div>';
			return;
		}

		echo '<div class="yoohw-cos-dashboard-task-list">';

		foreach ( $tasks as $task ) {
			self::render_dashboard_task_item( $task );
		}

		echo '</div>';
		echo '<p class="yoohw-cos-dashboard-task-footer">';

		if ( $total > count( $tasks ) ) {
			printf(
				/* translators: 1: visible task count, 2: total task count. */
				esc_html__( 'Showing %1$s of %2$s assigned open tasks.', 'yoohw-customer-intelligence' ),
				esc_html( number_format_i18n( count( $tasks ) ) ),
				esc_html( number_format_i18n( $total ) )
			);
			echo ' ';
		}

		echo '<a href="' . esc_url( $list_url ) . '">' . esc_html__( 'View all assigned tasks', 'yoohw-customer-intelligence' ) . '</a>';
		echo '</p>';
		echo '</div>';
	}

	private static function render_dashboard_task_item( array $task ): void {
		$task_id     = absint( $task['id'] ?? 0 );
		$customer_id = absint( $task['customer_id'] ?? 0 );
		$title       = sanitize_text_field( (string) ( $task['title'] ?? '' ) );
		$customer    = sanitize_text_field( (string) ( $task['customer_name'] ?? '' ) );
		$email       = sanitize_email( (string) ( $task['customer_email'] ?? '' ) );
		$priority    = YoOhw_COS_Tasks::normalize_priority( (string) ( $task['priority'] ?? 'normal' ) );
		$due_date    = YoOhw_COS_DB::format_admin_date( $task['due_date'] ?? '', __( 'No due date', 'yoohw-customer-intelligence' ) );
		$is_overdue  = YoOhw_COS_Tasks::STATUS_COMPLETED !== (string) ( $task['status'] ?? '' )
			&& YoOhw_COS_DB::date_timestamp( $task['due_date'] ?? '' ) > 0
			&& YoOhw_COS_DB::date_timestamp( $task['due_date'] ?? '' ) < current_time( 'timestamp' );

		if ( '' === $title ) {
			$title = sprintf(
				/* translators: %d: task ID. */
				__( 'Task #%d', 'yoohw-customer-intelligence' ),
				$task_id
			);
		}

		if ( '' === $customer ) {
			$customer = $email ?: __( '(No customer name)', 'yoohw-customer-intelligence' );
		}

		$task_url = add_query_arg(
			array(
				'page'    => 'yoohw-customer-intelligence-tasks',
				'task_id' => $task_id,
			),
			admin_url( 'admin.php' )
		);

		$customer_url = add_query_arg(
			array(
				'page'        => 'yoohw-customer-intelligence',
				'customer_id' => $customer_id,
			),
			admin_url( 'admin.php' )
		);

		$complete_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'    => 'yoohw_cos_complete_task',
					'task_id'   => $task_id,
					'_redirect' => rawurlencode( admin_url( 'index.php' ) ),
				),
				admin_url( 'admin-post.php' )
			),
			'yoohw_cos_complete_task'
		);

		echo '<div class="yoohw-cos-dashboard-task-row">';
		echo '<div class="yoohw-cos-dashboard-task-main">';
		echo '<strong class="yoohw-cos-dashboard-task-title"><a href="' . esc_url( $task_url ) . '">' . esc_html( $title ) . '</a></strong>';
		echo '<div class="yoohw-cos-dashboard-task-meta">';

		if ( $customer_id > 0 ) {
			echo '<a class="yoohw-cos-dashboard-task-customer" href="' . esc_url( $customer_url ) . '">' . esc_html( $customer ) . '</a>';
		} else {
			echo '<span class="yoohw-cos-dashboard-task-customer">' . esc_html( $customer ) . '</span>';
		}

		echo '<span class="yoohw-cos-badge yoohw-cos-badge--task-priority-' . esc_attr( sanitize_html_class( $priority ) ) . '">' . esc_html( YoOhw_COS_Tasks::get_priorities()[ $priority ] ?? __( 'Normal', 'yoohw-customer-intelligence' ) ) . '</span>';
		echo '<span class="yoohw-cos-dashboard-task-due ' . esc_attr( $is_overdue ? 'yoohw-cos-dashboard-task-overdue' : '' ) . '">' . wp_kses_post( $due_date ) . '</span>';
		echo '</div>';
		echo '</div>';
		echo '<div class="yoohw-cos-dashboard-task-actions"><a class="button button-small" href="' . esc_url( $complete_url ) . '">' . esc_html__( 'Complete', 'yoohw-customer-intelligence' ) . '</a></div>';
		echo '</div>';
	}

	public static function register_menu(): void {
		add_menu_page(
			__( 'YoOhw Customer Intelligence for WooCommerce', 'yoohw-customer-intelligence' ),
			__( 'Customers', 'yoohw-customer-intelligence' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			array( __CLASS__, 'render_overview_page' ),
			'dashicons-groups',
			55.6
		);

		add_submenu_page(
			'yoohw-customer-intelligence-overview',
			__( 'Overview', 'yoohw-customer-intelligence' ),
			__( 'Overview', 'yoohw-customer-intelligence' ),
			'manage_woocommerce',
			'yoohw-customer-intelligence-overview',
			array( __CLASS__, 'render_overview_page' )
		);

		add_submenu_page(
			'yoohw-customer-intelligence-overview',
			__( 'Customers', 'yoohw-customer-intelligence' ),
			__( 'Customers', 'yoohw-customer-intelligence' ),
			'manage_woocommerce',
			'yoohw-customer-intelligence',
			array( __CLASS__, 'render_customers_page' )
		);

		add_submenu_page(
			'yoohw-customer-intelligence-overview',
			__( 'Tasks', 'yoohw-customer-intelligence' ),
			self::get_tasks_menu_title(),
			'manage_woocommerce',
			'yoohw-customer-intelligence-tasks',
			array( __CLASS__, 'render_tasks_page' )
		);

		add_submenu_page(
			'yoohw-customer-intelligence-overview',
			__( 'Tags', 'yoohw-customer-intelligence' ),
			__( 'Tags', 'yoohw-customer-intelligence' ),
			'manage_woocommerce',
			'yoohw-customer-intelligence-tags',
			array( __CLASS__, 'render_tags_page' )
		);

		add_submenu_page(
			'yoohw-customer-intelligence-overview',
			__( 'Segments', 'yoohw-customer-intelligence' ),
			__( 'Segments', 'yoohw-customer-intelligence' ),
			'manage_woocommerce',
			'yoohw-customer-intelligence-segments',
			array( __CLASS__, 'render_segments_page' )
		);

		add_submenu_page(
			'yoohw-customer-intelligence-overview',
			__( 'Activity', 'yoohw-customer-intelligence' ),
			__( 'Activity', 'yoohw-customer-intelligence' ),
			'manage_woocommerce',
			'yoohw-customer-intelligence-activity',
			array( __CLASS__, 'render_activity_page' )
		);

		add_submenu_page(
			'yoohw-customer-intelligence-overview',
			__( 'Emails', 'yoohw-customer-intelligence' ),
			__( 'Emails', 'yoohw-customer-intelligence' ),
			'manage_woocommerce',
			self::EMAIL_SETTINGS_SLUG,
			array( __CLASS__, 'redirect_to_email_settings_page' )
		);

		add_submenu_page(
			'yoohw-customer-intelligence-overview',
			__( 'Settings', 'yoohw-customer-intelligence' ),
			__( 'Settings', 'yoohw-customer-intelligence' ),
			'manage_woocommerce',
			'yoohw-customer-intelligence-settings',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	public static function redirect_to_email_settings_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'yoohw-customer-intelligence' ) );
		}

		wp_safe_redirect( self::get_crm_email_settings_url() );
		exit;
	}

	private static function get_crm_email_settings_url(): string {
		return add_query_arg(
			array(
				'page'        => 'wc-settings',
				'tab'         => 'email',
				'email_group' => self::CRM_EMAIL_GROUP,
			),
			admin_url( 'admin.php' )
		);
	}

	private static function get_tasks_menu_title(): string {
		$label = esc_html__( 'Tasks', 'yoohw-customer-intelligence' );

		if ( ! current_user_can( 'manage_woocommerce' ) || ! self::table_exists( YoOhw_COS_DB::tasks_table() ) ) {
			return $label;
		}

		$counts        = YoOhw_COS_Tasks::get_counts();
		$overdue_count = absint( $counts['overdue'] ?? 0 );

		if ( $overdue_count <= 0 ) {
			return $label;
		}

		$count_label = number_format_i18n( $overdue_count );
		$screen_text = sprintf(
			/* translators: %s: overdue task count. */
			_n( '%s overdue task', '%s overdue tasks', $overdue_count, 'yoohw-customer-intelligence' ),
			$count_label
		);

		return $label . sprintf(
			' <span class="menu-counter count-%1$d"><span class="processing-count" aria-hidden="true">%2$s</span><span class="screen-reader-text">%3$s</span></span>',
			$overdue_count,
			esc_html( $count_label ),
			esc_html( $screen_text )
		);
	}

	public static function move_menu_before_products(): void {
		global $menu;

		if ( ! is_array( $menu ) ) {
			return;
		}

		$customers_key   = self::find_top_level_menu_key( self::MENU_SLUG );
		$woocommerce_key = self::find_top_level_menu_key( self::WOOCOMMERCE_MENU_SLUG );
		$products_key    = self::find_top_level_menu_key( self::PRODUCTS_MENU_SLUG );

		if ( null === $customers_key || null === $products_key ) {
			return;
		}

		$customers_item = $menu[ $customers_key ];
		unset( $menu[ $customers_key ] );

		$menu_position = null === $woocommerce_key
			? self::get_menu_position_before( $products_key, $menu )
			: self::get_menu_position_between( $woocommerce_key, $products_key, $menu );

		$menu[ $menu_position ] = $customers_item;

		ksort( $menu );
	}

	public static function order_menu_before_products( array $menu_order ): array {
		$customers_index = array_search( self::MENU_SLUG, $menu_order, true );
		$products_index  = array_search( self::PRODUCTS_MENU_SLUG, $menu_order, true );

		if ( false === $customers_index || false === $products_index ) {
			return $menu_order;
		}

		unset( $menu_order[ $customers_index ] );
		$menu_order = array_values( $menu_order );

		$products_index    = array_search( self::PRODUCTS_MENU_SLUG, $menu_order, true );
		$woocommerce_index = array_search( self::WOOCOMMERCE_MENU_SLUG, $menu_order, true );

		if ( false === $products_index ) {
			return $menu_order;
		}

		$insert_index = $products_index;

		if ( false !== $woocommerce_index && $insert_index <= $woocommerce_index ) {
			$insert_index = $woocommerce_index + 1;
		}

		array_splice( $menu_order, $insert_index, 0, self::MENU_SLUG );

		return array_values( $menu_order );
	}

	private static function find_top_level_menu_key( string $menu_slug ): ?string {
		global $menu;

		if ( ! is_array( $menu ) ) {
			return null;
		}

		foreach ( $menu as $position => $item ) {
			if ( isset( $item[2] ) && $menu_slug === $item[2] ) {
				return (string) $position;
			}
		}

		return null;
	}

	private static function get_menu_position_between( string $after_position, string $before_position, array $current_menu ): string {
		$after  = (float) $after_position;
		$before = (float) $before_position;

		if ( $before <= $after ) {
			return self::get_menu_position_before( $before_position, $current_menu );
		}

		$position = $before - 0.001;

		if ( $position <= $after ) {
			$position = ( $after + $before ) / 2;
		}

		for ( $i = 0; $i < 100; $i++ ) {
			$formatted_position = self::format_menu_position( $position );

			if ( ! isset( $current_menu[ $formatted_position ] ) && $position > $after && $position < $before ) {
				return $formatted_position;
			}

			$position = ( $after + $position ) / 2;
		}

		return self::format_menu_position( ( $after + $before ) / 2 );
	}

	private static function get_menu_position_before( string $target_position, array $current_menu ): string {
		$position = (float) $target_position - 0.001;

		do {
			$formatted_position = self::format_menu_position( $position );

			if ( ! isset( $current_menu[ $formatted_position ] ) ) {
				return $formatted_position;
			}

			$position -= 0.001;
		} while ( true );
	}

	private static function format_menu_position( float $position ): string {
		return rtrim( rtrim( number_format( $position, 6, '.', '' ), '0' ), '.' );
	}

	private static function is_post_request(): bool {
		$request_method = isset( $_SERVER['REQUEST_METHOD'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) )
			: '';

		return 'POST' === strtoupper( $request_method );
	}

	public static function render_overview_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'yoohw-customer-intelligence' ) );
		}

		$summary            = YoOhw_COS_Overview::get_summary();
		$risk_counts        = YoOhw_COS_Customers::get_risk_counts();
		$lifecycle_counts   = YoOhw_COS_Customers::get_lifecycle_counts();
		$recent_activity    = self::get_recent_activity();
		$attention_counts   = YoOhw_COS_Overview::get_attention_counts();
		$priority_customers = YoOhw_COS_Overview::get_priority_customers();
		$priority_tasks     = YoOhw_COS_Overview::get_priority_tasks();
		$sync_state         = self::get_sync_state();
		$refresh_state      = get_option( 'yoohw_cos_activity_semantics_recalculation', array() );
		$refresh_state      = is_array( $refresh_state ) ? $refresh_state : array();
		$revenue            = self::format_overview_money( (float) ( $summary['total_spent'] ?? 0 ) );
		$average_order      = self::format_overview_money( (float) ( $summary['average_order_value'] ?? 0 ) );
		$customers_url      = self::get_overview_customers_url();

		echo '<div class="wrap yoohw-cos-admin yoohw-cos-overview-page">';
		echo '<div class="yoohw-cos-overview-header">';
		echo '<div>';
		echo '<h1>' . esc_html__( 'Overview', 'yoohw-customer-intelligence' ) . '</h1>';
		echo '<p>' . esc_html__( 'Customer performance, attention signals, and the next actions for your team.', 'yoohw-customer-intelligence' ) . '</p>';
		echo '</div>';
		echo '<div class="yoohw-cos-overview-header__actions">';
		echo '<span class="yoohw-cos-data-scope"><span class="dashicons dashicons-chart-area" aria-hidden="true"></span>' . esc_html__( 'Lifetime data', 'yoohw-customer-intelligence' ) . '</span>';
		echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=yoohw-customer-intelligence-settings#yoohw-cos-sync-center' ) ) . '">' . esc_html__( 'Open sync center', 'yoohw-customer-intelligence' ) . '</a>';
		echo '</div>';
		echo '</div>';

		self::render_overview_data_status( $sync_state, $refresh_state );
		self::render_overview_notices();

		if ( empty( $sync_state['last_run_at'] ) && empty( $summary['total_customers'] ) ) {
			echo '<div class="notice notice-warning inline yoohw-cos-overview-sync-notice"><p>';
			echo esc_html__( 'No synced customer data found yet. Sync existing orders to populate the dashboard.', 'yoohw-customer-intelligence' );
			echo ' <a href="' . esc_url( admin_url( 'admin.php?page=yoohw-customer-intelligence-settings#yoohw-cos-sync-center' ) ) . '">' . esc_html__( 'Open sync center', 'yoohw-customer-intelligence' ) . '</a>';
			echo '</p></div>';
		}

		echo '<div class="postbox yoohw-cos-overview-summary">';
		echo '<div class="postbox-header"><h2 class="hndle">' . esc_html__( 'Summary', 'yoohw-customer-intelligence' ) . '</h2></div>';
		echo '<div class="inside">';
		echo '<div class="yoohw-cos-stat-grid yoohw-cos-stat-grid--six yoohw-cos-overview-stats">';
		self::render_stat_card(
			__( 'Customers', 'yoohw-customer-intelligence' ),
			number_format_i18n( absint( $summary['total_customers'] ?? 0 ) ),
			$customers_url,
			__( 'Active profiles', 'yoohw-customer-intelligence' )
		);
		self::render_stat_card(
			__( 'Orders', 'yoohw-customer-intelligence' ),
			number_format_i18n( absint( $summary['total_orders'] ?? 0 ) ),
			self::get_overview_customers_url( array( 'orderby' => 'total_orders', 'order' => 'DESC' ) ),
			__( 'Lifetime orders', 'yoohw-customer-intelligence' )
		);
		self::render_stat_card(
			__( 'Revenue', 'yoohw-customer-intelligence' ),
			$revenue,
			self::get_overview_customers_url( array( 'orderby' => 'total_spent', 'order' => 'DESC' ) ),
			__( 'Lifetime revenue', 'yoohw-customer-intelligence' )
		);
		self::render_stat_card(
			__( 'Average order value', 'yoohw-customer-intelligence' ),
			$average_order,
			self::get_overview_customers_url( array( 'orderby' => 'average_order_value', 'order' => 'DESC' ) ),
			__( 'Across synced orders', 'yoohw-customer-intelligence' )
		);
		self::render_stat_card(
			__( 'Repeat customer rate', 'yoohw-customer-intelligence' ),
			number_format_i18n( (float) ( $summary['repeat_rate'] ?? 0 ), 1 ) . '%',
			self::get_overview_customers_url( array( 'customer_cohort' => 'repeat' ) ),
			sprintf(
				/* translators: %s: repeat customer count. */
				__( '%s repeat customers', 'yoohw-customer-intelligence' ),
				number_format_i18n( absint( $summary['repeat_customers'] ?? 0 ) )
			)
		);
		self::render_stat_card(
			__( 'High-value customers', 'yoohw-customer-intelligence' ),
			number_format_i18n( absint( $summary['high_value_customers'] ?? 0 ) ),
			self::get_overview_customers_url( array( 'vip_status' => 'high_value' ) ),
			__( 'Across all value tiers', 'yoohw-customer-intelligence' )
		);
		echo '</div>';
		echo '</div>';
		echo '</div>';

		self::render_attention_panel( $attention_counts );

		echo '<div class="yoohw-cos-overview-layout">';
		echo '<div class="yoohw-cos-overview-main">';
		self::render_customer_health_panel(
			$lifecycle_counts,
			$risk_counts
		);
		self::render_priority_customers_panel( $priority_customers );
		echo '</div>';
		echo '<div class="yoohw-cos-overview-side">';
		self::render_priority_tasks_panel( $priority_tasks );
		self::render_recent_activity_panel( $recent_activity );
		echo '</div>';
		echo '</div>';

		echo '</div>';
	}

	public static function render_customers_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'yoohw-customer-intelligence' ) );
		}

		YoOhw_COS_Customer_Exporter::maybe_handle_request();

		if ( isset( $_GET['customer_id'] ) ) {
			$customer_id = absint( wp_unslash( $_GET['customer_id'] ) );
			YoOhw_COS_Customer_Profile::render( $customer_id );
			return;
		}

		if (
			! empty( $_GET['s'] )
			&& empty( $_GET['customer_status'] )
			&& empty( $_GET['vip_status'] )
			&& empty( $_GET['risk_level'] )
			&& empty( $_GET['lifecycle_stage'] )
			&& empty( $_GET['customer_cohort'] )
			&& empty( $_GET['customer_attention'] )
			&& ( ! self::is_loyalty_integration_active() || empty( $_GET['loyalty_level'] ) )
			&& empty( $_GET['customer_tag'] )
			&& empty( $_GET['customer_segment'] )
		) {
			$matched_customer_id = YoOhw_COS_Customers::find_customer_id_by_search(
				sanitize_text_field( wp_unslash( $_GET['s'] ) )
			);

			if ( $matched_customer_id > 0 ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'page'        => 'yoohw-customer-intelligence',
							'customer_id' => $matched_customer_id,
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}
		}

		self::maybe_handle_customers_bulk_action();

		$list_table = new YoOhw_COS_Customers_List();
		$list_table->prepare_items();

		echo '<div class="wrap yoohw-cos-admin yoohw-cos-customers-page">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Customers', 'yoohw-customer-intelligence' ) . '</h1>';
		echo '<p>' . esc_html__( 'Search, filter, export, and manage customer profiles synced from WooCommerce orders.', 'yoohw-customer-intelligence' ) . '</p>';

		if ( isset( $_GET['yoohw_customers_bulk'] ) ) {
			$updated      = absint( wp_unslash( $_GET['yoohw_customers_bulk'] ) );
			$bulk_action  = isset( $_GET['yoohw_customers_bulk_action'] ) ? sanitize_key( wp_unslash( $_GET['yoohw_customers_bulk_action'] ) ) : '';
			$bulk_target  = isset( $_GET['yoohw_customers_bulk_target'] ) ? sanitize_text_field( wp_unslash( $_GET['yoohw_customers_bulk_target'] ) ) : '';
			$bulk_message = self::get_customers_bulk_success_message( $bulk_action, $updated, $bulk_target );

			echo '<div class="notice notice-success is-dismissible"><p>';
			echo esc_html( $bulk_message );
			echo '</p></div>';
		}

		if ( ! empty( $_GET['yoohw_customers_bulk_err'] ) ) {
			$error = sanitize_key( wp_unslash( $_GET['yoohw_customers_bulk_err'] ) );

			$messages = array(
				'no_customers'    => __( 'Please select at least one customer before applying a bulk action.', 'yoohw-customer-intelligence' ),
				'missing_tag'     => __( 'Please select a tag before applying this bulk action.', 'yoohw-customer-intelligence' ),
				'missing_segment' => __( 'Please select a segment before applying this bulk action.', 'yoohw-customer-intelligence' ),
				'missing_task'    => __( 'Please enter a follow-up task title before applying this bulk action.', 'yoohw-customer-intelligence' ),
				'invalid_assignee' => __( 'Please select a valid assignee. Tasks can only be assigned to administrators or shop managers.', 'yoohw-customer-intelligence' ),
				'invalid_due_date' => __( 'Please enter a valid task due date or leave it empty.', 'yoohw-customer-intelligence' ),
				'no_changes'      => __( 'Bulk action completed, but no customer records were changed.', 'yoohw-customer-intelligence' ),
			);
			$message = $messages[ $error ] ?? __( 'Bulk action could not be completed.', 'yoohw-customer-intelligence' );

			if ( 'no_changes' === $error ) {
				$bulk_action = isset( $_GET['yoohw_customers_bulk_action'] ) ? sanitize_key( wp_unslash( $_GET['yoohw_customers_bulk_action'] ) ) : '';
				$bulk_target = isset( $_GET['yoohw_customers_bulk_target'] ) ? sanitize_text_field( wp_unslash( $_GET['yoohw_customers_bulk_target'] ) ) : '';
				$message     = self::get_customers_bulk_no_changes_message( $bulk_action, $bulk_target );
			}

			echo '<div class="notice notice-error is-dismissible"><p>';
			echo esc_html( $message );
			echo '</p></div>';
		}

		$list_table->views();

		echo '<form method="post">';
		echo '<input type="hidden" name="page" value="yoohw-customer-intelligence" />';
		wp_nonce_field( 'yoohw_cos_customers_bulk_action', 'yoohw_cos_customers_bulk_nonce' );
		wp_nonce_field( 'yoohw_cos_export_customers', 'yoohw_cos_customers_export_nonce' );
		self::render_customers_list_hidden_state();
		$list_table->search_box( __( 'Search', 'yoohw-customer-intelligence' ), 'yoohw-cos-customers' );
		$list_table->display();
		echo '</form>';

		echo '</div>';
	}

	private static function maybe_handle_customers_bulk_action(): void {
		if ( ! self::is_post_request() ) {
			return;
		}

		if ( empty( $_POST['yoohw_cos_customers_bulk_nonce'] ) ) {
			return;
		}

		if (
			! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['yoohw_cos_customers_bulk_nonce'] ) ),
				'yoohw_cos_customers_bulk_action'
			)
		) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$redirect_filter_args = self::sanitize_customers_list_redirect_args( wp_unslash( $_POST ) );

		$action = isset( $_POST['action'] )
			? sanitize_key( wp_unslash( $_POST['action'] ) )
			: '';

		if ( '-1' === $action || '' === $action ) {
			$action = isset( $_POST['action2'] )
				? sanitize_key( wp_unslash( $_POST['action2'] ) )
				: '';
		}

		$allowed_actions = array(
			'bulk_assign_tag',
			'bulk_remove_tag',
			'bulk_assign_segment',
			'bulk_remove_segment',
			'bulk_create_task',
			'bulk_archive_customer',
			'bulk_restore_customer',
		);

		if ( ! in_array( $action, $allowed_actions, true ) ) {
			return;
		}

		$customer_ids = isset( $_POST['customer_ids'] ) && is_array( $_POST['customer_ids'] )
			? array_map( 'absint', wp_unslash( $_POST['customer_ids'] ) )
			: array();

		$customer_ids = array_filter( $customer_ids );
		$customer_ids = array_filter(
			$customer_ids,
			static function( int $customer_id ): bool {
				return YoOhw_COS_Customers::customer_exists( $customer_id );
			}
		);

		if ( empty( $customer_ids ) ) {
			wp_safe_redirect(
				add_query_arg(
					self::get_customers_list_redirect_args(
						array(
							'yoohw_customers_bulk_err' => 'no_customers',
						),
						$redirect_filter_args
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$updated        = 0;
		$tag_id         = 0;
		$segment_id     = 0;
		$target_label   = '';
		$bulk_task_data = array();

		/*
		|--------------------------------------------------------------------------
		| Validate tag actions
		|--------------------------------------------------------------------------
		*/

		if ( 'bulk_assign_tag' === $action || 'bulk_remove_tag' === $action ) {
			$tag_id = isset( $_POST['bulk_tag_id'] )
				? absint( wp_unslash( $_POST['bulk_tag_id'] ) )
				: 0;
			$tag    = $tag_id > 0 ? YoOhw_COS_Tags::get_tag( $tag_id ) : array();

			if ( $tag_id <= 0 || empty( $tag ) ) {
				wp_safe_redirect(
					add_query_arg(
						self::get_customers_list_redirect_args(
							array(
								'yoohw_customers_bulk_err' => 'missing_tag',
							),
							$redirect_filter_args
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}

			$target_label = sanitize_text_field( (string) ( $tag['name'] ?? '' ) );
		}

		/*
		|--------------------------------------------------------------------------
		| Validate segment actions
		|--------------------------------------------------------------------------
		*/

		if ( 'bulk_assign_segment' === $action || 'bulk_remove_segment' === $action ) {
			$segment_id = isset( $_POST['bulk_segment_id'] )
				? absint( wp_unslash( $_POST['bulk_segment_id'] ) )
				: 0;
			$segment    = $segment_id > 0 ? YoOhw_COS_Segments::get_segment( $segment_id ) : array();

			if ( $segment_id <= 0 || empty( $segment ) ) {
				wp_safe_redirect(
					add_query_arg(
						self::get_customers_list_redirect_args(
							array(
								'yoohw_customers_bulk_err' => 'missing_segment',
							),
							$redirect_filter_args
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}

			$target_label = sanitize_text_field( (string) ( $segment['name'] ?? '' ) );
		}

		if ( 'bulk_create_task' === $action ) {
			$task_title = isset( $_POST['bulk_task_title'] )
				? sanitize_text_field( wp_unslash( $_POST['bulk_task_title'] ) )
				: '';

			if ( '' === $task_title ) {
				wp_safe_redirect(
					add_query_arg(
						self::get_customers_list_redirect_args(
							array(
								'yoohw_customers_bulk_err' => 'missing_task',
							),
							$redirect_filter_args
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}

			$task_due_date_raw = isset( $_POST['bulk_task_due_date'] )
				? sanitize_text_field( wp_unslash( $_POST['bulk_task_due_date'] ) )
				: '';
			$task_due_date     = YoOhw_COS_Tasks::normalize_due_date( $task_due_date_raw );

			if ( '' !== $task_due_date_raw && null === $task_due_date ) {
				wp_safe_redirect(
					add_query_arg(
						self::get_customers_list_redirect_args(
							array(
								'yoohw_customers_bulk_err' => 'invalid_due_date',
							),
							$redirect_filter_args
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}

			$assigned_user_id = isset( $_POST['bulk_task_assigned_user_id'] )
				? absint( wp_unslash( $_POST['bulk_task_assigned_user_id'] ) )
				: 0;

			if ( $assigned_user_id > 0 && ! YoOhw_COS_Tasks::is_assignable_user( $assigned_user_id ) ) {
				wp_safe_redirect(
					add_query_arg(
						self::get_customers_list_redirect_args(
							array(
								'yoohw_customers_bulk_err' => 'invalid_assignee',
							),
							$redirect_filter_args
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}

			$bulk_task_data = array(
				'title'            => $task_title,
				'priority'         => isset( $_POST['bulk_task_priority'] )
					? YoOhw_COS_Tasks::normalize_priority( sanitize_key( wp_unslash( $_POST['bulk_task_priority'] ) ) )
					: 'normal',
				'due_date'         => $task_due_date,
				'assigned_user_id' => $assigned_user_id,
				'created_by'       => get_current_user_id(),
			);
			$target_label   = $task_title;
		}

		/*
		|--------------------------------------------------------------------------
		| Assign tag
		|--------------------------------------------------------------------------
		*/

		if ( 'bulk_assign_tag' === $action ) {
			foreach ( $customer_ids as $customer_id ) {
				if (
					! YoOhw_COS_Tags::customer_has_tag( $customer_id, $tag_id )
					&&
					YoOhw_COS_Tags::assign_tag(
						$customer_id,
						$tag_id,
						0,
						false
					)
				) {
					$updated++;
				}
			}
		}

		/*
		|--------------------------------------------------------------------------
		| Remove tag
		|--------------------------------------------------------------------------
		*/

		if ( 'bulk_remove_tag' === $action ) {
			foreach ( $customer_ids as $customer_id ) {
				if (
					YoOhw_COS_Tags::remove_tag(
						$customer_id,
						$tag_id,
						false
					)
				) {
					$updated++;
				}
			}
		}

		/*
		|--------------------------------------------------------------------------
		| Assign segment
		|--------------------------------------------------------------------------
		*/

		if ( 'bulk_assign_segment' === $action ) {
			foreach ( $customer_ids as $customer_id ) {
				if (
					! YoOhw_COS_Segments::customer_in_segment( $customer_id, $segment_id )
					&&
					YoOhw_COS_Segments::assign_customer(
						$customer_id,
						$segment_id,
						0,
						false
					)
				) {
					$updated++;
				}
			}
		}

		/*
		|--------------------------------------------------------------------------
		| Remove segment
		|--------------------------------------------------------------------------
		*/

		if ( 'bulk_remove_segment' === $action ) {
			foreach ( $customer_ids as $customer_id ) {
				if (
					YoOhw_COS_Segments::remove_customer(
						$customer_id,
						$segment_id,
						false
					)
				) {
					$updated++;
				}
			}
		}

		if ( 'bulk_create_task' === $action ) {
			foreach ( $customer_ids as $customer_id ) {
				$task_id = YoOhw_COS_Tasks::create_task(
					array_merge(
						$bulk_task_data,
						array(
							'customer_id' => $customer_id,
						)
					)
				);

				if ( $task_id > 0 ) {
					$updated++;
				}
			}
		}

		if ( 'bulk_archive_customer' === $action ) {
			foreach ( $customer_ids as $customer_id ) {
				if ( YoOhw_COS_Customers::archive_customer( $customer_id, get_current_user_id() ) ) {
					$updated++;
				}
			}
		}

		if ( 'bulk_restore_customer' === $action ) {
			foreach ( $customer_ids as $customer_id ) {
				if ( YoOhw_COS_Customers::restore_customer( $customer_id ) ) {
					$updated++;
				}
			}
		}

		/*
		|--------------------------------------------------------------------------
		| Record bulk summary event
		|--------------------------------------------------------------------------
		*/

		if ( $updated > 0 ) {
			YoOhw_COS_Events::record(
				array(
					'customer_id'  => 0,
					'event_type'   => 'bulk_customer_action',
					'event_source' => 'customer_os',
					'severity'     => 'info',
					'description'  => sprintf(
						/* translators: 1: bulk action label, 2: number of customers. */
						__( 'Bulk customer action completed: %1$s. %2$d customers changed.', 'yoohw-customer-intelligence' ),
						self::get_customer_bulk_action_label( $action ),
						$updated
					),
					'metadata'     => array(
						'action'       => $action,
						'target'       => $target_label,
						'updated'      => $updated,
						'customer_ids' => $customer_ids,
					),
				)
			);

			wp_safe_redirect(
				add_query_arg(
					self::get_customers_list_redirect_args(
						array(
							'yoohw_customers_bulk'        => $updated,
							'yoohw_customers_bulk_action' => $action,
							'yoohw_customers_bulk_target' => $target_label,
						),
						$redirect_filter_args
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				self::get_customers_list_redirect_args(
					array(
						'yoohw_customers_bulk_err'    => 'no_changes',
						'yoohw_customers_bulk_action' => $action,
						'yoohw_customers_bulk_target' => $target_label,
					),
					$redirect_filter_args
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private static function get_customer_bulk_action_label( string $action ): string {
		$labels = array(
			'bulk_assign_tag'     => __( 'Assign tag', 'yoohw-customer-intelligence' ),
			'bulk_remove_tag'     => __( 'Remove tag', 'yoohw-customer-intelligence' ),
			'bulk_assign_segment' => __( 'Assign segment', 'yoohw-customer-intelligence' ),
			'bulk_remove_segment' => __( 'Remove segment', 'yoohw-customer-intelligence' ),
			'bulk_create_task'      => __( 'Create follow-up task', 'yoohw-customer-intelligence' ),
			'bulk_archive_customer' => __( 'Archive customers', 'yoohw-customer-intelligence' ),
			'bulk_restore_customer' => __( 'Restore customers', 'yoohw-customer-intelligence' ),
		);

		return $labels[ $action ] ?? __( 'Bulk action', 'yoohw-customer-intelligence' );
	}

	private static function get_customers_bulk_success_message( string $action, int $updated, string $target = '' ): string {
		$action_label = self::get_customer_bulk_action_label( $action );
		$count        = number_format_i18n( absint( $updated ) );

		if ( 'bulk_create_task' === $action ) {
			return '' !== $target
				? sprintf(
					/* translators: 1: number of customers, 2: task title. */
					__( 'Created follow-up tasks for %1$s customers: %2$s.', 'yoohw-customer-intelligence' ),
					$count,
					$target
				)
				: sprintf(
					/* translators: %s: number of customers. */
					__( 'Created follow-up tasks for %s customers.', 'yoohw-customer-intelligence' ),
					$count
				);
		}

		if ( 'bulk_archive_customer' === $action ) {
			return sprintf(
				/* translators: %s: number of customers. */
				__( 'Archived %s customers.', 'yoohw-customer-intelligence' ),
				$count
			);
		}

		if ( 'bulk_restore_customer' === $action ) {
			return sprintf(
				/* translators: %s: number of customers. */
				__( 'Restored %s customers from archive.', 'yoohw-customer-intelligence' ),
				$count
			);
		}

		if ( '' !== $target ) {
			return sprintf(
				/* translators: 1: bulk action label, 2: target label, 3: number of customers. */
				__( '%1$s "%2$s" completed for %3$s customers.', 'yoohw-customer-intelligence' ),
				$action_label,
				$target,
				$count
			);
		}

		return sprintf(
			/* translators: 1: bulk action label, 2: number of customers. */
			__( '%1$s completed for %2$s customers.', 'yoohw-customer-intelligence' ),
			$action_label,
			$count
		);
	}

	private static function get_customers_bulk_no_changes_message( string $action, string $target = '' ): string {
		$action_label = self::get_customer_bulk_action_label( $action );

		if ( '' !== $target ) {
			return sprintf(
				/* translators: 1: bulk action label, 2: target label. */
				__( '%1$s "%2$s" did not change any selected customers. They may already match that state.', 'yoohw-customer-intelligence' ),
				$action_label,
				$target
			);
		}

		return sprintf(
			/* translators: %s: bulk action label. */
			__( '%s did not change any selected customers.', 'yoohw-customer-intelligence' ),
			$action_label
		);
	}

	private static function render_customers_list_hidden_state(): void {
		$preserve_keys = array(
			'customer_status',
			'customer_view',
			'orderby',
			'order',
		);

		if ( self::is_loyalty_integration_active() ) {
			$preserve_keys[] = 'loyalty_level';
		}

		foreach ( $preserve_keys as $key ) {
			if ( ! isset( $_REQUEST[ $key ] ) || is_array( $_REQUEST[ $key ] ) ) {
				continue;
			}

			$value = sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) );

			if ( '' === $value ) {
				continue;
			}

			echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" />';
		}
	}

	public static function render_tasks_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'yoohw-customer-intelligence' ) );
		}

		self::maybe_handle_tasks_bulk_action();

		$list_table = new YoOhw_COS_Tasks_List();
		$list_table->prepare_items();
		$task_counts = YoOhw_COS_Tasks::get_counts();

		$editing_task = array();

		if ( ! empty( $_GET['task_id'] ) ) {
			$editing_task = YoOhw_COS_Tasks::get_task(
				absint( wp_unslash( $_GET['task_id'] ) )
			);
		}

		echo '<div class="wrap yoohw-cos-admin yoohw-cos-tasks-page">';
		echo '<div class="yoohw-cos-task-header">';
		echo '<div class="yoohw-cos-task-header__title">';
		echo '<span class="dashicons dashicons-clipboard" aria-hidden="true"></span>';
		echo '<div>';
		echo '<h1>' . esc_html__( 'Follow-up tasks', 'yoohw-customer-intelligence' ) . '</h1>';
		echo '<p>' . esc_html__( 'Track follow-ups for customers and orders.', 'yoohw-customer-intelligence' ) . '</p>';
		echo '</div>';
		echo '</div>';
		echo '<a class="button button-primary" href="#yoohw-cos-task-form">' . esc_html__( 'Add task', 'yoohw-customer-intelligence' ) . '</a>';
		echo '</div>';

		self::render_tasks_notices();
		self::render_tasks_summary_cards( $task_counts );

		echo '<div id="col-container" class="wp-clearfix yoohw-cos-task-layout">';

		echo '<div id="col-left">';
		echo '<div class="col-wrap">';
		self::render_task_form( $editing_task );
		echo '</div>';
		echo '</div>';

		echo '<div id="col-right">';
		echo '<div class="col-wrap">';
		echo '<div class="yoohw-cos-task-list-panel__header">';
		echo '<h2>' . esc_html__( 'Tasks', 'yoohw-customer-intelligence' ) . '</h2>';
		echo '</div>';
		$list_table->views();
		echo '<form method="post">';
		echo '<input type="hidden" name="page" value="yoohw-customer-intelligence-tasks" />';

		foreach ( array( 'task_view', 'priority', 'assigned_user_id', 'customer_id', 'order_id' ) as $key ) {
			if ( ! empty( $_GET[ $key ] ) ) {
				echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) ) . '" />';
			}
		}

		$list_table->search_box( __( 'Search tasks', 'yoohw-customer-intelligence' ), 'yoohw-cos-tasks' );
		$list_table->display();
		echo '</form>';
		echo '</div>';
		echo '</div>';

		echo '</div>';
		echo '</div>';
	}

	private static function render_tasks_summary_cards( array $counts ): void {
		$current = isset( $_GET['task_view'] ) ? sanitize_key( wp_unslash( $_GET['task_view'] ) ) : 'open';

		$cards = array(
			'open'           => array(
				'label' => __( 'Open', 'yoohw-customer-intelligence' ),
				'icon'  => 'dashicons-list-view',
			),
			'overdue'        => array(
				'label' => __( 'Overdue', 'yoohw-customer-intelligence' ),
				'icon'  => 'dashicons-warning',
			),
			'assigned_to_me' => array(
				'label' => __( 'Assigned to me', 'yoohw-customer-intelligence' ),
				'icon'  => 'dashicons-admin-users',
			),
			'completed'      => array(
				'label' => __( 'Completed', 'yoohw-customer-intelligence' ),
				'icon'  => 'dashicons-yes-alt',
			),
			'all'            => array(
				'label' => __( 'All', 'yoohw-customer-intelligence' ),
				'icon'  => 'dashicons-archive',
			),
		);

		echo '<div class="yoohw-cos-task-summary">';

		foreach ( $cards as $view => $card ) {
			$count = absint( $counts[ $view ] ?? 0 );
			$url   = add_query_arg(
				array(
					'page'      => 'yoohw-customer-intelligence-tasks',
					'task_view' => $view,
				),
				admin_url( 'admin.php' )
			);
			$class = 'yoohw-cos-task-summary-card yoohw-cos-task-summary-card--' . sanitize_html_class( $view );

			if ( $current === $view ) {
				$class .= ' is-current';
			}

			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">';
			echo '<span class="yoohw-cos-task-summary-card__label">';
			echo '<span class="dashicons ' . esc_attr( $card['icon'] ) . '" aria-hidden="true"></span>';
			echo esc_html( $card['label'] );
			echo '</span>';
			echo '<strong class="yoohw-cos-task-summary-card__value">' . esc_html( number_format_i18n( $count ) ) . '</strong>';
			echo '</a>';
		}

		echo '</div>';
	}

	private static function maybe_handle_tasks_bulk_action(): void {
		if ( ! self::is_post_request() ) {
			return;
		}

		$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';

		if ( '-1' === $action || '' === $action ) {
			$action = isset( $_POST['action2'] ) ? sanitize_key( wp_unslash( $_POST['action2'] ) ) : '';
		}

		if ( ! in_array( $action, array( 'complete', 'reopen', 'delete' ), true ) ) {
			return;
		}

		check_admin_referer( 'bulk-yoohw_cos_tasks' );

		$task_ids = isset( $_POST['task_ids'] ) && is_array( $_POST['task_ids'] )
			? array_map( 'absint', wp_unslash( $_POST['task_ids'] ) )
			: array();

		$task_ids = array_filter( $task_ids );

		if ( empty( $task_ids ) ) {
			wp_safe_redirect(
				add_query_arg(
					self::get_tasks_list_redirect_args(
						array(
							'yoohw_tasks_bulk_missing' => 1,
						)
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$updated = 0;

		foreach ( $task_ids as $task_id ) {
			if ( empty( YoOhw_COS_Tasks::get_task( $task_id ) ) ) {
				continue;
			}

			if ( 'complete' === $action && YoOhw_COS_Tasks::complete_task( $task_id ) ) {
				$updated++;
			}

			if ( 'reopen' === $action && YoOhw_COS_Tasks::reopen_task( $task_id ) ) {
				$updated++;
			}

			if ( 'delete' === $action && YoOhw_COS_Tasks::delete_task( $task_id ) ) {
				$updated++;
			}
		}

		wp_safe_redirect(
			add_query_arg(
				self::get_tasks_list_redirect_args(
					array(
						'yoohw_tasks_bulk_action' => $action,
						'yoohw_tasks_bulk_done'   => $updated,
					)
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private static function render_tasks_notices(): void {
		if ( ! empty( $_GET['yoohw_task_error'] ) ) {
			$error = sanitize_key( wp_unslash( $_GET['yoohw_task_error'] ) );

			$messages = array(
				'missing_customer' => __( 'Please select a valid customer.', 'yoohw-customer-intelligence' ),
				'missing_title'    => __( 'Please enter a task title.', 'yoohw-customer-intelligence' ),
				'invalid_assignee' => __( 'Please assign tasks only to an administrator or shop manager.', 'yoohw-customer-intelligence' ),
				'invalid_task'     => __( 'Task could not be found.', 'yoohw-customer-intelligence' ),
				'save_failed'      => __( 'Task could not be saved.', 'yoohw-customer-intelligence' ),
			);

			echo '<div class="notice notice-error is-dismissible"><p>';
			echo esc_html( $messages[ $error ] ?? __( 'Task action could not be completed.', 'yoohw-customer-intelligence' ) );
			echo '</p></div>';
		}

		$success_messages = array(
			'yoohw_task_created'   => __( 'Task created successfully.', 'yoohw-customer-intelligence' ),
			'yoohw_task_updated'   => __( 'Task updated successfully.', 'yoohw-customer-intelligence' ),
			'yoohw_task_completed' => __( 'Task marked complete.', 'yoohw-customer-intelligence' ),
			'yoohw_task_reopened'  => __( 'Task reopened.', 'yoohw-customer-intelligence' ),
			'yoohw_task_deleted'   => __( 'Task deleted.', 'yoohw-customer-intelligence' ),
		);

		foreach ( $success_messages as $key => $message ) {
			if ( isset( $_GET[ $key ] ) && absint( wp_unslash( $_GET[ $key ] ) ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
			}
		}

		if ( ! empty( $_GET['yoohw_tasks_bulk_missing'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>';
			echo esc_html__( 'Please select at least one task before applying a bulk action.', 'yoohw-customer-intelligence' );
			echo '</p></div>';
		}

		if ( isset( $_GET['yoohw_tasks_bulk_done'] ) ) {
			$done   = absint( wp_unslash( $_GET['yoohw_tasks_bulk_done'] ) );
			$action = isset( $_GET['yoohw_tasks_bulk_action'] ) ? sanitize_key( wp_unslash( $_GET['yoohw_tasks_bulk_action'] ) ) : '';

			echo '<div class="notice notice-success is-dismissible"><p>';
			printf(
				/* translators: 1: number of tasks, 2: task bulk action name. */
				esc_html__( '%1$s tasks processed for action: %2$s.', 'yoohw-customer-intelligence' ),
				esc_html( number_format_i18n( $done ) ),
				esc_html( $action )
			);
			echo '</p></div>';
		}
	}

	private static function render_task_form( array $task = array() ): void {
		$is_editing           = ! empty( $task['id'] );
		$requested_customer_id = isset( $_GET['customer_id'] ) ? absint( wp_unslash( $_GET['customer_id'] ) ) : 0;
		$requested_order_id    = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
		$selected_id           = absint( $task['customer_id'] ?? $requested_customer_id );
		$selected_order_id     = absint( $task['order_id'] ?? $requested_order_id );
		$selected_due          = YoOhw_COS_Tasks::format_due_date_for_input( $task['due_date'] ?? '' );

		echo '<div id="yoohw-cos-task-form" class="postbox yoohw-cos-task-form-wrap">';
		echo '<div class="postbox-header"><h2 class="hndle">';
		echo $is_editing
			? esc_html__( 'Edit task', 'yoohw-customer-intelligence' )
			: esc_html__( 'Add new task', 'yoohw-customer-intelligence' );
		echo '</h2></div>';
		echo '<div class="inside">';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';

		if ( $is_editing ) {
			echo '<input type="hidden" name="action" value="yoohw_cos_update_task" />';
			echo '<input type="hidden" name="task_id" value="' . esc_attr( absint( $task['id'] ) ) . '" />';
			wp_nonce_field( 'yoohw_cos_update_task' );
		} else {
			echo '<input type="hidden" name="action" value="yoohw_cos_create_task" />';
			wp_nonce_field( 'yoohw_cos_create_task' );
		}

		echo '<div class="form-field form-required">';
		echo '<label for="yoohw_cos_task_customer_id">' . esc_html__( 'Customer', 'yoohw-customer-intelligence' ) . '</label>';
		self::render_task_customer_select( $selected_id );
		echo '<p>' . esc_html__( 'Tasks are stored against customer profiles.', 'yoohw-customer-intelligence' ) . '</p>';
		echo '</div>';

		echo '<div class="form-field form-required">';
		echo '<label for="yoohw_cos_task_title">' . esc_html__( 'Title', 'yoohw-customer-intelligence' ) . '</label>';
		echo '<input type="text" id="yoohw_cos_task_title" name="task_title" value="' . esc_attr( $task['title'] ?? '' ) . '" required />';
		echo '</div>';

		echo '<div class="form-field">';
		echo '<label for="yoohw_cos_task_description">' . esc_html__( 'Description', 'yoohw-customer-intelligence' ) . '</label>';
		echo '<textarea id="yoohw_cos_task_description" name="task_description" rows="4">' . esc_textarea( $task['description'] ?? '' ) . '</textarea>';
		echo '</div>';

		echo '<div class="form-field">';
		echo '<label for="yoohw_cos_task_due_date">' . esc_html__( 'Due date', 'yoohw-customer-intelligence' ) . '</label>';
		echo '<input type="datetime-local" id="yoohw_cos_task_due_date" name="task_due_date" value="' . esc_attr( $selected_due ) . '" />';
		echo '</div>';

		echo '<div class="form-field">';
		echo '<label for="yoohw_cos_task_priority">' . esc_html__( 'Priority', 'yoohw-customer-intelligence' ) . '</label>';
		echo '<select id="yoohw_cos_task_priority" name="task_priority">';
		$current_priority = YoOhw_COS_Tasks::normalize_priority( (string) ( $task['priority'] ?? 'normal' ) );

		foreach ( YoOhw_COS_Tasks::get_priorities() as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $current_priority, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}

		echo '</select>';
		echo '</div>';

		echo '<div class="form-field">';
		echo '<label for="yoohw_cos_task_status">' . esc_html__( 'Status', 'yoohw-customer-intelligence' ) . '</label>';
		echo '<select id="yoohw_cos_task_status" name="task_status">';
		$current_status = YoOhw_COS_Tasks::normalize_status( (string) ( $task['status'] ?? YoOhw_COS_Tasks::STATUS_OPEN ) );

		foreach ( YoOhw_COS_Tasks::get_statuses() as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $current_status, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}

		echo '</select>';
		echo '</div>';

		echo '<div class="form-field">';
		echo '<label for="yoohw_cos_task_assigned_user_id">' . esc_html__( 'Assigned to', 'yoohw-customer-intelligence' ) . '</label>';
		self::render_task_assignee_select( absint( $task['assigned_user_id'] ?? get_current_user_id() ) );
		echo '</div>';

		echo '<div class="form-field">';
		echo '<label for="yoohw_cos_task_order_id">' . esc_html__( 'Order ID', 'yoohw-customer-intelligence' ) . '</label>';
		echo '<input type="number" min="1" id="yoohw_cos_task_order_id" name="order_id" value="' . esc_attr( $selected_order_id ?: '' ) . '" />';
		echo '<p>' . esc_html__( 'Optional WooCommerce order reference.', 'yoohw-customer-intelligence' ) . '</p>';
		echo '</div>';

		echo '<div class="yoohw-cos-task-form-actions">';
		submit_button(
			$is_editing
				? __( 'Update task', 'yoohw-customer-intelligence' )
				: __( 'Add task', 'yoohw-customer-intelligence' ),
			'primary',
			'submit',
			false
		);

		if ( $is_editing ) {
			echo ' <a class="button" href="' . esc_url( admin_url( 'admin.php?page=yoohw-customer-intelligence-tasks' ) ) . '">';
			echo esc_html__( 'Cancel', 'yoohw-customer-intelligence' );
			echo '</a>';
		}

		echo '</div>';
		echo '</form>';
		echo '</div>';
		echo '</div>';
	}

	private static function render_task_customer_select( int $selected_customer_id ): void {
		$selected_customer = $selected_customer_id > 0 ? YoOhw_COS_Customers::get_customer( $selected_customer_id ) : array();

		echo '<select id="yoohw_cos_task_customer_id" name="customer_id" class="yoohw-cos-task-customer-search" data-placeholder="' . esc_attr__( 'Search customer profile', 'yoohw-customer-intelligence' ) . '" required>';
		echo '<option value=""></option>';

		if ( ! empty( $selected_customer ) ) {
			echo '<option value="' . esc_attr( $selected_customer_id ) . '" selected="selected">';
			echo esc_html( self::format_task_customer_option_label( $selected_customer ) );
			echo '</option>';
		}

		echo '</select>';
	}

	private static function get_task_customer_options( int $selected_customer_id = 0 ): array {
		global $wpdb;

		$table = YoOhw_COS_DB::customers_table();

		$customers = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, display_name, email, phone
				FROM %i
				ORDER BY display_name ASC, email ASC, id ASC
				LIMIT 200",
				$table
			),
			ARRAY_A
		);

		$customers = is_array( $customers ) ? $customers : array();

		if ( $selected_customer_id > 0 ) {
			$found = false;

			foreach ( $customers as $customer ) {
				if ( absint( $customer['id'] ?? 0 ) === $selected_customer_id ) {
					$found = true;
					break;
				}
			}

			if ( ! $found ) {
				$selected = YoOhw_COS_Customers::get_customer( $selected_customer_id );

				if ( ! empty( $selected ) ) {
					array_unshift( $customers, $selected );
				}
			}
		}

		return $customers;
	}

	private static function format_task_customer_option_label( array $customer ): string {
		$name  = trim( (string) ( $customer['display_name'] ?? '' ) );
		$email = sanitize_email( (string) ( $customer['email'] ?? '' ) );
		$phone = sanitize_text_field( (string) ( $customer['phone'] ?? '' ) );

		if ( '' === $name ) {
			$name = $email ? $email : __( '(No name)', 'yoohw-customer-intelligence' );
		}

		$parts = array( '#' . absint( $customer['id'] ?? 0 ), $name );

		if ( $email && $email !== $name ) {
			$parts[] = $email;
		} elseif ( $phone ) {
			$parts[] = $phone;
		}

		return implode( ' - ', $parts );
	}

	private static function render_task_assignee_select( int $selected_user_id ): void {
		$users = YoOhw_COS_Tasks::get_assignable_users();

		echo '<select id="yoohw_cos_task_assigned_user_id" name="assigned_user_id" class="yoohw-cos-task-assignee-search" data-placeholder="' . esc_attr__( 'Search assignee', 'yoohw-customer-intelligence' ) . '">';
		echo '<option value="0">' . esc_html__( 'Unassigned', 'yoohw-customer-intelligence' ) . '</option>';

		foreach ( $users as $user ) {
			echo '<option value="' . esc_attr( absint( $user->ID ) ) . '" ' . selected( $selected_user_id, absint( $user->ID ), false ) . '>';
			echo esc_html( $user->display_name );
			echo '</option>';
		}

		echo '</select>';
	}

	private static function get_tasks_list_redirect_args( array $extra = array() ): array {
		$args = array(
			'page' => 'yoohw-customer-intelligence-tasks',
		);

		foreach ( array( 'task_view', 'priority', 'assigned_user_id', 'customer_id', 'order_id', 's', 'paged' ) as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only task list state is preserved across verified bulk action redirects.
			if ( empty( $_GET[ $key ] ) ) {
				continue;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Value is sanitized after preserving scalar shape.
			$value = wp_unslash( $_GET[ $key ] );

			if ( is_array( $value ) ) {
				continue;
			}

			$args[ $key ] = is_numeric( $value )
				? absint( $value )
				: sanitize_text_field( $value );
		}

		return array_merge( $args, $extra );
	}

	public static function render_tags_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'yoohw-customer-intelligence' ) );
		}

		self::maybe_handle_tags_bulk_action();

		$list_table = new YoOhw_COS_Tags_List();
		$list_table->prepare_items();

		$editing_tag = array();

		if ( ! empty( $_GET['edit_tag'] ) ) {
			$editing_tag = YoOhw_COS_Tags::get_tag(
				absint( wp_unslash( $_GET['edit_tag'] ) )
			);
		}

		echo '<div class="wrap yoohw-cos-admin yoohw-cos-tags-page">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Tags', 'yoohw-customer-intelligence' ) . '</h1>';
		echo '<p>' . esc_html__( 'Create reusable customer labels for profiles, filters, and bulk actions.', 'yoohw-customer-intelligence' ) . '</p>';

		if ( ! empty( $_GET['yoohw_tag_error'] ) ) {
			$error = sanitize_key( wp_unslash( $_GET['yoohw_tag_error'] ) );

			$messages = array(
				'missing_name'   => __( 'Please enter a tag name.', 'yoohw-customer-intelligence' ),
				'duplicate_name' => __( 'A tag with this name already exists.', 'yoohw-customer-intelligence' ),
			);

			echo '<div class="notice notice-error is-dismissible"><p>';
			echo esc_html( $messages[ $error ] ?? __( 'Tag could not be saved.', 'yoohw-customer-intelligence' ) );
			echo '</p></div>';
		}

		if ( isset( $_GET['yoohw_tag_created'] ) ) {
			if ( absint( wp_unslash( $_GET['yoohw_tag_created'] ) ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>';
				echo esc_html__( 'Tag created successfully.', 'yoohw-customer-intelligence' );
				echo '</p></div>';
			} else {
				echo '<div class="notice notice-error is-dismissible"><p>';
				echo esc_html__( 'Tag could not be created.', 'yoohw-customer-intelligence' );
				echo '</p></div>';
			}
		}

		if ( isset( $_GET['yoohw_tag_updated'] ) ) {
			if ( absint( wp_unslash( $_GET['yoohw_tag_updated'] ) ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>';
				echo esc_html__( 'Tag updated successfully.', 'yoohw-customer-intelligence' );
				echo '</p></div>';
			} else {
				echo '<div class="notice notice-error is-dismissible"><p>';
				echo esc_html__( 'Tag could not be updated.', 'yoohw-customer-intelligence' );
				echo '</p></div>';
			}
		}

		if ( isset( $_GET['yoohw_tag_deleted'] ) ) {
			if ( absint( wp_unslash( $_GET['yoohw_tag_deleted'] ) ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>';
				echo esc_html__( 'Tag deleted successfully.', 'yoohw-customer-intelligence' );
				echo '</p></div>';
			} else {
				echo '<div class="notice notice-error is-dismissible"><p>';
				echo esc_html__( 'Tag could not be deleted.', 'yoohw-customer-intelligence' );
				echo '</p></div>';
			}
		}

		if ( isset( $_GET['yoohw_tags_bulk_deleted'] ) ) {
			$deleted = absint( wp_unslash( $_GET['yoohw_tags_bulk_deleted'] ) );
			$blocked = isset( $_GET['yoohw_tags_bulk_blocked'] ) ? absint( wp_unslash( $_GET['yoohw_tags_bulk_blocked'] ) ) : 0;

			if ( $deleted > 0 ) {
				echo '<div class="notice notice-success is-dismissible"><p>';
				printf(
					/* translators: %s: number of tags deleted. */
					esc_html__( '%s tags deleted.', 'yoohw-customer-intelligence' ),
					esc_html( number_format_i18n( $deleted ) )
				);
				echo '</p></div>';
			}

			if ( $blocked > 0 ) {
				echo '<div class="notice notice-warning is-dismissible"><p>';
				printf(
					/* translators: %s: number of assigned tags skipped during bulk deletion. */
					esc_html__( '%s assigned tags were skipped. Remove assignments or use the individual force-delete action.', 'yoohw-customer-intelligence' ),
					esc_html( number_format_i18n( $blocked ) )
				);
				echo '</p></div>';
			}
		}

		if ( ! empty( $_GET['yoohw_tags_bulk_missing'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>';
			echo esc_html__( 'Please select at least one tag before applying a bulk action.', 'yoohw-customer-intelligence' );
			echo '</p></div>';
		}

		if ( ! empty( $_GET['yoohw_tag_delete_block'] ) ) {
			$tag_id = absint( wp_unslash( $_GET['yoohw_tag_delete_block'] ) );
			$count  = isset( $_GET['tag_customer_count'] ) ? absint( wp_unslash( $_GET['tag_customer_count'] ) ) : 0;

			$force_url = wp_nonce_url(
				add_query_arg(
					array(
						'action' => 'yoohw_cos_delete_tag',
						'tag_id' => $tag_id,
						'force'  => 1,
					),
					admin_url( 'admin-post.php' )
				),
				'yoohw_cos_delete_tag'
			);

			echo '<div class="notice notice-warning"><p>';
			printf(
				/* translators: %s: number of customers assigned to this tag. */
				esc_html__( 'This tag is assigned to %s customers. Delete it anyway?', 'yoohw-customer-intelligence' ),
				esc_html( number_format_i18n( $count ) )
			);
			echo ' <a class="button button-small button-link-delete" href="' . esc_url( $force_url ) . '" data-yoohw-cos-confirm="' . esc_attr__( 'This will remove the tag from all assigned customers. Continue?', 'yoohw-customer-intelligence' ) . '">';
			echo esc_html__( 'Force delete tag', 'yoohw-customer-intelligence' );
			echo '</a>';
			echo '</p></div>';
		}

		echo '<div id="col-container" class="wp-clearfix yoohw-cos-term-layout">';

		echo '<div id="col-left">';
		echo '<div class="col-wrap">';
		self::render_tag_form( $editing_tag );
		echo '</div>';
		echo '</div>';

		echo '<div id="col-right">';
		echo '<div class="col-wrap">';
		echo '<form method="post">';
		echo '<input type="hidden" name="page" value="yoohw-customer-intelligence-tags" />';
		$list_table->search_box( __( 'Search tags', 'yoohw-customer-intelligence' ), 'yoohw-cos-tags' );
		$list_table->display();
		echo '</form>';
		echo '</div>';
		echo '</div>';

		echo '</div>';

		echo '</div>';
	}

	private static function maybe_handle_tags_bulk_action(): void {
		if ( ! self::is_post_request() ) {
			return;
		}

		$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';

		if ( '-1' === $action || '' === $action ) {
			$action = isset( $_POST['action2'] ) ? sanitize_key( wp_unslash( $_POST['action2'] ) ) : '';
		}

		if ( 'delete' !== $action ) {
			return;
		}

		check_admin_referer( 'bulk-yoohw_cos_tags' );

		$tag_ids = isset( $_POST['tag_ids'] ) && is_array( $_POST['tag_ids'] )
			? array_map( 'absint', wp_unslash( $_POST['tag_ids'] ) )
			: array();

		if ( empty( array_filter( $tag_ids ) ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'                    => 'yoohw-customer-intelligence-tags',
						'yoohw_tags_bulk_missing' => 1,
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$deleted = 0;
		$blocked = 0;

		foreach ( array_filter( $tag_ids ) as $tag_id ) {
			if ( ! YoOhw_COS_Tags::tag_exists( $tag_id ) ) {
				continue;
			}

			if ( YoOhw_COS_Tags::get_tag_customer_count( $tag_id ) > 0 ) {
				$blocked++;
				continue;
			}

			if ( YoOhw_COS_Tags::delete_tag( $tag_id, false ) ) {
				$deleted++;
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                     => 'yoohw-customer-intelligence-tags',
					'yoohw_tags_bulk_deleted'  => $deleted,
					'yoohw_tags_bulk_blocked'  => $blocked,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private static function render_tag_form( array $tag = array() ): void {
		$is_editing = ! empty( $tag['id'] );

		echo '<div class="form-wrap">';
		echo '<h2>';

		echo $is_editing
			? esc_html__( 'Edit tag', 'yoohw-customer-intelligence' )
			: esc_html__( 'Add new tag', 'yoohw-customer-intelligence' );

		echo '</h2>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';

		if ( $is_editing ) {
			echo '<input type="hidden" name="action" value="yoohw_cos_update_tag" />';
			echo '<input type="hidden" name="tag_id" value="' . esc_attr( absint( $tag['id'] ) ) . '" />';
			wp_nonce_field( 'yoohw_cos_update_tag' );
		} else {
			echo '<input type="hidden" name="action" value="yoohw_cos_create_tag" />';
			wp_nonce_field( 'yoohw_cos_create_tag' );
		}

		echo '<div class="form-field form-required term-name-wrap">';
		echo '<label for="yoohw_cos_tag_name">' . esc_html__( 'Name', 'yoohw-customer-intelligence' ) . '</label>';
		echo '<input type="text" id="yoohw_cos_tag_name" name="tag_name" value="' . esc_attr( $tag['name'] ?? '' ) . '" placeholder="' . esc_attr__( 'High value, high risk, needs follow-up...', 'yoohw-customer-intelligence' ) . '" required />';
		echo '<p>' . esc_html__( 'The name is how the tag appears on customer profiles and customer lists.', 'yoohw-customer-intelligence' ) . '</p>';
		echo '</div>';

		echo '<div class="form-field term-color-wrap">';
		echo '<label for="yoohw_cos_tag_color">' . esc_html__( 'Color', 'yoohw-customer-intelligence' ) . '</label>';
		echo '<input type="color" id="yoohw_cos_tag_color" name="tag_color" value="' . esc_attr( ! empty( $tag['color'] ) ? $tag['color'] : '#f0f0f1' ) . '" />';
		echo '</div>';

		echo '<div class="form-field term-description-wrap">';
		echo '<label for="yoohw_cos_tag_description">' . esc_html__( 'Description', 'yoohw-customer-intelligence' ) . '</label>';
		echo '<textarea id="yoohw_cos_tag_description" name="tag_description" rows="5" placeholder="' . esc_attr__( 'Describe how this tag should be used...', 'yoohw-customer-intelligence' ) . '">' . esc_textarea( $tag['description'] ?? '' ) . '</textarea>';
		echo '<p>' . esc_html__( 'Descriptions are shown internally only.', 'yoohw-customer-intelligence' ) . '</p>';
		echo '</div>';

		submit_button(
			$is_editing
				? __( 'Update tag', 'yoohw-customer-intelligence' )
				: __( 'Add new tag', 'yoohw-customer-intelligence' ),
			'primary',
			'submit',
			false
		);

		if ( $is_editing ) {
			echo ' <a class="button" href="' . esc_url( admin_url( 'admin.php?page=yoohw-customer-intelligence-tags' ) ) . '">';
			echo esc_html__( 'Cancel', 'yoohw-customer-intelligence' );
			echo '</a>';
		}

		echo '</form>';
		echo '</div>';
	}

	public static function render_segments_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'yoohw-customer-intelligence' ) );
		}

		self::maybe_handle_segments_bulk_action();

		$list_table = new YoOhw_COS_Segments_List();
		$list_table->prepare_items();

		echo '<div class="wrap yoohw-cos-admin yoohw-cos-segments-page">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Segments', 'yoohw-customer-intelligence' ) . '</h1>';
		echo '<p>' . esc_html__( 'Create customer groups for filters, operations, and reporting.', 'yoohw-customer-intelligence' ) . '</p>';

		if ( ! empty( $_GET['yoohw_segment_error'] ) ) {
			$error = sanitize_key( wp_unslash( $_GET['yoohw_segment_error'] ) );

			$messages = array(
				'missing_name'   => __( 'Please enter a segment name.', 'yoohw-customer-intelligence' ),
				'duplicate_name' => __( 'A segment with this name already exists.', 'yoohw-customer-intelligence' ),
			);

			echo '<div class="notice notice-error is-dismissible"><p>';
			echo esc_html( $messages[ $error ] ?? __( 'Segment could not be saved.', 'yoohw-customer-intelligence' ) );
			echo '</p></div>';
		}

		if ( isset( $_GET['yoohw_segment_created'] ) ) {
			if ( absint( wp_unslash( $_GET['yoohw_segment_created'] ) ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>';
				echo esc_html__( 'Segment created successfully.', 'yoohw-customer-intelligence' );
				echo '</p></div>';
			} else {
				echo '<div class="notice notice-error is-dismissible"><p>';
				echo esc_html__( 'Segment could not be created.', 'yoohw-customer-intelligence' );
				echo '</p></div>';
			}
		}

		if ( isset( $_GET['yoohw_segment_deleted'] ) ) {
			if ( absint( wp_unslash( $_GET['yoohw_segment_deleted'] ) ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>';
				echo esc_html__( 'Segment deleted successfully.', 'yoohw-customer-intelligence' );
				echo '</p></div>';
			} else {
				echo '<div class="notice notice-error is-dismissible"><p>';
				echo esc_html__( 'Segment could not be deleted.', 'yoohw-customer-intelligence' );
				echo '</p></div>';
			}
		}

		if ( isset( $_GET['yoohw_segments_bulk_deleted'] ) ) {
			$deleted = absint( wp_unslash( $_GET['yoohw_segments_bulk_deleted'] ) );
			$blocked = isset( $_GET['yoohw_segments_bulk_blocked'] ) ? absint( wp_unslash( $_GET['yoohw_segments_bulk_blocked'] ) ) : 0;

			if ( $deleted > 0 ) {
				echo '<div class="notice notice-success is-dismissible"><p>';
				printf(
					/* translators: %s: number of segments deleted. */
					esc_html__( '%s segments deleted.', 'yoohw-customer-intelligence' ),
					esc_html( number_format_i18n( $deleted ) )
				);
				echo '</p></div>';
			}

			if ( $blocked > 0 ) {
				echo '<div class="notice notice-warning is-dismissible"><p>';
				printf(
					/* translators: %s: number of assigned segments skipped during bulk deletion. */
					esc_html__( '%s assigned segments were skipped. Remove assignments or use the individual force-delete action.', 'yoohw-customer-intelligence' ),
					esc_html( number_format_i18n( $blocked ) )
				);
				echo '</p></div>';
			}
		}

		if ( ! empty( $_GET['yoohw_segments_bulk_missing'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>';
			echo esc_html__( 'Please select at least one segment before applying a bulk action.', 'yoohw-customer-intelligence' );
			echo '</p></div>';
		}

		if ( ! empty( $_GET['yoohw_segment_delete_block'] ) ) {
			$segment_id = absint( wp_unslash( $_GET['yoohw_segment_delete_block'] ) );
			$count      = isset( $_GET['segment_customer_count'] ) ? absint( wp_unslash( $_GET['segment_customer_count'] ) ) : 0;

			$force_url = wp_nonce_url(
				add_query_arg(
					array(
						'action'     => 'yoohw_cos_delete_segment',
						'segment_id' => $segment_id,
						'force'      => 1,
					),
					admin_url( 'admin-post.php' )
				),
				'yoohw_cos_delete_segment'
			);

			echo '<div class="notice notice-warning"><p>';
			printf(
				/* translators: %s: number of customers assigned to this segment. */
				esc_html__( 'This segment is assigned to %s customers. Delete it anyway?', 'yoohw-customer-intelligence' ),
				esc_html( number_format_i18n( $count ) )
			);
			echo ' <a class="button button-small button-link-delete" href="' . esc_url( $force_url ) . '" data-yoohw-cos-confirm="' . esc_attr__( 'This will remove the segment from all assigned customers. Continue?', 'yoohw-customer-intelligence' ) . '">';
			echo esc_html__( 'Force delete segment', 'yoohw-customer-intelligence' );
			echo '</a>';
			echo '</p></div>';
		}

		if ( isset( $_GET['yoohw_segment_updated'] ) ) {
			if ( absint( wp_unslash( $_GET['yoohw_segment_updated'] ) ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>';
				echo esc_html__( 'Segment updated successfully.', 'yoohw-customer-intelligence' );
				echo '</p></div>';
			} else {
				echo '<div class="notice notice-error is-dismissible"><p>';
				echo esc_html__( 'Segment could not be updated.', 'yoohw-customer-intelligence' );
				echo '</p></div>';
			}
		}

		$editing_segment = array();

		if ( ! empty( $_GET['edit_segment'] ) ) {
			$editing_segment = YoOhw_COS_Segments::get_segment(
				absint( wp_unslash( $_GET['edit_segment'] ) )
			);
		}

		echo '<div id="col-container" class="wp-clearfix yoohw-cos-term-layout">';

		echo '<div id="col-left">';
		echo '<div class="col-wrap">';
		self::render_segment_form( $editing_segment );
		echo '</div>';
		echo '</div>';

		echo '<div id="col-right">';
		echo '<div class="col-wrap">';
		echo '<form method="post">';
		echo '<input type="hidden" name="page" value="yoohw-customer-intelligence-segments" />';
		$list_table->search_box( __( 'Search segments', 'yoohw-customer-intelligence' ), 'yoohw-cos-segments' );
		$list_table->display();
		echo '</form>';
		echo '</div>';
		echo '</div>';

		echo '</div>';

		echo '</div>';
	}

	private static function maybe_handle_segments_bulk_action(): void {
		if ( ! self::is_post_request() ) {
			return;
		}

		$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';

		if ( '-1' === $action || '' === $action ) {
			$action = isset( $_POST['action2'] ) ? sanitize_key( wp_unslash( $_POST['action2'] ) ) : '';
		}

		if ( 'delete' !== $action ) {
			return;
		}

		check_admin_referer( 'bulk-yoohw_cos_segments' );

		$segment_ids = isset( $_POST['segment_ids'] ) && is_array( $_POST['segment_ids'] )
			? array_map( 'absint', wp_unslash( $_POST['segment_ids'] ) )
			: array();

		if ( empty( array_filter( $segment_ids ) ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'                        => 'yoohw-customer-intelligence-segments',
						'yoohw_segments_bulk_missing' => 1,
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$deleted = 0;
		$blocked = 0;

		foreach ( array_filter( $segment_ids ) as $segment_id ) {
			if ( ! YoOhw_COS_Segments::segment_exists( $segment_id ) ) {
				continue;
			}

			if ( YoOhw_COS_Segments::get_segment_customer_count( $segment_id ) > 0 ) {
				$blocked++;
				continue;
			}

			if ( YoOhw_COS_Segments::delete_segment( $segment_id, false ) ) {
				$deleted++;
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                         => 'yoohw-customer-intelligence-segments',
					'yoohw_segments_bulk_deleted'  => $deleted,
					'yoohw_segments_bulk_blocked'  => $blocked,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private static function render_segment_form( array $segment = array() ): void {
		$is_editing = ! empty( $segment['id'] );

		echo '<div class="form-wrap">';
		echo '<h2>';
		echo $is_editing
			? esc_html__( 'Edit segment', 'yoohw-customer-intelligence' )
			: esc_html__( 'Add new segment', 'yoohw-customer-intelligence' );
		echo '</h2>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';

		if ( $is_editing ) {
			echo '<input type="hidden" name="action" value="yoohw_cos_update_segment" />';
			echo '<input type="hidden" name="segment_id" value="' . esc_attr( absint( $segment['id'] ) ) . '" />';
			wp_nonce_field( 'yoohw_cos_update_segment' );
		} else {
			echo '<input type="hidden" name="action" value="yoohw_cos_create_segment" />';
			wp_nonce_field( 'yoohw_cos_create_segment' );
		}

		echo '<div class="form-field form-required term-name-wrap">';
		echo '<label for="yoohw_cos_segment_name">' . esc_html__( 'Name', 'yoohw-customer-intelligence' ) . '</label>';
		echo '<input type="text" id="yoohw_cos_segment_name" name="segment_name" value="' . esc_attr( $segment['name'] ?? '' ) . '" placeholder="' . esc_attr__( 'High-value recovery, wholesale, repeat buyers...', 'yoohw-customer-intelligence' ) . '" required />';
		echo '<p>' . esc_html__( 'The name is how the segment appears on customer profiles and customer lists.', 'yoohw-customer-intelligence' ) . '</p>';
		echo '</div>';

		echo '<div class="form-field term-description-wrap">';
		echo '<label for="yoohw_cos_segment_description">' . esc_html__( 'Description', 'yoohw-customer-intelligence' ) . '</label>';
		echo '<textarea id="yoohw_cos_segment_description" name="segment_description" rows="5" placeholder="' . esc_attr__( 'Describe what this segment is used for...', 'yoohw-customer-intelligence' ) . '">' . esc_textarea( $segment['description'] ?? '' ) . '</textarea>';
		echo '<p>' . esc_html__( 'Descriptions are shown internally only.', 'yoohw-customer-intelligence' ) . '</p>';
		echo '</div>';

		submit_button(
			$is_editing
				? __( 'Update segment', 'yoohw-customer-intelligence' )
				: __( 'Add new segment', 'yoohw-customer-intelligence' ),
			'primary',
			'submit',
			false
		);

		if ( $is_editing ) {
			echo ' <a class="button" href="' . esc_url( admin_url( 'admin.php?page=yoohw-customer-intelligence-segments' ) ) . '">';
			echo esc_html__( 'Cancel', 'yoohw-customer-intelligence' );
			echo '</a>';
		}

		echo '</form>';
		echo '</div>';
	}

	public static function render_activity_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'yoohw-customer-intelligence' ) );
		}

		$list_table = new YoOhw_COS_Activity_List();
		$list_table->prepare_items();

		echo '<div class="wrap yoohw-cos-admin yoohw-cos-activity-page">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Activity', 'yoohw-customer-intelligence' ) . '</h1>';
		echo '<p>' . esc_html__( 'Review order sync, notes, tags, segments, and task events.', 'yoohw-customer-intelligence' ) . '</p>';

		$list_table->views();

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="yoohw-customer-intelligence-activity" />';

		if ( ! empty( $_GET['customer_id'] ) ) {
			echo '<input type="hidden" name="customer_id" value="' . esc_attr( absint( wp_unslash( $_GET['customer_id'] ) ) ) . '" />';
		}

		$list_table->search_box( __( 'Search activity', 'yoohw-customer-intelligence' ), 'yoohw-cos-activity' );
		$list_table->display();
		echo '</form>';

		echo '</div>';
	}

	public static function render_settings_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'yoohw-customer-intelligence' ) );
		}

		echo '<div class="wrap yoohw-cos-admin yoohw-cos-settings-page">';
		echo '<h1>' . esc_html__( 'Settings', 'yoohw-customer-intelligence' ) . '</h1>';

		$stats      = YoOhw_COS_Customers::get_stats();
		$sync_state = self::get_sync_state();
		$readiness  = self::get_setup_readiness( $sync_state );

		self::render_setup_panel( $readiness, $sync_state, $stats );

		if ( isset( $_GET['yoohw_cos_processed'] ) ) {
			$processed = absint( wp_unslash( $_GET['yoohw_cos_processed'] ) );
			$has_more  = ! empty( $_GET['yoohw_cos_has_more'] );

			echo '<div class="notice notice-success is-dismissible"><p>';
			printf(
				/* translators: %s: number of orders processed by customer sync. */
				esc_html__( 'Customer sync completed. %s orders processed in this batch.', 'yoohw-customer-intelligence' ),
				esc_html( number_format_i18n( $processed ) )
			);
			echo '</p></div>';

			if ( ! $has_more ) {
				echo '<div class="notice notice-info is-dismissible"><p>';
				echo esc_html__( 'No more orders found. Customer sync appears to be complete.', 'yoohw-customer-intelligence' );
				echo '</p></div>';
			}
		}

		if ( ! empty( $_GET['yoohw_cos_reset'] ) ) {
			echo '<div class="notice notice-warning is-dismissible"><p>';
			echo esc_html__( 'Customer data has been reset.', 'yoohw-customer-intelligence' );
			echo '</p></div>';
		}

		if ( isset( $_GET['yoohw_cos_recalculated'] ) ) {
			$updated          = absint( wp_unslash( $_GET['yoohw_cos_recalculated'] ) );
			$recalculate_more = ! empty( $_GET['yoohw_cos_recalculate_more'] );

			echo '<div class="notice notice-success is-dismissible"><p>';
			printf(
				/* translators: %s: number of customers updated by intelligence recalculation. */
				esc_html__( 'Customer intelligence recalculated. %s customers updated in this batch.', 'yoohw-customer-intelligence' ),
				esc_html( number_format_i18n( $updated ) )
			);
			echo '</p></div>';

			if ( ! $recalculate_more ) {
				echo '<div class="notice notice-info is-dismissible"><p>';
				echo esc_html__( 'No more customers found. Intelligence recalculation appears to be complete.', 'yoohw-customer-intelligence' );
				echo '</p></div>';
			}
		}

		if ( isset( $_GET['yoohw_cos_backfilled'] ) ) {
			$updated = absint( wp_unslash( $_GET['yoohw_cos_backfilled'] ) );
			$more    = ! empty( $_GET['yoohw_cos_backfill_more'] );

			echo '<div class="notice notice-success is-dismissible"><p>';
			printf(
				/* translators: %s: number of customers updated by first-order backfill. */
				esc_html__( 'First order data backfilled. %s customers updated in this batch.', 'yoohw-customer-intelligence' ),
				esc_html( number_format_i18n( $updated ) )
			);
			echo '</p></div>';

			if ( ! $more ) {
				echo '<div class="notice notice-info is-dismissible"><p>';
				echo esc_html__( 'No more customers found. First order backfill appears to be complete.', 'yoohw-customer-intelligence' );
				echo '</p></div>';
			}
		}

		$blacklist_core_active    = self::is_blacklist_manager_integration_active();
		$blacklist_premium_active = self::is_blacklist_manager_premium_integration_active();

		if ( $blacklist_core_active && isset( $_GET['yoohw_cos_blacklist_synced'] ) ) {
			$created = absint( wp_unslash( $_GET['yoohw_cos_blacklist_synced'] ) );
			$scanned = isset( $_GET['yoohw_cos_blacklist_scanned'] ) ? absint( wp_unslash( $_GET['yoohw_cos_blacklist_scanned'] ) ) : 0;
			$skipped = isset( $_GET['yoohw_cos_blacklist_skipped'] ) ? absint( wp_unslash( $_GET['yoohw_cos_blacklist_skipped'] ) ) : 0;
			$stage   = isset( $_GET['yoohw_cos_blacklist_batch_stage'] ) ? sanitize_key( wp_unslash( $_GET['yoohw_cos_blacklist_batch_stage'] ) ) : 'core';
			$more    = ! empty( $_GET['yoohw_cos_blacklist_sync_more'] );

			echo '<div class="notice notice-success is-dismissible"><p>';
			printf(
				/* translators: 1: created event count, 2: scanned source row count, 3: skipped source row count, 4: current backfill stage. */
				esc_html__( 'Blacklist Manager signals synced. %1$s events created from %2$s scanned rows; %3$s rows skipped or already synced. Stage: %4$s.', 'yoohw-customer-intelligence' ),
				esc_html( number_format_i18n( $created ) ),
				esc_html( number_format_i18n( $scanned ) ),
				esc_html( number_format_i18n( $skipped ) ),
				esc_html( self::format_blacklist_sync_stage_label( $stage ) )
			);
			echo '</p></div>';

			if ( ! $more ) {
				echo '<div class="notice notice-info is-dismissible"><p>';
				echo esc_html__( 'No more Blacklist Manager rows found. Signal sync appears to be complete.', 'yoohw-customer-intelligence' );
				echo '</p></div>';
			}
		}

		if ( ! empty( $_GET['yoohw_cos_scoring_settings_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>';
			echo esc_html__( 'Customer scoring settings saved.', 'yoohw-customer-intelligence' );
			echo ' <a href="' . esc_url( admin_url( 'admin.php?page=yoohw-customer-intelligence-settings#yoohw-cos-recalculate-intelligence' ) ) . '">' . esc_html__( 'Recalculate intelligence', 'yoohw-customer-intelligence' ) . '</a>';
			echo '</p></div>';
		}

		if ( self::is_loyalty_integration_active() && ! empty( $_GET['yoohw_cos_loyalty_task_automation_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>';
			echo esc_html__( 'Loyalty task automation settings saved.', 'yoohw-customer-intelligence' );
			echo '</p></div>';
		}

		echo '<div class="postbox yoohw-cos-operations-panel">';
		echo '<div class="postbox-header"><h2 class="hndle">' . esc_html__( 'Operations', 'yoohw-customer-intelligence' ) . '</h2></div>';
		echo '<div class="inside">';
		echo '<div id="yoohw-cos-sync-center" class="yoohw-cos-operation yoohw-cos-operation--primary" data-yoohw-cos-sync-center>';
		echo '<div class="yoohw-cos-operation__body">';
		echo '<h3>' . esc_html__( 'Sync center', 'yoohw-customer-intelligence' ) . '</h3>';

		$query_next_page = isset( $_GET['yoohw_cos_next_page'] ) ? absint( wp_unslash( $_GET['yoohw_cos_next_page'] ) ) : 1;
		$query_has_more  = ! empty( $_GET['yoohw_cos_has_more'] );
		$stored_has_more = ! empty( $sync_state['has_more'] );
		$has_more        = $query_has_more || $stored_has_more;
		$next_page       = $query_has_more ? $query_next_page : absint( $sync_state['next_page'] ?? 1 );
		$next_page       = $has_more ? max( 1, $next_page ) : 1;
		$sync_auto_submit = $query_has_more && ! empty( $_GET['yoohw_cos_auto_sync'] );
		$sync_percent    = absint( $sync_state['percent'] ?? 0 );

		if ( 'completed' === $sync_state['status'] && empty( $sync_state['has_more'] ) && ! empty( $sync_state['last_run_at'] ) ) {
			$sync_percent = 100;
		}

		self::render_sync_center_status( $sync_state, $has_more, $next_page, $sync_percent );

		echo '<form class="yoohw-cos-sync-form yoohw-cos-operation-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-yoohw-cos-ajax-sync="1" data-yoohw-cos-ajax-action="yoohw_cos_ajax_sync_customers" data-yoohw-cos-sync-page-field="sync_page" data-yoohw-cos-sync-running-text="' . esc_attr__( 'Syncing orders...', 'yoohw-customer-intelligence' ) . '" data-yoohw-cos-sync-complete-text="' . esc_attr__( 'Sync complete.', 'yoohw-customer-intelligence' ) . '"' . ( $sync_auto_submit ? ' data-yoohw-cos-auto-submit="1"' : '' ) . '>';
		echo '<input type="hidden" name="action" value="yoohw_cos_sync_customers" />';
		echo '<input type="hidden" name="sync_page" value="' . esc_attr( $has_more ? $next_page : 1 ) . '" />';
		echo '<input type="hidden" name="auto_sync" value="1" />';

		wp_nonce_field( 'yoohw_cos_sync_customers' );

		submit_button(
			$has_more
				? __( 'Continue sync', 'yoohw-customer-intelligence' )
				: __( 'Sync existing orders', 'yoohw-customer-intelligence' ),
			'primary',
			'submit',
			false
		);
		echo '<span class="spinner" data-yoohw-cos-sync-spinner></span>';

		echo '</form>';
		echo '</div>';
		echo '</div>';

		echo '<div class="yoohw-cos-operation-group">';
		echo '<h3>' . esc_html__( 'Maintenance', 'yoohw-customer-intelligence' ) . '</h3>';
		echo '<div class="yoohw-cos-operation-list">';

		$recalculate_next = isset( $_GET['yoohw_cos_recalculate_next'] ) ? absint( wp_unslash( $_GET['yoohw_cos_recalculate_next'] ) ) : 1;
		$recalculate_more = ! empty( $_GET['yoohw_cos_recalculate_more'] );
		$recalculate_auto_submit = $recalculate_more && ( ! empty( $_GET['yoohw_cos_recalculate_auto'] ) || isset( $_GET['yoohw_cos_recalculated'] ) );

		echo '<div id="yoohw-cos-recalculate-intelligence" class="yoohw-cos-operation-row" data-yoohw-cos-sync-container>';
		echo '<div class="yoohw-cos-operation-row__content">';
		echo '<h4>' . esc_html__( 'Recalculate intelligence', 'yoohw-customer-intelligence' ) . '</h4>';
		echo '<p>' . esc_html__( 'Refresh customer status, lifecycle, value tier, risk, and trust scores for existing profiles.', 'yoohw-customer-intelligence' ) . '</p>';
		self::render_operation_progress( __( 'Ready to recalculate.', 'yoohw-customer-intelligence' ) );
		echo '</div>';
		echo '<form class="yoohw-cos-recalculate-form yoohw-cos-operation-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-yoohw-cos-ajax-sync="1" data-yoohw-cos-ajax-action="yoohw_cos_ajax_recalculate_intelligence" data-yoohw-cos-sync-page-field="recalculate_page" data-yoohw-cos-sync-running-text="' . esc_attr__( 'Recalculating intelligence...', 'yoohw-customer-intelligence' ) . '" data-yoohw-cos-sync-complete-text="' . esc_attr__( 'Recalculation complete.', 'yoohw-customer-intelligence' ) . '"' . ( $recalculate_auto_submit ? ' data-yoohw-cos-auto-submit="1"' : '' ) . '>';
		echo '<input type="hidden" name="action" value="yoohw_cos_recalculate_intelligence" />';
		echo '<input type="hidden" name="recalculate_page" value="' . esc_attr( $recalculate_more ? $recalculate_next : 1 ) . '" />';
		echo '<input type="hidden" name="auto_recalculate" value="1" />';
		wp_nonce_field( 'yoohw_cos_recalculate_intelligence' );

		submit_button(
			$recalculate_more
				? __( 'Continue recalculation', 'yoohw-customer-intelligence' )
				: __( 'Recalculate intelligence', 'yoohw-customer-intelligence' ),
			'secondary',
			'submit',
			false
		);
		echo '<span class="spinner" data-yoohw-cos-sync-spinner></span>';

		echo '</form>';
		echo '</div>';

		if ( $blacklist_core_active ) {
			$blacklist_next = isset( $_GET['yoohw_cos_blacklist_sync_next'] ) ? absint( wp_unslash( $_GET['yoohw_cos_blacklist_sync_next'] ) ) : 1;
			$blacklist_more = ! empty( $_GET['yoohw_cos_blacklist_sync_more'] );
			$blacklist_stage = isset( $_GET['yoohw_cos_blacklist_sync_stage'] ) ? sanitize_key( wp_unslash( $_GET['yoohw_cos_blacklist_sync_stage'] ) ) : 'core';
			$blacklist_auto_submit = $blacklist_more && ( ! empty( $_GET['yoohw_cos_blacklist_sync_auto'] ) || isset( $_GET['yoohw_cos_blacklist_synced'] ) );
			$blacklist_description = $blacklist_premium_active
				? __( 'Backfill core blacklist signals, then premium risk signals from the active Premium license.', 'yoohw-customer-intelligence' )
				: __( 'Backfill core Blacklist Manager signals.', 'yoohw-customer-intelligence' );

			echo '<div class="yoohw-cos-operation-row" data-yoohw-cos-sync-container>';
			echo '<div class="yoohw-cos-operation-row__content">';
			echo '<h4>' . esc_html__( 'Sync Blacklist Manager signals', 'yoohw-customer-intelligence' ) . '</h4>';
			echo '<p>' . esc_html( $blacklist_description ) . '</p>';
			self::render_operation_progress( __( 'Ready to sync signals.', 'yoohw-customer-intelligence' ) );
			echo '</div>';
			echo '<form class="yoohw-cos-blacklist-sync-form yoohw-cos-operation-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-yoohw-cos-ajax-sync="1" data-yoohw-cos-ajax-action="yoohw_cos_ajax_sync_blacklist_signals" data-yoohw-cos-sync-page-field="blacklist_sync_page" data-yoohw-cos-sync-stage-field="blacklist_sync_stage" data-yoohw-cos-sync-running-text="' . esc_attr__( 'Syncing Blacklist Manager signals...', 'yoohw-customer-intelligence' ) . '" data-yoohw-cos-sync-complete-text="' . esc_attr__( 'Signal sync complete.', 'yoohw-customer-intelligence' ) . '"' . ( $blacklist_auto_submit ? ' data-yoohw-cos-auto-submit="1"' : '' ) . '>';
			echo '<input type="hidden" name="action" value="yoohw_cos_sync_blacklist_signals" />';
			echo '<input type="hidden" name="blacklist_sync_page" value="' . esc_attr( $blacklist_more ? $blacklist_next : 1 ) . '" />';
			echo '<input type="hidden" name="blacklist_sync_stage" value="' . esc_attr( $blacklist_more ? $blacklist_stage : 'core' ) . '" />';
			echo '<input type="hidden" name="auto_blacklist_sync" value="1" />';
			wp_nonce_field( 'yoohw_cos_sync_blacklist_signals' );

			submit_button(
				$blacklist_more
					? __( 'Continue signal sync', 'yoohw-customer-intelligence' )
					: __( 'Sync Blacklist signals', 'yoohw-customer-intelligence' ),
				'secondary',
				'submit',
				false
			);
			echo '<span class="spinner" data-yoohw-cos-sync-spinner></span>';

			echo '</form>';
			echo '</div>';
		}

		$backfill_next = isset( $_GET['yoohw_cos_backfill_next'] ) ? absint( wp_unslash( $_GET['yoohw_cos_backfill_next'] ) ) : 1;
		$backfill_more = ! empty( $_GET['yoohw_cos_backfill_more'] );
		$backfill_auto_submit = $backfill_more && ( ! empty( $_GET['yoohw_cos_backfill_auto'] ) || isset( $_GET['yoohw_cos_backfilled'] ) );

		echo '<div class="yoohw-cos-operation-row" data-yoohw-cos-sync-container>';
		echo '<div class="yoohw-cos-operation-row__content">';
		echo '<h4>' . esc_html__( 'Backfill first order data', 'yoohw-customer-intelligence' ) . '</h4>';
		echo '<p>' . esc_html__( 'Populate first order ID and first order date for customer profiles that are missing acquisition data.', 'yoohw-customer-intelligence' ) . '</p>';
		self::render_operation_progress( __( 'Ready to backfill.', 'yoohw-customer-intelligence' ) );
		echo '</div>';
		echo '<form class="yoohw-cos-backfill-form yoohw-cos-operation-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-yoohw-cos-ajax-sync="1" data-yoohw-cos-ajax-action="yoohw_cos_ajax_backfill_first_orders" data-yoohw-cos-sync-page-field="backfill_page" data-yoohw-cos-sync-running-text="' . esc_attr__( 'Backfilling first order data...', 'yoohw-customer-intelligence' ) . '" data-yoohw-cos-sync-complete-text="' . esc_attr__( 'First order backfill complete.', 'yoohw-customer-intelligence' ) . '"' . ( $backfill_auto_submit ? ' data-yoohw-cos-auto-submit="1"' : '' ) . '>';
		echo '<input type="hidden" name="action" value="yoohw_cos_backfill_first_orders" />';
		echo '<input type="hidden" name="backfill_page" value="' . esc_attr( $backfill_more ? $backfill_next : 1 ) . '" />';
		echo '<input type="hidden" name="auto_backfill" value="1" />';
		wp_nonce_field( 'yoohw_cos_backfill_first_orders' );

		submit_button(
			$backfill_more
				? __( 'Continue backfill', 'yoohw-customer-intelligence' )
				: __( 'Backfill first order data', 'yoohw-customer-intelligence' ),
			'secondary',
			'submit',
			false
		);
		echo '<span class="spinner" data-yoohw-cos-sync-spinner></span>';

		echo '</form>';
		echo '</div>';

		echo '<div class="yoohw-cos-operation-row yoohw-cos-operation-row--danger">';
		echo '<div class="yoohw-cos-operation-row__content">';
		echo '<h4>' . esc_html__( 'Reset customer data', 'yoohw-customer-intelligence' ) . '</h4>';
		echo '<p>' . esc_html__( 'Clear normalized customer data. WooCommerce orders and WordPress users are not deleted.', 'yoohw-customer-intelligence' ) . '</p>';
		echo '</div>';
		echo '<form class="yoohw-cos-operation-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-yoohw-cos-confirm="' . esc_attr__( 'Are you sure you want to reset customer data?', 'yoohw-customer-intelligence' ) . '">';
		echo '<input type="hidden" name="action" value="yoohw_cos_reset_data" />';
		wp_nonce_field( 'yoohw_cos_reset_data' );

		submit_button(
			__( 'Reset data', 'yoohw-customer-intelligence' ),
			'delete',
			'submit',
			false
		);

		echo '</form>';
		echo '</div>';
		echo '</div>';
		echo '</div>';
		echo '</div>';
		echo '</div>';

		self::render_customer_scoring_settings_panel();
		self::render_loyalty_task_automation_panel();

		echo '</div>';
	}

	private static function render_customer_scoring_settings_panel(): void {
		if (
			! class_exists( 'YoOhw_COS_Intelligence' )
			|| ! is_callable( array( 'YoOhw_COS_Intelligence', 'get_scoring_settings' ) )
		) {
			return;
		}

		$settings = YoOhw_COS_Intelligence::get_scoring_settings();
		$tiers    = $settings['value_tiers'];
		$lifecycle = $settings['lifecycle'];
		$status   = $settings['customer_status'];

		echo '<div class="postbox yoohw-cos-settings-panel yoohw-cos-scoring-settings-panel">';
		echo '<div class="postbox-header"><h2 class="hndle">' . esc_html__( 'Customer scoring', 'yoohw-customer-intelligence' ) . '</h2></div>';
		echo '<div class="inside">';
		echo '<p>' . esc_html__( 'Configure the thresholds used to classify customer status, value tier, and lifecycle stage.', 'yoohw-customer-intelligence' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="yoohw_cos_save_scoring_settings" />';

		wp_nonce_field( 'yoohw_cos_save_scoring_settings' );

		echo '<h3>' . esc_html__( 'Value tiers', 'yoohw-customer-intelligence' ) . '</h3>';
		echo '<table class="widefat striped yoohw-cos-scoring-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Tier', 'yoohw-customer-intelligence' ) . '</th>';
		echo '<th>' . esc_html__( 'Minimum spent', 'yoohw-customer-intelligence' ) . '</th>';
		echo '<th>' . esc_html__( 'Minimum orders', 'yoohw-customer-intelligence' ) . '</th>';
		echo '</tr></thead><tbody>';
		self::render_scoring_threshold_row(
			__( 'High value', 'yoohw-customer-intelligence' ),
			self::render_scoring_input( 'value_tiers', 'high_value_spent', $tiers['high_value_spent'], '0.01' ),
			self::render_scoring_input( 'value_tiers', 'high_value_orders', $tiers['high_value_orders'] )
		);
		self::render_scoring_threshold_row(
			__( 'Very high value', 'yoohw-customer-intelligence' ),
			self::render_scoring_input( 'value_tiers', 'very_high_value_spent', $tiers['very_high_value_spent'], '0.01' ),
			self::render_scoring_input( 'value_tiers', 'very_high_value_orders', $tiers['very_high_value_orders'] )
		);
		self::render_scoring_threshold_row(
			__( 'Top customer', 'yoohw-customer-intelligence' ),
			self::render_scoring_input( 'value_tiers', 'top_customer_spent', $tiers['top_customer_spent'], '0.01' ),
			self::render_scoring_input( 'value_tiers', 'top_customer_orders', $tiers['top_customer_orders'] )
		);
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Lifecycle stage', 'yoohw-customer-intelligence' ) . '</h3>';
		echo '<table class="form-table yoohw-cos-scoring-form-table" role="presentation"><tbody>';
		self::render_scoring_setting_row( __( 'Repeat customer orders', 'yoohw-customer-intelligence' ), self::render_scoring_input( 'lifecycle', 'repeat_orders', $lifecycle['repeat_orders'] ) );
		self::render_scoring_setting_row( __( 'Loyal customer spent', 'yoohw-customer-intelligence' ), self::render_scoring_input( 'lifecycle', 'loyal_spent', $lifecycle['loyal_spent'], '0.01' ) );
		self::render_scoring_setting_row( __( 'Loyal customer orders', 'yoohw-customer-intelligence' ), self::render_scoring_input( 'lifecycle', 'loyal_orders', $lifecycle['loyal_orders'] ) );
		self::render_scoring_setting_row( __( 'Top lifecycle spent', 'yoohw-customer-intelligence' ), self::render_scoring_input( 'lifecycle', 'top_customer_spent', $lifecycle['top_customer_spent'], '0.01' ) );
		self::render_scoring_setting_row( __( 'Top lifecycle orders', 'yoohw-customer-intelligence' ), self::render_scoring_input( 'lifecycle', 'top_customer_orders', $lifecycle['top_customer_orders'] ) );
		self::render_scoring_setting_row( __( 'Dormant after days', 'yoohw-customer-intelligence' ), self::render_scoring_input( 'lifecycle', 'dormant_days', $lifecycle['dormant_days'] ) );
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Customer status', 'yoohw-customer-intelligence' ) . '</h3>';
		echo '<table class="form-table yoohw-cos-scoring-form-table" role="presentation"><tbody>';
		self::render_scoring_setting_row( __( 'New through order count', 'yoohw-customer-intelligence' ), self::render_scoring_input( 'customer_status', 'new_max_orders', $status['new_max_orders'], '1', '0' ) );
		self::render_scoring_setting_row( __( 'VIP spent threshold', 'yoohw-customer-intelligence' ), self::render_scoring_input( 'customer_status', 'vip_spent', $status['vip_spent'], '0.01' ) );
		self::render_scoring_setting_row( __( 'VIP order threshold', 'yoohw-customer-intelligence' ), self::render_scoring_input( 'customer_status', 'vip_orders', $status['vip_orders'] ) );
		self::render_scoring_setting_row( __( 'At risk after days', 'yoohw-customer-intelligence' ), self::render_scoring_input( 'customer_status', 'at_risk_days', $status['at_risk_days'] ) );
		self::render_scoring_setting_row( __( 'Inactive after days', 'yoohw-customer-intelligence' ), self::render_scoring_input( 'customer_status', 'inactive_days', $status['inactive_days'] ) );
		echo '</tbody></table>';

		echo '<p class="description">' . esc_html__( 'After changing thresholds, run Recalculate intelligence so existing customer profiles use the new scoring rules.', 'yoohw-customer-intelligence' ) . '</p>';

		submit_button( __( 'Save scoring settings', 'yoohw-customer-intelligence' ) );

		echo '</form>';
		echo '</div>';
		echo '</div>';
	}

	private static function render_scoring_threshold_row( string $label, string $spent_input, string $orders_input ): void {
		echo '<tr>';
		echo '<th scope="row">' . esc_html( $label ) . '</th>';
		echo '<td>' . $spent_input . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inputs are built by render_scoring_input().
		echo '<td>' . $orders_input . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inputs are built by render_scoring_input().
		echo '</tr>';
	}

	private static function render_scoring_setting_row( string $label, string $input ): void {
		echo '<tr>';
		echo '<th scope="row">' . esc_html( $label ) . '</th>';
		echo '<td>' . $input . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inputs are built by render_scoring_input().
		echo '</tr>';
	}

	private static function render_scoring_input( string $group, string $key, $value, string $step = '1', string $min = '1' ): string {
		$is_decimal = false !== strpos( $step, '.' );
		$min        = $is_decimal && '1' === $min ? '0' : $min;
		$value_attr = $is_decimal
			? self::format_scoring_amount_input_value( (float) $value )
			: (string) absint( $value );

		return sprintf(
			'<input class="small-text yoohw-cos-scoring-input" type="number" min="%1$s" step="%2$s" name="scoring[%3$s][%4$s]" value="%5$s" />',
			esc_attr( $min ),
			esc_attr( $step ),
			esc_attr( $group ),
			esc_attr( $key ),
			esc_attr( $value_attr )
		);
	}

	private static function format_scoring_amount_input_value( float $value ): string {
		$formatted = rtrim( rtrim( number_format( $value, 2, '.', '' ), '0' ), '.' );

		return '' === $formatted ? '0' : $formatted;
	}

	private static function render_loyalty_task_automation_panel(): void {
		if (
			! class_exists( 'YoOhw_COS_Loyalty_Integration' )
			|| ! is_callable( array( 'YoOhw_COS_Loyalty_Integration', 'is_loyalty_plugin_active' ) )
			|| ! YoOhw_COS_Loyalty_Integration::is_loyalty_plugin_active()
			|| ! is_callable( array( 'YoOhw_COS_Loyalty_Integration', 'get_task_automation_settings' ) )
			|| ! is_callable( array( 'YoOhw_COS_Loyalty_Integration', 'get_configured_loyalty_roles' ) )
		) {
			return;
		}

		$settings = YoOhw_COS_Loyalty_Integration::get_task_automation_settings();
		$roles    = YoOhw_COS_Loyalty_Integration::get_configured_loyalty_roles();
		$assignees = class_exists( 'YoOhw_COS_Tasks' ) && is_callable( array( 'YoOhw_COS_Tasks', 'get_assignable_users' ) )
			? YoOhw_COS_Tasks::get_assignable_users()
			: array();

		echo '<div class="postbox yoohw-cos-settings-panel yoohw-cos-loyalty-task-automation-panel">';
		echo '<div class="postbox-header"><h2 class="hndle">' . esc_html__( 'Loyalty task automation', 'yoohw-customer-intelligence' ) . '</h2></div>';
		echo '<div class="inside">';
		echo '<p>' . esc_html__( 'Create follow-up tasks from loyalty signals such as high-value level changes, large redemptions, and negative balances.', 'yoohw-customer-intelligence' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="yoohw_cos_save_loyalty_task_automation" />';

		wp_nonce_field( 'yoohw_cos_save_loyalty_task_automation' );

		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr>';
		echo '<th scope="row">' . esc_html__( 'Automation', 'yoohw-customer-intelligence' ) . '</th>';
		echo '<td><label><input type="checkbox" name="enabled" value="yes" ' . checked( 'yes', $settings['enabled'] ?? 'no', false ) . ' /> ' . esc_html__( 'Enable loyalty task automation', 'yoohw-customer-intelligence' ) . '</label></td>';
		echo '</tr>';

		echo '<tr>';
		echo '<th scope="row">' . esc_html__( 'Value tier level-up', 'yoohw-customer-intelligence' ) . '</th>';
		echo '<td>';
		echo '<label><input type="checkbox" name="vip_level_up_enabled" value="yes" ' . checked( 'yes', $settings['vip_level_up_enabled'] ?? 'no', false ) . ' /> ' . esc_html__( 'Create a high-priority task when a customer reaches selected loyalty levels', 'yoohw-customer-intelligence' ) . '</label>';
		self::render_loyalty_task_level_select( $roles, (array) ( $settings['vip_levels'] ?? array() ) );
		echo '</td>';
		echo '</tr>';

		echo '<tr>';
		echo '<th scope="row">' . esc_html__( 'Downgrade or reset', 'yoohw-customer-intelligence' ) . '</th>';
		echo '<td><label><input type="checkbox" name="downgrade_enabled" value="yes" ' . checked( 'yes', $settings['downgrade_enabled'] ?? 'no', false ) . ' /> ' . esc_html__( 'Create a task when a loyalty level moves down or is reset', 'yoohw-customer-intelligence' ) . '</label></td>';
		echo '</tr>';

		echo '<tr>';
		echo '<th scope="row">' . esc_html__( 'Large redemption', 'yoohw-customer-intelligence' ) . '</th>';
		echo '<td>';
		echo '<label><input type="checkbox" name="large_redemption_enabled" value="yes" ' . checked( 'yes', $settings['large_redemption_enabled'] ?? 'no', false ) . ' /> ' . esc_html__( 'Create a task when redeemed points reach this threshold', 'yoohw-customer-intelligence' ) . '</label>';
		echo '<br /><input class="small-text" type="number" min="1" step="1" name="large_redemption_points" value="' . esc_attr( absint( $settings['large_redemption_points'] ?? 500 ) ) . '" /> ';
		echo esc_html__( 'points', 'yoohw-customer-intelligence' );
		echo '</td>';
		echo '</tr>';

		echo '<tr>';
		echo '<th scope="row">' . esc_html__( 'Negative balance', 'yoohw-customer-intelligence' ) . '</th>';
		echo '<td><label><input type="checkbox" name="negative_balance_enabled" value="yes" ' . checked( 'yes', $settings['negative_balance_enabled'] ?? 'no', false ) . ' /> ' . esc_html__( 'Create an urgent task when available points become negative', 'yoohw-customer-intelligence' ) . '</label></td>';
		echo '</tr>';

		echo '<tr>';
		echo '<th scope="row">' . esc_html__( 'Dormant with points', 'yoohw-customer-intelligence' ) . '</th>';
		echo '<td>';
		echo '<label><input type="checkbox" name="dormant_points_enabled" value="yes" ' . checked( 'yes', $settings['dormant_points_enabled'] ?? 'no', false ) . ' /> ' . esc_html__( 'Create a task when recalculation finds a dormant registered customer above this points threshold', 'yoohw-customer-intelligence' ) . '</label>';
		echo '<br /><input class="small-text" type="number" min="1" step="1" name="dormant_points_threshold" value="' . esc_attr( absint( $settings['dormant_points_threshold'] ?? 500 ) ) . '" /> ';
		echo esc_html__( 'available points', 'yoohw-customer-intelligence' );
		echo '</td>';
		echo '</tr>';

		echo '<tr>';
		echo '<th scope="row">' . esc_html__( 'Reconciliation issues', 'yoohw-customer-intelligence' ) . '</th>';
		echo '<td>';
		echo '<label><input type="checkbox" name="reconciliation_enabled" value="yes" ' . checked( 'yes', $settings['reconciliation_enabled'] ?? 'no', false ) . ' /> ' . esc_html__( 'Create idempotent tasks when Loyalty points reconciliation finds user-level issues', 'yoohw-customer-intelligence' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Disabled by default because large scans can surface many affected users.', 'yoohw-customer-intelligence' ) . '</p>';
		echo '</td>';
		echo '</tr>';

		echo '<tr>';
		echo '<th scope="row">' . esc_html__( 'Default assignee', 'yoohw-customer-intelligence' ) . '</th>';
		echo '<td>';
		echo '<select name="assigned_user_id" class="wc-enhanced-select" style="min-width: 260px;">';
		echo '<option value="0">' . esc_html__( 'Unassigned', 'yoohw-customer-intelligence' ) . '</option>';

		foreach ( $assignees as $assignee ) {
			$user_id = absint( $assignee->ID ?? 0 );

			if ( $user_id <= 0 ) {
				continue;
			}

			echo '<option value="' . esc_attr( $user_id ) . '" ' . selected( $user_id, absint( $settings['assigned_user_id'] ?? 0 ), false ) . '>' . esc_html( $assignee->display_name ?: sprintf(
				/* translators: %d: user ID. */
				__( 'User #%d', 'yoohw-customer-intelligence' ),
				$user_id
			) ) . '</option>';
		}

		echo '</select>';
		echo '</td>';
		echo '</tr>';

		echo '<tr>';
		echo '<th scope="row">' . esc_html__( 'Due date', 'yoohw-customer-intelligence' ) . '</th>';
		echo '<td><input class="small-text" type="number" min="0" max="30" step="1" name="due_days" value="' . esc_attr( absint( $settings['due_days'] ?? 1 ) ) . '" /> ';
		echo esc_html__( 'days after the signal', 'yoohw-customer-intelligence' );
		echo '</td>';
		echo '</tr>';
		echo '</tbody></table>';

		submit_button( __( 'Save loyalty automation', 'yoohw-customer-intelligence' ) );

		echo '</form>';
		echo '</div>';
		echo '</div>';
	}

	private static function render_loyalty_task_level_select( array $roles, array $selected_roles ): void {
		$roles          = array_values( array_filter( array_map( 'sanitize_key', $roles ) ) );
		$selected_roles = array_values( array_filter( array_map( 'sanitize_key', $selected_roles ) ) );

		if ( empty( $roles ) ) {
			echo '<p class="description">' . esc_html__( 'No loyalty levels are configured yet.', 'yoohw-customer-intelligence' ) . '</p>';
			return;
		}

		echo '<p>';
		echo '<select name="vip_levels[]" class="wc-enhanced-select" multiple="multiple" style="min-width: 320px;">';

		foreach ( $roles as $role ) {
			echo '<option value="' . esc_attr( $role ) . '" ' . selected( in_array( $role, $selected_roles, true ), true, false ) . '>';

			if ( is_callable( array( 'YoOhw_COS_Loyalty_Integration', 'format_role_label' ) ) ) {
				echo esc_html( YoOhw_COS_Loyalty_Integration::format_role_label( $role ) );
			} else {
				echo esc_html( ucwords( str_replace( array( '-', '_' ), ' ', $role ) ) );
			}

			echo '</option>';
		}

		echo '</select>';
		echo '</p>';
		echo '<p class="description">' . esc_html__( 'Use this only for meaningful high-value tiers, not every loyalty level.', 'yoohw-customer-intelligence' ) . '</p>';
	}

	private static function get_sync_state(): array {
		$state = get_option( 'yoohw_cos_sync_state', array() );

		if ( ! is_array( $state ) ) {
			$state = array();
		}

		$status = isset( $state['status'] ) ? sanitize_key( (string) $state['status'] ) : 'not_started';

		if ( ! in_array( $status, array( 'not_started', 'in_progress', 'completed' ), true ) ) {
			$status = 'not_started';
		}

		$sync_order = isset( $state['sync_order'] ) ? sanitize_key( (string) $state['sync_order'] ) : '';

		if (
			YoOhw_COS_Customers::SYNC_ORDER !== $sync_order
			&& ( ! empty( $state['has_more'] ) || 'in_progress' === $status )
		) {
			$status                  = 'not_started';
			$state['has_more']       = 0;
			$state['next_page']      = 1;
			$state['last_page']      = 0;
			$state['last_processed'] = 0;
			$state['last_scanned']   = 0;
		}

		$legacy_last_sync_at = get_option( 'yoohw_cos_last_sync_at', '' );

		return array(
			'status'          => $status,
			'sync_order'      => YoOhw_COS_Customers::SYNC_ORDER === $sync_order ? $sync_order : '',
			'batch_size'      => absint( $state['batch_size'] ?? 200 ),
			'last_page'       => absint( $state['last_page'] ?? 0 ),
			'next_page'       => absint( $state['next_page'] ?? 1 ),
			'last_processed'  => absint( $state['last_processed'] ?? 0 ),
			'last_scanned'    => absint( $state['last_scanned'] ?? 0 ),
			'total_processed' => absint( $state['total_processed'] ?? 0 ),
			'total_scanned'   => absint( $state['total_scanned'] ?? 0 ),
			'total_orders'    => absint( $state['total_orders'] ?? 0 ),
			'percent'         => absint( $state['percent'] ?? 0 ),
			'has_more'        => ! empty( $state['has_more'] ) ? 1 : 0,
			'started_at'      => isset( $state['started_at'] ) ? sanitize_text_field( (string) $state['started_at'] ) : '',
			'last_run_at'     => isset( $state['last_run_at'] ) ? sanitize_text_field( (string) $state['last_run_at'] ) : sanitize_text_field( (string) $legacy_last_sync_at ),
			'completed_at'    => isset( $state['completed_at'] ) ? sanitize_text_field( (string) $state['completed_at'] ) : '',
		);
	}

	private static function get_setup_readiness( array $sync_state ): array {
		$database = self::get_database_status();
		$hpos     = self::get_hpos_status();
		$sync     = self::get_sync_status_summary( $sync_state );

		return array(
			'woocommerce' => array(
				'label'  => ( class_exists( 'WooCommerce' ) || function_exists( 'WC' ) )
					? __( 'Active', 'yoohw-customer-intelligence' )
					: __( 'Inactive', 'yoohw-customer-intelligence' ),
				'type'   => ( class_exists( 'WooCommerce' ) || function_exists( 'WC' ) ) ? 'good' : 'error',
				'detail' => __( 'Required dependency', 'yoohw-customer-intelligence' ),
			),
			'hpos'        => $hpos,
			'database'    => $database,
			'sync'        => $sync,
		);
	}

	private static function get_database_status(): array {
		global $wpdb;

		$missing = array();
		$keys    = YoOhw_COS_Install::expected_table_keys();

		foreach ( $keys as $table_key ) {
			$table = YoOhw_COS_DB::table( $table_key );
			$found = $wpdb->get_var(
				$wpdb->prepare(
					'SHOW TABLES LIKE %s',
					$table
				)
			);

			if ( $found !== $table ) {
				$missing[] = $table_key;
			}
		}

		$total   = count( $keys );
		$present = $total - count( $missing );

		if ( empty( $missing ) ) {
			return array(
				'label'   => __( 'Ready', 'yoohw-customer-intelligence' ),
				'type'    => 'good',
				'detail'  => sprintf(
					/* translators: %1$s: available database tables, %2$s: expected database tables. */
					__( '%1$s/%2$s tables', 'yoohw-customer-intelligence' ),
					number_format_i18n( $present ),
					number_format_i18n( $total )
				),
				'missing' => array(),
			);
		}

		return array(
			'label'   => __( 'Needs repair', 'yoohw-customer-intelligence' ),
			'type'    => 'error',
			'detail'  => sprintf(
				/* translators: %s: comma-separated missing database table keys. */
				__( 'Missing: %s', 'yoohw-customer-intelligence' ),
				implode( ', ', $missing )
			),
			'missing' => $missing,
		);
	}

	private static function get_hpos_status(): array {
		$order_util_class = '\Automattic\WooCommerce\Utilities\OrderUtil';

		if (
			class_exists( $order_util_class )
			&& method_exists( $order_util_class, 'custom_orders_table_usage_is_enabled' )
		) {
			$enabled = (bool) call_user_func( array( $order_util_class, 'custom_orders_table_usage_is_enabled' ) );

			return array(
				'label'  => $enabled
					? __( 'Enabled', 'yoohw-customer-intelligence' )
					: __( 'Legacy storage', 'yoohw-customer-intelligence' ),
				'type'   => $enabled ? 'good' : 'info',
				'detail' => __( 'Compatibility declared', 'yoohw-customer-intelligence' ),
			);
		}

		return array(
			'label'  => __( 'Unknown', 'yoohw-customer-intelligence' ),
			'type'   => 'warning',
			'detail' => __( 'WooCommerce HPOS status API unavailable', 'yoohw-customer-intelligence' ),
		);
	}

	private static function get_sync_status_summary( array $sync_state ): array {
		if ( empty( $sync_state['last_run_at'] ) ) {
			return array(
				'label'  => __( 'Not synced', 'yoohw-customer-intelligence' ),
				'type'   => 'warning',
				'detail' => __( 'Run the first order sync', 'yoohw-customer-intelligence' ),
			);
		}

		if ( ! empty( $sync_state['has_more'] ) || 'in_progress' === $sync_state['status'] ) {
			return array(
				'label'  => __( 'In progress', 'yoohw-customer-intelligence' ),
				'type'   => 'warning',
				'detail' => __( 'More orders are ready to process', 'yoohw-customer-intelligence' ),
			);
		}

		return array(
			'label'  => __( 'Complete', 'yoohw-customer-intelligence' ),
			'type'   => 'good',
			'detail' => __( 'Last sync completed', 'yoohw-customer-intelligence' ),
		);
	}

	private static function render_setup_panel( array $readiness, array $sync_state, array $stats ): void {
		$sync_status = $readiness['sync'];
		$cta_url     = '#yoohw-cos-sync-center';
		$cta_label   = ! empty( $sync_state['has_more'] )
			? __( 'Continue sync', 'yoohw-customer-intelligence' )
			: __( 'Sync existing orders', 'yoohw-customer-intelligence' );

		if ( 'completed' === $sync_state['status'] && empty( $sync_state['has_more'] ) ) {
			$cta_url   = admin_url( 'admin.php?page=yoohw-customer-intelligence' );
			$cta_label = __( 'View customers', 'yoohw-customer-intelligence' );
		}

		echo '<div class="postbox yoohw-cos-setup-panel">';
		echo '<div class="postbox-header"><h2 class="hndle">' . esc_html__( 'Store setup', 'yoohw-customer-intelligence' ) . '</h2></div>';
		echo '<div class="inside">';
		echo '<div class="yoohw-cos-setup-panel__header">';
		echo '<div>';
		echo '<p class="yoohw-cos-setup-panel__lead">' . esc_html__( 'Confirm the store is ready, then sync existing WooCommerce orders into customer profiles.', 'yoohw-customer-intelligence' ) . '</p>';
		echo '</div>';
		echo '<a class="button button-primary" href="' . esc_url( $cta_url ) . '">' . esc_html( $cta_label ) . '</a>';
		echo '</div>';

		echo '<div class="yoohw-cos-readiness-grid">';
		self::render_readiness_item( __( 'WooCommerce', 'yoohw-customer-intelligence' ), $readiness['woocommerce'] );
		self::render_readiness_item( __( 'HPOS', 'yoohw-customer-intelligence' ), $readiness['hpos'] );
		self::render_readiness_item( __( 'Database', 'yoohw-customer-intelligence' ), $readiness['database'] );
		self::render_readiness_item( __( 'Order sync', 'yoohw-customer-intelligence' ), $sync_status );
		echo '</div>';

		if ( empty( $sync_state['last_run_at'] ) && empty( $stats['total_customers'] ) ) {
			echo '<div class="notice notice-warning inline"><p>';
			echo esc_html__( 'No synced customer data found yet. Run the order sync to build the initial customer workspace.', 'yoohw-customer-intelligence' );
			echo '</p></div>';
		} elseif ( ! empty( $sync_state['has_more'] ) ) {
			echo '<div class="notice notice-warning inline"><p>';
			echo esc_html__( 'The previous sync stopped before all orders were processed. Continue syncing from the saved resume point.', 'yoohw-customer-intelligence' );
			echo '</p></div>';
		}

		echo '</div>';
		echo '</div>';
	}

	private static function render_readiness_item( string $label, array $status ): void {
		echo '<div class="yoohw-cos-readiness-item">';
		echo '<div class="yoohw-cos-readiness-item__label">' . esc_html( $label ) . '</div>';
		echo wp_kses_post( self::render_status_pill( (string) ( $status['label'] ?? '' ), (string) ( $status['type'] ?? 'info' ) ) );

		if ( ! empty( $status['detail'] ) ) {
			echo '<div class="yoohw-cos-readiness-item__detail">' . esc_html( (string) $status['detail'] ) . '</div>';
		}

		echo '</div>';
	}

	private static function render_sync_center_status( array $sync_state, bool $has_more, int $next_page, int $sync_percent ): void {
		$summary = self::get_sync_status_summary( $sync_state );
		$sync_percent = min( 100, absint( $sync_percent ) );

		echo '<p>' . esc_html__( 'Import and normalize customer data from existing WooCommerce orders.', 'yoohw-customer-intelligence' ) . '</p>';

		if ( empty( $sync_state['last_run_at'] ) ) {
			echo '<div class="notice notice-warning inline"><p>';
			echo esc_html__( 'Order sync has not run yet. Start sync to create customer profiles from existing WooCommerce orders.', 'yoohw-customer-intelligence' );
			echo '</p></div>';
		} elseif ( $has_more ) {
			echo '<div class="notice notice-warning inline"><p>';
			printf(
				/* translators: %s: next sync page number. */
				esc_html__( 'Sync can resume from page %s.', 'yoohw-customer-intelligence' ),
				esc_html( number_format_i18n( $next_page ) )
			);
			echo '</p></div>';
		}

		$progress_message = empty( $sync_state['last_run_at'] )
			? __( 'Ready to sync.', 'yoohw-customer-intelligence' )
			: ( $has_more ? __( 'Sync is in progress.', 'yoohw-customer-intelligence' ) : __( 'Sync complete.', 'yoohw-customer-intelligence' ) );

		echo '<div class="yoohw-cos-sync-progress" data-yoohw-cos-sync-progress aria-live="polite">';
		echo '<div class="yoohw-cos-progress-header">';
		echo '<span data-yoohw-cos-sync-message>' . esc_html( $progress_message ) . '</span>';
		echo '<strong data-yoohw-cos-sync-percent>' . esc_html( number_format_i18n( $sync_percent ) ) . '%</strong>';
		echo '</div>';
		echo '<div class="yoohw-cos-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . esc_attr( $sync_percent ) . '" data-yoohw-cos-progress-track>';
		echo '<span class="yoohw-cos-progress-bar" data-yoohw-cos-progress-bar style="width:' . esc_attr( $sync_percent ) . '%;"></span>';
		echo '</div>';
		echo '<div class="yoohw-cos-progress-counts">';
		echo '<span>' . esc_html__( 'Orders scanned:', 'yoohw-customer-intelligence' ) . ' <strong class="yoohw-cos-sync-total-scanned">' . esc_html( number_format_i18n( absint( $sync_state['total_scanned'] ) ) ) . '</strong></span>';
		echo '<span>' . esc_html__( 'Total orders:', 'yoohw-customer-intelligence' ) . ' <strong class="yoohw-cos-sync-total-orders">' . ( $sync_state['total_orders'] > 0 ? esc_html( number_format_i18n( absint( $sync_state['total_orders'] ) ) ) : '&mdash;' ) . '</strong></span>';
		echo '<span>' . esc_html__( 'Profiles updated:', 'yoohw-customer-intelligence' ) . ' <strong class="yoohw-cos-sync-total-processed">' . esc_html( number_format_i18n( absint( $sync_state['total_processed'] ) ) ) . '</strong></span>';
		echo '</div>';
		echo '</div>';

		echo '<table class="widefat yoohw-cos-status-table"><tbody>';
		self::render_status_table_row( __( 'Status', 'yoohw-customer-intelligence' ), '<span class="yoohw-cos-sync-status-value">' . self::render_status_pill( (string) $summary['label'], (string) $summary['type'] ) . '</span>' );
		self::render_status_table_row( __( 'Batch size', 'yoohw-customer-intelligence' ), number_format_i18n( absint( $sync_state['batch_size'] ) ) );
		self::render_status_table_row( __( 'Last batch scanned', 'yoohw-customer-intelligence' ), '<span class="yoohw-cos-sync-last-scanned">' . esc_html( number_format_i18n( absint( $sync_state['last_scanned'] ) ) ) . '</span>' );
		self::render_status_table_row( __( 'Orders scanned', 'yoohw-customer-intelligence' ), '<span class="yoohw-cos-sync-total-scanned">' . esc_html( number_format_i18n( absint( $sync_state['total_scanned'] ) ) ) . '</span>' );
		self::render_status_table_row( __( 'Customer profiles updated', 'yoohw-customer-intelligence' ), '<span class="yoohw-cos-sync-total-processed">' . esc_html( number_format_i18n( absint( $sync_state['total_processed'] ) ) ) . '</span>' );
		self::render_status_table_row( __( 'Total orders', 'yoohw-customer-intelligence' ), '<span class="yoohw-cos-sync-total-orders">' . ( $sync_state['total_orders'] > 0 ? esc_html( number_format_i18n( absint( $sync_state['total_orders'] ) ) ) : '&mdash;' ) . '</span>' );
		self::render_status_table_row( __( 'Resume page', 'yoohw-customer-intelligence' ), '<span class="yoohw-cos-sync-resume-page">' . ( $has_more ? esc_html( number_format_i18n( $next_page ) ) : '&mdash;' ) . '</span>' );
		self::render_status_table_row( __( 'Last sync run', 'yoohw-customer-intelligence' ), YoOhw_COS_DB::format_admin_date( $sync_state['last_run_at'], '&mdash;' ) );
		echo '</tbody></table>';
	}

	private static function render_operation_progress( string $message ): void {
		echo '<div class="yoohw-cos-sync-progress yoohw-cos-sync-progress--inline" data-yoohw-cos-sync-progress hidden aria-live="polite">';
		echo '<div class="yoohw-cos-progress-header">';
		echo '<span data-yoohw-cos-sync-message>' . esc_html( $message ) . '</span>';
		echo '<strong data-yoohw-cos-sync-percent>0%</strong>';
		echo '</div>';
		echo '<div class="yoohw-cos-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-yoohw-cos-progress-track>';
		echo '<span class="yoohw-cos-progress-bar" data-yoohw-cos-progress-bar style="width:0%;"></span>';
		echo '</div>';
		echo '<div class="yoohw-cos-progress-counts">';
		echo '<span>' . esc_html__( 'Scanned:', 'yoohw-customer-intelligence' ) . ' <strong class="yoohw-cos-sync-total-scanned">0</strong></span>';
		echo '<span>' . esc_html__( 'Processed:', 'yoohw-customer-intelligence' ) . ' <strong class="yoohw-cos-sync-total-processed">0</strong></span>';
		echo '<span>' . esc_html__( 'Skipped:', 'yoohw-customer-intelligence' ) . ' <strong class="yoohw-cos-sync-total-skipped">0</strong></span>';
		echo '<span>' . esc_html__( 'Total:', 'yoohw-customer-intelligence' ) . ' <strong class="yoohw-cos-sync-total-items">&mdash;</strong></span>';
		echo '<span>' . esc_html__( 'Stage:', 'yoohw-customer-intelligence' ) . ' <strong class="yoohw-cos-sync-stage">&mdash;</strong></span>';
		echo '</div>';
		echo '</div>';
	}

	private static function render_status_table_row( string $label, string $value ): void {
		echo '<tr>';
		echo '<th scope="row">' . esc_html( $label ) . '</th>';
		echo '<td>' . wp_kses_post( $value ) . '</td>';
		echo '</tr>';
	}

	private static function render_status_pill( string $label, string $type ): string {
		$type = sanitize_html_class( $type );

		if ( '' === $type ) {
			$type = 'info';
		}

		return '<span class="yoohw-cos-status-pill yoohw-cos-status-pill--' . esc_attr( $type ) . '">' . esc_html( $label ) . '</span>';
	}

	private static function render_overview_data_status( array $sync_state, array $refresh_state ): void {
		$last_full_sync = sanitize_text_field( (string) ( $sync_state['last_run_at'] ?? '' ) );
		$last_updated   = sanitize_text_field( (string) get_option( 'yoohw_cos_customer_data_updated_at', '' ) );
		$full_sync_time = YoOhw_COS_DB::date_timestamp( $last_full_sync );
		$updated_time   = YoOhw_COS_DB::date_timestamp( $last_updated );
		$last_run_at    = $updated_time > $full_sync_time ? $last_updated : $last_full_sync;
		$last_timestamp = max( $full_sync_time, $updated_time );
		$stale_days     = max( 1, absint( apply_filters( 'yoohw_cos_overview_stale_sync_days', 7 ) ) );
		$age_seconds    = $last_timestamp ? max( 0, current_time( 'timestamp' ) - $last_timestamp ) : 0;
		$is_stale       = $last_timestamp && $age_seconds >= ( $stale_days * DAY_IN_SECONDS );
		$is_syncing     = ! empty( $sync_state['has_more'] ) || 'in_progress' === (string) ( $sync_state['status'] ?? '' );
		$refresh_status = sanitize_key( (string) ( $refresh_state['status'] ?? '' ) );
		$type           = 'success';
		$icon           = 'yes-alt';

		if ( empty( $last_run_at ) ) {
			$type    = 'warning';
			$icon    = 'warning';
			$message = __( 'Customer data has not been synced yet.', 'yoohw-customer-intelligence' );
		} elseif ( $is_syncing ) {
			$type    = 'info';
			$icon    = 'update';
			$message = __( 'Order sync is in progress. Dashboard totals may continue to change.', 'yoohw-customer-intelligence' );
		} elseif ( $is_stale ) {
			$type    = 'warning';
			$icon    = 'clock';
			$message = sprintf(
				/* translators: %s: date of the last customer sync. */
				__( 'No customer data changes have been recorded since %s. Run sync if store history changed outside normal WooCommerce flows.', 'yoohw-customer-intelligence' ),
				YoOhw_COS_DB::format_admin_date( $last_run_at, '&mdash;' )
			);
		} else {
			$message = sprintf(
				/* translators: %s: date of the last customer sync. */
				__( 'Customer data is current. Last updated %s.', 'yoohw-customer-intelligence' ),
				YoOhw_COS_DB::format_admin_date( $last_run_at, '&mdash;' )
			);
		}

		echo '<div class="yoohw-cos-overview-data-status yoohw-cos-overview-data-status--' . esc_attr( $type ) . '">';
		echo '<span class="dashicons dashicons-' . esc_attr( $icon ) . '" aria-hidden="true"></span>';
		echo '<span>' . wp_kses_post( $message ) . '</span>';

		if ( in_array( $refresh_status, array( 'pending', 'in_progress' ), true ) ) {
			echo '<span class="yoohw-cos-overview-data-status__refresh">';
			echo esc_html__( 'Customer classifications are being refreshed in the background.', 'yoohw-customer-intelligence' );
			echo '</span>';
		}

		echo '</div>';
	}

	private static function render_overview_notices(): void {
		if ( ! empty( $_GET['yoohw_task_completed'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>';
			echo esc_html__( 'Task completed.', 'yoohw-customer-intelligence' );
			echo '</p></div>';
		}

		if ( ! empty( $_GET['yoohw_task_error'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>';
			echo esc_html__( 'The task could not be updated.', 'yoohw-customer-intelligence' );
			echo '</p></div>';
		}
	}

	private static function render_stat_card(
		string $label,
		string $value,
		string $url = '',
		string $meta = ''
	): void {
		if ( '' !== $url ) {
			echo '<a class="yoohw-cos-stat-card yoohw-cos-stat-card--linked" href="' . esc_url( $url ) . '">';
		} else {
			echo '<div class="yoohw-cos-stat-card">';
		}

		echo '<div class="yoohw-cos-stat-card__label">';
		echo esc_html( $label );
		echo '</div>';
		echo '<div class="yoohw-cos-stat-card__value">';
		echo wp_kses_post( $value );
		echo '</div>';

		if ( '' !== $meta ) {
			echo '<div class="yoohw-cos-stat-card__meta">' . esc_html( $meta ) . '</div>';
		}

		echo '' !== $url ? '</a>' : '</div>';
	}

	private static function render_attention_panel( array $counts ): void {
		$items = array(
			array(
				'label' => __( 'Overdue tasks', 'yoohw-customer-intelligence' ),
				'value' => absint( $counts['overdue_tasks'] ?? 0 ),
				'meta'  => __( 'Requires immediate follow-up', 'yoohw-customer-intelligence' ),
				'url'   => self::get_overview_tasks_url( array( 'task_view' => 'overdue' ) ),
				'type'  => 'critical',
			),
			array(
				'label' => __( 'Due in 7 days', 'yoohw-customer-intelligence' ),
				'value' => absint( $counts['due_soon_tasks'] ?? 0 ),
				'meta'  => __( 'Upcoming customer follow-ups', 'yoohw-customer-intelligence' ),
				'url'   => self::get_overview_tasks_url( array( 'task_view' => 'due_soon' ) ),
				'type'  => 'info',
			),
			array(
				'label' => __( 'High-value retention risk', 'yoohw-customer-intelligence' ),
				'value' => absint( $counts['high_value_retention_risk'] ?? 0 ),
				'meta'  => __( 'At-risk or inactive high-value customers', 'yoohw-customer-intelligence' ),
				'url'   => self::get_overview_customers_url( array( 'customer_attention' => 'high_value_retention' ) ),
				'type'  => 'warning',
			),
			array(
				'label' => __( 'High-risk customers', 'yoohw-customer-intelligence' ),
				'value' => absint( $counts['high_risk_customers'] ?? 0 ),
				'meta'  => __( 'Risk and trust review needed', 'yoohw-customer-intelligence' ),
				'url'   => self::get_overview_customers_url( array( 'risk_level' => 'high' ) ),
				'type'  => 'critical',
			),
			array(
				'label' => __( 'Missing contact details', 'yoohw-customer-intelligence' ),
				'value' => absint( $counts['missing_contact_customers'] ?? 0 ),
				'meta'  => __( 'Email or phone is unavailable', 'yoohw-customer-intelligence' ),
				'url'   => self::get_overview_customers_url( array( 'customer_attention' => 'missing_contact' ) ),
				'type'  => 'warning',
			),
		);

		echo '<div class="postbox yoohw-cos-overview-attention">';
		echo '<div class="postbox-header">';
		echo '<h2 class="hndle">' . esc_html__( 'Needs attention', 'yoohw-customer-intelligence' ) . '</h2>';
		echo '<span class="yoohw-cos-overview-panel-note">' . esc_html__( 'Open an item to work the matching queue.', 'yoohw-customer-intelligence' ) . '</span>';
		echo '</div>';
		echo '<div class="inside">';
		echo '<div class="yoohw-cos-attention-grid">';

		foreach ( $items as $item ) {
			$has_items = absint( $item['value'] ) > 0;
			$type      = $has_items ? sanitize_html_class( (string) $item['type'] ) : 'clear';

			echo '<a class="yoohw-cos-attention-card yoohw-cos-attention-card--' . esc_attr( $type ) . '" href="' . esc_url( (string) $item['url'] ) . '">';
			echo '<span class="yoohw-cos-attention-card__value">' . esc_html( number_format_i18n( absint( $item['value'] ) ) ) . '</span>';
			echo '<span class="yoohw-cos-attention-card__content">';
			echo '<strong>' . esc_html( (string) $item['label'] ) . '</strong>';
			echo '<span>' . esc_html( (string) $item['meta'] ) . '</span>';
			echo '</span>';
			echo '<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>';
			echo '</a>';
		}

		echo '</div>';
		echo '</div>';
		echo '</div>';
	}

	private static function render_customer_health_panel( array $lifecycle_counts, array $risk_counts ): void {
		echo '<div class="postbox yoohw-cos-overview-panel yoohw-cos-overview-panel--health">';
		echo '<div class="postbox-header"><h2 class="hndle">' . esc_html__( 'Customer distribution', 'yoohw-customer-intelligence' ) . '</h2></div>';
		echo '<div class="inside">';
		echo '<div class="yoohw-cos-health-grid">';

		echo '<div class="yoohw-cos-health-section">';
		echo '<h3>' . esc_html__( 'Lifecycle', 'yoohw-customer-intelligence' ) . '</h3>';
		self::render_distribution_list(
			$lifecycle_counts,
			array(
				'new'     => __( 'New', 'yoohw-customer-intelligence' ),
				'repeat'  => __( 'Repeat', 'yoohw-customer-intelligence' ),
				'loyal'   => __( 'Loyal', 'yoohw-customer-intelligence' ),
				'vip'     => __( 'VIP', 'yoohw-customer-intelligence' ),
				'dormant' => __( 'Dormant', 'yoohw-customer-intelligence' ),
			),
			array(
				'new'     => self::get_overview_customers_url( array( 'lifecycle_stage' => 'new' ) ),
				'repeat'  => self::get_overview_customers_url( array( 'lifecycle_stage' => 'repeat' ) ),
				'loyal'   => self::get_overview_customers_url( array( 'lifecycle_stage' => 'loyal' ) ),
				'vip'     => self::get_overview_customers_url( array( 'lifecycle_stage' => 'vip' ) ),
				'dormant' => self::get_overview_customers_url( array( 'lifecycle_stage' => 'dormant' ) ),
			)
		);
		echo '</div>';

		echo '<div class="yoohw-cos-health-section">';
		echo '<h3>' . esc_html__( 'Risk and trust', 'yoohw-customer-intelligence' ) . '</h3>';
		self::render_distribution_list(
			$risk_counts,
			array(
				'none'   => __( 'No risk', 'yoohw-customer-intelligence' ),
				'low'    => __( 'Low risk', 'yoohw-customer-intelligence' ),
				'medium' => __( 'Medium risk', 'yoohw-customer-intelligence' ),
				'high'   => __( 'High risk', 'yoohw-customer-intelligence' ),
			),
			array(
				'none'   => self::get_overview_customers_url( array( 'risk_level' => 'none' ) ),
				'low'    => self::get_overview_customers_url( array( 'risk_level' => 'low' ) ),
				'medium' => self::get_overview_customers_url( array( 'risk_level' => 'medium' ) ),
				'high'   => self::get_overview_customers_url( array( 'risk_level' => 'high' ) ),
			)
		);
		echo '</div>';

		echo '</div>';
		echo '</div>';
		echo '</div>';
	}

	private static function render_distribution_panel( string $title, array $counts, array $labels ): void {
		echo '<div class="postbox yoohw-cos-overview-panel">';
		echo '<div class="postbox-header"><h2 class="hndle">' . esc_html( $title ) . '</h2></div>';
		echo '<div class="inside">';
		self::render_distribution_list( $counts, $labels );
		echo '</div>';
		echo '</div>';
	}

	private static function render_distribution_list( array $counts, array $labels, array $urls = array() ): void {
		$total = 0;

		foreach ( $labels as $key => $label ) {
			$total += absint( $counts[ $key ] ?? 0 );
		}

		if ( 0 === $total ) {
			YoOhw_COS_Admin_UI::render_empty_state(
				__( 'No customer data yet.', 'yoohw-customer-intelligence' ),
				__( 'Run order sync to populate customer health metrics.', 'yoohw-customer-intelligence' ),
				array(),
				'compact'
			);
			return;
		}

		echo '<div class="yoohw-cos-distribution-list">';

		foreach ( $labels as $key => $label ) {
			$count   = absint( $counts[ $key ] ?? 0 );
			$percent = $total > 0 ? (int) round( ( $count / $total ) * 100 ) : 0;
			$url     = esc_url( (string) ( $urls[ $key ] ?? '' ) );

			if ( '' !== $url ) {
				echo '<a class="yoohw-cos-distribution-row yoohw-cos-distribution-row--linked" href="' . esc_url( $url ) . '">';
			} else {
				echo '<div class="yoohw-cos-distribution-row">';
			}

			echo '<div class="yoohw-cos-distribution-row__meta">';
			echo '<span class="yoohw-cos-distribution-label">' . esc_html( $label ) . '</span>';
			echo '<span class="yoohw-cos-distribution-value"><strong>' . esc_html( number_format_i18n( $count ) ) . '</strong> <span>' . esc_html( number_format_i18n( $percent ) ) . '%</span></span>';
			echo '</div>';
			echo '<div class="yoohw-cos-distribution-track" aria-hidden="true">';
			echo '<span class="yoohw-cos-distribution-bar" style="width:' . esc_attr( $percent ) . '%;"></span>';
			echo '</div>';
			echo '' !== $url ? '</a>' : '</div>';
		}

		echo '</div>';
	}

	private static function render_priority_customers_panel( array $customers ): void {
		echo '<div class="postbox yoohw-cos-overview-panel yoohw-cos-overview-panel--customers">';
		echo '<div class="postbox-header">';
		echo '<h2 class="hndle">' . esc_html__( 'Priority customers', 'yoohw-customer-intelligence' ) . '</h2>';
		echo '<span class="yoohw-cos-overview-panel-note">' . esc_html__( 'Ranked by retention, risk, and profile completeness.', 'yoohw-customer-intelligence' ) . '</span>';
		echo '</div>';
		echo '<div class="inside">';

		if ( empty( $customers ) ) {
			YoOhw_COS_Admin_UI::render_empty_state(
				__( 'No priority customers right now.', 'yoohw-customer-intelligence' ),
				__( 'Customers with urgent retention, risk, or contact issues will appear here.', 'yoohw-customer-intelligence' ),
				array(),
				'compact'
			);
		} else {
			echo '<div class="yoohw-cos-priority-customer-list">';

			foreach ( $customers as $customer ) {
				$customer_id = absint( $customer['id'] ?? 0 );
				$name        = trim( sanitize_text_field( (string) ( $customer['display_name'] ?? '' ) ) );
				$name        = '' !== $name ? $name : __( '(No customer name)', 'yoohw-customer-intelligence' );
				$profile_url = self::get_overview_customers_url( array( 'customer_id' => $customer_id ) );
				$reasons     = self::get_priority_customer_reasons( $customer );

				echo '<div class="yoohw-cos-priority-customer">';
				echo '<div class="yoohw-cos-priority-customer__main">';
				echo '<strong><a href="' . esc_url( $profile_url ) . '">' . esc_html( $name ) . '</a></strong>';
				echo '<div class="yoohw-cos-priority-customer__reasons">';

				foreach ( $reasons as $reason ) {
					echo '<span class="yoohw-cos-badge yoohw-cos-badge--' . esc_attr( sanitize_html_class( (string) $reason['type'] ) ) . '">' . esc_html( (string) $reason['label'] ) . '</span>';
				}

				echo '</div>';
				echo '</div>';
				echo '<div class="yoohw-cos-priority-customer__commerce">';
				echo '<strong>' . wp_kses_post( self::format_overview_money( (float) ( $customer['total_spent'] ?? 0 ) ) ) . '</strong>';
				echo '<span>' . esc_html( sprintf(
					/* translators: %s: number of customer orders. */
					_n( '%s order', '%s orders', absint( $customer['total_orders'] ?? 0 ), 'yoohw-customer-intelligence' ),
					number_format_i18n( absint( $customer['total_orders'] ?? 0 ) )
				) ) . '</span>';
				echo '</div>';
				echo '<a class="button button-small" href="' . esc_url( $profile_url ) . '">' . esc_html__( 'View', 'yoohw-customer-intelligence' ) . '</a>';
				echo '</div>';
			}

			echo '</div>';
		}

		echo '<p class="yoohw-cos-panel-actions"><a href="' . esc_url( self::get_overview_customers_url() ) . '">' . esc_html__( 'View all customers', 'yoohw-customer-intelligence' ) . '</a></p>';
		echo '</div>';
		echo '</div>';
	}

	private static function get_priority_customer_reasons( array $customer ): array {
		$reasons = array();
		$status  = sanitize_key( (string) ( $customer['customer_status'] ?? '' ) );

		if ( 'inactive' === $status ) {
			$reasons[] = array(
				'label' => __( 'Inactive', 'yoohw-customer-intelligence' ),
				'type'  => 'danger',
			);
		} elseif ( 'at_risk' === $status ) {
			$reasons[] = array(
				'label' => __( 'At risk', 'yoohw-customer-intelligence' ),
				'type'  => 'warning',
			);
		}

		if ( (float) ( $customer['risk_score'] ?? 0 ) >= 70 ) {
			$reasons[] = array(
				'label' => __( 'High risk', 'yoohw-customer-intelligence' ),
				'type'  => 'danger',
			);
		}

		if ( empty( $customer['email'] ) || empty( $customer['phone'] ) ) {
			$reasons[] = array(
				'label' => __( 'Missing contact', 'yoohw-customer-intelligence' ),
				'type'  => 'muted',
			);
		}

		if ( 'none' !== sanitize_key( (string) ( $customer['vip_status'] ?? 'none' ) ) ) {
			$reasons[] = array(
				'label' => __( 'High value', 'yoohw-customer-intelligence' ),
				'type'  => 'info',
			);
		}

		return array_slice( $reasons, 0, 3 );
	}

	private static function render_priority_tasks_panel( array $tasks ): void {
		echo '<div class="postbox yoohw-cos-overview-panel yoohw-cos-overview-panel--tasks">';
		echo '<div class="postbox-header"><h2 class="hndle">' . esc_html__( 'Tasks to handle', 'yoohw-customer-intelligence' ) . '</h2></div>';
		echo '<div class="inside">';

		if ( empty( $tasks ) ) {
			YoOhw_COS_Admin_UI::render_empty_state(
				__( 'No open tasks.', 'yoohw-customer-intelligence' ),
				__( 'New customer follow-ups will appear here.', 'yoohw-customer-intelligence' ),
				array(),
				'compact'
			);
		} else {
			echo '<div class="yoohw-cos-overview-task-list">';

			foreach ( $tasks as $task ) {
				self::render_overview_task_item( $task );
			}

			echo '</div>';
		}

		echo '<p class="yoohw-cos-panel-actions"><a href="' . esc_url( self::get_overview_tasks_url() ) . '">' . esc_html__( 'View all tasks', 'yoohw-customer-intelligence' ) . '</a></p>';
		echo '</div>';
		echo '</div>';
	}

	private static function render_overview_task_item( array $task ): void {
		$task_id     = absint( $task['id'] ?? 0 );
		$customer_id = absint( $task['customer_id'] ?? 0 );
		$title       = sanitize_text_field( (string) ( $task['title'] ?? '' ) );
		$customer    = sanitize_text_field( (string) ( $task['customer_name'] ?? '' ) );
		$customer    = '' !== $customer ? $customer : sanitize_email( (string) ( $task['customer_email'] ?? '' ) );
		$priority    = YoOhw_COS_Tasks::normalize_priority( (string) ( $task['priority'] ?? 'normal' ) );
		$due_date    = sanitize_text_field( (string) ( $task['due_date'] ?? '' ) );
		$is_overdue  = '' !== $due_date && YoOhw_COS_DB::date_timestamp( $due_date ) < current_time( 'timestamp' );
		$task_url    = add_query_arg(
			array(
				'page'    => 'yoohw-customer-intelligence-tasks',
				'task_id' => $task_id,
			),
			admin_url( 'admin.php' )
		);
		$customer_url = self::get_overview_customers_url( array( 'customer_id' => $customer_id ) );
		$complete_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'    => 'yoohw_cos_complete_task',
					'task_id'   => $task_id,
					'_redirect' => rawurlencode( admin_url( 'admin.php?page=yoohw-customer-intelligence-overview' ) ),
				),
				admin_url( 'admin-post.php' )
			),
			'yoohw_cos_complete_task'
		);

		echo '<div class="yoohw-cos-overview-task">';
		echo '<div class="yoohw-cos-overview-task__main">';
		echo '<strong><a href="' . esc_url( $task_url ) . '">' . esc_html( $title ) . '</a></strong>';
		echo '<div class="yoohw-cos-overview-task__meta">';

		if ( $customer_id > 0 && '' !== $customer ) {
			echo '<a href="' . esc_url( $customer_url ) . '">' . esc_html( $customer ) . '</a>';
		} elseif ( '' !== $customer ) {
			echo '<span>' . esc_html( $customer ) . '</span>';
		}

		echo '<span class="yoohw-cos-badge yoohw-cos-badge--task-priority-' . esc_attr( sanitize_html_class( $priority ) ) . '">' . esc_html( YoOhw_COS_Tasks::get_priorities()[ $priority ] ?? __( 'Normal', 'yoohw-customer-intelligence' ) ) . '</span>';
		echo '<span class="' . esc_attr( $is_overdue ? 'yoohw-cos-overview-task__overdue' : '' ) . '">' . wp_kses_post( YoOhw_COS_DB::format_admin_date( $due_date, __( 'No due date', 'yoohw-customer-intelligence' ) ) ) . '</span>';
		echo '</div>';
		echo '</div>';
		echo '<a class="button button-small" href="' . esc_url( $complete_url ) . '">' . esc_html__( 'Complete', 'yoohw-customer-intelligence' ) . '</a>';
		echo '</div>';
	}

	private static function format_overview_money( float $amount ): string {
		return function_exists( 'wc_price' )
			? wc_price( $amount )
			: number_format_i18n( $amount, 2 );
	}

	private static function get_overview_customers_url( array $args = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page' => 'yoohw-customer-intelligence',
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	private static function get_overview_tasks_url( array $args = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page'      => 'yoohw-customer-intelligence-tasks',
					'task_view' => 'open',
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	private static function render_recent_activity_panel( array $events ): void {
		echo '<div class="postbox yoohw-cos-overview-panel yoohw-cos-overview-panel--activity">';
		echo '<div class="postbox-header"><h2 class="hndle">' . esc_html__( 'Recent activity', 'yoohw-customer-intelligence' ) . '</h2></div>';
		echo '<div class="inside">';

		if ( empty( $events ) ) {
			YoOhw_COS_Admin_UI::render_empty_state(
				__( 'No activity yet.', 'yoohw-customer-intelligence' ),
				__( 'Customer sync and team actions will appear here.', 'yoohw-customer-intelligence' ),
				array(),
				'compact'
			);
			echo '</div>';
			echo '</div>';
			return;
		}

		echo '<ul class="yoohw-cos-recent-activity">';

		foreach ( $events as $event ) {
			$event_label = self::format_event_type_label( (string) ( $event['event_type'] ?? '' ) );
			$severity    = self::get_event_severity_type( (string) ( $event['severity'] ?? 'info' ) );
			$description = trim( wp_strip_all_tags( (string) ( $event['description'] ?? '' ) ) );
			$customer    = self::get_recent_activity_customer_link( $event );
			$date        = YoOhw_COS_DB::format_admin_date( $event['created_at'] ?? '', '&mdash;' );

			if ( '' === $description ) {
				$description = $event_label;
			}

			echo '<li class="yoohw-cos-recent-activity__item">';
			echo '<div class="yoohw-cos-recent-activity__line">';
			echo '<span class="yoohw-cos-recent-activity__description">' . esc_html( $description ) . '</span>';
			echo '<span class="yoohw-cos-recent-activity__date">' . wp_kses_post( $date ) . '</span>';
			echo '</div>';
			echo '<div class="yoohw-cos-recent-activity__meta">';
			echo wp_kses_post( self::render_status_pill( $event_label, $severity ) );
			echo wp_kses_post( $customer );
			echo '</div>';
			echo '</li>';
		}

		echo '</ul>';
		echo '<p class="yoohw-cos-panel-actions"><a href="' . esc_url( admin_url( 'admin.php?page=yoohw-customer-intelligence-activity' ) ) . '">' . esc_html__( 'View all activity', 'yoohw-customer-intelligence' ) . '</a></p>';
		echo '</div>';
		echo '</div>';
	}

	private static function get_recent_activity( int $limit = 6 ): array {
		global $wpdb;

		$events_table    = YoOhw_COS_DB::events_table();
		$customers_table = YoOhw_COS_DB::customers_table();
		$limit           = max( 1, absint( $limit ) );

		if ( ! self::table_exists( $events_table ) || ! self::table_exists( $customers_table ) ) {
			return array();
		}

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					e.id,
					e.customer_id,
					e.event_type,
					e.event_source,
					e.severity,
					e.object_type,
					e.object_id,
					e.description,
					e.created_at,
					c.id AS customer_record_id,
					c.display_name,
					c.email
				FROM %i e
				LEFT JOIN %i c ON e.customer_id = c.id
				ORDER BY e.created_at DESC, e.id DESC
				LIMIT %d",
				$events_table,
				$customers_table,
				$limit
			),
			ARRAY_A
		);

		return is_array( $results ) ? $results : array();
	}

	private static function table_exists( string $table ): bool {
		global $wpdb;

		if ( '' === $table ) {
			return false;
		}

		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$table
			)
		);

		return $found === $table;
	}

	private static function format_event_type_label( string $event_type ): string {
		$event_type = sanitize_key( $event_type );

		if ( '' === $event_type ) {
			return __( 'Activity', 'yoohw-customer-intelligence' );
		}

		$labels = array(
			'bulk_customer_action' => __( 'Bulk action', 'yoohw-customer-intelligence' ),
			'customer_email_sent'  => __( 'Customer email sent', 'yoohw-customer-intelligence' ),
			'blacklist_blocked'    => __( 'Blacklist blocked', 'yoohw-customer-intelligence' ),
			'blacklist_match_detected' => __( 'Blacklist match', 'yoohw-customer-intelligence' ),
			'blacklist_removed'    => __( 'Blacklist cleared', 'yoohw-customer-intelligence' ),
			'blacklist_suspect'    => __( 'Blacklist suspect', 'yoohw-customer-intelligence' ),
			'premium_order_risk_scored' => __( 'Premium order risk scored', 'yoohw-customer-intelligence' ),
			'premium_risk_rule_matched' => __( 'Premium risk rule matched', 'yoohw-customer-intelligence' ),
			'premium_antibot_blocked' => __( 'Premium anti-bot blocked', 'yoohw-customer-intelligence' ),
			'premium_antibot_would_block' => __( 'Premium anti-bot challenge', 'yoohw-customer-intelligence' ),
			'premium_payment_abuse_detected' => __( 'Premium payment abuse', 'yoohw-customer-intelligence' ),
			'premium_device_signal_detected' => __( 'Premium device signal', 'yoohw-customer-intelligence' ),
			'premium_gateway_fraud_signal' => __( 'Premium gateway fraud signal', 'yoohw-customer-intelligence' ),
			'note_added'           => __( 'Note added', 'yoohw-customer-intelligence' ),
			'note_deleted'         => __( 'Note deleted', 'yoohw-customer-intelligence' ),
			'note_updated'         => __( 'Note updated', 'yoohw-customer-intelligence' ),
			'order_synced'         => __( 'Order synced', 'yoohw-customer-intelligence' ),
			'segment_assigned'     => __( 'Segment added', 'yoohw-customer-intelligence' ),
			'segment_removed'      => __( 'Segment removed', 'yoohw-customer-intelligence' ),
			'task_completed'       => __( 'Task completed', 'yoohw-customer-intelligence' ),
			'task_created'         => __( 'Task created', 'yoohw-customer-intelligence' ),
			'tag_assigned'         => __( 'Tag added', 'yoohw-customer-intelligence' ),
			'tag_removed'          => __( 'Tag removed', 'yoohw-customer-intelligence' ),
		);

		if ( isset( $labels[ $event_type ] ) ) {
			return $labels[ $event_type ];
		}

		return ucwords( str_replace( '_', ' ', $event_type ) );
	}

	private static function format_blacklist_sync_stage_label( string $stage ): string {
		$stage = sanitize_key( $stage );

		$labels = array(
			'complete'              => __( 'Complete', 'yoohw-customer-intelligence' ),
			'core'                  => __( 'Core blacklist', 'yoohw-customer-intelligence' ),
			'core_unavailable'      => __( 'Core unavailable', 'yoohw-customer-intelligence' ),
			'detection_log'         => __( 'Premium detection log', 'yoohw-customer-intelligence' ),
			'orders'                => __( 'Premium order risk', 'yoohw-customer-intelligence' ),
			'payment_abuse'         => __( 'Premium payment abuse', 'yoohw-customer-intelligence' ),
			'premium_unavailable'   => __( 'Premium unavailable', 'yoohw-customer-intelligence' ),
		);

		return $labels[ $stage ] ?? ucwords( str_replace( '_', ' ', $stage ) );
	}

	private static function get_event_severity_type( string $severity ): string {
		$severity = sanitize_key( $severity );

		if ( 'error' === $severity ) {
			return 'error';
		}

		if ( 'warning' === $severity ) {
			return 'warning';
		}

		if ( 'success' === $severity ) {
			return 'good';
		}

		return 'info';
	}

	private static function get_recent_activity_customer_link( array $event ): string {
		$customer_id = absint( $event['customer_record_id'] ?? 0 );
		$name        = trim( (string) ( $event['display_name'] ?? '' ) );
		$email       = sanitize_email( (string) ( $event['email'] ?? '' ) );

		if ( '' === $name ) {
			$name = $email;
		}

		if ( '' === $name ) {
			$name = __( 'System', 'yoohw-customer-intelligence' );
		}

		if ( $customer_id <= 0 ) {
			return esc_html( $name );
		}

		$url = add_query_arg(
			array(
				'page'        => 'yoohw-customer-intelligence',
				'customer_id' => $customer_id,
			),
			admin_url( 'admin.php' )
		);

		return '<a href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a>';
	}

	private static function get_customers_list_redirect_args( array $extra = array(), array $filters = array() ): array {
		$args = array(
			'page' => 'yoohw-customer-intelligence',
		);

		return array_merge( $args, $filters, $extra );
	}

	private static function sanitize_customers_list_redirect_args( array $source ): array {
		$args = array();

		$preserve_keys = array(
			's',
			'customer_status',
			'customer_view',
			'vip_status',
			'risk_level',
			'lifecycle_stage',
			'customer_cohort',
			'customer_attention',
			'customer_tag',
			'customer_segment',
			'paged',
			'orderby',
			'order',
		);

		if ( self::is_loyalty_integration_active() ) {
			$preserve_keys[] = 'loyalty_level';
		}

		foreach ( $preserve_keys as $key ) {
			if ( ! isset( $source[ $key ] ) || '' === $source[ $key ] ) {
				continue;
			}

			$value = $source[ $key ];

			if ( is_array( $value ) ) {
				continue;
			}

			$args[ $key ] = is_numeric( $value )
				? absint( $value )
				: sanitize_text_field( $value );
		}

		return $args;
	}

	private static function is_loyalty_integration_active(): bool {
		return class_exists( 'YoOhw_COS_Loyalty_Integration' )
			&& is_callable( array( 'YoOhw_COS_Loyalty_Integration', 'is_loyalty_plugin_active' ) )
			&& YoOhw_COS_Loyalty_Integration::is_loyalty_plugin_active();
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
}
