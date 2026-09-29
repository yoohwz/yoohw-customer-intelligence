<?php
defined( 'ABSPATH' ) || exit;

echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n\n";

if ( ! empty( $recipient_user ) && $recipient_user instanceof WP_User ) {
	printf(
		/* translators: %s: recipient display name. */
		esc_html__( 'Hi %s,', 'yoohw-customer-intelligence' ),
		esc_html( $recipient_user->display_name )
	);
	echo "\n\n";
}

echo esc_html( $email_intro );

$is_previous_notice = 'yoohw_cos_task_reassigned' === $email->id && ! empty( $context['previous_notice'] );
$is_completed       = 'yoohw_cos_task_completed' === $email->id;
$is_reopened        = 'yoohw_cos_task_reopened' === $email->id;
if ( $is_previous_notice ) {
	echo "\n" . esc_html__( 'No action is required unless you need to add context for the new owner.', 'yoohw-customer-intelligence' );
}

echo "\n\n";
echo esc_html( $is_previous_notice ? __( 'Handoff complete', 'yoohw-customer-intelligence' ) : $email_badge ) . "\n";
echo esc_html__( 'Task:', 'yoohw-customer-intelligence' ) . ' ' . esc_html( (string) ( $task['title'] ?? '' ) ) . "\n";
echo esc_html__( 'Task ID:', 'yoohw-customer-intelligence' ) . ' #' . esc_html( absint( $task['id'] ?? 0 ) ) . "\n";
echo esc_html__( 'Customer:', 'yoohw-customer-intelligence' ) . ' ' . esc_html( $customer_name ) . "\n";
echo esc_html__( 'Due date:', 'yoohw-customer-intelligence' ) . ' ' . esc_html( wp_strip_all_tags( $due_date ) ) . "\n";
echo esc_html__( 'Priority:', 'yoohw-customer-intelligence' ) . ' ' . esc_html( $priority_label ) . "\n";
echo esc_html__( 'Status:', 'yoohw-customer-intelligence' ) . ' ' . esc_html( $status_label ) . "\n";

if ( ! empty( $task['assignee_name'] ) ) {
	echo ( $is_previous_notice ? esc_html__( 'New assignee:', 'yoohw-customer-intelligence' ) : esc_html__( 'Assignee:', 'yoohw-customer-intelligence' ) ) . ' ' . esc_html( $task['assignee_name'] ) . "\n";
}

if ( $is_completed && ! empty( $task['completed_by_name'] ) ) {
	echo esc_html__( 'Completed by:', 'yoohw-customer-intelligence' ) . ' ' . esc_html( $task['completed_by_name'] ) . "\n";
}

if ( ! empty( $task['order_id'] ) ) {
	echo esc_html__( 'Order:', 'yoohw-customer-intelligence' ) . ' #' . esc_html( absint( $task['order_id'] ) ) . "\n";
}

if ( ! empty( $task['description'] ) ) {
	echo "\n" . ( $is_reopened ? esc_html__( 'Latest note:', 'yoohw-customer-intelligence' ) : esc_html__( 'Internal note:', 'yoohw-customer-intelligence' ) ) . "\n";
	echo esc_html( (string) $task['description'] ) . "\n";
}

if ( '' !== $task_url ) {
	echo "\n" . ( $is_previous_notice || $is_completed ? esc_html__( 'View task:', 'yoohw-customer-intelligence' ) : esc_html__( 'Open task:', 'yoohw-customer-intelligence' ) ) . ' ' . esc_url( $task_url ) . "\n";
}

if ( $additional_content ) {
	echo "\n" . esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n";
}

echo "\n----------------------------------------\n\n";
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
