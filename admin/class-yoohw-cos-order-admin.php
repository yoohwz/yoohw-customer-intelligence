<?php
defined( 'ABSPATH' ) || exit;

final class YoOhw_COS_Order_Admin {

	private const SEARCH_NONCE_ACTION = 'yoohw_cos_search_customers';
	private const ORDER_LIST_CUSTOMER_FILTER_PARAM = 'yoohw_cos_customer_id';

	public static function init(): void {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_yoohw_cos_json_search_customers', array( __CLASS__, 'handle_customer_search' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_task_metabox' ), 5, 2 );
		add_action( 'admin_footer', array( __CLASS__, 'render_task_form_target' ) );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( __CLASS__, 'render_customer_field' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save_customer_profile_link' ), 60, 2 );
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( __CLASS__, 'remove_registered_customer_order_list_filter' ), 1, 0 );
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( __CLASS__, 'render_order_list_customer_filter' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'render_legacy_order_list_customer_filter' ), 20, 0 );
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( __CLASS__, 'filter_order_list_query_args' ) );
		add_filter( 'request', array( __CLASS__, 'filter_legacy_order_list_query_vars' ), 50 );
	}

	public static function enqueue_assets( string $hook ): void {
		if ( ! self::is_order_edit_screen( $hook ) && ! self::is_order_list_screen( $hook ) ) {
			return;
		}

		$js_path  = YOOHW_COS_PATH . 'assets/js/order-admin.js';
		$css_path = YOOHW_COS_PATH . 'assets/css/order-admin.css';
		$js_ver   = file_exists( $js_path ) ? (string) filemtime( $js_path ) : YOOHW_COS_VERSION;
		$css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : YOOHW_COS_VERSION;

		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_script(
			'yoohw-cos-order-admin',
			YOOHW_COS_URL . 'assets/js/order-admin.js',
			array( 'jquery', 'wc-enhanced-select', 'jquery-tiptip' ),
			$js_ver,
			true
		);
		wp_localize_script(
			'yoohw-cos-order-admin',
			'yoohwCosOrderAdmin',
			array(
				'ajaxUrl'                 => admin_url( 'admin-ajax.php' ),
				'assigneeNoResultsText'   => __( 'No assignable users found', 'yoohw-customer-intelligence' ),
				'assigneePlaceholderText' => __( 'Search assignee', 'yoohw-customer-intelligence' ),
				'assigneeSearchNonce'     => wp_create_nonce( 'yoohw_cos_search_assignable_users' ),
				'searchNonce'             => wp_create_nonce( self::SEARCH_NONCE_ACTION ),
				'placeholderText'         => __( 'Search YoOhw customer profile', 'yoohw-customer-intelligence' ),
				'orderListPlaceholderText' => __( 'Filter by customer', 'yoohw-customer-intelligence' ),
				'noResultsText'           => __( 'No customer profiles found', 'yoohw-customer-intelligence' ),
			)
		);

		wp_enqueue_style(
			'yoohw-cos-order-admin',
			YOOHW_COS_URL . 'assets/css/order-admin.css',
			array(),
			$css_ver
		);
	}

	public static function handle_customer_search(): void {
		check_ajax_referer( self::SEARCH_NONCE_ACTION, 'security' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json( array() );
		}

		$term             = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
		$include_archived = ! empty( $_GET['include_archived'] );
		$results          = array();

		foreach ( self::search_customers( $term, $include_archived ) as $customer ) {
			$customer_id = absint( $customer['id'] ?? 0 );

			if ( $customer_id > 0 ) {
				$results[ $customer_id ] = self::format_customer_option( $customer, $include_archived );
			}
		}

		wp_send_json( $results );
	}

	public static function remove_registered_customer_order_list_filter(): void {
		global $wp_filter;

		$hook = 'woocommerce_order_list_table_restrict_manage_orders';

		if ( empty( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof WP_Hook ) {
			return;
		}

		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'] ?? null;

				if ( is_array( $function ) && isset( $function[1] ) && 'customers_filter' === $function[1] ) {
					remove_action( $hook, $function, absint( $priority ) );
				}
			}
		}
	}

	public static function render_order_list_customer_filter( string $order_type = 'shop_order', string $which = 'top' ): void {
		if ( 'shop_order' !== $order_type || 'top' !== $which || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		self::render_order_list_customer_filter_select();
	}

	public static function render_legacy_order_list_customer_filter(): void {
		global $typenow;

		if ( 'shop_order' !== $typenow || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		self::render_order_list_customer_filter_select();
	}

	public static function filter_order_list_query_args( array $query_args ): array {
		$customer_id = self::get_order_list_filter_customer_id();

		if ( $customer_id <= 0 ) {
			return $query_args;
		}

		return self::apply_order_list_customer_filter_to_query_args( $query_args, $customer_id );
	}

	public static function filter_legacy_order_list_query_vars( array $query_vars ): array {
		$post_type = isset( $query_vars['post_type'] )
			? sanitize_key( (string) $query_vars['post_type'] )
			: ( isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '' );

		if ( 'shop_order' !== $post_type ) {
			return $query_vars;
		}

		$customer_id = self::get_order_list_filter_customer_id();

		if ( $customer_id <= 0 ) {
			return $query_vars;
		}

		return self::apply_order_list_customer_filter_to_query_args( $query_vars, $customer_id );
	}

	private static function apply_order_list_customer_filter_to_query_args( array $query_args, int $customer_id ): array {
		$customer = YoOhw_COS_Customers::get_customer( $customer_id );

		if ( empty( $customer ) ) {
			return $query_args;
		}

		$order_ids = self::get_order_ids_for_customer_filter( $customer );

		if ( empty( $order_ids ) ) {
			$order_ids = array( 0 );
		}

		$current_post_in = isset( $query_args['post__in'] ) && is_array( $query_args['post__in'] )
			? array_map( 'absint', $query_args['post__in'] )
			: array();

		if ( ! empty( $current_post_in ) ) {
			$order_ids = array_values( array_intersect( $current_post_in, $order_ids ) );
		}

		$query_args['post__in'] = ! empty( $order_ids ) ? $order_ids : array( 0 );

		return $query_args;
	}

	private static function get_order_ids_for_customer_filter( array $customer ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$base_args = array(
			'type'   => 'shop_order',
			'limit'  => -1,
			'return' => 'ids',
			'status' => array_keys( wc_get_order_statuses() ),
		);

		$queries     = array();
		$customer_id = absint( $customer['id'] ?? 0 );
		$wp_user_id  = absint( $customer['wp_user_id'] ?? 0 );
		$email       = sanitize_email( (string) ( $customer['email'] ?? '' ) );
		$phone       = sanitize_text_field( (string) ( $customer['phone'] ?? '' ) );

		if ( $customer_id > 0 ) {
			$queries[] = array(
				'meta_key'   => YoOhw_COS_Customers::ORDER_CUSTOMER_META_KEY,
				'meta_value' => (string) $customer_id,
			);
		}

		if ( $wp_user_id > 0 ) {
			$queries[] = array(
				'customer_id' => $wp_user_id,
			);
		}

		if ( '' !== $email ) {
			$queries[] = array(
				'billing_email' => $email,
			);
		}

		if ( '' !== $phone ) {
			$queries[] = array(
				'billing_phone' => $phone,
			);
		}

		$order_ids = array();

		foreach ( $queries as $query ) {
			$ids = wc_get_orders( array_merge( $base_args, $query ) );

			if ( is_array( $ids ) ) {
				$order_ids = array_merge( $order_ids, array_map( 'absint', $ids ) );
			}
		}

		return array_values( array_unique( array_filter( $order_ids ) ) );
	}

	private static function render_order_list_customer_filter_select(): void {
		$customer_id = self::get_order_list_filter_customer_id();
		$customer    = $customer_id > 0 ? YoOhw_COS_Customers::get_customer( $customer_id ) : array();

		echo '<select class="yoohw-cos-order-list-customer-search" name="' . esc_attr( self::ORDER_LIST_CUSTOMER_FILTER_PARAM ) . '" data-placeholder="' . esc_attr__( 'Filter by customer', 'yoohw-customer-intelligence' ) . '" data-allow_clear="true">';
		echo '<option value=""></option>';

		if ( ! empty( $customer ) ) {
			echo '<option value="' . esc_attr( $customer_id ) . '" selected="selected">' . esc_html( self::format_customer_option( $customer, true ) ) . '</option>';
		}

		echo '</select>';
	}

	private static function get_order_list_filter_customer_id(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only order list filter.
		$customer_id = isset( $_GET[ self::ORDER_LIST_CUSTOMER_FILTER_PARAM ] ) ? absint( wp_unslash( $_GET[ self::ORDER_LIST_CUSTOMER_FILTER_PARAM ] ) ) : 0;

		if ( $customer_id <= 0 || ! YoOhw_COS_Customers::customer_exists( $customer_id ) ) {
			return 0;
		}

		return $customer_id;
	}

	public static function render_task_form_target(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! self::is_order_edit_screen( '' ) ) {
			return;
		}

		echo '<form id="yoohw-cos-order-task-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"></form>';
	}

	public static function render_customer_field( WC_Order $order ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$customer_id = self::get_order_customer_profile_id( $order );
		$customer    = $customer_id > 0 ? YoOhw_COS_Customers::get_customer( $customer_id ) : array();

		$customer_help_tip = __( 'Uses customer profiles. WooCommerce customer user is synchronized when the selected profile has a WP user.', 'yoohw-customer-intelligence' );

		echo '<p class="form-field form-field-wide yoohw-cos-order-customer-field">';
		echo '<span class="yoohw-cos-order-customer-heading">';
		echo '<label for="yoohw_cos_customer_id">' . esc_html__( 'Customer:', 'yoohw-customer-intelligence' ) . '</label>';

		echo '<span class="yoohw-cos-order-customer-links">';
		if ( ! empty( $customer ) ) {
			echo '<a href="' . esc_url( self::get_customer_profile_url( $customer_id ) ) . '">' . esc_html__( 'Profile', 'yoohw-customer-intelligence' ) . ' &rarr;</a>';

			$wp_user_id = absint( $customer['wp_user_id'] ?? 0 );
			if ( $wp_user_id > 0 ) {
				echo '<a href="' . esc_url( get_edit_user_link( $wp_user_id ) ) . '">' . esc_html__( 'WP user profile', 'yoohw-customer-intelligence' ) . ' &rarr;</a>';
			}
		}

		if ( function_exists( 'wc_help_tip' ) ) {
			echo wp_kses_post( wc_help_tip( $customer_help_tip ) );
		}
		echo '</span>';

		echo '</span>';
		echo '<select id="yoohw_cos_customer_id" name="yoohw_cos_customer_id" class="yoohw-cos-order-customer-search" data-placeholder="' . esc_attr__( 'Search YoOhw customer profile', 'yoohw-customer-intelligence' ) . '" data-allow_clear="true">';

		if ( ! empty( $customer ) ) {
			echo '<option value="' . esc_attr( $customer_id ) . '" selected="selected">' . esc_html( self::format_customer_option( $customer ) ) . '</option>';
		}

		echo '</select>';


		echo '</p>';
	}

	public static function save_customer_profile_link( int $order_id, $order = null ): void {
		if ( 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) || ! self::verify_order_save_request( $order_id ) ) {
			return;
		}

		if ( ! isset( $_POST['yoohw_cos_customer_id'] ) ) {
			return;
		}

		$customer_id = absint( wp_unslash( $_POST['yoohw_cos_customer_id'] ) );
		$wc_order    = $order instanceof WC_Order ? $order : wc_get_order( $order_id );

		if ( ! $wc_order instanceof WC_Order ) {
			return;
		}

		if ( $customer_id > 0 ) {
			$customer = YoOhw_COS_Customers::get_customer( $customer_id );

			if ( empty( $customer ) ) {
				return;
			}

			$wc_order->update_meta_data( YoOhw_COS_Customers::ORDER_CUSTOMER_META_KEY, $customer_id );

			$wp_user_id = absint( $customer['wp_user_id'] ?? 0 );
			$wc_order->set_customer_id( $wp_user_id > 0 && get_user_by( 'id', $wp_user_id ) ? $wp_user_id : 0 );
		} else {
			$wc_order->delete_meta_data( YoOhw_COS_Customers::ORDER_CUSTOMER_META_KEY );
			$wc_order->set_customer_id( 0 );
		}

		$wc_order->save();
		YoOhw_COS_Customers::sync_from_order( $wc_order );
	}

	public static function register_task_metabox( string $screen_id, $order_or_post = null ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! in_array( $screen_id, self::get_order_screen_ids(), true ) ) {
			return;
		}

		add_meta_box(
			'yoohw-cos-order-tasks',
			__( 'Customer task', 'yoohw-customer-intelligence' ),
			array( __CLASS__, 'render_task_metabox' ),
			$screen_id,
			'side',
			'high'
		);
	}

	public static function render_task_metabox( $order_or_post, array $metabox = array() ): void {
		$order = self::resolve_order( $order_or_post );

		if ( ! $order instanceof WC_Order || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$order_id    = $order->get_id();
		$customer_id = self::get_order_customer_profile_id( $order );
		$form_id     = 'yoohw-cos-order-task-form';
		$redirect    = self::get_order_edit_url( $order ) . '#yoohw-cos-order-tasks';

		self::render_task_notices();

		if ( $customer_id <= 0 ) {
			echo '<p class="description">' . esc_html__( 'No YoOhw customer profile is linked to this order yet. Select a customer profile and save the order before creating tasks.', 'yoohw-customer-intelligence' ) . '</p>';
			return;
		}

		$tasks      = YoOhw_COS_Tasks::get_order_tasks( $order_id, array( 'limit' => 5, 'status' => 'open' ) );
		$total_open = YoOhw_COS_Tasks::get_order_task_count( $order_id, 'open' );
		$tasks_url  = add_query_arg(
			array(
				'page'        => 'yoohw-customer-intelligence-tasks',
				'task_view'   => 'open',
				'customer_id' => $customer_id,
				'order_id'    => $order_id,
			),
			admin_url( 'admin.php' )
		);

		echo '<div class="yoohw-cos-order-task-form">';
		echo '<input form="' . esc_attr( $form_id ) . '" type="hidden" name="action" value="yoohw_cos_create_task" />';
		echo '<input form="' . esc_attr( $form_id ) . '" type="hidden" name="customer_id" value="' . esc_attr( $customer_id ) . '" />';
		echo '<input form="' . esc_attr( $form_id ) . '" type="hidden" name="order_id" value="' . esc_attr( $order_id ) . '" />';
		echo '<input form="' . esc_attr( $form_id ) . '" type="hidden" name="_redirect" value="' . esc_attr( $redirect ) . '" />';
		echo '<input form="' . esc_attr( $form_id ) . '" type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'yoohw_cos_create_task' ) ) . '" />';

		echo '<p>';
		echo '<label for="yoohw_cos_order_task_title"><strong>' . esc_html__( 'Add task', 'yoohw-customer-intelligence' ) . '</strong></label>';
		echo '<input form="' . esc_attr( $form_id ) . '" type="text" id="yoohw_cos_order_task_title" name="task_title" class="widefat" placeholder="' . esc_attr__( 'Follow up about this order...', 'yoohw-customer-intelligence' ) . '" required />';
		echo '</p>';

		echo '<p>';
		echo '<label for="yoohw_cos_order_task_priority">' . esc_html__( 'Priority', 'yoohw-customer-intelligence' ) . '</label>';
		echo '<select form="' . esc_attr( $form_id ) . '" id="yoohw_cos_order_task_priority" name="task_priority" class="widefat">';

		foreach ( YoOhw_COS_Tasks::get_priorities() as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( 'normal', $value, false ) . '>' . esc_html( $label ) . '</option>';
		}

		echo '</select>';
		echo '</p>';

		echo '<p>';
		echo '<label for="yoohw_cos_order_task_due_date">' . esc_html__( 'Due date', 'yoohw-customer-intelligence' ) . '</label>';
		echo '<input form="' . esc_attr( $form_id ) . '" type="datetime-local" id="yoohw_cos_order_task_due_date" name="task_due_date" class="widefat" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="yoohw_cos_order_task_assignee">' . esc_html__( 'Assigned to', 'yoohw-customer-intelligence' ) . '</label>';
		self::render_task_assignee_select( get_current_user_id(), 'yoohw_cos_order_task_assignee', $form_id );
		echo '</p>';

		echo '<p>';
		echo '<textarea form="' . esc_attr( $form_id ) . '" name="task_description" rows="2" class="widefat" placeholder="' . esc_attr__( 'Optional task notes...', 'yoohw-customer-intelligence' ) . '"></textarea>';
		echo '</p>';

		submit_button(
			__( 'Add task', 'yoohw-customer-intelligence' ),
			'secondary',
			'submit',
			false,
			array( 'form' => $form_id )
		);

		echo '</div>';
		echo '<hr />';

		if ( empty( $tasks ) ) {
			echo '<p class="description">' . esc_html__( 'No open tasks for this order.', 'yoohw-customer-intelligence' ) . '</p>';
			return;
		}

		echo '<p class="yoohw-cos-order-task-summary">';
		printf(
			/* translators: 1: number of tasks currently shown, 2: total number of open tasks. */
			esc_html__( 'Showing %1$s of %2$s open tasks.', 'yoohw-customer-intelligence' ),
			esc_html( number_format_i18n( count( $tasks ) ) ),
			esc_html( number_format_i18n( $total_open ) )
		);
		echo ' <a href="' . esc_url( $tasks_url ) . '">' . esc_html__( 'View all tasks', 'yoohw-customer-intelligence' ) . '</a>';
		echo '</p>';

		echo '<div class="yoohw-cos-order-task-list">';

		foreach ( $tasks as $task ) {
			self::render_order_task_item( $order, $task );
		}

		echo '</div>';
	}

	private static function is_order_edit_screen( string $hook ): bool {
		$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$screen_id = $screen ? (string) $screen->id : '';
		$page      = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$action    = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';

		return 'shop_order' === $screen_id
			|| ( 'post.php' === $hook && 'shop_order' === $post_type )
			|| ( 'wc-orders' === $page && in_array( $action, array( 'edit', 'new' ), true ) );
	}

	private static function is_order_list_screen( string $hook ): bool {
		$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$screen_id = $screen ? (string) $screen->id : '';
		$page      = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$action    = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';

		return 'edit-shop_order' === $screen_id
			|| ( 'edit.php' === $hook && 'shop_order' === $post_type )
			|| ( 'woocommerce_page_wc-orders' === $screen_id && 'wc-orders' === $page && ! in_array( $action, array( 'edit', 'new' ), true ) );
	}

	private static function get_order_customer_profile_id( WC_Order $order ): int {
		$linked_customer_id = absint( $order->get_meta( YoOhw_COS_Customers::ORDER_CUSTOMER_META_KEY, true ) );

		if ( $linked_customer_id > 0 && YoOhw_COS_Customers::customer_exists( $linked_customer_id ) ) {
			return $linked_customer_id;
		}

		return YoOhw_COS_Customers::find_customer_id_from_order(
			$order,
			absint( $order->get_customer_id() ),
			sanitize_email( $order->get_billing_email() ),
			sanitize_text_field( $order->get_billing_phone() )
		);
	}

	private static function verify_order_save_request( int $order_id ): bool {
		if ( isset( $_POST['woocommerce_meta_nonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_POST['woocommerce_meta_nonce'] ) );

			if ( wp_verify_nonce( $nonce, 'woocommerce_save_data' ) ) {
				return true;
			}
		}

		if ( isset( $_POST['_wpnonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) );

			return (bool) wp_verify_nonce( $nonce, 'update-order_' . absint( $order_id ) );
		}

		return false;
	}

	private static function get_order_screen_ids(): array {
		$screen_ids = array( 'shop_order', 'woocommerce_page_wc-orders' );

		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screen_ids[] = wc_get_page_screen_id( 'shop-order' );
		}

		return array_values( array_unique( array_filter( $screen_ids ) ) );
	}

	private static function resolve_order( $order_or_post ): ?WC_Order {
		if ( $order_or_post instanceof WC_Order ) {
			return $order_or_post;
		}

		if ( $order_or_post instanceof WP_Post ) {
			$order = wc_get_order( $order_or_post->ID );

			return $order instanceof WC_Order ? $order : null;
		}

		if ( is_numeric( $order_or_post ) ) {
			$order = wc_get_order( absint( $order_or_post ) );

			return $order instanceof WC_Order ? $order : null;
		}

		return null;
	}

	private static function get_order_edit_url( WC_Order $order ): string {
		if ( method_exists( $order, 'get_edit_order_url' ) ) {
			return $order->get_edit_order_url();
		}

		return add_query_arg(
			array(
				'post'   => $order->get_id(),
				'action' => 'edit',
			),
			admin_url( 'post.php' )
		);
	}

	private static function render_task_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only notices are returned by verified admin-post task handlers.
		$created   = isset( $_GET['yoohw_task_created'] ) ? absint( wp_unslash( $_GET['yoohw_task_created'] ) ) : 0;
		$completed = isset( $_GET['yoohw_task_completed'] ) ? absint( wp_unslash( $_GET['yoohw_task_completed'] ) ) : 0;
		$reopened  = isset( $_GET['yoohw_task_reopened'] ) ? absint( wp_unslash( $_GET['yoohw_task_reopened'] ) ) : 0;
		$error     = isset( $_GET['yoohw_task_error'] ) ? sanitize_key( wp_unslash( $_GET['yoohw_task_error'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( $created ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Task created successfully.', 'yoohw-customer-intelligence' ) . '</p></div>';
		}

		if ( $completed ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Task marked complete.', 'yoohw-customer-intelligence' ) . '</p></div>';
		}

		if ( $reopened ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Task reopened.', 'yoohw-customer-intelligence' ) . '</p></div>';
		}

		if ( '' !== $error ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Task action could not be completed.', 'yoohw-customer-intelligence' ) . '</p></div>';
		}
	}

	private static function render_task_assignee_select( int $selected_user_id, string $field_id, string $form_id = '' ): void {
		$users = YoOhw_COS_Tasks::get_assignable_users();

		echo '<select id="' . esc_attr( $field_id ) . '" name="assigned_user_id" class="widefat yoohw-cos-task-assignee-search" data-placeholder="' . esc_attr__( 'Search assignee', 'yoohw-customer-intelligence' ) . '"' . ( '' !== $form_id ? ' form="' . esc_attr( $form_id ) . '"' : '' ) . '>';
		echo '<option value="0">' . esc_html__( 'Unassigned', 'yoohw-customer-intelligence' ) . '</option>';

		foreach ( $users as $user ) {
			echo '<option value="' . esc_attr( absint( $user->ID ) ) . '" ' . selected( $selected_user_id, absint( $user->ID ), false ) . '>';
			echo esc_html( $user->display_name );
			echo '</option>';
		}

		echo '</select>';
	}

	private static function render_order_task_item( WC_Order $order, array $task ): void {
		$task_id     = absint( $task['id'] ?? 0 );
		$status      = YoOhw_COS_Tasks::normalize_status( (string) ( $task['status'] ?? YoOhw_COS_Tasks::STATUS_OPEN ) );
		$is_complete = YoOhw_COS_Tasks::STATUS_COMPLETED === $status;
		$action      = $is_complete ? 'reopen' : 'complete';
		$nonce       = 'yoohw_cos_' . $action . '_task';
		$redirect    = self::get_order_edit_url( $order ) . '#yoohw-cos-order-tasks';
		$action_url  = wp_nonce_url(
			add_query_arg(
				array(
					'action'    => 'yoohw_cos_' . $action . '_task',
					'task_id'   => $task_id,
					'_redirect' => rawurlencode( $redirect ),
				),
				admin_url( 'admin-post.php' )
			),
			$nonce
		);

		$priority = YoOhw_COS_Tasks::normalize_priority( (string) ( $task['priority'] ?? 'normal' ) );
		$due_date = YoOhw_COS_DB::format_admin_date( $task['due_date'] ?? '', '&mdash;' );
		$overdue  = ! $is_complete
			&& YoOhw_COS_DB::date_timestamp( $task['due_date'] ?? '' ) > 0
			&& YoOhw_COS_DB::date_timestamp( $task['due_date'] ?? '' ) < current_time( 'timestamp' );

		echo '<div class="yoohw-cos-order-task-item">';
		echo '<div class="yoohw-cos-order-task-item__main">';
		echo '<strong class="yoohw-cos-order-task-item__title">' . esc_html( $task['title'] ?? '' ) . '</strong>';
		echo '<div class="yoohw-cos-order-task-meta">';
		echo '<span class="yoohw-cos-order-task-badge yoohw-cos-order-task-badge--' . esc_attr( sanitize_html_class( $priority ) ) . '">' . esc_html( YoOhw_COS_Tasks::get_priorities()[ $priority ] ?? __( 'Normal', 'yoohw-customer-intelligence' ) ) . '</span>';

		if ( '&mdash;' !== $due_date ) {
			echo '<span class="' . esc_attr( $overdue ? 'yoohw-cos-order-task-overdue' : 'yoohw-cos-order-task-due' ) . '">' . wp_kses_post( $due_date ) . '</span>';
		}

		if ( ! empty( $task['assignee_name'] ) ) {
			echo '<span class="yoohw-cos-order-task-assignee">' . esc_html( $task['assignee_name'] ) . '</span>';
		}

		echo '</div>';
		echo '</div>';
		echo '<div class="yoohw-cos-order-task-actions"><a class="button button-small" href="' . esc_url( $action_url ) . '">' . esc_html( $is_complete ? __( 'Reopen', 'yoohw-customer-intelligence' ) : __( 'Complete', 'yoohw-customer-intelligence' ) ) . '</a></div>';
		echo '</div>';
	}

	private static function search_customers( string $term, bool $include_archived = false ): array {
		global $wpdb;

		$table = YoOhw_COS_DB::customers_table();
		$term  = trim( $term );
		$limit = 20;

		$where  = $include_archived ? 'WHERE 1=1' : 'WHERE archived_at IS NULL';
		$params = array( $table );

		if ( '' !== $term ) {
			$like = '%' . $wpdb->esc_like( $term ) . '%';
			$where .= ' AND (display_name LIKE %s OR first_name LIKE %s OR last_name LIKE %s OR email LIKE %s OR phone LIKE %s';
			$params = array_merge( $params, array( $like, $like, $like, $like, $like ) );

			foreach ( self::get_phone_search_needles( $term ) as $phone_needle ) {
				$where   .= ' OR ' . self::get_normalized_phone_sql_expression( 'phone' ) . ' LIKE %s';
				$params[] = '%' . $wpdb->esc_like( $phone_needle ) . '%';
			}

			if ( preg_match( '/^#?(\d+)$/', $term, $matches ) ) {
				$where .= ' OR id = %d OR wp_user_id = %d';
				$params[] = absint( $matches[1] );
				$params[] = absint( $matches[1] );
			}

			$where .= ')';
		}

		$params[] = $limit;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, wp_user_id, email, phone, first_name, last_name, display_name, archived_at FROM %i {$where} ORDER BY updated_at DESC LIMIT %d",
				...$params
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	private static function get_phone_search_needles( string $term ): array {
		$digits = self::normalize_phone_digits( $term );

		if ( '' === $digits ) {
			return array();
		}

		$needles = array( $digits );

		if ( '0' === substr( $digits, 0, 1 ) && strlen( $digits ) > 1 ) {
			$needles[] = '84' . substr( $digits, 1 );
		}

		if ( '84' === substr( $digits, 0, 2 ) && strlen( $digits ) > 2 ) {
			$needles[] = '0' . substr( $digits, 2 );
		}

		return array_values( array_unique( array_filter( $needles ) ) );
	}

	private static function normalize_phone_digits( string $phone ): string {
		return (string) preg_replace( '/\D+/', '', $phone );
	}

	private static function get_normalized_phone_sql_expression( string $column ): string {
		$column = sanitize_key( $column );

		return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE({$column}, ' ', ''), '-', ''), '(', ''), ')', ''), '+', ''), '.', ''), '/', '')";
	}

	private static function format_customer_option( array $customer, bool $show_archived_state = false ): string {
		$customer_id  = absint( $customer['id'] ?? 0 );
		$display_name = trim( sanitize_text_field( (string) ( $customer['display_name'] ?? '' ) ) );
		$email        = sanitize_email( $customer['email'] ?? '' );
		$phone        = sanitize_text_field( (string) ( $customer['phone'] ?? '' ) );

		if ( '' === $display_name ) {
			$display_name = trim(
				sanitize_text_field( (string) ( $customer['first_name'] ?? '' ) ) . ' ' . sanitize_text_field( (string) ( $customer['last_name'] ?? '' ) )
			);
		}

		if ( '' === $display_name ) {
			$display_name = $email ?: $phone;
		}

		if ( '' === $display_name ) {
			$display_name = sprintf(
				/* translators: %d: customer ID. */
				__( 'Customer #%d', 'yoohw-customer-intelligence' ),
				$customer_id
			);
		}

		$details = array_filter( array( $email, $phone ) );

		if ( empty( $details ) ) {
			$label = sprintf(
				/* translators: 1: customer name, 2: customer ID. */
				__( '%1$s (#%2$d)', 'yoohw-customer-intelligence' ),
				$display_name,
				$customer_id
			);

			return self::maybe_append_archived_label( $label, $customer, $show_archived_state );
		}

		$label = sprintf(
			/* translators: 1: customer name, 2: customer ID, 3: customer email or phone. */
			__( '%1$s (#%2$d - %3$s)', 'yoohw-customer-intelligence' ),
			$display_name,
			$customer_id,
			implode( ', ', $details )
		);

		return self::maybe_append_archived_label( $label, $customer, $show_archived_state );
	}

	private static function maybe_append_archived_label( string $label, array $customer, bool $show_archived_state ): string {
		if ( ! $show_archived_state || empty( $customer['archived_at'] ) ) {
			return $label;
		}

		return sprintf(
			/* translators: %s: customer option label. */
			__( '%s (Archived)', 'yoohw-customer-intelligence' ),
			$label
		);
	}

	private static function get_customer_profile_url( int $customer_id ): string {
		return add_query_arg(
			array(
				'page'        => 'yoohw-customer-intelligence',
				'customer_id' => absint( $customer_id ),
			),
			admin_url( 'admin.php' )
		);
	}
}
