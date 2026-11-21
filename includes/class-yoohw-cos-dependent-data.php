<?php
defined( 'ABSPATH' ) || exit;

/** Keeps dependent rows erasable even while the owning plugin is inactive. */
final class YoOhw_COS_Dependent_Data {
	private const OPTION = 'yoohw_cos_dependent_customer_tables';
	private const LIMIT = 16;
	private const DELETE_LIMIT = 250;

	/**
	 * Register suffixes of per-site add-on tables with an indexed customer_id.
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
		foreach ( array_diff( $combined, $registered ) as $suffix ) {
			if ( ! self::eligible_table( $suffix ) ) {
				return false;
			}
		}
		if ( $combined === $registered ) {
			return true;
		}
		update_option( self::OPTION, $combined, false );
		return $combined === self::registered();
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

	private static function eligible_table( string $suffix ): bool {
		global $wpdb;
		$table = $wpdb->prefix . $suffix;
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( '' !== $wpdb->last_error || $found !== $table ) {
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
		$tables = array();
		foreach ( self::registered() as $suffix ) {
			$table = $wpdb->prefix . $suffix;
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( '' !== $wpdb->last_error ) {
				throw new RuntimeException( 'Dependent customer tables could not be read.' );
			}
			if ( $found === $table ) {
				$tables[] = $table;
			}
		}
		return $tables;
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
