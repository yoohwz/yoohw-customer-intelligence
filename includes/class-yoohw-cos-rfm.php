<?php
defined( 'ABSPATH' ) || exit;

/** Raw lifetime commerce facts shared by the Customers list and profile. */
final class YoOhw_COS_RFM {
	public static function recency_days( array $customer ): ?int {
		if ( absint( $customer['total_orders'] ?? 0 ) < 1 ) {
			return null;
		}
		$date = (string) ( $customer['last_order_date'] ?? '' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}(?: \d{2}:\d{2}:\d{2})?$/', $date ) ) {
			return null;
		}
		$format = 10 === strlen( $date ) ? '!Y-m-d' : '!Y-m-d H:i:s';
		$order_date = DateTimeImmutable::createFromFormat( $format, $date, wp_timezone() );
		if ( ! $order_date || $order_date->format( substr( $format, 1 ) ) !== $date ) {
			return null;
		}
		$day = DateTimeImmutable::createFromFormat( '!Y-m-d', substr( $date, 0, 10 ), wp_timezone() );
		$today = new DateTimeImmutable( current_time( 'Y-m-d' ), wp_timezone() );
		return max( 0, (int) $day->diff( $today )->format( '%r%a' ) );
	}

	public static function summary( array $customer ): array {
		$days   = self::recency_days( $customer );
		$orders = absint( $customer['total_orders'] ?? 0 );
		return array(
			'r' => null === $days ? __( 'Unavailable', 'yoohw-customer-intelligence' ) : sprintf(
				/* translators: %s: whole days since the last recognized order. */
				_n( '%s day', '%s days', $days, 'yoohw-customer-intelligence' ),
				number_format_i18n( $days )
			),
			'f' => sprintf(
				/* translators: %s: lifetime recognized-order count. */
				_n( '%s order', '%s orders', $orders, 'yoohw-customer-intelligence' ),
				number_format_i18n( $orders )
			),
			'm' => YoOhw_COS_Commerce_Metrics_Policy::format_money( $customer, 'total_spent' ),
		);
	}
}
