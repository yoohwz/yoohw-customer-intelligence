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

if ( '' !== $intro ) {
	echo YoOhw_COS_Email_CRM_Base::plain_text( $intro ) . "\n\n";
}

$is_escalation = 'yoohw_cos_task_overdue_escalation' === $email->id;
$is_daily      = 'yoohw_cos_daily_followup_summary' === $email->id;
if ( $is_escalation ) {
	echo YoOhw_COS_Email_CRM_Base::plain_text( __( 'Escalation required.', 'yoohw-customer-intelligence' ) ) . "\n";
}
echo ( $is_escalation ? YoOhw_COS_Email_CRM_Base::plain_text( __( 'Escalated overdue tasks:', 'yoohw-customer-intelligence' ) ) : ( $is_daily ? YoOhw_COS_Email_CRM_Base::plain_text( __( 'Today\'s queue:', 'yoohw-customer-intelligence' ) ) : YoOhw_COS_Email_CRM_Base::plain_text( __( 'Overdue queue:', 'yoohw-customer-intelligence' ) ) ) ) . ' ' . YoOhw_COS_Email_CRM_Base::plain_text( number_format_i18n( absint( $task_count ) ) ) . "\n\n";

foreach ( $sections as $section ) {
	if ( empty( $section['tasks'] ) ) {
		continue;
	}

	echo YoOhw_COS_Email_CRM_Base::plain_text( $section['title'] ) . ' (' . YoOhw_COS_Email_CRM_Base::plain_text( number_format_i18n( count( $section['tasks'] ) ) ) . ")\n";
	echo YoOhw_COS_Email_CRM_Base::plain_text( str_repeat( '-', strlen( (string) $section['title'] ) ) ) . "\n";

	foreach ( $section['tasks'] as $task ) {
		$summary = $email->get_template_task_summary( $task );

		echo '- ' . YoOhw_COS_Email_CRM_Base::plain_text( $summary['title'] ) . "\n";
		echo '  ' . YoOhw_COS_Email_CRM_Base::plain_text( __( 'Customer:', 'yoohw-customer-intelligence' ) ) . ' ' . YoOhw_COS_Email_CRM_Base::plain_text( $summary['customer_name'] ) . "\n";
		echo '  ' . YoOhw_COS_Email_CRM_Base::plain_text( __( 'Due:', 'yoohw-customer-intelligence' ) ) . ' ' . YoOhw_COS_Email_CRM_Base::plain_text( $summary['due_date'] ) . "\n";
		echo '  ' . YoOhw_COS_Email_CRM_Base::plain_text( __( 'Priority:', 'yoohw-customer-intelligence' ) ) . ' ' . YoOhw_COS_Email_CRM_Base::plain_text( $summary['priority_label'] ) . "\n";
		if ( '' !== $summary['task_url'] ) {
			echo '  ' . YoOhw_COS_Email_CRM_Base::plain_text( __( 'View:', 'yoohw-customer-intelligence' ) ) . ' ' . YoOhw_COS_Email_CRM_Base::plain_url( $summary['task_url'] ) . "\n";
		}
	}

	echo "\n";
}

if ( $is_escalation ) {
	echo YoOhw_COS_Email_CRM_Base::plain_text( __( 'This email may also be sent to configured escalation recipients.', 'yoohw-customer-intelligence' ) ) . "\n";
}
if ( '' !== $task_list_url ) {
	echo ( $is_escalation ? YoOhw_COS_Email_CRM_Base::plain_text( __( 'Review overdue tasks:', 'yoohw-customer-intelligence' ) ) : YoOhw_COS_Email_CRM_Base::plain_text( __( 'Open task list:', 'yoohw-customer-intelligence' ) ) ) . ' ' . YoOhw_COS_Email_CRM_Base::plain_url( $task_list_url ) . "\n";
}

if ( $additional_content ) {
	echo "\n" . YoOhw_COS_Email_CRM_Base::plain_text( wptexturize( $additional_content ) ) . "\n";
}

echo "\n----------------------------------------\n\n";
echo YoOhw_COS_Email_CRM_Base::plain_text( (string) apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
