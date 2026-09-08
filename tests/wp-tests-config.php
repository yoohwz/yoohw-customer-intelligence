<?php
require_once getenv( 'YCI_TEST_GUARD' );
yci_test_environment();
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );
define( 'WP_DEBUG', true );
define( 'WP_TESTS_DOMAIN', 'example.test' );
define( 'WP_TESTS_EMAIL', 'admin@example.test' );
define( 'WP_TESTS_TITLE', 'YCI disposable tests' );
define( 'WP_PHP_BINARY', escapeshellarg( PHP_BINARY ) );
define( 'WP_TESTS_FORCE_KNOWN_BUGS', false );
$table_prefix = 'wptests_';
