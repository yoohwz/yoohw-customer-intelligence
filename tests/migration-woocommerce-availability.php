<?php
/** Verify that a missing WooCommerce order source cannot complete a commerce migration. */
define( 'ABSPATH', __DIR__ );

$migration_state = array(
	'commerce_currency_v3' => array( 'status' => 'pending', 'phase' => 'orders', 'next_page' => 1 ),
);
$scheduled = false;
$released = false;

function get_option( $key, $default = false ) {
	return 'yoohw_cos_data_migrations' === $key ? $GLOBALS['migration_state'] : $default;
}
function wp_next_scheduled( $hook ) {
	return $GLOBALS['scheduled'];
}
function wp_schedule_single_event( $timestamp, $hook ) {
	$GLOBALS['scheduled'] = true;
}
function add_action( $hook, $callback ) {}
function sanitize_key( $value ) { return strtolower( (string) $value ); }

final class YoOhw_COS_Install {
	public static function schema_is_ready(): bool { return true; }
}
final class YoOhw_COS_DB {
	public static function acquire_work_locks( array $locks ): string { return 'owned-test-lock'; }
	public static function release_work_locks( string $lock ): void { $GLOBALS['released'] = true; }
}

require_once dirname( __DIR__ ) . '/includes/class-yoohw-cos-migration-runner.php';

YoOhw_COS_Migration_Runner::init();
if ( $scheduled ) { throw new RuntimeException( 'Commerce migration scheduled without WooCommerce order APIs.' ); }

$method = new ReflectionMethod( YoOhw_COS_Migration_Runner::class, 'run_next_batch_guarded' );
$method->setAccessible( true );
$method->invoke( null );
if ( ! $released || $migration_state['commerce_currency_v3']['status'] !== 'pending' || $scheduled ) {
	throw new RuntimeException( 'Missing WooCommerce order APIs changed migration progress.' );
}

eval( 'function wc_get_orders() {} function wc_get_order_statuses() {}' );
YoOhw_COS_Migration_Runner::init();
if ( ! $scheduled ) { throw new RuntimeException( 'Commerce migration did not resume scheduling after WooCommerce returned.' ); }

echo "PASS: commerce migration pauses without WooCommerce and resumes scheduling when available\n";
