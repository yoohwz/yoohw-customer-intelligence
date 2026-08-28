<?php
defined( 'ABSPATH' ) || exit;

/**
 * Narrow boundary used by core/admin code to discover optional providers.
 * Provider schema/options/license probes remain isolated in adapter classes.
 */
final class YoOhw_COS_Integrations {

	public static function loyalty_active(): bool {
		return class_exists( 'YoOhw_COS_Loyalty_Integration' )
			&& is_callable( array( 'YoOhw_COS_Loyalty_Integration', 'is_loyalty_plugin_active' ) )
			&& YoOhw_COS_Loyalty_Integration::is_loyalty_plugin_active();
	}

	public static function blacklist_active(): bool {
		return class_exists( 'YoOhw_COS_Blacklist_Manager_Integration' )
			&& is_callable( array( 'YoOhw_COS_Blacklist_Manager_Integration', 'is_active' ) )
			&& YoOhw_COS_Blacklist_Manager_Integration::is_active();
	}

	public static function blacklist_premium_active(): bool {
		return self::blacklist_active()
			&& class_exists( 'YoOhw_COS_Blacklist_Manager_Premium_Integration' )
			&& is_callable( array( 'YoOhw_COS_Blacklist_Manager_Premium_Integration', 'is_active' ) )
			&& YoOhw_COS_Blacklist_Manager_Premium_Integration::is_active();
	}

	public static function loyalty_customer_data( int $wp_user_id ): array {
		if ( ! self::loyalty_active() || ! is_callable( array( 'YoOhw_COS_Loyalty_Integration', 'get_user_loyalty_customer_data' ) ) ) {
			return array();
		}

		$data = YoOhw_COS_Loyalty_Integration::get_user_loyalty_customer_data( absint( $wp_user_id ) );

		return is_array( $data ) ? $data : array();
	}

	public static function loyalty_roles(): array {
		if ( ! self::loyalty_active() || ! is_callable( array( 'YoOhw_COS_Loyalty_Integration', 'get_configured_loyalty_roles' ) ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'sanitize_key', (array) YoOhw_COS_Loyalty_Integration::get_configured_loyalty_roles() ) ) );
	}

	public static function blacklist_backfill_source_count(): int {
		$total = self::blacklist_active() && is_callable( array( 'YoOhw_COS_Blacklist_Manager_Integration', 'get_backfill_source_count' ) )
			? YoOhw_COS_Blacklist_Manager_Integration::get_backfill_source_count()
			: 0;

		if ( self::blacklist_premium_active() && is_callable( array( 'YoOhw_COS_Blacklist_Manager_Premium_Integration', 'get_backfill_source_count' ) ) ) {
			$total += YoOhw_COS_Blacklist_Manager_Premium_Integration::get_backfill_source_count();
		}

		return absint( $total );
	}

	public static function customer_blacklist_status( int $customer_id ): array {
		if ( ! self::blacklist_active() || ! is_callable( array( 'YoOhw_COS_Blacklist_Manager_Integration', 'get_customer_blacklist_status' ) ) ) {
			return array();
		}

		$status = YoOhw_COS_Blacklist_Manager_Integration::get_customer_blacklist_status( absint( $customer_id ) );

		return is_array( $status ) ? $status : array();
	}
}
