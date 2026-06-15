<?php
defined( 'ABSPATH' ) || exit;

final class YoOhw_COS_Intelligence {

	public static function init(): void {
		// Reserved for future hooks.
	}

	public static function calculate_customer_status( array $customer ): string {
		$total_orders = absint( $customer['total_orders'] ?? 0 );
		$total_spent  = (float) ( $customer['total_spent'] ?? 0 );
		$last_active  = $customer['last_activity_date'] ?? '';

		if ( $total_orders <= 1 ) {
			return 'new';
		}

		if ( $total_spent >= 1000 || $total_orders >= 10 ) {
			return 'vip';
		}

		if ( self::days_since( $last_active ) >= 90 ) {
			return 'inactive';
		}

		if ( self::days_since( $last_active ) >= 45 ) {
			return 'at_risk';
		}

		return 'active';
	}

	public static function calculate_vip_status( array $customer ): string {
		$total_orders = absint( $customer['total_orders'] ?? 0 );
		$total_spent  = (float) ( $customer['total_spent'] ?? 0 );

		if ( $total_spent >= 5000 || $total_orders >= 50 ) {
			return 'platinum';
		}

		if ( $total_spent >= 2500 || $total_orders >= 25 ) {
			return 'gold';
		}

		if ( $total_spent >= 1000 || $total_orders >= 10 ) {
			return 'silver';
		}

		return 'none';
	}

	public static function calculate_trust_score( array $customer ): float {
		$total_orders = absint( $customer['total_orders'] ?? 0 );
		$total_spent  = (float) ( $customer['total_spent'] ?? 0 );

		$score = 50.0;

		if ( $total_orders >= 2 ) {
			$score += 10;
		}

		if ( $total_orders >= 5 ) {
			$score += 10;
		}

		if ( $total_orders >= 10 ) {
			$score += 10;
		}

		if ( $total_spent >= 500 ) {
			$score += 10;
		}

		if ( $total_spent >= 1000 ) {
			$score += 10;
		}

		return min( 100, max( 0, $score ) );
	}

	private static function days_since( ?string $date ): int {
		$timestamp = YoOhw_COS_DB::date_timestamp( $date );

		if ( ! $timestamp ) {
			return 9999;
		}

		return (int) floor( ( current_time( 'timestamp' ) - $timestamp ) / DAY_IN_SECONDS );
	}

	public static function calculate_risk_score( array $customer ): float {
		$total_orders = absint( $customer['total_orders'] ?? 0 );
		$total_spent  = (float) ( $customer['total_spent'] ?? 0 );
		$last_active  = $customer['last_activity_date'] ?? '';
		$email        = sanitize_email( $customer['email'] ?? '' );
		$phone        = sanitize_text_field( $customer['phone'] ?? '' );

		$score = 0.0;

		if ( empty( $email ) ) {
			$score += 20;
		}

		if ( empty( $phone ) ) {
			$score += 10;
		}

		if ( $total_orders <= 1 ) {
			$score += 10;
		}

		if ( $total_spent >= 1000 && $total_orders <= 1 ) {
			$score += 20;
		}

		if ( self::days_since( $last_active ) >= 180 ) {
			$score += 10;
		}

		return min( 100, max( 0, $score ) );
	}

	public static function calculate_risk_level( float $risk_score ): string {
		if ( $risk_score >= 70 ) {
			return 'high';
		}

		if ( $risk_score >= 40 ) {
			return 'medium';
		}

		if ( $risk_score >= 15 ) {
			return 'low';
		}

		return 'none';
	}

