<?php
defined( 'ABSPATH' ) || exit;

$email_improvements_enabled = class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) && \Automattic\WooCommerce\Utilities\FeaturesUtil::feature_is_enabled( 'email_improvements' );
$panel_background           = $email_palette_panel_background_color ?? '#f6f7f7';
$border_color               = $email_palette_border_color ?? '#dcdcde';
$text_color                 = $email_palette_text_color ?? '#1d2327';
$secondary_text_color       = $email_palette_secondary_text_color ?? '#646970';
$accent_color               = $email_palette_accent_color ?? $text_color;
$is_escalation              = 'yoohw_cos_task_overdue_escalation' === $email->id;
$is_daily                   = 'yoohw_cos_daily_followup_summary' === $email->id;
$count_label                = $is_escalation ? __( 'Escalated overdue tasks', 'yoohw-customer-intelligence' ) : ( $is_daily ? __( 'Today\'s queue', 'yoohw-customer-intelligence' ) : __( 'Overdue queue', 'yoohw-customer-intelligence' ) );
$visible_sections           = array_filter(
	$sections,
	static function( array $section ): bool {
		return ! empty( $section['tasks'] );
	}
);

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<?php echo $email_improvements_enabled ? '<div class="email-introduction">' : ''; ?>
<?php if ( $recipient_user instanceof WP_User ) : ?>
	<p><?php printf( /* translators: %s: recipient display name. */ esc_html__( 'Hi %s,', 'yoohw-customer-intelligence' ), esc_html( $recipient_user->display_name ) ); ?></p>
<?php endif; ?>
<?php if ( '' !== $intro ) : ?>
	<?php if ( $is_escalation ) : ?>
		<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 0 0 20px;"><tr><td style="background: <?php echo esc_attr( $panel_background ); ?>; border-left: 3px solid <?php echo esc_attr( $accent_color ); ?>; padding: 14px 16px;"><strong><?php esc_html_e( 'Escalation required.', 'yoohw-customer-intelligence' ); ?></strong><br><?php echo esc_html( $intro ); ?></td></tr></table>
	<?php else : ?>
		<p><?php echo esc_html( $intro ); ?></p>
	<?php endif; ?>
<?php endif; ?>
<?php echo $email_improvements_enabled ? '</div>' : ''; ?>

<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 0 0 22px;">
	<tr><td style="background: <?php echo esc_attr( $panel_background ); ?>; border: 1px solid <?php echo esc_attr( $border_color ); ?>; border-radius: 8px; padding: 18px 20px;">
		<p style="color: <?php echo esc_attr( $secondary_text_color ); ?>; font-size: 12px; font-weight: 700; line-height: 18px; margin: 0 0 5px; text-transform: uppercase;"><?php echo esc_html( $count_label ); ?></p>
		<p style="color: <?php echo esc_attr( $accent_color ); ?>; font-size: 30px; font-weight: 700; line-height: 36px; margin: 0;"><?php echo esc_html( number_format_i18n( absint( $task_count ) ) ); ?></p>
	</td></tr>
</table>

<?php foreach ( $visible_sections as $section ) : ?>
	<h2 class="<?php echo $email_improvements_enabled ? 'email-order-detail-heading' : ''; ?>" style="font-size: 18px; line-height: 25px; margin: 24px 0 10px;">
		<?php echo esc_html( $section['title'] ); ?>
		<span style="color: <?php echo esc_attr( $secondary_text_color ); ?>; font-size: 14px; font-weight: 400;"><?php echo esc_html( '(' . number_format_i18n( count( $section['tasks'] ) ) . ')' ); ?></span>
	</h2>
	<?php foreach ( $section['tasks'] as $task ) : ?>
		<?php $summary = $email->get_template_task_summary( $task ); ?>
		<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="border: 1px solid <?php echo esc_attr( $border_color ); ?>; margin: 0 0 10px;">
			<tr><td style="color: <?php echo esc_attr( $text_color ); ?>; font-size: 14px; line-height: 21px; padding: 12px 14px; overflow-wrap: anywhere;">
				<strong><?php if ( '' !== $summary['task_url'] ) : ?><a href="<?php echo esc_url( $summary['task_url'] ); ?>" style="color: <?php echo esc_attr( $text_color ); ?>;"><?php echo esc_html( $summary['title'] ); ?></a><?php else : ?><?php echo esc_html( $summary['title'] ); ?><?php endif; ?></strong>
				<br><span style="color: <?php echo esc_attr( $secondary_text_color ); ?>; font-size: 12px;"><?php esc_html_e( 'Customer', 'yoohw-customer-intelligence' ); ?> · <?php esc_html_e( 'Due', 'yoohw-customer-intelligence' ); ?> · <?php esc_html_e( 'Priority', 'yoohw-customer-intelligence' ); ?></span>
				<br><?php if ( '' !== $summary['customer_url'] ) : ?><a href="<?php echo esc_url( $summary['customer_url'] ); ?>"><?php echo esc_html( $summary['customer_name'] ); ?></a><?php else : ?><?php echo esc_html( $summary['customer_name'] ); ?><?php endif; ?> · <?php echo esc_html( wp_strip_all_tags( $summary['due_date'] ) ); ?> · <?php echo esc_html( $summary['priority_label'] ); ?>
			</td></tr>
		</table>
	<?php endforeach; ?>
<?php endforeach; ?>

<?php if ( $is_escalation ) : ?>
	<p style="color: <?php echo esc_attr( $secondary_text_color ); ?>; font-size: 13px; line-height: 20px;"><?php esc_html_e( 'This email may also be sent to configured escalation recipients.', 'yoohw-customer-intelligence' ); ?></p>
<?php endif; ?>
<?php if ( '' !== $task_list_url ) : ?>
	<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin: 18px 0 24px;"><tr><td><a class="button" href="<?php echo esc_url( $task_list_url ); ?>"><?php echo $is_escalation ? esc_html__( 'Review overdue tasks', 'yoohw-customer-intelligence' ) : esc_html__( 'Open task list', 'yoohw-customer-intelligence' ); ?></a></td></tr></table>
<?php endif; ?>

<?php
if ( $additional_content ) {
	echo $email_improvements_enabled ? '<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation"><tr><td class="email-additional-content">' : '';
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
	echo $email_improvements_enabled ? '</td></tr></table>' : '';
}
do_action( 'woocommerce_email_footer', $email );
