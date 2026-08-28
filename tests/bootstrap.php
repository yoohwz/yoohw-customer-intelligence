<?php

defined( 'ABSPATH' ) || 'cli' === PHP_SAPI || exit;

$yoohw_cos_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';

if ( file_exists( $yoohw_cos_autoload ) ) {
	require_once $yoohw_cos_autoload;
}

$yoohw_cos_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $yoohw_cos_tests_dir ) {
	$yoohw_cos_tests_dir = '/tmp/wordpress-tests-lib';
}

if ( ! file_exists( $yoohw_cos_tests_dir . '/includes/functions.php' ) ) {
	echo "WordPress test library not found. Set WP_TESTS_DIR.\n";
	exit( 1 );
}

require_once $yoohw_cos_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function(): void {
		$woocommerce = getenv( 'WC_PLUGIN_FILE' );

		if ( ! $woocommerce && defined( 'WP_PLUGIN_DIR' ) ) {
			$woocommerce = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		}

		if ( $woocommerce && file_exists( $woocommerce ) ) {
			require_once $woocommerce;
		}

		require dirname( __DIR__ ) . '/yoohw-customer-intelligence.php';
	}
);

require $yoohw_cos_tests_dir . '/includes/bootstrap.php';

if ( class_exists( 'WC_Install' ) ) {
	WC_Install::install();
}

if ( class_exists( 'YoOhw_COS_Install' ) ) {
	YoOhw_COS_Install::install();
}

if ( class_exists( 'YoOhw_COS_Customers' ) ) {
	YoOhw_COS_Customers::reset_data();
}

if ( function_exists( 'wc_get_orders' ) ) {
	do {
		$yoohw_cos_test_orders = wc_get_orders(
			array(
				'limit'  => 100,
				'return' => 'objects',
				'status' => array_keys( wc_get_order_statuses() ),
				'type'   => 'shop_order',
			)
		);

		foreach ( is_array( $yoohw_cos_test_orders ) ? $yoohw_cos_test_orders : array() as $yoohw_cos_test_order ) {
			if ( $yoohw_cos_test_order instanceof WC_Order ) {
				$yoohw_cos_test_order->delete( true );
			}
		}
	} while ( count( $yoohw_cos_test_orders ) >= 100 );
}
