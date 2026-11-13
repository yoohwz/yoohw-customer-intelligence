<?php
require_once dirname( __DIR__ ) . '/environment.php';
yci_test_environment();

/** Deliberately narrow companion stand-in; native AAP behavior is tested in AAP. */
final class WCAAP_Customer_Summary {
	public const VERSION = 2;
	public static $response;
	public static $calls = array();
	public static function read( $user_id ) {
		self::$calls[] = $user_id;
		if ( self::$response instanceof Throwable ) { throw self::$response; }
		return self::$response;
	}
}
