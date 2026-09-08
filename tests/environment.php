<?php
/** Fail closed before loading any WordPress, plugin, Composer or supplied PHP config. */
function yci_test_environment(): array {
	$fail = static function ( string $reason ): void {
		throw new RuntimeException( 'YCI unsafe test environment: ' . $reason );
	};
	$root = getenv( 'YCI_TEST_ROOT' );
	if ( 'cli' !== PHP_SAPI || ! $root || realpath( $root ) !== $root || is_link( $root )
		|| ! preg_match( '~/yci-test-[a-zA-Z0-9_-]+$~D', $root )
		|| ( fileperms( $root ) & 0777 ) !== 0700 || fileowner( $root ) !== posix_geteuid() ) {
		$fail( 'missing owned private root' );
	}
	$config = $root . '/environment.json';
	if ( ! is_file( $config ) || is_link( $config ) || ( fileperms( $config ) & 0777 ) !== 0600 ) {
		$fail( 'missing private credentials' );
	}
	$env = json_decode( file_get_contents( $config ), true );
	if ( ! is_array( $env ) || ! isset( $env['database'], $env['password'], $env['token'] )
		|| ! preg_match( '/^yci[a-f0-9]{24}$/D', $env['database'] )
		|| ! preg_match( '/^[a-f0-9]{64}$/D', $env['password'] )
		|| ! preg_match( '/^[a-f0-9]{64}$/D', $env['token'] )
		|| ! hash_equals( $env['token'], (string) getenv( 'YCI_TEST_TOKEN' ) ) ) {
		$fail( 'invalid ownership credentials' );
	}
	foreach ( array( 'WP_TESTS_DIR' => $root . '/tests', 'WC_PLUGIN_FILE' => $root . '/woocommerce/woocommerce.php', 'YCI_TEST_GUARD' => __FILE__ ) as $key => $expected ) {
		if ( getenv( $key ) !== $expected || realpath( $expected ) !== $expected ) {
			$fail( 'path mismatch: ' . $key );
		}
	}
	if ( ! in_array( getenv( 'WC_HPOS_ENABLED' ), array( 'yes', 'no' ), true )
		|| ! is_file( $root . '/wordpress/wp-settings.php' )
		|| file_get_contents( $root . '/wp-tests-config.php' ) !== file_get_contents( __DIR__ . '/wp-tests-config.php' ) ) {
		$fail( 'storage mode or canonical WordPress config mismatch' );
	}
	$constants = array(
		'DB_NAME' => $env['database'], 'DB_USER' => $env['database'], 'DB_PASSWORD' => $env['password'],
		'DB_HOST' => 'localhost:' . $root . '/mysql.sock', 'ABSPATH' => $root . '/wordpress/',
		'WP_TESTS_CONFIG_FILE_PATH' => $root . '/wp-tests-config.php',
		'WP_TESTS_PHPUNIT_POLYFILLS_PATH' => dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills',
	);
	foreach ( $constants as $key => $value ) {
		if ( defined( $key ) && constant( $key ) !== $value ) {
			$fail( 'predefined constant mismatch: ' . $key );
		}
	}
	mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );
	$db = new mysqli( 'localhost', $env['database'], $env['password'], $env['database'], 0, $root . '/mysql.sock' );
	$grants = $db->query( 'SHOW GRANTS FOR CURRENT_USER' )->fetch_all( MYSQLI_NUM );
	$scoped = false;
	foreach ( $grants as $row ) {
		$grant = $row[0];
		if ( preg_match( '/^GRANT USAGE ON \*\.\* TO /', $grant ) ) {
			continue;
		}
		if ( strpos( $grant, 'GRANT ALL PRIVILEGES ON `' . $env['database'] . '`.* TO ' ) !== 0 || strpos( $grant, 'WITH GRANT OPTION' ) !== false ) {
			$fail( 'database privileges exceed owned database' );
		}
		$scoped = true;
	}
	$token = $db->query( 'SELECT token FROM yci_environment_owner' )->fetch_row();
	if ( ! $scoped || ! $token || ! hash_equals( $env['token'], $token[0] ) ) {
		$fail( 'database ownership mismatch' );
	}
	$db->close();
	foreach ( $constants as $key => $value ) {
		if ( ! defined( $key ) ) {
			define( $key, $value );
		}
	}
	return $env;
}

// A pluggable replacement exists before any fixture/plugin hook. No recipient/body is persisted.
if ( ! function_exists( 'wp_mail' ) ) {
	function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
		$GLOBALS['yci_intercepted_mail'] = ( $GLOBALS['yci_intercepted_mail'] ?? 0 ) + 1;
		return true;
	}
} else {
	throw new RuntimeException( 'YCI unsafe test environment: mail already initialized' );
}
