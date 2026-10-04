<?php
/** Canonical config copied only into the runner's owned WordPress directory. */
require_once getenv( 'YCI_TEST_GUARD' );
yci_test_environment( 'cli-server' === PHP_SAPI );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );
define( 'WP_DEBUG', true );
$table_prefix = 'wptests_';
require_once ABSPATH . 'wp-settings.php';
