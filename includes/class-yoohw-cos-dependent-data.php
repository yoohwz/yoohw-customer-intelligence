<?php
defined( 'ABSPATH' ) || exit;

/** Keeps dependent rows erasable even while the owning plugin is inactive. */
final class YoOhw_COS_Dependent_Data {
	private const OPTION = 'yoohw_cos_dependent_customer_tables';
	private const LIMIT = 16;
	private const DELETE_LIMIT = 250;

	/**
	 * Register site-relative suffixes of InnoDB tables with an indexed unsigned BIGINT customer_id.
	 * Call under the Reset guard after installation. Tables must contain only
	 * customer-bound operational data. Foreign keys in either direction are
	 * unsupported, including relationships between dependent tables.
	 */
	public static function register( array $suffixes ): bool {
		if ( ! self::valid( $suffixes ) ) {
			return false;
		}
		$registered = self::registered();
		$combined = array_values( array_unique( array_merge( $registered, $suffixes ), SORT_REGULAR ) );
		if ( ! self::valid( $combined ) ) {
			return false;
		}
		global $wpdb;
		if ( $combined && ! self::is_transactional( YoOhw_COS_DB::customers_table() ) ) {
			return false;
		}
		foreach ( $suffixes as $suffix ) {
			if ( ! self::eligible_table( $wpdb->prefix . $suffix ) ) {
				return false;
			}
		}
		if ( $combined === $registered ) {
			return true;
		}
		$serialized = maybe_serialize( $combined );
		$stored = $wpdb->query( $wpdb->prepare(
			"INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'off')
			ON DUPLICATE KEY UPDATE option_value = %s, autoload = 'off'",
			$wpdb->options, self::OPTION, $serialized, $serialized
		) );
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return false !== $stored && $combined === self::registered();
	}

	private static function valid( array $suffixes ): bool {
		if ( count( $suffixes ) > self::LIMIT ) {
			return false;
		}
		foreach ( $suffixes as $suffix ) {
			if ( ! is_string( $suffix ) || ! preg_match( '/^yoohw_(?!cos_)[a-z0-9]+_[a-z0-9_]+$/D', $suffix ) || strlen( $suffix ) > 40 ) {
				return false;
			}
		}
		return true;
	}

	private static function registered(): array {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s FOR UPDATE', $wpdb->options, self::OPTION ) );
		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'Dependent customer data registration could not be read.' );
		}
		$registered = null === $value ? array() : maybe_unserialize( $value );
		if ( ! is_array( $registered ) || ! self::valid( $registered ) ) {
			throw new RuntimeException( 'Dependent customer data registration is invalid.' );
		}
		return $registered;
	}

	private static function is_transactional( string $table ): bool {
		global $wpdb;
		$engine = $wpdb->get_var( $wpdb->prepare(
			'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table
		) );
		return '' === $wpdb->last_error && 'InnoDB' === $engine;
	}

	private static function eligible_table( string $table ): bool {
		global $wpdb;
		if ( ! self::is_transactional( $table ) ) {
			return false;
		}
		$column = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM %i WHERE Field = 'customer_id'", $table ), ARRAY_A );
		if ( '' !== $wpdb->last_error || ! $column || 'NO' !== $column['Null'] ||
			! preg_match( '/^bigint(?:\([0-9]+\))? unsigned$/Di', $column['Type'] ) ) {
			return false;
		}
		$index = $wpdb->get_row( $wpdb->prepare( "SHOW INDEX FROM %i WHERE Column_name = 'customer_id' AND Seq_in_index = 1", $table ) );
		if ( '' !== $wpdb->last_error || ! $index ) {
			return false;
		}
		$foreign_key = $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
			WHERE REFERENCED_TABLE_NAME IS NOT NULL
				AND ((TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s)
					OR (REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = %s))
			LIMIT 1', $table, $table
		) );
		return '' === $wpdb->last_error && null === $foreign_key;
	}

	private static function tables(): array {
		global $wpdb;
		$registered = self::registered();
		if ( $registered && ! self::is_transactional( YoOhw_COS_DB::customers_table() ) ) {
			throw new RuntimeException( 'Dependent data requires an InnoDB customer profile table.' );
		}
		$tables = array();
		foreach ( $registered as $suffix ) {
			$table = $wpdb->prefix . $suffix;
			if ( self::eligible_table( $table ) ) {
				$tables[] = $table;
			} elseif ( ! self::table_was_removed( $table ) ) {
				throw new RuntimeException( 'Dependent customer table schema is incompatible.' );
			}
		}
		return $tables;
	}

	/** An absent metadata row can also mean restricted grants. Check the driver result. */
	private static function table_was_removed( string $table ): bool {
		global $wpdb;
		$suppressed = $wpdb->suppress_errors( true );
		try {
			$wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 1', $table ) );
			$connection = $wpdb->dbh;
			return $connection instanceof mysqli && 1146 === mysqli_errno( $connection ); // ER_NO_SUCH_TABLE.
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}
	}

	/** A nonzero result keeps the profile available for the next erasure page. */
	public static function erase( int $customer_id ): int {
		global $wpdb;
		foreach ( self::tables() as $table ) {
			$deleted = $wpdb->query( $wpdb->prepare(
				'DELETE FROM %i WHERE customer_id = %d LIMIT %d', $table, $customer_id, self::DELETE_LIMIT
			) );
			if ( false === $deleted ) {
				throw new RuntimeException( 'Dependent customer rows could not be erased.' );
			}
			if ( $deleted ) {
				return $deleted;
			}
		}
		return 0;
	}

	public static function reset(): void {
		global $wpdb;
		foreach ( self::tables() as $table ) {
			if ( false === $wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $table ) ) ||
				'0' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ) ) {
				throw new RuntimeException( 'Dependent customer rows could not be reset.' );
			}
		}
	}
}
