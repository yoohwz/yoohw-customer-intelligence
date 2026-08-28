<?php
/**
 * Data retention contract.
 *
 * Customer Intelligence data is preserved by default on uninstall. Sites that
 * explicitly set the non-autoloaded option below to "yes" may request removal.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( 'yes' !== get_option( 'yoohw_cos_remove_data_on_uninstall', 'no' ) ) {
	return;
}

global $wpdb;

$yoohw_cos_table_suffixes = array(
	'yoohw_cos_customer_segments',
	'yoohw_cos_customer_tags',
	'yoohw_cos_notification_log',
	'yoohw_cos_migration_issues',
	'yoohw_cos_customer_order_facts',
	'yoohw_cos_segments',
	'yoohw_cos_tags',
	'yoohw_cos_tasks',
	'yoohw_cos_notes',
	'yoohw_cos_events',
	'yoohw_cos_customers',
);

foreach ( $yoohw_cos_table_suffixes as $yoohw_cos_table_suffix ) {
	$yoohw_cos_table = $wpdb->prefix . $yoohw_cos_table_suffix;
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $yoohw_cos_table ) );
}

$wpdb->query(
	$wpdb->prepare(
		'DELETE FROM %i WHERE option_name LIKE %s',
		$wpdb->options,
		$wpdb->esc_like( 'yoohw_cos_' ) . '%'
	)
);
