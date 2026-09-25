<?php
defined( 'ABSPATH' ) || exit;

/** A read-only view of durable Customer Intelligence state. */
final class YoOhw_COS_Diagnostics {
	public static function snapshot(): array {
		global $wpdb;
		$schema = YoOhw_COS_Install::inspect_schema();
		$reset = YoOhw_COS_Reset_Guard::state();
		$privacy = YoOhw_COS_Privacy_Erasure::is_suppressed( array() );
		$data = array(
			'profiles' => 0, 'active_profiles' => 0, 'archived_profiles' => 0,
			'order_facts' => 0, 'open_tasks' => 0, 'overdue_tasks' => 0,
			'comparable' => 0, 'mixed' => 0, 'unknown' => 0, 'outdated_metrics' => 0,
			'current_generation' => 0, 'stale_generation' => 0, 'currency_unready' => 0,
		);
		$generation = YoOhw_COS_Intelligence::get_scoring_generation();
		$generation = preg_match( '/^[a-f0-9-]{36}$/D', $generation ) ? $generation : '';
		$tables_ready = 'ready' === $schema['status'] || 'upgrade-required' === $schema['status'];
		if ( $tables_ready ) {
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT COUNT(*) profiles,
				 SUM(archived_at IS NULL) active_profiles,
				 SUM(archived_at IS NOT NULL) archived_profiles,
				 SUM(archived_at IS NULL AND money_state = 'comparable' AND commerce_metrics_version >= %d AND money_currency IS NOT NULL AND money_currency <> '') comparable,
				 SUM(archived_at IS NULL AND money_state = 'mixed' AND commerce_metrics_version >= %d) mixed,
				 SUM(archived_at IS NULL AND (money_state NOT IN ('comparable', 'mixed') OR commerce_metrics_version < %d OR (money_state = 'comparable' AND (money_currency IS NULL OR money_currency = '')))) unknown,
				 SUM(archived_at IS NULL AND commerce_metrics_version < %d) outdated_metrics,
				 SUM(archived_at IS NULL AND intelligence_currency_ready = 1 AND %s <> '' AND intelligence_generation = %s) current_generation,
				 SUM(archived_at IS NULL AND intelligence_currency_ready = 1 AND (%s = '' OR intelligence_generation <> %s)) stale_generation,
				 SUM(archived_at IS NULL AND intelligence_currency_ready <> 1) currency_unready
				 FROM %i",
				YoOhw_COS_Commerce_Metrics_Policy::VERSION,
				YoOhw_COS_Commerce_Metrics_Policy::VERSION,
				YoOhw_COS_Commerce_Metrics_Policy::VERSION,
				YoOhw_COS_Commerce_Metrics_Policy::VERSION,
				$generation, $generation, $generation, $generation, YoOhw_COS_DB::customers_table()
			), ARRAY_A );
			if ( is_array( $row ) ) {
				foreach ( $row as $key => $value ) { $data[ $key ] = absint( $value ); }
			}
			$data['order_facts'] = absint( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', YoOhw_COS_DB::order_facts_table() ) ) );
			$tasks = $wpdb->get_row( $wpdb->prepare(
				'SELECT SUM(status <> %s) open_tasks, SUM(status <> %s AND due_date IS NOT NULL AND due_date < %s) overdue_tasks FROM %i',
				YoOhw_COS_Tasks::STATUS_COMPLETED, YoOhw_COS_Tasks::STATUS_COMPLETED, YoOhw_COS_DB::now(), YoOhw_COS_DB::tasks_table()
			), ARRAY_A );
			if ( is_array( $tasks ) ) {
				$data['open_tasks'] = absint( $tasks['open_tasks'] );
				$data['overdue_tasks'] = absint( $tasks['overdue_tasks'] );
			}
		}

