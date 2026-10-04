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

printf( /* translators: %s: store name. */ esc_html__( 'Message from %s', 'yoohw-customer-intelligence' ), esc_html( get_bloginfo( 'name' ) ) );
echo "\n\n" . esc_html( $message_body ) . "\n";

if ( $additional_content ) {
	echo "\n" . esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n";
}

echo "\n----------------------------------------\n\n";
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce owns this existing email hook; preserving its name is required for email integrations.
