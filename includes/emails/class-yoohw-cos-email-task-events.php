<?php
defined( 'ABSPATH' ) || exit;

abstract class YoOhw_COS_Email_Task_Event extends YoOhw_COS_Email_CRM_Base {

	public function __construct() {
		$this->template_html  = 'emails/crm-task-notification.php';
		$this->template_plain = 'emails/plain/crm-task-notification.php';

		parent::__construct();
	}

	public function trigger( array $task, int $recipient_user_id, array $context = array() ): bool {
		$this->task    = $task;
		$this->context = $context;
		$this->object  = (object) $task;

		if ( empty( $task ) || ! $this->prepare_recipient_user( $recipient_user_id ) ) {
			return false;
		}

		$this->set_task_placeholders( $task );

		return $this->send_notification();
	}

	public function get_content_html() {
		return wc_get_template_html(
			$this->template_html,
			$this->get_task_template_args( false ),
			'',
			$this->template_base
		);
	}

	public function get_content_plain() {
		return wc_get_template_html(
			$this->template_plain,
			$this->get_task_template_args( true ),
			'',
			$this->template_base
		);
	}

	public function get_intro_text(): string {
		return __( 'A CRM follow-up task needs attention.', 'yoohw-customer-intelligence' );
	}

	public function get_badge_label(): string {
		return __( 'CRM follow-up', 'yoohw-customer-intelligence' );
	}
}

class YoOhw_COS_Email_Task_Assigned extends YoOhw_COS_Email_Task_Event {

	public function __construct() {
		$this->id              = 'yoohw_cos_task_assigned';
		$this->title           = __( 'Task assigned', 'yoohw-customer-intelligence' );
		$this->description     = __( 'Sent when a CRM follow-up task is assigned to a user.', 'yoohw-customer-intelligence' );
		$this->recipient_label = __( 'Task assignee', 'yoohw-customer-intelligence' );

		parent::__construct();
	}

	public function get_default_subject() {
		return __( '[{site_title}] New CRM task: {task_title}', 'yoohw-customer-intelligence' );
	}

	public function get_default_heading() {
		return __( 'New CRM task assigned', 'yoohw-customer-intelligence' );
	}

	public function get_intro_text(): string {
		return __( 'You have a new CRM follow-up task assigned to you.', 'yoohw-customer-intelligence' );
	}

	public function get_badge_label(): string {
		return __( 'New assignment', 'yoohw-customer-intelligence' );
	}
}

class YoOhw_COS_Email_Task_Reassigned extends YoOhw_COS_Email_Task_Event {

	public function __construct() {
		$this->id              = 'yoohw_cos_task_reassigned';
		$this->title           = __( 'Task reassigned', 'yoohw-customer-intelligence' );
		$this->description     = __( 'Sent when a CRM follow-up task is reassigned to another user.', 'yoohw-customer-intelligence' );
		$this->recipient_label = __( 'New task assignee', 'yoohw-customer-intelligence' );

		parent::__construct();
	}

	public function init_form_fields() {
		parent::init_form_fields();

		$this->form_fields['notify_previous_assignee'] = array(
			'title'       => __( 'Previous assignee', 'yoohw-customer-intelligence' ),
			'type'        => 'checkbox',
			'label'       => __( 'Also notify the previous assignee', 'yoohw-customer-intelligence' ),
			'default'     => 'no',
			'description' => __( 'Useful when your team needs a handoff record when tasks move between owners.', 'yoohw-customer-intelligence' ),
			'desc_tip'    => true,
		);
	}

	public function should_notify_previous_assignee(): bool {
		return 'yes' === $this->get_option( 'notify_previous_assignee', 'no' );
	}

	public function get_default_subject() {
		return __( '[{site_title}] CRM task reassigned: {task_title}', 'yoohw-customer-intelligence' );
	}

	public function get_default_heading() {
		return __( 'CRM task reassigned', 'yoohw-customer-intelligence' );
	}

	public function get_intro_text(): string {
		if ( ! empty( $this->context['previous_notice'] ) ) {
			return __( 'This CRM follow-up task has been reassigned away from you.', 'yoohw-customer-intelligence' );
		}

		return __( 'This CRM follow-up task has been reassigned to you.', 'yoohw-customer-intelligence' );
	}

