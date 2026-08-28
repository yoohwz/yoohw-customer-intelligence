<?php
defined( 'ABSPATH' ) || exit;

final class YoOhw_COS_Events {

	public static function init(): void {
		// Reserved for future hooks.
	}

	public static function record( array $args ): int {
		global $wpdb;

		$defaults = array(
			'customer_id'   => null,
			'wp_user_id'    => null,
			'event_type'    => '',
			'event_source'  => 'system',
			'severity'      => 'info',
			'object_type'   => null,
			'object_id'     => null,
			'description'   => '',
			'metadata'      => array(),
			'event_key'     => null,
			'created_at'    => YoOhw_COS_DB::now(),
		);

		$args = wp_parse_args( $args, $defaults );

		if ( empty( $args['event_type'] ) ) {
			return 0;
		}

		$table = YoOhw_COS_DB::events_table();
		$event_key = self::normalize_event_key( (string) ( $args['event_key'] ?? '' ) );

		if ( '' !== $event_key ) {
			$legacy_event_id = self::adopt_legacy_event_key( $table, $event_key, $args );

			if ( $legacy_event_id > 0 ) {
				return $legacy_event_id;
			}
		}

		$previous_error_suppression = '' !== $event_key ? $wpdb->suppress_errors() : null;

		$inserted = $wpdb->insert(
			$table,
			array(
				'customer_id'   => $args['customer_id'] ? absint( $args['customer_id'] ) : null,
				'wp_user_id'    => $args['wp_user_id'] ? absint( $args['wp_user_id'] ) : null,
				'event_type'    => sanitize_key( $args['event_type'] ),
				'event_source'  => sanitize_key( $args['event_source'] ),
				'severity'      => sanitize_key( $args['severity'] ),
				'object_type'   => $args['object_type'] ? sanitize_key( $args['object_type'] ) : null,
				'object_id'     => $args['object_id'] ? absint( $args['object_id'] ) : null,
				'description'   => wp_kses_post( $args['description'] ),
				'metadata_json' => YoOhw_COS_DB::json_encode( (array) $args['metadata'] ),
				'event_key'     => $event_key ?: null,
				'created_at'    => $args['created_at'],
			),
			array(
				'%d',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
			)
		);

		if ( '' !== $event_key ) {
			$wpdb->suppress_errors( (bool) $previous_error_suppression );
		}

		if ( ! $inserted ) {
			if ( '' !== $event_key ) {
				$existing_id = $wpdb->get_var(
					$wpdb->prepare( 'SELECT id FROM %i WHERE event_key = %s LIMIT 1', $table, $event_key )
				);

				return absint( $existing_id );
			}

			return 0;
		}

		$event_id = absint( $wpdb->insert_id );

		do_action( 'yoohw_cos_event_recorded', $event_id, $args );

		return $event_id;
	}

	public static function make_event_key(
		string $event_source,
		string $event_type,
		string $object_type,
		int $object_id,
		int $customer_id = 0,
		string $external_id = ''
	): string {
		$identity = implode(
			'|',
			array(
				sanitize_key( $event_source ),
				sanitize_key( $event_type ),
				sanitize_key( $object_type ),
				absint( $object_id ),
				absint( $customer_id ),
				sanitize_text_field( $external_id ),
			)
		);

		return hash( 'sha256', $identity );
	}

	private static function normalize_event_key( string $event_key ): string {
		$event_key = trim( sanitize_text_field( $event_key ) );

		if ( '' === $event_key ) {
			return '';
		}

		return strlen( $event_key ) <= 191 ? $event_key : hash( 'sha256', $event_key );
	}

