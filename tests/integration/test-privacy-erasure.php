<?php
require_once dirname( __DIR__ ) . '/environment.php';
yci_test_environment();

final class YCI_Privacy_Erasure_Test extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		YoOhw_COS_Customers::reset_data();
		delete_option( 'yoohw_cos_privacy_suppression_secret' );
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', YoOhw_COS_DB::table( 'privacy_suppression' ) ) );
	}

	public function tear_down(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', YoOhw_COS_DB::table( 'privacy_suppression' ) ) );
		delete_option( 'yoohw_cos_privacy_suppression_secret' );
		parent::tear_down();
	}

	private function erase_all( string $email ): array {
		$result = array();
		for ( $page = 1; $page <= 30; $page++ ) {
			$result = YoOhw_COS_Privacy_Erasure::erase( $email, $page );
			if ( $result['done'] ) { return $result; }
			$this->assertStringNotContainsString( 'temporarily unavailable', implode( ' ', $result['messages'] ) );
		}
		$this->fail( 'Eraser did not complete within bounded pages.' );
	}

	public function test_guest_erasure_is_paged_and_suppresses_resync_without_touching_woocommerce(): void {
		global $wpdb;
		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		$this->assertSame( array( YoOhw_COS_Privacy_Erasure::class, 'erase' ), $erasers['yoohw-customer-intelligence']['callback'] );
		$order = wc_create_order();
		$order->set_billing_email( 'erase-guest@example.test' );
		$order->set_billing_phone( '+15551234567' );
		$order->set_total( '42.00' );
		$order->set_status( 'completed' );
		$order->update_meta_data( '_other_plugin', 'keep' );
		$order->save();
		$customer_id = YoOhw_COS_Customers::sync_from_order( $order );
		$this->assertGreaterThan( 0, $customer_id );
		for ( $i = 0; $i < 30; $i++ ) {
			$wpdb->insert( YoOhw_COS_DB::notes_table(), array( 'customer_id' => $customer_id, 'note_content' => 'Synthetic note', 'created_at' => YoOhw_COS_DB::now(), 'updated_at' => YoOhw_COS_DB::now() ) );
		}
		$other = YoOhw_COS_Customers::create_customer( array( 'email' => 'other@example.test' ) );
		$mail_before = (int) ( $GLOBALS['yci_intercepted_mail'] ?? 0 );
		$first = YoOhw_COS_Privacy_Erasure::erase( 'erase-guest@example.test', 1 );
		$this->assertTrue( $first['items_removed'] );
		$this->assertTrue( $first['items_retained'] );
		$this->assertFalse( $first['done'] );
		$this->assertSame( 5, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE customer_id = %d', YoOhw_COS_DB::notes_table(), $customer_id ) ) );
		$this->erase_all( 'erase-guest@example.test' );
		$this->assertSame( $mail_before, (int) ( $GLOBALS['yci_intercepted_mail'] ?? 0 ) );
		$this->assertSame( array(), YoOhw_COS_Customers::get_customer( $customer_id ) );
		$this->assertNotEmpty( YoOhw_COS_Customers::get_customer( $other ) );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE customer_id = %d', YoOhw_COS_DB::order_facts_table(), $customer_id ) ) );
		$this->assertTrue( YoOhw_COS_Privacy_Erasure::is_suppressed( array( 'email' => 'erase-guest@example.test' ) ) );
		$this->assertFalse( YoOhw_COS_Privacy_Erasure::is_suppressed( array( 'phone' => '+15551234567' ) ) );
		$this->assertSame( 0, YoOhw_COS_Customers::sync_from_order_id( $order->get_id() ) );
		$this->assertSame( 0, YoOhw_COS_Events::record( array( 'event_type' => 'synthetic_signal', 'event_source' => 'wc_blacklist_manager', 'object_type' => 'order', 'object_id' => $order->get_id() ) ) );

		$this->assertFalse( wp_next_scheduled( YoOhw_COS_Customers::ORDER_SYNC_RETRY_HOOK, array( $order->get_id() ) ) );
		$this->assertSame( 0, YoOhw_COS_Customers::create_customer( array( 'email' => 'erase-guest@example.test' ) ) );
		$fresh = wc_get_order( $order->get_id() );
		$this->assertSame( 'erase-guest@example.test', $fresh->get_billing_email() );
		$this->assertSame( 'keep', $fresh->get_meta( '_other_plugin' ) );
		$this->assertSame( '', (string) $fresh->get_meta( YoOhw_COS_Customers::ORDER_CUSTOMER_META_KEY ) );
		$this->assertSame( '', (string) $fresh->get_meta( YoOhw_COS_Reset_Guard::META_KEY ) );
		$this->assertSame( array( 'data' => array(), 'done' => true ), YoOhw_COS_Privacy_Exporter::export( 'erase-guest@example.test', 1 ) );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT identity_kind, identity_digest FROM %i', YoOhw_COS_DB::table( 'privacy_suppression' ) ), ARRAY_A );
		$this->assertNotEmpty( $rows );
		$this->assertStringNotContainsString( 'erase-guest@example.test', wp_json_encode( $rows ) );
		$this->assertSame( 'yes', strlen( get_option( 'yoohw_cos_privacy_suppression_secret' ) ) === 64 ? 'yes' : 'no' );
	}

	public function test_exact_user_match_erases_alias_profiles_and_owner_views_only(): void {
		global $wpdb;
		$user_id = self::factory()->user->create( array( 'user_email' => 'owner@example.test' ) );
		$other_user = self::factory()->user->create( array( 'user_email' => 'other-owner@example.test' ) );
		$linked = YoOhw_COS_Customers::create_customer( array( 'wp_user_id' => $user_id, 'email' => 'alias@example.test' ) );
		$guest = YoOhw_COS_Customers::create_customer( array( 'email' => 'owner@example.test' ) );
		$unrelated = YoOhw_COS_Customers::create_customer( array( 'email' => 'owner-partial@example.test' ) );
		update_user_meta( $user_id, '_yoohw_cos_saved_customer_views', array( 'fixture' => 'personal' ) );
		update_user_meta( $other_user, '_yoohw_cos_saved_customer_views', array( 'fixture' => 'other' ) );
		$wpdb->insert( YoOhw_COS_DB::events_table(), array( 'customer_id' => null, 'wp_user_id' => $user_id, 'event_type' => 'signal', 'event_source' => 'wc_blacklist_manager', 'created_at' => YoOhw_COS_DB::now() ) );
		$unlinked_event = (int) $wpdb->insert_id;
		$this->assertGreaterThan( 0, $unlinked_event );
		$export = YoOhw_COS_Privacy_Exporter::export( 'owner@example.test', 1 );
		$this->assertContains( 'yoohw-cos-events-' . $unlinked_event, array_column( $export['data'], 'item_id' ) );
		$task = YoOhw_COS_Tasks::create_task( array( 'customer_id' => $linked, 'title' => 'Synthetic task' ) );
		$this->assertGreaterThan( 0, $task );
		$wpdb->insert( YoOhw_COS_DB::notification_log_table(), array( 'notification_key' => 'privacy-fixture', 'notification_type' => 'task', 'task_id' => $task, 'created_at' => YoOhw_COS_DB::now(), 'expires_at' => YoOhw_COS_DB::now() ) );
		$this->erase_all( 'owner@example.test' );
		$this->assertSame( array(), YoOhw_COS_Customers::get_customer( $linked ) );
		$this->assertSame( array(), YoOhw_COS_Customers::get_customer( $guest ) );
		$this->assertNotEmpty( YoOhw_COS_Customers::get_customer( $unrelated ) );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE task_id = %d', YoOhw_COS_DB::notification_log_table(), $task ) ) );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id = %d', YoOhw_COS_DB::events_table(), $unlinked_event ) ) );
		$this->assertFalse( metadata_exists( 'user', $user_id, '_yoohw_cos_saved_customer_views' ) );
		$this->assertTrue( metadata_exists( 'user', $other_user, '_yoohw_cos_saved_customer_views' ) );
		$this->assertNotFalse( get_user_by( 'id', $user_id ) );
		$this->assertTrue( YoOhw_COS_Privacy_Erasure::is_suppressed( array( 'wp_user_id' => $user_id ) ) );
		$this->assertTrue( YoOhw_COS_Privacy_Erasure::is_suppressed( array( 'email' => 'alias@example.test' ) ) );
		$this->assertSame( 0, YoOhw_COS_Customers::create_customer( array( 'email' => 'alias@example.test' ) ) );
		$order = wc_create_order( array( 'customer_id' => $user_id ) );
		$order->set_billing_email( 'new-alias@example.test' );
		$order->save();
		$this->assertSame( 0, YoOhw_COS_Customers::sync_from_order_id( $order->get_id() ) );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE order_id = %d', YoOhw_COS_DB::order_facts_table(), $order->get_id() ) ) );

		$this->assertTrue( YoOhw_COS_Privacy_Erasure::erase( 'owner@example.test', 1 )['done'] );
		$this->assertTrue( YoOhw_COS_Privacy_Erasure::erase( 'owner@example.test', 1 )['items_retained'] );
	}
	public function test_reset_between_pages_never_uses_a_stale_customer_id(): void {
		$old = YoOhw_COS_Customers::create_customer( array( 'email' => 'erase-before-reset@example.test' ) );
		$this->assertGreaterThan( 0, $old );
		$this->assertFalse( YoOhw_COS_Privacy_Erasure::erase( 'erase-before-reset@example.test', 1 )['done'] );
		YoOhw_COS_Customers::reset_data();
		$new = YoOhw_COS_Customers::create_customer( array( 'email' => 'unrelated-after-reset@example.test' ) );
		$this->assertSame( $old, $new );
		$this->assertTrue( YoOhw_COS_Privacy_Erasure::erase( 'erase-before-reset@example.test', 2 )['done'] );
		$this->assertSame( 'unrelated-after-reset@example.test', YoOhw_COS_Customers::get_customer( $new )['email'] );
		$this->assertTrue( YoOhw_COS_Privacy_Erasure::erase( 'erase-before-reset@example.test', 1 )['done'] );
		$this->assertSame( 'unrelated-after-reset@example.test', YoOhw_COS_Customers::get_customer( $new )['email'] );
	}

	public function test_partial_email_match_does_not_erase_an_unrelated_profile(): void {
		$id = YoOhw_COS_Customers::create_customer( array( 'email' => 'not-owner@example.test' ) );
		$this->assertGreaterThan( 0, $id );
		$result = YoOhw_COS_Privacy_Erasure::erase( 'owner@example.test', 1 );
		$this->assertTrue( $result['done'] );
		$this->assertFalse( $result['items_removed'] );
		$this->assertNotEmpty( YoOhw_COS_Customers::get_customer( $id ) );
	}

	public function test_failed_order_link_clear_keeps_fact_retryable(): void {
		global $wpdb;
		$order = wc_create_order();
		$order->set_billing_email( 'link-failure@example.test' );
		$order->set_total( '18.00' );
		$order->save();
		$id = YoOhw_COS_Customers::sync_from_order( $order );
		$this->assertGreaterThan( 0, $id );
		$block = static function( $sql ) {
			if ( preg_match( '/^DELETE FROM .*?(?:wc_orders_meta|postmeta)\b/i', $sql ) ) { return 'SELECT 1'; }
			return $sql;
		};
		add_filter( 'query', $block );
		try {
			// The first page may remove the order-sync activity event.
			for ( $page = 1; $page <= 4; $page++ ) {
				$result = YoOhw_COS_Privacy_Erasure::erase( 'link-failure@example.test', $page );
				if ( ! $result['items_removed'] ) { break; }
			}
			$this->assertFalse( $result['done'] );
			$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE customer_id = %d', YoOhw_COS_DB::order_facts_table(), $id ) ) );
			$this->assertNotEmpty( YoOhw_COS_Customers::get_customer( $id ) );
		} finally {
			remove_filter( 'query', $block );
		}
		$this->erase_all( 'link-failure@example.test' );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE customer_id = %d', YoOhw_COS_DB::order_facts_table(), $id ) ) );
	}

	public function test_each_dependent_table_drains_without_shrinking_offsets(): void {
		global $wpdb;
		$id = YoOhw_COS_Customers::create_customer( array( 'email' => 'many-rows@example.test' ) );
		$this->assertGreaterThan( 0, $id );
		for ( $i = 0; $i < 27; $i++ ) {
			$now = YoOhw_COS_DB::now();
			$this->assertSame( 1, $wpdb->insert( YoOhw_COS_DB::notes_table(), array( 'customer_id' => $id, 'note_content' => 'Synthetic note', 'created_at' => $now, 'updated_at' => $now ) ) );
			$this->assertSame( 1, $wpdb->insert( YoOhw_COS_DB::tasks_table(), array( 'customer_id' => $id, 'title' => 'Synthetic task', 'created_at' => $now, 'updated_at' => $now ) ) );
			$this->assertSame( 1, $wpdb->insert( YoOhw_COS_DB::events_table(), array( 'customer_id' => $id, 'event_type' => 'synthetic', 'created_at' => $now ) ) );
			$this->assertSame( 1, $wpdb->insert( YoOhw_COS_DB::order_facts_table(), array( 'customer_id' => $id, 'order_id' => 900000 + $i, 'order_status' => 'completed', 'order_date' => $now, 'updated_at' => $now ) ) );
		}
		$this->erase_all( 'many-rows@example.test' );
		foreach ( array( 'notes', 'tasks', 'events', 'order_facts' ) as $kind ) {
			$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE customer_id = %d', YoOhw_COS_DB::table( $kind ), $id ) ) );
		}
		$this->assertSame( array(), YoOhw_COS_Customers::get_customer( $id ) );
	}

	public function test_missing_secret_with_receipts_fails_closed_without_rotation(): void {
		$id = YoOhw_COS_Customers::create_customer( array( 'email' => 'secret-loss@example.test' ) );
		$this->assertGreaterThan( 0, $id );
		$this->erase_all( 'secret-loss@example.test' );
		delete_option( 'yoohw_cos_privacy_suppression_secret' );
		$this->assertNull( YoOhw_COS_Privacy_Erasure::is_suppressed( array( 'email' => 'secret-loss@example.test' ) ) );
		$this->assertSame( 0, YoOhw_COS_Customers::create_customer( array( 'email' => 'unrelated@example.test' ) ) );
		$result = YoOhw_COS_Privacy_Erasure::erase( 'secret-loss@example.test', 1 );
		$this->assertFalse( $result['done'] );
		$this->assertNull( get_option( 'yoohw_cos_privacy_suppression_secret', null ) );
	}

	public function test_all_linked_aliases_survive_reset_after_first_erasure_page(): void {
		$user_id = self::factory()->user->create( array( 'user_email' => 'all-aliases@example.test' ) );
		YoOhw_COS_Customers::create_customer( array( 'wp_user_id' => $user_id, 'email' => 'first-alias@example.test' ) );
		YoOhw_COS_Customers::create_customer( array( 'wp_user_id' => $user_id, 'email' => 'second-alias@example.test' ) );
		$first = YoOhw_COS_Privacy_Erasure::erase( 'all-aliases@example.test', 1 );
		$this->assertFalse( $first['done'] );
		$this->assertTrue( YoOhw_COS_Privacy_Erasure::is_suppressed( array( 'email' => 'first-alias@example.test' ) ) );
		$this->assertTrue( YoOhw_COS_Privacy_Erasure::is_suppressed( array( 'email' => 'second-alias@example.test' ) ) );
		YoOhw_COS_Customers::reset_data();
		$order = wc_create_order();
		$order->set_billing_email( 'second-alias@example.test' );
		$order->save();
		$this->assertSame( 0, YoOhw_COS_Customers::sync_from_order_id( $order->get_id() ) );
	}

	public function test_phone_only_order_cannot_adopt_profile_during_erasure(): void {
		global $wpdb;
		$id = YoOhw_COS_Customers::create_customer( array( 'email' => 'pending-erase@example.test', 'phone' => '+15557654321' ) );
		for ( $i = 0; $i < 27; $i++ ) {
			$wpdb->insert( YoOhw_COS_DB::notes_table(), array( 'customer_id' => $id, 'note_content' => 'Synthetic', 'created_at' => YoOhw_COS_DB::now(), 'updated_at' => YoOhw_COS_DB::now() ) );
		}
		$this->assertFalse( YoOhw_COS_Privacy_Erasure::erase( 'pending-erase@example.test', 1 )['done'] );
		$order = wc_create_order();
		$order->set_billing_phone( '+15557654321' );
		$order->save();
		$this->assertSame( 0, YoOhw_COS_Customers::sync_from_order_id( $order->get_id() ) );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE order_id = %d', YoOhw_COS_DB::order_facts_table(), $order->get_id() ) ) );
		$this->assertSame( '', (string) wc_get_order( $order->get_id() )->get_meta( YoOhw_COS_Customers::ORDER_CUSTOMER_META_KEY ) );
	}

	public function test_integration_event_preserves_source_user_suppression(): void {
		$user_id = self::factory()->user->create( array( 'user_email' => 'event-owner@example.test' ) );
		$this->erase_all( 'event-owner@example.test' );
		$guest = YoOhw_COS_Customers::create_customer( array( 'email' => 'different-event@example.test', 'wp_user_id' => 0 ) );
		$this->assertGreaterThan( 0, $guest );
		$this->assertSame( 0, YoOhw_COS_Events::record( array( 'customer_id' => $guest, 'wp_user_id' => $user_id, 'event_type' => 'synthetic_signal', 'event_source' => 'wc_blacklist_manager' ) ) );
	}

	public function test_stale_raw_link_to_another_customer_is_retained_and_fact_retryable(): void {
		global $wpdb;
		$order = wc_create_order();
		$order->set_billing_email( 'raw-link-a@example.test' );
		$order->save();
		$a = YoOhw_COS_Customers::sync_from_order( $order );
		$b = YoOhw_COS_Customers::create_customer( array( 'email' => 'raw-link-b@example.test' ) );
		$this->assertGreaterThan( 0, $a );
		$this->assertGreaterThan( 0, $b );
		$order = wc_get_order( $order->get_id() );
		$order->delete_meta_data( YoOhw_COS_Customers::ORDER_CUSTOMER_META_KEY );
		$order->add_meta_data( YoOhw_COS_Customers::ORDER_CUSTOMER_META_KEY, $b, true );
		$order->update_meta_data( YoOhw_COS_Reset_Guard::META_KEY, 'stale:' . $b );
		$order->save_meta_data();
		for ( $page = 1; $page <= 5; $page++ ) {
			$result = YoOhw_COS_Privacy_Erasure::erase( 'raw-link-a@example.test', $page );
			if ( ! $result['items_removed'] ) { break; }
		}
		$this->assertFalse( $result['done'] );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE customer_id = %d', YoOhw_COS_DB::order_facts_table(), $a ) ) );
		$this->assertSame( (string) $b, (string) wc_get_order( $order->get_id() )->get_meta( YoOhw_COS_Customers::ORDER_CUSTOMER_META_KEY ) );
	}

}
