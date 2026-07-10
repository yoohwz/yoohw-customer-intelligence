<?php
defined( 'ABSPATH' ) || exit;

final class YoOhw_COS_Intelligence {

	private const SCORING_SETTINGS_OPTION = 'yoohw_cos_scoring_settings';

	public static function init(): void {
		// Reserved for future hooks.
	}

	public static function calculate_customer_status( array $customer ): string {
		$total_orders = absint( $customer['total_orders'] ?? 0 );
		$total_spent  = (float) ( $customer['total_spent'] ?? 0 );
		$last_active  = $customer['last_activity_date'] ?? '';
		$settings     = self::get_scoring_settings();
		$status       = $settings['customer_status'];

		if ( $total_orders <= absint( $status['new_max_orders'] ?? 1 ) ) {
			return 'new';
		}

		if ( $total_spent >= (float) $status['vip_spent'] || $total_orders >= absint( $status['vip_orders'] ) ) {
			return 'vip';
		}

		if ( self::days_since( $last_active ) >= absint( $status['inactive_days'] ) ) {
			return 'inactive';
		}

		if ( self::days_since( $last_active ) >= absint( $status['at_risk_days'] ) ) {
			return 'at_risk';
		}

		return 'active';
	}

	public static function calculate_vip_status( array $customer ): string {
		$total_orders = absint( $customer['total_orders'] ?? 0 );
		$total_spent  = (float) ( $customer['total_spent'] ?? 0 );
		$settings     = self::get_scoring_settings();
		$tiers        = $settings['value_tiers'];

		if ( $total_spent >= (float) $tiers['top_customer_spent'] || $total_orders >= absint( $tiers['top_customer_orders'] ) ) {
			return 'platinum';
		}

		if ( $total_spent >= (float) $tiers['very_high_value_spent'] || $total_orders >= absint( $tiers['very_high_value_orders'] ) ) {
			return 'gold';
		}

		if ( $total_spent >= (float) $tiers['high_value_spent'] || $total_orders >= absint( $tiers['high_value_orders'] ) ) {
			return 'silver';
		}

		return 'none';
	}