	public function get_badge_label(): string {
		return __( 'Reassigned', 'yoohw-customer-intelligence' );
	}
}

class YoOhw_COS_Email_Task_Due_Soon extends YoOhw_COS_Email_Task_Event {

	public function __construct() {
		$this->id              = 'yoohw_cos_task_due_soon';
		$this->title           = __( 'Task due soon', 'yoohw-customer-intelligence' );
		$this->description     = __( 'Sent before an assigned open CRM task reaches its due date.', 'yoohw-customer-intelligence' );
		$this->recipient_label = __( 'Task assignee', 'yoohw-customer-intelligence' );

		parent::__construct();
	}

	public function init_form_fields() {
		parent::init_form_fields();

		$this->form_fields['lead_time_hours'] = array(
			'title'       => __( 'Due soon window', 'yoohw-customer-intelligence' ),
			'type'        => 'select',
			'description' => __( 'Send due soon notifications for tasks due within this window.', 'yoohw-customer-intelligence' ),
			'default'     => '24',
			'class'       => 'wc-enhanced-select',
			'options'     => array(
				'1'  => __( '1 hour', 'yoohw-customer-intelligence' ),
				'6'  => __( '6 hours', 'yoohw-customer-intelligence' ),
				'24' => __( '24 hours', 'yoohw-customer-intelligence' ),
				'48' => __( '48 hours', 'yoohw-customer-intelligence' ),
			),
			'desc_tip'    => true,
		);
	}

	public function get_lead_time_hours(): int {
		return max( 1, absint( $this->get_option( 'lead_time_hours', '24' ) ) );
	}

	public function get_default_subject() {
		return __( '[{site_title}] CRM task due soon: {task_title}', 'yoohw-customer-intelligence' );
	}

	public function get_default_heading() {
		return __( 'CRM task due soon', 'yoohw-customer-intelligence' );
	}

	public function get_intro_text(): string {
		return __( 'This CRM follow-up task is approaching its due date.', 'yoohw-customer-intelligence' );
	}

	public function get_badge_label(): string {
		return __( 'Due soon', 'yoohw-customer-intelligence' );
	}
}

class YoOhw_COS_Email_Task_Completed extends YoOhw_COS_Email_Task_Event {

	public function __construct() {
		$this->id              = 'yoohw_cos_task_completed';
		$this->title           = __( 'Task completed', 'yoohw-customer-intelligence' );
		$this->description     = __( 'Optionally sent when a CRM task is completed by another team member.', 'yoohw-customer-intelligence' );
		$this->recipient_label = __( 'Creator or assignee when completed by someone else', 'yoohw-customer-intelligence' );
		$this->default_enabled = 'no';

		parent::__construct();
	}

	public function get_default_subject() {
		return __( '[{site_title}] CRM task completed: {task_title}', 'yoohw-customer-intelligence' );
	}

	public function get_default_heading() {
		return __( 'CRM task completed', 'yoohw-customer-intelligence' );
	}

	public function get_intro_text(): string {
		return __( 'This CRM follow-up task has been marked complete.', 'yoohw-customer-intelligence' );
	}

	public function get_badge_label(): string {
		return __( 'Completed', 'yoohw-customer-intelligence' );
	}
}

class YoOhw_COS_Email_Task_Reopened extends YoOhw_COS_Email_Task_Event {

	public function __construct() {
		$this->id              = 'yoohw_cos_task_reopened';
		$this->title           = __( 'Task reopened', 'yoohw-customer-intelligence' );
		$this->description     = __( 'Sent when a completed CRM task is reopened.', 'yoohw-customer-intelligence' );
		$this->recipient_label = __( 'Task assignee and creator', 'yoohw-customer-intelligence' );

		parent::__construct();
	}

	public function get_default_subject() {
		return __( '[{site_title}] CRM task reopened: {task_title}', 'yoohw-customer-intelligence' );
	}

	public function get_default_heading() {
		return __( 'CRM task reopened', 'yoohw-customer-intelligence' );
	}

	public function get_intro_text(): string {
		return __( 'This CRM follow-up task has been reopened and needs follow-up.', 'yoohw-customer-intelligence' );
	}

	public function get_badge_label(): string {
		return __( 'Reopened', 'yoohw-customer-intelligence' );
	}
}
