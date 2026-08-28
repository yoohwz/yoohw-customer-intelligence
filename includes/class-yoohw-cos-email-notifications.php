<?php
defined( 'ABSPATH' ) || exit;

final class YoOhw_COS_Email_Notifications {

	private const GROUP = 'crm';
	private const CRON_DUE_SOON = 'yoohw_cos_crm_email_due_soon';
	private const CRON_DAILY = 'yoohw_cos_crm_email_daily';
	private const BATCH_SIZE = 200;
	private const DIGEST_TASK_LIMIT = 200;

	private static $email_classes = array(
		'YoOhw_COS_Email_Customer_Message',
		'YoOhw_COS_Email_Task_Assigned',
		'YoOhw_COS_Email_Task_Reassigned',
		'YoOhw_COS_Email_Task_Due_Soon',
		'YoOhw_COS_Email_Task_Overdue',
		'YoOhw_COS_Email_Task_Overdue_Escalation',
		'YoOhw_COS_Email_Task_Completed',
		'YoOhw_COS_Email_Task_Reopened',
		'YoOhw_COS_Email_Daily_Followup_Summary',
	);

	public static function init(): void {
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'register_email_classes' ) );
		add_filter( 'woocommerce_email_groups', array( __CLASS__, 'register_email_group' ) );
		add_filter( 'woocommerce_email_settings', array( __CLASS__, 'replace_email_notifications_field' ), 20 );
		add_action( 'woocommerce_admin_field_yoohw_cos_grouped_email_notification', array( __CLASS__, 'render_grouped_email_notifications' ) );

		add_action( 'yoohw_cos_task_created', array( __CLASS__, 'handle_task_created' ), 10, 2 );
		add_action( 'yoohw_cos_task_reassigned', array( __CLASS__, 'handle_task_reassigned' ), 10, 3 );
		add_action( 'yoohw_cos_task_completed', array( __CLASS__, 'handle_task_completed' ), 10, 3 );
		add_action( 'yoohw_cos_task_reopened', array( __CLASS__, 'handle_task_reopened' ), 10, 3 );

		add_action( self::CRON_DUE_SOON, array( __CLASS__, 'run_due_soon_notifications' ) );
		add_action( self::CRON_DAILY, array( __CLASS__, 'run_daily_notifications' ) );

		self::maybe_schedule_events();
		self::maybe_register_with_initialized_mailer();
	}

	public static function activate(): void {
		self::maybe_schedule_events();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_DUE_SOON );
		wp_clear_scheduled_hook( self::CRON_DAILY );
	}

	public static function register_email_classes( array $emails ): array {
		self::include_email_classes();

		foreach ( self::$email_classes as $class_name ) {
			if ( ! isset( $emails[ $class_name ] ) && class_exists( $class_name ) ) {
				$emails[ $class_name ] = new $class_name();
			}
		}

		return $emails;
	}

	public static function register_email_group( array $groups ): array {
		$groups[ self::GROUP ] = __( 'CRM', 'yoohw-customer-intelligence' );

		return $groups;
	}

	public static function get_customer_message_default_subject( array $customer ): string {
		$email = self::get_crm_email( 'YoOhw_COS_Email_Customer_Message' );

		if ( $email instanceof YoOhw_COS_Email_Customer_Message ) {
			return $email->get_composer_subject( $customer );
		}

		return sprintf(
			/* translators: %s: store name. */
			__( 'A message from %s', 'yoohw-customer-intelligence' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);
	}

	public static function send_customer_message( array $customer, string $subject, string $message ) {
		$email = self::get_crm_email( 'YoOhw_COS_Email_Customer_Message' );

		if ( ! $email instanceof YoOhw_COS_Email_Customer_Message ) {
			return new WP_Error(
				'customer_email_unavailable',
				__( 'The WooCommerce customer email template is unavailable.', 'yoohw-customer-intelligence' )
			);
		}

		if ( ! $email->is_enabled() ) {
			return new WP_Error(
				'customer_email_disabled',
				__( 'Customer messages are disabled in WooCommerce email settings.', 'yoohw-customer-intelligence' )
			);
		}

		if ( ! $email->trigger( $customer, $subject, $message ) ) {
			return new WP_Error(
				'customer_email_send_failed',
				__( 'WooCommerce could not send the email. Please check the store email configuration and try again.', 'yoohw-customer-intelligence' )
			);
		}

		return true;
	}

	public static function replace_email_notifications_field( array $settings ): array {
		$replace_types = array(
			'email_notification',
			'email_notification_block_emails',
			'yowcl_grouped_email_notification',
		);

		foreach ( $settings as $index => $setting ) {
			if ( empty( $setting['type'] ) || ! in_array( $setting['type'], $replace_types, true ) ) {
				continue;
			}

			$settings[ $index ]['type'] = 'yoohw_cos_grouped_email_notification';
		}

		return $settings;
	}

	public static function render_grouped_email_notifications(): void {
		$mailer          = WC()->mailer();
		$email_templates = $mailer ? $mailer->get_emails() : array();
		$email_templates = self::filter_visible_email_templates( $email_templates );
		$current_group   = self::get_current_email_group( $email_templates );
		$columns         = apply_filters(
			'woocommerce_email_setting_columns',
			array(
				'status'     => '',
				'name'       => __( 'Email', 'yoohw-customer-intelligence' ),
				'email_type' => __( 'Content type', 'yoohw-customer-intelligence' ),
				'recipient'  => __( 'Recipient(s)', 'yoohw-customer-intelligence' ),
				'actions'    => '',
			)
		);
		$filtered_templates = self::filter_email_templates_by_group( $email_templates, $current_group );
		?>
		<tr valign="top">
			<td class="wc_emails_wrapper" colspan="2">
				<?php self::render_group_styles(); ?>
				<?php self::render_group_navigation( $email_templates, $current_group ); ?>
				<table class="wc_emails widefat" cellspacing="0">
					<thead>
						<tr>
							<?php foreach ( $columns as $key => $column ) : ?>
								<th class="wc-email-settings-table-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $column ); ?></th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $filtered_templates ) ) : ?>
							<tr>
								<td colspan="<?php echo esc_attr( count( $columns ) ); ?>">
									<?php esc_html_e( 'No email notifications found in this group.', 'yoohw-customer-intelligence' ); ?>
								</td>
							</tr>
						<?php endif; ?>

						<?php foreach ( $filtered_templates as $email_key => $email ) : ?>
							<tr>
								<?php foreach ( $columns as $key => $column ) : ?>
									<?php self::render_email_column( $key, $email_key, $email ); ?>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</td>
		</tr>
		<?php
	}

	public static function handle_task_created( int $task_id, array $task ): void {
		$task = self::get_task_for_email( $task_id );

		if ( empty( $task ) || YoOhw_COS_Tasks::STATUS_COMPLETED === (string) ( $task['status'] ?? '' ) ) {
			return;
		}

		$assigned_user_id = absint( $task['assigned_user_id'] ?? 0 );

		if ( $assigned_user_id <= 0 ) {
			return;
		}

		self::trigger_email( 'YoOhw_COS_Email_Task_Assigned', $task, $assigned_user_id );
	}

	public static function handle_task_reassigned( int $task_id, array $old_task, array $new_task ): void {
		$task = self::get_task_for_email( $task_id );

		if ( empty( $task ) || YoOhw_COS_Tasks::STATUS_COMPLETED === (string) ( $task['status'] ?? '' ) ) {
			return;
		}

		$new_assignee_id = absint( $new_task['assigned_user_id'] ?? 0 );
		$old_assignee_id = absint( $old_task['assigned_user_id'] ?? 0 );

		if ( $new_assignee_id > 0 ) {
			self::trigger_email(
				'YoOhw_COS_Email_Task_Reassigned',
				$task,
				$new_assignee_id,
				array(
					'old_task'        => $old_task,
					'old_assignee_id' => $old_assignee_id,
				)
			);
		}

		$email = self::get_crm_email( 'YoOhw_COS_Email_Task_Reassigned' );

		if (
			$email instanceof YoOhw_COS_Email_Task_Reassigned
			&& $email->should_notify_previous_assignee()
			&& $old_assignee_id > 0
			&& $old_assignee_id !== $new_assignee_id
		) {
			self::trigger_email(
				'YoOhw_COS_Email_Task_Reassigned',
				$task,
				$old_assignee_id,
				array(
					'old_task'        => $old_task,
					'old_assignee_id' => $old_assignee_id,
					'previous_notice' => true,
				)
			);
		}
	}

	public static function handle_task_completed( int $task_id, array $old_task, array $new_task ): void {
		$task      = self::get_task_for_email( $task_id );
		$actor_id  = absint( $new_task['completed_by'] ?? get_current_user_id() );
		$user_ids  = array();
		$creator   = absint( $task['created_by'] ?? $old_task['created_by'] ?? 0 );
		$assignee  = absint( $task['assigned_user_id'] ?? $old_task['assigned_user_id'] ?? 0 );
		$user_ids  = self::add_recipient_user_id( $user_ids, $creator, $actor_id );
		$user_ids  = self::add_recipient_user_id( $user_ids, $assignee, $actor_id );

		foreach ( $user_ids as $user_id ) {
			self::trigger_email(
				'YoOhw_COS_Email_Task_Completed',
				$task,
				$user_id,
				array(
					'old_task' => $old_task,
					'actor_id' => $actor_id,
				)
			);
		}
	}

	public static function handle_task_reopened( int $task_id, array $old_task, array $new_task ): void {
		$task     = self::get_task_for_email( $task_id );
		$actor_id = get_current_user_id();
		$user_ids = array();
		$user_ids = self::add_recipient_user_id( $user_ids, absint( $task['assigned_user_id'] ?? $new_task['assigned_user_id'] ?? 0 ), $actor_id );
		$user_ids = self::add_recipient_user_id( $user_ids, absint( $task['created_by'] ?? $new_task['created_by'] ?? 0 ), $actor_id );

		foreach ( $user_ids as $user_id ) {
			self::trigger_email(
				'YoOhw_COS_Email_Task_Reopened',
				$task,
				$user_id,
				array(
					'old_task' => $old_task,
					'actor_id' => $actor_id,
				)
			);
		}
	}

	public static function run_due_soon_notifications( int $after_task_id = 0 ): void {
		$email = self::get_crm_email( 'YoOhw_COS_Email_Task_Due_Soon' );

		if ( ! $email || ! $email->is_enabled() ) {
			return;
		}

		$lead_hours = $email instanceof YoOhw_COS_Email_Task_Due_Soon ? $email->get_lead_time_hours() : 24;
		$tasks      = self::get_open_tasks_due_soon( $lead_hours, $after_task_id );
		$last_task_id = absint( $after_task_id );

		foreach ( $tasks as $task ) {
			$assignee_id = absint( $task['assigned_user_id'] ?? 0 );
			$last_task_id = max( $last_task_id, absint( $task['id'] ?? 0 ) );

			if ( $assignee_id <= 0 ) {
				continue;
			}

			$notification_key = self::claim_notification( 'task_due_soon', $task, $assignee_id );

			if ( '' === $notification_key ) {
				continue;
			}

			if ( self::trigger_email( 'YoOhw_COS_Email_Task_Due_Soon', $task, $assignee_id ) ) {
				YoOhw_COS_Notification_Ledger::mark_sent( $notification_key );
			} else {
				YoOhw_COS_Notification_Ledger::release( $notification_key );
			}
		}

		YoOhw_COS_Notification_Ledger::cleanup();

		if ( count( $tasks ) >= self::BATCH_SIZE && $last_task_id > $after_task_id ) {
			$args = array( $last_task_id );

			if ( ! wp_next_scheduled( self::CRON_DUE_SOON, $args ) ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_DUE_SOON, $args );
			}
		}
	}

	public static function run_daily_notifications( array $cursors = array() ): void {
		$next = array(
			'overdue'    => self::run_overdue_digest_notifications( self::normalize_digest_cursor( $cursors['overdue'] ?? array() ) ),
			'escalation' => self::run_overdue_escalation_notifications( self::normalize_digest_cursor( $cursors['escalation'] ?? array() ) ),
			'summary'    => self::run_daily_summary_notifications( self::normalize_digest_cursor( $cursors['summary'] ?? array() ) ),
		);

		YoOhw_COS_Notification_Ledger::cleanup();

		$has_more = false;
		$next_cursors = array();

		foreach ( $next as $channel => $result ) {
			$has_more = $has_more || ! empty( $result['has_more'] );
			$next_cursors[ $channel ] = self::normalize_digest_cursor( $result['cursor'] ?? array() );
		}

		if ( $has_more ) {
			$args = array( $next_cursors );

			if ( ! wp_next_scheduled( self::CRON_DAILY, $args ) ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_DAILY, $args );
			}
		}
	}

	private static function run_overdue_digest_notifications( array $cursor = array() ): array {
		$email = self::get_crm_email( 'YoOhw_COS_Email_Task_Overdue' );

		if ( ! $email || ! $email->is_enabled() ) {
			return array( 'cursor' => $cursor, 'has_more' => false );
		}

		$batch = self::get_open_overdue_task_groups( 0, $cursor );

		foreach ( $batch['groups'] as $user_id => $tasks ) {
			$marker = self::get_digest_chunk_marker( 'overdue_digest', absint( $user_id ), $tasks );
			$notification_key = self::claim_notification( $marker, array(), absint( $user_id ) );

			if ( '' === $notification_key ) {
				continue;
			}

			if ( self::trigger_digest_email( 'YoOhw_COS_Email_Task_Overdue', $tasks, absint( $user_id ) ) ) {
				YoOhw_COS_Notification_Ledger::mark_sent( $notification_key );
			} else {
				YoOhw_COS_Notification_Ledger::release( $notification_key );
			}
		}

		return array( 'cursor' => $batch['cursor'], 'has_more' => $batch['has_more'] );
	}

	private static function run_overdue_escalation_notifications( array $cursor = array() ): array {
		$email = self::get_crm_email( 'YoOhw_COS_Email_Task_Overdue_Escalation' );

		if ( ! $email || ! $email->is_enabled() ) {
			return array( 'cursor' => $cursor, 'has_more' => false );
		}

		$days_overdue = $email instanceof YoOhw_COS_Email_Task_Overdue_Escalation ? $email->get_days_overdue() : 3;

		$batch = self::get_open_overdue_task_groups( $days_overdue, $cursor );

		foreach ( $batch['groups'] as $user_id => $tasks ) {
			$marker = self::get_digest_chunk_marker( 'overdue_escalation_' . absint( $days_overdue ), absint( $user_id ), $tasks );
			$notification_key = self::claim_notification( $marker, array(), absint( $user_id ) );

			if ( '' === $notification_key ) {
				continue;
			}

			if ( self::trigger_digest_email( 'YoOhw_COS_Email_Task_Overdue_Escalation', $tasks, absint( $user_id ) ) ) {
				YoOhw_COS_Notification_Ledger::mark_sent( $notification_key );
			} else {
				YoOhw_COS_Notification_Ledger::release( $notification_key );
			}
		}

		return array( 'cursor' => $batch['cursor'], 'has_more' => $batch['has_more'] );
	}

	private static function run_daily_summary_notifications( array $cursor = array() ): array {
		$email = self::get_crm_email( 'YoOhw_COS_Email_Daily_Followup_Summary' );

		if ( ! $email || ! $email->is_enabled() ) {
			return array( 'cursor' => $cursor, 'has_more' => false );
		}

		$batch = self::get_daily_summary_task_groups( $cursor );

		foreach ( $batch['groups'] as $user_id => $tasks ) {
			$marker = self::get_digest_chunk_marker( 'daily_summary', absint( $user_id ), $tasks );
			$notification_key = self::claim_notification( $marker, array(), absint( $user_id ) );

			if ( '' === $notification_key ) {
				continue;
			}

			if ( self::trigger_digest_email( 'YoOhw_COS_Email_Daily_Followup_Summary', $tasks, absint( $user_id ) ) ) {
				YoOhw_COS_Notification_Ledger::mark_sent( $notification_key );
			} else {
				YoOhw_COS_Notification_Ledger::release( $notification_key );
			}
		}

		return array( 'cursor' => $batch['cursor'], 'has_more' => $batch['has_more'] );
	}

	public static function get_task_for_email( int $task_id ): array {
		global $wpdb;

		$task_id = absint( $task_id );

		if ( $task_id <= 0 ) {
			return array();
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					t.*,
					c.display_name AS customer_name,
					c.email AS customer_email,
					c.phone AS customer_phone,
					assignee.display_name AS assignee_name,
					assignee.user_email AS assignee_email,
					creator.display_name AS creator_name,
					creator.user_email AS creator_email,
					completer.display_name AS completed_by_name,
					completer.user_email AS completed_by_email
				FROM %i t
				LEFT JOIN %i c ON c.id = t.customer_id
				LEFT JOIN %i assignee ON assignee.ID = t.assigned_user_id
				LEFT JOIN %i creator ON creator.ID = t.created_by
				LEFT JOIN %i completer ON completer.ID = t.completed_by
				WHERE t.id = %d
				LIMIT 1",
				YoOhw_COS_DB::tasks_table(),
				YoOhw_COS_DB::customers_table(),
				$wpdb->users,
				$wpdb->users,
				$wpdb->users,
				$task_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : array();
	}

	private static function get_open_tasks_due_soon( int $lead_hours, int $after_task_id = 0 ): array {
		$now       = current_time( 'timestamp' );
		$end       = $now + ( max( 1, $lead_hours ) * HOUR_IN_SECONDS );
		$start_sql = wp_date( 'Y-m-d H:i:s', $now );
		$end_sql   = wp_date( 'Y-m-d H:i:s', $end );

		return self::get_open_tasks_by_date_window( $start_sql, $end_sql, $after_task_id );
	}

	private static function get_open_overdue_task_groups( int $minimum_days_overdue = 0, array $cursor = array() ): array {
		global $wpdb;

		$cursor        = self::normalize_digest_cursor( $cursor );
		$now_timestamp = current_time( 'timestamp' );
		$cutoff        = $minimum_days_overdue > 0
			? wp_date( 'Y-m-d H:i:s', $now_timestamp - ( $minimum_days_overdue * DAY_IN_SECONDS ) )
			: wp_date( 'Y-m-d H:i:s', $now_timestamp );
		$user_id       = absint( $cursor['user_id'] );
		$after_task_id = absint( $cursor['task_id'] );

		if ( $after_task_id <= 0 ) {
			$user_id = self::get_next_overdue_assignee( $user_id, $cutoff );
		}

		if ( $user_id <= 0 ) {
			return array( 'groups' => array(), 'cursor' => $cursor, 'has_more' => false );
		}

		$tasks = self::get_open_overdue_tasks_for_user( $user_id, $cutoff, $after_task_id );

		if ( empty( $tasks ) && $after_task_id > 0 ) {
			return self::get_open_overdue_task_groups( $minimum_days_overdue, array( 'user_id' => $user_id, 'task_id' => 0 ) );
		}

		$last_task_id = ! empty( $tasks ) ? absint( end( $tasks )['id'] ?? 0 ) : 0;
		$chunk_full   = count( $tasks ) >= self::DIGEST_TASK_LIMIT;
		$next_user    = $chunk_full ? 0 : self::get_next_overdue_assignee( $user_id, $cutoff );

		return array(
			'groups'   => empty( $tasks ) ? array() : array( $user_id => $tasks ),
			'cursor'   => array( 'user_id' => $user_id, 'task_id' => $chunk_full ? $last_task_id : 0 ),
			'has_more' => $chunk_full || $next_user > 0,
		);
	}

	private static function get_next_overdue_assignee( int $after_user_id, string $cutoff ): int {
		global $wpdb;

		return absint(
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT assigned_user_id FROM %i
					WHERE status <> %s AND assigned_user_id > %d AND due_date IS NOT NULL AND due_date < %s
					ORDER BY assigned_user_id ASC LIMIT 1",
					YoOhw_COS_DB::tasks_table(),
					YoOhw_COS_Tasks::STATUS_COMPLETED,
					absint( $after_user_id ),
					$cutoff
				)
			)
		);
	}

	private static function get_open_overdue_tasks_for_user( int $user_id, string $cutoff, int $after_task_id = 0 ): array {
		global $wpdb;

		$tasks = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					t.*,
					c.display_name AS customer_name,
					c.email AS customer_email,
					c.phone AS customer_phone,
					assignee.display_name AS assignee_name,
					assignee.user_email AS assignee_email,
					creator.display_name AS creator_name,
					creator.user_email AS creator_email
				FROM %i t
				LEFT JOIN %i c ON c.id = t.customer_id
				LEFT JOIN %i assignee ON assignee.ID = t.assigned_user_id
				LEFT JOIN %i creator ON creator.ID = t.created_by
				WHERE t.status <> %s
					AND t.assigned_user_id = %d
					AND t.id > %d
					AND t.due_date IS NOT NULL
					AND t.due_date < %s
				ORDER BY t.id ASC
				LIMIT %d",
				YoOhw_COS_DB::tasks_table(),
				YoOhw_COS_DB::customers_table(),
				$wpdb->users,
				$wpdb->users,
				YoOhw_COS_Tasks::STATUS_COMPLETED,
				absint( $user_id ),
				absint( $after_task_id ),
				$cutoff,
				self::DIGEST_TASK_LIMIT
			),
			ARRAY_A
		);

		return is_array( $tasks ) ? $tasks : array();
	}

	private static function get_daily_summary_task_groups( array $cursor = array() ): array {
		global $wpdb;

		$cursor       = self::normalize_digest_cursor( $cursor );
		$now          = current_time( 'timestamp' );
		$today_start  = wp_date( 'Y-m-d 00:00:00', $now );
		$today_end    = wp_date( 'Y-m-d 23:59:59', $now );
		$current_time = wp_date( 'Y-m-d H:i:s', $now );
		$user_id       = absint( $cursor['user_id'] );
		$after_task_id = absint( $cursor['task_id'] );

		if ( $after_task_id <= 0 ) {
			$user_id = self::get_next_summary_assignee( $user_id, $current_time, $today_start, $today_end );
		}

		if ( $user_id <= 0 ) {
			return array( 'groups' => array(), 'cursor' => $cursor, 'has_more' => false );
		}

		$tasks = self::get_daily_summary_tasks_for_user( $user_id, $current_time, $today_start, $today_end, $after_task_id );

		if ( empty( $tasks ) && $after_task_id > 0 ) {
			return self::get_daily_summary_task_groups( array( 'user_id' => $user_id, 'task_id' => 0 ) );
		}

		$last_task_id = ! empty( $tasks ) ? absint( end( $tasks )['id'] ?? 0 ) : 0;
		$chunk_full   = count( $tasks ) >= self::DIGEST_TASK_LIMIT;
		$next_user    = $chunk_full ? 0 : self::get_next_summary_assignee( $user_id, $current_time, $today_start, $today_end );

		return array(
			'groups'   => empty( $tasks ) ? array() : array( $user_id => $tasks ),
			'cursor'   => array( 'user_id' => $user_id, 'task_id' => $chunk_full ? $last_task_id : 0 ),
			'has_more' => $chunk_full || $next_user > 0,
		);
	}

	private static function get_next_summary_assignee( int $after_user_id, string $current_time, string $today_start, string $today_end ): int {
		global $wpdb;

		return absint(
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT assigned_user_id FROM %i
					WHERE status <> %s AND assigned_user_id > %d
						AND ((due_date IS NOT NULL AND due_date < %s)
						OR (due_date IS NOT NULL AND due_date BETWEEN %s AND %s)
						OR priority IN ('high', 'urgent'))
					ORDER BY assigned_user_id ASC LIMIT 1",
					YoOhw_COS_DB::tasks_table(),
					YoOhw_COS_Tasks::STATUS_COMPLETED,
					absint( $after_user_id ),
					$current_time,
					$today_start,
					$today_end
				)
			)
		);
	}

	private static function get_daily_summary_tasks_for_user( int $user_id, string $current_time, string $today_start, string $today_end, int $after_task_id = 0 ): array {
		global $wpdb;

		$tasks = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					t.*,
					c.display_name AS customer_name,
					c.email AS customer_email,
					c.phone AS customer_phone,
					assignee.display_name AS assignee_name,
					assignee.user_email AS assignee_email,
					creator.display_name AS creator_name,
					creator.user_email AS creator_email
				FROM %i t
				LEFT JOIN %i c ON c.id = t.customer_id
				LEFT JOIN %i assignee ON assignee.ID = t.assigned_user_id
				LEFT JOIN %i creator ON creator.ID = t.created_by
				WHERE t.status <> %s
					AND t.assigned_user_id = %d
					AND t.id > %d
					AND (
						(t.due_date IS NOT NULL AND t.due_date < %s)
						OR (t.due_date IS NOT NULL AND t.due_date BETWEEN %s AND %s)
						OR t.priority IN ('high', 'urgent')
					)
				ORDER BY t.id ASC
				LIMIT %d",
				YoOhw_COS_DB::tasks_table(),
				YoOhw_COS_DB::customers_table(),
				$wpdb->users,
				$wpdb->users,
				YoOhw_COS_Tasks::STATUS_COMPLETED,
				absint( $user_id ),
				absint( $after_task_id ),
				$current_time,
				$today_start,
				$today_end,
				self::DIGEST_TASK_LIMIT
			),
			ARRAY_A
		);

		return is_array( $tasks ) ? $tasks : array();
	}

	private static function get_open_tasks_by_date_window( string $start, string $end, int $after_task_id = 0 ): array {
		global $wpdb;

		$tasks = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					t.*,
					c.display_name AS customer_name,
					c.email AS customer_email,
					c.phone AS customer_phone,
					assignee.display_name AS assignee_name,
					assignee.user_email AS assignee_email,
					creator.display_name AS creator_name,
					creator.user_email AS creator_email
				FROM %i t
				LEFT JOIN %i c ON c.id = t.customer_id
				LEFT JOIN %i assignee ON assignee.ID = t.assigned_user_id
				LEFT JOIN %i creator ON creator.ID = t.created_by
				WHERE t.status <> %s
					AND t.assigned_user_id IS NOT NULL
					AND t.assigned_user_id > 0
					AND t.due_date IS NOT NULL
					AND t.due_date BETWEEN %s AND %s
					AND t.id > %d
				ORDER BY t.id ASC
				LIMIT %d",
				YoOhw_COS_DB::tasks_table(),
				YoOhw_COS_DB::customers_table(),
				$wpdb->users,
				$wpdb->users,
				YoOhw_COS_Tasks::STATUS_COMPLETED,
				$start,
				$end,
				absint( $after_task_id ),
				self::BATCH_SIZE
			),
			ARRAY_A
		);

		return is_array( $tasks ) ? $tasks : array();
	}

	private static function trigger_email( string $class_name, array $task, int $recipient_user_id, array $context = array() ): bool {
		$email = self::get_crm_email( $class_name );

		if ( ! $email || ! method_exists( $email, 'trigger' ) ) {
			return false;
		}

		return (bool) $email->trigger( $task, $recipient_user_id, $context );
	}

	private static function trigger_digest_email( string $class_name, array $tasks, int $recipient_user_id, array $context = array() ): bool {
		$email = self::get_crm_email( $class_name );

		if ( ! $email || ! method_exists( $email, 'trigger' ) ) {
			return false;
		}

		return (bool) $email->trigger( $tasks, $recipient_user_id, $context );
	}

	private static function get_crm_email( string $class_name ) {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}

		$mailer = WC()->mailer();

		if ( ! $mailer ) {
			return null;
		}

		$emails = $mailer->get_emails();

		return isset( $emails[ $class_name ] ) ? $emails[ $class_name ] : null;
	}

	private static function include_email_classes(): void {
		if ( ! class_exists( 'WC_Email' ) ) {
			return;
		}

		require_once YOOHW_COS_PATH . 'includes/emails/class-yoohw-cos-email-crm-base.php';
		require_once YOOHW_COS_PATH . 'includes/emails/class-yoohw-cos-email-customer-message.php';
		require_once YOOHW_COS_PATH . 'includes/emails/class-yoohw-cos-email-task-events.php';
		require_once YOOHW_COS_PATH . 'includes/emails/class-yoohw-cos-email-task-digests.php';
	}

	private static function maybe_register_with_initialized_mailer(): void {
		if ( ! did_action( 'woocommerce_email' ) || ! function_exists( 'WC' ) ) {
			return;
		}

		$mailer = WC()->mailer();

		if ( ! $mailer || ! isset( $mailer->emails ) || ! is_array( $mailer->emails ) ) {
			return;
		}

		$mailer->emails = self::register_email_classes( $mailer->emails );
	}

	private static function maybe_schedule_events(): void {
		if ( ! wp_next_scheduled( self::CRON_DUE_SOON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_DUE_SOON );
		}

		if ( ! wp_next_scheduled( self::CRON_DAILY ) ) {
			wp_schedule_event( self::get_next_daily_run_timestamp(), 'daily', self::CRON_DAILY );
		}
	}

	private static function get_next_daily_run_timestamp(): int {
		$timezone  = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$now        = new DateTimeImmutable( 'now', $timezone );
		$today_run  = $now->setTime( 8, 0, 0 );
		$target_run = $today_run > $now ? $today_run : $today_run->modify( '+1 day' );

		return $target_run->getTimestamp();
	}

	private static function add_recipient_user_id( array $user_ids, int $user_id, int $excluded_user_id = 0 ): array {
		$user_id = absint( $user_id );

		if ( $user_id <= 0 || ( $excluded_user_id > 0 && $user_id === absint( $excluded_user_id ) ) ) {
			return $user_ids;
		}

		if ( ! in_array( $user_id, $user_ids, true ) ) {
			$user_ids[] = $user_id;
		}

		return $user_ids;
	}

	private static function claim_notification( string $event, array $task = array(), int $recipient_user_id = 0 ): string {
		$legacy_key = self::get_notification_marker_key( $event, $task );
		$key        = YoOhw_COS_Notification_Ledger::key( $event, $task, $recipient_user_id );

		if ( get_option( $legacy_key, false ) ) {
			if ( YoOhw_COS_Notification_Ledger::claim( $key, $event, absint( $task['id'] ?? 0 ), $recipient_user_id ) ) {
				YoOhw_COS_Notification_Ledger::mark_sent( $key );
			}
			delete_option( $legacy_key );

			return '';
		}

		return YoOhw_COS_Notification_Ledger::claim( $key, $event, absint( $task['id'] ?? 0 ), $recipient_user_id ) ? $key : '';
	}

	private static function normalize_digest_cursor( $cursor ): array {
		if ( ! is_array( $cursor ) ) {
			return array( 'user_id' => absint( $cursor ), 'task_id' => 0 );
		}

		return array(
			'user_id' => absint( $cursor['user_id'] ?? 0 ),
			'task_id' => absint( $cursor['task_id'] ?? 0 ),
		);
	}

	private static function get_digest_chunk_marker( string $type, int $user_id, array $tasks ): string {
		$task_ids = array_values( array_filter( array_map( static fn( array $task ): int => absint( $task['id'] ?? 0 ), $tasks ) ) );

		return sanitize_key( $type )
			. '_' . wp_date( 'Ymd', current_time( 'timestamp' ) )
			. '_' . absint( $user_id )
			. '_' . ( $task_ids[0] ?? 0 )
			. '_' . ( ! empty( $task_ids ) ? end( $task_ids ) : 0 )
			. '_' . substr( hash( 'sha256', implode( ',', $task_ids ) ), 0, 16 );
	}

	private static function get_notification_marker_key( string $event, array $task = array() ): string {
		$task_id = absint( $task['id'] ?? 0 );
		$basis   = $event . '|' . $task_id . '|' . (string) ( $task['due_date'] ?? '' ) . '|' . (string) ( $task['updated_at'] ?? '' );

		return 'yoohw_cos_email_' . md5( $basis );
	}

	private static function get_current_email_group( array $email_templates ): string {
		$group  = isset( $_GET['email_group'] ) ? sanitize_key( wp_unslash( $_GET['email_group'] ) ) : 'general';
		$groups = self::get_email_group_labels( $email_templates );

		return isset( $groups[ $group ] ) ? $group : 'general';
	}

	private static function get_email_group( $email ): string {
		$group = isset( $email->email_group ) ? sanitize_key( (string) $email->email_group ) : '';

		if ( self::GROUP === $group || 'loyalty' === $group ) {
			return $group;
		}

		return 'general';
	}

	private static function filter_visible_email_templates( array $email_templates ): array {
		if ( self::is_loyalty_integration_active() ) {
			return $email_templates;
		}

		return array_filter(
			$email_templates,
			static function( $email ): bool {
				return 'loyalty' !== self::get_email_group( $email );
			}
		);
	}

	private static function get_email_group_labels( array $email_templates ): array {
		$labels = array(
			'general' => __( 'General', 'yoohw-customer-intelligence' ),
		);

		foreach ( $email_templates as $email ) {
			$group = self::get_email_group( $email );

			if ( isset( $labels[ $group ] ) ) {
				continue;
			}

			if ( self::GROUP === $group ) {
				$labels[ $group ] = __( 'CRM', 'yoohw-customer-intelligence' );
			} elseif ( 'loyalty' === $group ) {
				$labels[ $group ] = __( 'Loyalty', 'yoohw-customer-intelligence' );
			}
		}

		$labels[ self::GROUP ] = __( 'CRM', 'yoohw-customer-intelligence' );

		return $labels;
	}

	private static function filter_email_templates_by_group( array $email_templates, string $group ): array {
		return array_filter(
			$email_templates,
			static function( $email ) use ( $group ): bool {
				return self::get_email_group( $email ) === $group;
			}
		);
	}

	private static function render_group_navigation( array $email_templates, string $current_group ): void {
		$groups = self::get_email_group_labels( $email_templates );
		$counts = array_fill_keys( array_keys( $groups ), 0 );

		foreach ( $email_templates as $email ) {
			$group = self::get_email_group( $email );

			if ( ! isset( $counts[ $group ] ) ) {
				$counts[ $group ] = 0;
			}

			++$counts[ $group ];
		}

		echo '<div class="yoohw-cos-email-group-tabs"><ul class="subsubsub yoohw-cos-email-groups">';

		$group_index = 0;
		$group_count = count( $groups );

		foreach ( $groups as $group => $label ) {
			++$group_index;
			$url       = admin_url( 'admin.php?page=wc-settings&tab=email&email_group=' . $group );
			$class     = $current_group === $group ? 'current' : '';
			$separator = $group_index < $group_count ? ' | ' : '';

			printf(
				'<li><a href="%1$s" class="%2$s">%3$s <span class="count">(%4$d)</span></a>%5$s</li>',
				esc_url( $url ),
				esc_attr( $class ),
				esc_html( $label ),
				absint( $counts[ $group ] ?? 0 ),
				esc_html( $separator )
			);
		}

		echo '</ul></div>';
	}

	private static function is_loyalty_integration_active(): bool {
		return YoOhw_COS_Integrations::loyalty_active();
	}

	private static function render_group_styles(): void {
		static $rendered = false;

		if ( $rendered ) {
			return;
		}

		$rendered = true;
		?>
		<style>
			.yoohw-cos-email-group-tabs {
				margin: 16px 0 12px;
			}

			.yoohw-cos-email-group-tabs .subsubsub {
				float: none;
				margin: 0;
				padding: 0 0 2px;
			}

			.yoohw-cos-email-group-tabs .subsubsub li {
				margin: 0 8px 0 0;
				padding: 0;
			}

			.yoohw-cos-email-group-tabs .subsubsub a {
				font-size: 14px;
				font-weight: 600;
				line-height: 1.8;
				text-decoration: none;
			}

			.yoohw-cos-email-group-tabs .subsubsub .count {
				font-weight: 400;
			}
		</style>
		<?php
	}

	private static function render_email_column( string $key, string $email_key, $email ): void {
		if ( ! in_array( $key, array( 'name', 'recipient', 'status', 'email_type', 'actions' ), true ) ) {
			do_action( 'woocommerce_email_setting_column_' . $key, $email );
			return;
		}

		echo '<td class="wc-email-settings-table-' . esc_attr( $key ) . '">';

		switch ( $key ) {
			case 'name':
				echo '<a href="' . esc_url( self::get_email_manage_url( $email_key, $email ) ) . '">' . esc_html( $email->get_title() ) . '</a> ';
				echo wc_help_tip( $email->get_description() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				break;
			case 'recipient':
				if ( method_exists( $email, 'get_recipient_label' ) ) {
					echo esc_html( $email->get_recipient_label() );
				} else {
					echo esc_html( $email->is_customer_email() ? __( 'Customer', 'yoohw-customer-intelligence' ) : $email->get_recipient() );
				}
				break;
			case 'status':
				if ( $email->is_manual() ) {
					echo '<span class="status-manual tips" data-tip="' . esc_attr__( 'Manually sent', 'yoohw-customer-intelligence' ) . '">' . esc_html__( 'Manual', 'yoohw-customer-intelligence' ) . '</span>';
				} elseif ( $email->is_enabled() ) {
					echo '<span class="status-enabled tips" data-tip="' . esc_attr__( 'Enabled', 'yoohw-customer-intelligence' ) . '">' . esc_html__( 'Yes', 'yoohw-customer-intelligence' ) . '</span>';
				} else {
					echo '<span class="status-disabled tips" data-tip="' . esc_attr__( 'Disabled', 'yoohw-customer-intelligence' ) . '">-</span>';
				}
				break;
			case 'email_type':
				echo esc_html( $email->get_content_type() );
				break;
			case 'actions':
				echo '<a class="button alignright" href="' . esc_url( self::get_email_manage_url( $email_key, $email ) ) . '">' . esc_html__( 'Manage', 'yoohw-customer-intelligence' ) . '</a>';
				break;
		}

		echo '</td>';
	}

	private static function get_email_manage_url( string $email_key, $email ): string {
		return add_query_arg(
			array(
				'page'        => 'wc-settings',
				'tab'         => 'email',
				'section'     => strtolower( $email_key ),
				'email_group' => self::get_email_group( $email ),
			),
			admin_url( 'admin.php' )
		);
	}
}
