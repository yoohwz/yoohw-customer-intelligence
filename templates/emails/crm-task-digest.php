<?php
defined( 'ABSPATH' ) || exit;

$email_improvements_enabled = class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) && \Automattic\WooCommerce\Utilities\FeaturesUtil::feature_is_enabled( 'email_improvements' );
$panel_background           = $email_palette_panel_background_color ?? '#f6f7f7';
$border_color               = $email_palette_border_color ?? '#dcdcde';
$text_color                 = $email_palette_text_color ?? '#1d2327';
$secondary_text_color       = $email_palette_secondary_text_color ?? '#646970';
$accent_color               = $email_palette_accent_color ?? $text_color;
$table_class                = $email_improvements_enabled ? 'email-order-details' : '';
$table_border               = $email_improvements_enabled ? '0' : '1';
$table_cellpadding          = $email_improvements_enabled ? '0' : '6';
$visible_sections           = array_filter(
	$sections,
	static function( array $section ): bool {
		return ! empty( $section['tasks'] );
	}
);

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<?php echo $email_improvements_enabled ? '<div class="email-introduction">' : ''; ?>
<?php if ( ! empty( $recipient_user ) && $recipient_user instanceof WP_User ) : ?>
	<p>
		<?php
		printf(
			/* translators: %s: recipient display name. */
			esc_html__( 'Hi %s,', 'yoohw-customer-intelligence' ),
			esc_html( $recipient_user->display_name )
		);
		?>
	</p>
<?php endif; ?>

<?php if ( '' !== $intro ) : ?>
	<p><?php echo esc_html( $intro ); ?></p>
<?php endif; ?>
<?php echo $email_improvements_enabled ? '</div>' : ''; ?>

<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 0 0 24px;">
	<tr>
		<td style="background: <?php echo esc_attr( $panel_background ); ?>; border: 1px solid <?php echo esc_attr( $border_color ); ?>; border-radius: 8px; padding: 22px;">
			<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
				<tr>
					<td valign="top" style="padding: 0;">
						<p style="color: <?php echo esc_attr( $secondary_text_color ); ?>; font-size: 13px; line-height: 18px; margin: 0 0 6px; text-transform: uppercase;">
							<?php esc_html_e( 'CRM follow-up queue', 'yoohw-customer-intelligence' ); ?>
						</p>
						<p style="color: <?php echo esc_attr( $accent_color ); ?>; font-size: 32px; font-weight: 700; line-height: 38px; margin: 0;">
							<?php echo esc_html( number_format_i18n( absint( $task_count ) ) ); ?>
						</p>
					</td>
					<td align="right" valign="middle" style="color: <?php echo esc_attr( $secondary_text_color ); ?>; font-size: 14px; line-height: 20px; padding: 0 0 0 16px;">
						<?php esc_html_e( 'Open tasks in this email', 'yoohw-customer-intelligence' ); ?>
					</td>
				</tr>
			</table>
		</td>
	</tr>
</table>

<?php foreach ( $visible_sections as $section ) : ?>
	<h2 class="<?php echo $email_improvements_enabled ? 'email-order-detail-heading' : ''; ?>">
		<?php echo esc_html( $section['title'] ); ?>
		<?php if ( $email_improvements_enabled ) : ?>
			<span style="color: <?php echo esc_attr( $secondary_text_color ); ?>; font-size: 14px; font-weight: 400;">
				<?php echo esc_html( '(' . number_format_i18n( count( $section['tasks'] ) ) . ')' ); ?>
			</span>
		<?php endif; ?>
	</h2>

	<table class="<?php echo esc_attr( $table_class ); ?>" cellspacing="0" cellpadding="<?php echo esc_attr( $table_cellpadding ); ?>" border="<?php echo esc_attr( $table_border ); ?>" width="100%" style="border: 1px solid <?php echo esc_attr( $border_color ); ?>; margin: 0 0 24px;" bordercolor="<?php echo esc_attr( $border_color ); ?>">
		<thead>
			<tr>
				<th scope="col" style="color: <?php echo esc_attr( $secondary_text_color ); ?>; font-size: 13px; line-height: 18px; padding: <?php echo $email_improvements_enabled ? '12px 16px' : '6px'; ?>; text-align:left;"><?php esc_html_e( 'Task', 'yoohw-customer-intelligence' ); ?></th>
				<th scope="col" style="color: <?php echo esc_attr( $secondary_text_color ); ?>; font-size: 13px; line-height: 18px; padding: <?php echo $email_improvements_enabled ? '12px 16px' : '6px'; ?>; text-align:left;"><?php esc_html_e( 'Customer', 'yoohw-customer-intelligence' ); ?></th>
				<th scope="col" style="color: <?php echo esc_attr( $secondary_text_color ); ?>; font-size: 13px; line-height: 18px; padding: <?php echo $email_improvements_enabled ? '12px 16px' : '6px'; ?>; text-align:left;"><?php esc_html_e( 'Due', 'yoohw-customer-intelligence' ); ?></th>
				<th scope="col" style="color: <?php echo esc_attr( $secondary_text_color ); ?>; font-size: 13px; line-height: 18px; padding: <?php echo $email_improvements_enabled ? '12px 16px' : '6px'; ?>; text-align:left;"><?php esc_html_e( 'Priority', 'yoohw-customer-intelligence' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $section['tasks'] as $task ) : ?>
				<?php $summary = $email->get_template_task_summary( $task ); ?>
				<tr>
					<td style="color: <?php echo esc_attr( $text_color ); ?>; font-size: 14px; line-height: 20px; padding: <?php echo $email_improvements_enabled ? '14px 16px' : '6px'; ?>; vertical-align: top;">
						<a href="<?php echo esc_url( $summary['task_url'] ); ?>" style="color: <?php echo esc_attr( $text_color ); ?>; font-weight: 600; text-decoration: none;"><?php echo esc_html( $summary['title'] ); ?></a>
					</td>
					<td style="color: <?php echo esc_attr( $text_color ); ?>; font-size: 14px; line-height: 20px; padding: <?php echo $email_improvements_enabled ? '14px 16px' : '6px'; ?>; vertical-align: top;">
						<?php if ( '' !== $summary['customer_url'] ) : ?>
							<a href="<?php echo esc_url( $summary['customer_url'] ); ?>"><?php echo esc_html( $summary['customer_name'] ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $summary['customer_name'] ); ?>
						<?php endif; ?>
					</td>
					<td style="color: <?php echo esc_attr( $text_color ); ?>; font-size: 14px; line-height: 20px; padding: <?php echo $email_improvements_enabled ? '14px 16px' : '6px'; ?>; vertical-align: top;"><?php echo wp_kses_post( $summary['due_date'] ); ?></td>
					<td style="color: <?php echo esc_attr( $text_color ); ?>; font-size: 14px; line-height: 20px; padding: <?php echo $email_improvements_enabled ? '14px 16px' : '6px'; ?>; vertical-align: top;"><?php echo esc_html( $summary['priority_label'] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endforeach; ?>

<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin: 0 0 24px;">
	<tr>
		<td>
			<a class="button" href="<?php echo esc_url( $task_list_url ); ?>"><?php esc_html_e( 'Open task list', 'yoohw-customer-intelligence' ); ?></a>
		</td>
	</tr>
</table>

<?php
if ( $additional_content ) {
	echo $email_improvements_enabled ? '<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation"><tr><td class="email-additional-content">' : '';
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
	echo $email_improvements_enabled ? '</td></tr></table>' : '';
}

do_action( 'woocommerce_email_footer', $email );
