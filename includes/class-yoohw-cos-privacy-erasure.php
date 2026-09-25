<?php
defined( 'ABSPATH' ) || exit;

/** Exact-identity suppression and native, bounded personal-data erasure. */
final class YoOhw_COS_Privacy_Erasure {
	private const SECRET_OPTION = 'yoohw_cos_privacy_suppression_secret';
	private const PAGE_SIZE = 25;
	private const DOMAIN = 'yoohw-customer-intelligence';

	public static function init(): void {
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register' ) );
	}

	public static function register( array $erasers ): array {
		$erasers['yoohw-customer-intelligence'] = array(
			'eraser_friendly_name' => __( 'Customer Intelligence', self::DOMAIN ),
			'callback' => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/** Null means suppression could not be evaluated and the writer must fail closed. */
	public static function is_suppressed( array $identity ): ?bool {
		global $wpdb;
		$table = YoOhw_COS_DB::table( 'privacy_suppression' );
		$secret = get_option( self::SECRET_OPTION, null );
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( '' !== $wpdb->last_error || $exists !== $table ) {
			return null;
		}
		if ( null === $secret ) {
			$row = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i LIMIT 1', $table ) );
			return '' === $wpdb->last_error && null === $row ? false : null;
		}
		if ( ! is_string( $secret ) || ! preg_match( '/^[a-f0-9]{64}$/D', $secret ) ) {
			return null;
		}
		$unknown = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE hash_version <> 1 LIMIT 1', $table ) );
		if ( '' !== $wpdb->last_error || null !== $unknown ) { return null; }
		foreach ( self::identity_values( $identity ) as $kind => $value ) {
			$digest = hash_hmac( 'sha256', $kind . '|' . $value, hex2bin( $secret ) );
			$found = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE identity_kind = %s AND identity_digest = %s AND hash_version = 1 LIMIT 1', $table, $kind, $digest ) );
			if ( '' !== $wpdb->last_error ) {
				return null;
			}
			if ( null !== $found ) {
				return true;
			}
		}
		return false;
	}

	private static function identity_values( array $identity ): array {
		$values = array();
		$email = YoOhw_COS_Customer_Identity::normalize_email( (string) ( $identity['email'] ?? '' ) );
		if ( '' !== $email && is_email( $email ) && strlen( $email ) <= 191 ) {
			$values['email'] = $email;
		}
		$user_id = absint( $identity['wp_user_id'] ?? 0 );
		if ( $user_id > 0 ) {
			$values['user'] = (string) $user_id;
		}
		return $values;
	}

	private static function ensure_receipts( array $identities ): bool {
		global $wpdb;
		$secret = get_option( self::SECRET_OPTION, null );
		if ( null === $secret ) {
			// A missing key with existing rows cannot be replaced without losing lookup authority.
			if ( null !== self::is_suppressed( array() ) ) {
				$secret = bin2hex( random_bytes( 32 ) );
				if ( ! add_option( self::SECRET_OPTION, $secret, '', false ) ) {
					$secret = get_option( self::SECRET_OPTION, null );
				}
			}
		}
		if ( ! is_string( $secret ) || ! preg_match( '/^[a-f0-9]{64}$/D', $secret ) ) {
			return false;
		}
		$table = YoOhw_COS_DB::table( 'privacy_suppression' );
		foreach ( $identities as $identity ) {
			foreach ( self::identity_values( $identity ) as $kind => $value ) {
				$digest = hash_hmac( 'sha256', $kind . '|' . $value, hex2bin( $secret ) );
				$result = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (identity_kind, identity_digest, hash_version, created_at) VALUES (%s, %s, 1, %s)', $table, $kind, $digest, YoOhw_COS_DB::now() ) );
				if ( false === $result ) {
					return false;
				}
			}
		}
		return true;
	}

	private static function result( bool $removed, bool $retained, bool $done, bool $retry = false ): array {
		$messages = array();
		if ( $retained ) {
			$messages[] = __( 'Customer Intelligence retains a non-raw, one-way suppression receipt solely to prevent automatic recreation from source data.', self::DOMAIN );
		}
		$messages[] = __( 'This plugin does not delete WooCommerce orders or WordPress user data. Those source systems have their own privacy and retention responsibilities.', self::DOMAIN );
		if ( $retry ) {
			$messages[] = __( 'Customer Intelligence erasure is temporarily unavailable. Retry after Reset or database recovery.', self::DOMAIN );
		}
		return array( 'items_removed' => $removed, 'items_retained' => $retained, 'messages' => $messages, 'done' => $done );
	}

	/** A page always re-resolves identity under the same lock as all CRM writers. */
	public static function erase( $email_address, $page = 1 ): array {
		global $wpdb;
		if ( ! is_string( $email_address ) || ! is_numeric( $page ) || (int) $page < 1 || (string) (int) $page !== (string) $page ) {
			return self::result( false, false, true );
		}
		$email = strtolower( trim( $email_address ) );
		if ( ! is_email( $email ) || sanitize_email( $email ) !== $email || strlen( $email ) > 191 ) {
			return self::result( false, false, true );
		}
		if ( ! YoOhw_COS_Reset_Guard::enter() ) {
			return self::result( false, false, false, true );
		}
		try {
			$user = get_user_by( 'email', $email );
			$user_id = $user && strtolower( (string) $user->user_email ) === $email ? (int) $user->ID : 0;
			$table = YoOhw_COS_DB::customers_table();
			$profile = $wpdb->get_row( $wpdb->prepare(
				'SELECT id, email FROM %i WHERE (email = %s AND BINARY email = BINARY %s)' . ( $user_id ? ' OR wp_user_id = %d' : '' ) . ' ORDER BY id ASC LIMIT 1',
				...array_merge( array( $table, $email, $email ), $user_id ? array( $user_id ) : array() )
			), ARRAY_A );
			if ( '' !== $wpdb->last_error ) {
				return self::result( false, false, false, true );
			}
			$identities = array( array( 'email' => $email, 'wp_user_id' => $user_id ) );
			if ( $profile ) {
				$identities[] = array( 'email' => (string) $profile['email'] );
			}
			if ( ! self::ensure_receipts( $identities ) ) {
				return self::result( false, false, false, true );
			}
			if ( $profile ) {
				$customer_id = (int) $profile['id'];
				foreach ( array( 'notes', 'tasks', 'events', 'customer_tags', 'customer_segments', 'order_facts' ) as $kind ) {
					$rows = self::first_rows( $kind, $customer_id );
					if ( null === $rows ) {
						return self::result( false, true, false, true );
					}
					if ( $rows ) {
						$outcome = self::delete_rows( $kind, $customer_id, $rows );
						return self::result( $outcome['removed'], true, false, $outcome['retry'] );
					}
				}
				if ( $user_id ) {
					$unlinked = self::first_unlinked_events( $user_id );
					if ( null === $unlinked ) { return self::result( false, true, false, true ); }
					if ( $unlinked ) {
						$removed = self::delete_unlinked_events( $user_id, $unlinked );
						return self::result( $removed > 0, true, false, count( $unlinked ) !== $removed );
					}
				}
				// The profile is deleted only after all dependent tables are empty.
				if ( $user_id && ! self::delete_views( $user_id ) ) {
					return self::result( false, true, false, true );
				}
				$deleted = $wpdb->delete( $table, array( 'id' => $customer_id ), array( '%d' ) );
				return self::result( 1 === $deleted, true, false, 1 !== $deleted );
			}
			if ( $user_id ) {
				$unlinked = self::first_unlinked_events( $user_id );
				if ( null === $unlinked ) { return self::result( false, true, false, true ); }
				if ( $unlinked ) {
					$removed = self::delete_unlinked_events( $user_id, $unlinked );
					return self::result( $removed > 0, true, false, count( $unlinked ) !== $removed );
				}
			}
			$had_views = $user_id && metadata_exists( 'user', $user_id, '_yoohw_cos_saved_customer_views' );
			if ( $user_id && ! self::delete_views( $user_id ) ) {
				return self::result( false, true, false, true );
			}
			return self::result( $had_views, true, true );
		} catch ( Throwable $exception ) {
			return self::result( false, false, false, true );
		} finally {
			YoOhw_COS_Reset_Guard::leave();
		}
	}

	private static function first_rows( string $kind, int $customer_id ): ?array {
		global $wpdb;
		$table = YoOhw_COS_DB::table( $kind );
		$projection = 'order_facts' === $kind ? 'id, order_id' : 'id';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT {$projection} FROM %i WHERE customer_id = %d ORDER BY id ASC LIMIT %d", $table, $customer_id, self::PAGE_SIZE ), ARRAY_A );
		return '' === $wpdb->last_error && is_array( $rows ) ? $rows : null;
	}

	private static function delete_rows( string $kind, int $customer_id, array $rows ): array {
		global $wpdb;
		$ids = array_map( 'intval', array_column( $rows, 'id' ) );
		if ( 'order_facts' === $kind ) {
			$removed = 0;
			foreach ( $rows as $row ) {
				if ( ! YoOhw_COS_Customers::unlink_order_for_erasure( (int) $row['order_id'], $customer_id ) ) {
					return array( 'removed' => $removed > 0, 'retry' => true );
				}
				$deleted = $wpdb->delete( YoOhw_COS_DB::order_facts_table(), array( 'id' => (int) $row['id'], 'customer_id' => $customer_id ), array( '%d', '%d' ) );
				if ( 1 !== $deleted ) {
					return array( 'removed' => $removed > 0, 'retry' => true );
				}
				$removed++;
			}
			return array( 'removed' => $removed > 0, 'retry' => false );
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$notification_count = 0;
		if ( 'tasks' === $kind ) {
			$notification_count = $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE task_id IN ({$placeholders})", ...array_merge( array( YoOhw_COS_DB::notification_log_table() ), $ids ) ) );
			if ( false === $notification_count ) {
				return array( 'removed' => false, 'retry' => true );
			}
		}
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE customer_id = %d AND id IN ({$placeholders})", ...array_merge( array( YoOhw_COS_DB::table( $kind ), $customer_id ), $ids ) ) );
		return array( 'removed' => (int) $deleted > 0 || $notification_count > 0, 'retry' => count( $ids ) !== $deleted );
	}

	/** These integration event sources store wp_user_id as the source customer. */
	private static function first_unlinked_events( int $user_id ): ?array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id FROM %i WHERE customer_id IS NULL AND wp_user_id = %d AND event_source IN ('wc_blacklist_manager', 'wc_blacklist_manager_premium', 'wc_loyalty') ORDER BY id ASC LIMIT %d",
			YoOhw_COS_DB::events_table(), $user_id, self::PAGE_SIZE
		), ARRAY_A );
		return '' === $wpdb->last_error && is_array( $rows ) ? $rows : null;
	}

	private static function delete_unlinked_events( int $user_id, array $rows ): int {
		global $wpdb;
		$ids = array_map( 'intval', array_column( $rows, 'id' ) );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$deleted = $wpdb->query( $wpdb->prepare(
			"DELETE FROM %i WHERE customer_id IS NULL AND wp_user_id = %d AND event_source IN ('wc_blacklist_manager', 'wc_blacklist_manager_premium', 'wc_loyalty') AND id IN ({$placeholders})",
			...array_merge( array( YoOhw_COS_DB::events_table(), $user_id ), $ids )
		) );
		return false === $deleted ? 0 : (int) $deleted;
	}

	private static function delete_views( int $user_id ): bool {
		$key = '_yoohw_cos_saved_customer_views';
		if ( ! metadata_exists( 'user', $user_id, $key ) ) {
			return true;
		}
		delete_user_meta( $user_id, $key );
		return ! metadata_exists( 'user', $user_id, $key );
	}
}
