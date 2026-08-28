<?php
defined( 'ABSPATH' ) || exit;

/** Atomic, bounded notification dedupe ledger. */
final class YoOhw_COS_Notification_Ledger {

	public const RETENTION_DAYS = 45;
	private const LEASE_MINUTES = 15;
	private static $claim_tokens = array();

	public static function key( string $event, array $task = array(), int $recipient_user_id = 0 ): string {
		$basis = sanitize_key( $event )
			. '|' . absint( $task['id'] ?? 0 )
			. '|' . absint( $recipient_user_id )
			. '|' . (string) ( $task['due_date'] ?? '' )
			. '|' . (string) ( $task['updated_at'] ?? '' );

		return hash( 'sha256', $basis );
	}

	public static function claim( string $key, string $type, int $task_id = 0, int $recipient_user_id = 0 ): bool {
		global $wpdb;

		$now_datetime = current_datetime();
		$key          = sanitize_text_field( $key );
		$now          = $now_datetime->format( 'Y-m-d H:i:s' );
		$lease_until  = $now_datetime->modify( '+' . self::LEASE_MINUTES . ' minutes' )->format( 'Y-m-d H:i:s' );
		$expires_at   = $now_datetime->modify( '+' . self::RETENTION_DAYS . ' days' )->format( 'Y-m-d H:i:s' );
		$token        = str_replace( '-', '', wp_generate_uuid4() );
		$previous_error_suppression = $wpdb->suppress_errors();
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO %i
					(notification_key, notification_type, task_id, recipient_user_id, status, claim_token, lease_until, attempts, created_at, updated_at, expires_at)
				VALUES (%s, %s, %d, %d, 'pending', %s, %s, 1, %s, %s, %s)
				ON DUPLICATE KEY UPDATE
					claim_token = IF(status = 'pending' AND (lease_until IS NULL OR lease_until < VALUES(updated_at)), VALUES(claim_token), claim_token),
					attempts = IF(status = 'pending' AND (lease_until IS NULL OR lease_until < VALUES(updated_at)), attempts + 1, attempts),
					updated_at = IF(status = 'pending' AND (lease_until IS NULL OR lease_until < VALUES(updated_at)), VALUES(updated_at), updated_at),
					expires_at = IF(status = 'pending' AND (lease_until IS NULL OR lease_until < VALUES(updated_at)), VALUES(expires_at), expires_at),
					lease_until = IF(status = 'pending' AND (lease_until IS NULL OR lease_until < VALUES(updated_at)), VALUES(lease_until), lease_until)",
				YoOhw_COS_DB::notification_log_table(),
				$key,
				sanitize_key( $type ),
				absint( $task_id ),
				absint( $recipient_user_id ),
				$token,
				$lease_until,
				$now,
				$now,
				$expires_at
			)
		);
		$wpdb->suppress_errors( (bool) $previous_error_suppression );

		if ( ! in_array( $inserted, array( 1, 2 ), true ) ) {
			return false;
		}

		self::$claim_tokens[ $key ] = $token;

		return true;
	}

	public static function mark_sent( string $key ): void {
		global $wpdb;

		$key   = sanitize_text_field( $key );
		$token = (string) ( self::$claim_tokens[ $key ] ?? '' );

		if ( '' === $token ) {
			return;
		}

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i
				SET status = 'sent', sent_at = %s, updated_at = %s, lease_until = NULL
				WHERE notification_key = %s AND status = 'pending' AND claim_token = %s",
				YoOhw_COS_DB::notification_log_table(),
				YoOhw_COS_DB::now(),
				YoOhw_COS_DB::now(),
				$key,
				$token
			)
		);
		unset( self::$claim_tokens[ $key ] );
	}

	public static function release( string $key ): void {
		global $wpdb;

		$key   = sanitize_text_field( $key );
		$token = (string) ( self::$claim_tokens[ $key ] ?? '' );

		if ( '' === $token ) {
			return;
		}

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM %i WHERE notification_key = %s AND status = 'pending' AND claim_token = %s",
				YoOhw_COS_DB::notification_log_table(),
				$key,
				$token
			)
		);
		unset( self::$claim_tokens[ $key ] );
	}

	public static function cleanup( int $limit = 200 ): int {
		global $wpdb;

		$limit = min( 1000, max( 1, absint( $limit ) ) );
		$sql = $wpdb->prepare(
			'DELETE FROM %i WHERE expires_at < %s ORDER BY id ASC LIMIT %d',
			YoOhw_COS_DB::notification_log_table(),
			YoOhw_COS_DB::now(),
			$limit
		);

		$deleted = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic placeholders are prepared above.

		return false === $deleted ? 0 : absint( $deleted );
	}
}
