<?php
require_once dirname( __DIR__ ) . '/environment.php';
yci_test_environment();
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WP_UnitTestCase' ) ) { return; }

final class YoOhw_COS_Diagnostics_Test extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		YoOhw_COS_Install::install();
		YoOhw_COS_Customers::reset_data();
		delete_option( YoOhw_COS_Reset_Guard::OPTION );
		YoOhw_COS_Reset_Guard::init();
		delete_option( 'yoohw_cos_privacy_suppression_secret' );
	}

	public function test_snapshot_reads_actual_counts_without_writing_options_or_cron(): void {
		global $wpdb;
		$id = YoOhw_COS_Customers::create_customer( array( 'email' => 'diagnostic@example.test' ) );
		$this->assertGreaterThan( 0, $id );
		$task = YoOhw_COS_Tasks::create_task( array( 'customer_id' => $id, 'title' => 'Synthetic task', 'due_date' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
		$this->assertGreaterThan( 0, $task );
		update_option( 'yoohw_cos_sync_state', array( 'status' => 'in_progress', 'total_scanned' => 12, 'total_processed' => 10, 'total_retryable' => 1, 'total_unresolved' => 1, 'has_more' => 1 ), false );
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
}
