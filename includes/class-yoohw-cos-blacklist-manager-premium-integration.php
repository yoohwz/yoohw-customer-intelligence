<?php
defined( 'ABSPATH' ) || exit;

final class YoOhw_COS_Blacklist_Manager_Premium_Integration {

	private const EVENT_SOURCE = 'wc_blacklist_manager_premium';

	private const EVENT_ORDER_RISK = 'premium_order_risk_scored';
	private const EVENT_RULE_MATCH = 'premium_risk_rule_matched';
	private const EVENT_ANTIBOT_BLOCKED = 'premium_antibot_blocked';
	private const EVENT_ANTIBOT_WOULD_BLOCK = 'premium_antibot_would_block';
	private const EVENT_PAYMENT_ABUSE = 'premium_payment_abuse_detected';
	private const EVENT_DEVICE_SIGNAL = 'premium_device_signal_detected';
	private const EVENT_GATEWAY_FRAUD = 'premium_gateway_fraud_signal';
	private const REASSOCIATION_HOOK = 'yoohw_cos_reassociate_premium_checkout_events';
	private const REASSOCIATION_STATE_OPTION = 'yoohw_cos_premium_reassociation_state';
	private const REASSOCIATION_VERSION = 1;

	public static function init(): void {
		if ( ! self::is_premium_available() ) {
			return;
		}

		add_action( 'yobm_after_job', array( __CLASS__, 'handle_after_risk_job' ), 9, 2 );
		add_action( 'bmp_antibot_risk_failed', array( __CLASS__, 'handle_antibot_risk_failed' ), 10, 2 );
		add_action( 'bmp_antibot_risk_challenge_required', array( __CLASS__, 'handle_antibot_challenge_required' ), 10, 2 );
		add_action( 'bmp_js_proof_failed', array( __CLASS__, 'handle_js_proof_failed' ), 10, 3 );
		add_action( 'bmp_session_continuity_failed', array( __CLASS__, 'handle_session_continuity_failed' ), 10, 3 );
		add_action( 'bmp_fp_anomalies_failed', array( __CLASS__, 'handle_fp_anomalies_failed' ), 10, 2 );
		add_action( 'bmp_payment_abuse_event_recorded', array( __CLASS__, 'handle_payment_abuse_event_recorded' ), 10, 3 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'reassociate_checkout_events_for_order' ), 40, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'reassociate_checkout_events_for_order' ), 40, 1 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'reassociate_checkout_events_for_order' ), 40, 1 );
		add_action( self::REASSOCIATION_HOOK, array( __CLASS__, 'process_existing_event_reassociation' ) );

		add_filter( 'yoohw_cos_customer_risk_score', array( __CLASS__, 'apply_customer_risk_score' ), 20, 2 );
		add_filter( 'yoohw_cos_customer_risk_factors', array( __CLASS__, 'apply_customer_risk_factors' ), 20, 2 );

		self::maybe_schedule_existing_event_reassociation();
	}

	public static function is_active(): bool {
		return self::is_premium_available();
	}

	private static function is_premium_available(): bool {
		if (
			! class_exists( 'YoOhw_COS_Blacklist_Manager_Integration' )
			|| ! is_callable( array( 'YoOhw_COS_Blacklist_Manager_Integration', 'is_active' ) )
			|| ! YoOhw_COS_Blacklist_Manager_Integration::is_active()
		) {
			return false;
		}

		if ( ! defined( 'WC_BLACKLIST_MANAGER_PREMIUM_VERSION' ) ) {
			return false;
		}

		if ( function_exists( 'wc_blacklist_manager_is_premium_available' ) ) {
			return (bool) wc_blacklist_manager_is_premium_available();
		}

		if ( function_exists( 'yobmp_premium_license_active' ) ) {
			return (bool) yobmp_premium_license_active();
		}

		return class_exists( 'YOBMP_Premium_Gate' )
			&& is_callable( array( 'YOBMP_Premium_Gate', 'is_active' ) )
			&& (bool) YOBMP_Premium_Gate::is_active();
	}

	public static function handle_after_risk_job( $order_id, $job_hook ): void {
		$order_id = absint( $order_id );
		$job_hook = sanitize_key( (string) $job_hook );

		if ( $order_id <= 0 || '' === $job_hook || ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund ) {
			return;
		}

		$customer_id = self::resolve_customer_id( array(), $order );
		$wp_user_id  = absint( $order->get_customer_id() );
		$risk_score  = max( 0, (int) $order->get_meta( '_risk_score', true ) );
		$rule        = self::get_rule_for_job( $job_hook );
		$rule_score  = ! empty( $rule['meta_key'] ) ? max( 0, (int) $order->get_meta( $rule['meta_key'], true ) ) : 0;
		$rules       = self::collect_order_rule_matches( $order );

		if ( $risk_score <= 0 && empty( $rules ) ) {
			return;
		}

		$metadata = array(
			'order_id'      => $order_id,
			'job_hook'      => $job_hook,
			'risk_score'    => $risk_score,
			'matched_rules' => $rules,
		);

		if ( ! empty( $rule ) ) {
			$metadata['current_rule'] = self::format_rule_match( $job_hook, $rule, $rule_score );
		}

		self::record_event(
			array(
				'customer_id'  => $customer_id,
				'wp_user_id'   => $wp_user_id,
				'event_type'   => self::EVENT_ORDER_RISK,
				'severity'     => self::severity_for_order_risk( $risk_score ),
				'object_type'  => 'order',
				'object_id'    => $order_id,
				'description'  => sprintf(
					/* translators: 1: order number, 2: risk score. */
					__( 'Blacklist Manager Premium scored order #%1$s at %2$d risk points.', 'yoohw-customer-intelligence' ),
					$order->get_order_number(),
					$risk_score
				),
				'metadata'     => $metadata,
			)
		);

		if ( $rule_score > 0 && ! empty( $rule ) ) {
			self::record_current_rule_event( $order, $customer_id, $wp_user_id, $job_hook, $rule, $rule_score, $risk_score );
		}
	}

	public static function handle_antibot_risk_failed( $decision, $context = '' ): void {
		$payload    = self::normalize_checkout_payload( $decision, 'antibot_risk_failed', $context );
		$event_type = self::checkout_failure_event_type( $payload );

		self::record_checkout_event(
			$event_type,
			'error',
			__( 'Blacklist Manager Premium blocked checkout after anti-bot risk evaluation.', 'yoohw-customer-intelligence' ),
			$payload
		);
	}

	public static function handle_antibot_challenge_required( $decision, $context = '' ): void {
		$payload = self::normalize_checkout_payload( $decision, 'antibot_challenge_required', $context );

		self::record_checkout_event(
			self::EVENT_ANTIBOT_WOULD_BLOCK,
			'warning',
			__( 'Blacklist Manager Premium required a checkout verification challenge.', 'yoohw-customer-intelligence' ),
			$payload
		);
	}

	public static function handle_js_proof_failed( $reason, $context = '', $details = array() ): void {
		$details             = is_array( $details ) ? $details : array();
		$details['reason']   = sanitize_key( (string) $reason );
		$payload             = self::normalize_checkout_payload( $details, 'js_proof_failed', $context );
		$payload['reasons']  = array_values( array_unique( array_filter( array_merge( $payload['reasons'], array( sanitize_key( (string) $reason ) ) ) ) ) );

		self::record_checkout_event(
			self::EVENT_DEVICE_SIGNAL,
			'error',
			__( 'Blacklist Manager Premium detected a failed checkout JS proof.', 'yoohw-customer-intelligence' ),
			$payload
		);
	}

	public static function handle_session_continuity_failed( $reasons, $context = '', $details = array() ): void {
		$details            = is_array( $details ) ? $details : array();
		$payload            = self::normalize_checkout_payload( $details, 'session_continuity_failed', $context );
		$payload['reasons'] = array_values(
			array_unique(
				array_filter(
					array_merge(
						$payload['reasons'],
						self::sanitize_key_list( $reasons )
					)
				)
			)
		);

		self::record_checkout_event(
			self::EVENT_DEVICE_SIGNAL,
			'error',
			__( 'Blacklist Manager Premium detected failed checkout session continuity.', 'yoohw-customer-intelligence' ),
			$payload
		);
	}

	public static function handle_fp_anomalies_failed( $event, $context = '' ): void {
		$payload = self::normalize_checkout_payload( $event, 'fingerprint_anomalies_failed', $context );

		self::record_checkout_event(
			self::EVENT_DEVICE_SIGNAL,
			'error',
			__( 'Blacklist Manager Premium detected checkout browser fingerprint anomalies.', 'yoohw-customer-intelligence' ),
			$payload
		);
	}

	public static function handle_payment_abuse_event_recorded( int $event_id, $order = null, array $event_data = array() ): bool {
		global $wpdb;

		$event_id = absint( $event_id );

		if ( $event_id <= 0 ) {
			return false;
		}

		if ( empty( $event_data ) ) {
			$table = $wpdb->prefix . 'wc_blacklist_payment_abuse_events';

			if ( ! self::table_exists( $table ) ) {
				return false;
			}

			$event_data = $wpdb->get_row(
				$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $table, $event_id ),
				ARRAY_A
			);
			$event_data = is_array( $event_data ) ? $event_data : array();
		}

		if ( empty( $event_data ) ) {
			return false;
		}

		$event_data['id'] = $event_id;

		if ( $order instanceof WC_Order ) {
			$event_data['order_id'] = absint( $order->get_id() );
		}

		return self::backfill_payment_abuse_row( $event_data );
	}

	public static function reassociate_checkout_events_for_order( $order_or_id ): int {
		global $wpdb;

		$order_id = $order_or_id instanceof WC_Order ? absint( $order_or_id->get_id() ) : absint( $order_or_id );

		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return 0;
		}

		$order = $order_or_id instanceof WC_Order ? $order_or_id : wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund ) {
			return 0;
		}

		$customer_id = YoOhw_COS_Customers::find_customer_id_from_order(
			$order,
			absint( $order->get_customer_id() ),
			sanitize_email( $order->get_billing_email() ),
			sanitize_text_field( $order->get_billing_phone() )
		);

		if ( $customer_id <= 0 ) {
			return 0;
		}

		$table      = YoOhw_COS_DB::events_table();
		$candidates = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT *
				FROM %i
				WHERE event_source = %s
					AND (customer_id IS NULL OR customer_id = 0)
				ORDER BY created_at DESC, id DESC
				LIMIT 500",
				$table,
				self::EVENT_SOURCE
			),
			ARRAY_A
		);

		$wp_user_id  = absint( $order->get_customer_id() );
		$email_hash  = self::privacy_hash( strtolower( sanitize_email( $order->get_billing_email() ) ) );
		$phone_value = preg_replace( '/\D+/', '', (string) $order->get_billing_phone() );
		$phone_hash  = self::privacy_hash( $phone_value ?: (string) $order->get_billing_phone() );
		$assigned    = 0;

		foreach ( is_array( $candidates ) ? $candidates : array() as $event ) {
			$metadata = YoOhw_COS_DB::json_decode( (string) ( $event['metadata_json'] ?? '' ) );

			if ( ! self::unlinked_event_matches_order( $event, $metadata, $order, $wp_user_id, $email_hash, $phone_hash ) ) {
				continue;
			}

			if ( YoOhw_COS_Events::assign_customer( absint( $event['id'] ?? 0 ), $customer_id, $wp_user_id ) ) {
				$assigned++;
			}
		}

		if ( $assigned > 0 ) {
			self::refresh_customer_risk_score( $customer_id );
		}

		return $assigned;
	}

	public static function reassociate_existing_unlinked_events( int $after_event_id = 0, int $limit = 500 ): array {
		global $wpdb;

		$table = YoOhw_COS_DB::events_table();
		$limit = min( 1000, max( 1, absint( $limit ) ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT *
				FROM %i
				WHERE id > %d
					AND event_source = %s
					AND (customer_id IS NULL OR customer_id = 0)
				ORDER BY id ASC
				LIMIT %d",
				$table,
				absint( $after_event_id ),
				self::EVENT_SOURCE,
				$limit
			),
			ARRAY_A
		);
		$assigned_customers = array();
		$assigned           = 0;
		$last_event_id      = absint( $after_event_id );

		foreach ( is_array( $rows ) ? $rows : array() as $event ) {
			$event_id      = absint( $event['id'] ?? 0 );
			$last_event_id = max( $last_event_id, $event_id );
			$metadata      = YoOhw_COS_DB::json_decode( (string) ( $event['metadata_json'] ?? '' ) );
			$order_id      = 'order' === (string) ( $event['object_type'] ?? '' )
				? absint( $event['object_id'] ?? 0 )
				: absint( $metadata['order_id'] ?? 0 );
			$wp_user_id    = absint( $event['wp_user_id'] ?? 0 );
			$customer_id   = 0;

			if ( $order_id > 0 && function_exists( 'wc_get_order' ) ) {
				$order = wc_get_order( $order_id );

				if ( $order instanceof WC_Order && ! $order instanceof WC_Order_Refund ) {
					$wp_user_id = absint( $order->get_customer_id() );
					$customer_id = YoOhw_COS_Customers::find_customer_id_from_order(
						$order,
						$wp_user_id,
						sanitize_email( $order->get_billing_email() ),
						sanitize_text_field( $order->get_billing_phone() )
					);
				}
			}

			if ( $customer_id <= 0 && $wp_user_id > 0 ) {
				$customer_id = YoOhw_COS_Customers::find_customer_id(
					array( 'wp_user_id' => $wp_user_id )
				);
			}

			if ( $customer_id > 0 && YoOhw_COS_Events::assign_customer( $event_id, $customer_id, $wp_user_id ) ) {
				$assigned++;
				$assigned_customers[ $customer_id ] = true;
			}
		}

		foreach ( array_keys( $assigned_customers ) as $customer_id ) {
			self::refresh_customer_risk_score( absint( $customer_id ) );
		}

		return array(
			'scanned'       => count( $rows ),
			'assigned'      => $assigned,
			'last_event_id' => $last_event_id,
			'has_more'      => count( $rows ) >= $limit,
		);
	}

	public static function process_existing_event_reassociation(): void {
		$state = get_option( self::REASSOCIATION_STATE_OPTION, array() );
		$state = is_array( $state ) ? $state : array();

		if (
			self::REASSOCIATION_VERSION === absint( $state['version'] ?? 0 )
			&& 'completed' === (string) ( $state['status'] ?? '' )
		) {
			return;
		}

		$previous_state = $state;
		$result         = self::reassociate_existing_unlinked_events( absint( $state['last_event_id'] ?? 0 ) );

		$state = array(
			'version'        => self::REASSOCIATION_VERSION,
			'status'         => ! empty( $result['has_more'] ) ? 'in_progress' : 'completed',
			'last_event_id'  => absint( $result['last_event_id'] ?? 0 ),
			'total_scanned'  => absint( $previous_state['total_scanned'] ?? 0 ) + absint( $result['scanned'] ?? 0 ),
			'total_assigned' => absint( $previous_state['total_assigned'] ?? 0 ) + absint( $result['assigned'] ?? 0 ),
			'updated_at'     => YoOhw_COS_DB::now(),
		);

		update_option( self::REASSOCIATION_STATE_OPTION, $state, false );

		if ( 'in_progress' === $state['status'] && ! wp_next_scheduled( self::REASSOCIATION_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::REASSOCIATION_HOOK );
		}
	}

	private static function maybe_schedule_existing_event_reassociation(): void {
		$state = get_option( self::REASSOCIATION_STATE_OPTION, array() );
		$state = is_array( $state ) ? $state : array();

		if (
			self::REASSOCIATION_VERSION === absint( $state['version'] ?? 0 )
			&& 'completed' === (string) ( $state['status'] ?? '' )
		) {
			return;
		}

		if ( ! wp_next_scheduled( self::REASSOCIATION_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::REASSOCIATION_HOOK );
		}
	}

	public static function apply_customer_risk_score( $score, array $customer ): float {
		$signal = self::get_customer_premium_signal( $customer );

		if ( empty( $signal ) || empty( $signal['impact'] ) ) {
			return (float) $score;
		}

		return (float) $score + (float) $signal['impact'];
	}

	public static function apply_customer_risk_factors( $factors, array $customer ): array {
		$factors = is_array( $factors ) ? $factors : array();
		$signal  = self::get_customer_premium_signal( $customer );

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

	public static function get_customer_security_summary( int $customer_id ): array {
		$customer_id = absint( $customer_id );

		if ( $customer_id <= 0 ) {
			return array();
		}

		$events = self::get_customer_premium_events( $customer_id, 80 );

		if ( empty( $events ) ) {
			return array();
		}

		$latest_order_risk = array();
		$highest_order_risk = array();
		$matched_rules = array();
		$recent_events = array();

		foreach ( $events as $event ) {
			$event_type = sanitize_key( (string) ( $event['event_type'] ?? '' ) );
			$metadata   = is_array( $event['metadata'] ?? null ) ? $event['metadata'] : array();

			if ( self::EVENT_ORDER_RISK === $event_type ) {
				$risk_score = max( 0, (int) ( $metadata['risk_score'] ?? 0 ) );

				if ( empty( $latest_order_risk ) ) {
					$latest_order_risk = self::summary_order_risk_item( $event, $risk_score );
				}

				if ( empty( $highest_order_risk ) || $risk_score > (int) ( $highest_order_risk['risk_score'] ?? 0 ) ) {
					$highest_order_risk = self::summary_order_risk_item( $event, $risk_score );
				}
			}

			foreach ( (array) ( $metadata['matched_rules'] ?? array() ) as $rule ) {
				if ( ! is_array( $rule ) ) {
					continue;
				}

				$key = sanitize_key( (string) ( $rule['key'] ?? $rule['job_hook'] ?? '' ) );

				if ( '' === $key ) {
					continue;
				}

				$current_score = (int) ( $rule['score'] ?? 0 );

				if ( ! isset( $matched_rules[ $key ] ) || $current_score > (int) ( $matched_rules[ $key ]['score'] ?? 0 ) ) {
					$matched_rules[ $key ] = array(
						'key'      => $key,
						'label'    => sanitize_text_field( (string) ( $rule['label'] ?? self::format_rule_label( $key ) ) ),
						'score'    => max( 0, $current_score ),
						'category' => sanitize_key( (string) ( $rule['category'] ?? 'risk' ) ),
					);
				}
			}

			if ( self::EVENT_ORDER_RISK !== $event_type && count( $recent_events ) < 6 ) {
				$recent_events[] = array(
					'event_type'  => $event_type,
					'label'       => self::format_event_type_label( $event_type ),
					'severity'    => sanitize_key( (string) ( $event['severity'] ?? 'info' ) ),
					'description' => wp_kses_post( (string) ( $event['description'] ?? '' ) ),
					'created_at'  => sanitize_text_field( (string) ( $event['created_at'] ?? '' ) ),
					'metadata'    => self::security_event_metadata_summary( $metadata ),
				);
			}
		}

		return array(
			'latest_order_risk'  => $latest_order_risk,
			'highest_order_risk' => $highest_order_risk,
			'matched_rules'      => array_values( $matched_rules ),
			'recent_events'      => $recent_events,
		);
	}

	public static function format_event_type_label( string $event_type ): string {
		$event_type = sanitize_key( $event_type );

		$labels = array(
			self::EVENT_ORDER_RISK         => __( 'Premium order risk scored', 'yoohw-customer-intelligence' ),
			self::EVENT_RULE_MATCH         => __( 'Premium risk rule matched', 'yoohw-customer-intelligence' ),
			self::EVENT_ANTIBOT_BLOCKED    => __( 'Premium anti-bot blocked', 'yoohw-customer-intelligence' ),
			self::EVENT_ANTIBOT_WOULD_BLOCK => __( 'Premium anti-bot challenge', 'yoohw-customer-intelligence' ),
			self::EVENT_PAYMENT_ABUSE      => __( 'Premium payment abuse', 'yoohw-customer-intelligence' ),
			self::EVENT_DEVICE_SIGNAL      => __( 'Premium device signal', 'yoohw-customer-intelligence' ),
			self::EVENT_GATEWAY_FRAUD      => __( 'Premium gateway fraud signal', 'yoohw-customer-intelligence' ),
		);

		return $labels[ $event_type ] ?? ucwords( str_replace( '_', ' ', $event_type ) );
	}

	public static function backfill_legacy_signals( int $limit = 300, int $page = 1 ): array {
		$limit = min( 500, max( 1, absint( $limit ) ) );
		$page  = max( 1, absint( $page ) );

		$result = array(
			'scanned'   => 0,
			'processed' => 0,
			'skipped'   => 0,
			'has_more'  => false,
			'next_page' => $page,
			'stage'     => 'orders',
		);

		$order_total = self::count_risk_orders();
		$order_pages = $order_total > 0 ? (int) ceil( $order_total / $limit ) : 0;

		if ( $page <= max( 1, $order_pages ) && $order_total > 0 ) {
			$batch = self::backfill_risk_orders_batch( $limit, $page );

			$result = array_merge( $result, $batch );
			$result['stage'] = 'orders';
			$result['has_more'] = $page < $order_pages || self::count_detection_log_rows() > 0 || self::count_payment_abuse_rows() > 0;
			$result['next_page'] = $result['has_more'] ? $page + 1 : $page;

			return $result;
		}

		$detection_total = self::count_detection_log_rows();
		$detection_pages = $detection_total > 0 ? (int) ceil( $detection_total / $limit ) : 0;
		$detection_page  = $page - $order_pages;

		if ( $detection_page <= max( 1, $detection_pages ) && $detection_total > 0 ) {
			$batch = self::backfill_detection_log_batch( $limit, $detection_page );

			$result = array_merge( $result, $batch );
			$result['stage'] = 'detection_log';
			$result['has_more'] = $detection_page < $detection_pages || self::count_payment_abuse_rows() > 0;
			$result['next_page'] = $result['has_more'] ? $page + 1 : $page;

			return $result;
		}

		$payment_total = self::count_payment_abuse_rows();
		$payment_pages = $payment_total > 0 ? (int) ceil( $payment_total / $limit ) : 0;
		$payment_page  = $page - $order_pages - $detection_pages;

		if ( $payment_page <= max( 1, $payment_pages ) && $payment_total > 0 ) {
			$batch = self::backfill_payment_abuse_batch( $limit, $payment_page );

			$result = array_merge( $result, $batch );
			$result['stage'] = 'payment_abuse';
			$result['has_more'] = $payment_page < $payment_pages;
			$result['next_page'] = $result['has_more'] ? $page + 1 : $page;

			return $result;
		}

		$result['stage'] = 'complete';

		return $result;
	}

	private static function backfill_risk_orders_batch( int $limit, int $page ): array {
		$query = self::risk_order_query( $limit, $page );
		$ids   = is_array( $query['ids'] ?? null ) ? $query['ids'] : array();

		$result = array(
			'scanned'   => 0,
			'processed' => 0,
			'skipped'   => 0,
		);

		foreach ( $ids as $order_id ) {
			$result['scanned']++;

			if ( self::backfill_risk_order( absint( $order_id ) ) ) {
				$result['processed']++;
			} else {
				$result['skipped']++;
			}
		}

		return $result;
	}

	private static function backfill_risk_order( int $order_id ): bool {
		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return false;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund ) {
			return false;
		}

		$risk_score = max( 0, (int) $order->get_meta( '_risk_score', true ) );

		if ( $risk_score <= 0 ) {
			return false;
		}

		$customer_id = self::resolve_customer_id( array(), $order );
		$wp_user_id  = absint( $order->get_customer_id() );
		$created_at  = $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : YoOhw_COS_DB::now();

		return self::record_event(
			array(
				'customer_id' => $customer_id,
				'wp_user_id'  => $wp_user_id,
				'event_type'  => self::EVENT_ORDER_RISK,
				'severity'    => self::severity_for_order_risk( $risk_score ),
				'object_type' => 'order',
				'object_id'   => $order_id,
				'description' => sprintf(
					/* translators: 1: order number, 2: risk score. */
					__( 'Backfilled Blacklist Manager Premium risk score for order #%1$s: %2$d.', 'yoohw-customer-intelligence' ),
					$order->get_order_number(),
					$risk_score
				),
				'metadata'    => array(
					'schema'        => 'premium_order_risk_backfill_v1',
					'order_id'      => $order_id,
					'risk_score'    => $risk_score,
					'score'         => $risk_score,
					'timestamp'     => $created_at,
					'source_rule'   => 'order_meta:_risk_score',
					'matched_rules' => self::collect_order_rule_matches( $order ),
				),
				'created_at'  => $created_at,
				'idempotent'  => true,
			)
		);
	}

	private static function risk_order_query( int $limit, int $page ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array(
				'ids'   => array(),
				'total' => 0,
			);
		}

		$args = array(
			'type'       => 'shop_order',
			'limit'      => $limit,
			'page'       => $page,
			'paginate'   => true,
			'orderby'    => 'ID',
			'order'      => 'ASC',
			'return'     => 'ids',
			'status'     => function_exists( 'wc_get_order_statuses' ) ? array_keys( wc_get_order_statuses() ) : 'any',
			'meta_query' => array(
				array(
					'key'     => '_risk_score',
					'value'   => 0,
					'compare' => '>',
					'type'    => 'NUMERIC',
				),
			),
		);

		$query = wc_get_orders( $args );

		if ( is_object( $query ) && isset( $query->orders ) ) {
			return array(
				'ids'   => is_array( $query->orders ) ? $query->orders : array(),
				'total' => absint( $query->total ?? 0 ),
			);
		}

		return array(
			'ids'   => is_array( $query ) ? $query : array(),
			'total' => is_array( $query ) ? count( $query ) : 0,
		);
	}

	private static function count_risk_orders(): int {
		$query = self::risk_order_query( 1, 1 );

		return absint( $query['total'] ?? 0 );
	}

	private static function backfill_detection_log_batch( int $limit, int $page ): array {
		global $wpdb;

		$result = array(
			'scanned'   => 0,
			'processed' => 0,
			'skipped'   => 0,
		);

		$table = $wpdb->prefix . 'wc_blacklist_detection_log';

		if ( ! self::table_exists( $table ) ) {
			return $result;
		}

		$offset = ( max( 1, $page ) - 1 ) * $limit;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, `timestamp`, type, source, action, details, view
				FROM %i
				WHERE (source LIKE %s AND details LIKE %s)
				OR source IN ('woo_checkout', 'woo_api_checkout', 'paypal_payments_create_order')
				ORDER BY id ASC
				LIMIT %d OFFSET %d",
				$table,
				'woo_order_%',
				'%risk_score%',
				$limit,
				$offset
			),
			ARRAY_A
		);

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$result['scanned']++;

			if ( self::backfill_detection_log_row( $row ) ) {
				$result['processed']++;
			} else {
				$result['skipped']++;
			}
		}

		return $result;
	}

	private static function count_detection_log_rows(): int {
		global $wpdb;

		$table = $wpdb->prefix . 'wc_blacklist_detection_log';

		if ( ! self::table_exists( $table ) ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM %i
				WHERE (source LIKE %s AND details LIKE %s)
				OR source IN ('woo_checkout', 'woo_api_checkout', 'paypal_payments_create_order')",
				$table,
				'woo_order_%',
				'%risk_score%'
			)
		);
	}

	private static function backfill_detection_log_row( array $row ): bool {
		$log_id = absint( $row['id'] ?? 0 );

		if ( $log_id <= 0 ) {
			return false;
		}

		$source = sanitize_key( (string) ( $row['source'] ?? '' ) );
		$view   = self::decode_json_array( (string) ( $row['view'] ?? '' ) );
		$order_id = self::order_id_from_detection_source( $source );

		if ( $order_id <= 0 ) {
			$order_id = self::extract_order_id( $view );
		}

		$order = $order_id > 0 && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		$order = $order instanceof WC_Order ? $order : null;

		$payload = self::premium_detection_payload( $row, $view, $order_id );
		$event_type = self::event_type_for_detection_payload( $payload );
		$severity = self::severity_for_detection_payload( $payload, $event_type );
		$customer_id = self::resolve_customer_id( $payload, $order );
		$wp_user_id = self::resolve_wp_user_id( $payload, $order );
		$created_at = self::normalize_created_at( $row['timestamp'] ?? '' );

		return self::record_event(
			array(
				'customer_id' => $customer_id,
				'wp_user_id'  => $wp_user_id,
				'event_type'  => $event_type,
				'severity'    => $severity,
				'object_type' => 'premium_detection_log',
				'object_id'   => $log_id,
				'description' => self::backfill_detection_description( $event_type, $order ),
				'metadata'    => $payload,
				'created_at'  => $created_at,
				'idempotent'  => true,
			)
		);
	}

	private static function premium_detection_payload( array $row, array $view, int $order_id ): array {
		$details = sanitize_text_field( (string) ( $row['details'] ?? '' ) );
		$source  = sanitize_key( (string) ( $row['source'] ?? '' ) );
		$action  = sanitize_key( (string) ( $row['action'] ?? '' ) );
		$type    = sanitize_key( (string) ( $row['type'] ?? '' ) );
		$score   = max( 0, (int) ( $view['score'] ?? 0 ) );
		$reasons = self::sanitize_key_list( $view['reasons'] ?? array() );

		if ( empty( $reasons ) ) {
			$reasons = self::reason_codes_from_text( $details );
		}

		$identity = is_array( $view['identity'] ?? null ) ? $view['identity'] : array();

		return array_filter(
			array(
				'schema'        => 'premium_detection_log_backfill_v1',
				'log_id'        => absint( $row['id'] ?? 0 ),
				'order_id'      => $order_id,
				'signal'        => self::signal_label_from_detection( $source, $action, $reasons ),
				'source_rule'   => $source . ':' . ( $action ?: 'event' ),
				'type'          => $type,
				'action'        => $action,
				'score'         => $score,
				'raw_score'     => max( 0, (int) ( $view['raw_score'] ?? $score ) ),
				'threshold'     => max( 0, (int) ( $view['threshold'] ?? 0 ) ),
				'reasons'       => $reasons,
				'timestamp'     => self::normalize_created_at( $row['timestamp'] ?? '' ),
				'context'       => self::sanitize_checkout_context( $view['context'] ?? array() ),
				'signals'       => self::sanitize_signal_summary( $view['signals'] ?? array() ),
				'details_hash'  => self::privacy_hash( $details ),
				'identity_hashes' => self::identity_hashes_from_detection_view( $view, $identity ),
				'wp_user_id'    => absint( $identity['user_id'] ?? 0 ),
			),
			static function ( $value ) {
				return ! ( '' === $value || 0 === $value || array() === $value || null === $value );
			}
		);
	}

	private static function backfill_payment_abuse_batch( int $limit, int $page ): array {
		global $wpdb;

		$result = array(
			'scanned'   => 0,
			'processed' => 0,
			'skipped'   => 0,
		);

		$table = $wpdb->prefix . 'wc_blacklist_payment_abuse_events';

		if ( ! self::table_exists( $table ) ) {
			return $result;
		}

		$offset = ( max( 1, $page ) - 1 ) * $limit;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, created_at, order_id, source, gateway, gateway_profile, failure_code, failure_family, decline_code,
					cart_hash, payment_reference_hash, ip_hash, ip_prefix_hash, email_hash, phone_hash, device_hash,
					session_hash, user_hash, user_agent_hash, identity_hash
				FROM %i
				ORDER BY id ASC
				LIMIT %d OFFSET %d",
				$table,
				$limit,
				$offset
			),
			ARRAY_A
		);

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$result['scanned']++;

			if ( self::backfill_payment_abuse_row( $row ) ) {
				$result['processed']++;
			} else {
				$result['skipped']++;
			}
		}

		return $result;
	}

	private static function count_payment_abuse_rows(): int {
		global $wpdb;

		$table = $wpdb->prefix . 'wc_blacklist_payment_abuse_events';

		if ( ! self::table_exists( $table ) ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i',
				$table
			)
		);
	}

	private static function backfill_payment_abuse_row( array $row ): bool {
		$event_id = absint( $row['id'] ?? 0 );

		if ( $event_id <= 0 ) {
			return false;
		}

		$order_id = absint( $row['order_id'] ?? 0 );
		$order    = $order_id > 0 && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		$order    = $order instanceof WC_Order ? $order : null;
		$customer_id = self::resolve_customer_id( array(), $order );
		$wp_user_id  = self::resolve_wp_user_id( array(), $order );
		$score       = self::score_for_payment_abuse_row( $row );
		$created_at  = self::normalize_created_at( $row['created_at'] ?? '' );

		return self::record_event(
			array(
				'customer_id' => $customer_id,
				'wp_user_id'  => $wp_user_id,
				'event_type'  => self::EVENT_PAYMENT_ABUSE,
				'severity'    => $score >= 30 ? 'error' : 'warning',
				'object_type' => 'premium_payment_abuse_event',
				'object_id'   => $event_id,
				'description' => self::backfill_payment_abuse_description( $order ),
				'metadata'    => self::payment_abuse_metadata( $row, $score ),
				'created_at'  => $created_at,
				'idempotent'  => true,
			)
		);
	}

	private static function record_current_rule_event( WC_Order $order, int $customer_id, int $wp_user_id, string $job_hook, array $rule, int $rule_score, int $risk_score ): void {
		$event_type = self::event_type_for_rule_category( (string) ( $rule['category'] ?? '' ) );
		$order_id   = absint( $order->get_id() );
		$object_id  = self::stable_object_id( array( $order_id, $job_hook, $event_type ) );

		self::record_event(
			array(
				'customer_id'  => $customer_id,
				'wp_user_id'   => $wp_user_id,
				'event_type'   => $event_type,
				'severity'     => self::severity_for_rule_score( $rule_score ),
				'object_type'  => 'premium_risk_rule',
				'object_id'    => $object_id,
				'description'  => sprintf(
					/* translators: 1: rule label, 2: order number. */
					__( 'Blacklist Manager Premium matched "%1$s" on order #%2$s.', 'yoohw-customer-intelligence' ),
					(string) ( $rule['label'] ?? self::format_rule_label( $job_hook ) ),
					$order->get_order_number()
				),
				'metadata'     => array(
					'order_id'   => $order_id,
					'job_hook'   => $job_hook,
					'rule'       => self::format_rule_match( $job_hook, $rule, $rule_score ),
					'risk_score' => $risk_score,
				),
				'idempotent'   => true,
			)
		);
	}

	private static function record_checkout_event( string $event_type, string $severity, string $description, array $payload ): void {
		$order_id = absint( $payload['order_id'] ?? 0 );
		$order    = $order_id > 0 && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		$order    = $order instanceof WC_Order ? $order : null;

		$customer_id = self::resolve_customer_id( $payload, $order );
		$wp_user_id  = self::resolve_wp_user_id( $payload, $order );
		$object_type = $order instanceof WC_Order ? 'order' : 'checkout_antibot';
		$object_id   = $order instanceof WC_Order ? absint( $order->get_id() ) : self::stable_object_id(
			array(
				$event_type,
				(string) ( $payload['signal'] ?? '' ),
				(string) ( $payload['checkout_context'] ?? '' ),
				(string) ( $payload['score'] ?? 0 ),
				(string) wp_json_encode( (array) ( $payload['reasons'] ?? array() ) ),
				(string) YoOhw_COS_DB::now(),
			)
		);

		if ( $order instanceof WC_Order ) {
			$description = sprintf(
				/* translators: 1: description, 2: order number. */
				__( '%1$s Order #%2$s.', 'yoohw-customer-intelligence' ),
				$description,
				$order->get_order_number()
			);
		}

		self::record_event(
			array(
				'customer_id' => $customer_id,
				'wp_user_id'  => $wp_user_id,
				'event_type'  => $event_type,
				'severity'    => $severity,
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'description' => $description,
				'metadata'    => self::checkout_event_metadata( $payload ),
			)
		);
	}

	private static function record_event( array $args ): bool {
		$customer_id = absint( $args['customer_id'] ?? 0 );
		$event_type  = sanitize_key( (string) ( $args['event_type'] ?? '' ) );
		$object_type = ! empty( $args['object_type'] ) ? sanitize_key( (string) $args['object_type'] ) : '';
		$object_id   = absint( $args['object_id'] ?? 0 );

		if ( '' === $event_type ) {
			return false;
		}

		if (
			! empty( $args['idempotent'] )
			&& '' !== $object_type
			&& $object_id > 0
			&& YoOhw_COS_Events::event_exists( $event_type, $object_type, $object_id, $customer_id )
		) {
			if ( $customer_id > 0 ) {
				self::refresh_customer_risk_score( $customer_id );
			}

			return false;
		}

		$event_args = array(
				'customer_id'  => $customer_id ?: null,
				'wp_user_id'   => ! empty( $args['wp_user_id'] ) ? absint( $args['wp_user_id'] ) : null,
				'event_type'   => $event_type,
				'event_source' => self::EVENT_SOURCE,
				'severity'     => sanitize_key( (string) ( $args['severity'] ?? 'info' ) ),
				'object_type'  => $object_type ?: null,
				'object_id'    => $object_id ?: null,
				'description'  => wp_kses_post( (string) ( $args['description'] ?? '' ) ),
				'metadata'     => self::sanitize_metadata( (array) ( $args['metadata'] ?? array() ) ),
		);

		$created_at = self::normalize_created_at( $args['created_at'] ?? '' );

		if ( '' !== $created_at ) {
			$event_args['created_at'] = $created_at;
		}

		$event_id = YoOhw_COS_Events::record( $event_args );

		if ( $event_id > 0 && $customer_id > 0 ) {
			self::refresh_customer_risk_score( $customer_id );
		}

		return $event_id > 0;
	}

	private static function resolve_customer_id( array $payload, $order = null ): int {
		if ( $order instanceof WC_Order ) {
			$customer_id = YoOhw_COS_Customers::find_customer_id_from_order(
				$order,
				absint( $order->get_customer_id() ),
				sanitize_email( $order->get_billing_email() ),
				sanitize_text_field( $order->get_billing_phone() )
			);

			return $customer_id > 0 ? $customer_id : YoOhw_COS_Customers::sync_from_order( $order );
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

	private static function collect_order_rule_matches( WC_Order $order ): array {
		$matches = array();

		foreach ( self::risk_rule_map() as $job_hook => $rule ) {
			$meta_key = (string) ( $rule['meta_key'] ?? '' );

			if ( '' === $meta_key ) {
				continue;
			}

			$score = max( 0, (int) $order->get_meta( $meta_key, true ) );

			if ( $score <= 0 ) {
				continue;
			}

			$matches[] = self::format_rule_match( $job_hook, $rule, $score );
		}

		return $matches;
	}

	private static function get_rule_for_job( string $job_hook ): array {
		$rules = self::risk_rule_map();

		return isset( $rules[ $job_hook ] ) ? $rules[ $job_hook ] : array();
	}

	private static function risk_rule_map(): array {
		return array(
			'wc_blacklist_first_time_order_job' => array(
				'meta_key' => '_risk_score_first_time_order',
				'label'    => __( 'First-time order risk', 'yoohw-customer-intelligence' ),
				'category' => 'risk',
			),
			'wc_blacklist_order_phone_email_vs_address_job' => array(
				'meta_key' => '_risk_score_phone_email_address',
				'label'    => __( 'Phone/email versus address', 'yoohw-customer-intelligence' ),
				'category' => 'risk',
			),
			'wc_blacklist_order_phone_email_vs_ip_job' => array(
				'meta_key' => '_risk_score_phone_email_ip',
				'label'    => __( 'Phone/email versus IP', 'yoohw-customer-intelligence' ),
				'category' => 'risk',
			),
			'wc_blacklist_order_billing_shipping_job' => array(
				'meta_key' => '_risk_score_billing_shipping',
				'label'    => __( 'Billing versus shipping', 'yoohw-customer-intelligence' ),
				'category' => 'risk',
			),
			'wc_blacklist_order_value_job' => array(
				'meta_key' => '_risk_score_order_value',
				'label'    => __( 'Order value anomaly', 'yoohw-customer-intelligence' ),
				'category' => 'risk',
			),
			'wc_blacklist_order_attempts_job' => array(
				'meta_key' => '_risk_score_order_attempts',
				'label'    => __( 'Order attempts velocity', 'yoohw-customer-intelligence' ),
				'category' => 'risk',
			),
			'wc_blacklist_ip_country_job' => array(
				'meta_key' => '_risk_score_ip_country',
				'label'    => __( 'IP country mismatch', 'yoohw-customer-intelligence' ),
				'category' => 'risk',
			),
			'wc_blacklist_ip_coordinates_job' => array(
				'meta_key' => '_risk_score_ip_address',
				'label'    => __( 'IP coordinates mismatch', 'yoohw-customer-intelligence' ),
				'category' => 'risk',
			),
			'wc_blacklist_ip_hosting_job' => array(
				'meta_key' => '_risk_score_hosting_ip',
				'label'    => __( 'Hosting IP risk', 'yoohw-customer-intelligence' ),
				'category' => 'risk',
			),
			'wc_blacklist_order_ip_proxy_vpn_job' => array(
				'meta_key' => '_risk_score_using_proxy_vpn',
				'label'    => __( 'Proxy/VPN risk', 'yoohw-customer-intelligence' ),
				'category' => 'risk',
			),
			'wc_blacklist_device_vs_email_phone_job' => array(
				'meta_key' => '_risk_score_device_email_phone',
				'label'    => __( 'Device versus email/phone', 'yoohw-customer-intelligence' ),
				'category' => 'device',
			),
			'wc_blacklist_order_device_vs_address_job' => array(
				'meta_key' => '_risk_score_device_address',
				'label'    => __( 'Device versus address', 'yoohw-customer-intelligence' ),
				'category' => 'device',
			),
			'wc_blacklist_device_identity_spread_job' => array(
				'meta_key' => '_risk_score_device_identity_spread',
				'label'    => __( 'Device identity spread', 'yoohw-customer-intelligence' ),
				'category' => 'device',
			),
			'wc_blacklist_gateway_avs_job' => array(
				'meta_key' => '_risk_score_rule_avs_check',
				'label'    => __( 'Gateway AVS check', 'yoohw-customer-intelligence' ),
				'category' => 'gateway',
			),
			'wc_blacklist_gateway_card_billing_job' => array(
				'meta_key' => '_risk_score_card_billing_country',
				'label'    => __( 'Card billing country', 'yoohw-customer-intelligence' ),
				'category' => 'gateway',
			),
			'wc_blacklist_gateway_high_risk_country_job' => array(
				'meta_key' => '_risk_score_high_risk_country',
				'label'    => __( 'Gateway high-risk country', 'yoohw-customer-intelligence' ),
				'category' => 'payment',
			),
			'wc_blacklist_order_paypal_payer_vs_customer_job' => array(
				'meta_key' => '_risk_score_paypal_payer_vs_customer',
				'label'    => __( 'PayPal payer versus customer', 'yoohw-customer-intelligence' ),
				'category' => 'payment',
			),
		);
	}

	private static function format_rule_match( string $job_hook, array $rule, int $score ): array {
		return array(
			'key'      => sanitize_key( $job_hook ),
			'label'    => sanitize_text_field( (string) ( $rule['label'] ?? self::format_rule_label( $job_hook ) ) ),
			'meta_key' => sanitize_key( (string) ( $rule['meta_key'] ?? '' ) ),
			'category' => sanitize_key( (string) ( $rule['category'] ?? 'risk' ) ),
			'score'    => max( 0, $score ),
		);
	}

	private static function normalize_checkout_payload( $payload, string $signal, $context ): array {
		$payload  = is_array( $payload ) ? $payload : array();
		$identity = self::current_request_identity();
		$order_id = self::extract_order_id( $payload );

		return array(
			'order_id'                         => $order_id,
			'wp_user_id'                       => absint( $identity['wp_user_id'] ?? 0 ),
			'email'                            => sanitize_email( (string) ( $identity['email'] ?? '' ) ),
			'phone'                            => sanitize_text_field( (string) ( $identity['phone'] ?? '' ) ),
			'signal'                           => sanitize_key( $signal ),
			'checkout_context'                 => sanitize_key( (string) $context ),
			'mode'                             => sanitize_key( (string) ( $payload['mode'] ?? '' ) ),
			'action'                           => sanitize_key( (string) ( $payload['action'] ?? '' ) ),
			'band'                             => sanitize_key( (string) ( $payload['band'] ?? '' ) ),
			'block'                            => ! empty( $payload['block'] ),
			'would_block'                      => ! empty( $payload['would_block'] ),
			'payment_abuse_monitor_would_block' => ! empty( $payload['payment_abuse_monitor_would_block'] ),
			'shadow_mode'                      => ! empty( $payload['shadow_mode'] ),
			'score'                            => max( 0, (int) ( $payload['score'] ?? ( $payload['points'] ?? 0 ) ) ),
			'raw_score'                        => max( 0, (int) ( $payload['raw_score'] ?? ( $payload['score'] ?? 0 ) ) ),
			'threshold'                        => max( 0, (int) ( $payload['threshold'] ?? 0 ) ),
			'reasons'                          => self::sanitize_key_list( $payload['reasons'] ?? ( $payload['reason'] ?? array() ) ),
			'context'                          => self::sanitize_checkout_context( $payload['context'] ?? array() ),
			'signals'                          => self::sanitize_signal_summary( $payload['signals'] ?? array() ),
		);
	}

	private static function checkout_event_metadata( array $payload ): array {
		return array_filter(
			array(
				'order_id'                         => absint( $payload['order_id'] ?? 0 ),
				'signal'                           => sanitize_key( (string) ( $payload['signal'] ?? '' ) ),
				'checkout_context'                 => sanitize_key( (string) ( $payload['checkout_context'] ?? '' ) ),
				'mode'                             => sanitize_key( (string) ( $payload['mode'] ?? '' ) ),
				'action'                           => sanitize_key( (string) ( $payload['action'] ?? '' ) ),
				'band'                             => sanitize_key( (string) ( $payload['band'] ?? '' ) ),
				'block'                            => ! empty( $payload['block'] ),
				'would_block'                      => ! empty( $payload['would_block'] ),
				'payment_abuse_monitor_would_block' => ! empty( $payload['payment_abuse_monitor_would_block'] ),
				'shadow_mode'                      => ! empty( $payload['shadow_mode'] ),
				'score'                            => max( 0, (int) ( $payload['score'] ?? 0 ) ),
				'raw_score'                        => max( 0, (int) ( $payload['raw_score'] ?? 0 ) ),
				'threshold'                        => max( 0, (int) ( $payload['threshold'] ?? 0 ) ),
				'reasons'                          => self::sanitize_key_list( $payload['reasons'] ?? array() ),
				'context'                          => self::sanitize_checkout_context( $payload['context'] ?? array() ),
				'signals'                          => self::sanitize_signal_summary( $payload['signals'] ?? array() ),
				'customer_matched'                 => ! empty( $payload['email'] ) || ! empty( $payload['phone'] ) || ! empty( $payload['wp_user_id'] ) || ! empty( $payload['order_id'] ),
				'identity_hashes'                  => array_filter(
					array(
						'email' => self::privacy_hash( strtolower( sanitize_email( (string) ( $payload['email'] ?? '' ) ) ) ),
						'phone' => self::privacy_hash( preg_replace( '/\D+/', '', (string) ( $payload['phone'] ?? '' ) ) ),
					)
				),
			),
			static function ( $value ) {
				return ! ( '' === $value || array() === $value || null === $value );
			}
		);
	}

	private static function unlinked_event_matches_order(
		array $event,
		array $metadata,
		WC_Order $order,
		int $wp_user_id,
		string $email_hash,
		string $phone_hash
	): bool {
		$event_order_id = absint( $metadata['order_id'] ?? 0 );

		if (
			'order' === (string) ( $event['object_type'] ?? '' )
			&& absint( $event['object_id'] ?? 0 ) === absint( $order->get_id() )
		) {
			return true;
		}

		if ( $event_order_id > 0 && $event_order_id === absint( $order->get_id() ) ) {
			return true;
		}

		if ( ! self::event_is_near_order( (string) ( $event['created_at'] ?? '' ), $order ) ) {
			return false;
		}

		if ( $wp_user_id > 0 && absint( $event['wp_user_id'] ?? 0 ) === $wp_user_id ) {
			return true;
		}

		$identity_hashes = is_array( $metadata['identity_hashes'] ?? null ) ? $metadata['identity_hashes'] : array();

		if ( '' !== $email_hash && hash_equals( $email_hash, (string) ( $identity_hashes['email'] ?? '' ) ) ) {
			return true;
		}

		return '' !== $phone_hash && hash_equals( $phone_hash, (string) ( $identity_hashes['phone'] ?? '' ) );
	}

	private static function event_is_near_order( string $event_date, WC_Order $order ): bool {
		$event_timestamp = YoOhw_COS_DB::date_timestamp( $event_date );
		$order_date      = $order->get_date_created();
		$order_timestamp = $order_date ? $order_date->getTimestamp() : 0;

		if ( $event_timestamp <= 0 || $order_timestamp <= 0 ) {
			return false;
		}

		return abs( $event_timestamp - $order_timestamp ) <= ( 2 * DAY_IN_SECONDS );
	}

	private static function checkout_failure_event_type( array $payload ): string {
		if ( ! empty( $payload['payment_abuse_monitor_would_block'] ) ) {
			return self::EVENT_PAYMENT_ABUSE;
		}

		foreach ( self::sanitize_key_list( $payload['reasons'] ?? array() ) as $reason ) {
			if ( false !== strpos( $reason, 'payment_abuse' ) || false !== strpos( $reason, 'payment' ) ) {
				return self::EVENT_PAYMENT_ABUSE;
			}
		}

		return self::EVENT_ANTIBOT_BLOCKED;
	}

	private static function sanitize_checkout_context( $context ): array {
		$context = is_array( $context ) ? $context : array();
		$allowed = array(
			'mode',
			'payment_method',
			'gateway_group',
			'is_express',
			'route',
		);
		$clean = array();

		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $context ) ) {
				continue;
			}

			if ( is_bool( $context[ $key ] ) ) {
				$clean[ $key ] = (bool) $context[ $key ];
			} else {
				$clean[ $key ] = sanitize_text_field( (string) $context[ $key ] );
			}
		}

		return $clean;
	}

	private static function sanitize_signal_summary( $signals ): array {
		$signals = is_array( $signals ) ? $signals : array();
		$clean   = array();

		foreach ( $signals as $signal ) {
			if ( ! is_array( $signal ) ) {
				continue;
			}

			$clean[] = array_filter(
				array(
					'source'  => sanitize_key( (string) ( $signal['source'] ?? '' ) ),
					'enabled' => ! empty( $signal['enabled'] ),
					'points'  => isset( $signal['points'] ) ? (int) $signal['points'] : null,
					'reasons' => self::sanitize_key_list( $signal['reasons'] ?? array() ),
				),
				static function ( $value ) {
					return ! ( '' === $value || array() === $value || null === $value );
				}
			);
		}

		return $clean;
	}

	private static function current_request_identity(): array {
		$email = self::request_value(
			array(
				array( 'billing_email' ),
				array( 'billing_address', 'email' ),
			)
		);
		$phone = self::request_value(
			array(
				array( 'billing_phone' ),
				array( 'billing_address', 'phone' ),
				array( 'shipping_phone' ),
				array( 'shipping_address', 'phone' ),
			)
		);

		return array(
			'wp_user_id' => is_user_logged_in() ? get_current_user_id() : 0,
			'email'      => sanitize_email( strtolower( $email ) ),
			'phone'      => sanitize_text_field( preg_replace( '/\D+/', '', $phone ) ?: $phone ),
		);
	}

	private static function request_value( array $paths ): string {
		$sources = array();

		if ( ! empty( $_POST ) ) {
			$sources[] = wp_unslash( $_POST );
		}

		if ( ! empty( $_REQUEST ) ) {
			$sources[] = wp_unslash( $_REQUEST );
		}

		foreach ( $sources as $source ) {
			if ( ! is_array( $source ) ) {
				continue;
			}

			foreach ( $paths as $path ) {
				$value = self::array_path_value( $source, $path );

				if ( '' !== $value ) {
					return $value;
				}
			}
		}

		return '';
	}

	private static function array_path_value( array $source, array $path ): string {
		$value = $source;

		foreach ( $path as $key ) {
			if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
				return '';
			}

			$value = $value[ $key ];
		}

		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}

		return sanitize_text_field( (string) $value );
	}

	private static function extract_order_id( array $payload ): int {
		foreach ( array( 'order_id', 'orderId', 'wc_order_id' ) as $key ) {
			if ( ! empty( $payload[ $key ] ) ) {
				return absint( $payload[ $key ] );
			}
		}

		foreach ( array( 'context', 'request', 'data' ) as $container ) {
			if ( empty( $payload[ $container ] ) || ! is_array( $payload[ $container ] ) ) {
				continue;
			}

			foreach ( array( 'order_id', 'orderId', 'wc_order_id' ) as $key ) {
				if ( ! empty( $payload[ $container ][ $key ] ) ) {
					return absint( $payload[ $container ][ $key ] );
				}
			}
		}

		return 0;
	}

	private static function get_customer_premium_signal( array $customer ): array {
		$customer_id = absint( $customer['id'] ?? 0 );

		if ( $customer_id <= 0 ) {
			return array();
		}

		$events = self::get_customer_premium_events( $customer_id, 80 );

		if ( empty( $events ) ) {
			return array();
		}

		$best = array();

		foreach ( $events as $event ) {
			$candidate = self::event_risk_impact( $event );

			if ( empty( $candidate ) || empty( $candidate['impact'] ) ) {
				continue;
			}

			if ( empty( $best ) || (float) $candidate['impact'] > (float) ( $best['impact'] ?? 0 ) ) {
				$best = $candidate;
			}
		}

		return $best;
	}

	private static function event_risk_impact( array $event ): array {
		$event_type = sanitize_key( (string) ( $event['event_type'] ?? '' ) );
		$metadata   = is_array( $event['metadata'] ?? null ) ? $event['metadata'] : array();
		$decay      = self::event_decay_multiplier( (string) ( $event['created_at'] ?? '' ) );
		$base       = 0.0;
		$label      = __( 'Blacklist Manager Premium: security signal', 'yoohw-customer-intelligence' );
		$description = __( 'This customer has a recent premium security signal.', 'yoohw-customer-intelligence' );

		if ( self::EVENT_ORDER_RISK === $event_type ) {
			$risk_score = max( 0, (int) ( $metadata['risk_score'] ?? 0 ) );
			$base       = min( 30, round( $risk_score * 0.35 ) );
			$label      = __( 'Blacklist Manager Premium: order risk', 'yoohw-customer-intelligence' );
			$description = sprintf(
				/* translators: %d: premium risk score. */
				__( 'Highest recent premium order risk score contributes by capped max score, not repeated event count. Latest candidate score: %d.', 'yoohw-customer-intelligence' ),
				$risk_score
			);
		} elseif ( self::EVENT_PAYMENT_ABUSE === $event_type ) {
			$base = 22;
			$label = __( 'Blacklist Manager Premium: payment abuse', 'yoohw-customer-intelligence' );
			$description = __( 'A matched checkout signal indicated premium payment abuse risk.', 'yoohw-customer-intelligence' );
		} elseif ( self::EVENT_ANTIBOT_BLOCKED === $event_type ) {
			$base = 18;
			$label = __( 'Blacklist Manager Premium: anti-bot blocked', 'yoohw-customer-intelligence' );
			$description = __( 'A matched checkout anti-bot block was recorded for this customer.', 'yoohw-customer-intelligence' );
		} elseif ( self::EVENT_DEVICE_SIGNAL === $event_type ) {
			$base = 12;
			$label = __( 'Blacklist Manager Premium: device signal', 'yoohw-customer-intelligence' );
			$description = __( 'A matched premium device or browser signal was recorded for this customer.', 'yoohw-customer-intelligence' );
		} elseif ( self::EVENT_GATEWAY_FRAUD === $event_type ) {
			$base = 15;
			$label = __( 'Blacklist Manager Premium: gateway fraud signal', 'yoohw-customer-intelligence' );
			$description = __( 'A matched premium gateway fraud signal was recorded for this customer.', 'yoohw-customer-intelligence' );
		} elseif ( self::EVENT_RULE_MATCH === $event_type ) {
			$rule_score = (int) ( $metadata['rule']['score'] ?? 0 );
			$base       = min( 15, round( $rule_score * 0.5 ) );
			$label      = __( 'Blacklist Manager Premium: rule match', 'yoohw-customer-intelligence' );
			$description = __( 'A premium risk rule matched this customer recently.', 'yoohw-customer-intelligence' );
		} elseif ( self::EVENT_ANTIBOT_WOULD_BLOCK === $event_type ) {
			$base = 5;
			$label = __( 'Blacklist Manager Premium: checkout challenge', 'yoohw-customer-intelligence' );
			$description = __( 'A checkout verification challenge was required; this is a light risk signal.', 'yoohw-customer-intelligence' );
		}

		$impact = (int) round( $base * $decay );

		if ( $impact <= 0 ) {
			return array();
		}

		return array(
			'impact'      => $impact,
			'label'       => $label,
			'description' => $description,
		);
	}

	private static function event_decay_multiplier( string $date ): float {
		$timestamp = YoOhw_COS_DB::date_timestamp( $date );

		if ( $timestamp <= 0 ) {
			return 0.2;
		}

		$days = (int) floor( ( current_time( 'timestamp' ) - $timestamp ) / DAY_IN_SECONDS );

		if ( $days <= 30 ) {
			return 1.0;
		}

		if ( $days <= 90 ) {
			return 0.5;
		}

		return 0.2;
	}

	private static function get_customer_premium_events( int $customer_id, int $limit = 50 ): array {
		$events = YoOhw_COS_Events::get_customer_events(
			$customer_id,
			array(
				'limit'        => $limit,
				'event_source' => self::EVENT_SOURCE,
			)
		);

		return $events;
	}

	private static function summary_order_risk_item( array $event, int $risk_score ): array {
		$metadata = is_array( $event['metadata'] ?? null ) ? $event['metadata'] : array();

		return array(
			'order_id'   => absint( $metadata['order_id'] ?? ( $event['object_id'] ?? 0 ) ),
			'risk_score' => max( 0, $risk_score ),
			'created_at' => sanitize_text_field( (string) ( $event['created_at'] ?? '' ) ),
			'job_hook'   => sanitize_key( (string) ( $metadata['job_hook'] ?? '' ) ),
		);
	}

	private static function security_event_metadata_summary( array $metadata ): array {
		return array_filter(
			array(
				'signal'           => sanitize_key( (string) ( $metadata['signal'] ?? '' ) ),
				'checkout_context' => sanitize_key( (string) ( $metadata['checkout_context'] ?? '' ) ),
				'score'            => isset( $metadata['score'] ) ? (int) $metadata['score'] : null,
				'threshold'        => isset( $metadata['threshold'] ) ? (int) $metadata['threshold'] : null,
				'reasons'          => self::sanitize_key_list( $metadata['reasons'] ?? array() ),
			),
			static function ( $value ) {
				return ! ( '' === $value || array() === $value || null === $value );
			}
		);
	}

	private static function event_type_for_detection_payload( array $payload ): string {
		$action = sanitize_key( (string) ( $payload['action'] ?? '' ) );
		$haystack = strtolower(
			implode(
				' ',
				array_merge(
					array(
						(string) ( $payload['source_rule'] ?? '' ),
						(string) ( $payload['signal'] ?? '' ),
					),
					self::sanitize_key_list( $payload['reasons'] ?? array() )
				)
			)
		);

		if ( false !== strpos( $haystack, 'payment_abuse' ) ) {
			return self::EVENT_PAYMENT_ABUSE;
		}

		if ( in_array( $action, array( 'would_block', 'shadow', 'challenge' ), true ) ) {
			return self::EVENT_ANTIBOT_WOULD_BLOCK;
		}

		if ( in_array( $action, array( 'block', 'blocked', 'cancel' ), true ) ) {
			return self::EVENT_ANTIBOT_BLOCKED;
		}

		return self::EVENT_DEVICE_SIGNAL;
	}

	private static function severity_for_detection_payload( array $payload, string $event_type ): string {
		if ( self::EVENT_ANTIBOT_WOULD_BLOCK === $event_type ) {
			return 'warning';
		}

		$score = max( 0, (int) ( $payload['score'] ?? 0 ) );

		if ( $score >= 70 || self::EVENT_ANTIBOT_BLOCKED === $event_type || self::EVENT_PAYMENT_ABUSE === $event_type ) {
			return 'error';
		}

		return $score > 0 ? 'warning' : 'info';
	}

	private static function backfill_detection_description( string $event_type, $order = null ): string {
		if ( self::EVENT_PAYMENT_ABUSE === $event_type ) {
			$description = __( 'Backfilled Blacklist Manager Premium payment-abuse detection log.', 'yoohw-customer-intelligence' );
		} elseif ( self::EVENT_ANTIBOT_WOULD_BLOCK === $event_type ) {
			$description = __( 'Backfilled Blacklist Manager Premium checkout challenge or would-block log.', 'yoohw-customer-intelligence' );
		} elseif ( self::EVENT_ANTIBOT_BLOCKED === $event_type ) {
			$description = __( 'Backfilled Blacklist Manager Premium anti-bot block log.', 'yoohw-customer-intelligence' );
		} else {
			$description = __( 'Backfilled Blacklist Manager Premium device signal log.', 'yoohw-customer-intelligence' );
		}

		if ( $order instanceof WC_Order ) {
			return sprintf(
				/* translators: 1: description, 2: order number. */
				__( '%1$s Order #%2$s.', 'yoohw-customer-intelligence' ),
				$description,
				$order->get_order_number()
			);
		}

		return $description;
	}

	private static function backfill_payment_abuse_description( $order = null ): string {
		$description = __( 'Backfilled Blacklist Manager Premium payment-abuse event.', 'yoohw-customer-intelligence' );

		if ( $order instanceof WC_Order ) {
			return sprintf(
				/* translators: 1: description, 2: order number. */
				__( '%1$s Order #%2$s.', 'yoohw-customer-intelligence' ),
				$description,
				$order->get_order_number()
			);
		}

		return $description;
	}

	private static function payment_abuse_metadata( array $row, int $score ): array {
		$failure_family = sanitize_key( (string) ( $row['failure_family'] ?? '' ) );
		$source         = sanitize_key( (string) ( $row['source'] ?? '' ) );
		$source_rule    = 'payment_abuse:' . ( $failure_family ?: ( $source ?: 'event' ) );

		return array_filter(
			array(
				'schema'       => 'premium_payment_abuse_backfill_v1',
				'payment_abuse_event_id' => absint( $row['id'] ?? 0 ),
				'order_id'     => absint( $row['order_id'] ?? 0 ),
				'signal'       => 'payment_abuse',
				'score'        => max( 0, $score ),
				'timestamp'    => self::normalize_created_at( $row['created_at'] ?? '' ),
				'source_rule'  => $source_rule,
				'source'       => $source,
				'gateway'      => sanitize_key( (string) ( $row['gateway'] ?? '' ) ),
				'gateway_profile' => sanitize_key( (string) ( $row['gateway_profile'] ?? '' ) ),
				'failure_code' => sanitize_key( (string) ( $row['failure_code'] ?? '' ) ),
				'failure_family' => $failure_family,
				'decline_code' => sanitize_key( (string) ( $row['decline_code'] ?? '' ) ),
				'hashes'       => self::privacy_hashes_from_row(
					$row,
					array(
						'cart_hash',
						'payment_reference_hash',
						'ip_hash',
						'ip_prefix_hash',
						'email_hash',
						'phone_hash',
						'device_hash',
						'session_hash',
						'user_hash',
						'user_agent_hash',
						'identity_hash',
					)
				),
			),
			static function ( $value ) {
				return ! ( '' === $value || 0 === $value || array() === $value || null === $value );
			}
		);
	}

	private static function score_for_payment_abuse_row( array $row ): int {
		$family = sanitize_key( (string) ( $row['failure_family'] ?? '' ) );
		$decline = sanitize_key( (string) ( $row['decline_code'] ?? '' ) );

		if ( false !== strpos( $family, 'high_risk' ) || false !== strpos( $family, 'fraud' ) || false !== strpos( $decline, 'fraud' ) || false !== strpos( $decline, 'risk' ) ) {
			return 35;
		}

		if ( false !== strpos( $family, 'card_validation' ) || false !== strpos( $family, 'authentication' ) ) {
			return 25;
		}

		return 15;
	}

	private static function signal_label_from_detection( string $source, string $action, array $reasons ): string {
		foreach ( $reasons as $reason ) {
			if ( false !== strpos( $reason, 'payment_abuse' ) ) {
				return 'payment_abuse';
			}
		}

		if ( 'paypal_payments_create_order' === $source ) {
			return 'paypal_checkout_antibot';
		}

		if ( in_array( $action, array( 'would_block', 'shadow', 'challenge' ), true ) ) {
			return 'checkout_challenge';
		}

		return 'checkout_antibot';
	}

	private static function reason_codes_from_text( string $text ): array {
		if ( '' === $text ) {
			return array();
		}

		preg_match_all( '/[a-z0-9_:-]{4,}/i', strtolower( $text ), $matches );
		$tokens = array();

		foreach ( (array) ( $matches[0] ?? array() ) as $token ) {
			$token = sanitize_key( str_replace( ':', '_', (string) $token ) );

			if (
				'' === $token
				|| in_array( $token, array( 'score', 'threshold', 'checkout', 'premium', 'blacklist', 'manager' ), true )
			) {
				continue;
			}

			$tokens[] = $token;
		}

		return array_slice( array_values( array_unique( $tokens ) ), 0, 8 );
	}

	private static function identity_hashes_from_detection_view( array $view, array $identity ): array {
		$request = is_array( $view['request'] ?? null ) ? $view['request'] : array();
		$hashes  = array();

		foreach (
			array(
				'ip_hash'                => $view['ip_hash'] ?? ( $request['ip_hash'] ?? ( $identity['ip_hash'] ?? '' ) ),
				'ip_address_hash'        => $view['ip_address'] ?? ( $request['ip'] ?? '' ),
				'user_agent_hash'        => $request['user_agent'] ?? ( $identity['user_agent_hash'] ?? '' ),
				'wc_session_prefix_hash' => $identity['wc_session_prefix'] ?? '',
			) as $key => $value
		) {
			$hash = self::privacy_hash( $value );

			if ( '' !== $hash ) {
				$hashes[ $key ] = $hash;
			}
		}

		return $hashes;
	}

	private static function privacy_hashes_from_row( array $row, array $keys ): array {
		$hashes = array();

		foreach ( $keys as $key ) {
			$key   = sanitize_key( (string) $key );
			$value = (string) ( $row[ $key ] ?? '' );
			$hash  = self::privacy_hash( $value );

			if ( '' !== $hash ) {
				$hashes[ $key ] = $hash;
			}
		}

		return $hashes;
	}

	private static function privacy_hash( $value ): string {
		$value = sanitize_text_field( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		return hash_hmac( 'sha256', $value, wp_salt( 'auth' ) );
	}

	private static function order_id_from_detection_source( string $source ): int {
		if ( preg_match( '/^woo_order_(\d+)$/', $source, $matches ) ) {
			return absint( $matches[1] ?? 0 );
		}

		return 0;
	}

	private static function decode_json_array( string $json ): array {
		if ( '' === trim( $json ) ) {
			return array();
		}

		$decoded = json_decode( $json, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	private static function event_type_for_rule_category( string $category ): string {
		$category = sanitize_key( $category );

		if ( 'device' === $category ) {
			return self::EVENT_DEVICE_SIGNAL;
		}

		if ( 'gateway' === $category ) {
			return self::EVENT_GATEWAY_FRAUD;
		}

		if ( 'payment' === $category ) {
			return self::EVENT_PAYMENT_ABUSE;
		}

		return self::EVENT_RULE_MATCH;
	}

	private static function severity_for_order_risk( int $risk_score ): string {
		if ( $risk_score >= 70 ) {
			return 'error';
		}

		if ( $risk_score >= 25 ) {
			return 'warning';
		}

		return 'info';
	}

	private static function severity_for_rule_score( int $rule_score ): string {
		if ( $rule_score >= 40 ) {
			return 'error';
		}

		return $rule_score > 0 ? 'warning' : 'info';
	}

	private static function format_rule_label( string $key ): string {
		return ucwords( str_replace( array( '_', '-' ), ' ', sanitize_key( $key ) ) );
	}

	private static function stable_object_id( array $parts ): int {
		$material = implode( '|', array_map( 'strval', $parts ) );

		return (int) sprintf( '%u', crc32( $material ) );
	}

	private static function sanitize_metadata( array $metadata ): array {
		$clean = array();

		foreach ( $metadata as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$clean[ $key ] = self::sanitize_metadata( $value );
			} elseif ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
				$clean[ $key ] = $value;
			} else {
				$clean[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		return $clean;
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

		if ( '' === $created_at || YoOhw_COS_DB::date_timestamp( $created_at ) <= 0 ) {
			return '';
		}

		return $created_at;
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
}
