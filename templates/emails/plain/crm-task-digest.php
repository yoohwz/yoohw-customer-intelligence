<?php
defined( 'ABSPATH' ) || exit;

YoOhw_COS_Email_CRM_Base::output_plain_body( YoOhw_COS_Email_CRM_Base::plain_text( $email_heading ) . "\n\n" );

if ( ! empty( $recipient_user ) && $recipient_user instanceof WP_User ) {
	YoOhw_COS_Email_CRM_Base::output_plain_body( sprintf(
		/* translators: %s: recipient display name. */
		YoOhw_COS_Email_CRM_Base::plain_text( __( 'Hi %s,', 'yoohw-customer-intelligence' ) ),
		YoOhw_COS_Email_CRM_Base::plain_text( $recipient_user->display_name )
	) );
	echo "\n\n";
}

if ( '' !== $intro ) {
	YoOhw_COS_Email_CRM_Base::output_plain_body( YoOhw_COS_Email_CRM_Base::plain_text( $intro ) . "\n\n" );
}

$yoohw_cos_is_escalation = 'yoohw_cos_task_overdue_escalation' === $email->id;
$yoohw_cos_is_daily      = 'yoohw_cos_daily_followup_summary' === $email->id;
if ( $yoohw_cos_is_escalation ) {
	YoOhw_COS_Email_CRM_Base::output_plain_body( YoOhw_COS_Email_CRM_Base::plain_text( __( 'Escalation required.', 'yoohw-customer-intelligence' ) ) . "\n" );
}
YoOhw_COS_Email_CRM_Base::output_plain_body( ( $yoohw_cos_is_escalation ? YoOhw_COS_Email_CRM_Base::plain_text( __( 'Escalated overdue tasks:', 'yoohw-customer-intelligence' ) ) : ( $yoohw_cos_is_daily ? YoOhw_COS_Email_CRM_Base::plain_text( __( 'Today\'s queue:', 'yoohw-customer-intelligence' ) ) : YoOhw_COS_Email_CRM_Base::plain_text( __( 'Overdue queue:', 'yoohw-customer-intelligence' ) ) ) ) . ' ' . YoOhw_COS_Email_CRM_Base::plain_text( number_format_i18n( absint( $task_count ) ) ) . "\n\n" );

foreach ( $sections as $yoohw_cos_section ) {
	if ( empty( $yoohw_cos_section['tasks'] ) ) {
		continue;
	}

	YoOhw_COS_Email_CRM_Base::output_plain_body( YoOhw_COS_Email_CRM_Base::plain_text( $yoohw_cos_section['title'] ) . ' (' . YoOhw_COS_Email_CRM_Base::plain_text( number_format_i18n( count( $yoohw_cos_section['tasks'] ) ) ) . ")\n" );
	YoOhw_COS_Email_CRM_Base::output_plain_body( YoOhw_COS_Email_CRM_Base::plain_text( str_repeat( '-', strlen( (string) $yoohw_cos_section['title'] ) ) ) . "\n" );

	foreach ( $yoohw_cos_section['tasks'] as $yoohw_cos_task ) {
		$yoohw_cos_summary = $email->get_template_task_summary( $yoohw_cos_task );

		YoOhw_COS_Email_CRM_Base::output_plain_body( '- ' . YoOhw_COS_Email_CRM_Base::plain_text( $yoohw_cos_summary['title'] ) . "\n" );
		YoOhw_COS_Email_CRM_Base::output_plain_body( '  ' . YoOhw_COS_Email_CRM_Base::plain_text( __( 'Customer:', 'yoohw-customer-intelligence' ) ) . ' ' . YoOhw_COS_Email_CRM_Base::plain_text( $yoohw_cos_summary['customer_name'] ) . "\n" );
		YoOhw_COS_Email_CRM_Base::output_plain_body( '  ' . YoOhw_COS_Email_CRM_Base::plain_text( __( 'Due:', 'yoohw-customer-intelligence' ) ) . ' ' . YoOhw_COS_Email_CRM_Base::plain_text( $yoohw_cos_summary['due_date'] ) . "\n" );
		YoOhw_COS_Email_CRM_Base::output_plain_body( '  ' . YoOhw_COS_Email_CRM_Base::plain_text( __( 'Priority:', 'yoohw-customer-intelligence' ) ) . ' ' . YoOhw_COS_Email_CRM_Base::plain_text( $yoohw_cos_summary['priority_label'] ) . "\n" );
		if ( '' !== $yoohw_cos_summary['task_url'] ) {
			YoOhw_COS_Email_CRM_Base::output_plain_body( '  ' . YoOhw_COS_Email_CRM_Base::plain_text( __( 'View:', 'yoohw-customer-intelligence' ) ) . ' ' . YoOhw_COS_Email_CRM_Base::plain_url( $yoohw_cos_summary['task_url'] ) . "\n" );
		}
	}

	echo "\n";
}

if ( $yoohw_cos_is_escalation ) {
	YoOhw_COS_Email_CRM_Base::output_plain_body( YoOhw_COS_Email_CRM_Base::plain_text( __( 'This email may also be sent to configured escalation recipients.', 'yoohw-customer-intelligence' ) ) . "\n" );
}
if ( '' !== $task_list_url ) {
	YoOhw_COS_Email_CRM_Base::output_plain_body( ( $yoohw_cos_is_escalation ? YoOhw_COS_Email_CRM_Base::plain_text( __( 'Review overdue tasks:', 'yoohw-customer-intelligence' ) ) : YoOhw_COS_Email_CRM_Base::plain_text( __( 'Open task list:', 'yoohw-customer-intelligence' ) ) ) . ' ' . YoOhw_COS_Email_CRM_Base::plain_url( $task_list_url ) . "\n" );
}

if ( $additional_content ) {
	YoOhw_COS_Email_CRM_Base::output_plain_body( "\n" . YoOhw_COS_Email_CRM_Base::plain_text( wptexturize( $additional_content ) ) . "\n" );
}

echo "\n----------------------------------------\n\n";
YoOhw_COS_Email_CRM_Base::output_plain_body( YoOhw_COS_Email_CRM_Base::plain_text( (string) apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce owns this existing email hook; preserving its name is required for email integrations.
