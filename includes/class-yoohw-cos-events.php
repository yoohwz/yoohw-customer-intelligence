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
			'created_at'    => YoOhw_COS_DB::now(),
		);

		$args = wp_parse_args( $args, $defaults );

		if ( empty( $args['event_type'] ) ) {
			return 0;
		}

		$table = YoOhw_COS_DB::events_table();

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
			)
		);

		if ( ! $inserted ) {
			return 0;
		}

		$event_id = absint( $wpdb->insert_id );

		do_action( 'yoohw_cos_event_recorded', $event_id, $args );

		return $event_id;
	}

	public static function get_customer_events( int $customer_id, array $args = array() ): array {
		global $wpdb;

		$defaults = array(
			'limit'  => 50,
			'offset' => 0,
		);

		$args = wp_parse_args( $args, $defaults );

		$table = YoOhw_COS_DB::events_table();

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT *
				FROM %i
				WHERE customer_id = %d
				ORDER BY created_at DESC, id DESC
				LIMIT %d OFFSET %d",
				$table,
				$customer_id,
				absint( $args['limit'] ),
				absint( $args['offset'] )
			),
			ARRAY_A
		);

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
