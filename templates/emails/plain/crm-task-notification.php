<?php
defined( 'ABSPATH' ) || exit;

echo YoOhw_COS_Email_CRM_Base::plain_text( $email_heading ) . "\n\n";

if ( ! empty( $recipient_user ) && $recipient_user instanceof WP_User ) {
	printf(
		/* translators: %s: recipient display name. */
		YoOhw_COS_Email_CRM_Base::plain_text( __( 'Hi %s,', 'yoohw-customer-intelligence' ) ),
		YoOhw_COS_Email_CRM_Base::plain_text( $recipient_user->display_name )
	);
	echo "\n\n";
}

echo YoOhw_COS_Email_CRM_Base::plain_text( $email_intro );

$is_previous_notice = 'yoohw_cos_task_reassigned' === $email->id && ! empty( $context['previous_notice'] );
$is_completed       = 'yoohw_cos_task_completed' === $email->id;
$is_reopened        = 'yoohw_cos_task_reopened' === $email->id;
if ( $is_previous_notice ) {
	echo "\n" . YoOhw_COS_Email_CRM_Base::plain_text( __( 'No action is required unless you need to add context for the new owner.', 'yoohw-customer-intelligence' ) );
}

echo "\n\n";
echo YoOhw_COS_Email_CRM_Base::plain_text( $is_previous_notice ? YoOhw_COS_Email_CRM_Base::plain_text( __( 'Handoff complete', 'yoohw-customer-intelligence' ) ) : $email_badge ) . "\n";
echo YoOhw_COS_Email_CRM_Base::plain_text( __( 'Task:', 'yoohw-customer-intelligence' ) ) . ' ' . YoOhw_COS_Email_CRM_Base::plain_text( (string) ( $task['title'] ?? '' ) ) . "\n";
echo YoOhw_COS_Email_CRM_Base::plain_text( __( 'Task ID:', 'yoohw-customer-intelligence' ) ) . ' #' . YoOhw_COS_Email_CRM_Base::plain_text( (string) absint( $task['id'] ?? 0 ) ) . "\n";
echo YoOhw_COS_Email_CRM_Base::plain_text( __( 'Customer:', 'yoohw-customer-intelligence' ) ) . ' ' . YoOhw_COS_Email_CRM_Base::plain_text( $customer_name ) . "\n";
echo YoOhw_COS_Email_CRM_Base::plain_text( __( 'Due date:', 'yoohw-customer-intelligence' ) ) . ' ' . YoOhw_COS_Email_CRM_Base::plain_text( $due_date ) . "\n";
echo YoOhw_COS_Email_CRM_Base::plain_text( __( 'Priority:', 'yoohw-customer-intelligence' ) ) . ' ' . YoOhw_COS_Email_CRM_Base::plain_text( $priority_label ) . "\n";
echo YoOhw_COS_Email_CRM_Base::plain_text( __( 'Status:', 'yoohw-customer-intelligence' ) ) . ' ' . YoOhw_COS_Email_CRM_Base::plain_text( $status_label ) . "\n";

if ( ! empty( $task['assignee_name'] ) ) {
	echo ( $is_previous_notice ? YoOhw_COS_Email_CRM_Base::plain_text( __( 'New assignee:', 'yoohw-customer-intelligence' ) ) : YoOhw_COS_Email_CRM_Base::plain_text( __( 'Assignee:', 'yoohw-customer-intelligence' ) ) ) . ' ' . YoOhw_COS_Email_CRM_Base::plain_text( $task['assignee_name'] ) . "\n";
}

if ( $is_completed && ! empty( $task['completed_by_name'] ) ) {
	echo YoOhw_COS_Email_CRM_Base::plain_text( __( 'Completed by:', 'yoohw-customer-intelligence' ) ) . ' ' . YoOhw_COS_Email_CRM_Base::plain_text( $task['completed_by_name'] ) . "\n";
}

if ( ! empty( $task['order_id'] ) ) {
	echo YoOhw_COS_Email_CRM_Base::plain_text( __( 'Order:', 'yoohw-customer-intelligence' ) ) . ' #' . YoOhw_COS_Email_CRM_Base::plain_text( (string) absint( $task['order_id'] ) ) . "\n";
}

if ( ! empty( $task['description'] ) ) {
	echo "\n" . ( $is_reopened ? YoOhw_COS_Email_CRM_Base::plain_text( __( 'Latest note:', 'yoohw-customer-intelligence' ) ) : YoOhw_COS_Email_CRM_Base::plain_text( __( 'Internal note:', 'yoohw-customer-intelligence' ) ) ) . "\n";
	echo YoOhw_COS_Email_CRM_Base::plain_text( (string) $task['description'] ) . "\n";
}

if ( '' !== $task_url ) {
	echo "\n" . ( $is_previous_notice || $is_completed ? YoOhw_COS_Email_CRM_Base::plain_text( __( 'View task:', 'yoohw-customer-intelligence' ) ) : YoOhw_COS_Email_CRM_Base::plain_text( __( 'Open task:', 'yoohw-customer-intelligence' ) ) ) . ' ' . YoOhw_COS_Email_CRM_Base::plain_url( $task_url ) . "\n";
}

if ( $additional_content ) {
	echo "\n" . YoOhw_COS_Email_CRM_Base::plain_text( wptexturize( $additional_content ) ) . "\n";
}

echo "\n----------------------------------------\n\n";
echo YoOhw_COS_Email_CRM_Base::plain_text( (string) apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
