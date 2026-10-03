<?php
require_once dirname( __DIR__, 3 ) . '/tests/environment.php';
$owned = yci_test_environment();
$db = new mysqli( 'localhost', $owned['database'], $owned['password'], $owned['database'], 0, getenv( 'YCI_TEST_ROOT' ) . '/mysql.sock' );
foreach ( $db->query( 'SHOW TABLES' )->fetch_all( MYSQLI_NUM ) as $table ) {
    if ( preg_match( '/^wptests_[a-zA-Z0-9_]+$/D', $table[0] ) ) { $db->query( 'DROP TABLE `' . $table[0] . '`' ); }
}
$db->close();
