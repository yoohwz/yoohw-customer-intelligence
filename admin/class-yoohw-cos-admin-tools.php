<?php
defined( 'ABSPATH' ) || exit;

final class YoOhw_COS_Admin_Tools {

	public static function init(): void {
		add_action( 'wp_ajax_yoohw_cos_ajax_sync_customers', array( __CLASS__, 'handle_ajax_sync_customers' ) );
		add_action( 'admin_post_yoohw_cos_sync_customers', array( __CLASS__, 'handle_sync_customers' ) );
		add_action( 'admin_post_yoohw_cos_reset_data', array( __CLASS__, 'handle_reset_data' ) );
		add_action( 'admin_post_yoohw_cos_assign_customer_tag', array( __CLASS__, 'handle_assign_customer_tag' ) );
		add_action( 'admin_post_yoohw_cos_remove_customer_tag', array( __CLASS__, 'handle_remove_customer_tag' ) );
		add_action( 'admin_post_yoohw_cos_add_customer_note', array( __CLASS__, 'handle_add_customer_note' ) );
		add_action( 'admin_post_yoohw_cos_update_customer_note', array( __CLASS__, 'handle_update_customer_note' ) );
		add_action( 'admin_post_yoohw_cos_delete_customer_note', array( __CLASS__, 'handle_delete_customer_note' ) );
		add_action( 'admin_post_yoohw_cos_recalculate_intelligence', array( __CLASS__, 'handle_recalculate_intelligence' ) );
		add_action( 'admin_post_yoohw_cos_backfill_first_orders', array( __CLASS__, 'handle_backfill_first_orders' ) );
		add_action( 'admin_post_yoohw_cos_assign_customer_segment', array( __CLASS__, 'handle_assign_customer_segment' ) );
		add_action( 'admin_post_yoohw_cos_remove_customer_segment', array( __CLASS__, 'handle_remove_customer_segment' ) );
		add_action( 'admin_post_yoohw_cos_create_tag', array( __CLASS__, 'handle_create_tag' ) );
		add_action( 'admin_post_yoohw_cos_update_tag', array( __CLASS__, 'handle_update_tag' ) );
		add_action( 'admin_post_yoohw_cos_delete_tag', array( __CLASS__, 'handle_delete_tag' ) );
		add_action( 'admin_post_yoohw_cos_create_segment', array( __CLASS__, 'handle_create_segment' ) );
		add_action( 'admin_post_yoohw_cos_delete_segment', array( __CLASS__, 'handle_delete_segment' ) );
		add_action( 'admin_post_yoohw_cos_update_segment', array( __CLASS__, 'handle_update_segment' ) );
		add_action( 'admin_post_yoohw_cos_create_task', array( __CLASS__, 'handle_create_task' ) );
		add_action( 'admin_post_yoohw_cos_update_task', array( __CLASS__, 'handle_update_task' ) );
		add_action( 'admin_post_yoohw_cos_complete_task', array( __CLASS__, 'handle_complete_task' ) );
		add_action( 'admin_post_yoohw_cos_reopen_task', array( __CLASS__, 'handle_reopen_task' ) );
		add_action( 'admin_post_yoohw_cos_delete_task', array( __CLASS__, 'handle_delete_task' ) );
	}

