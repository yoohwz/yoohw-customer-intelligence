<?php
/** Synthetic external add-on fixture; deliberately separate from Free implementation. */
final class YCI_Example_Extension_Provider {
	public static $observed = array();

	public static function register(): void {
		YoOhw_COS_Extensions::register_query(
			'example-provider/min-orders',
			static function ( $raw ) {
				if ( ! is_string( $raw ) || ! preg_match( '/^[1-9][0-9]{0,3}$/D', $raw ) ) {
					throw new InvalidArgumentException( 'Invalid threshold' );
				}
				return $raw;
			},
			static function ( $value ): array {
				return array( 'field' => 'total_orders', 'operator' => '>=', 'value' => $value );
			}
		);
		YoOhw_COS_Extensions::register_facts( 'example-provider/flags', static function ( array $core ): array {
			self::$observed['facts'] = $core;
			return array( 'example-provider/repeat' => (int) ( $core['core/recognized_order_count'] ?? 0 ) > 1, 'core/status' => 'override' );
		} );
		YoOhw_COS_Extensions::register_attention( 'example-provider/check', static function ( array $core, array $context ): array {
			self::$observed['attention'] = array( 'facts' => $core, 'context' => $context );
			return array( 'id' => 'example-provider/check', 'message' => 'Synthetic provider reason', 'severity' => 'info' );
		} );
		YoOhw_COS_Extensions::register_action( 'example-provider/open', static function ( int $customer_id, array $core ): array {
			self::$observed['action'] = array( 'customer_id' => $customer_id, 'facts' => $core );
			return array( 'id' => 'example-provider/open', 'label' => 'Synthetic action', 'url' => admin_url( 'admin.php?page=example-provider&customer_id=' . $customer_id ), 'capability' => 'manage_options' );
		} );
	}
}
