<?php
/** CIT-86 characterization evidence; execute only inside the owned isolated harness. */
require_once dirname( __DIR__, 2 ) . '/tests/environment.php';
yci_test_environment();

final class CIT86_Currency_Audit extends WP_UnitTestCase {
    private $orders = array();
    private $saved = array();

    public function set_up(): void {
        parent::set_up();
        foreach ( array( 'woocommerce_currency', 'yoohw_cos_data_migrations', 'yoohw_cos_intelligence_generation', 'yoohw_cos_intelligence_freshness', 'cron' ) as $key ) {
            $this->saved[ $key ] = get_option( $key, null );
        }
        delete_option( YoOhw_COS_Reset_Guard::OPTION );
        YoOhw_COS_Reset_Guard::init();
        foreach ( wc_get_orders( array( 'limit' => -1, 'type' => 'shop_order', 'status' => array_keys( wc_get_order_statuses() ) ) ) as $old ) { $old->delete( true ); }
        YoOhw_COS_Customers::reset_data();
        update_option( 'yoohw_cos_data_migrations', array( 'commerce_facts_v2' => array( 'status' => 'completed' ), 'commerce_currency_v3' => array( 'status' => 'completed' ) ), false );
        $user = wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        $user->add_cap( 'manage_woocommerce' );
    }

    public function tear_down(): void {
        foreach ( $this->orders as $order ) {
            $fresh = wc_get_order( $order->get_id() );
            if ( $fresh ) { $fresh->delete( true ); }
        }
        global $wpdb;
        foreach ( YoOhw_COS_Install::expected_table_keys() as $key ) {
            $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', YoOhw_COS_DB::table( $key ) ) );
        }
        foreach ( $this->saved as $key => $value ) {
            if ( null === $value ) { delete_option( $key ); } else { update_option( $key, $value, false ); }
        }
        parent::tear_down();
    }

    private function order( string $currency, string $total, string $subject = 'one' ): WC_Order {
        $order = wc_create_order();
        $this->orders[] = $order;
        $order->set_billing_email( 'cit86-' . $subject . '@example.test' );
        $order->set_billing_phone( 'one' === $subject ? '+15558600001' : '+15558600002' );
        $order->set_currency( $currency );
        $order->set_total( $total );
        $order->set_status( 'completed' );
        $order->save();
        return $order;
    }

    private function evidence( string $case, array $data ): void {
        $path = getenv( 'CIT86_AUDIT_OUTPUT' );
        if ( $path ) { file_put_contents( $path, wp_json_encode( array( 'mode' => getenv( 'WC_HPOS_ENABLED' ), 'case' => $case, 'data' => $data ) ) . "\n", FILE_APPEND ); }
    }

    private function snapshot( int $id ): array {
        $c = YoOhw_COS_Customers::get_customer( $id );
        ob_start(); YoOhw_COS_Customer_Profile::render( $id ); $profile = ob_get_clean();
        ob_start(); YoOhw_COS_Admin_Menu::render_customers_page(); $list = ob_get_clean();
        $formatted = wp_strip_all_tags( YoOhw_COS_Commerce_Metrics_Policy::format_money( $c, 'total_spent' ) );
        return array(
            'state' => $c['money_state'], 'currency' => $c['money_currency'], 'version' => (int) $c['commerce_metrics_version'],
            'orders' => (int) $c['total_orders'], 'spent' => (float) $c['total_spent'], 'aov' => (float) $c['average_order_value'],
            'global_ready' => YoOhw_COS_Migration_Runner::currency_backfill_is_complete(),
            'comparable' => (bool) YoOhw_COS_Commerce_Metrics_Policy::money_is_comparable( $c ),
            'matches_store' => YoOhw_COS_Commerce_Metrics_Policy::money_matches_store( $c ), 'display' => $formatted,
            'rfm' => array_map( 'wp_strip_all_tags', YoOhw_COS_RFM::summary( $c ) ),
            'profile_has_unavailable' => false !== strpos( $profile, 'Unavailable (mixed or unknown currency)' ),
            'list_has_unavailable' => false !== strpos( $list, 'Unavailable (mixed or unknown currency)' ),
            'extension' => array_intersect_key( YoOhw_COS_Customer_Facts::snapshot( $c ), array_flip( array( 'core/total_spent', 'core/average_order_value', 'core/money_state', 'core/money_currency' ) ) ),
            'overview_state' => YoOhw_COS_Overview::get_summary()['money_state'],
        );
    }