	public static function handle_sync_customers(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'yoohw-customer-intelligence' ) );
		}

		check_admin_referer( 'yoohw_cos_sync_customers' );

		$page  = isset( $_POST['sync_page'] ) ? absint( wp_unslash( $_POST['sync_page'] ) ) : 1;
		$page  = self::normalize_sync_page( $page );
		$limit = 200;

		$result = YoOhw_COS_Customers::sync_existing_orders( $limit, $page );
		self::update_sync_state( $page, $limit, $result );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                 => 'yoohw-customer-intelligence-settings',
					'yoohw_cos_processed'  => absint( $result['processed'] ),
					'yoohw_cos_next_page'  => absint( $result['next_page'] ),
					'yoohw_cos_has_more'   => ! empty( $result['has_more'] ) ? 1 : 0,
					'yoohw_cos_auto_sync'  => ! empty( $_POST['auto_sync'] ) ? 1 : 0,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_ajax_sync_customers(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to perform this action.', 'yoohw-customer-intelligence' ),
				),
				403
			);
		}

		check_ajax_referer( 'yoohw_cos_sync_customers', 'nonce' );

		$page  = isset( $_POST['sync_page'] ) ? absint( wp_unslash( $_POST['sync_page'] ) ) : 1;
		$page  = max( 1, $page );
		$page  = self::normalize_sync_page( $page );
		$limit = 200;

		$result = YoOhw_COS_Customers::sync_existing_orders( $limit, $page );
		$state  = self::update_sync_state( $page, $limit, $result );

		wp_send_json_success(
			array(
				'processed' => absint( $result['processed'] ?? 0 ),
				'scanned'   => absint( $result['scanned'] ?? 0 ),
				'hasMore'   => ! empty( $result['has_more'] ),
				'nextPage'  => absint( $result['next_page'] ?? $page ),
				'state'     => self::format_sync_state_for_response( $state ),
			)
		);
	}

	private static function normalize_sync_page( int $page ): int {
		$page = max( 1, absint( $page ) );

		if ( $page <= 1 ) {
			return 1;
		}

		$state = get_option( 'yoohw_cos_sync_state', array() );

		if ( ! is_array( $state ) ) {
			return 1;
		}

		$sync_order = isset( $state['sync_order'] ) ? sanitize_key( (string) $state['sync_order'] ) : '';

		if ( YoOhw_COS_Customers::SYNC_ORDER !== $sync_order ) {
			return 1;
		}

		return $page;
	}

	private static function update_sync_state( int $page, int $limit, array $result ): array {
		$now   = YoOhw_COS_DB::now();
		$state = get_option( 'yoohw_cos_sync_state', array() );

		$sync_order = is_array( $state ) && isset( $state['sync_order'] ) ? sanitize_key( (string) $state['sync_order'] ) : '';

		if ( ! is_array( $state ) || $page <= 1 || YoOhw_COS_Customers::SYNC_ORDER !== $sync_order ) {
			$state = array(
				'started_at'      => $now,
				'total_processed' => 0,
				'total_scanned'   => 0,
				'total_orders'    => YoOhw_COS_Customers::get_sync_order_count(),
				'sync_order'      => YoOhw_COS_Customers::SYNC_ORDER,
			);
		}

		$total_processed = absint( $state['total_processed'] ?? 0 ) + absint( $result['processed'] ?? 0 );
		$total_scanned   = absint( $state['total_scanned'] ?? 0 ) + absint( $result['scanned'] ?? 0 );
		$total_orders    = absint( $state['total_orders'] ?? 0 );

		if ( $total_orders <= 0 ) {
			$total_orders = YoOhw_COS_Customers::get_sync_order_count();
		}

		$has_more = ! empty( $result['has_more'] );
		$percent  = $total_orders > 0
			? min( 100, (int) round( ( $total_scanned / $total_orders ) * 100 ) )
			: ( $has_more ? 0 : 100 );

		$state = array_merge(
			$state,
			array(
				'status'          => $has_more ? 'in_progress' : 'completed',
				'batch_size'      => $limit,
				'last_page'       => $page,
				'next_page'       => absint( $result['next_page'] ?? $page ),
				'last_processed'  => absint( $result['processed'] ?? 0 ),
				'last_scanned'    => absint( $result['scanned'] ?? 0 ),
				'total_processed' => $total_processed,
				'total_scanned'   => $total_scanned,
				'total_orders'    => $total_orders,
				'percent'         => $percent,
				'has_more'        => $has_more ? 1 : 0,
				'last_run_at'     => $now,
				'completed_at'    => $has_more ? '' : $now,
				'sync_order'      => YoOhw_COS_Customers::SYNC_ORDER,
			)
		);

		update_option( 'yoohw_cos_sync_state', $state, false );
		update_option( 'yoohw_cos_last_sync_page', absint( $result['next_page'] ?? $page ), false );
		update_option( 'yoohw_cos_last_sync_at', $now, false );

		return $state;
	}

	private static function format_sync_state_for_response( array $state ): array {
		return array(
			'status'          => isset( $state['status'] ) ? sanitize_key( (string) $state['status'] ) : 'not_started',
			'syncOrder'       => isset( $state['sync_order'] ) ? sanitize_key( (string) $state['sync_order'] ) : '',
			'batchSize'       => absint( $state['batch_size'] ?? 0 ),
			'lastProcessed'   => absint( $state['last_processed'] ?? 0 ),
			'lastScanned'     => absint( $state['last_scanned'] ?? 0 ),
			'totalProcessed'  => absint( $state['total_processed'] ?? 0 ),
			'totalScanned'    => absint( $state['total_scanned'] ?? 0 ),
			'totalOrders'     => absint( $state['total_orders'] ?? 0 ),
			'percent'         => absint( $state['percent'] ?? 0 ),
			'hasMore'         => ! empty( $state['has_more'] ),
			'nextPage'        => absint( $state['next_page'] ?? 1 ),
			'lastRunAt'       => isset( $state['last_run_at'] ) ? sanitize_text_field( (string) $state['last_run_at'] ) : '',
			'completedAt'     => isset( $state['completed_at'] ) ? sanitize_text_field( (string) $state['completed_at'] ) : '',
		);
	}

	public static function handle_reset_data(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'yoohw-customer-intelligence' ) );
		}

		check_admin_referer( 'yoohw_cos_reset_data' );

		YoOhw_COS_Customers::reset_data();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => 'yoohw-customer-intelligence-settings',
					'yoohw_cos_reset'   => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_assign_customer_tag(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'yoohw-customer-intelligence' ) );
		}

		check_admin_referer( 'yoohw_cos_assign_customer_tag' );

		$customer_id = isset( $_POST['customer_id'] ) ? absint( wp_unslash( $_POST['customer_id'] ) ) : 0;
		$tag_name    = isset( $_POST['tag_name'] ) ? sanitize_text_field( wp_unslash( $_POST['tag_name'] ) ) : '';
		$assigned    = false;

		if ( $customer_id && $tag_name && YoOhw_COS_Customers::customer_exists( $customer_id ) ) {
			$tag_id = YoOhw_COS_Tags::create_tag( $tag_name );

			if ( $tag_id ) {
				$assigned = YoOhw_COS_Tags::assign_tag( $customer_id, $tag_id );
			}
		}

		wp_safe_redirect(
			add_query_arg(
					array(
						'page'        => 'yoohw-customer-intelligence',
						'customer_id' => $customer_id,
						'tag_added'   => $assigned ? 1 : 0,
					),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_remove_customer_tag(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'yoohw-customer-intelligence' ) );
		}

		check_admin_referer( 'yoohw_cos_remove_customer_tag' );

		$customer_id = isset( $_GET['customer_id'] ) ? absint( wp_unslash( $_GET['customer_id'] ) ) : 0;
		$tag_id      = isset( $_GET['tag_id'] ) ? absint( wp_unslash( $_GET['tag_id'] ) ) : 0;
		$removed     = false;

		if (
			$customer_id
			&& $tag_id
			&& YoOhw_COS_Customers::customer_exists( $customer_id )
			&& YoOhw_COS_Tags::tag_exists( $tag_id )
		) {
			$removed = YoOhw_COS_Tags::remove_tag( $customer_id, $tag_id );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
						'page'         => 'yoohw-customer-intelligence',
						'customer_id'  => $customer_id,
						'tag_removed'  => $removed ? 1 : 0,
					),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_add_customer_note(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'yoohw-customer-intelligence' ) );
		}

		check_admin_referer( 'yoohw_cos_add_customer_note' );

		$customer_id = isset( $_POST['customer_id'] ) ? absint( wp_unslash( $_POST['customer_id'] ) ) : 0;
		$note        = isset( $_POST['customer_note'] ) ? wp_kses_post( wp_unslash( $_POST['customer_note'] ) ) : '';
		$added       = false;

		if ( $customer_id && $note && YoOhw_COS_Customers::customer_exists( $customer_id ) ) {
			$added = (bool) YoOhw_COS_Notes::add_note( $customer_id, $note );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
						'page'        => 'yoohw-customer-intelligence',
						'customer_id' => $customer_id,
						'note_added'  => $added ? 1 : 0,
					),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_update_customer_note(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'yoohw-customer-intelligence' ) );
		}

		check_admin_referer( 'yoohw_cos_update_customer_note' );

		$customer_id = isset( $_POST['customer_id'] ) ? absint( wp_unslash( $_POST['customer_id'] ) ) : 0;
		$note_id     = isset( $_POST['note_id'] ) ? absint( wp_unslash( $_POST['note_id'] ) ) : 0;
		$content     = isset( $_POST['customer_note'] ) ? wp_kses_post( wp_unslash( $_POST['customer_note'] ) ) : '';
		$updated     = false;

		if (
			$customer_id
			&& $note_id
			&& $content
			&& YoOhw_COS_Notes::note_belongs_to_customer( $note_id, $customer_id )
		) {
			$updated = YoOhw_COS_Notes::update_note( $note_id, $content );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
						'page'         => 'yoohw-customer-intelligence',
						'customer_id'  => $customer_id,
						'note_updated' => $updated ? 1 : 0,
					),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_delete_customer_note(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'yoohw-customer-intelligence' ) );
		}

		check_admin_referer( 'yoohw_cos_delete_customer_note' );

		$customer_id = isset( $_GET['customer_id'] ) ? absint( wp_unslash( $_GET['customer_id'] ) ) : 0;
		$note_id     = isset( $_GET['note_id'] ) ? absint( wp_unslash( $_GET['note_id'] ) ) : 0;
		$deleted     = false;

		if (
			$customer_id
			&& $note_id
			&& YoOhw_COS_Notes::note_belongs_to_customer( $note_id, $customer_id )
		) {
			$deleted = YoOhw_COS_Notes::delete_note( $note_id );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
						'page'         => 'yoohw-customer-intelligence',
						'customer_id'  => $customer_id,
						'note_deleted' => $deleted ? 1 : 0,
					),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_recalculate_intelligence(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'yoohw-customer-intelligence' ) );
		}

		check_admin_referer( 'yoohw_cos_recalculate_intelligence' );

		$page  = isset( $_POST['recalculate_page'] ) ? absint( wp_unslash( $_POST['recalculate_page'] ) ) : 1;
		$limit = 500;

		$result = YoOhw_COS_Customers::recalculate_intelligence( $limit, $page );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                         => 'yoohw-customer-intelligence-settings',
					'yoohw_cos_recalculated'       => absint( $result['updated'] ),
					'yoohw_cos_recalculate_next'   => absint( $result['next_page'] ),
					'yoohw_cos_recalculate_more'   => ! empty( $result['has_more'] ) ? 1 : 0,
					'yoohw_cos_recalculate_auto'   => ! empty( $_POST['auto_recalculate'] ) ? 1 : 0,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_backfill_first_orders(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'yoohw-customer-intelligence' ) );
		}

		check_admin_referer( 'yoohw_cos_backfill_first_orders' );

		$page  = isset( $_POST['backfill_page'] ) ? absint( wp_unslash( $_POST['backfill_page'] ) ) : 1;
		$limit = 500;

		$result = YoOhw_COS_Customers::backfill_first_order_data( $limit, $page );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                    => 'yoohw-customer-intelligence-settings',
					'yoohw_cos_backfilled'    => absint( $result['updated'] ),
					'yoohw_cos_backfill_next' => absint( $result['next_page'] ),
					'yoohw_cos_backfill_more' => ! empty( $result['has_more'] ) ? 1 : 0,
					'yoohw_cos_backfill_auto' => ! empty( $_POST['auto_backfill'] ) ? 1 : 0,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_assign_customer_segment(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'yoohw-customer-intelligence' ) );
		}

		check_admin_referer( 'yoohw_cos_assign_customer_segment' );

		$customer_id  = isset( $_POST['customer_id'] ) ? absint( wp_unslash( $_POST['customer_id'] ) ) : 0;
		$segment_name = isset( $_POST['segment_name'] ) ? sanitize_text_field( wp_unslash( $_POST['segment_name'] ) ) : '';
		$assigned     = false;

		if ( $customer_id && $segment_name && YoOhw_COS_Customers::customer_exists( $customer_id ) ) {
			$segment_id = YoOhw_COS_Segments::create_segment( $segment_name );

			if ( $segment_id ) {
				$assigned = YoOhw_COS_Segments::assign_customer( $customer_id, $segment_id );
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
						'page'          => 'yoohw-customer-intelligence',
						'customer_id'   => $customer_id,
						'segment_added' => $assigned ? 1 : 0,
					),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_remove_customer_segment(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'yoohw-customer-intelligence' ) );
		}

		check_admin_referer( 'yoohw_cos_remove_customer_segment' );

		$customer_id = isset( $_GET['customer_id'] ) ? absint( wp_unslash( $_GET['customer_id'] ) ) : 0;
		$segment_id  = isset( $_GET['segment_id'] ) ? absint( wp_unslash( $_GET['segment_id'] ) ) : 0;
		$removed     = false;

		if (
			$customer_id
			&& $segment_id
			&& YoOhw_COS_Customers::customer_exists( $customer_id )
			&& YoOhw_COS_Segments::segment_exists( $segment_id )
		) {
			$removed = YoOhw_COS_Segments::remove_customer( $customer_id, $segment_id );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
						'page'            => 'yoohw-customer-intelligence',
						'customer_id'     => $customer_id,
						'segment_removed' => $removed ? 1 : 0,
					),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_create_task(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'yoohw-customer-intelligence' ) );
		}

		check_admin_referer( 'yoohw_cos_create_task' );

		$data  = self::get_task_post_data();
		$error = self::validate_task_data( $data );

		if ( '' !== $error ) {
			wp_safe_redirect(
				self::get_task_action_redirect(
					array(
						'yoohw_task_error' => $error,
					)
				)
			);
			exit;
		}

		$task_id = YoOhw_COS_Tasks::create_task( $data );

		wp_safe_redirect(
			self::get_task_action_redirect(
				array(
					'yoohw_task_created' => $task_id ? 1 : 0,
					'yoohw_task_error'   => $task_id ? null : 'save_failed',
				)
			)
		);
		exit;
	}

	public static function handle_update_task(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'yoohw-customer-intelligence' ) );
		}

		check_admin_referer( 'yoohw_cos_update_task' );

		$task_id = isset( $_POST['task_id'] ) ? absint( wp_unslash( $_POST['task_id'] ) ) : 0;

		if ( $task_id <= 0 || empty( YoOhw_COS_Tasks::get_task( $task_id ) ) ) {
			wp_safe_redirect(
				self::get_task_action_redirect(
					array(
						'yoohw_task_error' => 'invalid_task',
					)
				)
			);
			exit;
		}

		$data  = self::get_task_post_data();
		$error = self::validate_task_data( $data );

		if ( '' !== $error ) {
			wp_safe_redirect(
				self::get_task_action_redirect(
					array(
						'task_id'          => $task_id,
						'yoohw_task_error' => $error,
					)
				)
			);
			exit;
		}

		$updated = YoOhw_COS_Tasks::update_task( $task_id, $data );

		wp_safe_redirect(
			self::get_task_action_redirect(
				array(
					'yoohw_task_updated' => $updated ? 1 : 0,
					'yoohw_task_error'   => $updated ? null : 'save_failed',
				)
			)
		);
		exit;
	}

	public static function handle_complete_task(): void {
		self::handle_task_status_action( 'complete' );
	}

	public static function handle_reopen_task(): void {
		self::handle_task_status_action( 'reopen' );
	}

	public static function handle_delete_task(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'yoohw-customer-intelligence' ) );
		}

		check_admin_referer( 'yoohw_cos_delete_task' );

		$task_id = isset( $_GET['task_id'] ) ? absint( wp_unslash( $_GET['task_id'] ) ) : 0;
		$deleted = $task_id > 0 ? YoOhw_COS_Tasks::delete_task( $task_id ) : false;

		wp_safe_redirect(
			self::get_task_action_redirect(
				array(
					'yoohw_task_deleted' => $deleted ? 1 : 0,
					'yoohw_task_error'   => $deleted ? null : 'invalid_task',
				)
			)
		);
		exit;
	}

	private static function handle_task_status_action( string $task_action ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'yoohw-customer-intelligence' ) );
		}

		$task_action = sanitize_key( $task_action );
		$nonce       = 'complete' === $task_action ? 'yoohw_cos_complete_task' : 'yoohw_cos_reopen_task';

		check_admin_referer( $nonce );

		$task_id = isset( $_GET['task_id'] ) ? absint( wp_unslash( $_GET['task_id'] ) ) : 0;
		$updated = false;

		if ( $task_id > 0 ) {
			$updated = 'complete' === $task_action
				? YoOhw_COS_Tasks::complete_task( $task_id )
				: YoOhw_COS_Tasks::reopen_task( $task_id );
		}

		wp_safe_redirect(
			self::get_task_action_redirect(
				array(
					'yoohw_task_completed' => 'complete' === $task_action && $updated ? 1 : null,
					'yoohw_task_reopened'  => 'reopen' === $task_action && $updated ? 1 : null,
					'yoohw_task_error'     => $updated ? null : 'invalid_task',
				)
			)
		);
		exit;
	}

	private static function get_task_post_data(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Called only after task action handlers verify their nonce with check_admin_referer().
		return array(
			'customer_id'      => isset( $_POST['customer_id'] ) ? absint( wp_unslash( $_POST['customer_id'] ) ) : 0,
			'order_id'         => isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0,
			'assigned_user_id' => isset( $_POST['assigned_user_id'] ) ? absint( wp_unslash( $_POST['assigned_user_id'] ) ) : 0,
			'title'            => isset( $_POST['task_title'] ) ? sanitize_text_field( wp_unslash( $_POST['task_title'] ) ) : '',
			'description'      => isset( $_POST['task_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['task_description'] ) ) : '',
			'status'           => isset( $_POST['task_status'] ) ? sanitize_key( wp_unslash( $_POST['task_status'] ) ) : YoOhw_COS_Tasks::STATUS_OPEN,
			'priority'         => isset( $_POST['task_priority'] ) ? sanitize_key( wp_unslash( $_POST['task_priority'] ) ) : 'normal',
			'due_date'         => isset( $_POST['task_due_date'] ) ? sanitize_text_field( wp_unslash( $_POST['task_due_date'] ) ) : '',
			'created_by'       => get_current_user_id(),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	private static function validate_task_data( array $data ): string {
		if ( empty( $data['customer_id'] ) || ! YoOhw_COS_Customers::customer_exists( absint( $data['customer_id'] ) ) ) {
			return 'missing_customer';
		}

		if ( '' === trim( (string) ( $data['title'] ?? '' ) ) ) {
			return 'missing_title';
		}

		if ( ! empty( $data['assigned_user_id'] ) && ! YoOhw_COS_Tasks::is_assignable_user( absint( $data['assigned_user_id'] ) ) ) {
			return 'invalid_assignee';
		}

		return '';
	}

	private static function redirect_to_tasks( array $args = array() ): void {
		$args = array_filter(
			array_merge(
				array(
					'page' => 'yoohw-customer-intelligence-tasks',
				),
				$args
			),
			static function( $value ): bool {
				return null !== $value && '' !== $value;
			}
		);

		wp_safe_redirect(
			add_query_arg(
				$args,
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private static function get_task_action_redirect( array $args = array() ): string {
		$default = admin_url( 'admin.php?page=yoohw-customer-intelligence-tasks' );
		$target  = isset( $_REQUEST['_redirect'] )
			? rawurldecode( esc_url_raw( wp_unslash( $_REQUEST['_redirect'] ) ) )
			: $default;

		$target = wp_validate_redirect( $target, $default );

		$args = array_filter(
			$args,
			static function( $value ): bool {
				return null !== $value && '' !== $value;
			}
		);

		return add_query_arg( $args, $target );
	}

	public static function handle_create_tag(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'yoohw-customer-intelligence' ) );
		}

		check_admin_referer( 'yoohw_cos_create_tag' );

		$name        = isset( $_POST['tag_name'] ) ? sanitize_text_field( wp_unslash( $_POST['tag_name'] ) ) : '';
		$color       = isset( $_POST['tag_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['tag_color'] ) ) : '';
		$description = isset( $_POST['tag_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['tag_description'] ) ) : '';

		if ( '' === $name ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'            => 'yoohw-customer-intelligence-tags',
						'yoohw_tag_error' => 'missing_name',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		if ( YoOhw_COS_Tags::get_tag_by_slug( sanitize_title( $name ) ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'            => 'yoohw-customer-intelligence-tags',
						'yoohw_tag_error' => 'duplicate_name',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$tag_id = YoOhw_COS_Tags::create_tag( $name, $color, $description );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => 'yoohw-customer-intelligence-tags',
					'yoohw_tag_created' => $tag_id ? 1 : 0,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_update_tag(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'yoohw-customer-intelligence' ) );
		}

		check_admin_referer( 'yoohw_cos_update_tag' );

		$tag_id      = isset( $_POST['tag_id'] ) ? absint( wp_unslash( $_POST['tag_id'] ) ) : 0;
		$name        = isset( $_POST['tag_name'] ) ? sanitize_text_field( wp_unslash( $_POST['tag_name'] ) ) : '';
		$color       = isset( $_POST['tag_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['tag_color'] ) ) : '';
		$description = isset( $_POST['tag_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['tag_description'] ) ) : '';

		$updated = false;

		if ( '' === $name || ! YoOhw_COS_Tags::tag_exists( $tag_id ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'            => 'yoohw-customer-intelligence-tags',
						'edit_tag'        => $tag_id,
						'yoohw_tag_error' => 'missing_name',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$existing = YoOhw_COS_Tags::get_tag_by_slug( sanitize_title( $name ) );

		if ( ! empty( $existing ) && absint( $existing['id'] ?? 0 ) !== $tag_id ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'            => 'yoohw-customer-intelligence-tags',
						'edit_tag'        => $tag_id,
						'yoohw_tag_error' => 'duplicate_name',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		if ( $tag_id && $name ) {
			$updated = YoOhw_COS_Tags::update_tag( $tag_id, $name, $color, $description );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => 'yoohw-customer-intelligence-tags',
					'yoohw_tag_updated' => $updated ? 1 : 0,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_delete_tag(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'yoohw-customer-intelligence' ) );
		}

		check_admin_referer( 'yoohw_cos_delete_tag' );

		$tag_id = isset( $_GET['tag_id'] ) ? absint( wp_unslash( $_GET['tag_id'] ) ) : 0;
		$force  = ! empty( $_GET['force'] );

		if ( ! $tag_id ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'              => 'yoohw-customer-intelligence-tags',
						'yoohw_tag_deleted' => 0,
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$count = YoOhw_COS_Tags::get_tag_customer_count( $tag_id );

		if ( $count > 0 && ! $force ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'                    => 'yoohw-customer-intelligence-tags',
						'yoohw_tag_delete_block'  => $tag_id,
						'tag_customer_count'      => $count,
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$deleted = YoOhw_COS_Tags::delete_tag( $tag_id, $force );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => 'yoohw-customer-intelligence-tags',
					'yoohw_tag_deleted' => $deleted ? 1 : 0,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_create_segment(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'yoohw-customer-intelligence' ) );
		}

		check_admin_referer( 'yoohw_cos_create_segment' );

		$name        = isset( $_POST['segment_name'] ) ? sanitize_text_field( wp_unslash( $_POST['segment_name'] ) ) : '';
		$description = isset( $_POST['segment_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['segment_description'] ) ) : '';

		if ( '' === $name ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'                => 'yoohw-customer-intelligence-segments',
						'yoohw_segment_error' => 'missing_name',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		if ( YoOhw_COS_Segments::get_segment_by_slug( sanitize_title( $name ) ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'                => 'yoohw-customer-intelligence-segments',
						'yoohw_segment_error' => 'duplicate_name',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$segment_id = YoOhw_COS_Segments::create_segment(
			$name,
			'static',
			$description
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                 => 'yoohw-customer-intelligence-segments',
					'yoohw_segment_created'=> $segment_id ? 1 : 0,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_delete_segment(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'yoohw-customer-intelligence' ) );
		}

		check_admin_referer( 'yoohw_cos_delete_segment' );

		$segment_id = isset( $_GET['segment_id'] ) ? absint( wp_unslash( $_GET['segment_id'] ) ) : 0;
		$force      = ! empty( $_GET['force'] );

		if ( ! $segment_id ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'                  => 'yoohw-customer-intelligence-segments',
						'yoohw_segment_deleted' => 0,
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$count = YoOhw_COS_Segments::get_segment_customer_count( $segment_id );

		if ( $count > 0 && ! $force ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'                       => 'yoohw-customer-intelligence-segments',
						'yoohw_segment_delete_block' => $segment_id,
						'segment_customer_count'     => $count,
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$deleted = YoOhw_COS_Segments::delete_segment( $segment_id, $force );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                  => 'yoohw-customer-intelligence-segments',
					'yoohw_segment_deleted' => $deleted ? 1 : 0,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_update_segment(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'yoohw-customer-intelligence' ) );
		}

		check_admin_referer( 'yoohw_cos_update_segment' );

		$segment_id  = isset( $_POST['segment_id'] ) ? absint( wp_unslash( $_POST['segment_id'] ) ) : 0;
		$name        = isset( $_POST['segment_name'] ) ? sanitize_text_field( wp_unslash( $_POST['segment_name'] ) ) : '';
		$description = isset( $_POST['segment_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['segment_description'] ) ) : '';

		$updated = false;

		if ( '' === $name || ! YoOhw_COS_Segments::segment_exists( $segment_id ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'                => 'yoohw-customer-intelligence-segments',
						'edit_segment'        => $segment_id,
						'yoohw_segment_error' => 'missing_name',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$existing = YoOhw_COS_Segments::get_segment_by_slug( sanitize_title( $name ) );

		if ( ! empty( $existing ) && absint( $existing['id'] ?? 0 ) !== $segment_id ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'                => 'yoohw-customer-intelligence-segments',
						'edit_segment'        => $segment_id,
						'yoohw_segment_error' => 'duplicate_name',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		if ( $segment_id && $name ) {
			$updated = YoOhw_COS_Segments::update_segment( $segment_id, $name, $description );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                  => 'yoohw-customer-intelligence-segments',
					'yoohw_segment_updated' => $updated ? 1 : 0,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
