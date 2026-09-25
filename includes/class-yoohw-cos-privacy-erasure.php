<?php
defined( 'ABSPATH' ) || exit;

/** Exact-identity suppression and native, bounded personal-data erasure. */
final class YoOhw_COS_Privacy_Erasure {
	private const SECRET_OPTION = 'yoohw_cos_privacy_suppression_secret';
	private const PAGE_SIZE = 25;
	private const MAX_ALIAS_PROFILES = 1000;
	private const MAX_ORDER_LINKS = 1000;
	private const ORDER_LINK_OPTION_PREFIX = 'yoohw_cos_privacy_erasure_links_';
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
		if ( false !== self::is_suppressed( array() ) ) { return false; }
		$table = YoOhw_COS_DB::table( 'privacy_suppression' );
		$unique = array();
		foreach ( $identities as $identity ) {
			foreach ( self::identity_values( $identity ) as $kind => $value ) {
				$digest = hash_hmac( 'sha256', $kind . '|' . $value, hex2bin( $secret ) );
				if ( isset( $unique[ $kind . ':' . $digest ] ) ) { continue; }
				$unique[ $kind . ':' . $digest ] = true;
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
		if ( (int) $page > intdiv( PHP_INT_MAX, self::PAGE_SIZE ) ) { return self::result( false, false, false, true ); }
		if ( ! YoOhw_COS_Reset_Guard::enter() ) {
			return self::result( false, false, false, true );
		}
		try {
			$user = get_user_by( 'email', $email );
			$user_id = $user && strtolower( (string) $user->user_email ) === $email ? (int) $user->ID : 0;
			$table = YoOhw_COS_DB::customers_table();
			// All linked-user aliases must be receipted under this first-page lock.
			// A hard cap keeps the callback bounded; overflow fails closed without
			// deleting any subject record or losing aliases across Reset.
			$aliases = array();
			if ( $user_id ) {
				$aliases = $wpdb->get_results( $wpdb->prepare(
					'SELECT email FROM %i WHERE wp_user_id = %d ORDER BY id ASC LIMIT %d',
					$table, $user_id, self::MAX_ALIAS_PROFILES + 1
				), ARRAY_A );
				if ( '' !== $wpdb->last_error || ! is_array( $aliases ) ) {
					return self::result( false, false, false, true );
				}
				if ( count( $aliases ) > self::MAX_ALIAS_PROFILES ) {
					$result = self::result( false, false, false, true );
					$result['messages'][] = __( 'More linked Customer Intelligence profiles were found than can be safely prepared in one erasure page. Contact the site administrator; no Customer Intelligence data was deleted.', self::DOMAIN );
					return $result;
				}
			}
			$identities = array( array( 'email' => $email, 'wp_user_id' => $user_id ) );
			foreach ( $aliases as $alias ) {
				$identities[] = array( 'email' => (string) $alias['email'] );
			}
			if ( ! self::ensure_receipts( $identities ) ) {
				return self::result( false, false, false, true );
			}
			$snapshot_option = self::order_link_option_name( $email );
			$snapshot_status = null === $snapshot_option ? 'retry' : self::ensure_order_link_snapshot( $snapshot_option, $email, $user_id );
			if ( 'ok' !== $snapshot_status ) {
				$result = self::result( false, true, false, true );
				if ( 'limit' === $snapshot_status ) {
					$result['messages'][] = __( 'More Customer Intelligence order facts were found than can be safely prepared in one erasure page. Contact the site administrator; no Customer Intelligence data was deleted.', self::DOMAIN );
				}
				return $result;
			}
			$profile = $wpdb->get_row( $wpdb->prepare(
				'SELECT id, email FROM %i WHERE (email = %s AND BINARY email = BINARY %s)' . ( $user_id ? ' OR wp_user_id = %d' : '' ) . ' ORDER BY id ASC LIMIT 1',
				...array_merge( array( $table, $email, $email ), $user_id ? array( $user_id ) : array() )
			), ARRAY_A );
			if ( '' !== $wpdb->last_error ) {
				return self::result( false, false, false, true );
			}
			if ( $profile ) {
				$customer_id = (int) $profile['id'];
				foreach ( array( 'notes', 'tasks', 'events', 'customer_tags', 'customer_segments', 'order_facts' ) as $kind ) {
					if ( 'order_facts' === $kind ) {
						$links = self::drain_order_link_snapshot( $snapshot_option );
						if ( null !== $links ) { return self::result( $links['removed'], true, false, $links['retry'] ); }
					}
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
			$links = self::drain_order_link_snapshot( $snapshot_option );
			if ( null !== $links ) { return self::result( $links['removed'], true, false, $links['retry'] ); }
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

	private static function order_link_option_name( string $email ): ?string {
		$secret = get_option( self::SECRET_OPTION, null );
		if ( ! is_string( $secret ) || ! preg_match( '/^[a-f0-9]{64}$/D', $secret ) ) { return null; }
		return self::ORDER_LINK_OPTION_PREFIX . hash_hmac( 'sha256', 'order-links|' . $email, hex2bin( $secret ) );
	}

	private static function valid_order_link_snapshot( $snapshot ): bool {
		if ( ! is_array( $snapshot ) || 1 !== ( $snapshot['version'] ?? null ) || ! isset( $snapshot['links'] ) || ! is_array( $snapshot['links'] ) || count( $snapshot['links'] ) > self::MAX_ORDER_LINKS ) { return false; }
		foreach ( $snapshot['links'] as $order_id => $link ) {
			if ( ! is_int( $order_id ) || $order_id <= 0 || ! is_array( $link ) || ! isset( $link['customer_id'], $link['fingerprint'] )
				|| ! is_int( $link['customer_id'] ) || $link['customer_id'] <= 0 || ! is_string( $link['fingerprint'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $link['fingerprint'] ) ) { return false; }
		}
		return true;
	}

	/** Capture bounded, non-PII link references before the first subject deletion. */
	private static function ensure_order_link_snapshot( string $option, string $email, int $user_id ): string {
		global $wpdb;
		$existing = get_option( $option, null );
		if ( null !== $existing ) { return self::valid_order_link_snapshot( $existing ) ? 'ok' : 'retry'; }
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT f.order_id, f.customer_id FROM %i AS f INNER JOIN %i AS c ON c.id = f.customer_id WHERE (c.email = %s AND BINARY c.email = BINARY %s)' . ( $user_id ? ' OR c.wp_user_id = %d' : '' ) . ' ORDER BY f.id ASC LIMIT %d',
			...array_merge( array( YoOhw_COS_DB::order_facts_table(), YoOhw_COS_DB::customers_table(), $email, $email ), $user_id ? array( $user_id, self::MAX_ORDER_LINKS + 1 ) : array( self::MAX_ORDER_LINKS + 1 ) )
		), ARRAY_A );
		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) { return 'retry'; }
		if ( count( $rows ) > self::MAX_ORDER_LINKS ) { return 'limit'; }
		$links = array();
		foreach ( $rows as $row ) {
			$order_id = (int) $row['order_id'];
			$customer_id = (int) $row['customer_id'];
			if ( $order_id <= 0 || $customer_id <= 0 ) { return 'retry'; }
			$state = YoOhw_COS_Customers::order_link_state_for_erasure( $order_id );
			if ( null === $state ) { return 'retry'; }
			if ( $state['missing'] || ! $state['has_link'] ) { continue; }
			foreach ( $state['customer_ids'] as $linked_id ) {
				if ( $linked_id !== $customer_id ) { return 'retry'; }
			}
			if ( isset( $links[ $order_id ] ) && $links[ $order_id ]['customer_id'] !== $customer_id ) { return 'retry'; }
			$links[ $order_id ] = array( 'customer_id' => $customer_id, 'fingerprint' => $state['fingerprint'] );
		}
		if ( ! $links ) { return 'ok'; }
		$snapshot = array( 'version' => 1, 'links' => $links );
		if ( ! add_option( $option, $snapshot, '', false ) ) { return self::valid_order_link_snapshot( get_option( $option, null ) ) ? 'ok' : 'retry'; }
		return get_option( $option, null ) === $snapshot ? 'ok' : 'retry';
	}

	/** At most one page of snapshotted links is cleared per eraser invocation. */
	private static function drain_order_link_snapshot( string $option ): ?array {
		$snapshot = get_option( $option, null );
		if ( null === $snapshot ) { return null; }
		if ( ! self::valid_order_link_snapshot( $snapshot ) ) { return array( 'removed' => false, 'retry' => true ); }
		$removed = false;
		foreach ( array_slice( $snapshot['links'], 0, self::PAGE_SIZE, true ) as $order_id => $link ) {
			$state = YoOhw_COS_Customers::order_link_state_for_erasure( $order_id );
			if ( null === $state ) { return array( 'removed' => $removed, 'retry' => true ); }
			if ( ! $state['missing'] && $state['has_link'] && $state['fingerprint'] !== $link['fingerprint']
				&& ( ! $state['customer_ids'] || in_array( $link['customer_id'], $state['customer_ids'], true ) ) ) {
				return array( 'removed' => $removed, 'retry' => true );
			}
			if ( ! $state['missing'] && $state['has_link'] && $state['fingerprint'] === $link['fingerprint'] ) {
				if ( ! YoOhw_COS_Customers::unlink_order_for_erasure( $order_id, $link['customer_id'], $link['fingerprint'] ) ) {
					return array( 'removed' => $removed, 'retry' => true );
				}
				$removed = true;
			}
			unset( $snapshot['links'][ $order_id ] );
		}
		if ( $snapshot['links'] ) {
			update_option( $option, $snapshot, false );
			if ( get_option( $option, null ) !== $snapshot ) { return array( 'removed' => $removed, 'retry' => true ); }
		} else {
			delete_option( $option );
			if ( null !== get_option( $option, null ) ) { return array( 'removed' => $removed, 'retry' => true ); }
		}
		return array( 'removed' => $removed, 'retry' => false );
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
