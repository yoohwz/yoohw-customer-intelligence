<?php
defined( 'ABSPATH' ) || exit;

abstract class YoOhw_COS_Email_Task_Digest extends YoOhw_COS_Email_CRM_Base {

	protected $intro = '';

	public function __construct() {
		$this->template_html  = 'emails/crm-task-digest.php';
		$this->template_plain = 'emails/plain/crm-task-digest.php';

		parent::__construct();
	}

	public function trigger( array $tasks, int $recipient_user_id, array $context = array() ): bool {
		$this->tasks   = array_values( $tasks );
		$this->context = $context;
		$this->object  = (object) array(
			'tasks'             => $this->tasks,
			'recipient_user_id' => $recipient_user_id,
		);

		if ( empty( $this->tasks ) || ! $this->prepare_recipient_user( $recipient_user_id ) ) {
			return false;
		}

		$this->set_task_count_placeholder( count( $this->tasks ) );

		return $this->send_notification();
	}

	public function get_content_html() {
		return wc_get_template_html(
			$this->template_html,
			$this->get_digest_template_args( false, $this->get_sections(), $this->get_intro() ),
			'',
			$this->template_base
		);
	}

	public function get_content_plain() {
		return wc_get_template_html(
			$this->template_plain,
			$this->get_digest_template_args( true, $this->get_sections(), $this->get_intro() ),
			'',
			$this->template_base
		);
	}

	protected function get_intro(): string {
		return $this->intro;
	}

	protected function get_sections(): array {
		return array(
			array(
				'title' => __( 'Tasks', 'yoohw-customer-intelligence' ),
				'tasks' => $this->tasks,
			),
		);
	}

	protected function is_overdue( array $task ): bool {
		$due_date = $this->get_task_due_datetime( $task );

		return $due_date instanceof DateTimeImmutable && $due_date < current_datetime();
	}

	protected function is_due_today( array $task ): bool {
		$due_date = $this->get_task_due_datetime( $task );

		return $due_date instanceof DateTimeImmutable && $due_date->format( 'Y-m-d' ) === current_datetime()->format( 'Y-m-d' );
	}

	private function get_task_due_datetime( array $task ): ?DateTimeImmutable {
		$due_date = sanitize_text_field( (string) ( $task['due_date'] ?? '' ) );

		if ( '' === $due_date ) {
			return null;
		}

		$datetime = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $due_date, wp_timezone() );

		return $datetime instanceof DateTimeImmutable ? $datetime : null;
	}

	protected function is_high_priority( array $task ): bool {
		return in_array( YoOhw_COS_Tasks::normalize_priority( (string) ( $task['priority'] ?? '' ) ), array( 'high', 'urgent' ), true );
	}
}

class YoOhw_COS_Email_Task_Overdue extends YoOhw_COS_Email_Task_Digest {

	public function __construct() {
		$this->id              = 'yoohw_cos_task_overdue';
		$this->title           = __( 'Overdue task digest', 'yoohw-customer-intelligence' );
		$this->description     = __( 'Sent once per day to each assignee with open CRM tasks past their due date.', 'yoohw-customer-intelligence' );
		$this->recipient_label = __( 'Task assignee daily digest', 'yoohw-customer-intelligence' );
		$this->intro           = __( 'These CRM follow-up tasks are overdue and still open.', 'yoohw-customer-intelligence' );

		parent::__construct();
	}

	public function get_default_subject() {
		return __( '[{site_title}] {task_count} overdue CRM task(s)', 'yoohw-customer-intelligence' );
	}

	public function get_default_heading() {
		return __( 'Overdue CRM tasks', 'yoohw-customer-intelligence' );
	}
}

class YoOhw_COS_Email_Task_Overdue_Escalation extends YoOhw_COS_Email_Task_Digest {

	public function __construct() {
		$this->id              = 'yoohw_cos_task_overdue_escalation';
		$this->title           = __( 'Overdue task escalation', 'yoohw-customer-intelligence' );
		$this->description     = __( 'Optionally sent when assigned CRM tasks remain overdue past the escalation threshold.', 'yoohw-customer-intelligence' );
		$this->recipient_label = __( 'Task assignee plus configured recipients', 'yoohw-customer-intelligence' );
		$this->default_enabled = 'no';
		$this->intro           = __( 'These CRM follow-up tasks have passed the overdue escalation threshold.', 'yoohw-customer-intelligence' );

		parent::__construct();
	}

