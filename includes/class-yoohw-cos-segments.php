<?php
defined( 'ABSPATH' ) || exit;

final class YoOhw_COS_Segments {

	public static function init(): void {
		// Reserved for future hooks.
	}

	public static function create_segment(
		string $name,
		string $segment_type = 'static',
		string $description = '',
		array $rules = array()
	): int {
		global $wpdb;

		$name = sanitize_text_field( $name );

		if ( '' === $name ) {
			return 0;
		}

		$slug = sanitize_title( $name );

		$existing = self::get_segment_by_slug( $slug );

		if ( $existing ) {
			return absint( $existing['id'] );
		}

		$inserted = $wpdb->insert(
			YoOhw_COS_DB::segments_table(),
			array(
				'name'         => $name,
				'slug'         => $slug,
				'segment_type' => sanitize_key( $segment_type ),
				'description'  => sanitize_textarea_field( $description ),
				'rules_json'   => YoOhw_COS_DB::json_encode( $rules ),
				'created_at'   => YoOhw_COS_DB::now(),
				'updated_at'   => YoOhw_COS_DB::now(),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $inserted ? absint( $wpdb->insert_id ) : 0;
	}

	public static function get_segment_by_slug( string $slug ): array {
		global $wpdb;

		$segment = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE slug = %s LIMIT 1',
				YoOhw_COS_DB::segments_table(),
				sanitize_title( $slug )
			),
			ARRAY_A
		);

		return is_array( $segment ) ? $segment : array();
	}

	public static function get_all_segments(): array {
		global $wpdb;

		$segments = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i ORDER BY name ASC',
				YoOhw_COS_DB::segments_table()
			),
			ARRAY_A
		);

		return is_array( $segments ) ? $segments : array();
	}

	public static function assign_customer( int $customer_id, int $segment_id, int $created_by = 0, bool $record_event = true ): bool {
		global $wpdb;

		if ( $customer_id <= 0 || $segment_id <= 0 ) {
			return false;
		}

		if ( self::customer_in_segment( $customer_id, $segment_id ) ) {
			return true;
		}

		$inserted = $wpdb->insert(
			YoOhw_COS_DB::customer_segments_table(),
			array(
				'customer_id' => absint( $customer_id ),
				'segment_id'  => absint( $segment_id ),
				'created_by'  => $created_by ? absint( $created_by ) : get_current_user_id(),
				'created_at'  => YoOhw_COS_DB::now(),
			),
			array( '%d', '%d', '%d', '%s' )
		);

		if ( $inserted && $record_event ) {
			$segment = self::get_segment( $segment_id );

			YoOhw_COS_Events::record(
				array(
					'customer_id'  => $customer_id,
					'event_type'   => 'segment_assigned',
					'event_source' => 'customer_os',
					'severity'     => 'info',
					'object_type'  => 'segment',
					'object_id'    => $segment_id,
					'description'  => sprintf(
						/* translators: %s: segment name */
						__( 'Segment assigned: %s', 'yoohw-customer-intelligence' ),
						$segment['name'] ?? ''
					),
					'metadata'     => array(
						'segment_id'   => $segment_id,
						'segment_name' => $segment['name'] ?? '',
					),
				)
			);
		}

		return (bool) $inserted;
	}

	public static function remove_customer( int $customer_id, int $segment_id, bool $record_event = true ): bool {
		global $wpdb;

		$segment = self::get_segment( $segment_id );

		$deleted = $wpdb->delete(
			YoOhw_COS_DB::customer_segments_table(),
			array(
				'customer_id' => absint( $customer_id ),
				'segment_id'  => absint( $segment_id ),
			),
			array( '%d', '%d' )
		);

		if ( $deleted && $record_event ) {
			YoOhw_COS_Events::record(
				array(
					'customer_id'  => $customer_id,
					'event_type'   => 'segment_removed',
					'event_source' => 'customer_os',
					'severity'     => 'info',
					'object_type'  => 'segment',
					'object_id'    => $segment_id,
					'description'  => sprintf(
						/* translators: %s: segment name */
						__( 'Segment removed: %s', 'yoohw-customer-intelligence' ),
						$segment['name'] ?? ''
					),
				)
			);
		}

		return (bool) $deleted;
	}

	public static function get_segment( int $segment_id ): array {
		global $wpdb;

		$segment = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d LIMIT 1',
				YoOhw_COS_DB::segments_table(),
				absint( $segment_id )
			),
			ARRAY_A
		);

		return is_array( $segment ) ? $segment : array();
	}

	public static function segment_exists( int $segment_id ): bool {
		return ! empty( self::get_segment( $segment_id ) );
	}

	public static function customer_in_segment( int $customer_id, int $segment_id ): bool {
		global $wpdb;

		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM %i
				WHERE customer_id = %d AND segment_id = %d
				LIMIT 1",
				YoOhw_COS_DB::customer_segments_table(),
				absint( $customer_id ),
				absint( $segment_id )
			)
		);

		return ! empty( $id );
	}

	public static function get_customer_segments( int $customer_id ): array {
		global $wpdb;

		$segments_table          = YoOhw_COS_DB::segments_table();
		$customer_segments_table = YoOhw_COS_DB::customer_segments_table();

		$segments = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*
				FROM %i s
				INNER JOIN %i cs ON cs.segment_id = s.id
				WHERE cs.customer_id = %d
				ORDER BY s.name ASC",
				$segments_table,
				$customer_segments_table,
				absint( $customer_id )
			),
			ARRAY_A
		);

		return is_array( $segments ) ? $segments : array();
	}

	public static function get_segment_customer_count( int $segment_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM %i
				WHERE segment_id = %d",
				YoOhw_COS_DB::customer_segments_table(),
				absint( $segment_id )
			)
		);
	}

	public static function delete_segment( int $segment_id, bool $force = false ): bool {
		global $wpdb;

		$segment = self::get_segment( $segment_id );

		if ( empty( $segment ) ) {
			return false;
		}

		$count = self::get_segment_customer_count( $segment_id );

		if ( $count > 0 && ! $force ) {
			return false;
		}

		if ( $force ) {
			$wpdb->delete(
				YoOhw_COS_DB::customer_segments_table(),
				array( 'segment_id' => absint( $segment_id ) ),
				array( '%d' )
			);
		}

		$deleted = $wpdb->delete(
			YoOhw_COS_DB::segments_table(),
			array( 'id' => absint( $segment_id ) ),
			array( '%d' )
		);

		return (bool) $deleted;
	}

	public static function update_segment( int $segment_id, string $name, string $description = '' ): bool {
		global $wpdb;

		$segment = self::get_segment( $segment_id );

		if ( empty( $segment ) ) {
			return false;
		}

		$name = sanitize_text_field( $name );

		if ( '' === $name ) {
			return false;
		}

		$updated = $wpdb->update(
			YoOhw_COS_DB::segments_table(),
			array(
				'name'        => $name,
				'slug'        => sanitize_title( $name ),
				'description' => sanitize_textarea_field( $description ),
				'updated_at'  => YoOhw_COS_DB::now(),
			),
			array( 'id' => absint( $segment_id ) ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}
}
