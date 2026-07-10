<?php
defined( 'ABSPATH' ) || exit;

final class YoOhw_COS_Blacklist_Manager_Integration {

	private const EVENT_SOURCE = 'wc_blacklist_manager';

	public static function init(): void {
		if ( ! self::is_core_available() ) {
			return;
		}

		add_action( 'wc_blacklist_manager_order_suspected', array( __CLASS__, 'handle_order_suspected' ), 10, 2 );
		add_action( 'wc_blacklist_manager_order_blocked', array( __CLASS__, 'handle_order_blocked' ), 10, 2 );
		add_action( 'wc_blacklist_manager_order_blacklist_removed', array( __CLASS__, 'handle_order_blacklist_removed' ), 10, 2 );
		add_action( 'wc_blacklist_manager_order_suspect_detected', array( __CLASS__, 'handle_order_suspect_detected' ), 10, 2 );
		add_action( 'wc_blacklist_manager_dashboard_row_changed', array( __CLASS__, 'handle_dashboard_row_changed' ), 10, 4 );

		add_filter( 'yoohw_cos_customer_risk_score', array( __CLASS__, 'apply_customer_risk_score' ), 10, 2 );
		add_filter( 'yoohw_cos_customer_risk_factors', array( __CLASS__, 'apply_customer_risk_factors' ), 10, 2 );
	}

	public static function is_active(): bool {
		return self::is_core_available();
	}

	private static function is_core_available(): bool {
		return defined( 'WC_BLACKLIST_MANAGER_VERSION' ) || class_exists( 'WC_Blacklist_Manager' );
	}

	public static function handle_order_suspected( $payload, $order = null ): void {
		self::record_order_signal(
			'blacklist_suspect',
			'warning',
			__( 'Blacklist Manager marked this order as suspect.', 'yoohw-customer-intelligence' ),
			$payload,
			$order
		);
	}

	public static function handle_order_blocked( $payload, $order = null ): void {
		self::record_order_signal(
			'blacklist_blocked',
			'error',
			__( 'Blacklist Manager blocked this order/customer.', 'yoohw-customer-intelligence' ),
			$payload,
			$order
		);
	}

	public static function handle_order_blacklist_removed( $payload, $order = null ): void {
		self::record_order_signal(
			'blacklist_removed',
			'success',
			__( 'Blacklist Manager removed this order/customer from blacklist records.', 'yoohw-customer-intelligence' ),
			$payload,
			$order
		);
	}

	public static function handle_order_suspect_detected( $payload, $order = null ): void {
		self::record_order_signal(
			'blacklist_match_detected',
			'warning',
			__( 'Blacklist Manager detected a suspect-list match for this order.', 'yoohw-customer-intelligence' ),
			$payload,
			$order
		);
	}

	public static function handle_dashboard_row_changed( $event, $id, $row = array(), $record_type = 'main' ): void {
		$row         = is_array( $row ) ? $row : array();
		$event       = sanitize_key( (string) $event );
		$id          = absint( $id );
		$record_type = sanitize_key( (string) $record_type );

		if ( $id <= 0 ) {
			return;
		}

		$is_deleted = 'deleted' === $event;
		$is_blocked = ! empty( $row['is_blocked'] );
		$status     = $is_deleted ? 'removed' : ( $is_blocked ? 'blocked' : 'suspect' );
		$event_type = 'removed' === $status ? 'blacklist_removed' : ( 'blocked' === $status ? 'blacklist_blocked' : 'blacklist_suspect' );
		$severity   = 'removed' === $status ? 'success' : ( 'blocked' === $status ? 'error' : 'warning' );

		$order_id = absint( $row['order_id'] ?? 0 );
		$order    = $order_id > 0 && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

		$payload = array(
			'order_id'       => $order_id,
			'blacklist_ids'  => 'address' === $record_type ? array() : array( $id ),
			'address_ids'    => 'address' === $record_type ? array( $id ) : array(),
			'action'         => $event,
			'status'         => $status,
			'reason_code'    => sanitize_key( (string) ( $row['reason_code'] ?? '' ) ),
			'description'    => sanitize_textarea_field( (string) ( $row['description'] ?? ( $row['notes'] ?? '' ) ) ),
			'matched_fields' => self::matched_fields_from_row( $row, $record_type ),
			'source'         => 'dashboard',
			'actor_user_id'  => get_current_user_id(),
			'wp_user_id'     => absint( $row['wp_user_id'] ?? 0 ),
			'email'          => sanitize_email( (string) ( $row['email_address'] ?? '' ) ),
			'phone'          => sanitize_text_field( (string) ( $row['phone_number'] ?? '' ) ),
		);

		$object_type = 'address' === $record_type ? 'blacklist_address' : 'blacklist_entry';
		$description = self::dashboard_event_description( $status, $record_type );

		self::record_signal_event( $event_type, $severity, $description, $payload, $order, $object_type, $id );
	}

