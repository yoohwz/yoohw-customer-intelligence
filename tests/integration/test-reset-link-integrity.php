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

	public function test_current_guest_customer_message_requires_manager_nonce_and_selection(): void {
		global $wpdb;
		$ids = $this->ordinary_fixture();
		WC_Install::create_roles();
		$GLOBALS['wp_roles'] = new WP_Roles();
		$user = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		wp_set_current_user( $user );
		$this->assertFalse( get_user_by( 'email', 'ordinary@example.test' ) );
		$request = array( 'user' => $user, 'handler' => 'handle_send_customer_email', 'method' => 'POST', 'data' => array( 'customer_id' => $ids[0], 'security' => wp_create_nonce( 'yoohw_cos_send_customer_email' ), 'yoohw_cos_epoch' => YoOhw_COS_Reset_Guard::epoch(), 'email_subject' => 'Synthetic guest subject', 'email_message' => 'Synthetic guest message' ) );
		$wpdb->query( 'COMMIT' );
		$result = $this->fresh_action( $request );
		$this->assertStringContainsString( '"success":true', $result['body'] );
		$this->assertSame( 1, $result['mail'] );
		$request['data']['security'] = 'invalid';
		$result = $this->fresh_action( $request );
		$this->assertSame( 0, $result['mail'] );
		$this->assertSame( 403, $result['status'] );
		$request['user'] = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$wpdb->query( 'COMMIT' );
		$result = $this->fresh_action( $request );
		$this->assertSame( 0, $result['mail'] );
		$this->assertStringContainsString( '"success":false', $result['body'] );
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

	public static function segment_http_cases(): array {
		return array_map( static function( $case ) { return array( $case ); }, array( 'named', 'nojs', 'id', 'mixed', 'limit', 'over' ) );
	}

	private function correction_user(): int {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $user )->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $user );
		return $user;
	}

	/** @dataProvider segment_http_cases */
	public function test_segment_http_valid_inputs_and_atomic_name_limit( string $case ): void {
		global $wpdb;
		$ids = $this->ordinary_fixture();
		$user = $this->correction_user();
		$existing = YoOhw_COS_Segments::create_segment( 'Existing selection' );
		$data = array( 'customer_id' => $ids[0], '_wpnonce' => wp_create_nonce( 'yoohw_cos_assign_customer_segment' ), 'yoohw_cos_epoch' => YoOhw_COS_Reset_Guard::epoch() );
		$names = array( 'Round3 ' . $case . ' Alpha', 'Round3 ' . $case . ' Beta' );
		if ( in_array( $case, array( 'limit', 'over' ), true ) ) {
			$names = array_map( static function( $i ) use ( $case ) { return 'Bounded ' . $case . ' ' . $i; }, range( 1, 'limit' === $case ? 100 : 101 ) );
		}
		if ( 'id' !== $case ) { $data[ 'nojs' === $case ? 'segment_name_nojs' : 'segment_name' ] = implode( ',', $names ) . ',' . $names[0]; }
		if ( in_array( $case, array( 'id', 'mixed', 'over' ), true ) ) { $data['segment_id'] = $existing; }
		$wpdb->query( 'COMMIT' );
		$before = $this->ordinary_rows();
		$result = $this->fresh_action( array( 'user' => $user, 'handler' => 'handle_assign_customer_segment', 'method' => 'POST', 'data' => $data ) );
		$this->assertSame( 0, $result['mail'] );
		$this->assertSame( 'over' === $case ? 400 : 302, $result['status'], wp_json_encode( $result ) );
		if ( 'over' === $case ) {
			$this->assertSame( $before, $this->ordinary_rows(), 'Reject all input before assigning even the valid existing ID.' );
			return;
		}
		$this->assertStringContainsString( 'segment_added=1', $result['message'] );
		$after = $this->ordinary_rows();
		$created = 'id' === $case ? 0 : count( $names );
		$assigned = $created + ( isset( $data['segment_id'] ) ? 1 : 0 );
		$this->assertCount( count( $before['segments'] ) + $created, $after['segments'] );
		$this->assertCount( count( $before['customer_segments'] ) + $assigned, $after['customer_segments'] );
		$this->assertCount( count( $before['events'] ) + $assigned, $after['events'] );
		foreach ( array_slice( $after['events'], count( $before['events'] ) ) as $event ) {
			$this->assertSame( 'segment_assigned', $event['event_type'] );
			$this->assertSame( $ids[0], (int) $event['customer_id'] );
		}
	}

	private function correction_link( string $html, string $action, bool $force ): array {
		$dom = new DOMDocument();
		@$dom->loadHTML( $html );
		foreach ( ( new DOMXPath( $dom ) )->query( '//a[@href]' ) as $link ) {
			parse_str( (string) parse_url( html_entity_decode( $link->getAttribute( 'href' ) ), PHP_URL_QUERY ), $args );
			if ( $action === ( $args['action'] ?? '' ) && $force === ! empty( $args['force'] ) ) { return $args; }
		}
		return array();
	}

	private function correction_page( string $kind, int $user, array $data = array() ): array {
		return $this->fresh_action( array( 'user' => $user, 'render_kind' => $kind, 'method' => 'GET', 'data' => $data ) );
	}

	private function correction_warning( string $kind, int $user ): array {
		$page = $this->correction_page( $kind, $user, array( 's' => 'Ordinary ' . $kind ) );
		$this->assertSame( 200, $page['status'], wp_json_encode( $page ) );
		$row = $this->correction_link( $page['body'], 'yoohw_cos_delete_' . $kind, false );
		$this->assertNotEmpty( $row );
		$result = $this->fresh_action( array( 'user' => $user, 'handler' => 'handle_delete_' . $kind, 'method' => 'GET', 'data' => $row ) );
		$this->assertSame( 302, $result['status'], wp_json_encode( $result ) );
		parse_str( (string) parse_url( $result['message'], PHP_URL_QUERY ), $warning );
		$this->assertArrayHasKey( 'yoohw_' . $kind . '_delete_block', $warning );
		return $warning;
	}

	public static function confirmation_cases(): array {
		$cases = array();
		foreach ( array( 'tag', 'segment' ) as $kind ) {
			foreach ( array( 'current', 'legacy', 'before_warning', 'after_warning', 'pending', 'missing', 'malformed' ) as $stage ) { $cases[] = array( $kind, $stage ); }
		}
		return $cases;
	}

	/** @dataProvider confirmation_cases */
	public function test_real_force_confirmation_chain_preserves_original_generation( string $kind, string $stage ): void {
		global $wpdb;
		$ids = $this->ordinary_fixture();
		$user = $this->correction_user();
		if ( 'legacy' === $stage ) { delete_option( YoOhw_COS_Reset_Guard::OPTION ); YoOhw_COS_Reset_Guard::init(); }
		$epoch = YoOhw_COS_Reset_Guard::epoch();
		$wpdb->query( 'COMMIT' );
		$warning = $this->correction_warning( $kind, $user );
		if ( 'before_warning' === $stage ) {
			$this->ordinary_fixture(); $wpdb->query( 'COMMIT' );
		} elseif ( 'pending' === $stage ) {
			update_option( YoOhw_COS_Reset_Guard::OPTION, array( 'epoch' => $epoch, 'status' => 'pending' ), false ); $wpdb->query( 'COMMIT' );
		} elseif ( 'missing' === $stage ) {
			unset( $warning['yoohw_cos_epoch'] );
		} elseif ( 'malformed' === $stage ) {
			$warning['yoohw_cos_epoch'] = array( 'bad' );
		}
		$before = $this->ordinary_rows();
		$page = $this->correction_page( $kind, $user, $warning );
		$this->assertSame( 0, $page['mail'] );
		$force = $this->correction_link( $page['body'], 'yoohw_cos_delete_' . $kind, true );
		if ( in_array( $stage, array( 'before_warning', 'pending', 'missing', 'malformed' ), true ) ) {
			$this->assertEmpty( $force, 'Invalid warning context must not mint a destructive confirmation link.' );
			$this->assertStringContainsString( 'Reload', $page['body'] . $page['message'] );
			$this->assertSame( $before, $this->ordinary_rows() );
			if ( 'pending' === $stage ) { YoOhw_COS_Customers::reset_data(); $this->ordinary_fixture(); $wpdb->query( 'COMMIT' ); }
			// Genuine reload/reselection obtains its own row, warning and confirmation.
			$warning = $this->correction_warning( $kind, $user );
			$page = $this->correction_page( $kind, $user, $warning );
			$force = $this->correction_link( $page['body'], 'yoohw_cos_delete_' . $kind, true );
		} elseif ( 'after_warning' === $stage ) {
			$this->ordinary_fixture(); $wpdb->query( 'COMMIT' );
			$before = $this->ordinary_rows();
			$result = $this->fresh_action( array( 'user' => $user, 'handler' => 'handle_delete_' . $kind, 'method' => 'GET', 'data' => $force ) );
			$this->assertSame( 409, $result['status'] );
			$this->assertSame( 0, $result['mail'] );
			$this->assertSame( $before, $this->ordinary_rows() );
			$warning = $this->correction_warning( $kind, $user );
			$page = $this->correction_page( $kind, $user, $warning );
			$force = $this->correction_link( $page['body'], 'yoohw_cos_delete_' . $kind, true );
		}
		$this->assertNotEmpty( $force );
		$this->assertArrayHasKey( 'yoohw_cos_epoch', $force );
		$this->assertSame( $warning['yoohw_cos_epoch'], $force['yoohw_cos_epoch'] );
		$this->assertSame( YoOhw_COS_Reset_Guard::epoch(), $force['yoohw_cos_epoch'] );
		$before = $this->ordinary_rows();
		$result = $this->fresh_action( array( 'user' => $user, 'handler' => 'handle_delete_' . $kind, 'method' => 'GET', 'data' => $force ) );
		$this->assertSame( 302, $result['status'], wp_json_encode( $result ) );
		$this->assertStringContainsString( 'yoohw_' . $kind . '_deleted=1', $result['message'] );
		$this->assertSame( 0, $result['mail'] );
		$after = $this->ordinary_rows();
		$this->assertCount( count( $before[ $kind . 's' ] ) - 1, $after[ $kind . 's' ] );
		$this->assertEmpty( $after[ 'customer_' . $kind . 's' ] );
		unset( $before[ $kind . 's' ], $before[ 'customer_' . $kind . 's' ], $after[ $kind . 's' ], $after[ 'customer_' . $kind . 's' ] );
		$this->assertSame( $before, $after, 'Unrelated CRM records/events and other relationship family remain intact.' );
	}

	public static function confirmation_families(): array { return array( array( 'tag' ), array( 'segment' ) ); }

	/** @dataProvider confirmation_families */
	public function test_warning_reads_fresh_count_and_excludes_reset_during_render( string $kind ): void {
		global $wpdb;
		$this->ordinary_fixture();
		$user = $this->correction_user();
		$epoch = YoOhw_COS_Reset_Guard::epoch();
		$wpdb->query( 'COMMIT' );
		$warning = $this->correction_warning( $kind, $user );
		$warning[ $kind . '_customer_count' ] = '999'; // A redirect's old/display count is not a fresh reference read.
		$before = $this->ordinary_rows();
		$result = $this->fresh_action( array( 'user' => $user, 'render_kind' => $kind, 'probe_reset_warning' => true, 'method' => 'GET', 'data' => $warning ) );
		$this->assertSame( 'BUSY', $result['reset'] );
		$this->assertSame( 200, $result['status'] );
		$this->assertStringContainsString( 'assigned to 1 customers', $result['body'] );
		$this->assertStringNotContainsString( 'assigned to 999 customers', $result['body'] );
		$force = $this->correction_link( $result['body'], 'yoohw_cos_delete_' . $kind, true );
		$this->assertSame( $epoch, $force['yoohw_cos_epoch'] );
		$this->assertSame( $before, $this->ordinary_rows() );
	}

}

