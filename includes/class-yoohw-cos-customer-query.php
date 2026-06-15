<?php
defined( 'ABSPATH' ) || exit;

final class YoOhw_COS_Customer_Query {

	public static function sanitize_args( array $source ): array {
		$orderby = self::sanitize_orderby( self::get_scalar( $source, 'orderby', 'last_activity_date' ) );
		$order   = strtoupper( sanitize_key( self::get_scalar( $source, 'order', 'DESC' ) ) );

		if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
			$order = 'DESC';
		}

		return array(
			's'                => sanitize_text_field( self::get_scalar( $source, 's' ) ),
			'customer_tag'     => absint( self::get_scalar( $source, 'customer_tag' ) ),
			'customer_segment' => absint( self::get_scalar( $source, 'customer_segment' ) ),
			'customer_status'  => self::sanitize_customer_status( self::get_scalar( $source, 'customer_status' ) ),
			'vip_status'       => self::sanitize_vip_status( self::get_scalar( $source, 'vip_status' ) ),
			'risk_level'       => self::sanitize_risk_level( self::get_scalar( $source, 'risk_level' ) ),
			'lifecycle_stage'  => self::sanitize_lifecycle_stage( self::get_scalar( $source, 'lifecycle_stage' ) ),
			'customer_view'    => self::sanitize_customer_view( self::get_scalar( $source, 'customer_view' ) ),
			'orderby'          => $orderby,
			'order'            => $order,
			'paged'            => max( 1, absint( self::get_scalar( $source, 'paged', 1 ) ) ),
			'per_page'         => self::sanitize_per_page( self::get_scalar( $source, 'per_page', 20 ) ),
			'offset'           => isset( $source['offset'] ) ? max( 0, absint( self::get_scalar( $source, 'offset' ) ) ) : null,
		);
	}

	public static function query( array $args ): array {
		global $wpdb;

		$args = self::sanitize_args( $args );

		$table      = YoOhw_COS_DB::customers_table();
		$where_data = self::build_where_clause( $args );
		$where      = $where_data['where'];
		$params     = $where_data['params'];
		$offset     = null === $args['offset']
			? ( $args['paged'] - 1 ) * $args['per_page']
			: $args['offset'];

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Filter SQL fragments are hardcoded; sort direction is whitelisted and all dynamic values are prepared.
		$total_items = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i {$where}",
				...array_merge( array( $table ), $params )
			)
		);

		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT *
				FROM %i
				{$where}
				ORDER BY %i {$args['order']}, id DESC
				LIMIT %d OFFSET %d",
				...array_merge( array( $table ), $params, array( $args['orderby'], $args['per_page'], $offset ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return array(
			'items'       => is_array( $items ) ? $items : array(),
			'total_items' => $total_items,
			'args'        => $args,
		);
	}

	private static function build_where_clause( array $args ): array {
		global $wpdb;

		$customer_tags_table     = YoOhw_COS_DB::customer_tags_table();
		$customer_segments_table = YoOhw_COS_DB::customer_segments_table();
		$where                   = 'WHERE 1=1';
		$params                  = array();
		$search                  = (string) $args['s'];
		$normalized_search_id    = self::normalize_search_id( $search );

		if ( 'archived' === $args['customer_view'] ) {
			$where .= ' AND archived_at IS NOT NULL';
		} else {
			$where .= ' AND archived_at IS NULL';
		}

		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';

			$where .= ' AND (
				display_name LIKE %s
				OR first_name LIKE %s
				OR last_name LIKE %s
				OR email LIKE %s
				OR phone LIKE %s
			';

			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;

			if ( $normalized_search_id > 0 ) {
				$where .= '
					OR id = %d
					OR wp_user_id = %d
					OR last_order_id = %d
				';

				$params[] = $normalized_search_id;
				$params[] = $normalized_search_id;
				$params[] = $normalized_search_id;

				if ( function_exists( 'wc_get_order' ) ) {
					$wc_order = wc_get_order( $normalized_search_id );

					if ( is_a( $wc_order, 'WC_Order' ) ) {
						$order_customer_id = absint( $wc_order->get_customer_id() );
						$order_email       = sanitize_email( $wc_order->get_billing_email() );

						if ( $order_customer_id > 0 ) {
							$where   .= ' OR wp_user_id = %d';
							$params[] = $order_customer_id;
						}

						if ( $order_email ) {
							$where   .= ' OR email = %s';
							$params[] = $order_email;
						}
					}
				}
			}

			$where .= ')';
		}

		if ( $args['customer_tag'] > 0 ) {
			$where   .= " AND id IN (
				SELECT customer_id
				FROM %i
				WHERE tag_id = %d
			)";
			$params[] = $customer_tags_table;
			$params[] = $args['customer_tag'];
		}

		if ( $args['customer_segment'] > 0 ) {
			$where   .= " AND id IN (
				SELECT customer_id
				FROM %i
				WHERE segment_id = %d
			)";
			$params[] = $customer_segments_table;
			$params[] = $args['customer_segment'];
		}

		if ( '' !== $args['customer_status'] ) {
			$where   .= ' AND customer_status = %s';
			$params[] = $args['customer_status'];
		}

		if ( '' !== $args['vip_status'] ) {
			$where   .= ' AND vip_status = %s';
			$params[] = $args['vip_status'];
		}

		if ( '' !== $args['risk_level'] ) {
			if ( 'high' === $args['risk_level'] ) {
				$where .= ' AND risk_score >= 70';
			} elseif ( 'medium' === $args['risk_level'] ) {
				$where .= ' AND risk_score >= 40 AND risk_score < 70';
			} elseif ( 'low' === $args['risk_level'] ) {
				$where .= ' AND risk_score >= 15 AND risk_score < 40';
			} elseif ( 'none' === $args['risk_level'] ) {
				$where .= ' AND risk_score < 15';
			}
		}

		if ( '' !== $args['lifecycle_stage'] ) {
			$where   .= ' AND lifecycle_stage = %s';
			$params[] = $args['lifecycle_stage'];
		}

		return array(
			'where'  => $where,
			'params' => $params,
		);
	}

	private static function normalize_search_id( string $search ): int {
		$raw_search = trim( $search );

		if ( '' === $raw_search ) {
			return 0;
		}

		if ( preg_match( '/^#?(\d+)$/', $raw_search, $matches ) ) {
			return absint( $matches[1] );
		}

		if ( preg_match( '/^order[:\s#-]*(\d+)$/i', $raw_search, $matches ) ) {
			return absint( $matches[1] );
		}

		if ( preg_match( '/^customer[:\s#-]*(\d+)$/i', $raw_search, $matches ) ) {
			return absint( $matches[1] );
		}

		if ( preg_match( '/^user[:\s#-]*(\d+)$/i', $raw_search, $matches ) ) {
			return absint( $matches[1] );
		}

		return 0;
	}

	private static function get_scalar( array $source, string $key, $default = '' ): string {
		if ( ! isset( $source[ $key ] ) || is_array( $source[ $key ] ) ) {
			return (string) $default;
		}

		return (string) $source[ $key ];
	}

	private static function sanitize_orderby( string $orderby ): string {
		$orderby = sanitize_key( $orderby );

		$allowed_orderby = array(
			'total_orders',
			'total_spent',
			'average_order_value',
			'risk_score',
			'trust_score',
			'last_activity_date',
		);

		return in_array( $orderby, $allowed_orderby, true ) ? $orderby : 'last_activity_date';
	}

	private static function sanitize_per_page( string $per_page ): int {
		$per_page = absint( $per_page );

		if ( $per_page <= 0 ) {
			return 20;
		}

		return min( $per_page, 5000 );
	}

	private static function sanitize_customer_status( string $status ): string {
		$status = sanitize_key( $status );

		return in_array( $status, array( 'new', 'active', 'at_risk', 'inactive', 'vip' ), true ) ? $status : '';
	}

	private static function sanitize_vip_status( string $status ): string {
		$status = sanitize_key( $status );

		return in_array( $status, array( 'none', 'silver', 'gold', 'platinum' ), true ) ? $status : '';
	}

	private static function sanitize_risk_level( string $risk_level ): string {
		$risk_level = sanitize_key( $risk_level );

		return in_array( $risk_level, array( 'none', 'low', 'medium', 'high' ), true ) ? $risk_level : '';
	}

	private static function sanitize_lifecycle_stage( string $stage ): string {
		$stage = sanitize_key( $stage );

		return in_array( $stage, array( 'new', 'repeat', 'loyal', 'vip', 'dormant' ), true ) ? $stage : '';
	}

	private static function sanitize_customer_view( string $view ): string {
		$view = sanitize_key( $view );

		return 'archived' === $view ? 'archived' : '';
	}
}
