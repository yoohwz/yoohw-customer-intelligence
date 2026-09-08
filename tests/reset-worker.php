<?php
/** Separate-process reset race probe; the same guard runs before any WP code. */
require_once __DIR__ . '/environment.php';
yci_test_environment();
require getenv( 'YCI_TEST_ROOT' ) . '/wp-tests-config.php';
define( 'DISABLE_WP_CRON', true );
$GLOBALS['wp_filter'] = array(
	'pre_http_request' => array( 10 => array( array( 'function' => static function() { return new WP_Error( 'blocked', 'Isolated test' ); }, 'accepted_args' => 3 ) ) ),
	'pre_option_woocommerce_custom_orders_table_enabled' => array( 10 => array( array( 'function' => static function() { return getenv( 'WC_HPOS_ENABLED' ); }, 'accepted_args' => 0 ) ) ),
	'muplugins_loaded' => array( 10 => array( array( 'function' => static function() {
		require getenv( 'WC_PLUGIN_FILE' );
		require dirname( __DIR__ ) . '/yoohw-customer-intelligence.php';
	}, 'accepted_args' => 0 ) ) ),
);
require ABSPATH . 'wp-settings.php';
$mode = $argv[1] ?? '';
if ( 'stale-sync' === $mode ) {
	$wpdb->query( 'START TRANSACTION' );
	YoOhw_COS_Reset_Guard::state(); // Establish an old repeatable-read snapshot before Reset.
	echo "READY\n";
	fflush( STDOUT );
	fgets( STDIN );
	echo 'RESULT:' . YoOhw_COS_Customers::sync_from_order_id( (int) $argv[2] ) . "\n";
	$wpdb->query( 'COMMIT' );
} elseif ( 'interrupt-reset' === $mode ) {
	add_filter( 'query', static function( $query ) {
		if ( false !== strpos( $query, 'TRUNCATE TABLE' ) && false !== strpos( $query, YoOhw_COS_DB::notes_table() ) ) {
			exit( 13 );
		}
		return $query;
	} );
	YoOhw_COS_Customers::reset_data();
	exit( 2 );
} elseif ( 'reset' === $mode ) {
	try {
		YoOhw_COS_Customers::reset_data();
		echo "RESET\n";
	} catch ( RuntimeException $exception ) {
		echo false !== strpos( $exception->getMessage(), 'busy' ) ? "BUSY\n" : "FAILED\n";
	}
} else {
	exit( 2 );
}