    public function test_vnd_and_eur_pipeline_refund_update_reassignment_delete(): void {
        foreach ( array( 'VND', 'EUR' ) as $currency ) {
            update_option( 'woocommerce_currency', $currency );
            $a = $this->order( $currency, '125000' );
            $b = $this->order( $currency, '75000' );
            $id = YoOhw_COS_Customers::sync_from_order( $a );
            $this->assertSame( $id, YoOhw_COS_Customers::sync_from_order( $b ) );
            $s = $this->snapshot( $id );
            $this->assertTrue( $s['comparable'] ); $this->assertSame( $currency, $s['currency'] );
            $this->assertSame( 200000.0, $s['spent'] ); $this->assertSame( 100000.0, $s['aov'] );
            $this->assertFalse( $s['profile_has_unavailable'] ); $this->assertFalse( $s['list_has_unavailable'] );
            $this->evidence( $currency . '-same-currency', $s );
            $refund = wc_create_refund( array( 'order_id' => $a->get_id(), 'amount' => 25000, 'refund_payment' => false, 'restock_items' => false ) );
            $this->assertInstanceOf( 'WC_Order_Refund', $refund );
            YoOhw_COS_Customers::sync_from_order( wc_get_order( $a->get_id() ) );
            $this->assertSame( 175000.0, $this->snapshot( $id )['spent'] );
            $b->set_total( '80000' ); $b->save(); YoOhw_COS_Customers::sync_from_order( $b );
            $this->assertSame( 180000.0, $this->snapshot( $id )['spent'] );
            $b->set_billing_email( 'cit86-two@example.test' ); $b->set_billing_phone( '+15558600002' ); $b->save();
            $target = YoOhw_COS_Customers::create_customer( array( 'email' => 'cit86-two@example.test', 'phone' => '+15558600002' ) );
            $b->update_meta_data( YoOhw_COS_Customers::ORDER_CUSTOMER_META_KEY, $target );
            $b->update_meta_data( YoOhw_COS_Reset_Guard::META_KEY, YoOhw_COS_Reset_Guard::epoch() . ':' . $target ); $b->save();
            $new = YoOhw_COS_Customers::sync_from_order( $b );
            $this->assertNotSame( $id, $new ); $this->assertSame( 100000.0, $this->snapshot( $id )['spent'] );
            $this->assertSame( 80000.0, $this->snapshot( $new )['spent'] );
            $b->delete( true );
            $this->assertSame( 0, $this->snapshot( $new )['orders'] );
            $this->evidence( $currency . '-refund-update-reassign-delete', array( 'pass' => true ) );
            $a->delete( true );
            global $wpdb;
            $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', YoOhw_COS_DB::customers_table() ) );
        }
    }

    public function test_mixed_unknown_none_and_foreign_currency(): void {
        global $wpdb;
        update_option( 'woocommerce_currency', 'VND' );
        $a = $this->order( 'EUR', '123.45' ); $id = YoOhw_COS_Customers::sync_from_order( $a );
        $s = $this->snapshot( $id ); $this->assertTrue( $s['comparable'] ); $this->assertFalse( $s['matches_store'] );
        $this->assertStringContainsString( '(EUR)', $s['display'] ); $this->evidence( 'foreign-EUR-in-VND-store', $s );
        update_option( 'woocommerce_currency', 'EUR' ); $this->assertTrue( $this->snapshot( $id )['matches_store'] );
        update_option( 'woocommerce_currency', 'VND' ); $this->assertFalse( $this->snapshot( $id )['matches_store'] );
        $b = $this->order( 'VND', '100000' ); YoOhw_COS_Customers::sync_from_order( $b );
        $s = $this->snapshot( $id ); $this->assertSame( 'mixed', $s['state'] ); $this->assertFalse( $s['comparable'] ); $this->evidence( 'mixed', $s );
        $wpdb->update( YoOhw_COS_DB::order_facts_table(), array( 'currency' => null ), array( 'order_id' => $b->get_id() ) );
        YoOhw_COS_Commerce_Aggregates::rebuild_customer( $id );
        $s = $this->snapshot( $id ); $this->assertSame( 'unknown', $s['state'] ); $this->evidence( 'unknown-fact', $s );
        $a->delete( true ); $b->delete( true ); $s = $this->snapshot( $id );
        $this->assertSame( 'none', $s['state'] ); $this->assertFalse( $s['profile_has_unavailable'] ); $this->evidence( 'none', $s );
    }

