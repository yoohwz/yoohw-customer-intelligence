<?php
defined( 'ABSPATH' ) || exit;

/** Request-local interoperability contracts. No provider data is persisted. */
final class YoOhw_COS_Extensions {
	public const VERSION = 1;
	private static $query = array();
	private static $facts = array();
	private static $attention = array();
	private static $actions = array();

	public static function version(): int {
		return self::VERSION;
	}

	public static function valid_id( $id ): bool {
		return is_string( $id ) && strlen( $id ) <= 80
			&& (bool) preg_match( '/^[a-z][a-z0-9-]{0,39}\/[a-z][a-z0-9-]{0,38}$/D', $id )
			&& 'core/' !== substr( $id, 0, 5 );
	}

	private static function register( string $kind, $id, $callback ): bool {
		if ( ! self::valid_id( $id ) || ! is_callable( $callback ) || isset( self::${$kind}[ $id ] ) ) {
			return false;
		}
		self::${$kind}[ $id ] = $callback;
		ksort( self::${$kind}, SORT_STRING );
		return true;
	}

	public static function register_query( $id, $sanitizer, $predicate ): bool {
		if ( ! self::valid_id( $id ) || ! is_callable( $sanitizer ) || ! is_callable( $predicate ) || isset( self::$query[ $id ] ) ) {
			return false;
		}
		self::$query[ $id ] = array( $sanitizer, $predicate );
		ksort( self::$query, SORT_STRING );
		return true;
	}

	public static function register_facts( $id, $callback ): bool {
		return self::register( 'facts', $id, $callback );
	}

	public static function register_attention( $id, $callback ): bool {
		return self::register( 'attention', $id, $callback );
	}

	public static function register_action( $id, $callback ): bool {
		return self::register( 'actions', $id, $callback );
	}

	/** Invalid input remains an active fail-closed marker. */
	public static function sanitize_query( $input ): array {
		if ( ! is_array( $input ) || count( $input ) > 12 ) {
			return array( 'invalid' => true );
		}
		$result = array();
		foreach ( $input as $id => $raw ) {
			if ( ! self::valid_id( $id ) || ! isset( self::$query[ $id ] ) ) {
				return array( 'invalid' => true );
			}
			if ( ! is_scalar( $raw ) || is_bool( $raw ) || strlen( (string) $raw ) > 200 ) {
				return array( 'invalid' => true );
			}
			try {
				$value = call_user_func( self::$query[ $id ][0], $raw );
			} catch ( Throwable $error ) {
				return array( 'invalid' => true );
			}
			if ( ! is_scalar( $value ) || is_bool( $value ) || strlen( (string) $value ) > 200 || '' === (string) $value ) {
				return array( 'invalid' => true );
			}
			$result[ $id ] = (string) $value;
		}
		ksort( $result, SORT_STRING );
		return $result;
	}

	/** Providers return one field/operator/value tuple, never SQL. */
	public static function predicates( array $values ): ?array {
		$allowed = array( 'total_orders', 'first_order_date', 'last_order_date', 'last_activity_date', 'customer_status', 'lifecycle_stage', 'vip_status', 'risk_score', 'trust_score', 'total_spent', 'average_order_value' );
		$result = array();
		foreach ( $values as $id => $value ) {
			if ( ! isset( self::$query[ $id ] ) ) {
				return null;
			}
			try {
				$predicate = call_user_func( self::$query[ $id ][1], $value );
			} catch ( Throwable $error ) {
				return null;
			}
			if ( ! is_array( $predicate ) || array_keys( $predicate ) !== array( 'field', 'operator', 'value' )
				|| ! in_array( $predicate['field'], $allowed, true )
				|| ! in_array( $predicate['operator'], array( '=', '<', '<=', '>', '>=' ), true )
				|| ! is_scalar( $predicate['value'] ) || is_bool( $predicate['value'] )
				|| strlen( (string) $predicate['value'] ) > 200 ) {
				return null;
			}
			if ( in_array( $predicate['field'], array( 'total_orders', 'risk_score', 'trust_score', 'total_spent', 'average_order_value' ), true )
				&& ! preg_match( '/^-?(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?$/D', (string) $predicate['value'] ) ) {
				return null;
			}
			if ( in_array( $predicate['field'], array( 'first_order_date', 'last_order_date', 'last_activity_date' ), true )
				&& ! preg_match( '/^\d{4}-\d{2}-\d{2}(?: \d{2}:\d{2}:\d{2})?$/D', (string) $predicate['value'] ) ) {
				return null;
			}
			if ( in_array( $predicate['field'], array( 'customer_status', 'lifecycle_stage', 'vip_status' ), true )
				&& ! preg_match( '/^[a-z][a-z0-9_]{0,39}$/D', (string) $predicate['value'] ) ) {
				return null;
			}
			$result[] = $predicate;
		}
		return $result;
	}

