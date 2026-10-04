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

YoOhw_COS_Email_CRM_Base::output_plain_body( YoOhw_COS_Email_CRM_Base::plain_text( $email_intro ) );

$yoohw_cos_is_previous_notice = 'yoohw_cos_task_reassigned' === $email->id && ! empty( $context['previous_notice'] );
$yoohw_cos_is_completed       = 'yoohw_cos_task_completed' === $email->id;
$yoohw_cos_is_reopened        = 'yoohw_cos_task_reopened' === $email->id;
if ( $yoohw_cos_is_previous_notice ) {
	YoOhw_COS_Email_CRM_Base::output_plain_body( "\n" . YoOhw_COS_Email_CRM_Base::plain_text( __( 'No action is required unless you need to add context for the new owner.', 'yoohw-customer-intelligence' ) ) );
}

echo "\n\n";
YoOhw_COS_Email_CRM_Base::output_plain_body( YoOhw_COS_Email_CRM_Base::plain_text( $yoohw_cos_is_previous_notice ? YoOhw_COS_Email_CRM_Base::plain_text( __( 'Handoff complete', 'yoohw-customer-intelligence' ) ) : $email_badge ) . "\n" );
YoOhw_COS_Email_CRM_Base::output_plain_body( YoOhw_COS_Email_CRM_Base::plain_text( __( 'Task:', 'yoohw-customer-intelligence' ) ) . ' ' . YoOhw_COS_Email_CRM_Base::plain_text( (string) ( $task['title'] ?? '' ) ) . "\n" );
YoOhw_COS_Email_CRM_Base::output_plain_body( YoOhw_COS_Email_CRM_Base::plain_text( __( 'Task ID:', 'yoohw-customer-intelligence' ) ) . ' #' . YoOhw_COS_Email_CRM_Base::plain_text( (string) absint( $task['id'] ?? 0 ) ) . "\n" );
YoOhw_COS_Email_CRM_Base::output_plain_body( YoOhw_COS_Email_CRM_Base::plain_text( __( 'Customer:', 'yoohw-customer-intelligence' ) ) . ' ' . YoOhw_COS_Email_CRM_Base::plain_text( $customer_name ) . "\n" );
YoOhw_COS_Email_CRM_Base::output_plain_body( YoOhw_COS_Email_CRM_Base::plain_text( __( 'Due date:', 'yoohw-customer-intelligence' ) ) . ' ' . YoOhw_COS_Email_CRM_Base::plain_text( $due_date ) . "\n" );
YoOhw_COS_Email_CRM_Base::output_plain_body( YoOhw_COS_Email_CRM_Base::plain_text( __( 'Priority:', 'yoohw-customer-intelligence' ) ) . ' ' . YoOhw_COS_Email_CRM_Base::plain_text( $priority_label ) . "\n" );
YoOhw_COS_Email_CRM_Base::output_plain_body( YoOhw_COS_Email_CRM_Base::plain_text( __( 'Status:', 'yoohw-customer-intelligence' ) ) . ' ' . YoOhw_COS_Email_CRM_Base::plain_text( $status_label ) . "\n" );

if ( ! empty( $task['assignee_name'] ) ) {
	YoOhw_COS_Email_CRM_Base::output_plain_body( ( $yoohw_cos_is_previous_notice ? YoOhw_COS_Email_CRM_Base::plain_text( __( 'New assignee:', 'yoohw-customer-intelligence' ) ) : YoOhw_COS_Email_CRM_Base::plain_text( __( 'Assignee:', 'yoohw-customer-intelligence' ) ) ) . ' ' . YoOhw_COS_Email_CRM_Base::plain_text( $task['assignee_name'] ) . "\n" );
}

if ( $yoohw_cos_is_completed && ! empty( $task['completed_by_name'] ) ) {
	YoOhw_COS_Email_CRM_Base::output_plain_body( YoOhw_COS_Email_CRM_Base::plain_text( __( 'Completed by:', 'yoohw-customer-intelligence' ) ) . ' ' . YoOhw_COS_Email_CRM_Base::plain_text( $task['completed_by_name'] ) . "\n" );
}

if ( ! empty( $task['order_id'] ) ) {
	YoOhw_COS_Email_CRM_Base::output_plain_body( YoOhw_COS_Email_CRM_Base::plain_text( __( 'Order:', 'yoohw-customer-intelligence' ) ) . ' #' . YoOhw_COS_Email_CRM_Base::plain_text( (string) absint( $task['order_id'] ) ) . "\n" );
}

if ( ! empty( $task['description'] ) ) {
	YoOhw_COS_Email_CRM_Base::output_plain_body( "\n" . ( $yoohw_cos_is_reopened ? YoOhw_COS_Email_CRM_Base::plain_text( __( 'Latest note:', 'yoohw-customer-intelligence' ) ) : YoOhw_COS_Email_CRM_Base::plain_text( __( 'Internal note:', 'yoohw-customer-intelligence' ) ) ) . "\n" );
	YoOhw_COS_Email_CRM_Base::output_plain_body( YoOhw_COS_Email_CRM_Base::plain_text( (string) $task['description'] ) . "\n" );
}

if ( '' !== $task_url ) {
	YoOhw_COS_Email_CRM_Base::output_plain_body( "\n" . ( $yoohw_cos_is_previous_notice || $yoohw_cos_is_completed ? YoOhw_COS_Email_CRM_Base::plain_text( __( 'View task:', 'yoohw-customer-intelligence' ) ) : YoOhw_COS_Email_CRM_Base::plain_text( __( 'Open task:', 'yoohw-customer-intelligence' ) ) ) . ' ' . YoOhw_COS_Email_CRM_Base::plain_url( $task_url ) . "\n" );
}

if ( $additional_content ) {
	YoOhw_COS_Email_CRM_Base::output_plain_body( "\n" . YoOhw_COS_Email_CRM_Base::plain_text( wptexturize( $additional_content ) ) . "\n" );
}

echo "\n----------------------------------------\n\n";
YoOhw_COS_Email_CRM_Base::output_plain_body( YoOhw_COS_Email_CRM_Base::plain_text( (string) apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce owns this existing email hook; preserving its name is required for email integrations.
