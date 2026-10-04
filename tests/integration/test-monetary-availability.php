<?php
require_once dirname( __DIR__ ) . '/environment.php';
yci_test_environment();

final class YCI_Monetary_Availability_Test extends WP_UnitTestCase {
	private $owned_orders = array();
	public function set_up(): void {
		parent::set_up();
		delete_option( YoOhw_COS_Reset_Guard::OPTION );
		YoOhw_COS_Reset_Guard::init();
		YoOhw_COS_Customers::reset_data();
		update_option( 'yoohw_cos_data_migrations', array( 'commerce_currency_v3' => array( 'status' => 'completed' ) ), false );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		wp_get_current_user()->add_cap( 'manage_woocommerce' );
	}

	public function tear_down(): void {
		foreach ( $this->owned_orders as $order ) { if ( $order->get_id() ) { $order->delete( true ); } }
		YoOhw_COS_Customers::reset_data();
		parent::tear_down();
	}

	private function order( string $currency, float $amount = 3000 ): array {
		$order = wc_create_order();
		$order->set_billing_email( 'currency-' . wp_generate_uuid4() . '@example.test' );
		$order->set_currency( $currency );
		$order->set_total( $amount );
		$order->set_status( 'completed' );
		$order->save();
		$this->owned_orders[] = $order;
		$id = YoOhw_COS_Customers::sync_from_order( $order );
		return array( $order, YoOhw_COS_Customers::get_customer( $id ) );
	}

	public function test_effective_reasons_and_foreign_money_preserve_independent_dimensions(): void {
		update_option( 'woocommerce_currency', 'VND' );
		list( $order, $customer ) = $this->order( 'EUR', 123.45 );
		$a = YoOhw_COS_Commerce_Metrics_Policy::availability( $customer );
		$this->assertSame( 'comparable', $a['reason'] );
		$this->assertTrue( $a['amount_available'] );
		$this->assertFalse( $a['matches_store_currency'] );
		update_option( 'woocommerce_currency', 'EUR' );
		$this->assertTrue( YoOhw_COS_Commerce_Metrics_Policy::availability( $customer )['matches_store_currency'] );
		update_option( 'woocommerce_currency', 'VND' );
		$this->assertStringContainsString( 'EUR', YoOhw_COS_Commerce_Metrics_Policy::format_money( $customer, 'total_spent' ) );
		foreach ( array( 'mixed' => 'mixed', 'unknown' => 'unknown_source_currency', 'none' => 'none' ) as $state => $reason ) {
			$fixture = array_replace( $customer, array( 'money_state' => $state, 'total_orders' => 'none' === $state ? 0 : 1 ) );
			$this->assertSame( $reason, YoOhw_COS_Commerce_Metrics_Policy::availability( $fixture )['reason'] );
		}
		$this->assertSame( 'metrics_stale', YoOhw_COS_Commerce_Metrics_Policy::availability( array_replace( $customer, array( 'commerce_metrics_version' => 1, 'money_state' => 'unknown' ) ) )['reason'] );
		wp_clear_scheduled_hook( YoOhw_COS_Migration_Runner::HOOK );
		wp_schedule_single_event( time() + 5, YoOhw_COS_Migration_Runner::HOOK );
		$state = array( 'commerce_currency_v3' => array( 'status' => 'pending', 'started_at' => '2026-10-03 01:00:00' ) );
		update_option( 'yoohw_cos_data_migrations', $state, false );
		$this->assertSame( 'preparing_currency_data', YoOhw_COS_Commerce_Metrics_Policy::availability( $customer )['reason'] );
		$this->assertSame( 'preparing_currency_data', YoOhw_COS_Customer_Query::query( array( 'rfm_monetary_min' => '1' ) )['evaluation_reason'] );
		$state['commerce_currency_v3']['status'] = 'completed_with_issues';
		update_option( 'yoohw_cos_data_migrations', $state, false );
		$this->assertSame( 'currency_data_attention', YoOhw_COS_Commerce_Metrics_Policy::availability( $customer )['reason'] );
		$this->assertFalse( YoOhw_COS_Commerce_Metrics_Policy::money_is_comparable( $customer ) );
		$order->delete( true );
	}

