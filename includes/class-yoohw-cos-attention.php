<?php
defined( 'ABSPATH' ) || exit;

/** Shared deterministic Profile attention decision. */
final class YoOhw_COS_Attention {
	public static function reasons( array $customer, array $context = array() ): array {
		$customer = YoOhw_COS_Intelligence::safe_customer_decisions( $customer );
		$status = sanitize_key( (string) ( $customer['customer_status'] ?? '' ) );
		$high_value = ! in_array( sanitize_key( (string) ( $customer['vip_status'] ?? 'none' ) ), array( '', 'none' ), true );
		$email = sanitize_email( (string) ( $customer['email'] ?? '' ) );
		$phone = trim( (string) ( $customer['phone'] ?? '' ) );
		$open = max( 0, (int) ( $context['open_tasks'] ?? 0 ) );
		$overdue = max( 0, (int) ( $context['overdue_tasks'] ?? 0 ) );
		$action_url = '#yoohw_cos_profile_task_title';
		$action_label = __( 'Add task', 'yoohw-customer-intelligence' );
		if ( $overdue > 0 ) {
			$id = 'core/overdue_follow_up';
			$message = sprintf( _n( '%s open follow-up task is overdue.', '%s open follow-up tasks are overdue.', $overdue, 'yoohw-customer-intelligence' ), number_format_i18n( $overdue ) );
			$action_url = '#yoohw-cos-add-task';
			$action_label = __( 'Review open tasks', 'yoohw-customer-intelligence' );
		} elseif ( $high_value && in_array( $status, array( 'at_risk', 'inactive' ), true ) ) {
			$id = 'core/high_value_at_risk';
			$message = __( 'This high-value customer is at risk or inactive.', 'yoohw-customer-intelligence' );
		} elseif ( in_array( $status, array( 'at_risk', 'inactive' ), true ) ) {
			$id = 'core/at_risk';
			$message = __( 'This customer is at risk or inactive.', 'yoohw-customer-intelligence' );
		} elseif ( ! is_email( $email ) || '' === $phone ) {
			$id = 'core/missing_contact';
			$message = __( 'Contact details are incomplete. Check the customer identity before following up.', 'yoohw-customer-intelligence' );
			$action_url = '#yoohw-cos-profile-identity';
			$action_label = __( 'Review contact details', 'yoohw-customer-intelligence' );
		} elseif ( $high_value && 0 === $open ) {
			$id = 'core/high_value_no_follow_up';
			$message = __( 'This high-value customer has no open follow-up task.', 'yoohw-customer-intelligence' );
		} else {
			$id = 'core/none';
			$message = __( 'No current attention reason is recorded for this customer.', 'yoohw-customer-intelligence' );
			$action_url = '';
			$action_label = '';
		}
		$core = array( 'id' => $id, 'priority' => 0, 'message' => $message, 'severity' => 'core/none' === $id ? 'info' : 'warning', 'action_url' => $action_url, 'action_label' => $action_label );
		return array_merge( array( $core ), YoOhw_COS_Extensions::attention_reasons( $customer, $context ) );
	}
}
