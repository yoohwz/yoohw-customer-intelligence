<?php
defined( 'ABSPATH' ) || exit;

/** Atomic, bounded notification dedupe ledger. */
final class YoOhw_COS_Notification_Ledger {

	public const RETENTION_DAYS = 45;

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
		$previous_error_suppression = $wpdb->suppress_errors();

		$inserted = $wpdb->insert(
			YoOhw_COS_DB::notification_log_table(),
			array(
				'notification_key'  => sanitize_text_field( $key ),
				'notification_type' => sanitize_key( $type ),
				'task_id'            => $task_id > 0 ? absint( $task_id ) : null,
				'recipient_user_id'  => $recipient_user_id > 0 ? absint( $recipient_user_id ) : null,
				'status'             => 'pending',
				'created_at'         => YoOhw_COS_DB::now(),
				'expires_at'         => wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + self::RETENTION_DAYS * DAY_IN_SECONDS ),
			),
			array( '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
		);
		$wpdb->suppress_errors( (bool) $previous_error_suppression );

		return false !== $inserted;
	}

	public static function mark_sent( string $key ): void {
		global $wpdb;

		$wpdb->update(
			YoOhw_COS_DB::notification_log_table(),
			array(
				'status'  => 'sent',
				'sent_at' => YoOhw_COS_DB::now(),
			),
			array( 'notification_key' => sanitize_text_field( $key ) ),
			array( '%s', '%s' ),
			array( '%s' )
		);
	}

	public static function release( string $key ): void {
		global $wpdb;

		$wpdb->delete(
			YoOhw_COS_DB::notification_log_table(),
			array(
				'notification_key' => sanitize_text_field( $key ),
				'status'           => 'pending',
			),
			array( '%s', '%s' )
		);
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
