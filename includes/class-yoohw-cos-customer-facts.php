<?php
defined( 'ABSPATH' ) || exit;

	/** Read-only scalar snapshot from a canonical, already-loaded customer row. */
final class YoOhw_COS_Customer_Facts {
	public static function snapshot( array $customer, array $context = array() ): array {
		$core = self::core_snapshot( $customer, $context );
		return $core + YoOhw_COS_Extensions::fact_values( $core );
	}

	/** The fixed, PII-minimized map supplied to external providers. */
	public static function core_snapshot( array $customer, array $context = array() ): array {
		$policy = $customer['_commerce_context'] ?? YoOhw_COS_Commerce_Metrics_Policy::read_context();
		// Canonical query rows have already been evaluated against this page snapshot.
		if ( ! isset( $customer['_commerce_context'] ) ) {
			$customer = YoOhw_COS_Intelligence::safe_customer_decisions( $customer, $policy['generation'] );
		}
		$availability = YoOhw_COS_Commerce_Metrics_Policy::availability( $customer, $policy );
		$orders = absint( $customer['total_orders'] ?? 0 );
		$money_ready = 'comparable' === $availability['reason'];
		$money_state = $money_ready ? 'comparable' : ( 'none' === $availability['reason'] ? 'none' : 'unavailable' );
		$currency = $money_ready ? (string) $customer['money_currency'] : null;
		$facts = array(
			'core/recognized_order_count' => $orders,
			'core/first_order_date' => $orders ? ( $customer['first_order_date'] ?? null ) : null,
			'core/last_order_date' => $orders ? ( $customer['last_order_date'] ?? null ) : null,
			'core/rfm_recency_days' => YoOhw_COS_RFM::recency_days( $customer ),
			'core/rfm_frequency' => $orders,
			'core/rfm_monetary' => $money_ready ? (float) $customer['total_spent'] : null,
			'core/money_state' => $money_state,
			'core/money_currency' => $currency,
			'core/recorded_money_state' => $availability['customer_state'],
			'core/recorded_money_currency' => $availability['currency'],
			'core/money_reason' => $availability['reason'],
			'core/money_site_state' => $availability['site_state'],
			'core/money_amount_available' => $availability['amount_available'],
			'core/available_total_spent' => $availability['amount_available'] ? ( 'none' === $availability['reason'] ? 0.0 : (float) $customer['total_spent'] ) : null,
			'core/money_threshold_eligible' => $availability['matches_store_currency'],
			'core/monetary_intelligence_fresh' => $availability['intelligence_fresh'],
			'core/total_spent' => $money_ready ? (float) $customer['total_spent'] : null,
			'core/average_order_value' => $money_ready ? (float) $customer['average_order_value'] : null,
			'core/status' => (string) ( $customer['customer_status'] ?? '' ),
			'core/lifecycle_stage' => (string) ( $customer['lifecycle_stage'] ?? '' ),
			'core/value_tier' => (string) ( $customer['vip_status'] ?? '' ),
			'core/risk_score' => (float) ( $customer['risk_score'] ?? 0 ),
			'core/trust_score' => (float) ( $customer['trust_score'] ?? 0 ),
			'core/email_present' => is_email( sanitize_email( (string) ( $customer['email'] ?? '' ) ) ) ? true : false,
			'core/phone_present' => '' !== trim( (string) ( $customer['phone'] ?? '' ) ),
		);
		foreach ( array( 'open_tasks' => 'core/open_follow_up_count', 'overdue_tasks' => 'core/overdue_follow_up_count' ) as $input => $key ) {
			if ( isset( $context[ $input ] ) && is_numeric( $context[ $input ] ) ) {
				$facts[ $key ] = max( 0, (int) $context[ $input ] );
			}
		}
		return $facts;
	}
}
