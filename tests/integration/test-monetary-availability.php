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

}
