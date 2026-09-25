<?php
require_once dirname( __DIR__ ) . '/environment.php';
yci_test_environment();
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WP_UnitTestCase' ) ) { return; }

final class YoOhw_COS_Diagnostics_Test extends WP_UnitTestCase {
	public function set_up(): void {
		global $wpdb;
		parent::set_up();
		YoOhw_COS_Install::install();
		YoOhw_COS_Customers::reset_data();
		delete_option( YoOhw_COS_Reset_Guard::OPTION );
		YoOhw_COS_Reset_Guard::init();
		delete_option( 'yoohw_cos_privacy_suppression_secret' );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', YoOhw_COS_DB::table( 'privacy_suppression' ) ) );
	}

	public function test_snapshot_reads_actual_counts_without_writing_options_or_cron(): void {
		global $wpdb;
		$id = YoOhw_COS_Customers::create_customer( array( 'email' => 'diagnostic@example.test' ) );
		$this->assertGreaterThan( 0, $id );
		$task = YoOhw_COS_Tasks::create_task( array( 'customer_id' => $id, 'title' => 'Synthetic task', 'due_date' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
		$this->assertGreaterThan( 0, $task );
		update_option( 'yoohw_cos_sync_state', array( 'status' => 'in_progress', 'sync_order' => YoOhw_COS_Customers::SYNC_ORDER, 'total_scanned' => 12, 'total_processed' => 10, 'total_retryable' => 1, 'total_unresolved' => 1, 'has_more' => 1 ), false );
		$before_options = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name, option_value FROM %i WHERE option_name LIKE %s ORDER BY option_name', $wpdb->options, 'yoohw_cos_%' ), ARRAY_A );
		$before_cron = get_option( 'cron' );
		$first = YoOhw_COS_Diagnostics::snapshot();
		$second = YoOhw_COS_Diagnostics::snapshot();
		$this->assertSame( $first, $second );
		$this->assertSame( 1, $first['data']['profiles'] );
		$this->assertSame( 1, $first['data']['active_profiles'] );
		$this->assertSame( 1, $first['data']['open_tasks'] );
		$this->assertSame( 1, $first['data']['overdue_tasks'] );
		$this->assertSame( 12, $first['sync']['scanned'] );
		$this->assertTrue( $first['sync']['unfinished'] );
		$this->assertSame( '', $first['sync']['completed_at'] );
		$this->assertSame( $before_options, $wpdb->get_results( $wpdb->prepare( 'SELECT option_name, option_value FROM %i WHERE option_name LIKE %s ORDER BY option_name', $wpdb->options, 'yoohw_cos_%' ), ARRAY_A ) );
		$this->assertSame( $before_cron, get_option( 'cron' ) );
	}

	public function test_reset_and_privacy_failures_are_reported_without_identity(): void {
		update_option( YoOhw_COS_Reset_Guard::OPTION, array( 'epoch' => wp_generate_uuid4(), 'status' => 'pending' ), false );
		update_option( 'yoohw_cos_privacy_suppression_secret', 'invalid-secret', false );
		$diagnostics = YoOhw_COS_Diagnostics::snapshot();
		$this->assertSame( 'blocked', $diagnostics['status'] );
		$this->assertSame( 'pending', $diagnostics['reset']['status'] );
		$this->assertFalse( $diagnostics['privacy']['evaluable'] );
		$this->assertStringNotContainsString( 'invalid-secret', wp_json_encode( $diagnostics ) );
	}

	public function test_intelligence_checkpoint_is_distinct_from_activity_recalculation(): void {
		update_option( 'yoohw_cos_intelligence_generation', '11111111-1111-4111-8111-111111111111', false );
		$generation = YoOhw_COS_Intelligence::get_scoring_generation();
		update_option( 'yoohw_cos_intelligence_freshness', array( 'generation' => $generation, 'cursor' => 123, 'status' => 'in_progress' ), false );
		update_option( 'yoohw_cos_activity_semantics_recalculation', array( 'status' => 'completed', 'total_scanned' => 50, 'total_updated' => 30, 'next_page' => 2 ), false );
		$diagnostics = YoOhw_COS_Diagnostics::snapshot();
		$this->assertSame( 'in_progress', $diagnostics['intelligence']['worker']['status'] );
		$this->assertSame( 123, $diagnostics['intelligence']['worker']['cursor'] );
		$this->assertTrue( $diagnostics['intelligence']['worker']['generation_matches'] );
		$this->assertSame( 'completed', $diagnostics['intelligence']['activity_worker']['status'] );
		$this->assertSame( 50, $diagnostics['intelligence']['activity_worker']['scanned'] );
	}

	public function test_legacy_sync_completion_is_not_accepted_without_outcome_counts(): void {
		update_option( 'yoohw_cos_sync_state', array( 'status' => 'completed', 'last_run_at' => YoOhw_COS_DB::now(), 'completed_at' => YoOhw_COS_DB::now() ), false );
		$diagnostics = YoOhw_COS_Diagnostics::snapshot();
		$this->assertSame( 'not_started', $diagnostics['sync']['status'] );
		$this->assertTrue( $diagnostics['sync']['legacy_outcomes'] );
		$this->assertSame( '', $diagnostics['sync']['completed_at'] );
		$this->assertSame( 'attention', $diagnostics['status'] );
	}

	public function test_obsolete_in_progress_sync_order_requires_a_new_scan(): void {
		update_option( 'yoohw_cos_sync_state', array(
			'status' => 'in_progress', 'sync_order' => 'legacy_offset', 'has_more' => true,
			'total_retryable' => 0, 'total_unresolved' => 0,
		), false );
		$diagnostics = YoOhw_COS_Diagnostics::snapshot();
		$this->assertSame( 'not_started', $diagnostics['sync']['status'] );
		$this->assertFalse( $diagnostics['sync']['unfinished'] );
		$this->assertContains( 'sync', $diagnostics['actionable'] );
	}

	public function test_completed_migration_issues_do_not_require_a_worker_schedule(): void {
		foreach ( array( YoOhw_COS_Customers::RISK_SCORE_REFRESH_HOOK, 'yoohw_cos_crm_email_due_soon', 'yoohw_cos_crm_email_daily' ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
			wp_schedule_single_event( time() + HOUR_IN_SECONDS, $hook );
		}
		update_option( 'yoohw_cos_data_migrations', array( 'identity_normalization_v2' => array( 'status' => 'completed_with_issues', 'phase' => 'retry', 'unresolved_issues' => 1 ) ), false );
		wp_clear_scheduled_hook( YoOhw_COS_Migration_Runner::HOOK );
		$diagnostics = YoOhw_COS_Diagnostics::snapshot();
		$this->assertFalse( $diagnostics['cron']['migration']['scheduled'] );
		$this->assertContains( 'migration', $diagnostics['actionable'] );
		$this->assertNotContains( 'cron', $diagnostics['warnings'] );
	}
}
