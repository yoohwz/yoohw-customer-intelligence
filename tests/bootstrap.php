<?php

defined( 'ABSPATH' ) || 'cli' === PHP_SAPI || exit;

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
