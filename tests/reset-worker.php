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

if ( 'lock-probe' === $mode ) {
	// Existing admitted process entrypoint; commands touch only synthetic owned fixtures.
	$input = json_decode( fgets( STDIN ), true );
	$kind = $input['kind'];
	$identity = $input['identity'] ?? array();
	$acquire = static function() use ( $kind, $identity ) {
		if ( 'identity' === $kind ) { return YoOhw_COS_Customer_Identity::acquire_creation_lock( $identity ); }
		$method = new ReflectionMethod( YoOhw_COS_Migration_Runner::class, 'acquire_lock' );
		$method->setAccessible( true ); return $method->invoke( null );
	};
	$release = static function( $handle ) use ( $kind ) {
		if ( 'identity' === $kind ) { YoOhw_COS_Customer_Identity::release_creation_lock( $handle ); return; }
		$method = new ReflectionMethod( YoOhw_COS_Migration_Runner::class, 'release_lock' );
		$method->setAccessible( true ); $method->invoke( null, $handle );
	};
	$handle = $acquire();
	echo json_encode( array( 'acquired' => ! empty( $handle ), 'connection' => (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' ) ) ) . "\n"; fflush( STDOUT );
	try {
		while ( false !== ( $line = fgets( STDIN ) ) ) {
			$command = json_decode( $line, true );
			$action = $command['action'];
			$result = array();
			if ( 'end-ownership' === $action ) {
				// Simulate the native ownership ending while the old PHP handle is retained.
				$result['ended'] = (int) $wpdb->get_var( 'SELECT RELEASE_ALL_LOCKS()' );
			} elseif ( 'release' === $action ) {
				$release( $handle ); $result['released'] = true;
			} elseif ( 'try' === $action ) {
				$next = $acquire(); $result['acquired'] = ! empty( $next );
				if ( $next ) { $release( $next ); }
			} elseif ( 'sync' === $action || 'retry' === $action ) {
				$id = (int) $command['order'];
				if ( 'retry' === $action ) {
					wp_clear_scheduled_hook( YoOhw_COS_Customers::ORDER_SYNC_RETRY_HOOK, array( $id ) );
					do_action( YoOhw_COS_Customers::ORDER_SYNC_RETRY_HOOK, $id );
					$result['customer'] = YoOhw_COS_Customer_Identity::get_persisted_order_customer_id( wc_get_order( $id ) );
				} else { $result['customer'] = YoOhw_COS_Customers::sync_from_order_id( $id ); }
			} elseif ( 'migration' === $action ) {
				wp_cache_delete( 'yoohw_cos_data_migrations', 'options' );
				YoOhw_COS_Migration_Runner::run_next_batch();
				$result['state'] = YoOhw_COS_Migration_Runner::get_state();
			} elseif ( 'exit' === $action ) { break; }
			echo json_encode( $result ) . "\n"; fflush( STDOUT );
		}
	} finally { if ( $handle ) { $release( $handle ); } }
} elseif ( in_array( $mode, array( 'identity-sync-held', 'identity-sync-now', 'identity-retry' ), true ) ) {
	$decisions = 0;
	add_filter( 'yoohw_cos_customer_sync_data', static function( $data, $order, $customer_id ) use ( &$decisions, $mode ) {
		if ( 0 === $customer_id ) {
			$decisions++;
			if ( 'identity-sync-held' === $mode ) { echo "CREATING\n"; fflush( STDOUT ); fgets( STDIN ); }
		}
		return $data;
	}, 10, 3 );
	$id = (int) $argv[2];
	if ( 'identity-retry' === $mode ) {
		wp_clear_scheduled_hook( YoOhw_COS_Customers::ORDER_SYNC_RETRY_HOOK, array( $id ) );
		do_action( YoOhw_COS_Customers::ORDER_SYNC_RETRY_HOOK, $id );
		$customer = YoOhw_COS_Customer_Identity::get_persisted_order_customer_id( wc_get_order( $id ) );
	} else { $customer = YoOhw_COS_Customers::sync_from_order_id( $id ); }
	echo json_encode( array( 'customer' => $customer, 'decisions' => $decisions, 'retry' => false !== wp_next_scheduled( YoOhw_COS_Customers::ORDER_SYNC_RETRY_HOOK, array( $id ) ) ) ) . "\n";
} elseif ( 'csv-request' === $mode ) {
	// Preserve the exporter's real exit; capture its bytes only when this owned process ends.
	$input = json_decode( fgets( STDIN ), true );
	wp_set_current_user( (int) $input['user'] );
	$_SERVER['REQUEST_METHOD'] = 'GET';
	$_SERVER['HTTP_HOST'] = 'example.test';
	$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php';
	$_SERVER['SERVER_PORT'] = '80';
	$_GET = $_REQUEST = wp_slash( $input['data'] );
	$_POST = array();
	$status = 200;
	$message = '';
	$before_mail = $GLOBALS['yci_intercepted_mail'] ?? 0;
	add_filter( 'yoohw_cos_customer_csv_export_limit', static function() use ( $input ) { return $input['limit'] ?? 5000; } );
	add_filter( 'gettext', static function( $translation, $text, $domain ) use ( $input ) {
		return 'yoohw-customer-intelligence' === $domain ? ( $input['translations'][ $text ] ?? $translation ) : $translation;
	}, 10, 3 );
	add_filter( 'wp_die_handler', static function() {
		return static function( $message ) { throw new RuntimeException( strip_tags( (string) $message ), 403 ); };
	} );
	set_error_handler( static function( $severity, $message, $file, $line ) { throw new ErrorException( $message, 500, $severity, $file, $line ); }, E_WARNING | E_NOTICE );
	$bytes = '';
	ob_start( static function( $chunk ) use ( &$bytes ) { $bytes .= $chunk; return ''; } );
	register_shutdown_function( static function() use ( &$bytes, &$status, &$message, $before_mail ) {
		while ( ob_get_level() > 0 ) { ob_end_flush(); }
		echo json_encode( array( 'status' => $status, 'message' => $message, 'csv' => base64_encode( $bytes ), 'mail' => ( $GLOBALS['yci_intercepted_mail'] ?? 0 ) - $before_mail ) );
	} );
	try {
		if ( ! empty( $input['help'] ) ) {
			require_once ABSPATH . 'wp-admin/includes/admin.php';
			set_current_screen( 'woocommerce_page_yoohw-customer-intelligence' );
			YoOhw_COS_Admin_Menu::render_customers_page();
		} else {
			YoOhw_COS_Customer_Exporter::maybe_handle_request();
			throw new RuntimeException( 'Requested export returned instead of terminating.', 500 );
		}
	} catch ( Throwable $exception ) {
		$status = $exception->getCode();
		$message = $exception->getMessage();
	}
	exit;
} elseif ( 'ordinary-request' === $mode ) {
	// Fresh PHP request with the actual handler/nonce/capability path; no existing site.
	$input = json_decode( fgets( STDIN ), true );
	wp_set_current_user( (int) $input['user'] );
	if ( 'handle_send_customer_email' === ( $input['handler'] ?? '' ) || ! empty( $input['search'] ) ) { define( 'DOING_AJAX', true ); }
	$_SERVER['REQUEST_METHOD'] = $input['method'];
	$_SERVER['HTTP_HOST'] = 'example.test';
	$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php';
	$_SERVER['SERVER_PORT'] = '80';
	$_POST = 'POST' === $input['method'] ? $input['data'] : array();
	$_GET = 'GET' === $input['method'] ? $input['data'] : array();
	$_REQUEST = $input['data'];
	$before_mail = $GLOBALS['yci_intercepted_mail'] ?? 0;

	$reset_during_warning = '';
	if ( ! empty( $input['probe_reset_warning'] ) ) {
		add_filter( 'gettext', static function( $translation, $text ) use ( &$reset_during_warning ) {
			if ( '' === $reset_during_warning && in_array( $text, array( 'This tag is assigned to %s customers. Delete it anyway?', 'This segment is assigned to %s customers. Delete it anyway?' ), true ) ) {
				$process = proc_open( array( PHP_BINARY, '-d', 'disable_functions=mail', __FILE__, 'reset' ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
				if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Could not start owned reset probe.' ); }
				fclose( $pipes[0] );
				stream_set_timeout( $pipes[1], 15 );
				$reset_during_warning = trim( stream_get_contents( $pipes[1] ) );
				fclose( $pipes[1] ); fclose( $pipes[2] );
				if ( 0 !== proc_close( $process ) ) { throw new RuntimeException( 'Owned reset probe failed.' ); }
			}
			return $translation;
		}, 10, 2 );
	}

	add_filter( 'wp_die_handler', static function() {
		return static function( $message, $title = '', $args = array() ) {
			throw new RuntimeException( strip_tags( (string) $message ), (int) ( $args['response'] ?? 500 ) );
		};
	} );
	add_filter( 'wp_die_ajax_handler', static function() { return apply_filters( 'wp_die_handler', null ); } );
	add_filter( 'wp_redirect', static function( $url ) { throw new RuntimeException( $url, 302 ); } );
	set_error_handler( static function( $severity, $message, $file, $line ) { throw new ErrorException( $message, 500, $severity, $file, $line ); }, E_WARNING | E_NOTICE );
	ob_start();
	try {
		if ( isset( $input['render_kind'] ) ) {
			require_once ABSPATH . 'wp-admin/includes/admin.php';
			set_current_screen( 'woocommerce_page_yoohw-customer-intelligence' );
			call_user_func( array( 'YoOhw_COS_Admin_Menu', 'render_' . $input['render_kind'] . 's_page' ) );
		} elseif ( isset( $input['bulk'] ) ) {
			$method = new ReflectionMethod( 'YoOhw_COS_Admin_Menu', 'maybe_handle_' . $input['bulk'] . '_bulk_action' );
			$method->setAccessible( true );
			$method->invoke( null );
		} else {
			call_user_func( array( ! empty( $input['search'] ) ? 'YoOhw_COS_Order_Admin' : 'YoOhw_COS_Admin_Tools', $input['handler'] ) );
		}
		$status = 200;
		$message = '';
	} catch ( Throwable $exception ) {
		$status = $exception->getCode();
		$message = $exception->getMessage();
	}
	restore_error_handler();
	$output = ob_get_clean();
	echo json_encode( array( 'reset' => $reset_during_warning, 'status' => $status, 'message' => $message, 'body' => $output, 'mail' => ( $GLOBALS['yci_intercepted_mail'] ?? 0 ) - $before_mail ) ) . "\n";
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
