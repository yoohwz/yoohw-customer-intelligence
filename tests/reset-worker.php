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

if ( 'ordinary-request' === $mode ) {
	// Fresh PHP request with the actual handler/nonce/capability path; no existing site.
	$input = json_decode( fgets( STDIN ), true );
	wp_set_current_user( (int) $input['user'] );
	if ( 'handle_send_customer_email' === ( $input['handler'] ?? '' ) || ! empty( $input['search'] ) ) { define( 'DOING_AJAX', true ); }
	$_SERVER['REQUEST_METHOD'] = $input['method'];
	$_POST = 'POST' === $input['method'] ? $input['data'] : array();
	$_GET = 'GET' === $input['method'] ? $input['data'] : array();
	$_REQUEST = $input['data'];
	$before_mail = $GLOBALS['yci_intercepted_mail'] ?? 0;
	add_filter( 'wp_die_handler', static function() {
		return static function( $message, $title = '', $args = array() ) {
			throw new RuntimeException( strip_tags( (string) $message ), (int) ( $args['response'] ?? 500 ) );
		};
	} );
	add_filter( 'wp_die_ajax_handler', static function() { return apply_filters( 'wp_die_handler', null ); } );
	add_filter( 'wp_redirect', static function( $url ) { throw new RuntimeException( $url, 302 ); } );
	ob_start();
	try {
		if ( isset( $input['bulk'] ) ) {
			$method = new ReflectionMethod( 'YoOhw_COS_Admin_Menu', 'maybe_handle_' . $input['bulk'] . '_bulk_action' );
			$method->setAccessible( true );
			$method->invoke( null );
		} else {
			call_user_func( array( ! empty( $input['search'] ) ? 'YoOhw_COS_Order_Admin' : 'YoOhw_COS_Admin_Tools', $input['handler'] ) );
		}
		$status = 200;
		$message = '';
	} catch ( RuntimeException $exception ) {
		$status = $exception->getCode();
		$message = $exception->getMessage();
	}
	$output = ob_get_clean();
	echo json_encode( array( 'status' => $status, 'message' => $message, 'body' => $output, 'mail' => ( $GLOBALS['yci_intercepted_mail'] ?? 0 ) - $before_mail ) ) . "\n";
} elseif ( 'stale-ordinary' === $mode ) {
	echo "READY\n";
	fflush( STDOUT );
	fgets( STDIN );
	echo 'RESULT:' . YoOhw_COS_Notes::add_note( (int) $argv[2], 'Old request' ) . "\n";
} elseif ( 'hold-ordinary' === $mode ) {
	add_filter( 'query', static function( $query ) {
		if ( 0 === strpos( $query, 'INSERT INTO `' . YoOhw_COS_DB::notes_table() . '`' ) ) {
			echo "LOCKED\n";
			fflush( STDOUT );
			fgets( STDIN );
		}
		return $query;
	} );
	echo 'NOTE:' . YoOhw_COS_Notes::add_note( (int) $argv[2], 'Current writer' ) . "\n";
} elseif ( 'stale-sync' === $mode ) {
	$wpdb->query( 'START TRANSACTION' );
	YoOhw_COS_Reset_Guard::state(); // Establish an old repeatable-read snapshot before Reset.
	echo "READY\n";
	fflush( STDOUT );
	fgets( STDIN );
	echo 'RESULT:' . YoOhw_COS_Customers::sync_from_order_id( (int) $argv[2] ) . "\n";
	$wpdb->query( 'COMMIT' );
} elseif ( 'hold-writer' === $mode ) {
	if ( ! YoOhw_COS_Reset_Guard::enter() ) { exit( 3 ); }
	echo "LOCKED\n";
	fflush( STDOUT );
	fgets( STDIN );
	YoOhw_COS_Reset_Guard::leave();
	echo "RELEASED\n";
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