	public static function fact_values( array $core ): array {
		$extra = array();
		foreach ( self::$facts as $id => $callback ) {
			try {
				$values = call_user_func( $callback, $core );
			} catch ( Throwable $error ) {
				continue;
			}
			if ( ! is_array( $values ) || count( $values ) > 20 ) {
				continue;
			}
			foreach ( $values as $key => $value ) {
				if ( self::valid_id( $key ) && 0 === strpos( $key, explode( '/', $id )[0] . '/' )
					&& ! array_key_exists( $key, $core ) && ! array_key_exists( $key, $extra ) && ( is_scalar( $value ) || null === $value )
					&& ( null === $value || strlen( (string) $value ) <= 200 ) ) {
					$extra[ $key ] = $value;
				}
			}
		}
		return $extra;
	}

	public static function attention_reasons( array $customer, array $context ): array {
		$result = array();
		if ( ! self::$attention ) {
			return $result;
		}
		$safe_context = self::attention_context( $context );
		$core = YoOhw_COS_Customer_Facts::core_snapshot( $customer, $safe_context );
		foreach ( self::$attention as $id => $callback ) {
			try {
				$reason = call_user_func( $callback, $core, $safe_context );
			} catch ( Throwable $error ) {
				continue;
			}
			if ( ! is_array( $reason ) || ( $reason['id'] ?? null ) !== $id || ! is_string( $reason['message'] ?? null ) || '' === trim( $reason['message'] ) || strlen( $reason['message'] ) > 240 ) {
				continue;
			}
			$severity = $reason['severity'] ?? 'info';
			if ( ! in_array( $severity, array( 'info', 'warning', 'urgent' ), true ) ) {
				continue;
			}
			$result[] = array( 'id' => $id, 'priority' => 100 + count( $result ), 'message' => sanitize_text_field( $reason['message'] ), 'severity' => $severity, 'action_url' => '' );
			if ( count( $result ) >= 10 ) {
				break;
			}
		}
		return $result;
	}

	public static function actions( array $customer ): array {
		$result = array();
		$customer_id = absint( $customer['id'] ?? 0 );
		if ( ! self::$actions || $customer_id < 1 ) {
			return $result;
		}
		$core = YoOhw_COS_Customer_Facts::core_snapshot( $customer );
		foreach ( self::$actions as $id => $callback ) {
			try {
				$action = call_user_func( $callback, $customer_id, $core );
			} catch ( Throwable $error ) {
				continue;
			}
			if ( ! is_array( $action ) || ( $action['id'] ?? null ) !== $id || ! is_string( $action['label'] ?? null ) || '' === trim( $action['label'] ) || strlen( $action['label'] ) > 80
				|| ! is_string( $action['capability'] ?? null ) || ! preg_match( '/^[a-z][a-z0-9_]{0,79}$/D', $action['capability'] ) || ! current_user_can( $action['capability'] )
				|| ! is_string( $action['url'] ?? null ) || strlen( $action['url'] ) > 2048 || ! self::is_safe_admin_url( $action['url'] ) ) {
				continue;
			}
			$result[] = array( 'id' => $id, 'label' => sanitize_text_field( $action['label'] ), 'url' => $action['url'] );
			if ( count( $result ) >= 10 ) {
				break;
			}
		}
		return $result;
	}

	private static function attention_context( array $context ): array {
		$safe = array();
		foreach ( array( 'open_tasks', 'overdue_tasks' ) as $key ) {
			if ( isset( $context[ $key ] ) && is_numeric( $context[ $key ] ) ) {
				$safe[ $key ] = max( 0, (int) $context[ $key ] );
			}
		}
		return $safe;
	}

	private static function is_safe_admin_url( string $url ): bool {
		$target = wp_parse_url( $url );
		$base = wp_parse_url( admin_url() );
		$path = is_array( $target ) ? rawurldecode( $target['path'] ?? '' ) : '';
		return is_array( $target ) && is_array( $base )
			&& in_array( $target['scheme'] ?? '', array( 'http', 'https' ), true )
			&& ( $target['scheme'] ?? '' ) === ( $base['scheme'] ?? '' )
			&& ( $target['host'] ?? '' ) === ( $base['host'] ?? '' )
			&& ( $target['port'] ?? null ) === ( $base['port'] ?? null )
			&& ! isset( $target['user'] ) && ! isset( $target['pass'] )
			&& 0 === strpos( $path, $base['path'] ?? '/wp-admin/' )
			&& ! in_array( '..', explode( '/', $path ), true )
			&& ! in_array( '.', explode( '/', $path ), true )
			&& false === strpos( $url, '\\' );
	}
}
