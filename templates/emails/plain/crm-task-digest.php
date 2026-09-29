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

if ( '' !== $intro ) {
	echo esc_html( $intro ) . "\n\n";
}

$is_escalation = 'yoohw_cos_task_overdue_escalation' === $email->id;
$is_daily      = 'yoohw_cos_daily_followup_summary' === $email->id;
if ( $is_escalation ) {
	echo esc_html__( 'Escalation required.', 'yoohw-customer-intelligence' ) . "\n";
}
echo ( $is_escalation ? esc_html__( 'Escalated overdue tasks:', 'yoohw-customer-intelligence' ) : ( $is_daily ? esc_html__( 'Today\'s queue:', 'yoohw-customer-intelligence' ) : esc_html__( 'Overdue queue:', 'yoohw-customer-intelligence' ) ) ) . ' ' . esc_html( number_format_i18n( absint( $task_count ) ) ) . "\n\n";

foreach ( $sections as $section ) {
	if ( empty( $section['tasks'] ) ) {
		continue;
	}

	echo esc_html( $section['title'] ) . ' (' . esc_html( number_format_i18n( count( $section['tasks'] ) ) ) . ")\n";
	echo esc_html( str_repeat( '-', strlen( (string) $section['title'] ) ) ) . "\n";

	foreach ( $section['tasks'] as $task ) {
		$summary = $email->get_template_task_summary( $task );

		echo '- ' . esc_html( $summary['title'] ) . "\n";
		echo '  ' . esc_html__( 'Customer:', 'yoohw-customer-intelligence' ) . ' ' . esc_html( $summary['customer_name'] ) . "\n";
		echo '  ' . esc_html__( 'Due:', 'yoohw-customer-intelligence' ) . ' ' . esc_html( wp_strip_all_tags( $summary['due_date'] ) ) . "\n";
		echo '  ' . esc_html__( 'Priority:', 'yoohw-customer-intelligence' ) . ' ' . esc_html( $summary['priority_label'] ) . "\n";
		if ( '' !== $summary['task_url'] ) {
			echo '  ' . esc_html__( 'View:', 'yoohw-customer-intelligence' ) . ' ' . esc_url( $summary['task_url'] ) . "\n";
		}
	}

	echo "\n";
}

if ( $is_escalation ) {
	echo esc_html__( 'This email may also be sent to configured escalation recipients.', 'yoohw-customer-intelligence' ) . "\n";
}
if ( '' !== $task_list_url ) {
	echo ( $is_escalation ? esc_html__( 'Review overdue tasks:', 'yoohw-customer-intelligence' ) : esc_html__( 'Open task list:', 'yoohw-customer-intelligence' ) ) . ' ' . esc_url( $task_list_url ) . "\n";
}

if ( $additional_content ) {
	echo "\n" . esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n";
}

echo "\n----------------------------------------\n\n";
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
