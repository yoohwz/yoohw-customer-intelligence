<?php
require_once dirname( __DIR__, 3 ) . '/tests/environment.php';
$owned = yci_test_environment();
if ( 'seed' === getenv( 'CIT86_PHASE' ) ) {
    $db = new mysqli( 'localhost', $owned['database'], $owned['password'], $owned['database'], 0, getenv( 'YCI_TEST_ROOT' ) . '/mysql.sock' );
    foreach ( $db->query( 'SHOW TABLES' )->fetch_all( MYSQLI_NUM ) as $table ) {
        if ( preg_match( '/^wptests_[a-zA-Z0-9_]+$/D', $table[0] ) ) { $db->query( 'DROP TABLE `' . $table[0] . '`' ); }
    }
    $db->close();
}
require_once dirname( __DIR__, 3 ) . '/vendor/autoload.php';
require_once getenv( 'WP_TESTS_DIR' ) . '/includes/functions.php';
tests_add_filter( 'pre_http_request', static function () { return new WP_Error( 'cit86_http_blocked', 'Audit blocks HTTP.' ); } );
tests_add_filter( 'pre_option_woocommerce_custom_orders_table_enabled', static fn() => getenv( 'WC_HPOS_ENABLED' ) );
tests_add_filter( 'muplugins_loaded', static function () {
    require_once getenv( 'WC_PLUGIN_FILE' );
    WC_Install::create_tables();
    require_once getenv( 'CIT86_SOURCE' ) . '/yoohw-customer-intelligence.php';
} );
if ( 'upgrade' === getenv( 'CIT86_PHASE' ) ) {
    // The test-library bootstrap deletes all posts even with SKIP_INSTALL=1.
    // A real upgrade must preserve legacy CPT orders, so load ordinary WordPress.
    require getenv( 'YCI_TEST_ROOT' ) . '/wp-tests-config.php';
    tests_reset__SERVER();
    define( 'DISABLE_WP_CRON', true );
    require ABSPATH . 'wp-settings.php';
} else {
    require getenv( 'WP_TESTS_DIR' ) . '/includes/bootstrap.php';
}
if ( 'seed' === getenv( 'CIT86_PHASE' ) ) {
    WC_Install::install();
    YoOhw_COS_Install::install();
    update_option( 'woocommerce_currency', 'VND' );
    foreach ( array( '125000', '75000' ) as $total ) {
        $o = wc_create_order(); $o->set_billing_email( 'historical-cit86@example.test' ); $o->set_billing_phone( '+15558610000' );
        $o->set_currency( 'VND' ); $o->set_total( $total ); $o->set_status( 'completed' ); $o->save();
        if ( ! YoOhw_COS_Customers::sync_from_order( $o ) ) { throw new RuntimeException( 'Historical sync failed.' ); }
    }
}
if ( class_exists( 'YoOhw_COS_Migration_Runner' ) ) {
    for ( $i = 0; $i < 40; ++$i ) {
        $state = YoOhw_COS_Migration_Runner::get_state(); $active = false;
        foreach ( $state as $m ) { if ( is_array( $m ) && in_array( $m['status'] ?? '', array( 'pending', 'in_progress' ), true ) ) { $active = true; } }
        if ( ! $active ) { break; }
        YoOhw_COS_Migration_Runner::run_next_batch();
    }
}
global $wpdb;
$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', YoOhw_COS_DB::customers_table() ) );
$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE email=%s', YoOhw_COS_DB::customers_table(), 'historical-cit86@example.test' ), ARRAY_A );
$data = array( 'tag' => getenv( 'CIT86_TAG' ), 'phase' => getenv( 'CIT86_PHASE' ), 'mode' => getenv( 'WC_HPOS_ENABLED' ), 'version' => YOOHW_COS_VERSION,
    'db_version' => get_option( 'yoohw_cos_db_version' ), 'profiles' => $count, 'orders' => (int) ( $row['total_orders'] ?? 0 ), 'spent' => (float) ( $row['total_spent'] ?? 0 ),
    'state' => $row['money_state'] ?? 'pre-currency-schema', 'currency' => $row['money_currency'] ?? null,
    'ready' => method_exists( 'YoOhw_COS_Migration_Runner', 'currency_backfill_is_complete' ) ? YoOhw_COS_Migration_Runner::currency_backfill_is_complete() : null,
    'actual_hpos' => \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(),
    'wc_paid_count' => count( wc_get_orders( array( 'limit' => -1, 'status' => array( 'processing', 'completed' ) ) ) ),
    'cpt_counts' => $wpdb->get_results( "SELECT post_type,post_status,COUNT(*) n FROM {$wpdb->posts} WHERE post_type IN ('shop_order','shop_order_placehold') GROUP BY post_type,post_status", ARRAY_A ) );
file_put_contents( getenv( 'CIT86_HISTORICAL_OUTPUT' ), wp_json_encode( $data ) . "\n", FILE_APPEND );
if ( 'upgrade' === getenv( 'CIT86_PHASE' ) && ( 1 !== $count || 2 !== $data['orders'] || 200000.0 !== $data['spent'] || 'VND' !== $data['currency'] || 'comparable' !== $data['state'] || ! $data['ready'] ) ) {
    throw new RuntimeException( 'Historical VND upgrade did not converge: ' . wp_json_encode( $data ) );
}
echo 'PASS historical ' . getenv( 'CIT86_TAG' ) . ' ' . getenv( 'CIT86_PHASE' ) . ' HPOS=' . getenv( 'WC_HPOS_ENABLED' ) . "\n";
