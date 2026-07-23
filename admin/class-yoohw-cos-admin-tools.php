<?php
defined( 'ABSPATH' ) || exit;

final class YoOhw_COS_Admin_Tools {

	public static function init(): void {
		add_action( 'wp_ajax_yoohw_cos_send_customer_email', array( __CLASS__, 'handle_send_customer_email' ) );
		add_action( 'wp_ajax_yoohw_cos_ajax_sync_customers', array( __CLASS__, 'handle_ajax_sync_customers' ) );
		add_action( 'wp_ajax_yoohw_cos_ajax_recalculate_intelligence', array( __CLASS__, 'handle_ajax_recalculate_intelligence' ) );
		add_action( 'wp_ajax_yoohw_cos_ajax_backfill_first_orders', array( __CLASS__, 'handle_ajax_backfill_first_orders' ) );
		add_action( 'wp_ajax_yoohw_cos_ajax_sync_blacklist_signals', array( __CLASS__, 'handle_ajax_sync_blacklist_signals' ) );
		add_action( 'admin_post_yoohw_cos_sync_customers', array( __CLASS__, 'handle_sync_customers' ) );
		add_action( 'admin_post_yoohw_cos_reset_data', array( __CLASS__, 'handle_reset_data' ) );
		add_action( 'admin_post_yoohw_cos_assign_customer_tag', array( __CLASS__, 'handle_assign_customer_tag' ) );
		add_action( 'admin_post_yoohw_cos_remove_customer_tag', array( __CLASS__, 'handle_remove_customer_tag' ) );
		add_action( 'admin_post_yoohw_cos_add_customer_note', array( __CLASS__, 'handle_add_customer_note' ) );
		add_action( 'admin_post_yoohw_cos_update_customer_note', array( __CLASS__, 'handle_update_customer_note' ) );
		add_action( 'admin_post_yoohw_cos_delete_customer_note', array( __CLASS__, 'handle_delete_customer_note' ) );
		add_action( 'admin_post_yoohw_cos_recalculate_intelligence', array( __CLASS__, 'handle_recalculate_intelligence' ) );
		add_action( 'admin_post_yoohw_cos_backfill_first_orders', array( __CLASS__, 'handle_backfill_first_orders' ) );
		add_action( 'admin_post_yoohw_cos_sync_blacklist_signals', array( __CLASS__, 'handle_sync_blacklist_signals' ) );
		add_action( 'admin_post_yoohw_cos_save_scoring_settings', array( __CLASS__, 'handle_save_scoring_settings' ) );
		add_action( 'admin_post_yoohw_cos_save_loyalty_task_automation', array( __CLASS__, 'handle_save_loyalty_task_automation' ) );
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

	public static function handle_send_customer_email(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to send customer emails.', 'yoohw-customer-intelligence' ),
				),
				403
			);
		}

		check_ajax_referer( 'yoohw_cos_send_customer_email', 'security' );

		$customer_id = isset( $_POST['customer_id'] ) ? absint( wp_unslash( $_POST['customer_id'] ) ) : 0;
		$subject     = isset( $_POST['email_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['email_subject'] ) ) : '';
		$message     = isset( $_POST['email_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['email_message'] ) ) : '';
		$customer    = $customer_id > 0 ? YoOhw_COS_Customers::get_customer( $customer_id ) : array();

		if ( empty( $customer ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'The requested customer profile does not exist.', 'yoohw-customer-intelligence' ),
				),
				404
			);
		}

		$recipient = sanitize_email( (string) ( $customer['email'] ?? '' ) );

		if ( ! is_email( $recipient ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'This customer profile does not have a valid email address.', 'yoohw-customer-intelligence' ),
				),
				400
			);
		}

		if ( '' === trim( $subject ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Enter an email subject.', 'yoohw-customer-intelligence' ),
				),
				400
			);
		}

		if ( '' === trim( $message ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Enter an email message.', 'yoohw-customer-intelligence' ),
				),
				400
			);
		}

		$result = YoOhw_COS_Email_Notifications::send_customer_message( $customer, $subject, $message );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
				),
				500
			);
		}

		YoOhw_COS_Events::record(
			array(
				'customer_id'  => $customer_id,
				'event_type'   => 'customer_email_sent',
				'event_source' => 'customer_os',
				'object_type'  => 'customer',
				'object_id'    => $customer_id,
				'description'  => sprintf(
					/* translators: 1: customer email address, 2: email subject. */
					__( 'Email sent to %1$s with subject “%2$s”.', 'yoohw-customer-intelligence' ),
					$recipient,
					$subject
				),
				'metadata'     => array(
					'recipient'       => $recipient,
					'subject'         => $subject,
					'sent_by_user_id' => get_current_user_id(),
				),
			)
		);

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: customer email address. */
					__( 'Email sent to %s.', 'yoohw-customer-intelligence' ),
					$recipient
				),
			)
		);
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

	public static function handle_ajax_recalculate_intelligence(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			self::send_operation_error( __( 'You do not have permission to perform this action.', 'yoohw-customer-intelligence' ), 403 );
		}

		check_ajax_referer( 'yoohw_cos_recalculate_intelligence', 'nonce' );

		$page  = isset( $_POST['recalculate_page'] ) ? absint( wp_unslash( $_POST['recalculate_page'] ) ) : 1;
		$page  = max( 1, $page );
		$limit = 500;

		$result = YoOhw_COS_Customers::recalculate_intelligence( $limit, $page );
		$result = self::normalize_customer_operation_result( $result );
		$state  = self::update_operation_sync_state(
			'recalculate_intelligence',
			$page,
			$limit,
			$result,
			self::count_customer_rows(),
			$page <= 1
		);

		self::send_operation_success( $result, $state );
	}

	public static function handle_ajax_backfill_first_orders(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			self::send_operation_error( __( 'You do not have permission to perform this action.', 'yoohw-customer-intelligence' ), 403 );
		}

		check_ajax_referer( 'yoohw_cos_backfill_first_orders', 'nonce' );

		$page  = isset( $_POST['backfill_page'] ) ? absint( wp_unslash( $_POST['backfill_page'] ) ) : 1;
		$page  = max( 1, $page );
		$limit = 500;

		$result = YoOhw_COS_Customers::backfill_first_order_data( $limit, $page );
		$result = self::normalize_customer_operation_result( $result );
		$state  = self::update_operation_sync_state(
			'backfill_first_orders',
			$page,
			$limit,
			$result,
			self::count_customer_rows(),
			$page <= 1
		);

		self::send_operation_success( $result, $state );
	}

	public static function handle_ajax_sync_blacklist_signals(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			self::send_operation_error( __( 'You do not have permission to perform this action.', 'yoohw-customer-intelligence' ), 403 );
		}

		check_ajax_referer( 'yoohw_cos_sync_blacklist_signals', 'nonce' );

		if ( ! self::is_blacklist_manager_sync_available() ) {
			self::send_operation_error( __( 'Blacklist Manager integration is not active.', 'yoohw-customer-intelligence' ), 403 );
		}

		$page  = isset( $_POST['blacklist_sync_page'] ) ? absint( wp_unslash( $_POST['blacklist_sync_page'] ) ) : 1;
		$page  = max( 1, $page );
		$stage = isset( $_POST['blacklist_sync_stage'] ) ? sanitize_key( wp_unslash( $_POST['blacklist_sync_stage'] ) ) : 'core';
		$limit = 300;

		$batch = self::run_blacklist_signal_sync_batch( $limit, $page, $stage );
		$result = $batch['result'];
		$state  = self::update_operation_sync_state(
			'blacklist_signals',
			$page,
			$limit,
			$result,
			self::count_blacklist_sync_rows(),
			'core' === $stage && $page <= 1
		);

		self::send_operation_success( $result, $state, $batch['next_stage'] );
	}

	public static function handle_save_loyalty_task_automation(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'yoohw-customer-intelligence' ) );
		}

		check_admin_referer( 'yoohw_cos_save_loyalty_task_automation' );

		$saved = false;

		if (
			class_exists( 'YoOhw_COS_Loyalty_Integration' )
			&& is_callable( array( 'YoOhw_COS_Loyalty_Integration', 'is_loyalty_plugin_active' ) )
			&& YoOhw_COS_Loyalty_Integration::is_loyalty_plugin_active()
			&& is_callable( array( 'YoOhw_COS_Loyalty_Integration', 'update_task_automation_settings' ) )
		) {
			YoOhw_COS_Loyalty_Integration::update_task_automation_settings( wp_unslash( $_POST ) );
			$saved = true;
		}

		wp_safe_redirect(
			add_query_arg(
				array_filter(
					array(
						'page'                                     => 'yoohw-customer-intelligence-settings',
						'yoohw_cos_loyalty_task_automation_saved'  => $saved ? 1 : 0,
					)
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_save_scoring_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'yoohw-customer-intelligence' ) );
		}

		check_admin_referer( 'yoohw_cos_save_scoring_settings' );

		$source = isset( $_POST['scoring'] ) && is_array( $_POST['scoring'] )
			? wp_unslash( $_POST['scoring'] )
			: array();

		YoOhw_COS_Intelligence::update_scoring_settings( $source );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                              => 'yoohw-customer-intelligence-settings',
					'yoohw_cos_scoring_settings_saved'  => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
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
			'totalItems'      => absint( $state['total_orders'] ?? 0 ),
			'totalSkipped'    => 0,
			'percent'         => absint( $state['percent'] ?? 0 ),
			'hasMore'         => ! empty( $state['has_more'] ),
			'nextPage'        => absint( $state['next_page'] ?? 1 ),
			'lastRunAt'       => isset( $state['last_run_at'] ) ? sanitize_text_field( (string) $state['last_run_at'] ) : '',
			'completedAt'     => isset( $state['completed_at'] ) ? sanitize_text_field( (string) $state['completed_at'] ) : '',
		);
	}

	private static function normalize_customer_operation_result( array $result ): array {
		$updated = absint( $result['updated'] ?? 0 );
		$scanned = absint( $result['scanned'] ?? $updated );

		$result['processed'] = $updated;
		$result['scanned']   = $scanned;
		$result['skipped']   = max( 0, $scanned - $updated );

		return $result;
	}

	private static function update_operation_sync_state( string $operation, int $page, int $limit, array $result, int $total_items, bool $reset ): array {
		$operation = sanitize_key( $operation );
		$now       = YoOhw_COS_DB::now();
		$option    = self::operation_sync_state_option( $operation );
		$state     = get_option( $option, array() );

		if ( ! is_array( $state ) || empty( $state ) || $reset ) {
			$state = array(
				'operation'       => $operation,
				'started_at'      => $now,
				'total_processed' => 0,
				'total_scanned'   => 0,
				'total_skipped'   => 0,
				'total_items'     => absint( $total_items ),
			);
		}

		$last_processed = absint( $result['processed'] ?? 0 );
		$last_scanned   = absint( $result['scanned'] ?? $last_processed );
		$last_skipped   = absint( $result['skipped'] ?? max( 0, $last_scanned - $last_processed ) );
		$total_items    = absint( $total_items );

		if ( $total_items <= 0 ) {
			$total_items = absint( $state['total_items'] ?? 0 );
		}

		$total_processed = absint( $state['total_processed'] ?? 0 ) + $last_processed;
		$total_scanned   = absint( $state['total_scanned'] ?? 0 ) + $last_scanned;
		$total_skipped   = absint( $state['total_skipped'] ?? 0 ) + $last_skipped;
		$has_more        = ! empty( $result['has_more'] );
		$percent         = $total_items > 0
			? min( 100, (int) round( ( $total_scanned / $total_items ) * 100 ) )
			: ( $has_more ? 0 : 100 );

		$state = array_merge(
			$state,
			array(
				'operation'       => $operation,
				'status'          => $has_more ? 'in_progress' : 'completed',
				'batch_size'      => $limit,
				'last_page'       => $page,
				'next_page'       => absint( $result['next_page'] ?? $page ),
				'last_processed'  => $last_processed,
				'last_scanned'    => $last_scanned,
				'last_skipped'    => $last_skipped,
				'total_processed' => $total_processed,
				'total_scanned'   => $total_scanned,
				'total_skipped'   => $total_skipped,
				'total_items'     => $total_items,
				'percent'         => $percent,
				'has_more'        => $has_more ? 1 : 0,
				'stage'           => sanitize_key( (string) ( $result['stage'] ?? '' ) ),
				'last_run_at'     => $now,
				'completed_at'    => $has_more ? '' : $now,
			)
		);

		update_option( $option, $state, false );

		return $state;
	}

	private static function format_operation_state_for_response( array $state ): array {
		$stage = isset( $state['stage'] ) ? sanitize_key( (string) $state['stage'] ) : '';

		return array(
			'operation'       => isset( $state['operation'] ) ? sanitize_key( (string) $state['operation'] ) : '',
			'status'          => isset( $state['status'] ) ? sanitize_key( (string) $state['status'] ) : 'not_started',
			'batchSize'       => absint( $state['batch_size'] ?? 0 ),
			'lastProcessed'   => absint( $state['last_processed'] ?? 0 ),
			'lastScanned'     => absint( $state['last_scanned'] ?? 0 ),
			'lastSkipped'     => absint( $state['last_skipped'] ?? 0 ),
			'totalProcessed'  => absint( $state['total_processed'] ?? 0 ),
			'totalScanned'    => absint( $state['total_scanned'] ?? 0 ),
			'totalSkipped'    => absint( $state['total_skipped'] ?? 0 ),
			'totalItems'      => absint( $state['total_items'] ?? 0 ),
			'totalOrders'     => absint( $state['total_items'] ?? 0 ),
			'percent'         => absint( $state['percent'] ?? 0 ),
			'hasMore'         => ! empty( $state['has_more'] ),
			'nextPage'        => absint( $state['next_page'] ?? 1 ),
			'stage'           => $stage,
			'stageLabel'      => self::format_operation_stage_label( $stage ),
			'lastRunAt'       => isset( $state['last_run_at'] ) ? sanitize_text_field( (string) $state['last_run_at'] ) : '',
			'completedAt'     => isset( $state['completed_at'] ) ? sanitize_text_field( (string) $state['completed_at'] ) : '',
		);
	}

	private static function send_operation_success( array $result, array $state, string $next_stage = '' ): void {
		wp_send_json_success(
			array(
				'processed'  => absint( $result['processed'] ?? 0 ),
				'scanned'    => absint( $result['scanned'] ?? 0 ),
				'skipped'    => absint( $result['skipped'] ?? 0 ),
				'hasMore'    => ! empty( $result['has_more'] ),
				'nextPage'   => absint( $result['next_page'] ?? 1 ),
				'stage'      => sanitize_key( (string) ( $result['stage'] ?? '' ) ),
				'nextStage'  => sanitize_key( $next_stage ),
				'state'      => self::format_operation_state_for_response( $state ),
			)
		);
	}

	private static function send_operation_error( string $message, int $status_code = 400 ): void {
		wp_send_json_error(
			array(
				'message' => $message,
			),
			$status_code
		);
	}

	private static function operation_sync_state_option( string $operation ): string {
		return 'yoohw_cos_operation_sync_state_' . sanitize_key( $operation );
	}

	private static function format_operation_stage_label( string $stage ): string {
		$stage = sanitize_key( $stage );

		$labels = array(
			''                    => __( 'Current batch', 'yoohw-customer-intelligence' ),
			'complete'            => __( 'Complete', 'yoohw-customer-intelligence' ),
			'core'                => __( 'Core blacklist', 'yoohw-customer-intelligence' ),
			'core_unavailable'    => __( 'Core unavailable', 'yoohw-customer-intelligence' ),
			'detection_log'       => __( 'Premium detection log', 'yoohw-customer-intelligence' ),
			'orders'              => __( 'Premium order risk', 'yoohw-customer-intelligence' ),
			'payment_abuse'       => __( 'Premium payment abuse', 'yoohw-customer-intelligence' ),
			'premium_unavailable' => __( 'Premium unavailable', 'yoohw-customer-intelligence' ),
		);

		return $labels[ $stage ] ?? ucwords( str_replace( '_', ' ', $stage ) );
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
		$tag_id      = isset( $_POST['tag_id'] ) ? absint( wp_unslash( $_POST['tag_id'] ) ) : 0;
		$tag_name    = isset( $_POST['tag_name'] ) ? sanitize_text_field( wp_unslash( $_POST['tag_name'] ) ) : '';
		$tag_name    = '' !== $tag_name || ! isset( $_POST['tag_name_nojs'] )
			? $tag_name
			: sanitize_textarea_field( wp_unslash( $_POST['tag_name_nojs'] ) );
		$assigned    = false;

		if ( $customer_id && YoOhw_COS_Customers::customer_exists( $customer_id ) ) {
			if ( $tag_id && YoOhw_COS_Tags::tag_exists( $tag_id ) ) {
				$assigned = YoOhw_COS_Tags::assign_tag( $customer_id, $tag_id );
			}

			foreach ( self::parse_relationship_names( $tag_name ) as $name ) {
				$created_tag_id = YoOhw_COS_Tags::create_tag( $name );

				if ( $created_tag_id && YoOhw_COS_Tags::tag_exists( $created_tag_id ) ) {
					$assigned = YoOhw_COS_Tags::assign_tag( $customer_id, $created_tag_id ) || $assigned;
				}
			}
		}

		wp_safe_redirect(
			self::get_customer_profile_redirect_url(
				$customer_id,
				array(
					'tag_added' => $assigned ? 1 : 0,
				),
				'yoohw-cos-add-tag'
			)
		);
		exit;
	}

	private static function get_customer_profile_redirect_url( int $customer_id, array $args, string $fragment = '' ): string {
		$url = add_query_arg(
			array_merge(
				array(
					'page'        => 'yoohw-customer-intelligence',
					'customer_id' => $customer_id,
				),
				$args
			),
			admin_url( 'admin.php' )
		);

		if ( '' !== $fragment ) {
			$url .= '#' . rawurlencode( ltrim( $fragment, '#' ) );
		}

		return $url;
	}

	private static function parse_relationship_names( string $value ): array {
		$names = array();
		$parts = preg_split( '/[,\n]+/', $value );

		foreach ( is_array( $parts ) ? $parts : array() as $part ) {
			$name = sanitize_text_field( trim( $part ) );

			if ( '' === $name ) {
				continue;
			}

			$key           = sanitize_title( $name );
			$names[ $key ?: md5( $name ) ] = $name;
		}

		return array_values( $names );
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
			self::get_customer_profile_redirect_url(
				$customer_id,
				array(
					'tag_removed' => $removed ? 1 : 0,
				),
				'yoohw-cos-add-tag'
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

	public static function handle_sync_blacklist_signals(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'yoohw-customer-intelligence' ) );
		}

		check_admin_referer( 'yoohw_cos_sync_blacklist_signals' );

		if ( ! self::is_blacklist_manager_sync_available() ) {
			wp_die( esc_html__( 'Blacklist Manager integration is not active.', 'yoohw-customer-intelligence' ) );
		}

		$page  = isset( $_POST['blacklist_sync_page'] ) ? absint( wp_unslash( $_POST['blacklist_sync_page'] ) ) : 1;
		$stage = isset( $_POST['blacklist_sync_stage'] ) ? sanitize_key( wp_unslash( $_POST['blacklist_sync_stage'] ) ) : 'core';
		$limit = 300;
		$batch = self::run_blacklist_signal_sync_batch( $limit, $page, $stage );
		$result = $batch['result'];
		$next_stage = $batch['next_stage'];

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                              => 'yoohw-customer-intelligence-settings',
					'yoohw_cos_blacklist_synced'        => absint( $result['processed'] ?? 0 ),
					'yoohw_cos_blacklist_scanned'       => absint( $result['scanned'] ?? 0 ),
					'yoohw_cos_blacklist_skipped'       => absint( $result['skipped'] ?? 0 ),
					'yoohw_cos_blacklist_batch_stage'   => sanitize_key( (string) ( $result['stage'] ?? $stage ) ),
					'yoohw_cos_blacklist_sync_stage'    => sanitize_key( $next_stage ),
					'yoohw_cos_blacklist_sync_next'     => absint( $result['next_page'] ?? $page ),
					'yoohw_cos_blacklist_sync_more'     => ! empty( $result['has_more'] ) ? 1 : 0,
					'yoohw_cos_blacklist_sync_auto'     => ! empty( $_POST['auto_blacklist_sync'] ) ? 1 : 0,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private static function run_blacklist_signal_sync_batch( int $limit, int $page, string $stage ): array {
		$page  = max( 1, absint( $page ) );
		$stage = sanitize_key( $stage );

		$result = array(
			'scanned'   => 0,
			'processed' => 0,
			'skipped'   => 0,
			'has_more'  => false,
			'next_page' => $page,
			'stage'     => $stage,
		);
		$next_stage = $stage;

		if ( ! self::is_blacklist_manager_sync_available() ) {
			$result['stage'] = 'core_unavailable';

			return array(
				'result'     => $result,
				'next_stage' => 'complete',
			);
		}

		if ( 'premium' === $stage ) {
			if ( self::is_premium_risk_sync_available() ) {
				$result = YoOhw_COS_Blacklist_Manager_Premium_Integration::backfill_legacy_signals( $limit, $page );
				$next_stage = ! empty( $result['has_more'] ) ? 'premium' : 'complete';
			} else {
				$result['stage'] = 'premium_unavailable';
				$next_stage = 'complete';
			}

			return array(
				'result'     => $result,
				'next_stage' => $next_stage,
			);
		}

		$stage = 'core';

		if (
			class_exists( 'YoOhw_COS_Blacklist_Manager_Integration' )
			&& is_callable( array( 'YoOhw_COS_Blacklist_Manager_Integration', 'backfill_legacy_signals' ) )
		) {
			$result = YoOhw_COS_Blacklist_Manager_Integration::backfill_legacy_signals( $limit, $page );
		}

		$result['stage'] = 'core';
		$next_stage = ! empty( $result['has_more'] ) ? 'core' : 'complete';

		if ( empty( $result['has_more'] ) && self::is_premium_risk_sync_available() ) {
			$result['has_more'] = true;
			$result['next_page'] = 1;
			$next_stage = 'premium';
		}

		return array(
			'result'     => $result,
			'next_stage' => $next_stage,
		);
	}

	private static function is_blacklist_manager_sync_available(): bool {
		return class_exists( 'YoOhw_COS_Blacklist_Manager_Integration' )
			&& is_callable( array( 'YoOhw_COS_Blacklist_Manager_Integration', 'is_active' ) )
			&& YoOhw_COS_Blacklist_Manager_Integration::is_active();
	}

	private static function is_premium_risk_sync_available(): bool {
		return self::is_blacklist_manager_sync_available()
			&& class_exists( 'YoOhw_COS_Blacklist_Manager_Premium_Integration' )
			&& is_callable( array( 'YoOhw_COS_Blacklist_Manager_Premium_Integration', 'is_active' ) )
			&& YoOhw_COS_Blacklist_Manager_Premium_Integration::is_active()
			&& is_callable( array( 'YoOhw_COS_Blacklist_Manager_Premium_Integration', 'backfill_legacy_signals' ) );
	}

	private static function count_customer_rows(): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i',
				YoOhw_COS_DB::customers_table()
			)
		);
	}

	private static function count_blacklist_sync_rows(): int {
		$total = self::count_core_blacklist_rows();

		if ( self::is_premium_risk_sync_available() ) {
			$total += self::count_premium_blacklist_rows();
		}

		return $total;
	}

	private static function count_core_blacklist_rows(): int {
		global $wpdb;

		if ( ! self::is_blacklist_manager_sync_available() ) {
			return 0;
		}

		$blacklist_table = $wpdb->prefix . 'wc_blacklist';
		$log_table       = $wpdb->prefix . 'wc_blacklist_detection_log';

		return self::count_table_rows( $blacklist_table )
			+ self::count_detection_log_rows_like_source( $log_table, 'woo_order_%' );
	}

	private static function count_premium_blacklist_rows(): int {
		global $wpdb;

		return self::count_premium_risk_orders()
			+ self::count_premium_detection_log_rows()
			+ self::count_table_rows( $wpdb->prefix . 'wc_blacklist_payment_abuse_events' );
	}

	private static function count_premium_risk_orders(): int {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}

		$query = wc_get_orders(
			array(
				'type'       => 'shop_order',
				'limit'      => 1,
				'page'       => 1,
				'paginate'   => true,
				'orderby'    => 'ID',
				'order'      => 'ASC',
				'return'     => 'ids',
				'status'     => function_exists( 'wc_get_order_statuses' ) ? array_keys( wc_get_order_statuses() ) : 'any',
				'meta_query' => array(
					array(
						'key'     => '_risk_score',
						'value'   => 0,
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		if ( is_object( $query ) && isset( $query->total ) ) {
			return absint( $query->total );
		}

		return is_array( $query ) ? count( $query ) : 0;
	}

	private static function count_premium_detection_log_rows(): int {
		global $wpdb;

		$table = $wpdb->prefix . 'wc_blacklist_detection_log';

		if ( ! self::table_exists( $table ) ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM %i
				WHERE (source LIKE %s AND details LIKE %s)
				OR source IN ('woo_checkout', 'woo_api_checkout', 'paypal_payments_create_order')",
				$table,
				'woo_order_%',
				'%risk_score%'
			)
		);
	}

	private static function count_detection_log_rows_like_source( string $table, string $source_like ): int {
		global $wpdb;

		if ( ! self::table_exists( $table ) ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE source LIKE %s',
				$table,
				$source_like
			)
		);
	}

	private static function count_table_rows( string $table ): int {
		global $wpdb;

		if ( ! self::table_exists( $table ) ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i',
				$table
			)
		);
	}

	private static function table_exists( string $table ): bool {
		global $wpdb;

		return $table === $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$table
			)
		);
	}

	public static function handle_assign_customer_segment(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'yoohw-customer-intelligence' ) );
		}

		check_admin_referer( 'yoohw_cos_assign_customer_segment' );

		$customer_id  = isset( $_POST['customer_id'] ) ? absint( wp_unslash( $_POST['customer_id'] ) ) : 0;
		$segment_id   = isset( $_POST['segment_id'] ) ? absint( wp_unslash( $_POST['segment_id'] ) ) : 0;
		$segment_name = isset( $_POST['segment_name'] ) ? sanitize_text_field( wp_unslash( $_POST['segment_name'] ) ) : '';
		$segment_name = '' !== $segment_name || ! isset( $_POST['segment_name_nojs'] )
			? $segment_name
			: sanitize_textarea_field( wp_unslash( $_POST['segment_name_nojs'] ) );
		$assigned     = false;

		if ( $customer_id && YoOhw_COS_Customers::customer_exists( $customer_id ) ) {
			if ( $segment_id && YoOhw_COS_Segments::segment_exists( $segment_id ) ) {
				$assigned = YoOhw_COS_Segments::assign_customer( $customer_id, $segment_id );
			}

			foreach ( self::parse_relationship_names( $segment_name ) as $name ) {
				$created_segment_id = YoOhw_COS_Segments::create_segment( $name );

				if ( $created_segment_id && YoOhw_COS_Segments::segment_exists( $created_segment_id ) ) {
					$assigned = YoOhw_COS_Segments::assign_customer( $customer_id, $created_segment_id ) || $assigned;
				}
			}
		}

		wp_safe_redirect(
			self::get_customer_profile_redirect_url(
				$customer_id,
				array(
					'segment_added' => $assigned ? 1 : 0,
				),
				'yoohw-cos-add-segment'
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
			self::get_customer_profile_redirect_url(
				$customer_id,
				array(
					'segment_removed' => $removed ? 1 : 0,
				),
				'yoohw-cos-add-segment'
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
