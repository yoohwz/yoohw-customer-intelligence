<?php
/** Loopback-only HTTP regression entrypoint; all ownership checks precede WP. */
try {
	require_once __DIR__ . '/environment.php';
	yci_test_environment( true );
} catch ( Throwable $error ) {
	if ( 'cli-server' !== PHP_SAPI ) { throw $error; }
	http_response_code( 403 );
	echo 'Owned HTTP environment rejected.';
	exit;
}
if ( 'cli-server' !== PHP_SAPI || '/wp-admin/admin.php' !== parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) ) {
	http_response_code( 404 );
	exit;
}
define( 'DISABLE_WP_CRON', true );
define( 'WP_ADMIN', true );
$GLOBALS['wp_filter'] = array(
	'pre_http_request' => array( 10 => array( array( 'function' => static function() { return new WP_Error( 'blocked', 'Isolated HTTP test' ); }, 'accepted_args' => 3 ) ) ),
	'pre_option_woocommerce_custom_orders_table_enabled' => array( 10 => array( array( 'function' => static function() { return getenv( 'WC_HPOS_ENABLED' ); }, 'accepted_args' => 0 ) ) ),
	'muplugins_loaded' => array( 10 => array( array( 'function' => static function() {
		require getenv( 'WC_PLUGIN_FILE' );
		require dirname( __DIR__ ) . '/yoohw-customer-intelligence.php';
	}, 'accepted_args' => 0 ) ) ),
);
require_once ABSPATH . 'wp-load.php';
// Offline dependencies must not run version checks that turn intercepted HTTP into warnings.
foreach ( array( '_maybe_update_core', '_maybe_update_plugins', '_maybe_update_themes' ) as $callback ) {
	remove_action( 'admin_init', $callback );
}
// Make late dispatch observable even with PHP output buffering enabled.
add_action( 'admin_head', static function() { echo '<meta name="yci-admin-header-probe" content="rendered">'; } );
add_action( 'admin_footer', static function() { echo '<!-- yci-admin-footer-probe -->'; } );
require ABSPATH . 'wp-admin/admin.php';