	public static function get_risk_factors( array $customer ): array {
		$factors = array();

		$total_orders = absint( $customer['total_orders'] ?? 0 );
		$total_spent  = (float) ( $customer['total_spent'] ?? 0 );
		$last_active  = $customer['last_activity_date'] ?? '';
		$email        = sanitize_email( $customer['email'] ?? '' );
		$phone        = sanitize_text_field( $customer['phone'] ?? '' );

		if ( empty( $email ) ) {
			$factors[] = array(
				'label'       => __( 'Missing email address', 'yoohw-customer-intelligence' ),
				'impact'      => 20,
				'description' => __( 'This customer profile does not have an email address.', 'yoohw-customer-intelligence' ),
			);
		}

		if ( empty( $phone ) ) {
			$factors[] = array(
				'label'       => __( 'Missing phone number', 'yoohw-customer-intelligence' ),
				'impact'      => 10,
				'description' => __( 'This customer profile does not have a phone number.', 'yoohw-customer-intelligence' ),
			);
		}

		if ( $total_orders <= 1 ) {
			$factors[] = array(
				'label'       => __( 'New or low-history customer', 'yoohw-customer-intelligence' ),
				'impact'      => 10,
				'description' => __( 'Customers with very limited order history have less trust data available.', 'yoohw-customer-intelligence' ),
			);
		}

		if ( $total_spent >= 1000 && $total_orders <= 1 ) {
			$factors[] = array(
				'label'       => __( 'High value with limited history', 'yoohw-customer-intelligence' ),
				'impact'      => 20,
				'description' => __( 'A high-value purchase from a customer with limited history can require closer review.', 'yoohw-customer-intelligence' ),
			);
		}

		if ( self::days_since( $last_active ) >= 180 ) {
			$factors[] = array(
				'label'       => __( 'Long inactive period', 'yoohw-customer-intelligence' ),
				'impact'      => 10,
				'description' => __( 'This customer has not had recent recorded activity.', 'yoohw-customer-intelligence' ),
			);
		}

		if ( empty( $factors ) ) {
			$factors[] = array(
				'label'       => __( 'No major risk factors detected', 'yoohw-customer-intelligence' ),
				'impact'      => 0,
				'description' => __( 'Based on the current basic rules, this customer does not show obvious risk indicators.', 'yoohw-customer-intelligence' ),
			);
		}

		return $factors;
	}

	public static function get_trust_factors( array $customer ): array {
		$factors = array();

		$total_orders = absint( $customer['total_orders'] ?? 0 );
		$total_spent  = (float) ( $customer['total_spent'] ?? 0 );
		$email        = sanitize_email( $customer['email'] ?? '' );
		$phone        = sanitize_text_field( $customer['phone'] ?? '' );

		if ( ! empty( $email ) ) {
			$factors[] = array(
				'label'       => __( 'Email available', 'yoohw-customer-intelligence' ),
				'impact'      => 0,
				'description' => __( 'This customer profile has an email address.', 'yoohw-customer-intelligence' ),
			);
		}

		if ( ! empty( $phone ) ) {
			$factors[] = array(
				'label'       => __( 'Phone available', 'yoohw-customer-intelligence' ),
				'impact'      => 0,
				'description' => __( 'This customer profile has a phone number.', 'yoohw-customer-intelligence' ),
			);
		}

		if ( $total_orders >= 2 ) {
			$factors[] = array(
				'label'       => __( 'Repeat customer', 'yoohw-customer-intelligence' ),
				'impact'      => 10,
				'description' => __( 'This customer has placed at least 2 orders.', 'yoohw-customer-intelligence' ),
			);
		}

		if ( $total_orders >= 5 ) {
			$factors[] = array(
				'label'       => __( 'Established order history', 'yoohw-customer-intelligence' ),
				'impact'      => 10,
				'description' => __( 'This customer has placed at least 5 orders.', 'yoohw-customer-intelligence' ),
			);
		}

		if ( $total_orders >= 10 ) {
			$factors[] = array(
				'label'       => __( 'Strong order history', 'yoohw-customer-intelligence' ),
				'impact'      => 10,
				'description' => __( 'This customer has placed at least 10 orders.', 'yoohw-customer-intelligence' ),
			);
		}

		if ( $total_spent >= 500 ) {
			$factors[] = array(
				'label'       => __( 'Meaningful purchase value', 'yoohw-customer-intelligence' ),
				'impact'      => 10,
				'description' => __( 'This customer has spent at least 500 in store currency.', 'yoohw-customer-intelligence' ),
			);
		}

		if ( $total_spent >= 1000 ) {
			$factors[] = array(
				'label'       => __( 'High purchase value', 'yoohw-customer-intelligence' ),
				'impact'      => 10,
				'description' => __( 'This customer has spent at least 1000 in store currency.', 'yoohw-customer-intelligence' ),
			);
		}

		if ( empty( $factors ) ) {
			$factors[] = array(
				'label'       => __( 'Limited trust signals', 'yoohw-customer-intelligence' ),
				'impact'      => 0,
				'description' => __( 'There is not enough customer history yet to build a stronger trust score.', 'yoohw-customer-intelligence' ),
			);
		}

		return $factors;
	}

