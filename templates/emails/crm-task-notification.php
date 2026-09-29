<?php
defined( 'ABSPATH' ) || exit;

$email_improvements_enabled = class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) && \Automattic\WooCommerce\Utilities\FeaturesUtil::feature_is_enabled( 'email_improvements' );
$panel_background           = $email_palette_panel_background_color ?? '#f6f7f7';
$body_background            = $email_palette_body_background_color ?? '#ffffff';
$border_color               = $email_palette_border_color ?? '#dcdcde';
$text_color                 = $email_palette_text_color ?? '#1d2327';
$secondary_text_color       = $email_palette_secondary_text_color ?? '#646970';
$accent_color               = $email_palette_accent_color ?? $text_color;
$task_title                 = sanitize_text_field( (string) ( $task['title'] ?? '' ) );
$task_id                    = absint( $task['id'] ?? 0 );
$is_previous_notice         = 'yoohw_cos_task_reassigned' === $email->id && ! empty( $context['previous_notice'] );
$is_completed               = 'yoohw_cos_task_completed' === $email->id;
$is_due_soon                = 'yoohw_cos_task_due_soon' === $email->id;
$is_reopened                = 'yoohw_cos_task_reopened' === $email->id;
$is_handoff                 = $is_previous_notice || $is_completed;
$badge_label                = $is_previous_notice ? __( 'Handoff complete', 'yoohw-customer-intelligence' ) : $email_badge;
$detail_rows                = array(
	__( 'Customer', 'yoohw-customer-intelligence' ) => '' !== $customer_url ? '<a href="' . esc_url( $customer_url ) . '">' . esc_html( $customer_name ) . '</a>' : esc_html( $customer_name ),
	__( 'Due date', 'yoohw-customer-intelligence' ) => esc_html( wp_strip_all_tags( $due_date ) ),
	__( 'Priority', 'yoohw-customer-intelligence' ) => esc_html( $priority_label ),
	__( 'Status', 'yoohw-customer-intelligence' ) => esc_html( $status_label ),
);

if ( ! empty( $task['assignee_name'] ) ) {
	$assignee_label = $is_previous_notice ? __( 'New assignee', 'yoohw-customer-intelligence' ) : __( 'Assignee', 'yoohw-customer-intelligence' );
	$detail_rows[ $assignee_label ] = esc_html( $task['assignee_name'] );
}
if ( $is_completed && ! empty( $task['completed_by_name'] ) ) {
	$detail_rows[ __( 'Completed by', 'yoohw-customer-intelligence' ) ] = esc_html( $task['completed_by_name'] );
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
	if ( $recipient_user instanceof WP_User ) {
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
<?php if ( $is_previous_notice || $is_completed || $is_due_soon || $is_reopened || 'yoohw_cos_task_reassigned' === $email->id ) : ?>
	<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 0 0 20px;">
		<tr><td style="background: <?php echo esc_attr( $panel_background ); ?>; border-left: 3px solid <?php echo esc_attr( $accent_color ); ?>; padding: 14px 16px; color: <?php echo esc_attr( $text_color ); ?>;">
			<strong><?php echo esc_html( $email_intro ); ?></strong>
			<?php if ( $is_previous_notice ) : ?><br><?php esc_html_e( 'No action is required unless you need to add context for the new owner.', 'yoohw-customer-intelligence' ); ?><?php endif; ?>
		</td></tr>
	</table>
<?php else : ?>
	<p><?php echo esc_html( $email_intro ); ?></p>
<?php endif; ?>
<?php echo $email_improvements_enabled ? '</div>' : ''; ?>

<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 0 0 22px;">
	<tr><td style="background: <?php echo esc_attr( $body_background ); ?>; border: 1px solid <?php echo esc_attr( $border_color ); ?>; border-radius: 8px; padding: 20px;">
		<p style="color: <?php echo esc_attr( $accent_color ); ?>; font-size: 12px; font-weight: 700; line-height: 18px; margin: 0 0 10px; text-transform: uppercase;">
			<?php echo esc_html( $badge_label ); ?><?php if ( $task_id > 0 ) : ?><?php echo esc_html( ' · #' . $task_id ); ?><?php endif; ?>
		</p>
		<h2 style="color: <?php echo esc_attr( $text_color ); ?>; font-size: 21px; line-height: 28px; margin: 0 0 12px; overflow-wrap: anywhere;">
			<?php if ( '' !== $task_url && ! $is_handoff ) : ?><a href="<?php echo esc_url( $task_url ); ?>" style="color: <?php echo esc_attr( $text_color ); ?>;"><?php echo esc_html( $task_title ); ?></a><?php else : ?><?php echo esc_html( $task_title ); ?><?php endif; ?>
		</h2>
		<?php if ( $is_due_soon ) : ?>
			<p style="color: <?php echo esc_attr( $text_color ); ?>; font-size: 17px; font-weight: 700; line-height: 24px; margin: 0 0 12px;"><?php echo esc_html( wp_strip_all_tags( $due_date ) ); ?></p>
		<?php endif; ?>
		<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="border-top: 1px solid <?php echo esc_attr( $border_color ); ?>;">
			<?php foreach ( $detail_rows as $label => $value ) : ?>
				<tr>
					<td valign="top" style="color: <?php echo esc_attr( $secondary_text_color ); ?>; font-size: 13px; line-height: 19px; padding: 10px 12px 0 0; width: 35%;"><?php echo esc_html( $label ); ?></td>
					<td valign="top" style="color: <?php echo esc_attr( $text_color ); ?>; font-size: 13px; line-height: 19px; padding: 10px 0 0; overflow-wrap: anywhere;"><strong><?php echo wp_kses_post( $value ); ?></strong></td>
				</tr>
			<?php endforeach; ?>
		</table>
	</td></tr>
</table>

<?php if ( ! empty( $task['description'] ) ) : ?>
	<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 0 0 22px;">
		<tr><td style="border-left: 3px solid <?php echo esc_attr( $accent_color ); ?>; color: <?php echo esc_attr( $text_color ); ?>; padding: 2px 0 2px 14px; overflow-wrap: anywhere;">
			<strong><?php echo $is_reopened ? esc_html__( 'Latest note', 'yoohw-customer-intelligence' ) : esc_html__( 'Internal note', 'yoohw-customer-intelligence' ); ?></strong><br>
			<?php echo nl2br( esc_html( (string) $task['description'] ) ); ?>
		</td></tr>
	</table>
<?php endif; ?>

<?php if ( '' !== $task_url ) : ?>
	<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin: 0 0 24px;">
		<tr><td>
			<?php if ( $is_handoff ) : ?>
				<a href="<?php echo esc_url( $task_url ); ?>" style="display: inline-block; border: 1px solid <?php echo esc_attr( $border_color ); ?>; border-radius: 5px; color: <?php echo esc_attr( $text_color ); ?>; font-size: 14px; font-weight: 700; padding: 11px 17px; text-decoration: none;"><?php esc_html_e( 'View task', 'yoohw-customer-intelligence' ); ?></a>
			<?php else : ?>
				<a class="button" href="<?php echo esc_url( $task_url ); ?>"><?php esc_html_e( 'Open task', 'yoohw-customer-intelligence' ); ?></a>
			<?php endif; ?>
		</td></tr>
	</table>
<?php endif; ?>

<?php
if ( $additional_content ) {
	echo $email_improvements_enabled ? '<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation"><tr><td class="email-additional-content">' : '';
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
	echo $email_improvements_enabled ? '</td></tr></table>' : '';
}
do_action( 'woocommerce_email_footer', $email );