		$sync = get_option( 'yoohw_cos_sync_state', array() );
		$sync = is_array( $sync ) ? $sync : array();
		$legacy_outcomes = ! isset( $sync['total_retryable'], $sync['total_unresolved'] )
			&& ( ! empty( $sync['last_run_at'] ) || get_option( 'yoohw_cos_last_sync_at', '' ) );
		$has_outcomes = isset( $sync['total_retryable'], $sync['total_unresolved'] );
		if ( ! $has_outcomes ) { $sync = array(); }
		$sync_status = $has_outcomes ? sanitize_key( (string) ( $sync['status'] ?? 'not_started' ) ) : 'not_started';
		if ( ! in_array( $sync_status, array( 'not_started', 'in_progress', 'completed', 'completed_with_issues' ), true ) ) { $sync_status = 'not_started'; }
		$sync_order = sanitize_key( (string) ( $sync['sync_order'] ?? '' ) );
		$obsolete_scan = YoOhw_COS_Customers::SYNC_ORDER !== $sync_order && ( ! empty( $sync['has_more'] ) || 'in_progress' === $sync_status );
		if ( $obsolete_scan ) { $sync_status = 'not_started'; }
		$sync = array(
			'status' => $sync_status,
			'legacy_outcomes' => (bool) $legacy_outcomes,
			'scanned' => absint( $sync['total_scanned'] ?? 0 ),
			'processed' => absint( $sync['total_processed'] ?? 0 ),
			'total' => absint( $sync['total_orders'] ?? 0 ),
			'retryable' => absint( $sync['total_retryable'] ?? 0 ),
			'unresolved' => absint( $sync['total_unresolved'] ?? 0 ),
			'last_run_at' => sanitize_text_field( (string) ( $sync['last_run_at'] ?? '' ) ),
			'completed_at' => $has_outcomes ? sanitize_text_field( (string) ( $sync['completed_at'] ?? '' ) ) : '',
			'unfinished' => $has_outcomes && ! $obsolete_scan && ( ! empty( $sync['has_more'] ) || 'in_progress' === $sync_status ),
		);
		$migration_state = YoOhw_COS_Migration_Runner::get_state();
		$migrations = array();
		foreach ( array( 'identity_normalization_v2', 'commerce_facts_v2', 'activity_semantics_v2', 'commerce_currency_v3' ) as $id ) {
			if ( ! isset( $migration_state[ $id ] ) || ! is_array( $migration_state[ $id ] ) ) { continue; }
			$item = $migration_state[ $id ];
			$migrations[ $id ] = array(
				'status' => sanitize_key( (string) ( $item['status'] ?? '' ) ),
				'phase' => sanitize_key( (string) ( $item['phase'] ?? '' ) ),
				'processed' => absint( $item['processed'] ?? 0 ),
				'next_page' => absint( $item['next_page'] ?? 0 ),
				'pending_issues' => absint( $item['pending_issues'] ?? 0 ),
				'unresolved_issues' => absint( $item['unresolved_issues'] ?? 0 ),
			);
		}
		if ( $tables_ready ) {
			foreach ( $migrations as &$migration ) {
				$migration['pending_issues'] = 0;
				$migration['unresolved_issues'] = 0;
			}
			unset( $migration );
			$issues = $wpdb->get_results( $wpdb->prepare(
				"SELECT migration_id, status, COUNT(*) total FROM %i WHERE status IN ('pending', 'unresolved') GROUP BY migration_id, status",
				YoOhw_COS_DB::migration_issues_table()
			), ARRAY_A );
			foreach ( (array) $issues as $issue ) {
				$id = (string) $issue['migration_id'];
				if ( isset( $migrations[ $id ] ) && in_array( $issue['status'], array( 'pending', 'unresolved' ), true ) ) {
					$migrations[ $id ][ $issue['status'] . '_issues' ] = absint( $issue['total'] );
				}
			}
		}
		$worker = get_option( 'yoohw_cos_intelligence_freshness', array() );
		$worker = is_array( $worker ) ? $worker : array();
		$worker = array(
			'status' => sanitize_key( (string) ( $worker['status'] ?? 'not_started' ) ),
			'cursor' => absint( $worker['cursor'] ?? 0 ),
			'generation_matches' => '' !== $generation && (string) ( $worker['generation'] ?? '' ) === $generation,
		);
		$activity = get_option( 'yoohw_cos_activity_semantics_recalculation', array() );
		$activity = is_array( $activity ) ? $activity : array();
		$activity = array(
			'status' => sanitize_key( (string) ( $activity['status'] ?? 'not_started' ) ),
			'scanned' => absint( $activity['total_scanned'] ?? 0 ),
			'updated' => absint( $activity['total_updated'] ?? 0 ),
			'next_page' => absint( $activity['next_page'] ?? 0 ),
		);
		$cron = array();
		foreach ( array(
			'migration' => YoOhw_COS_Migration_Runner::HOOK,
			'intelligence_daily' => YoOhw_COS_Customers::RISK_SCORE_REFRESH_HOOK,
			'activity_recalculation' => 'yoohw_cos_recalculate_activity_semantics',
			'crm_due_soon' => 'yoohw_cos_crm_email_due_soon',
			'crm_daily' => 'yoohw_cos_crm_email_daily',
		) as $name => $hook ) {
			$cron[ $name ] = self::cron_fact( $hook );
		}
		$cron['intelligence_catchup'] = self::cron_fact( YoOhw_COS_Customers::RISK_SCORE_REFRESH_HOOK, array( -1 ) );
		$cron['intelligence_daily_wakeup'] = self::cron_fact( YoOhw_COS_Customers::RISK_SCORE_REFRESH_HOOK, array( 0 ) );
		foreach ( array(
			'loyalty_backfill' => array( 'yoohw_cos_loyalty_backfill_state', 'yoohw_cos_backfill_loyalty_history' ),
			'premium_reassociation' => array( 'yoohw_cos_premium_reassociation_state', 'yoohw_cos_reassociate_premium_checkout_events' ),
		) as $name => $definition ) {
			$state = get_option( $definition[0], array() );
			if ( ! is_array( $state ) || ! in_array( (string) ( $state['status'] ?? '' ), array( 'pending', 'in_progress' ), true ) ) { continue; }
			$cron[ $name ] = self::cron_fact( $definition[1] );
		}
		$privacy_ready = null !== $privacy;
		$currency_complete = YoOhw_COS_Migration_Runner::currency_backfill_is_complete();
		$receipt_count = $privacy_ready && $tables_ready
			? absint( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', YoOhw_COS_DB::table( 'privacy_suppression' ) ) ) ) : null;
		$warnings = array();
		if ( 'ready' !== $schema['status'] ) { $warnings[] = 'schema'; }
		if ( 'ready' !== $reset['status'] ) { $warnings[] = 'reset'; }
		if ( ! $privacy_ready ) { $warnings[] = 'privacy'; }
		if ( 'not_started' === $sync['status'] || $sync['unfinished'] || $sync['retryable'] || $sync['unresolved'] ) { $warnings[] = 'sync'; }
		if ( $data['stale_generation'] || $data['currency_unready'] ) { $warnings[] = 'intelligence'; }
		if ( ! $currency_complete || $data['outdated_metrics'] ) { $warnings[] = 'currency'; }
		foreach ( $migrations as $item ) {
			if ( in_array( $item['status'], array( 'pending', 'in_progress', 'completed_with_issues' ), true ) || $item['pending_issues'] || $item['unresolved_issues'] ) { $warnings[] = 'migration'; break; }
		}
		$migration_unresolved = false;
		foreach ( $migrations as $item ) {
			if ( $item['unresolved_issues'] || 'completed_with_issues' === $item['status'] ) { $migration_unresolved = true; break; }
		}
		$migration_active = false;
		$next_migration = '';
		foreach ( array( 'identity_normalization_v2', 'commerce_facts_v2', 'activity_semantics_v2', 'commerce_currency_v3' ) as $id ) {
			if ( in_array( $migrations[ $id ]['status'] ?? '', array( 'pending', 'in_progress' ), true ) ) { $migration_active = true; $next_migration = $id; break; }
		}
		$woocommerce_blocks_migration = in_array( $next_migration, array( 'commerce_facts_v2', 'commerce_currency_v3' ), true )
			&& ( ! function_exists( 'wc_get_orders' ) || ! function_exists( 'wc_get_order_statuses' ) );
		$required_cron = array( 'intelligence_daily', 'crm_due_soon', 'crm_daily' );
		if ( $migration_active && ! $woocommerce_blocks_migration ) { $required_cron[] = 'migration'; }
		if ( in_array( $activity['status'], array( 'pending', 'in_progress' ), true ) ) { $required_cron[] = 'activity_recalculation'; }
		foreach ( array( 'loyalty_backfill', 'premium_reassociation' ) as $integration ) {
			if ( isset( $cron[ $integration ] ) ) { $required_cron[] = $integration; }
		}
		foreach ( $required_cron as $name ) {
			if ( ! $cron[ $name ]['scheduled'] || $cron[ $name ]['overdue'] ) { $warnings[] = 'cron'; break; }
		}
		if ( in_array( $worker['status'], array( 'pending', 'in_progress' ), true ) && ! $cron['intelligence_catchup']['scheduled'] && ! $cron['intelligence_daily_wakeup']['scheduled'] ) { $warnings[] = 'cron'; }
		$status = 'ready';
		if ( 'blocked' === $schema['status'] || 'ready' !== $reset['status'] || ! $privacy_ready ) { $status = 'blocked'; }
		elseif ( 'not_started' === $sync['status'] || $sync['unresolved'] || $migration_unresolved || $woocommerce_blocks_migration || ( $currency_complete && $data['outdated_metrics'] ) || 'upgrade-required' === $schema['status'] || in_array( 'cron', $warnings, true ) ) { $status = 'attention'; }
		elseif ( ! empty( $warnings ) ) { $status = 'working'; }
		$actionable = array_values( array_intersect( $warnings, array( 'schema', 'reset', 'privacy', 'cron' ) ) );
		if ( 'not_started' === $sync['status'] || $sync['unresolved'] ) { $actionable[] = 'sync'; }
		if ( $migration_unresolved || $woocommerce_blocks_migration ) { $actionable[] = 'migration'; }
		if ( $currency_complete && $data['outdated_metrics'] ) { $actionable[] = 'currency'; }
		return array(
			'status' => $status, 'warnings' => array_values( array_unique( $warnings ) ), 'actionable' => array_values( array_unique( $actionable ) ),
			'schema' => $schema, 'data' => $data, 'sync' => $sync,
			'migrations' => $migrations,
			'woocommerce_blocks_migration' => $woocommerce_blocks_migration,
			'currency' => array( 'backfill_complete' => $currency_complete, 'store_currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '' ),
			'intelligence' => array( 'generation' => substr( $generation, 0, 8 ), 'worker' => $worker, 'activity_worker' => $activity, 'data_updated_at' => sanitize_text_field( (string) get_option( 'yoohw_cos_customer_data_updated_at', '' ) ) ),
			'cron' => $cron, 'disable_wp_cron' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'reset' => array( 'status' => $reset['status'] ), 'privacy' => array( 'evaluable' => $privacy_ready, 'receipts' => $receipt_count ),
		);
	}

	private static function cron_fact( string $hook, array $args = array() ): array {
		$next = wp_next_scheduled( $hook, $args );
		return array(
			'scheduled' => false !== $next,
			'next_site_time' => false !== $next ? wp_date( 'Y-m-d H:i:s T', $next ) : '',
			'overdue' => false !== $next && $next < time(),
		);
	}
}
