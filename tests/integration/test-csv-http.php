<?php
require_once dirname( __DIR__ ) . '/environment.php';
yci_test_environment();

/** Real HTTP bytes from the native wp-admin lifecycle, not a direct renderer. */
final class YCI_CSV_HTTP_Test extends WP_UnitTestCase {
	private $server;
	private $port;
	private $cookies = array();

	public function set_up(): void {
		parent::set_up();
		// Fresh WC install requests its one-time setup redirect; this fixture is already configured.
		delete_transient( '_wc_activation_redirect' );
		$socket = stream_socket_server( 'tcp://127.0.0.1:0', $error, $message );
		$this->assertIsResource( $socket );
		$this->port = (int) substr( strrchr( stream_socket_get_name( $socket, false ), ':' ), 1 );
		fclose( $socket );
		$log = getenv( 'YCI_TEST_ROOT' ) . '/csv-http-server.log';
		$this->server = proc_open(
			array( PHP_BINARY, '-d', 'disable_functions=mail', '-d', 'output_buffering=1048576', '-S', '127.0.0.1:' . $this->port, '-t', ABSPATH, dirname( __DIR__ ) . '/csv-http-router.php' ),
			array( array( 'pipe', 'r' ), array( 'file', $log, 'a' ), array( 'file', $log, 'a' ) ), $pipes
		);
		$this->assertIsResource( $this->server );
		fclose( $pipes[0] );
		chmod( $log, 0600 );
		for ( $attempt = 0; $attempt < 100; $attempt++ ) {
			$probe = @stream_socket_client( 'tcp://127.0.0.1:' . $this->port, $error, $message, 0.1 );
			if ( $probe ) { fclose( $probe ); return; }
			if ( ! proc_get_status( $this->server )['running'] ) { break; }
			usleep( 20000 );
		}
		proc_terminate( $this->server ); proc_close( $this->server );
		$this->server = null;
		$this->fail( 'Owned loopback HTTP server failed to start.' );
	}

	public function tear_down(): void {
		try {
			if ( is_resource( $this->server ) ) { proc_terminate( $this->server ); proc_close( $this->server ); }
			foreach ( $this->cookies as $key => $previous ) {
				if ( null === $previous ) { unset( $_COOKIE[ $key ] ); } else { $_COOKIE[ $key ] = $previous; }
			}
		} finally { parent::tear_down(); }
	}

	private function login( string $role = 'administrator' ): string {
		$user = self::factory()->user->create( array( 'role' => $role ) );
		if ( 'administrator' === $role ) { get_user_by( 'id', $user )->add_cap( 'manage_woocommerce' ); }
		wp_set_current_user( $user );
		$auth = wp_generate_auth_cookie( $user, time() + 300, 'auth' );
		$logged_in = wp_generate_auth_cookie( $user, time() + 300, 'logged_in', wp_parse_auth_cookie( $auth, 'auth' )['token'] );
		if ( ! array_key_exists( LOGGED_IN_COOKIE, $this->cookies ) ) { $this->cookies[ LOGGED_IN_COOKIE ] = $_COOKIE[ LOGGED_IN_COOKIE ] ?? null; }
		$_COOKIE[ LOGGED_IN_COOKIE ] = $logged_in;
		return AUTH_COOKIE . '=' . $auth . '; ' . LOGGED_IN_COOKIE . '=' . $logged_in;
	}

	private function request( array $data, string $cookie, ?string $token = null ): array {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		$socket = stream_socket_client( 'tcp://127.0.0.1:' . $this->port, $error, $message, 5 );
		$this->assertIsResource( $socket );
		stream_set_timeout( $socket, 15 );
		$query = http_build_query( $data + array( 'page' => 'yoohw-customer-intelligence' ), '', '&', PHP_QUERY_RFC3986 );
		$token = $token ?? getenv( 'YCI_TEST_TOKEN' );
		fwrite( $socket, "GET /wp-admin/admin.php?" . $query . " HTTP/1.1\r\nHost: example.test\r\nCookie: " . $cookie . "\r\nX-YCI-Test-Token: " . $token . "\r\nConnection: close\r\n\r\n" );
		$response = stream_get_contents( $socket );
		$metadata = stream_get_meta_data( $socket );
		fclose( $socket );
		$this->assertFalse( $metadata['timed_out'] );
		$this->assertStringContainsString( "\r\n\r\n", $response );
		list( $header, $body ) = explode( "\r\n\r\n", $response, 2 );
		preg_match( '/^HTTP\/1\.[01] (\d+)/', $header, $status );
		if ( false !== strpos( $header, 'Content-Type: text/csv' ) && ! empty( $data['yoohw_cos_customers_export_nonce'] ) ) {
			$this->assertStringNotContainsString( $data['yoohw_cos_customers_export_nonce'], $body );
		}
		return array( 'status' => (int) $status[1], 'headers' => $header, 'body' => $body );
	}