	public static function calculate_lifecycle_stage( array $customer ): string {
		$total_orders = absint( $customer['total_orders'] ?? 0 );
		$total_spent  = (float) ( $customer['total_spent'] ?? 0 );
		$last_active  = $customer['last_activity_date'] ?? '';

		if ( self::days_since( $last_active ) >= 180 ) {
			return 'dormant';
		}

		if ( $total_spent >= 5000 || $total_orders >= 50 ) {
			return 'vip';
		}

		if ( $total_orders >= 10 || $total_spent >= 1000 ) {
			return 'loyal';
		}

		if ( $total_orders >= 2 ) {
			return 'repeat';
		}

		return 'new';
	}

	public static function get_lifecycle_factors( array $customer ): array {
		$stage        = self::calculate_lifecycle_stage( $customer );
		$total_orders = absint( $customer['total_orders'] ?? 0 );
		$total_spent  = (float) ( $customer['total_spent'] ?? 0 );
		$last_active  = $customer['last_activity_date'] ?? '';

		$factors = array();

		if ( 'dormant' === $stage ) {
			$factors[] = array(
				'label'       => __( 'Dormant customer', 'yoohw-customer-intelligence' ),
				'description' => __( 'This customer has not had recorded activity for at least 180 days.', 'yoohw-customer-intelligence' ),
			);
		} elseif ( 'vip' === $stage ) {
			$factors[] = array(
				'label'       => __( 'VIP customer', 'yoohw-customer-intelligence' ),
				'description' => __( 'This customer has very high order count or purchase value.', 'yoohw-customer-intelligence' ),
			);
		} elseif ( 'loyal' === $stage ) {
			$factors[] = array(
				'label'       => __( 'Loyal customer', 'yoohw-customer-intelligence' ),
				'description' => __( 'This customer has strong repeat purchase behavior or meaningful lifetime value.', 'yoohw-customer-intelligence' ),
			);
		} elseif ( 'repeat' === $stage ) {
			$factors[] = array(
				'label'       => __( 'Repeat customer', 'yoohw-customer-intelligence' ),
				'description' => __( 'This customer has placed more than one order.', 'yoohw-customer-intelligence' ),
			);
		} else {
			$factors[] = array(
				'label'       => __( 'New customer', 'yoohw-customer-intelligence' ),
				'description' => __( 'This customer has limited order history so far.', 'yoohw-customer-intelligence' ),
			);
		}

		$factors[] = array(
			'label'       => __( 'Order count', 'yoohw-customer-intelligence' ),
			'description' => sprintf(
				/* translators: %d: order count */
				__( 'This customer has placed %d orders.', 'yoohw-customer-intelligence' ),
				$total_orders
			),
		);

		$factors[] = array(
			'label'       => __( 'Lifetime value', 'yoohw-customer-intelligence' ),
			'description' => sprintf(
				/* translators: %s: total spent */
				__( 'This customer has spent %s in store currency.', 'yoohw-customer-intelligence' ),
				number_format_i18n( $total_spent, 2 )
			),
		);

		$factors[] = array(
			'label'       => __( 'Last activity', 'yoohw-customer-intelligence' ),
			'description' => sprintf(
				/* translators: %d: days since last activity */
				__( 'Last recorded activity was %d days ago.', 'yoohw-customer-intelligence' ),
				self::days_since( $last_active )
			),
		);

		return $factors;
	}
}
