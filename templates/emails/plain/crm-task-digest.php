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

foreach ( $sections as $section ) {
	if ( empty( $section['tasks'] ) ) {
		continue;
	}

	echo esc_html( $section['title'] ) . "\n";
	echo esc_html( str_repeat( '-', strlen( (string) $section['title'] ) ) ) . "\n";

	foreach ( $section['tasks'] as $task ) {
		$summary = $email->get_template_task_summary( $task );

		echo '- ' . esc_html( $summary['title'] ) . "\n";
		echo '  ' . esc_html__( 'Customer:', 'yoohw-customer-intelligence' ) . ' ' . esc_html( $summary['customer_name'] ) . "\n";
		echo '  ' . esc_html__( 'Due:', 'yoohw-customer-intelligence' ) . ' ' . esc_html( wp_strip_all_tags( $summary['due_date'] ) ) . "\n";
		echo '  ' . esc_html__( 'Priority:', 'yoohw-customer-intelligence' ) . ' ' . esc_html( $summary['priority_label'] ) . "\n";
		echo '  ' . esc_html__( 'View:', 'yoohw-customer-intelligence' ) . ' ' . esc_url( $summary['task_url'] ) . "\n";
	}

	echo "\n";
}

echo esc_html__( 'View tasks:', 'yoohw-customer-intelligence' ) . ' ' . esc_url( $task_list_url ) . "\n";

if ( $additional_content ) {
	echo "\n" . esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n";
}

echo "\n----------------------------------------\n\n";
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