	/**
	 * Atomically attach a deterministic key to the oldest matching pre-key row.
	 * The unique event_key index arbitrates concurrent adopters and insertions.
	 */
	private static function adopt_legacy_event_key( string $table, string $event_key, array $args ): int {
		global $wpdb;

		$object_type = sanitize_key( (string) ( $args['object_type'] ?? '' ) );
		$object_id   = absint( $args['object_id'] ?? 0 );

		if ( '' === $object_type || $object_id <= 0 ) {
			return 0;
		}

		$event_type   = sanitize_key( (string) ( $args['event_type'] ?? '' ) );
		$event_source = sanitize_key( (string) ( $args['event_source'] ?? 'system' ) );
		$customer_id  = absint( $args['customer_id'] ?? 0 );
		$previous_error_suppression = $wpdb->suppress_errors();

		if ( $customer_id > 0 ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE %i target
					JOIN (
						SELECT id FROM %i
						WHERE event_key IS NULL
							AND event_type = %s
							AND event_source = %s
							AND object_type = %s
							AND object_id = %d
							AND customer_id = %d
						ORDER BY id ASC
						LIMIT 1
					) legacy ON legacy.id = target.id
					SET target.event_key = %s
					WHERE target.event_key IS NULL",
					$table,
					$table,
					$event_type,
					$event_source,
					$object_type,
					$object_id,
					$customer_id,
					$event_key
				)
			);
		} else {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE %i target
					JOIN (
						SELECT id FROM %i
						WHERE event_key IS NULL
							AND event_type = %s
							AND event_source = %s
							AND object_type = %s
							AND object_id = %d
							AND (customer_id IS NULL OR customer_id = 0)
						ORDER BY id ASC
						LIMIT 1
					) legacy ON legacy.id = target.id
					SET target.event_key = %s
					WHERE target.event_key IS NULL",
					$table,
					$table,
					$event_type,
					$event_source,
					$object_type,
					$object_id,
					$event_key
				)
			);
		}

		$wpdb->suppress_errors( (bool) $previous_error_suppression );

		return absint(
			$wpdb->get_var(
				$wpdb->prepare( 'SELECT id FROM %i WHERE event_key = %s LIMIT 1', $table, $event_key )
			)
		);
	}

	public static function get_customer_events( int $customer_id, array $args = array() ): array {
		global $wpdb;

		$defaults = array(
			'limit'        => 50,
			'offset'       => 0,
			'event_source' => '',
		);

		$args = wp_parse_args( $args, $defaults );

		$table        = YoOhw_COS_DB::events_table();
		$event_source = sanitize_key( (string) $args['event_source'] );
		$limit        = absint( $args['limit'] );
		$offset       = absint( $args['offset'] );

		if ( '' !== $event_source ) {
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT *
					FROM %i
					WHERE customer_id = %d
						AND event_source = %s
					ORDER BY created_at DESC, id DESC
					LIMIT %d OFFSET %d",
					$table,
					$customer_id,
					$event_source,
					$limit,
					$offset
				),
				ARRAY_A
			);
		} else {
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT *
					FROM %i
					WHERE customer_id = %d
					ORDER BY created_at DESC, id DESC
					LIMIT %d OFFSET %d",
					$table,
					$customer_id,
					$limit,
					$offset
				),
				ARRAY_A
			);
		}

		if ( empty( $results ) ) {
			return array();
		}

		foreach ( $results as &$row ) {
			$row['metadata'] = YoOhw_COS_DB::json_decode( $row['metadata_json'] ?? '' );
			unset( $row['metadata_json'] );
		}

		return $results;
	}

	public static function get_customer_event_count( int $customer_id ): int {
		global $wpdb;

		$table = YoOhw_COS_DB::events_table();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM %i
				WHERE customer_id = %d",
				$table,
				absint( $customer_id )
			)
		);
	}

	public static function assign_customer( int $event_id, int $customer_id, int $wp_user_id = 0 ): bool {
		global $wpdb;

		$event_id    = absint( $event_id );
		$customer_id = absint( $customer_id );

		if ( $event_id <= 0 || $customer_id <= 0 ) {
			return false;
		}

		if ( $wp_user_id > 0 ) {
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE %i
					SET customer_id = %d, wp_user_id = %d
					WHERE id = %d
						AND (customer_id IS NULL OR customer_id = 0)",
					YoOhw_COS_DB::events_table(),
					$customer_id,
					absint( $wp_user_id ),
					$event_id
				)
			);
		} else {
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE %i
					SET customer_id = %d
					WHERE id = %d
						AND (customer_id IS NULL OR customer_id = 0)",
					YoOhw_COS_DB::events_table(),
					$customer_id,
					$event_id
				)
			);
		}

		return false !== $updated && $updated > 0;
	}

	public static function event_exists(
		string $event_type,
		string $object_type,
		int $object_id,
		int $customer_id = 0
	): bool {
		global $wpdb;

		$table = YoOhw_COS_DB::events_table();

		if ( $customer_id > 0 ) {
			$event_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id
					FROM %i
					WHERE event_type = %s
					AND object_type = %s
					AND object_id = %d
					AND customer_id = %d
					LIMIT 1",
					$table,
					$event_type,
					$object_type,
					$object_id,
					$customer_id
				)
			);
		} else {
			$event_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id
					FROM %i
					WHERE event_type = %s
					AND object_type = %s
					AND object_id = %d
					LIMIT 1",
					$table,
					$event_type,
					$object_type,
					$object_id
				)
			);
		}

		return ! empty( $event_id );
	}

	public static function get_total_events(): int {
		global $wpdb;

		$table = YoOhw_COS_DB::events_table();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i',
				$table
			)
		);
	}

	public static function get_grouped_counts( string $field ): array {
		global $wpdb;

		$allowed_fields = array(
			'event_type',
			'event_source',
			'severity',
		);

		if ( ! in_array( $field, $allowed_fields, true ) ) {
			return array();
		}

		$table = YoOhw_COS_DB::events_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT %i as label, COUNT(*) as total
				FROM %i
				GROUP BY %i
				ORDER BY total DESC",
				$field,
				$table,
				$field
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}
}
