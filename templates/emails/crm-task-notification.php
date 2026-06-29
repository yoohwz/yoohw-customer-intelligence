<?php
defined( 'ABSPATH' ) || exit;

$email_improvements_enabled = class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) && \Automattic\WooCommerce\Utilities\FeaturesUtil::feature_is_enabled( 'email_improvements' );
$panel_background           = $email_palette_panel_background_color ?? '#f6f7f7';
$border_color               = $email_palette_border_color ?? '#dcdcde';
$text_color                 = $email_palette_text_color ?? '#1d2327';
$secondary_text_color       = $email_palette_secondary_text_color ?? '#646970';
$accent_color               = $email_palette_accent_color ?? $text_color;
$task_title                 = sanitize_text_field( (string) ( $task['title'] ?? '' ) );
$task_id                    = absint( $task['id'] ?? 0 );
$detail_rows                = array(
	__( 'Customer', 'yoohw-customer-intelligence' ) => '' !== $customer_url ? '<a href="' . esc_url( $customer_url ) . '">' . esc_html( $customer_name ) . '</a>' : esc_html( $customer_name ),
	__( 'Due date', 'yoohw-customer-intelligence' ) => wp_kses_post( $due_date ),
	__( 'Priority', 'yoohw-customer-intelligence' ) => esc_html( $priority_label ),
	__( 'Status', 'yoohw-customer-intelligence' ) => esc_html( $status_label ),
);

if ( ! empty( $task['assignee_name'] ) ) {
	$detail_rows[ __( 'Assignee', 'yoohw-customer-intelligence' ) ] = esc_html( $task['assignee_name'] );
}

if ( ! empty( $task['order_id'] ) ) {
	$order_label = '#' . absint( $task['order_id'] );
	$detail_rows[ __( 'Order', 'yoohw-customer-intelligence' ) ] = '' !== $order_url ? '<a href="' . esc_url( $order_url ) . '">' . esc_html( $order_label ) . '</a>' : esc_html( $order_label );
}

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<?php echo $email_improvements_enabled ? '<div class="email-introduction">' : ''; ?>
<p>
	<?php
	if ( ! empty( $recipient_user ) && $recipient_user instanceof WP_User ) {
		printf(
			/* translators: %s: recipient display name. */
			esc_html__( 'Hi %s,', 'yoohw-customer-intelligence' ),
			esc_html( $recipient_user->display_name )
		);
	} else {
		esc_html_e( 'Hi,', 'yoohw-customer-intelligence' );
	}
	?>
</p>
<p><?php echo esc_html( $email_intro ); ?></p>
<?php echo $email_improvements_enabled ? '</div>' : ''; ?>

<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 0 0 24px;">
	<tr>
		<td style="background: <?php echo esc_attr( $panel_background ); ?>; border: 1px solid <?php echo esc_attr( $border_color ); ?>; border-radius: 8px; padding: 24px;">
			<p style="color: <?php echo esc_attr( $secondary_text_color ); ?>; font-size: 13px; line-height: 18px; margin: 0 0 8px; text-transform: uppercase;">
				<?php echo esc_html( $email_badge ); ?>
				<?php if ( $task_id > 0 ) : ?>
					<span style="text-transform: none;"><?php echo esc_html( ' #' . $task_id ); ?></span>
				<?php endif; ?>
			</p>

			<h2 style="color: <?php echo esc_attr( $text_color ); ?>; font-size: 22px; line-height: 30px; margin: 0 0 8px;">
				<?php if ( '' !== $task_url ) : ?>
					<a href="<?php echo esc_url( $task_url ); ?>" style="color: <?php echo esc_attr( $text_color ); ?>; text-decoration: none;"><?php echo esc_html( $task_title ); ?></a>
				<?php else : ?>
					<?php echo esc_html( $task_title ); ?>
				<?php endif; ?>
			</h2>

			<p style="color: <?php echo esc_attr( $secondary_text_color ); ?>; font-size: 14px; line-height: 22px; margin: 0 0 18px;">
				<?php
				printf(
					/* translators: 1: customer name, 2: due date. */
					esc_html__( '%1$s - due %2$s', 'yoohw-customer-intelligence' ),
					esc_html( $customer_name ),
					esc_html( wp_strip_all_tags( $due_date ) )
				);
				?>
			</p>

			<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="border-top: 1px solid <?php echo esc_attr( $border_color ); ?>;">
				<?php foreach ( $detail_rows as $label => $value ) : ?>
					<tr>
						<td valign="top" style="color: <?php echo esc_attr( $secondary_text_color ); ?>; font-size: 14px; line-height: 20px; padding: 12px 16px 0 0; width: 34%;">
							<?php echo esc_html( $label ); ?>
						</td>
						<td valign="top" style="color: <?php echo esc_attr( $text_color ); ?>; font-size: 14px; line-height: 20px; padding: 12px 0 0;">
							<strong><?php echo wp_kses_post( $value ); ?></strong>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
		</td>
	</tr>
</table>

<?php if ( ! empty( $task['description'] ) ) : ?>
	<h2 class="<?php echo $email_improvements_enabled ? 'email-order-detail-heading' : ''; ?>"><?php esc_html_e( 'Internal note', 'yoohw-customer-intelligence' ); ?></h2>
	<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 0 0 24px;">
		<tr>
			<td style="border-left: 3px solid <?php echo esc_attr( $accent_color ); ?>; color: <?php echo esc_attr( $text_color ); ?>; padding: 2px 0 2px 14px;">
				<?php echo nl2br( esc_html( (string) $task['description'] ) ); ?>
			</td>
		</tr>
	</table>
<?php endif; ?>

<?php if ( '' !== $task_url ) : ?>
	<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin: 0 0 24px;">
		<tr>
			<td>
				<a class="button" href="<?php echo esc_url( $task_url ); ?>"><?php esc_html_e( 'Open task', 'yoohw-customer-intelligence' ); ?></a>
			</td>
		</tr>
	</table>
<?php endif; ?>

<?php
if ( $additional_content ) {
	echo $email_improvements_enabled ? '<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation"><tr><td class="email-additional-content">' : '';
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
	echo $email_improvements_enabled ? '</td></tr></table>' : '';
}

do_action( 'woocommerce_email_footer', $email );
