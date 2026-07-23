<?php
defined( 'ABSPATH' ) || exit;
?>

<?php if ( '' !== $customer_name ) : ?>
	<p>
		<?php
		printf(
			/* translators: %s: recipient display name. */
			esc_html__( 'Hi %s,', 'yoohw-customer-intelligence' ),
			esc_html( $customer_name )
		);
		?>
	</p>
<?php endif; ?>

<div class="yoohw-cos-customer-message">
	<?php echo wp_kses_post( wpautop( esc_html( $message_body ) ) ); ?>
</div>

<?php if ( $additional_content ) : ?>
	<div class="email-additional-content">
		<?php echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) ); ?>
	</div>
<?php endif; ?>
