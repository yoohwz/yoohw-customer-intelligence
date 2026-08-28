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

$yoohw_cos_hpos_enabled = strtolower( (string) getenv( 'WC_HPOS_ENABLED' ) );

if ( in_array( $yoohw_cos_hpos_enabled, array( 'yes', 'no' ), true ) ) {
	tests_add_filter(
		'pre_option_woocommerce_custom_orders_table_enabled',
		static fn(): string => $yoohw_cos_hpos_enabled
	);
}

tests_add_filter(
	'muplugins_loaded',
	static function(): void {
		$woocommerce = getenv( 'WC_PLUGIN_FILE' );

		if ( ! $woocommerce && defined( 'WP_PLUGIN_DIR' ) ) {
			$woocommerce = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		}

		if ( ! $woocommerce || ! is_readable( $woocommerce ) ) {
			throw new RuntimeException( 'WooCommerce test dependency is unavailable. Set WC_PLUGIN_FILE to woocommerce.php.' );
		}

		require_once $woocommerce;

		require dirname( __DIR__ ) . '/yoohw-customer-intelligence.php';
	}
);

require $yoohw_cos_tests_dir . '/includes/bootstrap.php';

if ( class_exists( 'WC_Install' ) ) {
	WC_Install::install();
}

$yoohw_cos_required_woocommerce_capabilities = array(
	'class WooCommerce'         => class_exists( 'WooCommerce' ),
	'class WC_Order'            => class_exists( 'WC_Order' ),
	'class WC_DateTime'         => class_exists( 'WC_DateTime' ),
	'function wc_create_order'  => function_exists( 'wc_create_order' ),
	'function wc_create_refund' => function_exists( 'wc_create_refund' ),
	'function wc_get_orders'     => function_exists( 'wc_get_orders' ),
);

foreach ( $yoohw_cos_required_woocommerce_capabilities as $yoohw_cos_capability => $yoohw_cos_available ) {
	if ( ! $yoohw_cos_available ) {
		throw new RuntimeException( 'WooCommerce test dependency failed to initialize: missing ' . $yoohw_cos_capability . '. Check WC_PLUGIN_FILE.' );
	}
}

if ( in_array( $yoohw_cos_hpos_enabled, array( 'yes', 'no' ), true ) ) {
	$yoohw_cos_order_util_class = '\\Automattic\\WooCommerce\\Utilities\\OrderUtil';

	if ( ! class_exists( $yoohw_cos_order_util_class ) ) {
		throw new RuntimeException( 'WooCommerce OrderUtil is unavailable; HPOS storage mode cannot be verified.' );
	}

	$yoohw_cos_actual_hpos = $yoohw_cos_order_util_class::custom_orders_table_usage_is_enabled();
	$yoohw_cos_expected_hpos = 'yes' === $yoohw_cos_hpos_enabled;

	if ( $yoohw_cos_actual_hpos !== $yoohw_cos_expected_hpos ) {
		throw new RuntimeException(
			sprintf(
				'WooCommerce order storage mismatch: WC_HPOS_ENABLED=%1$s, actual HPOS=%2$s.',
				$yoohw_cos_hpos_enabled,
				$yoohw_cos_actual_hpos ? 'yes' : 'no'
			)
		);
	}
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