	public function test_customer_only_stale_admission_and_generation_fence_converge(): void {
		global $wpdb;
		update_option( 'woocommerce_currency', 'VND' );
		list( $order, $customer ) = $this->order( 'VND', 100000 );
		$this->assertSame( 'platinum', $customer['vip_status'] );
		$generation = YoOhw_COS_Intelligence::get_scoring_generation();
		$wpdb->update( YoOhw_COS_DB::customers_table(), array( 'commerce_metrics_version' => 1, 'money_state' => 'unknown' ), array( 'id' => $customer['id'] ) );
		update_option( 'yoohw_cos_data_migrations', array( 'commerce_facts_v2' => array( 'status' => 'completed' ) ), false );
		YoOhw_COS_Install::maybe_update();
		$this->assertSame( 'pending', YoOhw_COS_Migration_Runner::get_state()['commerce_currency_v3']['status'] );
		$this->assertNotSame( $generation, YoOhw_COS_Intelligence::get_scoring_generation() );
		$this->assertSame( 'none', YoOhw_COS_Customers::get_customer( $customer['id'] )['vip_status'] );
		$this->assertSame( 0, YoOhw_COS_Customer_Query::query( array( 'vip_status' => 'platinum' ) )['total_items'] );
		$pending_generation = YoOhw_COS_Intelligence::get_scoring_generation();
		YoOhw_COS_Install::maybe_update();
		$this->assertSame( $pending_generation, YoOhw_COS_Intelligence::get_scoring_generation() );
		for ( $batch = 0; $batch < 20 && ! YoOhw_COS_Migration_Runner::currency_backfill_is_complete(); ++$batch ) { YoOhw_COS_Migration_Runner::run_next_batch(); }
		$this->assertTrue( YoOhw_COS_Migration_Runner::currency_backfill_is_complete() );
		$ready = YoOhw_COS_Customers::get_customer( $customer['id'] );
		$this->assertSame( 'comparable', $ready['money_state'] );
		$this->assertSame( 100000.0, (float) $ready['total_spent'] );
		$order->delete( true );
	}

