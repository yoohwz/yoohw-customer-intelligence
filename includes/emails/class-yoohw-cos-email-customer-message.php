<?php
defined( 'ABSPATH' ) || exit;

final class YoOhw_COS_Email_Customer_Message extends YoOhw_COS_Email_CRM_Base {

	private $customer = array();
	private $message_body = '';

	public function __construct() {
		$this->id              = 'yoohw_cos_customer_message';
		$this->title           = __( 'Customer message', 'yoohw-customer-intelligence' );
		$this->description     = __( 'Used when a store manager sends a message directly from a customer profile.', 'yoohw-customer-intelligence' );
		$this->recipient_label = __( 'Selected customer', 'yoohw-customer-intelligence' );
		$this->template_html   = 'emails/customer-message.php';
		$this->template_plain  = 'emails/plain/customer-message.php';

		parent::__construct();

		$this->customer_email = true;
		$this->manual         = true;
	}

	public function get_default_subject() {
		return __( '[{site_title}] A message for {customer_name}', 'yoohw-customer-intelligence' );
	}

	public function get_default_heading() {
		return __( 'A message from {site_title}', 'yoohw-customer-intelligence' );
	}

	public function get_composer_subject( array $customer ): string {
		$this->prepare_customer( $customer );

		return sanitize_text_field( wp_strip_all_tags( (string) $this->get_subject() ) );
	}

	public function trigger( array $customer, string $subject, string $message ): bool {
		$this->prepare_customer( $customer );
		$this->recipient    = sanitize_email( (string) ( $customer['email'] ?? '' ) );
		$this->message_body = sanitize_textarea_field( $message );
		$subject            = sanitize_text_field( $subject );

		if ( ! is_email( $this->recipient ) || '' === trim( $subject ) || '' === trim( $this->message_body ) ) {
			return false;
		}

		return $this->send(
			$this->recipient,
			$subject,
			$this->get_content(),
			$this->get_headers(),
			$this->get_attachments()
		);
	}

	public function get_content_html() {
		return wc_get_template_html(
			$this->template_html,
			$this->get_customer_message_template_args( false ),
			'',
			$this->template_base
		);
	}

	public function get_content_plain() {
		return wc_get_template_html(
			$this->template_plain,
			$this->get_customer_message_template_args( true ),
			'',
			$this->template_base
		);
	}

	public function get_block_editor_email_template_content() {
		return wc_get_template_html(
			'emails/block/customer-message.php',
			$this->get_customer_message_template_args( false ),
			'',
			$this->template_base
		);
	}

	private function prepare_customer( array $customer ): void {
		$this->customer = $customer;
		$this->object   = (object) $customer;

		$name = sanitize_text_field( (string) ( $customer['display_name'] ?? '' ) );

		if ( '' === $name ) {
			$name = trim(
				sanitize_text_field( (string) ( $customer['first_name'] ?? '' ) ) . ' ' .
				sanitize_text_field( (string) ( $customer['last_name'] ?? '' ) )
			);
		}

		if ( '' === $name ) {
			$name = sanitize_email( (string) ( $customer['email'] ?? '' ) );
		}

		$this->placeholders['{customer_name}'] = $name;
	}

	private function get_customer_message_template_args( bool $plain_text ): array {
		return array(
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => false,
			'plain_text'         => $plain_text,
			'email'              => $this,
			'customer'           => $this->customer,
			'customer_name'      => sanitize_text_field( (string) ( $this->placeholders['{customer_name}'] ?? '' ) ),
			'message_body'       => $this->message_body,
		);
	}
}
