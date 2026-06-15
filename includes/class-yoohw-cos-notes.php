<?php
defined( 'ABSPATH' ) || exit;

final class YoOhw_COS_Notes {

	public static function init(): void {
		// Reserved for future hooks.
	}

	public static function add_note( int $customer_id, string $content, string $note_type = 'internal' ): int {
		global $wpdb;

		$content = wp_kses_post( trim( $content ) );

		if ( $customer_id <= 0 || '' === $content ) {
			return 0;
		}

		$inserted = $wpdb->insert(
			YoOhw_COS_DB::notes_table(),
			array(
				'customer_id'  => absint( $customer_id ),
				'wp_user_id'   => 0,
				'author_id'    => get_current_user_id(),
				'note_type'    => sanitize_key( $note_type ),
				'note_content' => $content,
				'visibility'   => 'private',
				'created_at'   => YoOhw_COS_DB::now(),
				'updated_at'   => YoOhw_COS_DB::now(),
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return 0;
		}

		$note_id = absint( $wpdb->insert_id );

		YoOhw_COS_Events::record(
			array(
				'customer_id'  => $customer_id,
				'event_type'   => 'note_added',
				'event_source' => 'customer_os',
				'severity'     => 'info',
				'object_type'  => 'note',
				'object_id'    => $note_id,
				'description'  => __( 'Internal note added.', 'yoohw-customer-intelligence' ),
				'metadata'     => array(
					'note_id' => $note_id,
				),
			)
		);

		return $note_id;
	}

	public static function get_customer_notes( int $customer_id, int $limit = 20 ): array {
		global $wpdb;

		$notes = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT *
				FROM %i
				WHERE customer_id = %d
				ORDER BY created_at DESC, id DESC
				LIMIT %d",
				YoOhw_COS_DB::notes_table(),
				absint( $customer_id ),
				absint( $limit )
			),
			ARRAY_A
		);

		return is_array( $notes ) ? $notes : array();
	}

	public static function get_customer_note_count( int $customer_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM %i
				WHERE customer_id = %d",
				YoOhw_COS_DB::notes_table(),
				absint( $customer_id )
			)
		);
	}

	public static function get_note( int $note_id ): array {
		global $wpdb;

		$note = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d LIMIT 1',
				YoOhw_COS_DB::notes_table(),
				absint( $note_id )
			),
			ARRAY_A
		);

		return is_array( $note ) ? $note : array();
	}

	public static function note_belongs_to_customer( int $note_id, int $customer_id ): bool {
		$note = self::get_note( $note_id );

		return ! empty( $note ) && absint( $note['customer_id'] ?? 0 ) === absint( $customer_id );
	}

	public static function update_note( int $note_id, string $content ): bool {
		global $wpdb;

		$note = self::get_note( $note_id );

		if ( empty( $note ) ) {
			return false;
		}

		$content = wp_kses_post( trim( $content ) );

		if ( '' === $content ) {
			return false;
		}

		$updated = $wpdb->update(
			YoOhw_COS_DB::notes_table(),
			array(
				'note_content' => $content,
				'updated_at'   => YoOhw_COS_DB::now(),
			),
			array( 'id' => absint( $note_id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false !== $updated ) {
			YoOhw_COS_Events::record(
				array(
					'customer_id'  => absint( $note['customer_id'] ),
					'event_type'   => 'note_updated',
					'event_source' => 'customer_os',
					'severity'     => 'info',
					'object_type'  => 'note',
					'object_id'    => $note_id,
					'description'  => __( 'Internal note updated.', 'yoohw-customer-intelligence' ),
					'metadata'     => array(
						'note_id' => $note_id,
					),
				)
			);
		}

		return false !== $updated;
	}

	public static function delete_note( int $note_id ): bool {
		global $wpdb;

		$note = self::get_note( $note_id );

		if ( empty( $note ) ) {
			return false;
		}

		$deleted = $wpdb->delete(
			YoOhw_COS_DB::notes_table(),
			array( 'id' => absint( $note_id ) ),
			array( '%d' )
		);

		if ( $deleted ) {
			YoOhw_COS_Events::record(
				array(
					'customer_id'  => absint( $note['customer_id'] ),
					'event_type'   => 'note_deleted',
					'event_source' => 'customer_os',
					'severity'     => 'warning',
					'object_type'  => 'note',
					'object_id'    => $note_id,
					'description'  => __( 'Internal note deleted.', 'yoohw-customer-intelligence' ),
					'metadata'     => array(
						'note_id' => $note_id,
					),
				)
			);
		}

		return (bool) $deleted;
	}
}
