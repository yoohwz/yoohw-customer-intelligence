<?php
defined( 'ABSPATH' ) || exit;

final class YoOhw_COS_DB {

	private static $work_locks = array();
	private static $work_lock_owners = array();

	/** Connection-owned identity/migration locks; no expiring option lease or cache authority. */
	public static function acquire_work_locks( array $keys ): string {
		global $wpdb;
		$names = array();
		foreach ( $keys as $key ) {
			if ( ! is_string( $key ) || ( 'migration' !== $key && 0 !== strpos( $key, 'identity|' ) ) ) { return ''; }
			$names[] = 'yci-work-' . substr( hash( 'sha256', DB_NAME . '|' . $wpdb->prefix . '|' . $key ), 0, 55 );
		}
		$names = array_values( array_unique( $names ) );
		sort( $names, SORT_STRING );
		if ( empty( $names ) ) { return ''; }
		$connection = (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
		if ( $connection <= 0 ) { return ''; }
		$handle = bin2hex( random_bytes( 16 ) );
		self::$work_locks[ $handle ] = array( 'connection' => $connection, 'names' => array() );
		try {
			foreach ( $names as $name ) {
				// Refuse same-connection reentrancy as well as another connection's ownership.
				if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $name ) )
					|| '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IF(CONNECTION_ID() = %d, GET_LOCK(%s, 0), 0)', $connection, $name ) ) ) {
					self::release_work_locks( $handle );
					return '';
				}
				self::$work_locks[ $handle ]['names'][] = $name;
				self::$work_lock_owners[ $name ] = $handle;
			}
		} catch ( Throwable $exception ) {
			self::release_work_locks( $handle );
			throw $exception;
		}
		return $handle;
	}

	public static function release_work_locks( string $handle ): void {
		global $wpdb;
		$owned = self::$work_locks[ $handle ] ?? null;
		if ( ! $owned ) { return; }
		unset( self::$work_locks[ $handle ] );
		foreach ( array_reverse( $owned['names'] ) as $name ) {
			if ( ( self::$work_lock_owners[ $name ] ?? '' ) !== $handle ) { continue; }
			unset( self::$work_lock_owners[ $name ] );
			// RELEASE_LOCK itself refuses foreign connections; the saved connection also fences reconnects.
			$wpdb->get_var( $wpdb->prepare( 'SELECT IF(CONNECTION_ID() = %d, RELEASE_LOCK(%s), 0)', $owned['connection'], $name ) );
		}
	}

	public static function table( string $name ): string {
		global $wpdb;

		$allowed_tables = array(
			'customers'         => 'yoohw_cos_customers',
			'events'            => 'yoohw_cos_events',
			'notes'             => 'yoohw_cos_notes',
			'tasks'             => 'yoohw_cos_tasks',
			'tags'              => 'yoohw_cos_tags',
			'customer_tags'     => 'yoohw_cos_customer_tags',
			'segments'          => 'yoohw_cos_segments',
			'customer_segments' => 'yoohw_cos_customer_segments',
			'order_facts'       => 'yoohw_cos_customer_order_facts',
			'notification_log'  => 'yoohw_cos_notification_log',
			'migration_issues'  => 'yoohw_cos_migration_issues',
		);

		if ( ! isset( $allowed_tables[ $name ] ) ) {
			return '';
		}

		return $wpdb->prefix . $allowed_tables[ $name ];
	}

	public static function customers_table(): string {
		return self::table( 'customers' );
	}

	public static function events_table(): string {
		return self::table( 'events' );
	}

	public static function notes_table(): string {
		return self::table( 'notes' );
	}

	public static function tasks_table(): string {
		return self::table( 'tasks' );
	}

	public static function tags_table(): string {
		return self::table( 'tags' );
	}

	public static function customer_tags_table(): string {
		return self::table( 'customer_tags' );
	}

	public static function segments_table(): string {
		return self::table( 'segments' );
	}

	public static function customer_segments_table(): string {
		return self::table( 'customer_segments' );
	}

	public static function order_facts_table(): string {
		return self::table( 'order_facts' );
	}

	public static function notification_log_table(): string {
		return self::table( 'notification_log' );
	}

	public static function migration_issues_table(): string {
		return self::table( 'migration_issues' );
	}

	public static function now(): string {
		return current_time( 'mysql' );
	}

	public static function date_timestamp( ?string $date ): int {
		$date = trim( (string) $date );

		if ( '' === $date || preg_match( '/^0{4}-0{2}-0{2}/', $date ) ) {
			return 0;
		}

		$timestamp = strtotime( $date );

		if ( ! $timestamp || (int) gmdate( 'Y', $timestamp ) < 1900 ) {
			return 0;
		}

		return (int) $timestamp;
	}

	public static function format_admin_date( ?string $date, string $empty = '&mdash;', bool $include_time = true ): string {
		$timestamp = self::date_timestamp( $date );

		if ( ! $timestamp ) {
			return $empty;
		}

		$format = get_option( 'date_format' );

		if ( $include_time ) {
			$format .= ' ' . get_option( 'time_format' );
		}

		return esc_html( date_i18n( $format, $timestamp ) );
	}

	public static function json_encode( array $data ): string {
		$json = wp_json_encode( $data );

		return $json ? $json : '{}';
	}

	public static function json_decode( ?string $json ): array {
		if ( empty( $json ) ) {
			return array();
		}

		$data = json_decode( $json, true );

		return is_array( $data ) ? $data : array();
	}
}
