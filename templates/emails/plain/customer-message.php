<?php
defined( 'ABSPATH' ) || exit;

echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n\n";

if ( '' !== $customer_name ) {
	printf(
		/* translators: %s: recipient display name. */
		esc_html__( 'Hi %s,', 'yoohw-customer-intelligence' ),
		esc_html( $customer_name )
	);
	echo "\n\n";
}

echo esc_html( $message_body ) . "\n";

if ( $additional_content ) {
	echo "\n" . esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n";
}

echo "\n----------------------------------------\n\n";
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
