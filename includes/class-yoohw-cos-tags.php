<?php
defined( 'ABSPATH' ) || exit;

final class YoOhw_COS_Tags {

	public static function init(): void {
		// Reserved for future hooks.
	}

	public static function create_tag( string $name, string $color = '', string $description = '' ): int {
		global $wpdb;

		$name = sanitize_text_field( $name );

		if ( '' === $name ) {
			return 0;
		}

		$slug = sanitize_title( $name );

		$existing = self::get_tag_by_slug( $slug );

		if ( $existing ) {
			return absint( $existing['id'] );
		}

		$inserted = $wpdb->insert(
			YoOhw_COS_DB::tags_table(),
			array(
				'name'        => $name,
				'slug'        => $slug,
				'color'       => sanitize_hex_color( $color ),
				'description' => sanitize_textarea_field( $description ),
				'created_at'  => YoOhw_COS_DB::now(),
				'updated_at'  => YoOhw_COS_DB::now(),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $inserted ? absint( $wpdb->insert_id ) : 0;
	}

	public static function get_tag_by_slug( string $slug ): array {
		global $wpdb;

		$tag = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE slug = %s LIMIT 1',
				YoOhw_COS_DB::tags_table(),
				sanitize_title( $slug )
			),
			ARRAY_A
		);

		return is_array( $tag ) ? $tag : array();
	}

	public static function assign_tag( int $customer_id, int $tag_id, int $created_by = 0, bool $record_event = true ): bool {
		global $wpdb;

		if ( $customer_id <= 0 || $tag_id <= 0 ) {
			return false;
		}

		$already_assigned = self::customer_has_tag( $customer_id, $tag_id );

		if ( $already_assigned ) {
			return true;
		}

		$inserted = $wpdb->insert(
			YoOhw_COS_DB::customer_tags_table(),
			array(
				'customer_id' => absint( $customer_id ),
				'tag_id'      => absint( $tag_id ),
				'created_by'  => $created_by ? absint( $created_by ) : get_current_user_id(),
				'created_at'  => YoOhw_COS_DB::now(),
			),
			array( '%d', '%d', '%d', '%s' )
		);

		if ( $inserted && $record_event ) {
			$tag = self::get_tag( $tag_id );

			YoOhw_COS_Events::record(
				array(
					'customer_id'  => $customer_id,
					'event_type'   => 'tag_assigned',
					'event_source' => 'customer_os',
					'severity'     => 'info',
					'object_type'  => 'tag',
					'object_id'    => $tag_id,
					'description'  => sprintf(
						/* translators: %s: tag name */
						__( 'Tag assigned: %s', 'yoohw-customer-intelligence' ),
						$tag['name'] ?? ''
					),
					'metadata'     => array(
						'tag_id'   => $tag_id,
						'tag_name' => $tag['name'] ?? '',
					),
				)
			);
		}

		return (bool) $inserted;
	}

	public static function get_customer_tags( int $customer_id ): array {
		global $wpdb;

		$tags_table          = YoOhw_COS_DB::tags_table();
		$customer_tags_table = YoOhw_COS_DB::customer_tags_table();

		$tags = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.*
				FROM %i t
				INNER JOIN %i ct ON ct.tag_id = t.id
				WHERE ct.customer_id = %d
				ORDER BY t.name ASC",
				$tags_table,
				$customer_tags_table,
				absint( $customer_id )
			),
			ARRAY_A
		);

		return is_array( $tags ) ? $tags : array();
	}

	public static function get_tag( int $tag_id ): array {
		global $wpdb;

		$tag = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d LIMIT 1',
				YoOhw_COS_DB::tags_table(),
				absint( $tag_id )
			),
			ARRAY_A
		);

		return is_array( $tag ) ? $tag : array();
	}

	public static function tag_exists( int $tag_id ): bool {
		return ! empty( self::get_tag( $tag_id ) );
	}

	public static function customer_has_tag( int $customer_id, int $tag_id ): bool {
		global $wpdb;

		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM %i
				WHERE customer_id = %d AND tag_id = %d
				LIMIT 1",
				YoOhw_COS_DB::customer_tags_table(),
				absint( $customer_id ),
				absint( $tag_id )
			)
		);

		return ! empty( $id );
	}

	public static function remove_tag( int $customer_id, int $tag_id, bool $record_event = true ): bool {
		global $wpdb;

		if ( $customer_id <= 0 || $tag_id <= 0 ) {
			return false;
		}

		$tag = self::get_tag( $tag_id );

		$deleted = $wpdb->delete(
			YoOhw_COS_DB::customer_tags_table(),
			array(
				'customer_id' => absint( $customer_id ),
				'tag_id'      => absint( $tag_id ),
			),
			array( '%d', '%d' )
		);

		if ( $deleted && $record_event ) {
			YoOhw_COS_Events::record(
				array(
					'customer_id'  => $customer_id,
					'event_type'   => 'tag_removed',
					'event_source' => 'customer_os',
					'severity'     => 'info',
					'object_type'  => 'tag',
					'object_id'    => $tag_id,
					'description'  => sprintf(
						/* translators: %s: tag name */
						__( 'Tag removed: %s', 'yoohw-customer-intelligence' ),
						$tag['name'] ?? ''
					),
					'metadata'     => array(
						'tag_id'   => $tag_id,
						'tag_name' => $tag['name'] ?? '',
					),
				)
			);
		}

		return (bool) $deleted;
	}

	public static function get_all_tags(): array {
		global $wpdb;

		$tags = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i ORDER BY name ASC',
				YoOhw_COS_DB::tags_table()
			),
			ARRAY_A
		);

		return is_array( $tags ) ? $tags : array();
	}

	public static function get_tag_customer_count( int $tag_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE tag_id = %d',
				YoOhw_COS_DB::customer_tags_table(),
				absint( $tag_id )
			)
		);
	}

	public static function update_tag( int $tag_id, string $name, string $color = '', string $description = '' ): bool {
		global $wpdb;

		$name = sanitize_text_field( $name );

		if ( $tag_id <= 0 || '' === $name ) {
			return false;
		}

		$updated = $wpdb->update(
			YoOhw_COS_DB::tags_table(),
			array(
				'name'        => $name,
				'slug'        => sanitize_title( $name ),
				'color'       => sanitize_hex_color( $color ),
				'description' => sanitize_textarea_field( $description ),
				'updated_at'  => YoOhw_COS_DB::now(),
			),
			array( 'id' => absint( $tag_id ) ),
			array( '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	public static function delete_tag( int $tag_id, bool $force = false ): bool {
		global $wpdb;

		if ( $tag_id <= 0 ) {
			return false;
		}

		$count = self::get_tag_customer_count( $tag_id );

		if ( $count > 0 && ! $force ) {
			return false;
		}

		if ( $force ) {
			$wpdb->delete(
				YoOhw_COS_DB::customer_tags_table(),
				array( 'tag_id' => absint( $tag_id ) ),
				array( '%d' )
			);
		}

		$deleted = $wpdb->delete(
			YoOhw_COS_DB::tags_table(),
			array( 'id' => absint( $tag_id ) ),
			array( '%d' )
		);

		return (bool) $deleted;
	}
}
