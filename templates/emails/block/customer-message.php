<?php
defined( 'ABSPATH' ) || exit;
$yoohw_cos_border_color = $email_palette_border_color ?? '#dcdcde';
?>

<?php if ( '' !== $customer_name ) : ?>
	<p><?php printf( /* translators: %s: recipient display name. */ esc_html__( 'Hi %s,', 'yoohw-customer-intelligence' ), esc_html( $customer_name ) ); ?></p>
<?php endif; ?>

<div class="yoohw-cos-customer-message" style="border: 1px solid <?php echo esc_attr( $yoohw_cos_border_color ); ?>; border-radius: 8px; padding: 20px; overflow-wrap: anywhere;">
	<p style="font-size: 12px; font-weight: 700; margin: 0 0 14px; text-transform: uppercase;"><?php printf( /* translators: %s: store name. */ esc_html__( 'Message from %s', 'yoohw-customer-intelligence' ), esc_html( get_bloginfo( 'name' ) ) ); ?></p>
	<?php echo wp_kses_post( wpautop( esc_html( $message_body ) ) ); ?>
</div>

<?php if ( $additional_content ) : ?>
	<div class="email-additional-content"><?php echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) ); ?></div>
<?php endif; ?>
