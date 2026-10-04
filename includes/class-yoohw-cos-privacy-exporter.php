<?php
defined( 'ABSPATH' ) || exit;

/** Native, read-only WordPress personal data exporter. */
final class YoOhw_COS_Privacy_Exporter {
	private const PAGE_SIZE = 25;

	public static function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register' ) );
	}

	public static function register( array $exporters ): array {
		$exporters['yoohw-customer-intelligence'] = array(
			'exporter_friendly_name' => __( 'Customer Intelligence', 'yoohw-customer-intelligence' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	/** Every page resolves the request anew; neither customer IDs nor cursors are trusted. */
	public static function export( $email_address, $page = 1 ) {
		global $wpdb;
		$empty = array( 'data' => array(), 'done' => true );
		if ( ! is_string( $email_address ) || ! is_numeric( $page ) || (int) $page < 1 || (string) (int) $page !== (string) $page ) {
			return $empty;
		}
		$email = strtolower( trim( $email_address ) );
		if ( ! is_email( $email ) || sanitize_email( $email ) !== $email || strlen( $email ) > 191 ) {
			return $empty;
		}
		$page = (int) $page;
		if ( $page > intdiv( PHP_INT_MAX, self::PAGE_SIZE ) ) {
			return $empty;
		}
		$user = get_user_by( 'email', $email );
		$user_id = $user && strtolower( (string) $user->user_email ) === $email ? (int) $user->ID : 0;

		// A nonblocking read lock excludes Reset during all subject-bound queries.
		$lock = YoOhw_COS_Reset_Guard::lock_name();
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Database advisory locking requires the live connection, never a cached value.
			return self::retry_error();
		}
		try {
			if ( ! YoOhw_COS_Reset_Guard::ready() ) {
				return self::retry_error();
			}
			$views = array();
			if ( $user_id ) {
				foreach ( YoOhw_COS_Saved_Views::all_for_user( $user_id ) as $id => $view ) {
				if ( '' === YoOhw_COS_Saved_Views::stale_reason( $view['definition'] ?? null ) && '' !== trim( $view['name'] ) ) {
					$views[ $id ] = $view;
				}
			}
				ksort( $views, SORT_STRING );
			}
			$categories = self::categories( $email, $user_id );
			$categories['views'] = array( 'count' => count( $views ) );
			$start = ( $page - 1 ) * self::PAGE_SIZE;
			$total = 0;
			$data = array();
			foreach ( $categories as $name => $category ) {
				$count = 'views' === $name ? $category['count'] : self::count_rows( $category );
				if ( null === $count ) {
					return self::retry_error();
				}
				if ( $start < $total + $count && count( $data ) < self::PAGE_SIZE ) {
					$offset = max( 0, $start - $total );
					$limit = min( self::PAGE_SIZE - count( $data ), $count - $offset );
					$rows = 'views' === $name ? array_slice( $views, $offset, $limit, true ) : self::read_rows( $category, $limit, $offset );
					if ( null === $rows ) {
						return self::retry_error();
					}
					foreach ( $rows as $id => $row ) {
						$data[] = self::item( $name, $row, $id );
					}
				}
				$total += $count;
			}
			return array( 'data' => $data, 'done' => $page * self::PAGE_SIZE >= $total );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Database advisory locking requires the live connection, never a cached value.
		}
	}

	private static function retry_error(): WP_Error {
		return new WP_Error( 'yoohw_cos_privacy_retry', __( 'Customer Intelligence data is temporarily unavailable. Retry the personal data export after Reset or database recovery completes.', 'yoohw-customer-intelligence' ) );
	}

	/** Fixed SQL projections exclude operator IDs, technical keys and opaque blobs. */
	private static function categories( string $email, int $user_id ): array {
		$c = YoOhw_COS_DB::customers_table();
		$identity = '(c.email = %s AND BINARY c.email = BINARY %s' . ( $user_id > 0 ? ' OR c.wp_user_id = %d' : '' ) . ')';
		$args = $user_id > 0 ? array( $email, $email, $user_id ) : array( $email, $email );
		return array(
			'profile' => array( 'from' => "$c c", 'where' => $identity, 'args' => $args, 'order' => 'c.id', 'select' => 'c.id, c.wp_user_id, c.email, c.phone, c.first_name, c.last_name, c.display_name, c.customer_status, c.lifecycle_stage, c.vip_status, c.risk_score, c.trust_score, c.archived_at, c.archive_reason, c.created_at, c.updated_at, c.first_order_date, c.first_order_id, c.last_order_date, c.last_order_id, c.last_activity_date, c.total_orders, c.total_spent, c.average_order_value, c.money_state, c.money_currency, c.commerce_metrics_version, c.intelligence_currency_ready, c.intelligence_generation, c.loyalty_score, c.loyalty_level, c.available_points, c.earned_points' ),
			'notes' => array( 'from' => YoOhw_COS_DB::notes_table() . " r INNER JOIN $c c ON c.id = r.customer_id", 'where' => $identity, 'args' => $args, 'order' => 'r.id', 'select' => 'r.id, r.customer_id, r.note_type, r.visibility, r.note_content, r.created_at, r.updated_at' ),
			'tasks' => array( 'from' => YoOhw_COS_DB::tasks_table() . " r INNER JOIN $c c ON c.id = r.customer_id", 'where' => $identity, 'args' => $args, 'order' => 'r.id', 'select' => 'r.id, r.customer_id, r.title, r.description, r.status, r.priority, r.due_date, r.completed_at, r.created_at, r.updated_at, r.order_id' ),
			'events' => array( 'from' => YoOhw_COS_DB::events_table() . " r LEFT JOIN $c c ON c.id = r.customer_id", 'where' => $user_id > 0 ? "($identity OR (r.customer_id IS NULL AND r.wp_user_id = %d AND r.event_source IN ('wc_blacklist_manager', 'wc_blacklist_manager_premium', 'wc_loyalty')))" : $identity, 'args' => $user_id > 0 ? array_merge( $args, array( $user_id ) ) : $args, 'order' => 'r.id', 'select' => 'r.id, r.customer_id, r.event_type, r.event_source, r.severity, r.description, r.created_at, r.object_type, r.object_id' ),
			'tags' => array( 'from' => YoOhw_COS_DB::customer_tags_table() . " r INNER JOIN $c c ON c.id = r.customer_id INNER JOIN " . YoOhw_COS_DB::tags_table() . ' t ON t.id = r.tag_id', 'where' => $identity, 'args' => $args, 'order' => 'r.id', 'select' => 'r.id, r.customer_id, t.name, r.created_at' ),
			'segments' => array( 'from' => YoOhw_COS_DB::customer_segments_table() . " r INNER JOIN $c c ON c.id = r.customer_id INNER JOIN " . YoOhw_COS_DB::segments_table() . " t ON t.id = r.segment_id", 'where' => "$identity AND t.segment_type = 'static'", 'args' => $args, 'order' => 'r.id', 'select' => 'r.id, r.customer_id, t.name, r.created_at' ),
		);
	}

	private static function count_rows( array $category ): ?int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- FROM and WHERE come only from the fixed categories() map; subject values use prepare placeholders.
		$sql = $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $category['from'] . ' WHERE ' . $category['where'], ...$category['args'] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- FROM and WHERE come only from the fixed categories() map; subject values use prepare placeholders. SQL fragments come only from the fixed categories() map; variadic subject/pagination values fill its placeholders.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- FROM and WHERE come only from the fixed categories() map; subject values use prepare placeholders. SQL fragments come only from the fixed categories() map; variadic subject/pagination values fill its placeholders. Prepared immediately above from fixed category SQL and validated subject values.
		$count = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- FROM and WHERE come only from the fixed categories() map; subject values use prepare placeholders. SQL fragments come only from the fixed categories() map; variadic subject/pagination values fill its placeholders. Prepared immediately above from fixed category SQL and validated subject values. Owned CRM privacy/export state must reflect current rows; no WordPress object API exists.
		return '' === $wpdb->last_error && null !== $count ? (int) $count : null;
	}

	private static function read_rows( array $category, int $limit, int $offset ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Projection, joins, predicate and ordering come only from the fixed categories() map; values use placeholders.
		$sql = $wpdb->prepare( 'SELECT ' . $category['select'] . ' FROM ' . $category['from'] . ' WHERE ' . $category['where'] . ' ORDER BY ' . $category['order'] . ' ASC LIMIT %d OFFSET %d', ...array_merge( $category['args'], array( $limit, $offset ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Projection, joins, predicate and ordering come only from the fixed categories() map; values use placeholders. SQL fragments come only from the fixed categories() map; variadic subject/pagination values fill its placeholders.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Projection, joins, predicate and ordering come only from the fixed categories() map; values use placeholders. SQL fragments come only from the fixed categories() map; variadic subject/pagination values fill its placeholders. Prepared immediately above from fixed category SQL and validated subject values.
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Projection, joins, predicate and ordering come only from the fixed categories() map; values use placeholders. SQL fragments come only from the fixed categories() map; variadic subject/pagination values fill its placeholders. Prepared immediately above from fixed category SQL and validated subject values. Owned CRM privacy/export state must reflect current rows; no WordPress object API exists.
		return '' === $wpdb->last_error && is_array( $rows ) ? $rows : null;
	}

	private static function item( string $category, array $row, $view_id ): array {
		if ( 'views' === $category ) {
			$fields = array( 'Name' => $row['name'] );
			foreach ( $row['definition'] as $key => $value ) {
				if ( '' !== (string) $value ) {
					$fields[ ucwords( str_replace( '_', ' ', $key ) ) ] = $value;
				}
			}
			$id = $view_id;
		} else {
			$id = $row['id'];
			$fields = self::fields( $category, $row );
		}
		$labels = array(
			'profile' => __( 'Customer Intelligence profile', 'yoohw-customer-intelligence' ),
			'notes' => __( 'Customer note', 'yoohw-customer-intelligence' ),
			'tasks' => __( 'Customer task', 'yoohw-customer-intelligence' ),
			'events' => __( 'Customer activity', 'yoohw-customer-intelligence' ),
			'tags' => __( 'Customer tag', 'yoohw-customer-intelligence' ),
			'segments' => __( 'Customer static segment', 'yoohw-customer-intelligence' ),
			'views' => __( 'Personal saved customer view', 'yoohw-customer-intelligence' ),
		);
		$data = array();
		foreach ( $fields as $label => $value ) {
			if ( null === $value || '' === (string) $value || is_serialized( $value ) ) {
				continue;
			}
			$data[] = array( 'name' => self::translated_label( $label ), 'value' => self::plain_text( (string) $value ) );
		}
		return array( 'group_id' => 'yoohw-cos-' . $category, 'group_label' => $labels[ $category ], 'item_id' => 'yoohw-cos-' . $category . '-' . $id, 'data' => $data );
	}

	/** Register fixed export field labels for extraction by translation tooling. */
	private static function translated_label( string $label ): string {
		static $translations_by_locale = array();
		$locale = get_locale();
		if ( ! isset( $translations_by_locale[ $locale ] ) ) {
			$translations_by_locale[ $locale ] = array(
				'Archive reason' => __( 'Archive reason', 'yoohw-customer-intelligence' ),
				'Archived date' => __( 'Archived date', 'yoohw-customer-intelligence' ),
				'Available loyalty points' => __( 'Available loyalty points', 'yoohw-customer-intelligence' ),
				'Average order value' => __( 'Average order value', 'yoohw-customer-intelligence' ),
				'Completion date' => __( 'Completion date', 'yoohw-customer-intelligence' ),
				'Created date' => __( 'Created date', 'yoohw-customer-intelligence' ),
				'Customer Intelligence customer ID' => __( 'Customer Intelligence customer ID', 'yoohw-customer-intelligence' ),
				'Customer status' => __( 'Customer status', 'yoohw-customer-intelligence' ),
				'Description' => __( 'Description', 'yoohw-customer-intelligence' ),
				'Display name' => __( 'Display name', 'yoohw-customer-intelligence' ),
				'Due date' => __( 'Due date', 'yoohw-customer-intelligence' ),
				'Earned loyalty points' => __( 'Earned loyalty points', 'yoohw-customer-intelligence' ),
				'Email' => __( 'Email', 'yoohw-customer-intelligence' ),
				'Event type' => __( 'Event type', 'yoohw-customer-intelligence' ),
				'First name' => __( 'First name', 'yoohw-customer-intelligence' ),
				'First recognized order ID' => __( 'First recognized order ID', 'yoohw-customer-intelligence' ),
				'First recognized order date' => __( 'First recognized order date', 'yoohw-customer-intelligence' ),
				'Last activity date' => __( 'Last activity date', 'yoohw-customer-intelligence' ),
				'Last name' => __( 'Last name', 'yoohw-customer-intelligence' ),
				'Last recognized order ID' => __( 'Last recognized order ID', 'yoohw-customer-intelligence' ),
				'Last recognized order date' => __( 'Last recognized order date', 'yoohw-customer-intelligence' ),
				'Lifecycle stage' => __( 'Lifecycle stage', 'yoohw-customer-intelligence' ),
				'Loyalty level' => __( 'Loyalty level', 'yoohw-customer-intelligence' ),
				'Loyalty score' => __( 'Loyalty score', 'yoohw-customer-intelligence' ),
				'Membership date' => __( 'Membership date', 'yoohw-customer-intelligence' ),
				'Monetary state' => __( 'Monetary state', 'yoohw-customer-intelligence' ),
				'Name' => __( 'Name', 'yoohw-customer-intelligence' ),
				'Note content' => __( 'Note content', 'yoohw-customer-intelligence' ),
				'Note type' => __( 'Note type', 'yoohw-customer-intelligence' ),
				'Object reference' => __( 'Object reference', 'yoohw-customer-intelligence' ),
				'Object type' => __( 'Object type', 'yoohw-customer-intelligence' ),
				'Phone' => __( 'Phone', 'yoohw-customer-intelligence' ),
				'Priority' => __( 'Priority', 'yoohw-customer-intelligence' ),
				'RFM frequency' => __( 'RFM frequency', 'yoohw-customer-intelligence' ),
				'RFM monetary' => __( 'RFM monetary', 'yoohw-customer-intelligence' ),
				'RFM recency' => __( 'RFM recency', 'yoohw-customer-intelligence' ),
				'Recognized lifetime order count' => __( 'Recognized lifetime order count', 'yoohw-customer-intelligence' ),
				'Recorded currency' => __( 'Recorded currency', 'yoohw-customer-intelligence' ),
				'Related WooCommerce order ID' => __( 'Related WooCommerce order ID', 'yoohw-customer-intelligence' ),
				'Risk score' => __( 'Risk score', 'yoohw-customer-intelligence' ),
				'Severity' => __( 'Severity', 'yoohw-customer-intelligence' ),
				'Source' => __( 'Source', 'yoohw-customer-intelligence' ),
				'Static segment name' => __( 'Static segment name', 'yoohw-customer-intelligence' ),
				'Status' => __( 'Status', 'yoohw-customer-intelligence' ),
				'Tag name' => __( 'Tag name', 'yoohw-customer-intelligence' ),
				'Title' => __( 'Title', 'yoohw-customer-intelligence' ),
				'Total spent' => __( 'Total spent', 'yoohw-customer-intelligence' ),
				'Trust score' => __( 'Trust score', 'yoohw-customer-intelligence' ),
				'Updated date' => __( 'Updated date', 'yoohw-customer-intelligence' ),
				'Value tier' => __( 'Value tier', 'yoohw-customer-intelligence' ),
				'Visibility' => __( 'Visibility', 'yoohw-customer-intelligence' ),
				'WordPress user ID' => __( 'WordPress user ID', 'yoohw-customer-intelligence' ),
				// Saved View definition keys use the existing ucwords() display form.
				'S' => __( 'S', 'yoohw-customer-intelligence' ),
				'Customer Tag' => __( 'Customer Tag', 'yoohw-customer-intelligence' ),
				'Customer Segment' => __( 'Customer Segment', 'yoohw-customer-intelligence' ),
				'Customer Status' => __( 'Customer Status', 'yoohw-customer-intelligence' ),
				'Vip Status' => __( 'Vip Status', 'yoohw-customer-intelligence' ),
				'Risk Level' => __( 'Risk Level', 'yoohw-customer-intelligence' ),
				'Customer Cohort' => __( 'Customer Cohort', 'yoohw-customer-intelligence' ),
				'Customer Attention' => __( 'Customer Attention', 'yoohw-customer-intelligence' ),
				'Rfm Recency Max Days' => __( 'Rfm Recency Max Days', 'yoohw-customer-intelligence' ),
				'Rfm Frequency Min' => __( 'Rfm Frequency Min', 'yoohw-customer-intelligence' ),
				'Rfm Monetary Min' => __( 'Rfm Monetary Min', 'yoohw-customer-intelligence' ),
				'Loyalty Level' => __( 'Loyalty Level', 'yoohw-customer-intelligence' ),
				'Loyalty Score' => __( 'Loyalty Score', 'yoohw-customer-intelligence' ),
				'Lifecycle Stage' => __( 'Lifecycle Stage', 'yoohw-customer-intelligence' ),
				'Customer View' => __( 'Customer View', 'yoohw-customer-intelligence' ),
				'Orderby' => __( 'Orderby', 'yoohw-customer-intelligence' ),
				'Order' => __( 'Order', 'yoohw-customer-intelligence' ),
			);
		}
		return $translations_by_locale[ $locale ][ $label ] ?? $label;
	}

	private static function fields( string $category, array $row ): array {
		$customer_id = $row['customer_id'] ?? null;
		unset( $row['customer_id'] );
		if ( 'profile' === $category ) {
			$row = YoOhw_COS_Intelligence::safe_customer_decisions( $row );
			$fields = array( 'Customer Intelligence customer ID' => $row['id'] ?? null );
			$fields['WordPress user ID'] = $row['wp_user_id'];
			$map = array( 'email' => 'Email', 'phone' => 'Phone', 'first_name' => 'First name', 'last_name' => 'Last name', 'display_name' => 'Display name', 'customer_status' => 'Customer status', 'lifecycle_stage' => 'Lifecycle stage', 'vip_status' => 'Value tier', 'risk_score' => 'Risk score', 'trust_score' => 'Trust score', 'archived_at' => 'Archived date', 'archive_reason' => 'Archive reason', 'created_at' => 'Created date', 'updated_at' => 'Updated date', 'first_order_date' => 'First recognized order date', 'first_order_id' => 'First recognized order ID', 'last_order_date' => 'Last recognized order date', 'last_order_id' => 'Last recognized order ID', 'last_activity_date' => 'Last activity date', 'total_orders' => 'Recognized lifetime order count', 'money_state' => 'Monetary state', 'money_currency' => 'Recorded currency', 'loyalty_score' => 'Loyalty score', 'loyalty_level' => 'Loyalty level', 'available_points' => 'Available loyalty points', 'earned_points' => 'Earned loyalty points' );
			foreach ( $map as $key => $label ) { $fields[ $label ] = $row[ $key ] ?? null; }
			$availability = YoOhw_COS_Commerce_Metrics_Policy::availability( $row );
			$fields['Monetary availability reason'] = $availability['reason'];
			$fields['Currency readiness'] = $availability['site_state'];
			$fields['Amount available'] = $availability['amount_available'] ? '1' : '0';
			$fields['Available numeric total spent'] = $availability['amount_available'] ? ( 'none' === $availability['reason'] ? '0' : (string) $row['total_spent'] ) : '';
			$fields['Available numeric average order value'] = $availability['amount_available'] ? ( 'none' === $availability['reason'] ? '0' : (string) $row['average_order_value'] ) : '';
			$fields['Total spent'] = self::money( $row, 'total_spent' );
			$fields['Average order value'] = self::money( $row, 'average_order_value' );
			$days = YoOhw_COS_RFM::recency_days( $row );
			$fields['RFM recency'] = null === $days ? __( 'Unavailable', 'yoohw-customer-intelligence' ) : $days . ' days';
			$fields['RFM frequency'] = (int) $row['total_orders'] . ' recognized orders';
			$fields['RFM monetary'] = self::money( $row, 'total_spent' );
			return $fields;
		}
		$fields = array( 'Customer Intelligence customer ID' => $customer_id );
		$maps = array(
			'notes' => array( 'note_type' => 'Note type', 'visibility' => 'Visibility', 'note_content' => 'Note content', 'created_at' => 'Created date', 'updated_at' => 'Updated date' ),
			'tasks' => array( 'title' => 'Title', 'description' => 'Description', 'status' => 'Status', 'priority' => 'Priority', 'due_date' => 'Due date', 'completed_at' => 'Completion date', 'created_at' => 'Created date', 'updated_at' => 'Updated date', 'order_id' => 'Related WooCommerce order ID' ),
			'events' => array( 'event_type' => 'Event type', 'event_source' => 'Source', 'severity' => 'Severity', 'description' => 'Description', 'created_at' => 'Created date', 'object_type' => 'Object type', 'object_id' => 'Object reference' ),
			'tags' => array( 'name' => 'Tag name', 'created_at' => 'Membership date' ),
			'segments' => array( 'name' => 'Static segment name', 'created_at' => 'Membership date' ),
		);
		foreach ( $maps[ $category ] as $key => $label ) { $fields[ $label ] = $row[ $key ] ?? null; }
		if ( 'events' === $category && ! in_array( $row['object_type'] ?? '', array( 'order', 'note', 'task', 'tag', 'segment' ), true ) ) {
			unset( $fields['Object type'], $fields['Object reference'] );
		}
		return $fields;
	}

	private static function plain_text( string $value ): string {
		return wp_strip_all_tags( $value, true );
	}

	private static function money( array $row, string $key ): string {
		if ( 'none' === YoOhw_COS_Commerce_Metrics_Policy::availability( $row )['reason'] ) {
			return '0 (no recognized orders)';
		}
		if ( ! YoOhw_COS_Commerce_Metrics_Policy::money_is_comparable( $row ) ) {
			return ''; // Unavailable numeric values stay empty; machine reason is exported separately.
		}
		return $row[ $key ] . ' ' . $row['money_currency'];
	}
}
