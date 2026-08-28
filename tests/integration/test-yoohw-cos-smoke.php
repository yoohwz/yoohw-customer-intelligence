<?php

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WP_UnitTestCase' ) ) {
	return;
}

/**
 * @group yoohw-customer-intelligence
 */
final class YoOhw_COS_Integration_Smoke_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		$this->load_plugin_classes();
	}

	public function tear_down(): void {
		parent::tear_down();

		$this->clean_test_plugin_data();
		delete_option( 'yoohw_cos_scoring_settings' );
	}

	public function test_install_creates_expected_custom_tables(): void {
		global $wpdb;

		foreach ( YoOhw_COS_Install::expected_table_keys() as $table_key ) {
			$table = YoOhw_COS_DB::table( $table_key );
			$found = $wpdb->get_var(
				$wpdb->prepare(
					'SHOW TABLES LIKE %s',
					$table
				)
			);

			$this->assertSame( $table, $found, "Missing customer table: {$table_key}" );
		}
	}

	public function test_customer_partial_update_keeps_format_mapping_stable(): void {
		$customer_id = YoOhw_COS_Customers::create_customer(
			array(
				'email'          => 'format-check@example.test',
				'display_name'   => 'Format check',
				'total_orders'   => 1,
				'total_spent'    => 25.50,
				'trust_score'    => 50,
				'risk_score'     => 10,
				'customer_status' => 'active',
				'vip_status'     => 'none',
			)
		);

		$this->assertGreaterThan( 0, $customer_id );

		YoOhw_COS_Customers::update_customer(
			$customer_id,
			array(
				'vip_status'  => 'gold',
				'trust_score' => 75.25,
				'risk_score'  => 5,
			)
		);

		$customer = YoOhw_COS_Customers::get_customer( $customer_id );

		$this->assertSame( 'gold', $customer['vip_status'] );
		$this->assertSame( 75.25, (float) $customer['trust_score'] );
		$this->assertSame( 5.0, (float) $customer['risk_score'] );
	}

	public function test_reset_clears_customer_relationship_tables(): void {
		global $wpdb;

		$customer_id = YoOhw_COS_Customers::create_customer(
			array(
				'email'        => 'reset-check@example.test',
				'display_name' => 'Reset check',
			)
		);
		$tag_id      = YoOhw_COS_Tags::create_tag( 'Reset check tag' );
		$segment_id  = YoOhw_COS_Segments::create_segment( 'Reset check segment' );

		YoOhw_COS_Tags::assign_tag( $customer_id, $tag_id, 0, false );
		YoOhw_COS_Segments::assign_customer( $customer_id, $segment_id, 0, false );
		$wpdb->insert(
			YoOhw_COS_DB::tasks_table(),
			array(
				'customer_id' => $customer_id,
				'title'       => 'Reset check follow-up',
				'status'      => 'open',
				'priority'    => 'normal',
				'created_at'  => YoOhw_COS_DB::now(),
				'updated_at'  => YoOhw_COS_DB::now(),
			),
			array(
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
			)
		);

		YoOhw_COS_Customers::reset_data();

		$this->assertSame(
			0,
			(int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i', YoOhw_COS_DB::tasks_table() )
			)
		);
		$this->assertSame(
			0,
			(int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i', YoOhw_COS_DB::customer_tags_table() )
			)
		);
		$this->assertSame(
			0,
			(int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i', YoOhw_COS_DB::customer_segments_table() )
			)
		);
	}

	public function test_archive_hides_customer_from_default_query_and_restore_returns_it(): void {
		$customer_id = YoOhw_COS_Customers::create_customer(
			array(
				'email'          => 'archive-check@example.test',
				'display_name'   => 'Archive check',
				'customer_status' => 'active',
			)
		);

		$this->assertGreaterThan( 0, $customer_id );
		$this->assertTrue( YoOhw_COS_Customers::archive_customer( $customer_id ) );

		$default_query = YoOhw_COS_Customer_Query::query(
			array(
				's'        => 'archive-check@example.test',
				'per_page' => 10,
			)
		);
		$archive_query = YoOhw_COS_Customer_Query::query(
			array(
				's'             => 'archive-check@example.test',
				'customer_view' => 'archived',
				'per_page'      => 10,
			)
		);

		$this->assertSame( 0, (int) $default_query['total_items'] );
		$this->assertSame( 1, (int) $archive_query['total_items'] );

		$this->assertTrue( YoOhw_COS_Customers::restore_customer( $customer_id ) );

		$restored_query = YoOhw_COS_Customer_Query::query(
			array(
				's'        => 'archive-check@example.test',
				'per_page' => 10,
			)
		);

		$this->assertSame( 1, (int) $restored_query['total_items'] );
	}

	public function test_order_sync_uses_woocommerce_crud_when_available(): void {
		$order = wc_create_order();
		$order->set_billing_email( 'sync-check@example.test' );
		$order->set_billing_phone( '555-0100' );
		$order->set_billing_first_name( 'Sync' );
		$order->set_billing_last_name( 'Check' );
		$order->set_total( '19.99' );
		$order->set_status( 'processing' );
		$order->save();

		$customer_id = YoOhw_COS_Customers::sync_from_order( $order );
		$customer    = YoOhw_COS_Customers::get_customer( $customer_id );

		$this->assertGreaterThan( 0, $customer_id );
		$this->assertSame( 'sync-check@example.test', $customer['email'] );
		$this->assertSame( absint( $order->get_id() ), absint( $customer['last_order_id'] ) );
		$this->assertSame(
			$order->get_date_created()->date( 'Y-m-d H:i:s' ),
			$customer['last_activity_date']
		);
	}

	public function test_commerce_metrics_use_one_paid_population_and_are_idempotent(): void {
		$email = 'commerce-policy@example.test';
		$processing = $this->create_order( $email, 'processing', '100.00' );
		$failed     = $this->create_order( $email, 'failed', '500.00' );
		$completed  = $this->create_order( $email, 'completed', '50.00' );

		$customer_id = YoOhw_COS_Customers::sync_from_order( $processing );
		YoOhw_COS_Customers::sync_from_order( $processing );
		YoOhw_COS_Customers::sync_from_order( $failed );
		YoOhw_COS_Customers::sync_from_order( $completed );

		$customer = YoOhw_COS_Customers::get_customer( $customer_id );
		$this->assertSame( 2, absint( $customer['total_orders'] ) );
		$this->assertSame( 150.0, (float) $customer['total_spent'] );
		$this->assertSame( 75.0, (float) $customer['average_order_value'] );

		$processing->set_status( 'cancelled' );
		$processing->save();
		YoOhw_COS_Customers::sync_from_order( $processing );
		$customer = YoOhw_COS_Customers::get_customer( $customer_id );

		$this->assertSame( 1, absint( $customer['total_orders'] ) );
		$this->assertSame( 50.0, (float) $customer['total_spent'] );
		$this->assertSame( 50.0, (float) $customer['average_order_value'] );
	}

	public function test_order_fact_moves_contribution_when_customer_is_reassigned(): void {
		$settings = YoOhw_COS_Intelligence::get_scoring_settings_defaults();
		$settings['customer_status']['new_max_orders'] = 0;
		YoOhw_COS_Intelligence::update_scoring_settings( $settings );
		$order = $this->create_order( 'reassign-one@example.test', 'processing', '6000.00' );
		$first_id = YoOhw_COS_Customers::sync_from_order( $order );
		$first_before = YoOhw_COS_Customers::get_customer( $first_id );
		$this->assertSame( 'platinum', $first_before['vip_status'] );
		$this->assertSame( 'vip', $first_before['customer_status'] );
		$this->assertSame( 'vip', $first_before['lifecycle_stage'] );
		$this->assertSame( 70.0, (float) $first_before['trust_score'] );
		$second_id = YoOhw_COS_Customers::create_customer(
			array( 'email' => 'reassign-two@example.test', 'display_name' => 'Second customer' )
		);

		$order->update_meta_data( YoOhw_COS_Customers::ORDER_CUSTOMER_META_KEY, $second_id );
		$order->save();
		$this->assertSame( $second_id, YoOhw_COS_Customers::sync_from_order( $order ) );

		$first  = YoOhw_COS_Customers::get_customer( $first_id );
		$second = YoOhw_COS_Customers::get_customer( $second_id );
		$this->assertSame( 0, absint( $first['total_orders'] ) );
		$this->assertSame( 0.0, (float) $first['total_spent'] );
		$this->assertSame( 1, absint( $second['total_orders'] ) );
		$this->assertSame( 6000.0, (float) $second['total_spent'] );
		$this->assertSame( 'none', $first['vip_status'] );
		$this->assertSame( 'inactive', $first['customer_status'] );
		$this->assertSame( 'dormant', $first['lifecycle_stage'] );
		$this->assertSame( 50.0, (float) $first['trust_score'] );
		$this->assertSame( 'platinum', $second['vip_status'] );
		$this->assertSame( 'vip', $second['customer_status'] );
		$this->assertSame( 'vip', $second['lifecycle_stage'] );
		$this->assertSame( 70.0, (float) $second['trust_score'] );
	}

	public function test_identity_normalization_precedence_and_ambiguous_phone(): void {
		$wp_user_id = self::factory()->user->create( array( 'user_email' => 'identity-user@example.test' ) );
		$user_customer_id = YoOhw_COS_Customers::create_customer(
			array( 'wp_user_id' => $wp_user_id, 'email' => 'identity-user@example.test', 'display_name' => 'WP identity' )
		);
		$this->assertSame(
			$user_customer_id,
			YoOhw_COS_Customers::find_customer_id( array( 'wp_user_id' => $wp_user_id ) )
		);

		$first_id = YoOhw_COS_Customers::create_customer(
			array( 'email' => 'Case@Test.Example', 'phone' => '+1 (415) 555-0100', 'display_name' => 'First' )
		);
		$this->assertSame(
			$first_id,
			YoOhw_COS_Customers::find_customer_id( array( 'email' => 'case@test.example' ) )
		);
		$this->assertSame(
			$first_id,
			YoOhw_COS_Customers::find_customer_id( array( 'phone' => '+1 415 555 0100' ) )
		);

		YoOhw_COS_Customers::create_customer(
			array( 'email' => 'shared-phone@example.test', 'phone' => '+14155550100', 'display_name' => 'Second' )
		);
		$this->assertSame( 0, YoOhw_COS_Customers::find_customer_id( array( 'phone' => '001 415 555 0100' ) ) );

		$result = YoOhw_COS_Customer_Identity::resolve(
			array(
				'customer_id' => $first_id,
				'email'       => 'shared-phone@example.test',
			)
		);
		$this->assertSame( $first_id, absint( $result['customer_id'] ) );
		$this->assertSame( 'customer_id', $result['matched_by'] );
		$this->assertNotEmpty( $result['conflicts'] );
	}

	public function test_guest_email_change_keeps_explicit_profile_link(): void {
		$order = $this->create_order( 'guest-original@example.test', 'processing', '20.00' );
		$customer_id = YoOhw_COS_Customers::sync_from_order( $order );
		$order->set_billing_email( 'guest-changed@example.test' );
		$order->save();

		$this->assertSame( $customer_id, YoOhw_COS_Customers::sync_from_order( $order ) );
		$customer = YoOhw_COS_Customers::get_customer( $customer_id );
		$this->assertSame( 1, absint( $customer['total_orders'] ) );
		$this->assertSame( 'guest-changed@example.test', $customer['email'] );
	}

	public function test_conflicting_order_identity_is_recorded_without_overwrite(): void {
		global $wpdb;
		$user_id = self::factory()->user->create( array( 'user_email' => 'registered-owner@example.test' ) );
		$owner_id = YoOhw_COS_Customers::create_customer(
			array( 'wp_user_id' => $user_id, 'email' => 'registered-owner@example.test', 'display_name' => 'Registered owner' )
		);
		$other_id = YoOhw_COS_Customers::create_customer(
			array( 'email' => 'claimed-email@example.test', 'display_name' => 'Email owner' )
		);
		$order = $this->create_order( 'claimed-email@example.test', 'processing', '25.00' );
		$order = wc_get_order( $order->get_id() );
		$order->delete_meta_data( YoOhw_COS_Customers::ORDER_CUSTOMER_META_KEY );
		$order->set_customer_id( $user_id );
		$order->save();

		$this->assertSame( $owner_id, YoOhw_COS_Customers::sync_from_order( $order ) );
		$this->assertSame( 'registered-owner@example.test', YoOhw_COS_Customers::get_customer( $owner_id )['email'] );
		$this->assertSame( 'claimed-email@example.test', YoOhw_COS_Customers::get_customer( $other_id )['email'] );
		$this->assertSame(
			1,
			(int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i WHERE event_type = 'customer_identity_conflict' AND object_id = %d",
					YoOhw_COS_DB::events_table(),
					$order->get_id()
				)
			)
		);
	}

	public function test_guest_profile_safely_adopts_unowned_wp_user(): void {
		$order       = $this->create_order( 'guest-register@example.test', 'processing', '30.00' );
		$customer_id = YoOhw_COS_Customers::sync_from_order( $order );
		$user_id     = self::factory()->user->create( array( 'user_email' => 'registered-new@example.test' ) );
		$order->set_customer_id( $user_id );
		$order->set_billing_email( 'registered-new@example.test' );
		$order->save();

		$this->assertSame( $customer_id, YoOhw_COS_Customers::sync_from_order( $order ) );
		$customer = YoOhw_COS_Customers::get_customer( $customer_id );
		$this->assertSame( $user_id, absint( $customer['wp_user_id'] ) );
		$this->assertSame( 'registered-new@example.test', $customer['email'] );
	}

	public function test_persisted_explicit_link_wins_over_stale_order_object_meta(): void {
		$order = $this->create_order( 'stale-link@example.test', 'completed', '40.00' );
		$first_id = YoOhw_COS_Customers::sync_from_order( $order );
		$stale_order = wc_get_order( $order->get_id() );
		$second_id = YoOhw_COS_Customers::create_customer(
			array( 'email' => 'stale-link-new@example.test', 'display_name' => 'Persisted target' )
		);

		$order->read_meta_data( true );
		$order->update_meta_data( YoOhw_COS_Customers::ORDER_CUSTOMER_META_KEY, $second_id );
		$order->save();

		$this->assertSame( $second_id, YoOhw_COS_Customers::sync_from_order( $stale_order ) );
		$this->assertSame( 0, absint( YoOhw_COS_Customers::get_customer( $first_id )['total_orders'] ) );
		$this->assertSame( 1, absint( YoOhw_COS_Customers::get_customer( $second_id )['total_orders'] ) );
	}

	public function test_aggregate_rebuild_matches_incremental_facts(): void {
		$order = $this->create_order( 'rebuild@example.test', 'completed', '44.00' );
		$customer_id = YoOhw_COS_Customers::sync_from_order( $order );
		YoOhw_COS_Customers::update_customer(
			$customer_id,
			array(
				'total_orders' => 99,
				'total_spent' => 9999,
				'average_order_value' => 101,
				'vip_status' => 'platinum',
				'customer_status' => 'vip',
				'lifecycle_stage' => 'vip',
				'trust_score' => 100,
			)
		);

		$this->assertTrue( YoOhw_COS_Commerce_Aggregates::rebuild_customer( $customer_id ) );
		$customer = YoOhw_COS_Customers::get_customer( $customer_id );
		$this->assertSame( 1, absint( $customer['total_orders'] ) );
		$this->assertSame( 44.0, (float) $customer['total_spent'] );
		$this->assertSame( 44.0, (float) $customer['average_order_value'] );
		$this->assertSame( 'none', $customer['vip_status'] );
		$this->assertSame( 'new', $customer['customer_status'] );
		$this->assertSame( 'new', $customer['lifecycle_stage'] );
		$this->assertSame( 50.0, (float) $customer['trust_score'] );
	}

	public function test_refunded_status_reverses_the_order_population(): void {
		$order = $this->create_order( 'refunded@example.test', 'completed', '60.00' );
		$customer_id = YoOhw_COS_Customers::sync_from_order( $order );
		$order->set_status( 'refunded' );
		$order->save();
		YoOhw_COS_Customers::sync_from_order( $order );
		$customer = YoOhw_COS_Customers::get_customer( $customer_id );

		$this->assertSame( 0, absint( $customer['total_orders'] ) );
		$this->assertSame( 0.0, (float) $customer['total_spent'] );
		$this->assertSame( 0.0, (float) $customer['average_order_value'] );
	}

	public function test_partial_refund_reduces_net_revenue_without_changing_count(): void {
		$order = $this->create_order( 'partial-refund@example.test', 'completed', '100.00' );
		$customer_id = YoOhw_COS_Customers::sync_from_order( $order );
		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => 25,
				'reason'   => 'Architecture test',
			)
		);

		$this->assertFalse( is_wp_error( $refund ) );
		YoOhw_COS_Customers::sync_from_order( wc_get_order( $order->get_id() ) );
		$customer = YoOhw_COS_Customers::get_customer( $customer_id );
		$this->assertSame( 1, absint( $customer['total_orders'] ) );
		$this->assertSame( 75.0, (float) $customer['total_spent'] );
		$this->assertSame( 75.0, (float) $customer['average_order_value'] );
	}

	public function test_deleted_order_removes_its_persisted_contribution(): void {
		$order = $this->create_order( 'delete-order@example.test', 'completed', '6000.00' );
		$customer_id = YoOhw_COS_Customers::sync_from_order( $order );
		YoOhw_COS_Customers::remove_deleted_order_contribution( $order->get_id(), $order );
		$customer = YoOhw_COS_Customers::get_customer( $customer_id );

		$this->assertSame( 0, absint( $customer['total_orders'] ) );
		$this->assertSame( 0.0, (float) $customer['total_spent'] );
		$this->assertSame( 0.0, (float) $customer['average_order_value'] );
		$this->assertSame( 'none', $customer['vip_status'] );
		$this->assertSame( 'inactive', $customer['customer_status'] );
		$this->assertSame( 'dormant', $customer['lifecycle_stage'] );
		$this->assertSame( 50.0, (float) $customer['trust_score'] );
	}

	public function test_event_key_and_notification_claims_are_atomic(): void {
		global $wpdb;

		$key = YoOhw_COS_Events::make_event_key( 'test', 'delivered', 'external', 123, 0 );
		$first = YoOhw_COS_Events::record(
			array( 'event_key' => $key, 'event_type' => 'delivered', 'event_source' => 'test' )
		);
		$second = YoOhw_COS_Events::record(
			array( 'event_key' => $key, 'event_type' => 'delivered', 'event_source' => 'test' )
		);
		$this->assertGreaterThan( 0, $first );
		$this->assertSame( $first, $second );

		$notification_key = YoOhw_COS_Notification_Ledger::key( 'retry-test', array( 'id' => 123 ), 7 );
		$this->assertTrue( YoOhw_COS_Notification_Ledger::claim( $notification_key, 'retry-test', 123, 7 ) );
		$this->assertFalse( YoOhw_COS_Notification_Ledger::claim( $notification_key, 'retry-test', 123, 7 ) );
		$wpdb->update(
			YoOhw_COS_DB::notification_log_table(),
			array( 'lease_until' => '2000-01-01 00:00:00' ),
			array( 'notification_key' => $notification_key ),
			array( '%s' ),
			array( '%s' )
		);
		$this->assertTrue( YoOhw_COS_Notification_Ledger::claim( $notification_key, 'retry-test', 123, 7 ) );
		YoOhw_COS_Notification_Ledger::mark_sent( $notification_key );
		$this->assertFalse( YoOhw_COS_Notification_Ledger::claim( $notification_key, 'retry-test', 123, 7 ) );
		$claim = $wpdb->get_row(
			$wpdb->prepare( 'SELECT status, attempts FROM %i WHERE notification_key = %s', YoOhw_COS_DB::notification_log_table(), $notification_key ),
			ARRAY_A
		);
		$this->assertSame( 'sent', $claim['status'] );
		$this->assertSame( 2, absint( $claim['attempts'] ) );
	}

	public function test_notification_lease_uses_site_local_clock_across_timezones(): void {
		global $wpdb;

		$original_timezone = get_option( 'timezone_string', '' );
		$original_offset   = get_option( 'gmt_offset', 0 );

		try {
			foreach ( array( 'UTC', 'Asia/Ho_Chi_Minh', 'America/New_York' ) as $timezone ) {
				update_option( 'timezone_string', $timezone );
				$before = current_datetime();
				$key = YoOhw_COS_Notification_Ledger::key( 'timezone-lease-' . sanitize_key( $timezone ), array( 'id' => 9001 ), 7 );

				$this->assertTrue( YoOhw_COS_Notification_Ledger::claim( $key, 'timezone-lease', 9001, 7 ) );
				$claim = $wpdb->get_row(
					$wpdb->prepare(
						'SELECT claim_token, lease_until, attempts FROM %i WHERE notification_key = %s',
						YoOhw_COS_DB::notification_log_table(),
						$key
					),
					ARRAY_A
				);
				$lease = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $claim['lease_until'], wp_timezone() );
				$this->assertInstanceOf( DateTimeImmutable::class, $lease );
				$this->assertGreaterThanOrEqual( 14 * MINUTE_IN_SECONDS, $lease->getTimestamp() - $before->getTimestamp() );
				$this->assertLessThanOrEqual( 16 * MINUTE_IN_SECONDS, $lease->getTimestamp() - $before->getTimestamp() );
				$this->assertFalse( YoOhw_COS_Notification_Ledger::claim( $key, 'timezone-lease', 9001, 7 ) );

				$old_token = (string) $claim['claim_token'];
				$wpdb->update(
					YoOhw_COS_DB::notification_log_table(),
					array( 'lease_until' => current_datetime()->modify( '-1 minute' )->format( 'Y-m-d H:i:s' ) ),
					array( 'notification_key' => $key ),
					array( '%s' ),
					array( '%s' )
				);
				$this->assertTrue( YoOhw_COS_Notification_Ledger::claim( $key, 'timezone-lease', 9001, 7 ) );
				$reclaimed = $wpdb->get_row(
					$wpdb->prepare(
						'SELECT claim_token, attempts FROM %i WHERE notification_key = %s',
						YoOhw_COS_DB::notification_log_table(),
						$key
					),
					ARRAY_A
				);
				$this->assertNotSame( $old_token, (string) $reclaimed['claim_token'] );
				$this->assertSame( 2, absint( $reclaimed['attempts'] ) );
				YoOhw_COS_Notification_Ledger::mark_sent( $key );
				$this->assertFalse( YoOhw_COS_Notification_Ledger::claim( $key, 'timezone-lease', 9001, 7 ) );
			}
		} finally {
			update_option( 'timezone_string', $original_timezone );
			update_option( 'gmt_offset', $original_offset );
		}
	}

	public function test_notification_windows_use_site_local_day_and_time(): void {
		$original_timezone = get_option( 'timezone_string', '' );
		$original_offset   = get_option( 'gmt_offset', 0 );

		try {
			update_option( 'timezone_string', 'Asia/Ho_Chi_Minh' );
			$now = current_datetime();
			$customer_id = YoOhw_COS_Customers::create_customer(
				array( 'email' => 'timezone-window@example.test', 'display_name' => 'Timezone window' )
			);
			$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
			$inside_id = $this->insert_timezone_task( $customer_id, $user_id, 'Inside due-soon window', $now->modify( '+30 minutes' ) );
			$outside_id = $this->insert_timezone_task( $customer_id, $user_id, 'Outside due-soon window', $now->modify( '+2 hours' ) );
			$due_soon_method = new ReflectionMethod( 'YoOhw_COS_Email_Notifications', 'get_open_tasks_due_soon' );
			$due_soon_method->setAccessible( true );
			$due_soon_ids = array_map( 'absint', wp_list_pluck( $due_soon_method->invoke( null, 1, 0 ), 'id' ) );
			$this->assertContains( $inside_id, $due_soon_ids );
			$this->assertNotContains( $outside_id, $due_soon_ids );

			$overdue_id = $this->insert_timezone_task( $customer_id, $user_id, 'Locally overdue', $now->modify( '-1 minute' ) );
			$future_id = $this->insert_timezone_task( $customer_id, $user_id, 'Locally future', $now->modify( '+1 minute' ) );
			$overdue_method = new ReflectionMethod( 'YoOhw_COS_Email_Notifications', 'get_open_overdue_task_groups' );
			$overdue_method->setAccessible( true );
			$overdue = $overdue_method->invoke( null, 0, array() );
			$overdue_ids = array_map( 'absint', wp_list_pluck( $overdue['groups'][ $user_id ] ?? array(), 'id' ) );
			$this->assertContains( $overdue_id, $overdue_ids );
			$this->assertNotContains( $future_id, $overdue_ids );

			$today_id = $this->insert_timezone_task( $customer_id, $user_id, 'Local today boundary', $now->setTime( 23, 30, 0 ) );
			$tomorrow_id = $this->insert_timezone_task( $customer_id, $user_id, 'Local tomorrow boundary', $now->modify( '+1 day' )->setTime( 0, 30, 0 ) );
			$summary_method = new ReflectionMethod( 'YoOhw_COS_Email_Notifications', 'get_daily_summary_task_groups' );
			$summary_method->setAccessible( true );
			$summary = $summary_method->invoke( null, array() );
			$summary_ids = array_map( 'absint', wp_list_pluck( $summary['groups'][ $user_id ] ?? array(), 'id' ) );
			$this->assertContains( $today_id, $summary_ids );
			$this->assertNotContains( $tomorrow_id, $summary_ids );

			$marker_method = new ReflectionMethod( 'YoOhw_COS_Email_Notifications', 'get_digest_chunk_marker' );
			$marker_method->setAccessible( true );
			$marker = $marker_method->invoke( null, 'timezone-marker', $user_id, array( array( 'id' => $today_id ) ) );
			$this->assertStringContainsString( '_' . current_datetime()->format( 'Ymd' ) . '_', $marker );
		} finally {
			update_option( 'timezone_string', $original_timezone );
			update_option( 'gmt_offset', $original_offset );
		}
	}

	public function test_requested_woocommerce_order_storage_mode_is_active(): void {
		$requested = strtolower( (string) getenv( 'WC_HPOS_ENABLED' ) );
		$this->assertContains( $requested, array( 'yes', 'no' ) );
		$this->assertSame(
			'yes' === $requested,
			\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
		);
	}

	public function test_legacy_event_rows_adopt_deterministic_keys_without_duplicates(): void {
		global $wpdb;
		$customer_id = YoOhw_COS_Customers::create_customer(
			array( 'email' => 'legacy-events@example.test', 'display_name' => 'Legacy events' )
		);
		$cases = array(
			array( 'source' => 'woocommerce', 'type' => 'order_synced', 'object_type' => 'order', 'object_id' => 12345 ),
			array( 'source' => 'wc_loyalty', 'type' => 'loyalty_points_rewarded', 'object_type' => 'loyalty_points_log', 'object_id' => 67890 ),
		);

		foreach ( $cases as $case ) {
			$wpdb->insert(
				YoOhw_COS_DB::events_table(),
				array(
					'customer_id' => $customer_id,
					'event_type' => $case['type'],
					'event_source' => $case['source'],
					'severity' => 'info',
					'object_type' => $case['object_type'],
					'object_id' => $case['object_id'],
					'created_at' => YoOhw_COS_DB::now(),
				),
				array( '%d', '%s', '%s', '%s', '%s', '%d', '%s' )
			);
			$legacy_id = absint( $wpdb->insert_id );
			$key = YoOhw_COS_Events::make_event_key( $case['source'], $case['type'], $case['object_type'], $case['object_id'], $customer_id );
			$args = array(
				'event_key' => $key,
				'customer_id' => $customer_id,
				'event_type' => $case['type'],
				'event_source' => $case['source'],
				'object_type' => $case['object_type'],
				'object_id' => $case['object_id'],
			);

			$this->assertSame( $legacy_id, YoOhw_COS_Events::record( $args ) );
			$this->assertSame( $legacy_id, YoOhw_COS_Events::record( $args ) );
			$this->assertSame(
				1,
				(int) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COUNT(*) FROM %i WHERE event_type = %s AND object_type = %s AND object_id = %d AND customer_id = %d',
						YoOhw_COS_DB::events_table(),
						$case['type'],
						$case['object_type'],
						$case['object_id'],
						$customer_id
					)
				)
			);
		}
	}

	public function test_upgrade_from_0110_registers_resumable_data_migrations(): void {
		$preserved_customer_id = YoOhw_COS_Customers::create_customer(
			array( 'email' => 'migration-preserved@example.test', 'display_name' => 'Preserved migration row' )
		);
		update_option( 'yoohw_cos_db_version', '0.1.10' );
		delete_option( 'yoohw_cos_data_migrations' );
		YoOhw_COS_Install::maybe_update();

		$state = YoOhw_COS_Migration_Runner::get_state();
		$this->assertSame( '0.2.1', get_option( 'yoohw_cos_db_version' ) );
		$this->assertSame( 'pending', $state['identity_normalization_v2']['status'] );
		$this->assertSame( 'pending', $state['commerce_facts_v2']['status'] );
		$this->assertSame(
			'migration-preserved@example.test',
			YoOhw_COS_Customers::get_customer( $preserved_customer_id )['email']
		);

		$state['commerce_facts_v2']['status'] = 'pending';
		$state['identity_normalization_v2']['status'] = 'pending';
		$state['identity_normalization_v2']['phase'] = 'scan';
		$state['identity_normalization_v2']['last_customer_id'] = 0;
		update_option( 'yoohw_cos_data_migrations', $state, false );
		delete_option( 'yoohw_cos_data_migration_lock' );
		YoOhw_COS_Migration_Runner::run_next_batch();
		$resumed = YoOhw_COS_Migration_Runner::get_state();

		$this->assertSame( 'retries', $resumed['identity_normalization_v2']['phase'] );
		YoOhw_COS_Migration_Runner::run_next_batch();
		$resumed = YoOhw_COS_Migration_Runner::get_state();
		$this->assertSame( 'completed', $resumed['identity_normalization_v2']['status'] );
		$completed_at = $resumed['identity_normalization_v2']['completed_at'];
		YoOhw_COS_Migration_Runner::run_next_batch();
		$this->assertSame( $completed_at, YoOhw_COS_Migration_Runner::get_state()['identity_normalization_v2']['completed_at'] );
	}

	public function test_commerce_migration_retries_failures_and_accounts_for_unresolved_orders(): void {
		global $wpdb;
		$retry_order = $this->create_order( 'migration-retry@example.test', 'processing', '31.00' );
		YoOhw_COS_Customers::create_customer( array( 'email' => 'ambiguous-one@example.test', 'phone' => '+14155550177', 'display_name' => 'Ambiguous one' ) );
		YoOhw_COS_Customers::create_customer( array( 'email' => 'ambiguous-two@example.test', 'phone' => '+14155550177', 'display_name' => 'Ambiguous two' ) );
		$unresolved_order = wc_create_order();
		$unresolved_order->set_billing_phone( '+1 (415) 555-0177' );
		$unresolved_order->set_total( '32.00' );
		$unresolved_order->set_status( 'processing' );
		$unresolved_order->save();
		$attempts         = 0;
		$filter = static function( array $outcome, int $order_id ) use ( $retry_order, &$attempts ): array {
			if ( $order_id === $retry_order->get_id() && $attempts++ < 1 ) {
				return array( 'status' => 'retry', 'code' => 'transient_test_failure' );
			}

			return $outcome;
		};

		add_filter( 'yoohw_cos_migration_order_sync_outcome', $filter, 10, 2 );
		delete_option( 'yoohw_cos_data_migrations' );
		delete_option( 'yoohw_cos_data_migration_lock' );
		YoOhw_COS_Migration_Runner::register_upgrade( '0.2.0', '0.2.1' );
		$state = YoOhw_COS_Migration_Runner::get_state();
		$state['identity_normalization_v2']['status'] = 'completed';
		update_option( 'yoohw_cos_data_migrations', $state, false );

		try {
			for ( $batch = 0; $batch < 12; $batch++ ) {
				delete_option( 'yoohw_cos_data_migration_lock' );
				YoOhw_COS_Migration_Runner::run_next_batch();
				$current = YoOhw_COS_Migration_Runner::get_state()['commerce_facts_v2'];

				if ( in_array( $current['status'], array( 'completed', 'completed_with_issues' ), true ) ) {
					break;
				}
			}
		} finally {
			remove_filter( 'yoohw_cos_migration_order_sync_outcome', $filter, 10 );
		}

		$migration = YoOhw_COS_Migration_Runner::get_state()['commerce_facts_v2'];
		$this->assertSame( 'completed_with_issues', $migration['status'] );
		$this->assertGreaterThanOrEqual( 1, absint( $migration['unresolved_issues'] ) );
		$this->assertSame(
			1,
			(int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE order_id = %d', YoOhw_COS_DB::order_facts_table(), $retry_order->get_id() )
			)
		);
		$this->assertSame(
			'resolved',
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT status FROM %i WHERE migration_id = 'commerce_facts_v2' AND object_type = 'order' AND object_id = %d",
					YoOhw_COS_DB::migration_issues_table(),
					$retry_order->get_id()
				)
			)
		);
		$this->assertSame(
			'unresolved',
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT status FROM %i WHERE migration_id = 'commerce_facts_v2' AND object_type = 'order' AND object_id = %d",
					YoOhw_COS_DB::migration_issues_table(),
					$unresolved_order->get_id()
				)
			)
		);
	}

	public function test_order_admin_customer_filter_uses_query_meta_without_materializing_ids(): void {
		if ( ! class_exists( 'YoOhw_COS_Order_Admin' ) ) {
			require_once dirname( __DIR__, 2 ) . '/admin/class-yoohw-cos-order-admin.php';
		}

		$customer_id = YoOhw_COS_Customers::create_customer(
			array( 'email' => 'filter-query@example.test', 'display_name' => 'Filter query' )
		);
		$_GET['yoohw_cos_customer_id'] = (string) $customer_id;

		try {
			$args = YoOhw_COS_Order_Admin::filter_order_list_query_args(
				array( 'status' => array( 'wc-processing' ) )
			);
		} finally {
			unset( $_GET['yoohw_cos_customer_id'] );
		}

		$this->assertArrayHasKey( 'meta_query', $args );
		$this->assertArrayNotHasKey( 'post__in', $args );
		$this->assertSame( YoOhw_COS_Customers::ORDER_CUSTOMER_META_KEY, $args['meta_query'][0]['key'] );
		$this->assertSame( (string) $customer_id, $args['meta_query'][0]['value'] );
	}

	public function test_notification_task_query_is_bounded(): void {
		global $wpdb;

		$customer_id = YoOhw_COS_Customers::create_customer(
			array( 'email' => 'bounded-tasks@example.test', 'display_name' => 'Bounded tasks' )
		);
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		for ( $index = 0; $index < 205; $index++ ) {
			$wpdb->insert(
				YoOhw_COS_DB::tasks_table(),
				array(
					'customer_id'     => $customer_id,
					'assigned_user_id' => $user_id,
					'title'           => 'Bounded task ' . $index,
					'status'          => 'open',
					'priority'        => 'normal',
					'due_date'        => '2030-01-01 12:00:00',
					'created_at'      => YoOhw_COS_DB::now(),
					'updated_at'      => YoOhw_COS_DB::now(),
				),
				array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}

		$method = new ReflectionMethod( 'YoOhw_COS_Email_Notifications', 'get_open_tasks_by_date_window' );
		$method->setAccessible( true );
		$tasks = $method->invoke( null, '2029-12-31 00:00:00', '2030-01-02 00:00:00', 0 );
		$this->assertCount( 200, $tasks );
		$remaining = $method->invoke( null, '2029-12-31 00:00:00', '2030-01-02 00:00:00', absint( end( $tasks )['id'] ) );
		$this->assertCount( 5, $remaining );

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET due_date = %s, priority = %s WHERE assigned_user_id = %d',
				YoOhw_COS_DB::tasks_table(),
				'2020-01-01 00:00:00',
				'high',
				$user_id
			)
		);

		$overdue_method = new ReflectionMethod( 'YoOhw_COS_Email_Notifications', 'get_open_overdue_task_groups' );
		$overdue_method->setAccessible( true );
		$overdue_ids = array();
		$cursor = array();

		do {
			$overdue = $overdue_method->invoke( null, 0, $cursor );
			foreach ( (array) ( $overdue['groups'][ $user_id ] ?? array() ) as $task ) {
				$overdue_ids[] = absint( $task['id'] );
			}
			$cursor = $overdue['cursor'];
		} while ( ! empty( $overdue['has_more'] ) );

		$this->assertCount( 205, array_unique( $overdue_ids ) );

		$escalation_ids = array();
		$cursor = array();

		do {
			$escalation = $overdue_method->invoke( null, 3, $cursor );
			foreach ( (array) ( $escalation['groups'][ $user_id ] ?? array() ) as $task ) {
				$escalation_ids[] = absint( $task['id'] );
			}
			$cursor = $escalation['cursor'];
		} while ( ! empty( $escalation['has_more'] ) );

		$this->assertSame( $overdue_ids, $escalation_ids );

		$summary_method = new ReflectionMethod( 'YoOhw_COS_Email_Notifications', 'get_daily_summary_task_groups' );
		$summary_method->setAccessible( true );
		$summary_ids = array();
		$cursor = array();

		do {
			$summary = $summary_method->invoke( null, $cursor );
			foreach ( (array) ( $summary['groups'][ $user_id ] ?? array() ) as $task ) {
				$summary_ids[] = absint( $task['id'] );
			}
			$cursor = $summary['cursor'];
		} while ( ! empty( $summary['has_more'] ) );

		$this->assertSame( $overdue_ids, $summary_ids );

		$marker_method = new ReflectionMethod( 'YoOhw_COS_Email_Notifications', 'get_digest_chunk_marker' );
		$marker_method->setAccessible( true );
		$first_chunk = array_slice( $tasks, 0, 200 );
		$this->assertSame(
			$marker_method->invoke( null, 'overdue_digest', $user_id, $first_chunk ),
			$marker_method->invoke( null, 'overdue_digest', $user_id, $first_chunk )
		);
		$this->assertNotSame(
			$marker_method->invoke( null, 'overdue_digest', $user_id, $first_chunk ),
			$marker_method->invoke( null, 'overdue_digest', $user_id, $remaining )
		);
	}

	public function test_existing_order_sync_processes_oldest_orders_first(): void {
		global $wpdb;

		$email     = 'sync-order-sequence@example.test';
		$old_order = wc_create_order();
		$old_order->set_billing_email( $email );
		$old_order->set_billing_first_name( 'Sync' );
		$old_order->set_billing_last_name( 'Old' );
		$old_order->set_total( '10.00' );
		$old_order->set_status( 'processing' );
		$old_order->set_date_created( new WC_DateTime( '2024-01-01 00:00:00' ) );
		$old_order->save();

		$new_order = wc_create_order();
		$new_order->set_billing_email( $email );
		$new_order->set_billing_first_name( 'Sync' );
		$new_order->set_billing_last_name( 'New' );
		$new_order->set_total( '20.00' );
		$new_order->set_status( 'processing' );
		$new_order->set_date_created( new WC_DateTime( '2024-02-01 00:00:00' ) );
		$new_order->save();

		$result = YoOhw_COS_Customers::sync_existing_orders( 100, 1 );

		$this->assertGreaterThanOrEqual( 2, absint( $result['scanned'] ) );

		$customer_id = YoOhw_COS_Customers::find_customer_id(
			array(
				'email' => $email,
			)
		);
		$customer    = YoOhw_COS_Customers::get_customer( $customer_id );

		$this->assertGreaterThan( 0, $customer_id );
		$this->assertSame( absint( $old_order->get_id() ), absint( $customer['first_order_id'] ) );
		$this->assertSame( absint( $new_order->get_id() ), absint( $customer['last_order_id'] ) );
		$this->assertSame( '2024-02-01 00:00:00', $customer['last_activity_date'] );

		$synced_order_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT object_id
				FROM %i
				WHERE customer_id = %d
					AND event_type = %s
					AND object_type = %s
				ORDER BY id ASC",
				YoOhw_COS_DB::events_table(),
				$customer_id,
				'order_synced',
				'order'
			)
		);

		$this->assertSame(
			array(
				absint( $old_order->get_id() ),
				absint( $new_order->get_id() ),
			),
			array_map( 'absint', $synced_order_ids )
		);
	}

	public function test_inactive_status_takes_priority_over_value_tier(): void {
		$customer = array(
			'total_orders'       => 20,
			'total_spent'        => 5000,
			'last_order_date'    => date_i18n( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( 120 * DAY_IN_SECONDS ) ),
			'last_activity_date' => '',
		);

		$this->assertSame( 'inactive', YoOhw_COS_Intelligence::calculate_customer_status( $customer ) );
		$this->assertSame( 'platinum', YoOhw_COS_Intelligence::calculate_vip_status( $customer ) );
	}

	public function test_overview_summary_and_action_filters_use_existing_customer_data(): void {
		$repeat_id = YoOhw_COS_Customers::create_customer(
			array(
				'email'             => 'repeat-overview@example.test',
				'phone'             => '555-0110',
				'display_name'      => 'Repeat overview',
				'total_orders'      => 3,
				'total_spent'       => 300,
				'customer_status'   => 'active',
				'vip_status'        => 'silver',
				'lifecycle_stage'   => 'repeat',
				'last_activity_date' => YoOhw_COS_DB::now(),
			)
		);
		$missing_id = YoOhw_COS_Customers::create_customer(
			array(
				'email'             => 'missing-overview@example.test',
				'display_name'      => 'Missing overview',
				'total_orders'      => 1,
				'total_spent'       => 50,
				'customer_status'   => 'new',
				'vip_status'        => 'none',
				'lifecycle_stage'   => 'new',
				'last_activity_date' => YoOhw_COS_DB::now(),
			)
		);

		$this->assertGreaterThan( 0, $repeat_id );
		$this->assertGreaterThan( 0, $missing_id );

		$summary = YoOhw_COS_Overview::get_summary();
		$repeat  = YoOhw_COS_Customer_Query::query(
			array(
				'customer_cohort' => 'repeat',
				'per_page'        => 20,
			)
		);
		$missing = YoOhw_COS_Customer_Query::query(
			array(
				'customer_attention' => 'missing_contact',
				'per_page'           => 20,
			)
		);

		$this->assertSame( 2, absint( $summary['total_customers'] ) );
		$this->assertSame( 1, absint( $summary['repeat_customers'] ) );
		$this->assertSame( 50.0, (float) $summary['repeat_rate'] );
		$this->assertSame( 1, absint( $summary['high_value_customers'] ) );
		$this->assertSame( 1, absint( $repeat['total_items'] ) );
		$this->assertSame( 1, absint( $missing['total_items'] ) );
	}

	public function test_customer_events_filter_source_before_applying_limit(): void {
		$customer_id = YoOhw_COS_Customers::create_customer(
			array(
				'email'              => 'event-source-limit@example.test',
				'phone'              => '555-0199',
				'display_name'       => 'Event source limit',
				'total_orders'       => 2,
				'last_activity_date' => YoOhw_COS_DB::now(),
			)
		);

		$core_event_id = YoOhw_COS_Events::record(
			array(
				'customer_id'  => $customer_id,
				'event_type'   => 'blacklist_blocked',
				'event_source' => 'wc_blacklist_manager',
			)
		);

		for ( $index = 0; $index < 35; $index++ ) {
			YoOhw_COS_Events::record(
				array(
					'customer_id'  => $customer_id,
					'event_type'   => 'order_synced',
					'event_source' => 'woocommerce',
				)
			);
		}

		$events = YoOhw_COS_Events::get_customer_events(
			$customer_id,
			array(
				'limit'        => 30,
				'event_source' => 'wc_blacklist_manager',
			)
		);

		$this->assertCount( 1, $events );
		$this->assertSame( $core_event_id, absint( $events[0]['id'] ) );
	}

	public function test_risk_cache_refresh_uses_same_dynamic_score_as_integrations(): void {
		$customer_id = YoOhw_COS_Customers::create_customer(
			array(
				'email'              => 'risk-cache@example.test',
				'phone'              => '555-0188',
				'display_name'       => 'Risk cache',
				'total_orders'       => 2,
				'total_spent'        => 100,
				'risk_score'         => 0,
				'last_activity_date' => YoOhw_COS_DB::now(),
			)
		);

		YoOhw_COS_Events::record(
			array(
				'customer_id'  => $customer_id,
				'event_type'   => 'premium_payment_abuse_detected',
				'event_source' => 'wc_blacklist_manager_premium',
				'created_at'   => YoOhw_COS_DB::now(),
			)
		);

		add_filter(
			'yoohw_cos_customer_risk_score',
			array( 'YoOhw_COS_Blacklist_Manager_Premium_Integration', 'apply_customer_risk_score' ),
			20,
			2
		);

		try {
			$customer = YoOhw_COS_Customers::get_customer( $customer_id );
			$dynamic  = YoOhw_COS_Intelligence::calculate_risk_score( $customer );

			$this->assertSame( 22.0, $dynamic );

			YoOhw_COS_Customers::refresh_risk_score_cache_batch( 0, 500 );
			$refreshed = YoOhw_COS_Customers::get_customer( $customer_id );

			$this->assertSame( $dynamic, (float) $refreshed['risk_score'] );
			$this->assertSame( $dynamic, YoOhw_COS_Intelligence::get_current_risk_score( $refreshed ) );
		} finally {
			remove_filter(
				'yoohw_cos_customer_risk_score',
				array( 'YoOhw_COS_Blacklist_Manager_Premium_Integration', 'apply_customer_risk_score' ),
				20
			);
		}
	}

	public function test_unlinked_premium_order_event_is_reassociated_after_order_sync(): void {
		global $wpdb;

		$order = wc_create_order();
		$order->set_billing_email( 'reassociate@example.test' );
		$order->set_billing_phone( '555-0177' );
		$order->set_status( 'processing' );
		$order->save();

		$customer_id = YoOhw_COS_Customers::sync_from_order( $order );
		$event_id    = YoOhw_COS_Events::record(
			array(
				'event_type'   => 'premium_antibot_blocked',
				'event_source' => 'wc_blacklist_manager_premium',
				'object_type'  => 'order',
				'object_id'    => $order->get_id(),
				'metadata'     => array( 'order_id' => $order->get_id() ),
			)
		);

		$assigned = YoOhw_COS_Blacklist_Manager_Premium_Integration::reassociate_checkout_events_for_order( $order->get_id() );
		$linked   = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT customer_id FROM %i WHERE id = %d',
				YoOhw_COS_DB::events_table(),
				$event_id
			)
		);

		$this->assertSame( 1, $assigned );
		$this->assertSame( $customer_id, $linked );
	}

	public function test_payment_abuse_hook_records_an_idempotent_customer_event(): void {
		$order = wc_create_order();
		$order->set_billing_email( 'payment-hook@example.test' );
		$order->set_billing_phone( '555-0166' );
		$order->save();
		$customer_id = YoOhw_COS_Customers::sync_from_order( $order );

		add_action(
			'bmp_payment_abuse_event_recorded',
			array( 'YoOhw_COS_Blacklist_Manager_Premium_Integration', 'handle_payment_abuse_event_recorded' ),
			10,
			3
		);

		try {
			$payload = array(
				'created_at'     => YoOhw_COS_DB::now(),
				'order_id'       => $order->get_id(),
				'source'         => 'woocommerce_failed_order',
				'failure_family' => 'card_validation',
			);

			do_action( 'bmp_payment_abuse_event_recorded', 987654, $order, $payload );
			do_action( 'bmp_payment_abuse_event_recorded', 987654, $order, $payload );
		} finally {
			remove_action(
				'bmp_payment_abuse_event_recorded',
				array( 'YoOhw_COS_Blacklist_Manager_Premium_Integration', 'handle_payment_abuse_event_recorded' ),
				10
			);
		}

		$events = YoOhw_COS_Events::get_customer_events(
			$customer_id,
			array( 'event_source' => 'wc_blacklist_manager_premium' )
		);
		$matched = array_filter(
			$events,
			static fn( array $event ): bool => 'premium_payment_abuse_event' === (string) ( $event['object_type'] ?? '' )
				&& 987654 === absint( $event['object_id'] ?? 0 )
		);

		$this->assertCount( 1, $matched );
	}

	public function test_loyalty_backfill_imports_existing_log_with_original_date(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'yo_loyalty_points_log';
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$table} (
				id mediumint(9) NOT NULL AUTO_INCREMENT,
				user_id bigint(20) NOT NULL,
				action varchar(255) NOT NULL,
				order_id bigint(20) NOT NULL,
				amount decimal(10,2) NOT NULL,
				description text NOT NULL,
				date datetime NOT NULL,
				expired_date datetime DEFAULT NULL,
				PRIMARY KEY (id)
			)"
		);

		$user_id = self::factory()->user->create(
			array(
				'user_email' => 'loyalty-backfill@example.test',
				'role'       => 'customer',
			)
		);
		$event_date = '2025-01-02 03:04:05';

		$wpdb->insert(
			$table,
			array(
				'user_id'    => $user_id,
				'action'     => 'order_reward',
				'order_id'   => 0,
				'amount'     => 25,
				'description' => 'Historical reward',
				'date'       => $event_date,
			),
			array( '%d', '%s', '%d', '%f', '%s', '%s' )
		);
		$log_id = absint( $wpdb->insert_id );

		try {
			$result = YoOhw_COS_Loyalty_Integration::backfill_legacy_points_logs( 500, 1 );
			$this->assertGreaterThanOrEqual( 1, absint( $result['processed'] ) );

			$event = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM %i
					WHERE event_source = 'wc_loyalty'
						AND object_type = 'loyalty_points_log'
						AND object_id = %d
					LIMIT 1",
					YoOhw_COS_DB::events_table(),
					$log_id
				),
				ARRAY_A
			);

			$this->assertIsArray( $event );
			$this->assertSame( $event_date, $event['created_at'] );
		} finally {
			$wpdb->delete( $table, array( 'id' => $log_id ), array( '%d' ) );
		}
	}

	private function create_order( string $email, string $status, string $total ): WC_Order {
		$order = wc_create_order();
		$order->set_billing_email( $email );
		$order->set_billing_phone( '+1 415 555 0198' );
		$order->set_billing_first_name( 'Architecture' );
		$order->set_billing_last_name( 'Test' );
		$order->set_total( $total );
		$order->set_status( $status );
		$order->save();

		return $order;
	}

	private function insert_timezone_task( int $customer_id, int $user_id, string $title, DateTimeImmutable $due_date ): int {
		global $wpdb;

		$wpdb->insert(
			YoOhw_COS_DB::tasks_table(),
			array(
				'customer_id'      => $customer_id,
				'assigned_user_id' => $user_id,
				'title'            => $title,
				'status'           => 'open',
				'priority'         => 'normal',
				'due_date'         => $due_date->format( 'Y-m-d H:i:s' ),
				'created_at'       => current_datetime()->format( 'Y-m-d H:i:s' ),
				'updated_at'       => current_datetime()->format( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return absint( $wpdb->insert_id );
	}

	private function clean_test_plugin_data(): void {
		global $wpdb;

		foreach ( YoOhw_COS_Install::expected_table_keys() as $table_key ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', YoOhw_COS_DB::table( $table_key ) ) );
		}

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}

		do {
			$orders = wc_get_orders(
				array(
					'limit'  => 100,
					'return' => 'objects',
					'status' => array_keys( wc_get_order_statuses() ),
					'type'   => 'shop_order',
				)
			);

			foreach ( is_array( $orders ) ? $orders : array() as $order ) {
				if ( $order instanceof WC_Order ) {
					$order->delete( true );
				}
			}
		} while ( count( $orders ) >= 100 );
	}

	private function load_plugin_classes(): void {
		$root = dirname( __DIR__, 2 );

		if ( ! defined( 'YOOHW_COS_VERSION' ) ) {
			define( 'YOOHW_COS_VERSION', '1.3.0' );
		}

		if ( ! defined( 'YOOHW_COS_DB_VERSION' ) ) {
			define( 'YOOHW_COS_DB_VERSION', '0.2.1' );
		}

		if ( ! defined( 'YOOHW_COS_PATH' ) ) {
			define( 'YOOHW_COS_PATH', $root . '/' );
		}

		$files = array(
			'includes/class-yoohw-cos-install.php',
			'includes/class-yoohw-cos-db.php',
			'includes/class-yoohw-cos-commerce-metrics-policy.php',
			'includes/class-yoohw-cos-customer-identity.php',
			'includes/class-yoohw-cos-commerce-aggregates.php',
			'includes/class-yoohw-cos-migration-runner.php',
			'includes/class-yoohw-cos-notification-ledger.php',
			'includes/class-yoohw-cos-integrations.php',
			'includes/class-yoohw-cos-customer-query.php',
			'includes/class-yoohw-cos-events.php',
			'includes/class-yoohw-cos-customers.php',
			'includes/class-yoohw-cos-tags.php',
			'includes/class-yoohw-cos-notes.php',
			'includes/class-yoohw-cos-tasks.php',
			'includes/class-yoohw-cos-email-notifications.php',
			'includes/class-yoohw-cos-overview.php',
			'includes/class-yoohw-cos-intelligence.php',
			'includes/class-yoohw-cos-segments.php',
			'includes/class-yoohw-cos-blacklist-manager-integration.php',
			'includes/class-yoohw-cos-blacklist-manager-premium-integration.php',
			'includes/class-yoohw-cos-loyalty-integration.php',
		);

		foreach ( $files as $file ) {
			require_once $root . '/' . $file;
		}
	}
}