	private function transport( array $post ): string {
		$_POST = $_REQUEST = $post;
		$handler = static function() { return static function() { throw new RuntimeException( 'ajax-done' ); }; };
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $handler );
		ob_start();
		try { YoOhw_COS_Notice_Preferences::dismiss_request(); }
		catch ( RuntimeException $done ) { $this->assertSame( 'ajax-done', $done->getMessage() ); }
		finally { $output = ob_get_clean(); remove_filter( 'wp_die_ajax_handler', $handler ); remove_filter( 'wp_doing_ajax', '__return_true' ); $_POST = $_REQUEST = array(); }
		return $output;
	}

	public function test_native_notice_transport_is_per_user_revision_scoped_and_presentation_only(): void {
		$user = get_current_user_id();
		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $other )->add_cap( 'manage_woocommerce' );
		$_GET['page'] = 'yoohw-customer-intelligence';
		wp_clear_scheduled_hook( YoOhw_COS_Migration_Runner::HOOK );
		wp_schedule_single_event( time() + 5, YoOhw_COS_Migration_Runner::HOOK );
		$state = array( 'commerce_currency_v3' => array( 'status' => 'pending', 'started_at' => '2026-10-03 01:00:00' ) );
		update_option( 'yoohw_cos_data_migrations', $state, false );
		$notice = YoOhw_COS_Notice_Preferences::descriptor( 'commerce_update' );
		ob_start(); YoOhw_COS_Notice_Preferences::render_commerce_notice(); $html = ob_get_clean();
		$this->assertStringContainsString( 'is-dismissible', $html );
		$this->assertStringContainsString( 'automatically', $html );
		$post = array( 'key' => 'commerce_update', 'revision' => $notice['revision'], 'nonce' => wp_create_nonce( 'yoohw_cos_dismiss_notice' ) );
		$this->assertTrue( json_decode( $this->transport( $post ), true )['success'] );
		$this->assertSame( $state, YoOhw_COS_Migration_Runner::get_state() );
		wp_cache_delete( $user, 'user_meta' );
		$this->assertSame( '', YoOhw_COS_Notice_Preferences::opening_markup( 'commerce_update' ) );
		wp_set_current_user( $other );
		$this->assertNotSame( '', YoOhw_COS_Notice_Preferences::opening_markup( 'commerce_update' ) );
		wp_set_current_user( $user );
		$state['commerce_currency_v3']['status'] = 'in_progress';
		$state['commerce_currency_v3']['processed'] = 100;
		update_option( 'yoohw_cos_data_migrations', $state, false );
		$this->assertSame( $notice['revision'], YoOhw_COS_Notice_Preferences::descriptor( 'commerce_update' )['revision'] );
		$state['commerce_currency_v3']['status'] = 'completed_with_issues';
		$state['commerce_currency_v3']['unresolved_issues'] = 1;
		update_option( 'yoohw_cos_data_migrations', $state, false );
		$this->assertNotSame( '', YoOhw_COS_Notice_Preferences::opening_markup( 'commerce_update' ) );
		$this->assertFalse( json_decode( $this->transport( $post ), true )['success'] );
		$current = YoOhw_COS_Notice_Preferences::descriptor( 'commerce_update' );
		$post['revision'] = $current['revision'];
		$post['user_id'] = $other;
		$this->assertFalse( json_decode( $this->transport( $post ), true )['success'] );
		unset( $post['user_id'] );
		$this->assertTrue( json_decode( $this->transport( $post ), true )['success'] );
		$this->assertSame( $state, YoOhw_COS_Migration_Runner::get_state() );
		$this->assertSame( '', YoOhw_COS_Notice_Preferences::opening_markup( 'commerce_update' ) );
		$this->assertSame( 'attention', YoOhw_COS_Diagnostics::snapshot()['currency']['state'] );
		$state['commerce_currency_v3']['unresolved_issues'] = 2;
		update_option( 'yoohw_cos_data_migrations', $state, false );
		$this->assertNotSame( '', YoOhw_COS_Notice_Preferences::opening_markup( 'commerce_update' ) );
		$this->assertFalse( json_decode( $this->transport( $post ), true )['success'] );
		$state['commerce_currency_v3']['status'] = 'completed';
		update_option( 'yoohw_cos_data_migrations', $state, false );
		$this->assertNull( YoOhw_COS_Notice_Preferences::descriptor( 'commerce_update' ) );
		$this->assertLessThanOrEqual( 2, count( get_user_meta( $user, '_yoohw_cos_notice_preferences', true ) ) );
		unset( $_GET['page'] );
	}
	public function test_dismiss_transport_rejects_nonce_capability_and_arbitrary_keys(): void {
		$state = array( 'commerce_currency_v3' => array( 'status' => 'completed_with_issues', 'started_at' => 'fixture' ) );
		update_option( 'yoohw_cos_data_migrations', $state, false );
		$notice = YoOhw_COS_Notice_Preferences::descriptor( 'commerce_update' );
		$post = array( 'key' => 'commerce_update', 'revision' => $notice['revision'], 'nonce' => 'invalid' );
		$this->transport( $post );
		$this->assertSame( '', get_user_meta( get_current_user_id(), '_yoohw_cos_notice_preferences', true ) );
		$post['nonce'] = wp_create_nonce( 'yoohw_cos_dismiss_notice' );
		$post['key'] = 'arbitrary_user_meta';
		$this->assertFalse( json_decode( $this->transport( $post ), true )['success'] );
		$post['key'] = 'commerce_update';
		wp_get_current_user()->add_cap( 'manage_woocommerce', false );
		$this->assertFalse( current_user_can( 'manage_woocommerce' ) );
		$this->assertFalse( json_decode( $this->transport( $post ), true )['success'] );
		$this->assertSame( '', get_user_meta( get_current_user_id(), '_yoohw_cos_notice_preferences', true ) );
		$this->assertSame( $state, YoOhw_COS_Migration_Runner::get_state() );
	}

	public function test_customer_probe_is_bounded_and_resumes_after_first_page(): void {
		global $wpdb;
		for ( $i = 0; $i < 101; ++$i ) {
			$id = YoOhw_COS_Customers::create_customer( array( 'display_name' => 'Bounded synthetic probe' ) );
			$wpdb->update( YoOhw_COS_DB::customers_table(), array( 'commerce_metrics_version' => 2 ), array( 'id' => $id ) );
		}
		$wpdb->update( YoOhw_COS_DB::customers_table(), array( 'commerce_metrics_version' => 1 ), array( 'id' => $id ) );
		update_option( 'yoohw_cos_data_migrations', array( 'commerce_facts_v2' => array( 'status' => 'completed' ) ), false );
		YoOhw_COS_Install::maybe_update();
		$first = YoOhw_COS_Migration_Runner::get_state();
		$this->assertArrayNotHasKey( 'commerce_currency_v3', $first );
		$this->assertSame( 'pending', $first['_currency_customer_probe']['status'] );
		$this->assertLessThan( $id, $first['_currency_customer_probe']['cursor'] );
		YoOhw_COS_Migration_Runner::run_next_batch();
		$this->assertSame( 'pending', YoOhw_COS_Migration_Runner::get_state()['commerce_currency_v3']['status'] );
		for ( $i = 0; $i < 20 && ! YoOhw_COS_Migration_Runner::currency_backfill_is_complete(); ++$i ) { YoOhw_COS_Migration_Runner::run_next_batch(); }
		$this->assertTrue( YoOhw_COS_Migration_Runner::currency_backfill_is_complete() );
		$this->assertSame( 'none', YoOhw_COS_Commerce_Metrics_Policy::availability( YoOhw_COS_Customers::get_customer( $id ) )['reason'] );
	}

	private function complete_clean_customer_probe(): int {
		for ( $i = 0; $i < 101; ++$i ) {
			$id = YoOhw_COS_Customers::create_customer( array( 'display_name' => 'Clean synthetic probe', 'commerce_metrics_version' => 2 ) );
		}
		update_option( 'yoohw_cos_data_migrations', array( 'commerce_facts_v2' => array( 'status' => 'completed' ) ), false );
		YoOhw_COS_Install::maybe_update();
		$this->assertSame( 'pending', YoOhw_COS_Migration_Runner::get_state()['_currency_customer_probe']['status'] );
		YoOhw_COS_Migration_Runner::run_next_batch();
		$state = YoOhw_COS_Migration_Runner::get_state();
		$this->assertArrayNotHasKey( 'commerce_currency_v3', $state );
		$this->assertSame( 'completed', $state['_currency_customer_probe']['status'] );
		$this->assertSame( $id, $state['_currency_customer_probe']['cursor'] );
		wp_clear_scheduled_hook( YoOhw_COS_Migration_Runner::HOOK );
		return $id;
	}

	public function test_negative_probe_does_not_restart_unchanged_population_and_admits_new_stale_rows(): void {
		$this->complete_clean_customer_probe();
		$before = YoOhw_COS_Migration_Runner::get_state();
		$queries = array();
		$observe = static function ( $sql ) use ( &$queries ) { $queries[] = $sql; return $sql; };
		add_filter( 'query', $observe );
		try {
			for ( $i = 0; $i < 5; ++$i ) { YoOhw_COS_Install::maybe_update(); }
		} finally { remove_filter( 'query', $observe ); }
		$this->assertSame( $before, YoOhw_COS_Migration_Runner::get_state() );
		$this->assertFalse( wp_next_scheduled( YoOhw_COS_Migration_Runner::HOOK ) );
		foreach ( $queries as $sql ) {
			$this->assertStringNotContainsString( 'SELECT id, commerce_metrics_version, total_orders', $sql );
			$this->assertStringNotContainsString( 'WHERE policy_version <', $sql );
		}
		$id = YoOhw_COS_Customers::create_customer( array( 'display_name' => 'New stale synthetic probe', 'commerce_metrics_version' => 1 ) );
		YoOhw_COS_Install::maybe_update();
		$state = YoOhw_COS_Migration_Runner::get_state();
		$this->assertSame( 'pending', $state['commerce_currency_v3']['status'] );
		$this->assertArrayNotHasKey( '_currency_customer_probe', $state );
		$this->assertNotFalse( wp_next_scheduled( YoOhw_COS_Migration_Runner::HOOK ) );
		for ( $i = 0; $i < 20 && ! YoOhw_COS_Migration_Runner::currency_backfill_is_complete(); ++$i ) { YoOhw_COS_Migration_Runner::run_next_batch(); }
		$this->assertTrue( YoOhw_COS_Migration_Runner::currency_backfill_is_complete() );
		$this->assertSame( 'none', YoOhw_COS_Commerce_Metrics_Policy::availability( YoOhw_COS_Customers::get_customer( $id ) )['reason'] );
	}

	public function test_negative_probe_revalidates_old_rows_without_append_extending_deadline(): void {
		global $wpdb;
		$old_id = $this->complete_clean_customer_probe();
		$deadline = YoOhw_COS_Migration_Runner::get_state()['_currency_customer_probe']['revalidate_after'];
		$new_id = YoOhw_COS_Customers::create_customer( array( 'display_name' => 'New clean synthetic probe', 'commerce_metrics_version' => 2 ) );
		YoOhw_COS_Install::maybe_update();
		$state = YoOhw_COS_Migration_Runner::get_state();
		$this->assertSame( 'completed', $state['_currency_customer_probe']['status'] );
		$this->assertSame( $new_id, $state['_currency_customer_probe']['cursor'] );
		$this->assertSame( $deadline, $state['_currency_customer_probe']['revalidate_after'] );
		// External/in-place writes are discovered at the documented bounded revalidation.
		$wpdb->update( YoOhw_COS_DB::customers_table(), array( 'commerce_metrics_version' => 1 ), array( 'id' => $old_id ) );
		YoOhw_COS_Install::maybe_update();
		$this->assertSame( $state, YoOhw_COS_Migration_Runner::get_state() );
		$state['_currency_customer_probe']['revalidate_after'] = time() - 1;
		update_option( 'yoohw_cos_data_migrations', $state, false );
		YoOhw_COS_Install::maybe_update();
		$state = YoOhw_COS_Migration_Runner::get_state();
		$this->assertSame( 'pending', $state['_currency_customer_probe']['status'] );
		$this->assertLessThan( $old_id, $state['_currency_customer_probe']['cursor'] );
		YoOhw_COS_Migration_Runner::run_next_batch();
		$this->assertSame( 'pending', YoOhw_COS_Migration_Runner::get_state()['commerce_currency_v3']['status'] );
		for ( $i = 0; $i < 20 && ! YoOhw_COS_Migration_Runner::currency_backfill_is_complete(); ++$i ) { YoOhw_COS_Migration_Runner::run_next_batch(); }
		$this->assertTrue( YoOhw_COS_Migration_Runner::currency_backfill_is_complete() );
	}

	public function test_readiness_fence_survives_failed_companion_generation_write(): void {
		list( $order, $customer ) = $this->order( get_woocommerce_currency(), 100000 );
		$old = YoOhw_COS_Intelligence::get_scoring_generation();
		$reject = static fn( $new, $before ) => $before;
		add_filter( 'pre_update_option_yoohw_cos_intelligence_generation', $reject, 10, 2 );
		try { update_option( 'yoohw_cos_data_migrations', array( 'commerce_currency_v3' => array( 'status' => 'pending' ) ), false ); }
		finally { remove_filter( 'pre_update_option_yoohw_cos_intelligence_generation', $reject ); }
		$this->assertSame( $old, get_option( 'yoohw_cos_intelligence_generation' ) );
		$this->assertNotSame( $old, YoOhw_COS_Intelligence::get_scoring_generation() );
		$this->assertFalse( YoOhw_COS_Intelligence::persisted_decisions_are_safe( $customer ) );
		$this->assertSame( 0, YoOhw_COS_Customer_Query::query( array( 'vip_status' => 'platinum' ) )['total_items'] );
	}

	public function test_operational_dismissal_preserves_incident_and_reset_blocking(): void {
		$before = YoOhw_COS_Reset_Guard::state();
		update_option( YoOhw_COS_Reset_Guard::OPTION, array( 'epoch' => $before['epoch'], 'status' => 'pending' ), false );
		$incident = array( 'source' => 'synthetic_cit88', 'event' => 'replay_needed', 'mode' => 'manual_replay_required' );
		$this->assertFalse( YoOhw_COS_Reset_Guard::enter( $incident ) );
		$items = YoOhw_COS_Reset_Guard::operational_incidents();
		$notice = YoOhw_COS_Notice_Preferences::descriptor( 'operational_recovery' );
		$post = array( 'key' => 'operational_recovery', 'revision' => $notice['revision'], 'nonce' => wp_create_nonce( 'yoohw_cos_dismiss_notice' ) );
		$this->assertTrue( json_decode( $this->transport( $post ), true )['success'] );
		$this->assertSame( $items, YoOhw_COS_Reset_Guard::operational_incidents() );
		$this->assertFalse( YoOhw_COS_Reset_Guard::enter( false ) );
		ob_start(); YoOhw_COS_Reset_Guard::render_notice(); $html = ob_get_clean();
		$this->assertStringContainsString( 'Customer Reset requires recovery', $html );
		$this->assertStringNotContainsString( 'is-dismissible', $html );
		update_option( YoOhw_COS_Reset_Guard::OPTION, $before, false );
		$this->assertSame( '', YoOhw_COS_Notice_Preferences::opening_markup( 'operational_recovery' ) );
		ob_start(); YoOhw_COS_Reset_Guard::render_incidents(); $details = ob_get_clean();
		$this->assertStringContainsString( 'Operational recovery', $details );
		$this->assertNotEmpty( YoOhw_COS_Reset_Guard::operational_incidents() );
		update_option( YoOhw_COS_Reset_Guard::OPTION, array( 'epoch' => $before['epoch'], 'status' => 'pending' ), false );
		$this->assertFalse( YoOhw_COS_Reset_Guard::enter( $incident ) );
		$this->assertNotSame( $notice['revision'], YoOhw_COS_Notice_Preferences::descriptor( 'operational_recovery' )['revision'] );
		delete_option( 'yoohw_cos_operational_incidents' );
		update_option( YoOhw_COS_Reset_Guard::OPTION, $before, false );
		YoOhw_COS_Reset_Guard::init();
	}

	public function test_progress_grace_requires_evidence_and_later_success_recovers_error(): void {
		wp_clear_scheduled_hook( YoOhw_COS_Migration_Runner::HOOK );
		$state = array( 'commerce_currency_v3' => array( 'status' => 'in_progress', 'started_at' => 'synthetic grace' ) );
		update_option( 'yoohw_cos_data_migrations', $state, false );
		wp_clear_scheduled_hook( YoOhw_COS_Migration_Runner::HOOK );
		$this->assertSame( 'attention', YoOhw_COS_Commerce_Metrics_Policy::site_readiness()['state'] );
		$state['commerce_currency_v3']['last_progress_at'] = time();
		$state['commerce_currency_v3']['last_error'] = 'older synthetic error';
		$state['commerce_currency_v3']['last_error_at'] = wp_date( 'Y-m-d H:i:s', time() - 60, wp_timezone() );
		update_option( 'yoohw_cos_data_migrations', $state, false );
		$this->assertSame( 'preparing', YoOhw_COS_Commerce_Metrics_Policy::site_readiness()['state'] );
		$state['commerce_currency_v3']['last_progress_at'] = time() - 901;
		update_option( 'yoohw_cos_data_migrations', $state, false );
		$this->assertSame( 'attention', YoOhw_COS_Commerce_Metrics_Policy::site_readiness()['state'] );
		unset( $state['commerce_currency_v3']['last_error'] );
		update_option( 'yoohw_cos_data_migrations', $state, false );
		wp_schedule_single_event( time() + 60, YoOhw_COS_Migration_Runner::HOOK );
		$this->assertSame( 'preparing', YoOhw_COS_Commerce_Metrics_Policy::site_readiness()['state'] );
		$schema = get_option( 'yoohw_cos_schema_status' );
		update_option( 'yoohw_cos_schema_status', array( 'status' => 'blocked' ) );
		$this->assertSame( 'attention', YoOhw_COS_Commerce_Metrics_Policy::site_readiness()['state'] );
		update_option( 'yoohw_cos_schema_status', $schema );
	}

	public function test_new_failure_overrides_recent_progress_and_preparing_dismissal(): void {
		$progress = time() - 30;
		$state = array( 'commerce_currency_v3' => array( 'status' => 'in_progress', 'started_at' => 'synthetic failure ordering', 'last_progress_at' => $progress ) );
		update_option( 'yoohw_cos_data_migrations', $state, false );
		wp_schedule_single_event( time() + 60, YoOhw_COS_Migration_Runner::HOOK );
		$this->assertSame( 'preparing', YoOhw_COS_Commerce_Metrics_Policy::site_readiness()['state'] );
		$preparing = YoOhw_COS_Notice_Preferences::descriptor( 'commerce_update' );
		$post = array( 'key' => 'commerce_update', 'revision' => $preparing['revision'], 'nonce' => wp_create_nonce( 'yoohw_cos_dismiss_notice' ) );
		$this->assertTrue( json_decode( $this->transport( $post ), true )['success'] );
		$this->assertSame( '', YoOhw_COS_Notice_Preferences::opening_markup( 'commerce_update' ) );
		$state['commerce_currency_v3']['last_error'] = 'new synthetic failure after progress';
		$state['commerce_currency_v3']['last_error_at'] = wp_date( 'Y-m-d H:i:s', $progress + 10, wp_timezone() );
		update_option( 'yoohw_cos_data_migrations', $state, false );
		$this->assertSame( 'attention', YoOhw_COS_Commerce_Metrics_Policy::site_readiness()['state'] );
		$this->assertNotSame( $preparing['revision'], YoOhw_COS_Notice_Preferences::descriptor( 'commerce_update' )['revision'] );
		$this->assertNotSame( '', YoOhw_COS_Notice_Preferences::opening_markup( 'commerce_update' ) );
		$this->assertFalse( json_decode( $this->transport( $post ), true )['success'] );
		$state['commerce_currency_v3']['last_progress_at'] = $progress + 20;
		update_option( 'yoohw_cos_data_migrations', $state, false );
		$this->assertSame( 'preparing', YoOhw_COS_Commerce_Metrics_Policy::site_readiness()['state'] );
		foreach ( array( '', 'invalid', '2026-02-31 12:00:00' ) as $invalid ) {
			$state['commerce_currency_v3']['last_error_at'] = $invalid;
			update_option( 'yoohw_cos_data_migrations', $state, false );
			$this->assertSame( 'attention', YoOhw_COS_Commerce_Metrics_Policy::site_readiness()['state'] );
		}
	}

	private function profile_lifetime_copy( int $customer_id ): array {
		ob_start();
		try { YoOhw_COS_Customer_Profile::render( $customer_id ); }
		finally { $html = ob_get_clean(); }
		$dom = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		try { $dom->loadHTML( '<?xml encoding="UTF-8">' . $html ); }
		finally { libxml_clear_errors(); libxml_use_internal_errors( $previous ); }
		$xpath = new DOMXPath( $dom );
		$nodes = $xpath->query( '//section[h3[normalize-space()="Lifecycle"]]//li[strong[normalize-space()="Lifetime value"]]/div' );
		$this->assertSame( 1, $nodes->length );
		$this->assertSame( 0, $xpath->query( './*', $nodes->item( 0 ) )->length );
		$copy = $nodes->item( 0 )->textContent;
		foreach ( array( '<span', 'woocommerce-Price-amount', '&lt;', '&gt;', '&nbsp;', '&#' ) as $markup ) {
			$this->assertStringNotContainsString( $markup, $copy );
		}
		return array( $copy, $html, $xpath );
	}

	public function monetary_copy_cases(): array {
		return array(
			'current localized' => array( 'USD', 1234.5, ',', '.', 'right_space', "1.234,50\xC2\xA0$" ),
			'foreign localized' => array( 'VND', 100000, ',', '.', 'right_space', "100.000,00\xC2\xA0₫ (VND)" ),
			'current alternate' => array( 'USD', 1234.5, '.', ',', 'left_space', "$\xC2\xA01,234.50" ),
			'foreign alternate' => array( 'VND', 100000, '.', ',', 'left_space', "₫\xC2\xA0100,000.00 (VND)" ),
		);
	}

	/** @dataProvider monetary_copy_cases */
	public function test_profile_lifetime_value_is_localized_text_and_price_panels_stay_html( string $currency, float $amount, string $decimal, string $group, string $position, string $expected ): void {
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'woocommerce_price_num_decimals', 2 );
		update_option( 'woocommerce_price_decimal_sep', $decimal );
		update_option( 'woocommerce_price_thousand_sep', $group );
		update_option( 'woocommerce_currency_pos', $position );
		list( $order, $customer ) = $this->order( $currency, $amount );
		$this->assertSame( 'comparable', YoOhw_COS_Commerce_Metrics_Policy::availability( $customer )['reason'] );
		$this->assertSame( $expected, YoOhw_COS_Commerce_Metrics_Policy::format_money_text( $customer, 'total_spent' ) );
		list( $copy, $html, $xpath ) = $this->profile_lifetime_copy( $customer['id'] );
		$this->assertSame( 'This customer has spent ' . $expected . '.', $copy );
		$price = YoOhw_COS_Commerce_Metrics_Policy::format_money( $customer, 'total_spent' );
		$this->assertStringContainsString( 'woocommerce-Price-amount', $price );
		$this->assertStringContainsString( wp_kses_post( $price ), $html );
		$this->assertGreaterThanOrEqual( 2, $xpath->query( '//div[contains(@class,"yoohw-cos-profile-kpis")]//span[contains(@class,"woocommerce-Price-amount")]' )->length );
		$this->assertSame( 1, $xpath->query( '//tr[th[contains(.,"M · Lifetime")]]/td//span[contains(@class,"woocommerce-Price-amount")]' )->length );
		$this->assertStringContainsString( wp_kses_post( $order->get_formatted_order_total() ), $html );
	}

	public function unavailable_copy_cases(): array {
		return array(
			'mixed' => array( 'mixed', 'mixed' ),
			'unknown' => array( 'unknown', 'unknown_source_currency' ),
			'preparing' => array( 'preparing', 'preparing_currency_data' ),
			'attention' => array( 'attention', 'currency_data_attention' ),
			'stale' => array( 'stale', 'metrics_stale' ),
			'none' => array( 'none', 'none' ),
		);
	}

	/** @dataProvider unavailable_copy_cases */
	public function test_profile_unavailable_lifetime_value_remains_reason_specific( string $state, string $reason ): void {
		update_option( 'woocommerce_currency', 'USD' );
		list( $order, $customer ) = $this->order( 'VND', 100000 );
		global $wpdb;
		if ( 'mixed' === $state ) {
			$second = wc_create_order(); $this->owned_orders[] = $second;
			$second->set_billing_email( $order->get_billing_email() ); $second->set_currency( 'USD' ); $second->set_total( 10 ); $second->set_status( 'completed' ); $second->save();
			$this->assertSame( (int) $customer['id'], YoOhw_COS_Customers::sync_from_order( $second ) );
		} elseif ( 'unknown' === $state ) {
			// A persisted untrustworthy source currency, without guessing the store currency.
			$wpdb->update( YoOhw_COS_DB::customers_table(), array( 'money_state' => 'unknown', 'money_currency' => '' ), array( 'id' => $customer['id'] ) );
		} elseif ( 'stale' === $state ) {
			$wpdb->update( YoOhw_COS_DB::customers_table(), array( 'commerce_metrics_version' => 1 ), array( 'id' => $customer['id'] ) );
		} elseif ( 'none' === $state ) {
			$order->delete( true );
		} else {
			$migration = array( 'status' => 'preparing' === $state ? 'pending' : 'completed_with_issues', 'last_progress_at' => time(), 'last_error' => '' );
			update_option( 'yoohw_cos_data_migrations', array( 'commerce_currency_v3' => $migration ), false );
		}
		$customer = YoOhw_COS_Customers::get_customer( $customer['id'] );
		$this->assertSame( $reason, YoOhw_COS_Commerce_Metrics_Policy::availability( $customer )['reason'] );
		list( $copy ) = $this->profile_lifetime_copy( $customer['id'] );
		$this->assertSame( YoOhw_COS_Commerce_Metrics_Policy::reason_label( $reason ), $copy );
		$this->assertStringNotContainsString( '100', $copy );
		$this->assertStringNotContainsString( '(VND)', $copy );
	}

	public function test_factor_renderers_keep_generated_descriptions_outside_html_trust_boundary(): void {
		$payload = '<img src=x onerror="alert(1)"><script>alert(2)</script>& unsafe';
		$customer = array( 'risk_score' => 0, 'trust_score' => 50, 'lifecycle_stage' => 'new' );
		foreach ( array( 'render_risk_panel', 'render_trust_panel', 'render_lifecycle_panel' ) as $panel ) {
			$method = new ReflectionMethod( YoOhw_COS_Customer_Profile::class, $panel );
			$method->setAccessible( true );
			ob_start();
			try { $method->invoke( null, $customer, array( array( 'label' => 'Synthetic factor', 'description' => $payload, 'impact' => 0 ) ) ); }
			finally { $html = ob_get_clean(); }
			$this->assertStringContainsString( esc_html( $payload ), $html );
			$this->assertStringNotContainsString( '<img', $html );
			$this->assertStringNotContainsString( '<script', $html );
		}
	}

}
