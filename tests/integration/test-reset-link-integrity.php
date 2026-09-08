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

	public function test_rendered_selection_and_epoch_are_excluded_from_concurrent_reset(): void {
		global $wpdb;
		YoOhw_COS_Customers::reset_data();
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $user_id )->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $user_id );
		$order = wc_create_order();
		$order->set_billing_email( 'render-race@example.test' );
		$order->save();
		YoOhw_COS_Customers::sync_from_order( $order );
		$epoch = YoOhw_COS_Reset_Guard::epoch();
		$wpdb->query( 'COMMIT' );
		$observed = '';
		$pause = function( $translation, $text ) use ( &$observed ) {
			if ( 'Uses customer profiles. WooCommerce customer user is synchronized when the selected profile has a WP user.' === $text ) {
				global $wpdb;
				$wpdb->query( 'COMMIT' ); // Model the ordinary autocommit renderer; keep only its named guard.
				list( $process, $pipes ) = $this->worker( 'reset' );
				fclose( $pipes[0] );
				$observed = stream_get_contents( $pipes[1] );
				$error = stream_get_contents( $pipes[2] );
				fclose( $pipes[1] );
				fclose( $pipes[2] );
				$this->assertSame( 0, proc_close( $process ), $error );
			}
			return $translation;
		};
		add_filter( 'gettext', $pause, 10, 2 );
		ob_start();
		try {
			YoOhw_COS_Order_Admin::render_customer_field( $order );
			$html = ob_get_contents();
		} finally {
			ob_end_clean();
			remove_filter( 'gettext', $pause, 10 );
			wp_set_current_user( 0 );
		}
		$this->assertStringContainsString( 'BUSY', $observed );
		$this->assertStringContainsString( $epoch, $html );
		$this->assertSame( $epoch, YoOhw_COS_Reset_Guard::epoch() );
	}

	public function test_contended_permanent_delete_keeps_idempotent_cleanup_work(): void {
		global $wpdb;
		YoOhw_COS_Customers::reset_data();
		$order = wc_create_order();
		$order->set_billing_email( 'delete-race@example.test' );
		$order->set_total( '55.00' );
		$order->set_status( 'completed' );
		$order->save();
		$a = YoOhw_COS_Customers::sync_from_order( $order );
		$order_id = $order->get_id();
		$wpdb->query( 'COMMIT' );
		list( $process, $pipes ) = $this->worker( 'hold-writer' );
		try {
			$this->assertSame( "LOCKED\n", fgets( $pipes[1] ) );
			$order->delete( true );
			$wpdb->query( 'COMMIT' );
			fwrite( $pipes[0], "RELEASE\n" );
			fclose( $pipes[0] );
			$this->assertStringContainsString( 'RELEASED', stream_get_contents( $pipes[1] ) );
			$error = stream_get_contents( $pipes[2] );
		} finally {
			foreach ( $pipes as $pipe ) { if ( is_resource( $pipe ) ) { fclose( $pipe ); } }
			proc_terminate( $process );
			proc_close( $process );
		}
		$this->assertFalse( wc_get_order( $order_id ) );
		$this->assertNotFalse( wp_next_scheduled( YoOhw_COS_Customers::ORDER_SYNC_RETRY_HOOK, array( $order_id ) ), $error );
		do_action( YoOhw_COS_Customers::ORDER_SYNC_RETRY_HOOK, $order_id );
		do_action( YoOhw_COS_Customers::ORDER_SYNC_RETRY_HOOK, $order_id );
		$this->assertSame( 0, (int) YoOhw_COS_Customers::get_customer( $a )['total_orders'] );
		$this->assertSame( 0.0, (float) YoOhw_COS_Customers::get_customer( $a )['total_spent'] );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE order_id = %d', YoOhw_COS_DB::order_facts_table(), $order_id ) ) );
	}

	private function worker( string $mode, int $order_id = 0 ): array {
		$command = array( PHP_BINARY, '-d', 'disable_functions=mail', dirname( __DIR__ ) . '/reset-worker.php', $mode, (string) $order_id );
		$process = proc_open( $command, array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $process );
		stream_set_timeout( $pipes[1], 15 );
		stream_set_timeout( $pipes[2], 15 );
		return array( $process, $pipes );
	}

	public static function ordinary_operations(): array {
		return array_map( static function( $name ) { return array( $name ); }, array(
			'note_add', 'note_update', 'note_delete', 'task_create', 'task_idempotent', 'task_update',
			'task_complete', 'task_reopen', 'task_delete', 'tag_assign', 'tag_remove', 'segment_assign', 'segment_remove', 'tag_delete', 'segment_delete'
		) );
	}

	private function ordinary_fixture(): array {
		YoOhw_COS_Customers::reset_data();
		$c = YoOhw_COS_Customers::create_customer( array( 'email' => 'ordinary@example.test', 'display_name' => 'Rebuilt record' ) );
		$n = YoOhw_COS_Notes::add_note( $c, 'Retained note' );
		$t = YoOhw_COS_Tasks::create_task( array( 'customer_id' => $c, 'title' => 'Retained task' ) );
		$tag = YoOhw_COS_Tags::create_tag( 'Ordinary tag' );
		$segment = YoOhw_COS_Segments::create_segment( 'Ordinary segment' );
		YoOhw_COS_Tags::assign_tag( $c, $tag );
		YoOhw_COS_Segments::assign_customer( $c, $segment );
		return array( $c, $n, $t, $tag, $segment );
	}

	private function ordinary_rows(): array {
		global $wpdb;
		$rows = array();
		foreach ( array( 'customers', 'notes', 'tasks', 'tags', 'segments', 'customer_tags', 'customer_segments', 'events' ) as $table ) {
			$rows[ $table ] = $wpdb->get_results( 'SELECT * FROM `' . call_user_func( array( 'YoOhw_COS_DB', $table . '_table' ) ) . '` ORDER BY 1', ARRAY_A );
		}
		return $rows;
	}

	private function ordinary_operation( string $operation, array $ids ) {
		list( $c, $n, $t, $tag, $segment ) = $ids;
		switch ( $operation ) {
			case 'note_add': return YoOhw_COS_Notes::add_note( $c, 'Stale note' );
			case 'note_update': return YoOhw_COS_Notes::update_note( $n, 'Stale edit' );
			case 'note_delete': return YoOhw_COS_Notes::delete_note( $n );
			case 'task_create': return YoOhw_COS_Tasks::create_task( array( 'customer_id' => $c, 'title' => 'Stale task' ) );
			case 'task_idempotent': return YoOhw_COS_Tasks::create_idempotent_task( 'ordinary-source', array( 'customer_id' => $c, 'title' => 'Stale task' ) );
			case 'task_update': return YoOhw_COS_Tasks::update_task( $t, array( 'title' => 'Stale edit' ) );
			case 'task_complete': return YoOhw_COS_Tasks::complete_task( $t );
			case 'task_reopen': return YoOhw_COS_Tasks::reopen_task( $t );
			case 'task_delete': return YoOhw_COS_Tasks::delete_task( $t );
			case 'tag_assign': return YoOhw_COS_Tags::assign_tag( $c, $tag );
			case 'tag_remove': return YoOhw_COS_Tags::remove_tag( $c, $tag );
			case 'segment_assign': return YoOhw_COS_Segments::assign_customer( $c, $segment );
			case 'segment_remove': return YoOhw_COS_Segments::remove_customer( $c, $segment );
			case 'tag_delete': return YoOhw_COS_Tags::delete_tag( $tag, true );
			case 'segment_delete': return YoOhw_COS_Segments::delete_segment( $segment, true );
		}
	}

	/** @dataProvider ordinary_operations */
	public function test_pending_reset_rejects_ordinary_writers( string $operation ): void {
		$ids = $this->ordinary_fixture();
		if ( 'task_reopen' === $operation ) { YoOhw_COS_Tasks::complete_task( $ids[2] ); }
		if ( 'tag_assign' === $operation ) { YoOhw_COS_Tags::remove_tag( $ids[0], $ids[3] ); }
		if ( 'segment_assign' === $operation ) { YoOhw_COS_Segments::remove_customer( $ids[0], $ids[4] ); }
		$before = $this->ordinary_rows();
		$mail = $GLOBALS['yci_intercepted_mail'] ?? 0;
		$state = YoOhw_COS_Reset_Guard::state();
		update_option( YoOhw_COS_Reset_Guard::OPTION, array( 'epoch' => $state['epoch'], 'status' => 'pending' ), false );
		try {
			$result = $this->ordinary_operation( $operation, $ids );
			$this->assertSame( $before, $this->ordinary_rows(), $operation . ' must not mutate while Reset is pending.' );
			$this->assertFalse( (bool) $result );
			$this->assertSame( $mail, $GLOBALS['yci_intercepted_mail'] ?? 0 );
		} finally {
			update_option( YoOhw_COS_Reset_Guard::OPTION, $state, false );
			YoOhw_COS_Reset_Guard::init();
		}
		$this->assertTrue( (bool) $this->ordinary_operation( $operation, $ids ), 'Valid current operation remains available.' );
	}

	private function fresh_action( array $input ): array {
		list( $process, $pipes ) = $this->worker( 'ordinary-request' );
		try {
			fwrite( $pipes[0], wp_json_encode( $input ) . "\n" );
			fclose( $pipes[0] );
			$output = stream_get_contents( $pipes[1] );
			$error = stream_get_contents( $pipes[2] );
		} finally {
			foreach ( $pipes as $pipe ) { if ( is_resource( $pipe ) ) { fclose( $pipe ); } }
			proc_terminate( $process );
			proc_close( $process );
		}
		global $wpdb;
		$wpdb->query( 'COMMIT' ); // End PHPUnit's read snapshot before checking another connection's writes.
		$result = json_decode( $output, true );
		$this->assertIsArray( $result, $output . $error );
		return $result;
	}

	public function test_old_rendered_forms_rows_and_bulk_reject_reused_records_in_fresh_requests(): void {
		global $wpdb;
		$old = $this->ordinary_fixture();
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $user )->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $user );
		set_current_screen( 'woocommerce_page_yoohw-customer-intelligence' );
		ob_start();
		YoOhw_COS_Customer_Profile::render( $old[0] );
		$profile = ob_get_clean();
		$dom = new DOMDocument();
		@$dom->loadHTML( $profile );
		$xpath = new DOMXPath( $dom );
		$forms = array();
		foreach ( $xpath->query( '//form' ) as $form ) {
			$fields = array();
			foreach ( $xpath->query( './/input[@name]', $form ) as $input ) {
				$fields[ $input->getAttribute( 'name' ) ] = $input->getAttribute( 'value' );
			}
			if ( isset( $fields['action'] ) ) {
				$this->assertArrayHasKey( 'yoohw_cos_epoch', $fields, $fields['action'] );
				$forms[ $fields['action'] ] = $fields;
			}
		}
		$epoch = $forms['yoohw_cos_add_customer_note']['yoohw_cos_epoch'];
		$this->assertSame( YoOhw_COS_Reset_Guard::epoch(), $epoch );
		$rows = array();
		foreach ( $xpath->query( '//a[@href]' ) as $link ) {
			parse_str( (string) parse_url( html_entity_decode( $link->getAttribute( 'href' ) ), PHP_URL_QUERY ), $args );
			if ( isset( $args['action'] ) ) {
				$this->assertSame( $epoch, $args['yoohw_cos_epoch'] ?? null, $args['action'] );
				$rows[ $args['action'] ] = $args;
			}
		}
		// Every list holds its old epoch even when the rows are displayed after Reset.
		$lists = array();
		foreach ( array( 'Customers', 'Tasks', 'Tags', 'Segments' ) as $kind ) {
			$class = 'YoOhw_COS_' . $kind . '_List';
			$lists[ $kind ] = new $class();
			$lists[ $kind ]->prepare_items();
			$this->assertSame( $epoch, $lists[ $kind ]->selection_epoch );
		}
		$new = $this->ordinary_fixture();
		foreach ( array( 'Tasks' => 'column_title', 'Tags' => 'column_name', 'Segments' => 'column_name' ) as $kind => $column ) {
			$html = $lists[ $kind ]->$column( $lists[ $kind ]->items[0] );
			$this->assertStringContainsString( 'yoohw_cos_epoch=' . $epoch, $html );
			$this->assertStringNotContainsString( 'yoohw_cos_epoch=' . YoOhw_COS_Reset_Guard::epoch(), $html );
		}
		$this->assertSame( array_slice( $old, 0, 3 ), array_slice( $new, 0, 3 ), 'Customer, note AND task IDs are reused.' );
		$wpdb->query( 'COMMIT' );
		$before = $this->ordinary_rows();
		$requests = array();
		foreach ( $forms as $action => $data ) {
			$data += array( 'customer_note' => 'Stale', 'task_title' => 'Stale task', 'tag_name' => 'Must not create tag', 'segment_name' => 'Must not create segment', 'email_subject' => 'Stale', 'email_message' => 'Stale' );
			$requests[] = array( 'handler' => 'handle_' . substr( $action, strlen( 'yoohw_cos_' ) ), 'method' => 'POST', 'data' => $data );
		}
		foreach ( $rows as $action => $data ) {
			$requests[] = array( 'handler' => 'handle_' . substr( $action, strlen( 'yoohw_cos_' ) ), 'method' => 'GET', 'data' => $data );
		}
		// Additional task list actions, editing, and both status transitions.
		foreach ( array( 'complete_task', 'reopen_task', 'delete_task', 'delete_tag', 'delete_segment' ) as $action ) {
			$requests[] = array( 'handler' => 'handle_' . $action, 'method' => 'GET', 'data' => array( 'task_id' => $old[2], 'tag_id' => $old[3], 'segment_id' => $old[4], '_wpnonce' => wp_create_nonce( 'yoohw_cos_' . $action ), 'yoohw_cos_epoch' => $epoch ) );
		}
		$requests[] = array( 'handler' => 'handle_update_task', 'method' => 'POST', 'data' => array( 'task_id' => $old[2], 'customer_id' => $old[0], 'task_title' => 'Stale edit', '_wpnonce' => wp_create_nonce( 'yoohw_cos_update_task' ), 'yoohw_cos_epoch' => $epoch ) );
		foreach ( array( 'bulk_assign_tag', 'bulk_remove_tag', 'bulk_assign_segment', 'bulk_remove_segment', 'bulk_create_task', 'bulk_archive_customer', 'bulk_restore_customer' ) as $action ) {
			$requests[] = array( 'bulk' => 'customers', 'method' => 'POST', 'data' => array( 'action' => $action, 'customer_ids' => array( $old[0] ), 'yoohw_cos_customers_bulk_nonce' => wp_create_nonce( 'yoohw_cos_customers_bulk_action' ), 'yoohw_cos_epoch' => $lists['Customers']->selection_epoch ) );
		}
		foreach ( array( 'tasks' => 'task_ids', 'tags' => 'tag_ids', 'segments' => 'segment_ids' ) as $kind => $key ) {
			foreach ( 'tasks' === $kind ? array( 'complete', 'reopen', 'delete' ) : array( 'delete' ) as $action ) {
				$requests[] = array( 'bulk' => $kind, 'method' => 'POST', 'data' => array( 'action' => $action, $key => array( 1 ), '_wpnonce' => wp_create_nonce( 'bulk-yoohw_cos_' . $kind ), 'yoohw_cos_epoch' => $lists[ ucfirst( $kind ) ]->selection_epoch ) );
			}
		}
		foreach ( $requests as $request ) {
			$result = $this->fresh_action( $request + array( 'user' => $user ) );
			$this->assertSame( $before, $this->ordinary_rows(), wp_json_encode( $request ) );
			$this->assertSame( 0, $result['mail'] );
			if ( 'handle_send_customer_email' === ( $request['handler'] ?? '' ) ) {
				$this->assertStringContainsString( 'Reload', $result['body'] );
				$this->assertStringContainsString( '"success":false', $result['body'] );
			} else {
				$this->assertSame( 409, $result['status'], wp_json_encode( $result ) );
				$this->assertStringContainsString( 'Reload', $result['message'] );
			}
		}
		// Missing, malformed (including arrays), and valid reloaded HTTP submissions.
		$request = array( 'user' => $user, 'handler' => 'handle_add_customer_note', 'method' => 'POST', 'data' => $forms['yoohw_cos_add_customer_note'] + array( 'customer_note' => 'Reloaded note' ) );
		foreach ( array( null, 'bad', array(), '' ) as $invalid ) {
			unset( $request['data']['yoohw_cos_epoch'] );
			if ( null !== $invalid ) { $request['data']['yoohw_cos_epoch'] = $invalid; }
			$this->assertSame( 409, $this->fresh_action( $request )['status'] );
			$this->assertSame( $before, $this->ordinary_rows() );
		}
		$request['data']['yoohw_cos_epoch'] = YoOhw_COS_Reset_Guard::epoch();
		$this->assertSame( 302, $this->fresh_action( $request )['status'] );
		$this->assertSame( 2, YoOhw_COS_Notes::get_customer_note_count( $new[0] ) );
	}

	public function test_ordinary_writer_critical_section_and_stale_request_use_real_connections(): void {
		global $wpdb;
		$ids = $this->ordinary_fixture();
		$wpdb->query( 'COMMIT' );
		list( $process, $pipes ) = $this->worker( 'hold-ordinary', $ids[0] );
		try {
			$this->assertSame( "LOCKED\n", fgets( $pipes[1] ) );
			try {
				YoOhw_COS_Customers::reset_data();
				$this->fail( 'Reset must not interleave between ordinary reference read and INSERT.' );
			} catch ( RuntimeException $exception ) { $this->assertStringContainsString( 'busy', $exception->getMessage() ); }
			fwrite( $pipes[0], "GO\n" );
			fclose( $pipes[0] );
			$this->assertStringContainsString( 'NOTE:2', stream_get_contents( $pipes[1] ) );
		} finally {
			foreach ( $pipes as $pipe ) { if ( is_resource( $pipe ) ) { fclose( $pipe ); } }
			proc_terminate( $process ); proc_close( $process );
		}
		$this->assertSame( 2, YoOhw_COS_Notes::get_customer_note_count( $ids[0] ) );
		list( $process, $pipes ) = $this->worker( 'stale-ordinary', $ids[0] );
		try {
			$this->assertSame( "READY\n", fgets( $pipes[1] ) );
			$new = $this->ordinary_fixture();
			$wpdb->query( 'COMMIT' );
			$before = $this->ordinary_rows();
			fwrite( $pipes[0], "GO\n" ); fclose( $pipes[0] );
			$this->assertStringContainsString( 'RESULT:0', stream_get_contents( $pipes[1] ) );
			$wpdb->query( 'COMMIT' );
			$this->assertSame( $before, $this->ordinary_rows() );
		} finally {
			foreach ( $pipes as $pipe ) { if ( is_resource( $pipe ) ) { fclose( $pipe ); } }
			proc_terminate( $process ); proc_close( $process );
		}
	}

	public function test_reset_cannot_split_profile_snapshot_and_rendered_epoch(): void {
		global $wpdb;
		$ids = $this->ordinary_fixture();
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $user )->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $user );
		$epoch = YoOhw_COS_Reset_Guard::epoch();
		$wpdb->query( 'COMMIT' );
		$observed = '';
		$pause = function( $translation, $text ) use ( &$observed ) {
			if ( 'Internal notes' === $text && '' === $observed ) {
				global $wpdb;
				$wpdb->query( 'COMMIT' );
				list( $process, $pipes ) = $this->worker( 'reset' );
				fclose( $pipes[0] );
				$observed = stream_get_contents( $pipes[1] );
				$error = stream_get_contents( $pipes[2] );
				fclose( $pipes[1] ); fclose( $pipes[2] );
				$this->assertSame( 0, proc_close( $process ), $error );
			}
			return $translation;
		};
		add_filter( 'gettext', $pause, 10, 2 );
		ob_start();
		try {
			YoOhw_COS_Customer_Profile::render( $ids[0] );
			$html = ob_get_contents();
		} finally { ob_end_clean(); remove_filter( 'gettext', $pause, 10 ); }
		$this->assertStringContainsString( 'BUSY', $observed );
		$this->assertStringContainsString( 'name="yoohw_cos_epoch" value="' . $epoch . '"', $html );
		$this->assertSame( $epoch, YoOhw_COS_Reset_Guard::epoch() );
		// Nested service calls must not release the snapshot owner's lock.
		$this->assertTrue( YoOhw_COS_Reset_Guard::enter() );
		try {
			$this->assertGreaterThan( 0, YoOhw_COS_Notes::add_note( $ids[0], 'Nested note' ) );
			$this->assertNotNull( $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', YoOhw_COS_Reset_Guard::lock_name() ) ) );
		} finally { YoOhw_COS_Reset_Guard::leave(); }
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', YoOhw_COS_Reset_Guard::lock_name() ) ) );
	}

	public function test_explicit_empty_legacy_epoch_missing_permission_and_nonce_controls(): void {
		global $wpdb;
		$ids = $this->ordinary_fixture();
		delete_option( YoOhw_COS_Reset_Guard::OPTION );
		YoOhw_COS_Reset_Guard::init();
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $user )->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $user );
		$wpdb->query( 'COMMIT' );
		$request = array( 'user' => $user, 'handler' => 'handle_add_customer_note', 'method' => 'POST', 'data' => array( 'customer_id' => $ids[0], 'customer_note' => 'Legacy note', '_wpnonce' => wp_create_nonce( 'yoohw_cos_add_customer_note' ) ) );
		$this->assertSame( 409, $this->fresh_action( $request )['status'] );
		$this->assertSame( 1, YoOhw_COS_Notes::get_customer_note_count( $ids[0] ) );
		$request['data']['yoohw_cos_epoch'] = '';
		$this->assertSame( 302, $this->fresh_action( $request )['status'] );
		$this->assertSame( 2, YoOhw_COS_Notes::get_customer_note_count( $ids[0] ) );
		$request['data']['_wpnonce'] = 'invalid';
		$this->assertSame( 403, $this->fresh_action( $request )['status'] );
		$request['user'] = 0;
		$this->assertNotSame( 302, $this->fresh_action( $request )['status'] );
		$this->assertSame( 2, YoOhw_COS_Notes::get_customer_note_count( $ids[0] ) );
	}

	public function test_ajax_selector_keeps_original_form_epoch_and_reloaded_results(): void {
		global $wpdb;
		$ids = $this->ordinary_fixture();
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $user )->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $user );
		$epoch = YoOhw_COS_Reset_Guard::epoch();
		$wpdb->query( 'COMMIT' );
		$request = array( 'search' => true, 'user' => $user, 'handler' => 'handle_customer_search', 'method' => 'GET', 'data' => array( 'security' => wp_create_nonce( 'yoohw_cos_search_customers' ), 'term' => 'ordinary', 'selection' => '1', 'yoohw_cos_epoch' => $epoch ) );
		$result = $this->fresh_action( $request );
		$this->assertStringContainsString( 'Rebuilt record', $result['body'] );
		$this->ordinary_fixture();
		$wpdb->query( 'COMMIT' );
		$before = $this->ordinary_rows();
		$result = $this->fresh_action( $request );
		$this->assertStringContainsString( '"success":false', $result['body'] );
		$this->assertStringContainsString( 'Reload', $result['body'] );
		$this->assertSame( $before, $this->ordinary_rows() );
		unset( $request['data']['yoohw_cos_epoch'] );
		$this->assertStringContainsString( '"success":false', $this->fresh_action( $request )['body'] );
		$request['data']['yoohw_cos_epoch'] = YoOhw_COS_Reset_Guard::epoch();
		$this->assertStringContainsString( 'Rebuilt record', $this->fresh_action( $request )['body'] );
	}

	public function test_remaining_action_renderers_carry_coherent_selection_epochs(): void {
		global $wpdb;
		$ids = $this->ordinary_fixture();
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $user )->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $user );
		// Render fixture only: existing assigned-task email trigger calls missing send_notification (CIT-A03).
		$wpdb->update( YoOhw_COS_DB::tasks_table(), array( 'assigned_user_id' => $user ), array( 'id' => $ids[2] ) );
		$epoch = YoOhw_COS_Reset_Guard::epoch();
		$saved_get = $_GET; $saved_post = $_POST; $saved_request = $_REQUEST;
		$_GET = $_POST = $_REQUEST = array();
		set_current_screen( 'woocommerce_page_yoohw-customer-intelligence' );
		try {
			foreach ( array( 'customers', 'tasks', 'tags', 'segments' ) as $kind ) {
				ob_start();
				call_user_func( array( 'YoOhw_COS_Admin_Menu', 'render_' . $kind . '_page' ) );
				$html = ob_get_clean();
				$this->assertStringContainsString( 'name="yoohw_cos_epoch" value="' . $epoch . '"', $html, $kind );
			}
			ob_start();
			YoOhw_COS_Admin_Menu::render_dashboard_tasks_widget();
			$dashboard = ob_get_clean();
			$this->assertStringContainsString( 'yoohw_cos_epoch=' . $epoch, $dashboard );
			$tasks = YoOhw_COS_Reset_Guard::snapshot_rows( array( 'YoOhw_COS_Overview', 'get_priority_tasks' ) );
			$render = new ReflectionMethod( 'YoOhw_COS_Admin_Menu', 'render_priority_tasks_panel' );
			$render->setAccessible( true );
			ob_start(); $render->invoke( null, $tasks ); $overview = ob_get_clean();
			$this->assertStringContainsString( 'yoohw_cos_epoch=' . $epoch, $overview );
			$order = wc_create_order();
			$order->set_billing_email( 'ordinary@example.test' );
			$order->save();
			YoOhw_COS_Customers::sync_from_order( $order );
			YoOhw_COS_Tasks::update_task( $ids[2], array( 'order_id' => $order->get_id() ) );
			ob_start(); YoOhw_COS_Order_Admin::render_task_metabox( $order ); $metabox = ob_get_clean();
			$this->assertStringContainsString( 'name="yoohw_cos_epoch" value="' . $epoch . '" form="yoohw-cos-order-task-form"', $metabox );
			$this->assertStringContainsString( 'yoohw_cos_epoch=' . $epoch, $metabox );
		} finally { $_GET = $saved_get; $_POST = $saved_post; $_REQUEST = $saved_request; }
	}

}
