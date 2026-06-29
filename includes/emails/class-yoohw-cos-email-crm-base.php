<?php
defined( 'ABSPATH' ) || exit;

abstract class YoOhw_COS_Email_CRM_Base extends WC_Email {

	protected $default_enabled = 'yes';
	protected $recipient_label = '';
	protected $context = array();

	public $task = array();
	public $tasks = array();
	public $recipient_user = false;

	public function __construct() {
		$this->customer_email = false;
		$this->email_group    = 'crm';
		$this->template_base  = YOOHW_COS_PATH . 'templates/';

		$this->placeholders = array(
			'{task_title}'    => '',
			'{customer_name}' => '',
			'{due_date}'      => '',
			'{task_count}'    => '0',
		);

		parent::__construct();
	}

	public function init_form_fields() {
		parent::init_form_fields();

		if ( isset( $this->form_fields['enabled'] ) ) {
			$this->form_fields['enabled']['default'] = $this->default_enabled;
		}
	}

	public function admin_options() {
		ob_start();
		parent::admin_options();
		$output = ob_get_clean();

		$default_back_url = esc_url( admin_url( 'admin.php?page=wc-settings&tab=email' ) );
		$crm_back_url     = esc_url( admin_url( 'admin.php?page=wc-settings&tab=email&email_group=crm' ) );

		echo str_replace( $default_back_url, $crm_back_url, $output ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public function get_recipient_label(): string {
		return $this->recipient_label;
	}

	public function get_default_additional_content() {
		return '';
	}

	public function get_template_task_summary( array $task ): array {
		return array(
			'id'             => absint( $task['id'] ?? 0 ),
			'title'          => sanitize_text_field( (string) ( $task['title'] ?? '' ) ),
			'task_url'       => $this->get_task_url( $task ),
			'customer_name'  => $this->get_customer_name( $task ),
			'customer_url'   => $this->get_customer_url( $task ),
			'order_url'      => $this->get_order_url( $task ),
			'due_date'       => $this->format_task_due_date( $task ),
			'priority_label' => $this->get_task_priority_label( $task ),
			'status_label'   => $this->get_task_status_label( $task ),
		);
	}

	protected function get_woocommerce_email_palette_args(): array {
		$defaults = array(
			'base'        => '#720eec',
			'bg'          => '#f7f7f7',
			'body_bg'     => '#ffffff',
			'body_text'   => '#3c3c3c',
			'footer_text' => '#3c3c3c',
		);

		if ( class_exists( '\Automattic\WooCommerce\Internal\Email\EmailColors' ) ) {
			$defaults = \Automattic\WooCommerce\Internal\Email\EmailColors::get_default_colors();
		}

		$accent_color     = $this->get_hex_color( get_option( 'woocommerce_email_base_color', $defaults['base'] ), $defaults['base'] );
		$email_background = $this->get_hex_color( get_option( 'woocommerce_email_background_color', $defaults['bg'] ), $defaults['bg'] );
		$content_bg       = $this->get_hex_color( get_option( 'woocommerce_email_body_background_color', $defaults['body_bg'] ), $defaults['body_bg'] );
		$text_color       = $this->get_hex_color( get_option( 'woocommerce_email_text_color', $defaults['body_text'] ), $defaults['body_text'] );
		$secondary_color  = $this->get_hex_color( get_option( 'woocommerce_email_footer_text_color', $defaults['footer_text'] ), $defaults['footer_text'] );
		$panel_background = $email_background !== $content_bg ? $email_background : $this->mix_hex_color( $accent_color, $content_bg, 6 );

		return array(
			'email_palette_accent_color'           => $accent_color,
			'email_palette_background_color'       => $email_background,
			'email_palette_body_background_color'  => $content_bg,
			'email_palette_text_color'             => $text_color,
			'email_palette_secondary_text_color'   => $secondary_color,
			'email_palette_panel_background_color' => $panel_background,
			'email_palette_border_color'           => $this->mix_hex_color( $accent_color, $content_bg, 22 ),
		);
	}

	protected function prepare_recipient_user( int $user_id ): bool {
		$this->recipient_user = get_userdata( absint( $user_id ) );

		if ( ! $this->recipient_user instanceof WP_User || ! is_email( $this->recipient_user->user_email ) ) {
			$this->recipient = '';
			return false;
		}

		$this->recipient = $this->recipient_user->user_email;

		return true;
	}

	protected function set_task_placeholders( array $task ): void {
		$this->placeholders['{task_title}']    = (string) ( $task['title'] ?? '' );
		$this->placeholders['{customer_name}'] = $this->get_customer_name( $task );
		$this->placeholders['{due_date}']      = wp_strip_all_tags( $this->format_task_due_date( $task ) );
		$this->placeholders['{task_count}']    = '1';
	}

	protected function set_task_count_placeholder( int $count ): void {
		$this->placeholders['{task_count}'] = (string) max( 0, $count );
	}

	protected function get_task_template_args( bool $plain_text ): array {
		return array_merge(
			array(
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'      => true,
				'plain_text'         => $plain_text,
				'email'              => $this,
				'task'               => $this->task,
				'task_url'           => $this->get_task_url( $this->task ),
				'customer_name'      => $this->get_customer_name( $this->task ),
				'customer_url'       => $this->get_customer_url( $this->task ),
				'order_url'          => $this->get_order_url( $this->task ),
				'due_date'           => $this->format_task_due_date( $this->task ),
				'priority_label'     => $this->get_task_priority_label( $this->task ),
				'status_label'       => $this->get_task_status_label( $this->task ),
				'recipient_user'     => $this->recipient_user,
				'context'            => $this->context,
				'email_intro'        => method_exists( $this, 'get_intro_text' ) ? $this->get_intro_text() : '',
				'email_badge'        => method_exists( $this, 'get_badge_label' ) ? $this->get_badge_label() : __( 'CRM follow-up', 'yoohw-customer-intelligence' ),
			),
			$this->get_woocommerce_email_palette_args()
		);
	}

	protected function get_digest_template_args( bool $plain_text, array $sections, string $intro ): array {
		return array_merge(
			array(
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'      => true,
				'plain_text'         => $plain_text,
				'email'              => $this,
				'tasks'              => $this->tasks,
				'sections'           => $sections,
				'intro'              => $intro,
				'task_count'         => count( $this->tasks ),
				'task_list_url'      => admin_url( 'admin.php?page=yoohw-customer-intelligence-tasks' ),
				'recipient_user'     => $this->recipient_user,
				'context'            => $this->context,
			),
			$this->get_woocommerce_email_palette_args()
		);
	}

	protected function get_customer_name( array $task ): string {
		$name  = sanitize_text_field( (string) ( $task['customer_name'] ?? '' ) );
		$email = sanitize_email( (string) ( $task['customer_email'] ?? '' ) );

		if ( '' !== $name ) {
			return $name;
		}

		return '' !== $email ? $email : __( '(No customer name)', 'yoohw-customer-intelligence' );
	}

	protected function format_task_due_date( array $task ): string {
		return YoOhw_COS_DB::format_admin_date(
			$task['due_date'] ?? '',
			__( 'No due date', 'yoohw-customer-intelligence' )
		);
	}

	protected function get_task_priority_label( array $task ): string {
		$priority = YoOhw_COS_Tasks::normalize_priority( (string) ( $task['priority'] ?? 'normal' ) );

		return YoOhw_COS_Tasks::get_priorities()[ $priority ] ?? __( 'Normal', 'yoohw-customer-intelligence' );
	}

	protected function get_task_status_label( array $task ): string {
		$status = YoOhw_COS_Tasks::normalize_status( (string) ( $task['status'] ?? YoOhw_COS_Tasks::STATUS_OPEN ) );

		return YoOhw_COS_Tasks::get_statuses()[ $status ] ?? __( 'Open', 'yoohw-customer-intelligence' );
	}

	protected function get_task_url( array $task ): string {
		$task_id = absint( $task['id'] ?? 0 );

		return add_query_arg(
			array(
				'page'    => 'yoohw-customer-intelligence-tasks',
				'task_id' => $task_id,
			),
			admin_url( 'admin.php' )
		);
	}

	protected function get_customer_url( array $task ): string {
		$customer_id = absint( $task['customer_id'] ?? 0 );

		if ( $customer_id <= 0 ) {
			return '';
		}

		return add_query_arg(
			array(
				'page'        => 'yoohw-customer-intelligence',
				'customer_id' => $customer_id,
			),
			admin_url( 'admin.php' )
		);
	}

	protected function get_order_url( array $task ): string {
		$order_id = absint( $task['order_id'] ?? 0 );

		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return '';
		}

		$order = wc_get_order( $order_id );

		if ( $order && method_exists( $order, 'get_edit_order_url' ) ) {
			return $order->get_edit_order_url();
		}

		return admin_url( 'post.php?post=' . $order_id . '&action=edit' );
	}

	protected function get_recipient_first_name(): string {
		if ( ! $this->recipient_user instanceof WP_User ) {
			return '';
		}

		$first_name = trim( (string) $this->recipient_user->first_name );

		return '' !== $first_name ? $first_name : (string) $this->recipient_user->display_name;
	}

	private function get_hex_color( $color, string $fallback ): string {
		$color = is_string( $color ) ? trim( $color ) : '';
		$color = function_exists( 'sanitize_hex_color' ) ? sanitize_hex_color( $color ) : $color;
		$color = is_string( $color ) ? $color : '';

		return preg_match( '/^#(?:[0-9a-fA-F]{3}){1,2}$/', $color ) ? strtolower( $color ) : $fallback;
	}

	private function mix_hex_color( string $foreground, string $background, float $percentage ): string {
		$foreground = $this->normalize_hex_color( $foreground );
		$background = $this->normalize_hex_color( $background );
		$percentage = max( 0, min( 100, $percentage ) ) / 100;

		if ( '' === $foreground || '' === $background ) {
			return '' !== $background ? '#' . $background : '#ffffff';
		}

		$mixed = array();

		foreach ( array( 0, 2, 4 ) as $offset ) {
			$foreground_value = hexdec( substr( $foreground, $offset, 2 ) );
			$background_value = hexdec( substr( $background, $offset, 2 ) );
			$mixed[]          = str_pad( dechex( (int) round( ( $foreground_value * $percentage ) + ( $background_value * ( 1 - $percentage ) ) ) ), 2, '0', STR_PAD_LEFT );
		}

		return '#' . implode( '', $mixed );
	}

	private function normalize_hex_color( string $color ): string {
		$color = $this->get_hex_color( $color, '' );

		if ( '' === $color ) {
			return '';
		}

		$color = ltrim( $color, '#' );

		if ( 3 === strlen( $color ) ) {
			$color = $color[0] . $color[0] . $color[1] . $color[1] . $color[2] . $color[2];
		}

		return $color;
	}
}