	public static function get_scoring_settings(): array {
		$saved = get_option( self::SCORING_SETTINGS_OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		return self::sanitize_scoring_settings( array_replace_recursive( self::get_scoring_settings_defaults(), $saved ) );
	}

	public static function update_scoring_settings( array $source ): array {
		$settings = self::sanitize_scoring_settings( $source );

		update_option( self::SCORING_SETTINGS_OPTION, $settings, false );

		return $settings;
	}

	public static function get_scoring_settings_defaults(): array {
		return array(
			'value_tiers'     => array(
				'high_value_spent'        => 1000.0,
				'high_value_orders'       => 10,
				'very_high_value_spent'   => 2500.0,
				'very_high_value_orders'  => 25,
				'top_customer_spent'      => 5000.0,
				'top_customer_orders'     => 50,
			),
			'lifecycle'       => array(
				'repeat_orders'       => 2,
				'loyal_spent'         => 1000.0,
				'loyal_orders'        => 10,
				'top_customer_spent'  => 5000.0,
				'top_customer_orders' => 50,
				'dormant_days'        => 180,
			),
			'customer_status' => array(
				'new_max_orders' => 1,
				'vip_spent'      => 1000.0,
				'vip_orders'     => 10,
				'at_risk_days'   => 45,
				'inactive_days'  => 90,
			),
		);
	}

	private static function sanitize_scoring_settings( array $source ): array {
		$defaults = self::get_scoring_settings_defaults();
		$source   = array(
			'value_tiers'     => isset( $source['value_tiers'] ) && is_array( $source['value_tiers'] ) ? $source['value_tiers'] : array(),
			'lifecycle'       => isset( $source['lifecycle'] ) && is_array( $source['lifecycle'] ) ? $source['lifecycle'] : array(),
			'customer_status' => isset( $source['customer_status'] ) && is_array( $source['customer_status'] ) ? $source['customer_status'] : array(),
		);
		$source   = array_replace_recursive( $defaults, $source );

		$settings = array(
			'value_tiers'     => array(
				'high_value_spent'        => self::sanitize_scoring_amount( $source['value_tiers']['high_value_spent'] ?? $defaults['value_tiers']['high_value_spent'] ),
				'high_value_orders'       => max( 1, absint( $source['value_tiers']['high_value_orders'] ?? $defaults['value_tiers']['high_value_orders'] ) ),
				'very_high_value_spent'   => self::sanitize_scoring_amount( $source['value_tiers']['very_high_value_spent'] ?? $defaults['value_tiers']['very_high_value_spent'] ),
				'very_high_value_orders'  => max( 1, absint( $source['value_tiers']['very_high_value_orders'] ?? $defaults['value_tiers']['very_high_value_orders'] ) ),
				'top_customer_spent'      => self::sanitize_scoring_amount( $source['value_tiers']['top_customer_spent'] ?? $defaults['value_tiers']['top_customer_spent'] ),
				'top_customer_orders'     => max( 1, absint( $source['value_tiers']['top_customer_orders'] ?? $defaults['value_tiers']['top_customer_orders'] ) ),
			),
			'lifecycle'       => array(
				'repeat_orders'       => max( 1, absint( $source['lifecycle']['repeat_orders'] ?? $defaults['lifecycle']['repeat_orders'] ) ),
				'loyal_spent'         => self::sanitize_scoring_amount( $source['lifecycle']['loyal_spent'] ?? $defaults['lifecycle']['loyal_spent'] ),
				'loyal_orders'        => max( 1, absint( $source['lifecycle']['loyal_orders'] ?? $defaults['lifecycle']['loyal_orders'] ) ),
				'top_customer_spent'  => self::sanitize_scoring_amount( $source['lifecycle']['top_customer_spent'] ?? $defaults['lifecycle']['top_customer_spent'] ),
				'top_customer_orders' => max( 1, absint( $source['lifecycle']['top_customer_orders'] ?? $defaults['lifecycle']['top_customer_orders'] ) ),
				'dormant_days'        => max( 1, absint( $source['lifecycle']['dormant_days'] ?? $defaults['lifecycle']['dormant_days'] ) ),
			),
			'customer_status' => array(
				'new_max_orders' => max( 0, absint( $source['customer_status']['new_max_orders'] ?? $defaults['customer_status']['new_max_orders'] ) ),
				'vip_spent'      => self::sanitize_scoring_amount( $source['customer_status']['vip_spent'] ?? $defaults['customer_status']['vip_spent'] ),
				'vip_orders'     => max( 1, absint( $source['customer_status']['vip_orders'] ?? $defaults['customer_status']['vip_orders'] ) ),
				'at_risk_days'   => max( 1, absint( $source['customer_status']['at_risk_days'] ?? $defaults['customer_status']['at_risk_days'] ) ),
				'inactive_days'  => max( 1, absint( $source['customer_status']['inactive_days'] ?? $defaults['customer_status']['inactive_days'] ) ),
			),
		);

		$settings['value_tiers']['very_high_value_spent']  = max( $settings['value_tiers']['high_value_spent'], $settings['value_tiers']['very_high_value_spent'] );
		$settings['value_tiers']['top_customer_spent']     = max( $settings['value_tiers']['very_high_value_spent'], $settings['value_tiers']['top_customer_spent'] );
		$settings['value_tiers']['very_high_value_orders'] = max( $settings['value_tiers']['high_value_orders'], $settings['value_tiers']['very_high_value_orders'] );
		$settings['value_tiers']['top_customer_orders']    = max( $settings['value_tiers']['very_high_value_orders'], $settings['value_tiers']['top_customer_orders'] );

		$settings['lifecycle']['loyal_orders']        = max( $settings['lifecycle']['repeat_orders'], $settings['lifecycle']['loyal_orders'] );
		$settings['lifecycle']['top_customer_orders'] = max( $settings['lifecycle']['loyal_orders'], $settings['lifecycle']['top_customer_orders'] );
		$settings['lifecycle']['top_customer_spent']  = max( $settings['lifecycle']['loyal_spent'], $settings['lifecycle']['top_customer_spent'] );

		$settings['customer_status']['inactive_days'] = max( $settings['customer_status']['at_risk_days'], $settings['customer_status']['inactive_days'] );

		return $settings;
	}

	private static function sanitize_scoring_amount( $value ): float {
		$value      = sanitize_text_field( (string) $value );
		$normalized = str_replace( ',', '', $value );

		return max( 0.0, round( (float) $normalized, 2 ) );
	}

	public static function get_value_tier_labels(): array {
		return array(
			'none'     => __( 'Standard', 'yoohw-customer-intelligence' ),
			'silver'   => __( 'High value', 'yoohw-customer-intelligence' ),
			'gold'     => __( 'Very high value', 'yoohw-customer-intelligence' ),
			'platinum' => __( 'Top customer', 'yoohw-customer-intelligence' ),
		);
	}

	public static function get_value_tier_label( string $tier ): string {
		$tier   = sanitize_key( $tier );
		$labels = self::get_value_tier_labels();

		return $labels[ $tier ] ?? ucwords( str_replace( array( '_', '-' ), ' ', $tier ) );
	}

	public static function get_value_tier_badge_class( string $tier ): string {
		$tier = sanitize_key( $tier );

		$classes = array(
			'none'     => 'standard',
			'silver'   => 'high-value',
			'gold'     => 'very-high-value',
			'platinum' => 'top-customer',
		);

		return $classes[ $tier ] ?? sanitize_html_class( $tier );
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

		$score = (float) apply_filters( 'yoohw_cos_customer_risk_score', $score, $customer );

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

		$factors = apply_filters( 'yoohw_cos_customer_risk_factors', $factors, $customer );
		$factors = is_array( $factors ) ? $factors : array();

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
		$settings     = self::get_scoring_settings();
		$lifecycle    = $settings['lifecycle'];

		if ( self::days_since( $last_active ) >= absint( $lifecycle['dormant_days'] ) ) {
			return 'dormant';
		}

		if ( $total_spent >= (float) $lifecycle['top_customer_spent'] || $total_orders >= absint( $lifecycle['top_customer_orders'] ) ) {
			return 'vip';
		}

		if ( $total_orders >= absint( $lifecycle['loyal_orders'] ) || $total_spent >= (float) $lifecycle['loyal_spent'] ) {
			return 'loyal';
		}

		if ( $total_orders >= absint( $lifecycle['repeat_orders'] ) ) {
			return 'repeat';
		}

		return 'new';
	}

	public static function get_lifecycle_factors( array $customer ): array {
		$stage        = self::calculate_lifecycle_stage( $customer );
		$total_orders = absint( $customer['total_orders'] ?? 0 );
		$total_spent  = (float) ( $customer['total_spent'] ?? 0 );
		$last_active  = $customer['last_activity_date'] ?? '';
		$settings     = self::get_scoring_settings();
		$lifecycle    = $settings['lifecycle'];

		$factors = array();

		if ( 'dormant' === $stage ) {
			$factors[] = array(
				'label'       => __( 'Dormant customer', 'yoohw-customer-intelligence' ),
				'description' => sprintf(
					/* translators: %s: dormant threshold in days. */
					__( 'This customer has not had recorded activity for at least %s days.', 'yoohw-customer-intelligence' ),
					number_format_i18n( absint( $lifecycle['dormant_days'] ?? 180 ) )
				),
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
