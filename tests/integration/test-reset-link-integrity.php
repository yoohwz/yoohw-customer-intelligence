<?php
require_once dirname( __DIR__ ) . '/environment.php';
yci_test_environment();

final class YCI_Reset_Link_Integrity_Test extends WP_UnitTestCase {
	public function test_reset_reordered_profiles_cannot_transfer_an_old_order(): void {
		global $wpdb;
		YoOhw_COS_Customers::reset_data();
		$order = wc_create_order();
		$order->set_billing_email( 'reset-a@example.test' );
		$order->set_billing_first_name( 'Customer A' );
		$order->set_total( '42.00' );
		$order->set_status( 'completed' );
		$order->update_meta_data( '_unrelated_sentinel', 'unchanged' );
		$order->save();
		$old_a = YoOhw_COS_Customers::sync_from_order( $order );
		$this->assertGreaterThan( 0, $old_a );
		YoOhw_COS_Customers::reset_data();
		$b = YoOhw_COS_Customers::create_customer( array( 'email' => 'reset-b@example.test', 'display_name' => 'Customer B' ) );
		$a = YoOhw_COS_Customers::create_customer( array( 'email' => 'reset-a@example.test', 'display_name' => 'Customer A' ) );
		$this->assertSame( $old_a, $b, 'Fixture exercises the reused numeric ID.' );
		$this->assertSame( $a, YoOhw_COS_Customers::sync_from_order( $order ), 'Old order must resolve to rebuilt A, never reused B.' );
		$this->assertSame( $a, YoOhw_COS_Customers::sync_from_order( $order ) );
		$fresh = new WC_Order( $order->get_id() );
		$this->assertSame( array( $a ), YoOhw_COS_Customer_Identity::get_persisted_order_customer_ids( $fresh ) );
		$this->assertSame( 'unchanged', $fresh->get_meta( '_unrelated_sentinel' ) );
		$this->assertSame( '42.00', $fresh->get_total() );
		$this->assertSame( 'reset-a@example.test', $fresh->get_billing_email() );
		$this->assertSame( 'reset-b@example.test', YoOhw_COS_Customers::get_customer( $b )['email'] );
		$this->assertSame( 'Customer B', YoOhw_COS_Customers::get_customer( $b )['display_name'] );
		$this->assertSame( 0, (int) YoOhw_COS_Customers::get_customer( $b )['total_orders'] );
		$this->assertSame( 42.0, (float) YoOhw_COS_Customers::get_customer( $a )['total_spent'] );
		$this->assertSame( 1, (int) YoOhw_COS_Customers::get_customer( $a )['total_orders'] );
		$this->assertSame( 0, YoOhw_COS_Events::get_customer_event_count( $b ) );
		$this->assertGreaterThan( 0, YoOhw_COS_Events::get_customer_event_count( $a ) );
		$this->assertSame( $a, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT customer_id FROM %i WHERE order_id = %d', YoOhw_COS_DB::order_facts_table(), $order->get_id() ) ) );
	}
	public function test_pre_reset_manual_link_and_post_reset_missing_contact(): void {
		YoOhw_COS_Customers::reset_data();
		delete_option( YoOhw_COS_Reset_Guard::OPTION );
		YoOhw_COS_Reset_Guard::init();
		$a = YoOhw_COS_Customers::create_customer( array( 'display_name' => 'Manual A' ) );
		$order = wc_create_order();
		$order->update_meta_data( YoOhw_COS_Customers::ORDER_CUSTOMER_META_KEY, $a );
		$order->save();
		$this->assertSame( $a, YoOhw_COS_Customers::sync_from_order( $order ) );
		YoOhw_COS_Customers::reset_data();
		$b = YoOhw_COS_Customers::create_customer( array( 'display_name' => 'Reused B' ) );
		$this->assertSame( $a, $b );
		$this->assertSame( 0, YoOhw_COS_Customer_Identity::get_persisted_order_customer_id( $order ) );
		$this->assertSame( 0, YoOhw_COS_Customers::sync_from_order( $order ) );
		$this->assertSame( 0, (int) YoOhw_COS_Customers::get_customer( $b )['total_orders'] );
	}

	public function test_registered_order_users_refunds_and_definitions_survive_reset(): void {
		global $wpdb;
		YoOhw_COS_Customers::reset_data();
		$user_id = self::factory()->user->create( array( 'user_email' => 'reset-registered@example.test' ) );
		$order = wc_create_order( array( 'customer_id' => $user_id ) );
		$order->set_billing_email( 'reset-registered@example.test' );
		$order->set_billing_phone( '+84912345678' );
		$order->set_total( '100.00' );
		$order->set_status( 'completed' );
		$order->update_meta_data( '_other_plugin', 'sentinel' );
		$order->save();
		$old = YoOhw_COS_Customers::sync_from_order( $order );
		$refund = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => '10.00', 'reason' => 'Synthetic', 'refund_payment' => false ) );
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );
		$tag = YoOhw_COS_Tags::create_tag( 'Retained reset tag' );
		$segment = YoOhw_COS_Segments::create_segment( 'Retained reset segment' );
		YoOhw_COS_Tags::assign_tag( $old, $tag, 0, false );
		YoOhw_COS_Segments::assign_customer( $old, $segment, 0, false );
		$before = ( new WC_Order( $order->get_id() ) )->get_data();
		$refund_before = ( new WC_Order_Refund( $refund->get_id() ) )->get_data();
		YoOhw_COS_Customers::reset_data();
		$this->assertEquals( $before, ( new WC_Order( $order->get_id() ) )->get_data() );
		$this->assertEquals( $refund_before, ( new WC_Order_Refund( $refund->get_id() ) )->get_data() );
		$this->assertSame( 'reset-registered@example.test', get_user_by( 'id', $user_id )->user_email );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id = %d', YoOhw_COS_DB::tags_table(), $tag ) ) );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id = %d', YoOhw_COS_DB::segments_table(), $segment ) ) );
		$b = YoOhw_COS_Customers::create_customer( array( 'email' => 'other@example.test' ) );
		$a = YoOhw_COS_Customers::create_customer( array( 'wp_user_id' => $user_id, 'email' => 'reset-registered@example.test' ) );
		$this->assertSame( $a, YoOhw_COS_Customers::sync_from_order( $order ) );
		$this->assertSame( $a, YoOhw_COS_Customers::sync_from_order( $order ) );
		$this->assertSame( 90.0, (float) YoOhw_COS_Customers::get_customer( $a )['total_spent'] );
		$this->assertSame( 0.0, (float) YoOhw_COS_Customers::get_customer( $b )['total_spent'] );
	}

	public function test_interrupted_partial_reset_blocks_writes_and_retry_finishes(): void {
		global $wpdb;
		YoOhw_COS_Customers::reset_data();
		YoOhw_COS_Customers::create_customer( array( 'email' => 'partial@example.test' ) );
		$interrupt = static function( $query ) {
			if ( false !== strpos( $query, 'TRUNCATE TABLE' ) && false !== strpos( $query, YoOhw_COS_DB::notes_table() ) ) {
				throw new RuntimeException( 'Synthetic interruption after earlier TRUNCATE commits.' );
			}
			return $query;
		};
		add_filter( 'query', $interrupt );
		try {
			YoOhw_COS_Customers::reset_data();
			$this->fail( 'Partial reset reported success.' );
		} catch ( RuntimeException $exception ) {
			$this->assertStringContainsString( 'Synthetic interruption', $exception->getMessage() );
		} finally {
			remove_filter( 'query', $interrupt );
		}
		$this->assertFalse( YoOhw_COS_Reset_Guard::ready() );
		$this->assertSame( 0, YoOhw_COS_Customers::create_customer( array( 'email' => 'blocked@example.test' ) ) );
		$this->assertSame( 0, YoOhw_COS_Events::record( array( 'customer_id' => 1, 'event_type' => 'blocked' ) ) );
		$epoch = YoOhw_COS_Reset_Guard::epoch();
		$this->assertTrue( YoOhw_COS_Customers::reset_data() );
		$this->assertNotSame( $epoch, YoOhw_COS_Reset_Guard::epoch() );
		$this->assertTrue( YoOhw_COS_Reset_Guard::ready() );
		$this->assertGreaterThan( 0, YoOhw_COS_Customers::create_customer( array( 'email' => 'recovered@example.test' ) ) );
	}

	public function test_duplicate_submission_does_not_delete_rebuilt_profiles(): void {
		$epoch = YoOhw_COS_Reset_Guard::epoch();
		$this->assertTrue( YoOhw_COS_Customers::reset_data( $epoch ) );
		$a = YoOhw_COS_Customers::create_customer( array( 'email' => 'duplicate@example.test' ) );
		$this->assertTrue( YoOhw_COS_Customers::reset_data( $epoch ) );
		$this->assertTrue( YoOhw_COS_Customers::customer_exists( $a ) );
	}

	public function test_running_writer_excludes_reset_from_another_process(): void {
		global $wpdb;
		YoOhw_COS_Customers::reset_data();
		$order = wc_create_order();
		$order->set_billing_email( 'paused@example.test' );
		$order->save();
		$wpdb->query( 'COMMIT' ); // Make synthetic fixtures visible to the second connection.
		$observed = '';
		$pause = function( $data ) use ( &$observed ) {
			list( $process, $pipes ) = $this->worker( 'reset' );
			fclose( $pipes[0] );
			$observed = stream_get_contents( $pipes[1] );
			$error = stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			$this->assertSame( 0, proc_close( $process ), $error );
			return $data;
		};
		add_filter( 'yoohw_cos_customer_sync_data', $pause );
		try {
			$a = YoOhw_COS_Customers::sync_from_order( $order );
		} finally {
			remove_filter( 'yoohw_cos_customer_sync_data', $pause );
		}
		$this->assertStringContainsString( 'BUSY', $observed );
		$this->assertGreaterThan( 0, $a );
		$this->assertTrue( YoOhw_COS_Customers::customer_exists( $a ) );
		$this->assertTrue( YoOhw_COS_Customers::reset_data() );
	}

	public function test_request_started_before_reset_cannot_resume_a_stale_write(): void {
		global $wpdb;
		YoOhw_COS_Customers::reset_data();
		$order = wc_create_order();
		$order->set_billing_email( 'resumed@example.test' );
		$order->save();
		YoOhw_COS_Customers::sync_from_order( $order );
		$wpdb->query( 'COMMIT' );
		list( $process, $pipes ) = $this->worker( 'stale-sync', $order->get_id() );
		try {
			$this->assertSame( "READY\n", fgets( $pipes[1] ) );
			YoOhw_COS_Customers::reset_data();
			$b = YoOhw_COS_Customers::create_customer( array( 'email' => 'new-b@example.test' ) );
			$wpdb->query( 'COMMIT' ); // Publish the new generation before resuming the old transaction.
			fwrite( $pipes[0], "RESUME\n" );
			fclose( $pipes[0] );
			$result = stream_get_contents( $pipes[1] );
			$error = stream_get_contents( $pipes[2] );
			$this->assertStringContainsString( 'RESULT:0', $result, $error );
			$this->assertStringNotContainsString( 'Lock wait timeout', $error );
			$this->assertSame( 0, (int) YoOhw_COS_Customers::get_customer( $b )['total_orders'] );
			$this->assertSame( 0, YoOhw_COS_Customer_Identity::get_persisted_order_customer_id( $order ) );
		} finally {
			foreach ( $pipes as $pipe ) { if ( is_resource( $pipe ) ) { fclose( $pipe ); } }
			proc_terminate( $process );
			proc_close( $process );
		}
		$this->assertNotSame( $b, YoOhw_COS_Customers::sync_from_order( $order ) );
	}

	public function test_process_exit_mid_reset_leaves_durable_recovery_barrier(): void {
		global $wpdb;
		YoOhw_COS_Customers::reset_data();
		YoOhw_COS_Customers::create_customer( array( 'email' => 'crash@example.test' ) );
		$wpdb->query( 'COMMIT' );
		list( $process, $pipes ) = $this->worker( 'interrupt-reset' );
		fclose( $pipes[0] );
		stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$this->assertSame( 13, proc_close( $process ), $error );
		$this->assertFalse( YoOhw_COS_Reset_Guard::ready() );
		$this->assertSame( 0, YoOhw_COS_Customers::create_customer( array( 'email' => 'blocked-crash@example.test' ) ) );
		$this->assertTrue( YoOhw_COS_Customers::reset_data() );
		$this->assertTrue( YoOhw_COS_Reset_Guard::ready() );
	}

	public function test_link_token_cannot_authorize_a_different_numeric_id(): void {
		YoOhw_COS_Customers::reset_data();
		$order = wc_create_order();
		$order->set_billing_email( 'token-a@example.test' );
		$order->save();
		$a = YoOhw_COS_Customers::sync_from_order( $order );
		$b = YoOhw_COS_Customers::create_customer( array( 'email' => 'token-b@example.test' ) );
		$order->update_meta_data( YoOhw_COS_Customers::ORDER_CUSTOMER_META_KEY, $b );
		$order->save_meta_data(); // Simulates an incomplete two-key link update.
		$this->assertSame( 0, YoOhw_COS_Customer_Identity::get_persisted_order_customer_id( $order ) );
		$this->assertSame( $a, YoOhw_COS_Customers::sync_from_order( $order ) );
	}

	public function test_admin_render_filter_and_stale_form_save_use_current_link_validity(): void {
		YoOhw_COS_Customers::reset_data();
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $admin )->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $admin );
		$order = wc_create_order();
		$order->set_billing_email( 'admin-a@example.test' );
		$order->save();
		$old = YoOhw_COS_Customers::sync_from_order( $order );
		$old_epoch = YoOhw_COS_Reset_Guard::epoch();
		YoOhw_COS_Customers::reset_data();
		$b = YoOhw_COS_Customers::create_customer( array( 'email' => 'admin-b@example.test' ) );
		$a = YoOhw_COS_Customers::create_customer( array( 'email' => 'admin-a@example.test' ) );
		ob_start();
		YoOhw_COS_Order_Admin::render_customer_field( $order );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'name="yoohw_cos_link_epoch"', $html );
		$this->assertStringContainsString( YoOhw_COS_Reset_Guard::epoch(), $html );
		$this->assertStringNotContainsString( 'admin-b@example.test', $html );
		$original_post = $_POST;
		$original_method = $_SERVER['REQUEST_METHOD'] ?? '';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = array( 'yoohw_cos_customer_id' => $old, 'yoohw_cos_link_epoch' => $old_epoch, 'woocommerce_meta_nonce' => wp_create_nonce( 'woocommerce_save_data' ) );
		try {
			YoOhw_COS_Order_Admin::save_customer_profile_link( $order->get_id(), $order );
			$this->assertSame( 0, YoOhw_COS_Customer_Identity::get_persisted_order_customer_id( $order ) );
			$_POST['yoohw_cos_customer_id'] = $a;
			$_POST['yoohw_cos_link_epoch'] = YoOhw_COS_Reset_Guard::epoch();
			YoOhw_COS_Order_Admin::save_customer_profile_link( $order->get_id(), $order );
			$this->assertSame( $a, YoOhw_COS_Customer_Identity::get_persisted_order_customer_id( new WC_Order( $order->get_id() ) ) );
		} finally {
			$_POST = $original_post;
			$_SERVER['REQUEST_METHOD'] = $original_method;
			wp_set_current_user( 0 );
		}
		$filter = new ReflectionMethod( YoOhw_COS_Order_Admin::class, 'apply_order_list_customer_filter_to_query_args' );
		$filter->setAccessible( true );
		$query = $filter->invoke( null, array( 'return' => 'ids' ), $b );
		$this->assertNotContains( $order->get_id(), ( 'yes' === getenv( 'WC_HPOS_ENABLED' ) ? wc_get_orders( $query ) : ( new WP_Query( array_merge( $query, array( 'post_type' => 'shop_order', 'post_status' => array_keys( wc_get_order_statuses() ), 'fields' => 'ids' ) ) ) )->posts ) );
		$query = $filter->invoke( null, array( 'return' => 'ids' ), $a );
		$this->assertContains( $order->get_id(), ( 'yes' === getenv( 'WC_HPOS_ENABLED' ) ? wc_get_orders( $query ) : ( new WP_Query( array_merge( $query, array( 'post_type' => 'shop_order', 'post_status' => array_keys( wc_get_order_statuses() ), 'fields' => 'ids' ) ) ) )->posts ) );
	}

	private function worker( string $mode, int $order_id = 0 ): array {
		$command = array( PHP_BINARY, '-d', 'disable_functions=mail', dirname( __DIR__ ) . '/reset-worker.php', $mode, (string) $order_id );
		$process = proc_open( $command, array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $process );
		stream_set_timeout( $pipes[1], 15 );
		stream_set_timeout( $pipes[2], 15 );
		return array( $process, $pipes );
	}

}