    public function test_pending_progress_stall_issues_and_current_schema_recovery(): void {
        global $wpdb;
        update_option( 'woocommerce_currency', 'VND' );
        $a = $this->order( 'VND', '100000' ); $id = YoOhw_COS_Customers::sync_from_order( $a );
        foreach ( array( 'pending', 'in_progress', 'completed_with_issues' ) as $status ) {
            update_option( 'yoohw_cos_data_migrations', array( 'commerce_currency_v3' => array( 'status' => $status, 'phase' => 'orders', 'next_page' => 1, 'last_customer_id' => 0 ) ), false );
            wp_clear_scheduled_hook( YoOhw_COS_Migration_Runner::HOOK );
            $s = $this->snapshot( $id ); $this->assertFalse( $s['comparable'] ); $this->assertTrue( $s['profile_has_unavailable'] );
            $this->evidence( $status . '-unscheduled', $s );
            YoOhw_COS_Migration_Runner::init();
            $this->evidence( $status . '-init-scheduler', array( 'scheduled' => (bool) wp_next_scheduled( YoOhw_COS_Migration_Runner::HOOK ) ) );
        }
        $wpdb->update( YoOhw_COS_DB::order_facts_table(), array( 'currency' => null, 'policy_version' => 1 ), array( 'order_id' => $a->get_id() ) );
        update_option( 'yoohw_cos_data_migrations', array( 'commerce_facts_v2' => array( 'status' => 'completed' ) ), false );
        YoOhw_COS_Install::maybe_update();
        $this->assertSame( 'pending', YoOhw_COS_Migration_Runner::get_state()['commerce_currency_v3']['status'] );
        $before = YoOhw_COS_Migration_Runner::get_state()['commerce_currency_v3'];
        for ( $i = 0; $i < 15; ++$i ) {
            YoOhw_COS_Migration_Runner::run_next_batch();
            if ( YoOhw_COS_Migration_Runner::currency_backfill_is_complete() ) { break; }
        }
        $this->assertTrue( $this->snapshot( $id )['comparable'] );
        $this->evidence( 'missing-v3-null-fact-recovery', $this->snapshot( $id ) );
        update_option( 'yoohw_cos_data_migrations', array( 'commerce_currency_v3' => $before ), false );
        for ( $i = 0; $i < 15; ++$i ) { YoOhw_COS_Migration_Runner::run_next_batch(); if ( YoOhw_COS_Migration_Runner::currency_backfill_is_complete() ) { break; } }
        $this->assertSame( 1, $this->snapshot( $id )['orders'] ); $this->assertSame( 100000.0, $this->snapshot( $id )['spent'] );
        $this->evidence( 'worker-replay-idempotent', array( 'pass' => true ) );
    }

    public function test_characterize_customer_stale_gap_and_persisted_decision_gap(): void {
        global $wpdb;
        update_option( 'woocommerce_currency', 'VND' );
        $a = $this->order( 'VND', '100000' ); $id = YoOhw_COS_Customers::sync_from_order( $a );
        $wpdb->update( YoOhw_COS_DB::customers_table(), array( 'commerce_metrics_version' => 1, 'money_state' => 'unknown', 'money_currency' => null ), array( 'id' => $id ) );
        update_option( 'yoohw_cos_data_migrations', array( 'commerce_facts_v2' => array( 'status' => 'completed' ) ), false );
        YoOhw_COS_Install::maybe_update();
        $this->assertArrayNotHasKey( 'commerce_currency_v3', YoOhw_COS_Migration_Runner::get_state() );
        YoOhw_COS_Commerce_Aggregates::rebuild_customer( $id );
        $s = $this->snapshot( $id ); $this->assertTrue( $s['global_ready'] ); $this->assertFalse( $s['comparable'] ); $this->assertSame( 1, $s['version'] );
        $this->evidence( 'DEFECT-stale-customer-not-admitted', $s );
        YoOhw_COS_Commerce_Aggregates::rebuild_customer( $id, true );
        $generation = YoOhw_COS_Intelligence::get_scoring_generation();
        $this->assertSame( 'platinum', YoOhw_COS_Customers::get_customer( $id )['vip_status'] );
        $wpdb->update( YoOhw_COS_DB::order_facts_table(), array( 'currency' => null, 'policy_version' => 1 ), array( 'order_id' => $a->get_id() ) );
        YoOhw_COS_Install::maybe_update();
        $c = YoOhw_COS_Customers::get_customer( $id );
        $this->assertFalse( (bool) YoOhw_COS_Commerce_Metrics_Policy::money_is_comparable( $c ) );
        $this->assertTrue( YoOhw_COS_Intelligence::persisted_decisions_are_safe( $c ) );
        $this->assertSame( 'platinum', $c['vip_status'] );
        $this->assertSame( 'none', YoOhw_COS_Intelligence::calculate_vip_status( $c ) );
        $high_value = YoOhw_COS_Customer_Query::query( array( 'vip_status' => 'high_value' ) )['total_items'];
        $this->assertSame( 1, $high_value );
        $this->evidence( 'DEFECT-pending-keeps-persisted-money-decision', array( 'global_ready' => false, 'persisted_safe' => true, 'vip_status' => $c['vip_status'], 'live_vip' => 'none', 'high_value_filter_count' => $high_value, 'generation_unchanged' => $generation === YoOhw_COS_Intelligence::get_scoring_generation() ) );
    }
}