	public static function backfill_legacy_signals( int $limit = 300, int $page = 1 ): array {
		global $wpdb;

		$limit  = min( 500, max( 1, absint( $limit ) ) );
		$page   = max( 1, absint( $page ) );
		$offset = ( $page - 1 ) * $limit;

		$result = array(
			'scanned'   => 0,
			'processed' => 0,
			'skipped'   => 0,
			'has_more'  => false,
			'next_page' => $page,
		);

		$blacklist_table = $wpdb->prefix . 'wc_blacklist';
		$blacklist_rows  = array();

		if ( self::table_exists( $blacklist_table ) ) {
			$blacklist_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, first_name, last_name, phone_number, email_address, ip_address, domain, device_id, order_id, reason_code, description, date_added, is_blocked
					FROM %i
					ORDER BY id ASC
					LIMIT %d OFFSET %d",
					$blacklist_table,
					$limit,
					$offset
				),
				ARRAY_A
			);

			$blacklist_rows = is_array( $blacklist_rows ) ? $blacklist_rows : array();

			foreach ( $blacklist_rows as $row ) {
				$result['scanned']++;

				if ( self::backfill_blacklist_row( $row ) ) {
					$result['processed']++;
				} else {
					$result['skipped']++;
				}
			}
		}

		$log_result = self::backfill_detection_log_rows( $limit, $offset );

		$result['scanned']   += absint( $log_result['scanned'] ?? 0 );
		$result['processed'] += absint( $log_result['processed'] ?? 0 );
		$result['skipped']   += absint( $log_result['skipped'] ?? 0 );
		$result['has_more']   = count( $blacklist_rows ) >= $limit || ! empty( $log_result['has_more'] );
		$result['next_page']  = $result['has_more'] ? $page + 1 : $page;

		return $result;
	}

	public static function get_customer_blacklist_status( int $customer_id ): array {
		$customer_id = absint( $customer_id );

		if ( $customer_id <= 0 ) {
			return array();
		}

		$events = YoOhw_COS_Events::get_customer_events(
			$customer_id,
			array(
				'limit' => 30,
			)
		);

		foreach ( $events as $event ) {
			if ( self::EVENT_SOURCE !== (string) ( $event['event_source'] ?? '' ) ) {
				continue;
			}

			$event_type = sanitize_key( (string) ( $event['event_type'] ?? '' ) );

			if ( 'blacklist_blocked' === $event_type ) {
				return array(
					'status' => 'blocked',
					'label'  => __( 'Blacklist: Blocked', 'yoohw-customer-intelligence' ),
				);
			}

			if ( 'blacklist_removed' === $event_type ) {
				return array(
					'status' => 'cleared',
					'label'  => __( 'Blacklist: Cleared', 'yoohw-customer-intelligence' ),
				);
			}

			if ( in_array( $event_type, array( 'blacklist_suspect', 'blacklist_match_detected' ), true ) ) {
				return array(
					'status' => 'suspect',
					'label'  => __( 'Blacklist: Suspect', 'yoohw-customer-intelligence' ),
				);
			}
		}

		return array();
	}

	private static function record_order_signal( string $event_type, string $severity, string $description, $payload, $order = null ): void {
		$payload = self::normalize_payload( $payload );
		$order   = self::resolve_order( $order, absint( $payload['order_id'] ?? 0 ) );

		if ( $order instanceof WC_Order ) {
			$payload['order_id'] = absint( $order->get_id() );
			$description         = self::append_order_number( $description, $order );
		}

		self::record_signal_event( $event_type, $severity, $description, $payload, $order, 'order', absint( $payload['order_id'] ?? 0 ) );
	}

	private static function record_signal_event(
		string $event_type,
		string $severity,
		string $description,
		array $payload,
		$order,
		string $object_type,
		int $object_id,
		array $options = array()
	): bool {
		$customer_id = self::resolve_customer_id( $payload, $order );
		$wp_user_id  = self::resolve_wp_user_id( $payload, $order );
		$object_id   = absint( $object_id );

		if ( ! empty( $options['require_customer'] ) && $customer_id <= 0 ) {
			return false;
		}

		if (
			! empty( $options['idempotent'] )
			&& $object_id > 0
			&& YoOhw_COS_Events::event_exists(
				$event_type,
				$object_type,
				$object_id,
				$customer_id
			)
		) {
			if ( $customer_id > 0 ) {
				self::refresh_customer_risk_score( $customer_id );
			}

			return false;
		}

		$event_args = array(
			'customer_id'  => $customer_id ?: null,
			'wp_user_id'   => $wp_user_id ?: null,
			'event_type'   => $event_type,
			'event_source' => self::EVENT_SOURCE,
			'severity'     => $severity,
			'object_type'  => $object_type,
			'object_id'    => $object_id ?: null,
			'description'  => $description,
			'metadata'     => self::event_metadata( $payload ),
		);

		$created_at = self::normalize_created_at( $options['created_at'] ?? '' );

		if ( '' !== $created_at ) {
			$event_args['created_at'] = $created_at;
		}

		$event_id = YoOhw_COS_Events::record(
			$event_args
		);

		if ( $event_id > 0 && $customer_id > 0 ) {
			self::refresh_customer_risk_score( $customer_id );
		}

		return $event_id > 0;
	}

	private static function normalize_payload( $payload ): array {
		$payload = is_array( $payload ) ? $payload : array();

		return array(
			'order_id'       => absint( $payload['order_id'] ?? 0 ),
			'blacklist_ids'  => self::sanitize_id_list( $payload['blacklist_ids'] ?? ( $payload['blacklist_id'] ?? array() ) ),
			'address_ids'    => self::sanitize_id_list( $payload['address_ids'] ?? ( $payload['address_id'] ?? array() ) ),
			'action'         => sanitize_key( (string) ( $payload['action'] ?? '' ) ),
			'status'         => sanitize_key( (string) ( $payload['status'] ?? '' ) ),
			'reason_code'    => sanitize_key( (string) ( $payload['reason_code'] ?? '' ) ),
			'description'    => sanitize_textarea_field( (string) ( $payload['description'] ?? '' ) ),
			'matched_fields' => self::sanitize_key_list( $payload['matched_fields'] ?? array() ),
			'source'         => sanitize_key( (string) ( $payload['source'] ?? '' ) ),
			'actor_user_id'  => absint( $payload['actor_user_id'] ?? 0 ),
			'wp_user_id'     => absint( $payload['wp_user_id'] ?? 0 ),
			'email'          => sanitize_email( (string) ( $payload['email'] ?? '' ) ),
			'phone'          => sanitize_text_field( (string) ( $payload['phone'] ?? '' ) ),
			'details'        => sanitize_textarea_field( (string) ( $payload['details'] ?? '' ) ),
		);
	}

	private static function event_metadata( array $payload ): array {
		return array_filter(
			array(
				'order_id'       => absint( $payload['order_id'] ?? 0 ),
				'blacklist_ids'  => self::sanitize_id_list( $payload['blacklist_ids'] ?? array() ),
				'address_ids'    => self::sanitize_id_list( $payload['address_ids'] ?? array() ),
				'action'         => sanitize_key( (string) ( $payload['action'] ?? '' ) ),
				'status'         => sanitize_key( (string) ( $payload['status'] ?? '' ) ),
				'reason_code'    => sanitize_key( (string) ( $payload['reason_code'] ?? '' ) ),
				'description'    => sanitize_textarea_field( (string) ( $payload['description'] ?? '' ) ),
				'matched_fields' => self::sanitize_key_list( $payload['matched_fields'] ?? array() ),
				'source'         => sanitize_key( (string) ( $payload['source'] ?? '' ) ),
				'actor_user_id'  => absint( $payload['actor_user_id'] ?? 0 ),
			),
			static function ( $value ) {
				return ! ( '' === $value || 0 === $value || array() === $value || null === $value );
			}
		);
	}

	private static function resolve_order( $order, int $order_id ) {
		if ( $order instanceof WC_Order ) {
			return $order;
		}

		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$order = wc_get_order( $order_id );

		return $order instanceof WC_Order ? $order : null;
	}

	private static function resolve_customer_id( array $payload, $order = null ): int {
		if ( $order instanceof WC_Order ) {
			$customer_id = YoOhw_COS_Customers::find_customer_id_from_order(
				$order,
				absint( $order->get_customer_id() ),
				sanitize_email( $order->get_billing_email() ),
				sanitize_text_field( $order->get_billing_phone() )
			);

			if ( $customer_id > 0 ) {
				return $customer_id;
			}

			return YoOhw_COS_Customers::sync_from_order( $order );
		}

		return YoOhw_COS_Customers::find_customer_id(
			array(
				'wp_user_id' => absint( $payload['wp_user_id'] ?? 0 ),
				'email'      => sanitize_email( (string) ( $payload['email'] ?? '' ) ),
				'phone'      => sanitize_text_field( (string) ( $payload['phone'] ?? '' ) ),
			)
		);
	}

	private static function resolve_wp_user_id( array $payload, $order = null ): int {
		if ( $order instanceof WC_Order ) {
			return absint( $order->get_customer_id() );
		}

		return absint( $payload['wp_user_id'] ?? 0 );
	}

	private static function append_order_number( string $description, WC_Order $order ): string {
		return sprintf(
			/* translators: %s: order number. */
			__( '%1$s Order #%2$s.', 'yoohw-customer-intelligence' ),
			$description,
			$order->get_order_number()
		);
	}

	private static function dashboard_event_description( string $status, string $record_type ): string {
		if ( 'removed' === $status ) {
			return __( 'Blacklist Manager removed a dashboard blacklist record.', 'yoohw-customer-intelligence' );
		}

		if ( 'blocked' === $status ) {
			return 'address' === $record_type
				? __( 'Blacklist Manager dashboard marked an address as blocked.', 'yoohw-customer-intelligence' )
				: __( 'Blacklist Manager dashboard marked a record as blocked.', 'yoohw-customer-intelligence' );
		}

		return 'address' === $record_type
			? __( 'Blacklist Manager dashboard marked an address as suspect.', 'yoohw-customer-intelligence' )
			: __( 'Blacklist Manager dashboard marked a record as suspect.', 'yoohw-customer-intelligence' );
	}

	private static function matched_fields_from_row( array $row, string $record_type ): array {
		$fields = array();

		if ( 'address' === $record_type || ! empty( $row['address_display'] ) || ! empty( $row['customer_address'] ) ) {
			$fields[] = 'address';
		}

		foreach ( array(
			'phone_number'  => 'phone',
			'email_address' => 'email',
			'ip_address'    => 'ip',
			'domain'        => 'domain',
			'device_id'     => 'device',
			'first_name'    => 'name',
			'last_name'     => 'name',
		) as $key => $field ) {
			if ( ! empty( $row[ $key ] ) ) {
				$fields[] = $field;
			}
		}

		return array_values( array_unique( $fields ) );
	}

	private static function backfill_blacklist_row( array $row ): bool {
		$blacklist_id = absint( $row['id'] ?? 0 );

		if ( $blacklist_id <= 0 ) {
			return false;
		}

		$is_blocked = ! empty( $row['is_blocked'] );
		$order_id   = absint( $row['order_id'] ?? 0 );
		$order      = $order_id > 0 ? self::resolve_order( null, $order_id ) : null;
		$status     = $is_blocked ? 'blocked' : 'suspect';
		$event_type = $is_blocked ? 'blacklist_blocked' : 'blacklist_suspect';
		$severity   = $is_blocked ? 'error' : 'warning';

		$payload = array(
			'order_id'       => $order_id,
			'blacklist_ids'  => array( $blacklist_id ),
			'address_ids'    => array(),
			'action'         => 'backfill',
			'status'         => $status,
			'reason_code'    => sanitize_key( (string) ( $row['reason_code'] ?? '' ) ),
			'description'    => sanitize_textarea_field( (string) ( $row['description'] ?? '' ) ),
			'matched_fields' => self::matched_fields_from_row( $row, 'main' ),
			'source'         => 'backfill',
			'actor_user_id'  => 0,
			'email'          => sanitize_email( (string) ( $row['email_address'] ?? '' ) ),
			'phone'          => sanitize_text_field( (string) ( $row['phone_number'] ?? '' ) ),
		);

		$description = $is_blocked
			? __( 'Backfilled Blacklist Manager blocked signal.', 'yoohw-customer-intelligence' )
			: __( 'Backfilled Blacklist Manager suspect signal.', 'yoohw-customer-intelligence' );

		if ( $order instanceof WC_Order ) {
			$description = self::append_order_number( $description, $order );
		}

		return self::record_signal_event(
			$event_type,
			$severity,
			$description,
			$payload,
			$order,
			'blacklist_entry',
			$blacklist_id,
			array(
				'idempotent'       => true,
				'require_customer' => true,
				'created_at'       => $row['date_added'] ?? '',
			)
		);
	}

	private static function backfill_detection_log_rows( int $limit, int $offset ): array {
		global $wpdb;

		$result = array(
			'scanned'   => 0,
			'processed' => 0,
			'skipped'   => 0,
			'has_more'  => false,
		);

		$table = $wpdb->prefix . 'wc_blacklist_detection_log';

		if ( ! self::table_exists( $table ) ) {
			return $result;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, `timestamp`, type, source, action, details
				FROM %i
				WHERE source LIKE %s
				ORDER BY id ASC
				LIMIT %d OFFSET %d",
				$table,
				'woo_order_%',
				$limit,
				$offset
			),
			ARRAY_A
		);

		$rows = is_array( $rows ) ? $rows : array();

		foreach ( $rows as $row ) {
			$result['scanned']++;

			if ( self::backfill_detection_log_row( $row ) ) {
				$result['processed']++;
			} else {
				$result['skipped']++;
			}
		}

		$result['has_more'] = count( $rows ) >= $limit;

		return $result;
	}

	private static function backfill_detection_log_row( array $row ): bool {
		$log_id = absint( $row['id'] ?? 0 );
		$source = sanitize_text_field( (string) ( $row['source'] ?? '' ) );

		if ( $log_id <= 0 || ! preg_match( '/^woo_order_(\d+)$/', $source, $matches ) ) {
			return false;
		}

		$order_id = absint( $matches[1] ?? 0 );
		$order    = $order_id > 0 ? self::resolve_order( null, $order_id ) : null;

		if ( ! $order instanceof WC_Order ) {
			return false;
		}

		$action = sanitize_key( (string) ( $row['action'] ?? '' ) );
		$type   = sanitize_key( (string) ( $row['type'] ?? '' ) );

		if ( in_array( $action, array( 'block', 'cancel' ), true ) ) {
			$event_type = 'blacklist_blocked';
			$severity   = 'error';
			$status     = 'blocked';
		} elseif ( in_array( $action, array( 'remove', 'revoke', 'clear', 'cleared' ), true ) ) {
			$event_type = 'blacklist_removed';
			$severity   = 'success';
			$status     = 'removed';
		} elseif ( 'suspect' === $action ) {
			$event_type = 'bot' === $type ? 'blacklist_match_detected' : 'blacklist_suspect';
			$severity   = 'warning';
			$status     = 'suspect';
		} else {
			return false;
		}

		$payload = array(
			'order_id'       => $order_id,
			'blacklist_ids'  => array(),
			'address_ids'    => array(),
			'action'         => 'backfill_' . $action,
			'status'         => $status,
			'reason_code'    => $action,
			'description'    => '',
			'matched_fields' => self::matched_fields_from_detection_details( (string) ( $row['details'] ?? '' ) ),
			'source'         => 'detection_log',
			'actor_user_id'  => 0,
		);

		$description = self::backfill_detection_log_description( $event_type, $order );

		return self::record_signal_event(
			$event_type,
			$severity,
			$description,
			$payload,
			$order,
			'blacklist_detection_log',
			$log_id,
			array(
				'idempotent'       => true,
				'require_customer' => true,
				'created_at'       => $row['timestamp'] ?? '',
			)
		);
	}

	private static function backfill_detection_log_description( string $event_type, WC_Order $order ): string {
		if ( 'blacklist_blocked' === $event_type ) {
			$description = __( 'Backfilled Blacklist Manager blocked activity.', 'yoohw-customer-intelligence' );
		} elseif ( 'blacklist_removed' === $event_type ) {
			$description = __( 'Backfilled Blacklist Manager cleared activity.', 'yoohw-customer-intelligence' );
		} elseif ( 'blacklist_match_detected' === $event_type ) {
			$description = __( 'Backfilled Blacklist Manager match activity.', 'yoohw-customer-intelligence' );
		} else {
			$description = __( 'Backfilled Blacklist Manager suspect activity.', 'yoohw-customer-intelligence' );
		}

		return self::append_order_number( $description, $order );
	}

	private static function matched_fields_from_detection_details( string $details ): array {
		$details = strtolower( $details );
		$fields  = array();

		foreach ( array(
			'phone'    => 'phone',
			'email'    => 'email',
			'ip'       => 'ip',
			'device'   => 'device',
			'name'     => 'name',
			'address'  => 'address',
			'postcode' => 'postcode',
			'state'    => 'state',
			'domain'   => 'domain',
		) as $needle => $field ) {
			if ( false !== strpos( $details, $needle ) ) {
				$fields[] = $field;
			}
		}

		return array_values( array_unique( array_filter( $fields ) ) );
	}

	public static function apply_customer_risk_score( $score, array $customer ): float {
		$signal = self::get_current_customer_signal( $customer );

		if ( empty( $signal ) ) {
			return (float) $score;
		}

		return (float) $score + (float) ( $signal['impact'] ?? 0 );
	}

	public static function apply_customer_risk_factors( $factors, array $customer ): array {
		$factors = is_array( $factors ) ? $factors : array();
		$signal  = self::get_current_customer_signal( $customer );

		if ( empty( $signal ) || empty( $signal['impact'] ) ) {
			return $factors;
		}

		$factors[] = array(
			'label'       => $signal['label'],
			'impact'      => (int) $signal['impact'],
			'description' => $signal['description'],
		);

		return $factors;
	}

	private static function get_current_customer_signal( array $customer ): array {
		$customer_id = absint( $customer['id'] ?? 0 );

		if ( $customer_id <= 0 ) {
			return array();
		}

		$events = YoOhw_COS_Events::get_customer_events(
			$customer_id,
			array(
				'limit' => 30,
			)
		);

		foreach ( $events as $event ) {
			if ( self::EVENT_SOURCE !== (string) ( $event['event_source'] ?? '' ) ) {
				continue;
			}

			$event_type = sanitize_key( (string) ( $event['event_type'] ?? '' ) );

			if ( 'blacklist_removed' === $event_type ) {
				return array();
			}

			if ( 'blacklist_blocked' === $event_type ) {
				return array(
					'impact'      => 70,
					'label'       => __( 'Blacklist Manager: blocked', 'yoohw-customer-intelligence' ),
					'description' => __( 'This customer has a recent blocked signal from Blacklist Manager.', 'yoohw-customer-intelligence' ),
				);
			}

			if ( 'blacklist_suspect' === $event_type ) {
				return array(
					'impact'      => 30,
					'label'       => __( 'Blacklist Manager: suspect', 'yoohw-customer-intelligence' ),
					'description' => __( 'This customer has a recent suspect signal from Blacklist Manager.', 'yoohw-customer-intelligence' ),
				);
			}

			if ( 'blacklist_match_detected' === $event_type ) {
				return array(
					'impact'      => 25,
					'label'       => __( 'Blacklist Manager: match', 'yoohw-customer-intelligence' ),
					'description' => __( 'A recent order matched a suspect signal in Blacklist Manager.', 'yoohw-customer-intelligence' ),
				);
			}
		}

		return array();
	}

	private static function refresh_customer_risk_score( int $customer_id ): void {
		$customer = YoOhw_COS_Customers::get_customer( $customer_id );

		if ( empty( $customer ) ) {
			return;
		}

		YoOhw_COS_Customers::update_customer(
			$customer_id,
			array(
				'risk_score' => YoOhw_COS_Intelligence::calculate_risk_score( $customer ),
			)
		);
	}

	private static function sanitize_id_list( $ids ): array {
		$ids = is_array( $ids ) ? $ids : array( $ids );
		$ids = array_map( 'absint', $ids );

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	private static function sanitize_key_list( $values ): array {
		$values = is_array( $values ) ? $values : array( $values );
		$values = array_map(
			static function ( $value ) {
				return sanitize_key( (string) $value );
			},
			$values
		);

		return array_values( array_unique( array_filter( $values ) ) );
	}

	private static function table_exists( string $table ): bool {
		global $wpdb;

		if ( '' === $table ) {
			return false;
		}

		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$table
			)
		);

		return $found === $table;
	}

	private static function normalize_created_at( $created_at ): string {
		$created_at = sanitize_text_field( (string) $created_at );

		if ( '' === $created_at || ! class_exists( 'YoOhw_COS_DB' ) || YoOhw_COS_DB::date_timestamp( $created_at ) <= 0 ) {
			return '';
		}

		return $created_at;
	}
}