	private function export_args(): array {
		return array( 'yoohw_cos_export_customers' => '1', 'yoohw_cos_customers_export_nonce' => wp_create_nonce( 'yoohw_cos_export_customers' ), 'orderby' => 'display_name', 'order' => 'ASC' );
	}

	private function decode( array $response ): array {
		preg_match( '/^Location: ([^\r\n]+)/mi', $response['headers'], $location );
		$this->assertSame( 200, $response['status'], 'Unexpected route: ' . parse_url( $location[1] ?? '', PHP_URL_PATH ) );
		$this->assertStringContainsString( 'Content-Type: text/csv; charset=utf-8', $response['headers'] );
		$this->assertStringContainsString( 'Content-Disposition: attachment; filename="yoohw-customers-', $response['headers'] );
		$this->assertSame( "\xEF\xBB\xBF", substr( $response['body'], 0, 3 ), 'CSV must start at byte zero, before any admin output.' );
		$this->assertStringNotContainsString( 'yci-admin-header-probe', $response['body'] );
		$this->assertStringNotContainsString( 'yci-admin-footer-probe', $response['body'] );
		$this->assertStringNotContainsString( '<!DOCTYPE', $response['body'] );
		$process = proc_open( array( 'python3', '-c', 'import csv,io,json,sys; print(json.dumps(list(csv.reader(io.StringIO(sys.stdin.buffer.read().decode("utf-8-sig"),newline=""),strict=True))))' ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $process );
		fwrite( $pipes[0], $response['body'] ); fclose( $pipes[0] );
		$rows = json_decode( stream_get_contents( $pipes[1] ), true );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] ); fclose( $pipes[2] );
		$this->assertSame( 0, proc_close( $process ), $error );
		$this->assertIsArray( $rows );
		foreach ( $rows as $row ) { $this->assertCount( 17, $row ); }
		$this->assertSame( 'Name', $rows[0][0] );
		$this->assertMatchesRegularExpression( '/^X-YoOhw-COS-Export-Limit: 5000\r?$/mi', $response['headers'] );
		$this->assertMatchesRegularExpression( '/^X-YoOhw-COS-Export-Matching-Customers: ' . ( count( $rows ) - 1 ) . '\r?$/mi', $response['headers'] );
		return $rows;
	}

	public function test_first_purchase_saved_view_reopens_and_exports_the_canonical_cohort(): void {
		YoOhw_COS_Customers::reset_data();
		foreach ( array( 0, 1, 2, 4 ) as $orders ) {
			$this->assertGreaterThan( 0, YoOhw_COS_Customers::create_customer( array(
				'display_name' => 'Purchase HTTP ' . $orders,
				'email' => 'purchase-http-' . $orders . '@example.test',
				'total_orders' => $orders,
			) ) );
		}
		$cookie = $this->login();
		$definition = array( 'customer_cohort' => 'first_time', 's' => 'Purchase HTTP', 'orderby' => 'total_orders', 'order' => 'ASC' );
		$plain = $this->decode( $this->request( array_replace( $this->export_args(), $definition ), $cookie ) );
		$this->assertCount( 2, $plain );
		$this->assertSame( 'Purchase HTTP 1', $plain[1][0] );
		$this->assertSame( 'ok', YoOhw_COS_Saved_Views::mutate( 'create', '', 'First purchase HTTP', $definition ) );
		$id = array_key_first( YoOhw_COS_Saved_Views::all() );
		$this->assertSame( 'first_time', YoOhw_COS_Saved_Views::get( $id )['definition']['customer_cohort'] );
		// Reopen by ID with conflicting inputs: the native redirect must restore the personal definition.
		$open = $this->request( array( 'saved_view_id' => $id, 'customer_cohort' => 'repeat', 's' => 'Purchase HTTP 0' ), $cookie );
		$this->assertSame( 302, $open['status'] );
		preg_match( '/^Location: ([^\r\n]+)/mi', $open['headers'], $location );
		parse_str( parse_url( $location[1], PHP_URL_QUERY ), $restored );
		$this->assertSame( 'first_time', $restored['customer_cohort'] );
		$this->assertSame( 'Purchase HTTP', $restored['s'] );
		$this->assertSame( '1', $restored['saved_view_context'] );
		foreach ( array( 1, 2 ) as $reload ) {
			$rows = $this->decode( $this->request( array_replace( $this->export_args(), $restored ), $cookie ) );
			$this->assertSame( $plain, $rows );
		}
		$repeat = $this->decode( $this->request( $this->export_args() + array( 'customer_cohort' => 'repeat', 's' => 'Purchase HTTP' ), $cookie ) );
		$names = array_column( array_slice( $repeat, 1 ), 0 ); sort( $names );
		$this->assertSame( array( 'Purchase HTTP 2', 'Purchase HTTP 4' ), $names );
	}

	public function test_native_admin_response_plain_saved_view_and_rejections(): void {
		YoOhw_COS_Customers::reset_data();
		YoOhw_COS_Customers::create_customer( array( 'display_name' => 'CSV HTTP selected', 'email' => 'csv-http@example.test' ) );
		YoOhw_COS_Customers::create_customer( array( 'display_name' => 'Other synthetic', 'email' => 'other@example.test' ) );
		$cookie = $this->login();
		$args = $this->export_args() + array( 's' => 'CSV HTTP' );
		$rows = $this->decode( $this->request( $args, $cookie ) );
		$this->assertCount( 2, $rows );
		$this->assertSame( 'CSV HTTP selected', $rows[1][0] );
		$this->assertSame( 'ok', YoOhw_COS_Saved_Views::mutate( 'create', '', 'HTTP view', array( 's' => 'CSV HTTP' ) ) );
		$id = array_key_first( YoOhw_COS_Saved_Views::all() );
		$view_args = $args + array( 'saved_view_id' => $id, 'saved_view_context' => '1' );
		$view_rows = $this->decode( $this->request( $view_args, $cookie ) );
		$this->assertCount( 2, $view_rows );
		$this->assertSame( 'CSV HTTP selected', $view_rows[1][0] );
		$open = $view_args; unset( $open['saved_view_context'] );
		$this->assertSame( 302, $this->request( $open, $cookie )['status'] );
		$empty = $args; $empty['s'] = 'no matching synthetic subject';
		$this->assertCount( 1, $this->decode( $this->request( $empty, $cookie ) ) );
		foreach ( array( '', 'invalid' ) as $nonce ) {
			$bad = $args; $bad['yoohw_cos_customers_export_nonce'] = $nonce;
			$this->assertSame( 500, $this->request( $bad, $cookie )['status'] );
		}
		$stale = $view_args; $stale['saved_view_id'] = wp_generate_uuid4();
		$this->assertSame( 500, $this->request( $stale, $cookie )['status'] );
		$migrations = get_option( 'yoohw_cos_data_migrations' );
		try {
			$pending = is_array( $migrations ) ? $migrations : array();
			$pending['commerce_currency_v3'] = array( 'status' => 'pending', 'last_progress_at' => time(), 'last_error' => '' );
			update_option( 'yoohw_cos_data_migrations', $pending, false );
			$rejected = $this->request( $args + array( 'rfm_monetary_min' => '1' ), $cookie );
			$this->assertSame( 500, $rejected['status'] );
			$this->assertStringContainsString( YoOhw_COS_Commerce_Metrics_Policy::reason_label( 'preparing_currency_data' ), $rejected['body'] );
			$this->assertStringNotContainsString( 'Content-Type: text/csv', $rejected['headers'] );
			$this->assertStringNotContainsString( 'X-YoOhw-COS-Export-', $rejected['headers'] );
			$this->assertStringNotContainsString( "\xEF\xBB\xBF", $rejected['body'] );
			$this->assertStringNotContainsString( 'CSV HTTP selected', $rejected['body'] );
		} finally { update_option( 'yoohw_cos_data_migrations', $migrations, false ); }
		foreach ( array( '', 'invalid-owner-token' ) as $token ) {
			$rejected = $this->request( $args, $cookie, $token );
			$this->assertSame( 403, $rejected['status'] );
			$this->assertSame( 'Owned HTTP environment rejected.', $rejected['body'] );
		}
		$subscriber = $this->login( 'subscriber' );
		$this->assertSame( 403, $this->request( $this->export_args(), $subscriber )['status'] );
		$cookie = $this->login();
		$args = $this->export_args();
		$this->assertSame( 500, $this->request( $args + array( 'saved_view_id' => $id, 'saved_view_context' => '1' ), $cookie )['status'] );
		$normal = $this->request( array(), $cookie );
		$this->assertSame( 200, $normal['status'] );
		$this->assertStringContainsString( 'yci-admin-header-probe', $normal['body'] );
		$this->assertStringContainsString( 'yci-admin-footer-probe', $normal['body'] );
		$this->assertStringContainsString( 'Export CSV', $normal['body'] );
		$other_page = $args; $other_page['page'] = 'yoohw-customer-intelligence-tasks';
		$this->assertStringContainsString( 'yci-admin-header-probe', $this->request( $other_page, $cookie )['body'] );
		$boundary = get_option( YoOhw_COS_Reset_Guard::OPTION );
		try {
			update_option( YoOhw_COS_Reset_Guard::OPTION, array( 'epoch' => wp_generate_uuid4(), 'status' => 'pending' ), false );
			$this->assertSame( 409, $this->request( $args, $cookie )['status'] );
		} finally { update_option( YoOhw_COS_Reset_Guard::OPTION, $boundary, false ); }
	}
}
