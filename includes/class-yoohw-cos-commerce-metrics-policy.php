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

	public const VERSION = 2;
	private static $store_currency_generation = null;

	public static function currency( WC_Order $order ): ?string {
		$currency = strtoupper( trim( (string) $order->get_currency() ) );
		return preg_match( '/^[A-Z]{3}$/', $currency ) ? $currency : null;
	}

	public static function money_is_comparable( array $customer ): bool {
		return 'comparable' === self::availability( $customer )['reason'];
	}

	/** Read-only effective state. Site readiness precedes customer trust, then source state. */
	public static function availability( array $customer, array $context = array() ): array {
		$site = $context['site'] ?? self::site_readiness();
		$state = in_array( $customer['money_state'] ?? '', array( 'comparable', 'none', 'mixed', 'unknown' ), true ) ? $customer['money_state'] : 'unknown';
		$currency = preg_match( '/^[A-Z]{3}$/D', (string) ( $customer['money_currency'] ?? '' ) ) ? $customer['money_currency'] : null;
		$trusted = absint( $customer['commerce_metrics_version'] ?? 0 ) >= self::VERSION;
		if ( 'ready' !== $site['state'] ) {
			$reason = 'preparing' === $site['state'] ? 'preparing_currency_data' : 'currency_data_attention';
		} elseif ( ! $trusted ) {
			$reason = 'metrics_stale';
		} elseif ( 'none' === $state && 0 === absint( $customer['total_orders'] ?? 0 ) ) {
			$reason = 'none';
		} elseif ( 'mixed' === $state ) {
			$reason = 'mixed';
		} elseif ( 'comparable' === $state && null !== $currency ) {
			$reason = 'comparable';
		} else {
			$reason = 'unknown_source_currency';
		}
		return array(
			'customer_state' => $state, 'site_state' => $site['state'], 'reason' => $reason,
			'currency' => $currency, 'amount_available' => in_array( $reason, array( 'comparable', 'none' ), true ),
			'matches_store_currency' => 'comparable' === $reason && $currency === ( $context['store_currency'] ?? self::current_store_currency() ),
			'intelligence_fresh' => YoOhw_COS_Intelligence::persisted_decisions_are_safe( $customer, $context['generation'] ?? null ),
		);
	}

	/** Immutable read model for one already-loaded list page; never used for writes. */
	public static function read_context(): array {
		return array( 'site' => self::site_readiness(), 'generation' => YoOhw_COS_Intelligence::get_scoring_generation(), 'store_currency' => self::current_store_currency() );
	}

	public static function site_readiness(): array {
		$state = YoOhw_COS_Migration_Runner::get_state();
		$migration = $state['commerce_currency_v3'] ?? $state['commerce_facts_v2'] ?? array();
		$scheduled = wp_next_scheduled( YoOhw_COS_Migration_Runner::HOOK );
		$ready = YoOhw_COS_Migration_Runner::currency_backfill_is_complete();
		$status = $migration['status'] ?? '';
		$site = $ready ? 'ready' : 'attention';
		global $wpdb;
		$issues = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(status = 'pending') pending, SUM(status = 'unresolved') unresolved FROM %i WHERE migration_id IN ('commerce_facts_v2', 'commerce_currency_v3') AND status IN ('pending', 'unresolved')", YoOhw_COS_DB::migration_issues_table() ), ARRAY_A );
		$issues_readable = '' === $wpdb->last_error;
		$schema = get_option( 'yoohw_cos_schema_status', array() );
		$progress = absint( $migration['last_progress_at'] ?? 0 );
		if ( ! $ready && in_array( $status, array( 'pending', 'in_progress' ), true ) ) {
			// Recent successful progress can outlive cron rescheduling. Historical errors
			// do not imply failure after later successful work. Grace is not a completion SLA.
			$recent = $progress > time() - 15 * MINUTE_IN_SECONDS;
			$error_at = (string) ( $migration['last_error_at'] ?? '' );
			$error_date = preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $error_at ) ? DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $error_at, wp_timezone() ) : false;
			$error_timestamp = $error_date && $error_date->format( 'Y-m-d H:i:s' ) === $error_at ? $error_date->getTimestamp() : null;
			// Recent progress before a new failure is not recovery. Missing/invalid
			// error ordering is attention until the worker clears the error on success.
			$error_recovered = empty( $migration['last_error'] ) || ( $recent && null !== $error_timestamp && $progress > $error_timestamp );
			$site = ( $recent || ( $scheduled && $scheduled >= time() - 15 * MINUTE_IN_SECONDS ) )
				&& $error_recovered && empty( $migration['unresolved_issues'] )
				&& $issues_readable && empty( $issues['unresolved'] ) && 'blocked' !== ( $schema['status'] ?? '' )
				&& empty( $state['_read_error'] ) && function_exists( 'wc_get_orders' ) ? 'preparing' : 'attention';
		}
		return array( 'state' => $site, 'migration' => $migration, 'scheduled_at' => $scheduled ? (int) $scheduled : null, 'issues' => array( 'pending' => absint( $issues['pending'] ?? 0 ), 'unresolved' => absint( $issues['unresolved'] ?? 0 ) ) );
	}

	public static function reason_label( string $reason ): string {
		$labels = array(
			'none' => __( 'No recognized orders', 'yoohw-customer-intelligence' ),
			'mixed' => __( 'Multiple currencies', 'yoohw-customer-intelligence' ),
			'unknown_source_currency' => __( 'Order currency unavailable', 'yoohw-customer-intelligence' ),
			'preparing_currency_data' => __( 'Preparing currency data…', 'yoohw-customer-intelligence' ),
			'currency_data_attention' => __( 'Currency data needs attention', 'yoohw-customer-intelligence' ),
			'metrics_stale' => __( 'Updating monetary data…', 'yoohw-customer-intelligence' ),
		);
		return $labels[ $reason ] ?? __( 'Available', 'yoohw-customer-intelligence' );
	}

	public static function money_matches_store( array $customer ): bool {
		if ( ! self::money_is_comparable( $customer ) || ! function_exists( 'get_woocommerce_currency' ) ) {
			return false;
		}
		return $customer['money_currency'] === self::current_store_currency();
	}

	public static function current_store_currency(): string {
		if ( ! function_exists( 'get_woocommerce_currency' ) ) {
			return '';
		}
		$generation = YoOhw_COS_Intelligence::get_scoring_generation();
		if ( self::$store_currency_generation !== $generation ) {
			// A currency update in another request does not invalidate this request's option cache.
			wp_cache_delete( 'woocommerce_currency', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			self::$store_currency_generation = $generation;
		}
		return get_woocommerce_currency();
	}

	public static function format_money( array $source, string $key ): string {
		$availability = self::availability( $source );
		if ( 'none' === $availability['reason'] ) {
			return function_exists( 'wc_price' ) ? wc_price( 0 ) : number_format_i18n( 0, 2 );
		}
		if ( 'comparable' !== $availability['reason'] ) {
			return '<span class="yoohw-cos-money-reason">' . esc_html( self::reason_label( $availability['reason'] ) ) . '</span>';
		}
		$amount = (float) ( $source[ $key ] ?? 0 );
		$formatted = function_exists( 'wc_price' )
			? wc_price( $amount, array( 'currency' => $source['money_currency'] ) )
			: number_format_i18n( $amount, 2 ) . ' ' . esc_html( $source['money_currency'] );
		if ( function_exists( 'get_woocommerce_currency' ) && $source['money_currency'] !== get_woocommerce_currency() ) {
			$formatted .= ' (' . esc_html( $source['money_currency'] ) . ')';
		}
		return $formatted;
	}

	/** Plain text for sentence contexts; retain canonical monetary/localization policy. */
	public static function format_money_text( array $source, string $key ): string {
		return wp_strip_all_tags( html_entity_decode( self::format_money( $source, $key ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	private static function currency_data_ready(): bool {
		return YoOhw_COS_Migration_Runner::currency_backfill_is_complete();
	}

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
			'currency'          => self::currency( $order ),
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
