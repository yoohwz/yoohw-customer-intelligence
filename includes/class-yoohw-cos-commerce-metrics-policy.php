<?php
defined( 'ABSPATH' ) || exit;

/**
 * Authoritative commerce metric semantics for CRM aggregates.
 *
 * A recognized order is a non-refund shop order in a paid WooCommerce status.
 * The same population is used for total_orders, total_spent and AOV. Revenue is
 * net of partial refunds; fully refunded/refunded-status orders contribute zero.
 */
final class YoOhw_COS_Commerce_Metrics_Policy {

	public const VERSION = 1;

	public static function recognized_statuses(): array {
		$statuses = function_exists( 'wc_get_is_paid_statuses' )
			? (array) wc_get_is_paid_statuses()
			: array( 'processing', 'completed' );

		$statuses = array_map(
			static function( string $status ): string {
				return 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
			},
			array_map( 'sanitize_key', $statuses )
		);

		return array_values(
			array_unique(
				array_filter(
					(array) apply_filters( 'yoohw_cos_commerce_metric_statuses', $statuses )
				)
			)
		);
	}

	public static function is_placed_order( WC_Order $order ): bool {
		return ! $order instanceof WC_Order_Refund && 'shop_order' === $order->get_type();
	}

	public static function is_successful_order( WC_Order $order ): bool {
		return self::is_placed_order( $order )
			&& in_array( sanitize_key( $order->get_status() ), self::recognized_statuses(), true );
	}

	public static function is_revenue_contributing_order( WC_Order $order ): bool {
		if ( ! self::is_successful_order( $order ) ) {
			return false;
		}

		$gross    = max( 0.0, (float) $order->get_total() );
		$refunded = method_exists( $order, 'get_total_refunded' ) ? max( 0.0, (float) $order->get_total_refunded() ) : 0.0;

		return $gross - $refunded > 0;
	}

	public static function get_contribution( WC_Order $order, int $customer_id ): array {
		$recognized = self::is_successful_order( $order );
		$gross      = max( 0.0, (float) $order->get_total() );
		$refunded   = method_exists( $order, 'get_total_refunded' ) ? max( 0.0, (float) $order->get_total_refunded() ) : 0.0;
		$net        = $recognized ? max( 0.0, $gross - $refunded ) : 0.0;
		$date       = $order->get_date_created();

		return array(
			'order_id'          => absint( $order->get_id() ),
			'customer_id'       => absint( $customer_id ),
			'order_status'      => sanitize_key( $order->get_status() ),
			'order_total'       => $gross,
			'revenue_amount'    => $net,
			'counts_as_order'    => $recognized ? 1 : 0,
			'counts_as_revenue'  => self::is_revenue_contributing_order( $order ) ? 1 : 0,
			'order_date'        => $date ? $date->date( 'Y-m-d H:i:s' ) : YoOhw_COS_DB::now(),
			'policy_version'    => self::VERSION,
			'updated_at'        => YoOhw_COS_DB::now(),
		);
	}
}