	public function init_form_fields() {
		parent::init_form_fields();

		$this->form_fields['days_overdue'] = array(
			'title'       => __( 'Escalate after', 'yoohw-customer-intelligence' ),
			'type'        => 'number',
			'description' => __( 'Send escalation when a task has been overdue for at least this many days.', 'yoohw-customer-intelligence' ),
			'default'     => '3',
			'css'         => 'width:80px;',
			'desc_tip'    => true,
		);

		$this->form_fields['recipients'] = array(
			'title'       => __( 'Escalation recipient(s)', 'yoohw-customer-intelligence' ),
			'type'        => 'text',
			/* translators: %s: WP admin email. */
			'description' => sprintf( __( 'Comma-separated manager/admin recipients. Defaults to %s.', 'yoohw-customer-intelligence' ), '<code>' . esc_html( get_option( 'admin_email' ) ) . '</code>' ),
			'placeholder' => get_option( 'admin_email' ),
			'default'     => '',
			'desc_tip'    => true,
		);
	}

	public function trigger( array $tasks, int $recipient_user_id, array $context = array() ): bool {
		$sent = parent::trigger( $tasks, $recipient_user_id, $context );

		return $sent;
	}

	protected function prepare_recipient_user( int $user_id ): bool {
		if ( ! parent::prepare_recipient_user( $user_id ) ) {
			return false;
		}

		$recipients = array_filter(
			array_map(
				'trim',
				explode( ',', $this->recipient . ',' . $this->get_configured_recipients() )
			),
			'is_email'
		);

		$this->recipient = implode( ', ', array_unique( $recipients ) );

		return '' !== $this->recipient;
	}

	public function get_days_overdue(): int {
		return max( 1, absint( $this->get_option( 'days_overdue', '3' ) ) );
	}

	private function get_configured_recipients(): string {
		$recipients = trim( (string) $this->get_option( 'recipients', '' ) );

		return '' !== $recipients ? $recipients : (string) get_option( 'admin_email' );
	}

	public function get_default_subject() {
		return __( '[{site_title}] Escalation: {task_count} overdue CRM task(s)', 'yoohw-customer-intelligence' );
	}

	public function get_default_heading() {
		return __( 'CRM overdue task escalation', 'yoohw-customer-intelligence' );
	}
}

class YoOhw_COS_Email_Daily_Followup_Summary extends YoOhw_COS_Email_Task_Digest {

	public function __construct() {
		$this->id              = 'yoohw_cos_daily_followup_summary';
		$this->title           = __( 'Daily follow-up summary', 'yoohw-customer-intelligence' );
		$this->description     = __( 'Sent once per day to users with overdue, due-today, high-priority, or urgent CRM tasks.', 'yoohw-customer-intelligence' );
		$this->recipient_label = __( 'Users with assigned open tasks', 'yoohw-customer-intelligence' );
		$this->intro           = __( 'Here is your CRM follow-up queue for today.', 'yoohw-customer-intelligence' );

		parent::__construct();
	}

	protected function get_sections(): array {
		$overdue       = array();
		$due_today     = array();
		$high_priority = array();

		foreach ( $this->tasks as $task ) {
			if ( $this->is_overdue( $task ) ) {
				$overdue[] = $task;
				continue;
			}

			if ( $this->is_due_today( $task ) ) {
				$due_today[] = $task;
				continue;
			}

			if ( $this->is_high_priority( $task ) ) {
				$high_priority[] = $task;
			}
		}

		return array_filter(
			array(
				array(
					'title' => __( 'Overdue', 'yoohw-customer-intelligence' ),
					'tasks' => $overdue,
				),
				array(
					'title' => __( 'Due today', 'yoohw-customer-intelligence' ),
					'tasks' => $due_today,
				),
				array(
					'title' => __( 'High and urgent priority', 'yoohw-customer-intelligence' ),
					'tasks' => $high_priority,
				),
			),
			static function( array $section ): bool {
				return ! empty( $section['tasks'] );
			}
		);
	}

	public function get_default_subject() {
		return __( '[{site_title}] CRM follow-up summary ({task_count})', 'yoohw-customer-intelligence' );
	}

	public function get_default_heading() {
		return __( 'Daily CRM follow-up summary', 'yoohw-customer-intelligence' );
	}
}