/** CSV producer checks use the admitted environment and an independent Python decoder. */
final class YCI_CSV_Export_Safety_Test extends WP_UnitTestCase {
	private function snapshot(): array {
		global $wpdb;
		$rows = array();
		foreach ( array( 'customers', 'notes', 'tasks', 'tags', 'segments', 'customer_tags', 'customer_segments', 'events', 'order_facts' ) as $table ) {
			$rows[ $table ] = $wpdb->get_results( 'SELECT * FROM `' . call_user_func( array( 'YoOhw_COS_DB', $table . '_table' ) ) . '` ORDER BY 1', ARRAY_A );
		}
		return $rows;
	}

	private function request( array $options = array(), array $data = array() ): array {
		global $wpdb;
		$user = self::factory()->user->create( array( 'role' => $options['role'] ?? 'administrator' ) );
		wp_set_current_user( $user );
		$data += array( 'yoohw_cos_export_customers' => '1', 'yoohw_cos_customers_export_nonce' => wp_create_nonce( 'yoohw_cos_export_customers' ), 'orderby' => 'display_name', 'order' => 'ASC' );
		if ( ! empty( $options['missing_nonce'] ) ) { unset( $data['yoohw_cos_customers_export_nonce'] ); }
		$wpdb->query( 'COMMIT' );
		$before = $this->snapshot();
		$process = proc_open( array( PHP_BINARY, '-d', 'disable_functions=mail', dirname( __DIR__ ) . '/reset-worker.php', 'csv-request' ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $process );
		fwrite( $pipes[0], wp_json_encode( array( 'user' => $user, 'data' => $data ) + $options ) . "\n" );
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] ); fclose( $pipes[2] );
		$this->assertSame( 0, proc_close( $process ), $error );
		$this->assertSame( '', $error );
		$result = json_decode( $output, true );
		$this->assertIsArray( $result, $output );
		$this->assertSame( 0, $result['mail'] );
		$wpdb->query( 'COMMIT' );
		$this->assertSame( $before, $this->snapshot(), 'Export must not mutate product rows or events.' );
		$result['bytes'] = base64_decode( $result['csv'], true );
		return $result;
	}

	private function decode( array $result ): array {
		$this->assertSame( 200, $result['status'], $result['message'] );
		$this->assertSame( "\xEF\xBB\xBF", substr( $result['bytes'], 0, 3 ) );
		$process = proc_open( array( 'python3', '-c', 'import csv,io,json,sys; print(json.dumps(list(csv.reader(io.StringIO(sys.stdin.buffer.read().decode("utf-8-sig"), newline=""), strict=True)), ensure_ascii=False))' ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $process );
		fwrite( $pipes[0], $result['bytes'] ); fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] ); $error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] ); fclose( $pipes[2] );
		$this->assertSame( 0, proc_close( $process ), $error );
		$rows = json_decode( $output, true );
		$this->assertIsArray( $rows );
		foreach ( $rows as $row ) { $this->assertCount( 12, $row ); }
		return $rows;
	}

	private function fixture( array $values = array() ): int {
		global $wpdb;
		YoOhw_COS_Customers::reset_data();
		$id = YoOhw_COS_Customers::create_customer( array( 'display_name' => 'CSV fixture' ) );
		$this->assertGreaterThan( 0, $id );
		$this->assertNotFalse( $wpdb->update( YoOhw_COS_DB::customers_table(), $values + array( 'display_name' => 'CSV fixture', 'email' => 'csv@example.test', 'phone' => '', 'total_orders' => 2, 'total_spent' => '-12.50', 'average_order_value' => '-6.25', 'risk_score' => '0', 'trust_score' => '95.25' ), array( 'id' => $id ) ) );
		return $id;
	}

	public static function text_cases(): array {
		return array(
			'equals' => array( '=1+1', "\t=1+1" ),
			'plus' => array( '+1+1', "\t+1+1" ),
			'minus' => array( '-1+1', "\t-1+1" ),
			'at' => array( '@SUM(1)', "\t@SUM(1)" ),
			'ascii whitespace' => array( " \t\r\n=1+1", "\t=1+1" ),
			'control' => array( "\x01=1+1", "\t\x01=1+1" ),
			'nbsp' => array( "\u{00A0}=1+1", "\t\u{00A0}=1+1" ),
			'unicode space' => array( "\u{2003}＋1", "\t\u{2003}＋1" ),
			'zero width' => array( "\u{FEFF}\u{200B}@SUM(1)", "\t\u{FEFF}\u{200B}@SUM(1)" ),
			'fullwidth equals' => array( '＝1+1', "\t＝1+1" ),
			'fullwidth minus' => array( '－1', "\t－1" ),
			'fullwidth at' => array( '＠SUM(1)', "\t＠SUM(1)" ),
			'quote slash delimiters' => array( '=1+1\\",@SUM(1);\'x', "\t=1+1\\\",@SUM(1);'x" ),
			'normal slash quote' => array( 'Name\\",=1+1', 'Name\\",=1+1' ),
			'vietnamese' => array( 'Nguyễn Bảo', 'Nguyễn Bảo' ),
			'apostrophe' => array( "'Name", "'Name" ),
			'ordinary number name' => array( '123', '123' ),
			'line normalization' => array( "Normal\nname", 'Normal name' ),
		);
	}

	/** @dataProvider text_cases */
	public function test_real_export_text_matrix( string $input, string $expected ): void {
		$id = $this->fixture( array( 'display_name' => $input ) );
		$tag = YoOhw_COS_Tags::create_tag( 'CSV fixture tag ' . $id );
		$segment = YoOhw_COS_Segments::create_segment( 'CSV fixture segment ' . $id );
		global $wpdb;
		$wpdb->update( YoOhw_COS_DB::tags_table(), array( 'name' => $input ), array( 'id' => $tag ) );
		$wpdb->update( YoOhw_COS_DB::segments_table(), array( 'name' => $input ), array( 'id' => $segment ) );
		YoOhw_COS_Tags::assign_tag( $id, $tag, 0, false );
		YoOhw_COS_Segments::assign_customer( $id, $segment, 0, false );
		$result = $this->request();
		$rows = $this->decode( $result );
		$this->assertCount( 2, $rows );
		foreach ( array( 0, 10, 11 ) as $column ) { $this->assertSame( $expected, $rows[1][$column] ); }
		$this->assertSame( array( '2', '-12.50', '-6.25', '0.00', '95.25' ), array_slice( $rows[1], 3, 5 ) );
		if ( "\t" === substr( $expected, 0, 1 ) ) {
			$this->assertStringContainsString( '"' . str_replace( '"', '""', $expected ) . '"', $result['bytes'], 'TAB must be inside the quoted field with enclosure doubling.' );
		}
	}

	public function test_contacts_fallback_joined_and_translated_final_text(): void {
		$id = $this->fixture( array( 'display_name' => null, 'first_name' => '=1', 'last_name' => 'Nguyễn', 'email' => '+csv@example.test', 'phone' => '+84901234567' ) );
		$a = YoOhw_COS_Tags::create_tag( '=CSV' ); $b = YoOhw_COS_Tags::create_tag( 'Second; tag' );
		YoOhw_COS_Tags::assign_tag( $id, $a, 0, false ); YoOhw_COS_Tags::assign_tag( $id, $b, 0, false );
		$rows = $this->decode( $this->request( array( 'translations' => array( 'Name' => '=Header', 'New' => "\r\n=1+1", 'Standard' => '＠Tier' ) ) ) );
		$this->assertSame( "\t=Header", $rows[0][0] );
		$this->assertSame( "\t=1 Nguyễn", $rows[1][0] );
		$this->assertSame( "\t+csv@example.test", $rows[1][1] );
		$this->assertSame( "\t+84901234567", $rows[1][2] );
		$this->assertSame( "\t＠Tier", $rows[1][8] );
		$this->assertSame( "\t\r\n=1+1", $rows[1][9] );
		$this->assertSame( "\t=CSV; Second; tag", $rows[1][10] );
		global $wpdb;
		$wpdb->update( YoOhw_COS_DB::customers_table(), array( 'first_name' => null, 'last_name' => '', 'phone' => '001234567890', 'email' => null ), array( 'id' => $id ) );
		$rows = $this->decode( $this->request( array( 'translations' => array( '(No name)' => '-Unnamed' ) ) ) );
		$this->assertSame( "\t-Unnamed", $rows[1][0] );
		$this->assertSame( '', $rows[1][1] );
		$this->assertSame( "\t001234567890", $rows[1][2] );
	}

	public function test_authorization_filters_limit_empty_and_help(): void {
		$id = $this->fixture();
		foreach ( array( array( 'missing_nonce' => true ), array( 'role' => 'subscriber' ) ) as $options ) {
			$result = $this->request( $options );
			$this->assertSame( 403, $result['status'] ); $this->assertSame( '', $result['bytes'] );
		}
		$result = $this->request( array(), array( 'yoohw_cos_customers_export_nonce' => 'bad' ) );
		$this->assertSame( 403, $result['status'] ); $this->assertSame( '', $result['bytes'] );
		YoOhw_COS_Customers::create_customer( array( 'display_name' => 'AAA second CSV' ) );
		$rows = $this->decode( $this->request( array( 'limit' => 1 ) ) );
		$this->assertCount( 2, $rows ); $this->assertSame( 'AAA second CSV', $rows[1][0] );
		$rows = $this->decode( $this->request( array(), array( 's' => 'no matching CSV row' ) ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( array( 'Name', 'Email', 'Phone', 'Orders', 'Spent', 'AOV', 'Risk score', 'Trust score', 'Value tier', 'Lifecycle', 'Tags', 'Segments' ), $rows[0] );
		global $wpdb;
		$wpdb->update( YoOhw_COS_DB::customers_table(), array( 'archived_at' => '2026-01-01 00:00:00' ), array( 'id' => $id ) );
		$rows = $this->decode( $this->request( array(), array( 'customer_view' => 'archived' ) ) );
		$this->assertCount( 2, $rows ); $this->assertSame( 'CSV fixture', $rows[1][0] );
		$result = $this->request( array( 'help' => true ), array( 'page' => 'yoohw-customer-intelligence', 'yoohw_cos_export_customers' => '0' ) );
		$this->assertSame( 200, $result['status'], $result['message'] );
		$this->assertStringContainsString( 'Export CSV', $result['bytes'] );
		$this->assertStringContainsString( 'protective TAB', $result['bytes'] );
	}
}

final class YCI_Notification_Recipient_Test extends WP_UnitTestCase {
	private $messages = array();
	private $transport_result = true;

	public function set_up(): void {
		parent::set_up();
		WC()->mailer();
		// Reload role objects after the WC installer writes its role capabilities.
		WC_Install::create_roles();
		$GLOBALS['wp_roles'] = new WP_Roles();
		$this->messages = array();
		$this->transport_result = true;
		$GLOBALS['yci_fixture_mail_observer'] = function( $to, $subject, $message ) {
			$this->messages[] = array( 'to' => $to, 'subject' => $subject, 'message' => $message );
			return $this->transport_result;
		};
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		unset( $GLOBALS['yci_fixture_mail_observer'] );
		$this->messages = array();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function staff( string $role = 'shop_manager' ): int {
		$id = self::factory()->user->create( array( 'role' => $role ) );
		if ( 'subscriber' !== $role ) { $this->assertTrue( user_can( $id, 'manage_woocommerce' ), 'Fixture staff starts with effective CRM access.' ); }
		return $id;
	}

	private function task(): array {
		return array( 'id' => 13, 'title' => 'Synthetic confidential follow-up', 'customer_name' => 'Synthetic customer', 'due_date' => '2026-01-01 10:00:00', 'priority' => 'high', 'status' => 'open' );
	}

	private function delivered_to( array $ids, array $extra = array() ): void {
		$expected = $extra;
		foreach ( $ids as $id ) {
			$expected[] = get_userdata( $id )->user_email;
		}
		$actual = array();
		foreach ( $this->messages as $message ) {
			$actual = array_merge( $actual, array_map( 'trim', explode( ',', $message['to'] ) ) );
			$this->assertTrue( strpos( $message['message'], 'Synthetic confidential follow-up' ) !== false, 'Real template contains the synthetic task.' );
		}
		sort( $expected ); sort( $actual );
		$this->assertTrue( $expected === $actual, 'Only expected synthetic destinations reach intercepted transport.' );
	}

	public function test_send_prerequisite_renders_and_reports_transport(): void {
		$id = $this->staff();
		$email = new YoOhw_COS_Email_Task_Assigned();
		$this->assertTrue( $email->trigger( $this->task(), $id ) );
		$this->delivered_to( array( $id ) );
		$this->transport_result = false;
		$this->assertFalse( $email->trigger( $this->task(), $id ) );
		$this->assertCount( 2, $this->messages );
		$email->enabled = 'no';
		$this->assertFalse( $email->trigger( $this->task(), $id ) );
		$this->assertCount( 2, $this->messages );
	}

	public function test_revoked_recipient_never_reaches_transport(): void {
		$id = $this->staff();
		( new WP_User( $id ) )->set_role( 'subscriber' );
		$email = new YoOhw_COS_Email_Task_Assigned();
		$result = $email->trigger( $this->task(), $id );
		$this->assertCount( 0, $this->messages, 'Revoked staff must receive no task payload.' );
		$this->assertFalse( $result );
	}
	public function test_every_family_reloads_capabilities_and_clears_reused_state(): void {
		$a = $this->staff( 'administrator' );
		$b = $this->staff( 'subscriber' );
		( new WP_User( $b ) )->add_cap( 'manage_woocommerce' );
		$actor = $this->staff( 'subscriber' );
		foreach ( array( 'Task_Assigned', 'Task_Reassigned', 'Task_Due_Soon', 'Task_Completed', 'Task_Reopened', 'Task_Overdue', 'Daily_Followup_Summary' ) as $family ) {
			$class = 'YoOhw_COS_Email_' . $family;
			$email = new $class();
			$email->enabled = 'yes';
			$payload = $email instanceof YoOhw_COS_Email_Task_Digest ? array( $this->task() ) : $this->task();
			( new WP_User( $a ) )->remove_cap( 'manage_woocommerce' );
			$this->messages = array();
			wp_set_current_user( 0 );
			$this->assertTrue( $email->trigger( $payload, $a ), $family );
			( new WP_User( $a ) )->add_cap( 'manage_woocommerce', false );
			$this->assertContains( 'administrator', ( new WP_User( $a ) )->roles );
			$this->assertFalse( $email->trigger( $payload, $a ), $family );
			$this->assertSame( '', $email->recipient );
			$this->assertFalse( $email->recipient_user );
			$this->assertSame( array(), $email->task );
			$this->assertSame( array(), $email->tasks );
			wp_set_current_user( $actor );
			$this->assertTrue( $email->trigger( $payload, $b ), $family );
			$this->delivered_to( array( $a, $b ) );
			$this->assertFalse( $email->trigger( array(), $b ) );
			$this->assertSame( '', $email->recipient );
		}
	}

	public function test_deleted_and_invalid_accounts_and_disabled_defaults(): void {
		$email = new YoOhw_COS_Email_Task_Assigned();
		$id = $this->staff();
		wp_delete_user( $id );
		$this->assertFalse( $email->trigger( $this->task(), $id ) );
		$this->assertFalse( $email->trigger( $this->task(), 0 ) );
		$id = $this->staff();
		global $wpdb;
		$wpdb->update( $wpdb->users, array( 'user_email' => 'invalid' ), array( 'ID' => $id ) );
		clean_user_cache( $id );
		$this->assertFalse( $email->trigger( $this->task(), $id ) );
		$this->assertCount( 0, $this->messages );
		$id = $this->staff();
		$this->assertFalse( ( new YoOhw_COS_Email_Task_Completed() )->trigger( $this->task(), $id ) );
		$this->assertFalse( ( new YoOhw_COS_Email_Task_Overdue_Escalation() )->trigger( array( $this->task() ), $id ) );
		$this->assertCount( 0, $this->messages );
	}

	public function test_changes_during_render_and_final_parameters_reject_stale_payload(): void {
		$id = $this->staff();
		$email = new YoOhw_COS_Email_Task_Assigned();
		foreach ( array( 'woocommerce_email_subject_' . $email->id, 'woocommerce_mail_content', 'woocommerce_mail_callback_params' ) as $hook ) {
			( new WP_User( $id ) )->set_role( 'shop_manager' );
			$revoke = static function( $value ) use ( $id ) { ( new WP_User( $id ) )->set_role( 'subscriber' ); return $value; };
			add_filter( $hook, $revoke );
			try {
				$this->assertFalse( $email->trigger( $this->task(), $id ), $hook );
			} finally { remove_filter( $hook, $revoke ); }
			$this->assertCount( 0, $this->messages );
			$this->assertSame( '', $email->recipient );
		}
		( new WP_User( $id ) )->set_role( 'shop_manager' );
		$change_email = static function( $params ) use ( $id ) { wp_update_user( array( 'ID' => $id, 'user_email' => 'changed-staff@example.test' ) ); return $params; };
		add_filter( 'send_email_change_email', '__return_false' );
		add_filter( 'woocommerce_mail_callback_params', $change_email );
		try { $this->assertFalse( $email->trigger( $this->task(), $id ) ); }
		finally { remove_filter( 'woocommerce_mail_callback_params', $change_email ); remove_filter( 'send_email_change_email', '__return_false' ); }
		$this->assertCount( 0, $this->messages );
		$this->assertTrue( $email->trigger( $this->task(), $id ) );
		$this->delivered_to( array( $id ) );
	}

	public function test_capability_filter_uses_recipient_and_current_site_and_rejects_site_movement(): void {
		$id = $this->staff( 'subscriber' );
		$site = get_current_blog_id();
		$seen = 0;
		$effective = static function( $caps, $required, $args, $user ) use ( $id, $site, &$seen ) {
			if ( $user->ID === $id && in_array( 'manage_woocommerce', $required, true ) ) {
				++$seen;
				$caps['manage_woocommerce'] = $user->get_site_id() === $site && get_current_blog_id() === $site;
			}
			return $caps;
		};
		add_filter( 'user_has_cap', $effective, 10, 4 );
		$email = new YoOhw_COS_Email_Task_Assigned();
		try {
			$this->assertTrue( $email->trigger( $this->task(), $id ) );
			$this->assertGreaterThanOrEqual( 3, $seen );
			$this->messages = array();
			// Controlled site-context invalidation in the real single-site runtime.
			$move = static function( $params ) use ( $site ) { $GLOBALS['blog_id'] = $site + 1; return $params; };
			add_filter( 'woocommerce_mail_callback_params', $move );
			try { $this->assertFalse( $email->trigger( $this->task(), $id ) ); }
			finally { $GLOBALS['blog_id'] = $site; remove_filter( 'woocommerce_mail_callback_params', $move ); }
			$this->assertCount( 0, $this->messages );
		} finally { remove_filter( 'user_has_cap', $effective ); }
		$this->assertFalse( $email->trigger( $this->task(), $id ) );
	}

	public function test_escalation_sources_remain_independent(): void {
		$id = $this->staff();
		$email = new YoOhw_COS_Email_Task_Overdue_Escalation();
		$email->enabled = 'yes';
		$email->settings['recipients'] = 'manager@example.test, manager@example.test, MANAGER@example.test, invalid';
		$this->assertTrue( $email->trigger( array( $this->task() ), $id ) );
		$this->delivered_to( array( $id ), array( 'MANAGER@example.test' ) );
		( new WP_User( $id ) )->set_role( 'subscriber' );
		$this->messages = array();
		$this->assertTrue( $email->trigger( array( $this->task() ), $id ) );
		$this->delivered_to( array(), array( 'MANAGER@example.test' ) );
		$email->settings['recipients'] = get_userdata( $id )->user_email;
		$this->messages = array();
		$this->assertTrue( $email->trigger( array( $this->task() ), $id ) );
		$this->delivered_to( array( $id ) );
		$email->settings['recipients'] = '';
		update_option( 'admin_email', 'site-admin@example.test' );
		$this->messages = array();
		$this->assertTrue( $email->trigger( array( $this->task() ), 0 ) );
		$this->delivered_to( array(), array( 'site-admin@example.test' ) );
		$email->settings['recipients'] = 'invalid, also-invalid';
		$this->messages = array();
		$this->assertFalse( $email->trigger( array( $this->task() ), $id ) );
		$this->assertCount( 0, $this->messages );
		( new WP_User( $id ) )->set_role( 'shop_manager' );
		$this->assertTrue( $email->trigger( array( $this->task() ), $id ) );
		$this->delivered_to( array( $id ) );
		$this->messages = array();
		$email->settings['recipients'] = '';
		// Core rejects invalid admin_email writes; inject an invalid read explicitly.
		$invalid_admin = static function() { return 'invalid'; };
		add_filter( 'pre_option_admin_email', $invalid_admin );
		( new WP_User( $id ) )->set_role( 'subscriber' );
		try { $this->assertFalse( $email->trigger( array( $this->task() ), $id ) ); }
		finally { remove_filter( 'pre_option_admin_email', $invalid_admin ); }
		$this->assertCount( 0, $this->messages );
	}

	private function stored_task( int $assignee, int $creator, string $due ): int {
		$customer = YoOhw_COS_Customers::create_customer( array( 'email' => 'notification-fixture@example.test' ) );
		$id = YoOhw_COS_Tasks::create_task( array( 'customer_id' => $customer, 'title' => $this->task()['title'], 'assigned_user_id' => $assignee, 'created_by' => $creator, 'due_date' => $due ) );
		$this->assertGreaterThan( 0, $id );
		return $id;
	}

	public function test_actual_assignment_reassignment_completion_reopen_dispatch(): void {
		$a = $this->staff(); $b = $this->staff(); $actor = $this->staff();
		$emails = WC()->mailer()->get_emails();
		$reassigned = $emails['YoOhw_COS_Email_Task_Reassigned'];
		$completed = $emails['YoOhw_COS_Email_Task_Completed'];
		$old_settings = $reassigned->settings;
		$old_enabled = $completed->enabled;
		$reassigned->settings['notify_previous_assignee'] = 'yes';
		$completed->enabled = 'yes';
		wp_set_current_user( $actor );
		try {
			$id = $this->stored_task( $a, $a, '' );
			$this->delivered_to( array( $a ) );
			$this->messages = array();
			$this->assertTrue( YoOhw_COS_Tasks::update_task( $id, array( 'assigned_user_id' => $b ) ) );
			$this->delivered_to( array( $a, $b ) );
			$this->messages = array();
			( new WP_User( $a ) )->set_role( 'subscriber' );
			$this->assertTrue( YoOhw_COS_Tasks::complete_task( $id, $actor ) );
			$this->delivered_to( array( $b ) );
			$this->messages = array();
			wp_set_current_user( $b );
			$this->assertTrue( YoOhw_COS_Tasks::reopen_task( $id ) );
			$this->assertCount( 0, $this->messages, 'Actor excluded and revoked creator denied.' );
			wp_set_current_user( $actor );
			( new WP_User( $a ) )->set_role( 'shop_manager' );
			$this->assertTrue( YoOhw_COS_Tasks::complete_task( $id, $actor ) );
			$this->messages = array();
			$this->assertTrue( YoOhw_COS_Tasks::reopen_task( $id ) );
			$this->delivered_to( array( $a, $b ) );
			$this->messages = array();
			( new WP_User( $b ) )->set_role( 'subscriber' );
			$this->assertTrue( YoOhw_COS_Tasks::update_task( $id, array( 'assigned_user_id' => $a ) ) );
			$this->delivered_to( array( $a ) );
		} finally { $reassigned->settings = $old_settings; $completed->enabled = $old_enabled; }
	}

	public function test_due_soon_mixed_batch_failure_retry_and_dedupe(): void {
		global $wpdb;
		YoOhw_COS_Customers::reset_data();
		$a = $this->staff(); $b = $this->staff();
		$due = current_datetime()->modify( '+2 hours' )->format( 'Y-m-d H:i:s' );
		$first = $this->stored_task( $a, $a, $due );
		$second = $this->stored_task( $b, $b, $due );
		( new WP_User( $a ) )->set_role( 'subscriber' );
		$this->messages = array();
		$this->transport_result = false;
		YoOhw_COS_Email_Notifications::run_due_soon_notifications();
		$this->delivered_to( array( $b ) );
		$this->assertSame( '0', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . YoOhw_COS_DB::notification_log_table() ) );
		$this->messages = array();
		$this->transport_result = true;
		YoOhw_COS_Email_Notifications::run_due_soon_notifications();
		$this->delivered_to( array( $b ) );
		$this->messages = array();
		YoOhw_COS_Email_Notifications::run_due_soon_notifications();
		$this->assertCount( 0, $this->messages );
		( new WP_User( $a ) )->set_role( 'shop_manager' );
		YoOhw_COS_Email_Notifications::run_due_soon_notifications();
		$this->delivered_to( array( $a ) );
		$this->assertSame( '2', $wpdb->get_var( "SELECT COUNT(*) FROM " . YoOhw_COS_DB::notification_log_table() . " WHERE status = 'sent'" ) );
		$this->assertSame( '0', $wpdb->get_var( "SELECT COUNT(*) FROM " . YoOhw_COS_DB::notification_log_table() . " WHERE status = 'pending'" ) );
	}

	public function test_daily_worker_denied_group_continues_and_keeps_configured_escalation(): void {
		global $wpdb;
		YoOhw_COS_Customers::reset_data();
		$a = $this->staff(); $b = $this->staff();
		$due = current_datetime()->modify( '-5 days' )->format( 'Y-m-d H:i:s' );
		$this->stored_task( $a, $a, $due );
		$this->stored_task( $b, $b, $due );
		( new WP_User( $a ) )->set_role( 'subscriber' );
		$email = WC()->mailer()->get_emails()['YoOhw_COS_Email_Task_Overdue_Escalation'];
		$settings = $email->settings; $enabled = $email->enabled;
		$email->settings['recipients'] = 'escalation@example.test'; $email->enabled = 'yes';
		$this->messages = array();
		try {
			$this->transport_result = false;
			YoOhw_COS_Email_Notifications::run_daily_notifications();
			$this->delivered_to( array(), array( 'escalation@example.test' ) );
			$this->assertSame( '0', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . YoOhw_COS_DB::notification_log_table() ) );
			$this->transport_result = true; $this->messages = array();
			YoOhw_COS_Email_Notifications::run_daily_notifications();
			$this->delivered_to( array(), array( 'escalation@example.test' ) );
			$cursors = array_fill_keys( array( 'overdue', 'escalation', 'summary' ), array( 'user_id' => $a, 'task_id' => 0 ) );
			$this->messages = array();
			YoOhw_COS_Email_Notifications::run_daily_notifications( $cursors );
			$this->delivered_to( array( $b, $b, $b ), array( 'escalation@example.test' ) );
			$this->messages = array();
			YoOhw_COS_Email_Notifications::run_daily_notifications( $cursors );
			$this->assertCount( 0, $this->messages );
			$this->assertSame( '4', $wpdb->get_var( "SELECT COUNT(*) FROM " . YoOhw_COS_DB::notification_log_table() . " WHERE status = 'sent'" ) );
			$this->assertSame( '0', $wpdb->get_var( "SELECT COUNT(*) FROM " . YoOhw_COS_DB::notification_log_table() . " WHERE status = 'pending'" ) );
			$scheduled = wp_next_scheduled( 'yoohw_cos_crm_email_daily', array( $cursors ) );
			$this->assertNotFalse( $scheduled, 'Existing bounded continuation advances past denied group.' );
			$this->assertGreaterThan( time(), $scheduled );
		} finally { $email->settings = $settings; $email->enabled = $enabled; }
	}

	public function test_daily_summary_actual_transport_keeps_200_task_chunks(): void {
		global $wpdb;
		YoOhw_COS_Customers::reset_data();
		$id = $this->staff();
		$task_id = $this->stored_task( $id, $id, current_datetime()->modify( '-1 day' )->format( 'Y-m-d H:i:s' ) );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', YoOhw_COS_DB::tasks_table(), $task_id ), ARRAY_A );
		unset( $row['id'] );
		for ( $i = 2; $i <= 201; ++$i ) {
			$row['title'] = $this->task()['title'] . ' item-' . $i . '-end';
			$this->assertSame( 1, $wpdb->insert( YoOhw_COS_DB::tasks_table(), $row ) );
			if ( 200 === $i ) { $last = (int) $wpdb->insert_id; }
		}
		$emails = WC()->mailer()->get_emails();
		$overdue = $emails['YoOhw_COS_Email_Task_Overdue']; $enabled = $overdue->enabled; $overdue->enabled = 'no';
		$this->messages = array();
		try {
			YoOhw_COS_Email_Notifications::run_daily_notifications();
			$this->assertCount( 1, $this->messages );
			$this->assertTrue( strpos( $this->messages[0]['message'], 'item-200-end' ) !== false );
			$this->assertTrue( strpos( $this->messages[0]['message'], 'item-201-end' ) === false );
			$cursors = array( 'summary' => array( 'user_id' => $id, 'task_id' => $last ) );
			YoOhw_COS_Email_Notifications::run_daily_notifications( $cursors );
			$this->assertCount( 2, $this->messages );
			$this->assertTrue( strpos( $this->messages[1]['message'], 'item-201-end' ) !== false );
			$this->assertTrue( strpos( $this->messages[1]['message'], 'item-200-end' ) === false );
			YoOhw_COS_Email_Notifications::run_daily_notifications( $cursors );
			$this->assertCount( 2, $this->messages );
			$this->delivered_to( array( $id, $id ) );
		} finally { $overdue->enabled = $enabled; }
	}

	public function test_guest_customer_template_is_not_subject_to_staff_gate(): void {
		$this->assertFalse( get_user_by( 'email', 'guest-message@example.test' ) );
		$this->assertTrue( YoOhw_COS_Email_Notifications::send_customer_message( array( 'email' => 'guest-message@example.test', 'display_name' => 'Synthetic guest' ), 'Synthetic subject', 'Synthetic guest message' ) );
		$this->assertCount( 1, $this->messages );
		$this->assertTrue( $this->messages[0]['to'] === 'guest-message@example.test' );
		$this->assertTrue( strpos( $this->messages[0]['message'], 'Synthetic guest message' ) !== false );
	}

	public function test_capability_hook_mutation_at_transport_is_not_a_cached_allow(): void {
		$id = $this->staff();
		$email = new YoOhw_COS_Email_Task_Assigned();
		$at_transport = false;
		$arm = static function( $params ) use ( &$at_transport ) { $at_transport = true; return $params; };
		$revoke = static function( $caps, $required, $args, $user ) use ( $id, &$at_transport ) {
			if ( $at_transport && $user->ID === $id && in_array( 'manage_woocommerce', $required, true ) ) {
				$at_transport = false;
				( new WP_User( $id ) )->add_cap( 'manage_woocommerce', false );
			}
			return $caps;
		};
		add_filter( 'woocommerce_mail_callback_params', $arm );
		add_filter( 'user_has_cap', $revoke, 10, 4 );
		try {
			$this->assertFalse( $email->trigger( $this->task(), $id ) );
			$this->assertCount( 0, $this->messages );
		} finally {
			remove_filter( 'woocommerce_mail_callback_params', $arm );
			remove_filter( 'user_has_cap', $revoke );
		}
		( new WP_User( $id ) )->remove_cap( 'manage_woocommerce' );
		$this->assertTrue( $email->trigger( $this->task(), $id ) );
		$this->delivered_to( array( $id ) );
	}

}
