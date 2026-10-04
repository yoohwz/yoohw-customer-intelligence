<?php
defined( 'ABSPATH' ) || exit;

$yoohw_cos_email_improvements_enabled = class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) && \Automattic\WooCommerce\Utilities\FeaturesUtil::feature_is_enabled( 'email_improvements' );
$yoohw_cos_border_color               = $email_palette_border_color ?? '#dcdcde';
$yoohw_cos_text_color                 = $email_palette_text_color ?? '#1d2327';
$yoohw_cos_secondary_text_color       = $email_palette_secondary_text_color ?? '#646970';
$yoohw_cos_store_name                 = get_bloginfo( 'name' );

do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce owns this existing email hook; preserving its name is required for email integrations.
?>

<?php echo $yoohw_cos_email_improvements_enabled ? '<div class="email-introduction">' : ''; ?>
<?php if ( '' !== $customer_name ) : ?>
	<p><?php printf( /* translators: %s: recipient display name. */ esc_html__( 'Hi %s,', 'yoohw-customer-intelligence' ), esc_html( $customer_name ) ); ?></p>
<?php endif; ?>
<?php echo $yoohw_cos_email_improvements_enabled ? '</div>' : ''; ?>

<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 0 0 22px;">
	<tr><td style="border: 1px solid <?php echo esc_attr( $yoohw_cos_border_color ); ?>; border-radius: 8px; color: <?php echo esc_attr( $yoohw_cos_text_color ); ?>; padding: 20px; overflow-wrap: anywhere;">
		<p style="color: <?php echo esc_attr( $yoohw_cos_secondary_text_color ); ?>; font-size: 12px; font-weight: 700; line-height: 18px; margin: 0 0 14px; text-transform: uppercase;">
			<?php printf( /* translators: %s: store name. */ esc_html__( 'Message from %s', 'yoohw-customer-intelligence' ), esc_html( $yoohw_cos_store_name ) ); ?>
		</p>
		<div class="yoohw-cos-customer-message"><?php echo wp_kses_post( wpautop( esc_html( $message_body ) ) ); ?></div>
	</td></tr>
</table>

<?php
if ( $additional_content ) {
	echo $yoohw_cos_email_improvements_enabled ? '<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation"><tr><td class="email-additional-content">' : '';
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
	echo $yoohw_cos_email_improvements_enabled ? '</td></tr></table>' : '';
}
do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce owns this existing email hook; preserving its name is required for email integrations.
