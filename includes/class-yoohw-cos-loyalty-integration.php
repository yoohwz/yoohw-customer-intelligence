<?php
defined( 'ABSPATH' ) || exit;

final class YoOhw_COS_Loyalty_Integration {

	private const EVENT_SOURCE = 'wc_loyalty';
	private const TASK_AUTOMATION_OPTION = 'yoohw_cos_loyalty_task_automation';
	private const BACKFILL_STATE_OPTION = 'yoohw_cos_loyalty_backfill_state';
	private const BACKFILL_HOOK = 'yoohw_cos_backfill_loyalty_history';
	private const BACKFILL_VERSION = 1;

	public static function init(): void {
		if ( ! self::is_loyalty_plugin_active() ) {
			return;
		}

		add_filter( 'yoohw_cos_customer_sync_data', array( __CLASS__, 'inject_customer_sync_data' ), 10, 3 );
		add_filter( 'yoohw_cos_customer_recalculate_intelligence_data', array( __CLASS__, 'inject_recalculation_data' ), 10, 2 );

		add_action( 'yowcl_points_log_created', array( __CLASS__, 'handle_points_log_created' ), 10, 3 );
		add_action( 'yowcl_user_loyalty_role_updated', array( __CLASS__, 'handle_loyalty_role_updated' ), 10, 3 );
		add_action( 'yowcl_points_reconciliation_issue_found', array( __CLASS__, 'handle_points_reconciliation_issue_found' ), 10, 2 );
		add_action( 'yoohw_cos_customer_intelligence_recalculated', array( __CLASS__, 'handle_customer_intelligence_recalculated' ), 10, 4 );
		add_action( self::BACKFILL_HOOK, array( __CLASS__, 'process_legacy_points_backfill' ) );

		self::maybe_schedule_legacy_points_backfill();
	}

	public static function is_loyalty_plugin_active(): bool {
		return self::is_loyalty_plugin_loaded_or_active() && self::is_loyalty_license_active();
	}

	private static function is_loyalty_plugin_loaded_or_active(): bool {
		if (
			defined( 'WC_LOYALTY_PLUGIN_FILE' )
			&& class_exists( 'YOWCL_Loyalty' )
		) {
			return true;
		}

		if ( class_exists( 'YOWCL_Helper_Roles' ) || class_exists( 'YOWCL_Backend' ) ) {
			return true;
		}

		$plugin_basename = 'wc-loyalty/wc-loyalty.php';

		if ( function_exists( 'is_plugin_active' ) && is_plugin_active( $plugin_basename ) ) {
			return true;
		}

		$active_plugins = (array) get_option( 'active_plugins', array() );

		if ( in_array( $plugin_basename, $active_plugins, true ) ) {
			return true;
		}

		if ( is_multisite() ) {
			$network_plugins = (array) get_site_option( 'active_sitewide_plugins', array() );

			if ( isset( $network_plugins[ $plugin_basename ] ) ) {
				return true;
			}
		}

		return false;
	}

	public static function is_loyalty_license_active(): bool {
		if ( function_exists( 'yowcl_premium_license_active' ) ) {
			return (bool) yowcl_premium_license_active();
		}

		if ( class_exists( 'YOWCL_Backend' ) && is_callable( array( 'YOWCL_Backend', 'is_activated' ) ) ) {
			return (bool) YOWCL_Backend::is_activated();
		}

		if ( class_exists( 'YOWCL_Premium_Gate' ) && is_callable( array( 'YOWCL_Premium_Gate', 'is_active' ) ) ) {
			return (bool) YOWCL_Premium_Gate::is_active();
		}

		if ( class_exists( 'YOWCL_License_Validator' ) && is_callable( array( 'YOWCL_License_Validator', 'is_premium_active' ) ) ) {
			return (bool) YOWCL_License_Validator::is_premium_active();
		}

		return false;
	}

	public static function inject_customer_sync_data( array $data, $order, int $customer_id ): array {
		$user_id = is_a( $order, 'WC_Order' ) ? absint( $order->get_customer_id() ) : 0;

		if ( $user_id <= 0 ) {
			return $data;
		}

		$data = array_merge( $data, self::get_user_loyalty_customer_data( $user_id ) );

		return $data;
	}

	public static function inject_recalculation_data( array $customer, int $customer_id ): array {
		$user_id = absint( $customer['wp_user_id'] ?? 0 );

		if ( $user_id <= 0 ) {
			return $customer;
		}

		$customer = array_merge( $customer, self::get_user_loyalty_customer_data( $user_id ) );

		return $customer;
	}

	public static function handle_points_log_created( int $log_id, array $payload, array $context = array() ): void {
		$user_id = absint( $payload['user_id'] ?? 0 );

		if ( $user_id <= 0 || $log_id <= 0 ) {
			return;
		}

		$customer_id = self::sync_user_to_customer_profile( $user_id );

		if ( $customer_id <= 0 ) {
			return;
		}

		self::record_points_event( $customer_id, $user_id, $log_id, $payload, $context );
		self::maybe_create_points_log_tasks( $customer_id, $user_id, $log_id, $payload, $context );
	}

	public static function handle_loyalty_role_updated( int $user_id, string $new_role, string $old_role = '' ): void {
		$user_id = absint( $user_id );

		if ( $user_id <= 0 ) {
			return;
		}

		$new_role = sanitize_key( $new_role );
		$old_role = sanitize_key( $old_role );

		if ( '' !== $old_role && $old_role === $new_role ) {
			return;
		}

		$customer_id = self::sync_user_to_customer_profile( $user_id, $new_role );

		if ( $customer_id <= 0 ) {
			return;
		}

		YoOhw_COS_Events::record(
			array(
				'customer_id'  => $customer_id,
				'wp_user_id'   => $user_id,
				'event_type'   => 'loyalty_level_changed',
				'event_source' => self::EVENT_SOURCE,
				'severity'     => 'success',
				'object_type'  => 'user',
				'object_id'    => $user_id,
				'description'  => sprintf(
					/* translators: 1: old loyalty level, 2: new loyalty level. */
					__( 'Loyalty level changed from %1$s to %2$s.', 'yoohw-customer-intelligence' ),
					self::format_role_label( $old_role ?: 'none' ),
					self::format_role_label( $new_role )
				),
				'metadata'     => self::get_user_loyalty_metadata(
					$user_id,
					array(
						'old_level' => $old_role,
						'new_level' => $new_role,
					)
				),
			)
		);

		self::maybe_create_level_change_task( $customer_id, $user_id, $new_role, $old_role );
	}

	public static function handle_customer_intelligence_recalculated( int $customer_id, array $customer, array $previous_customer = array(), bool $updated = false ): void {
		$customer_id = absint( $customer_id );

		if ( $customer_id <= 0 ) {
			return;
		}

		self::maybe_create_dormant_points_task( $customer_id, $customer );
	}

	public static function handle_points_reconciliation_issue_found( array $issue, array $context = array() ): void {
		$user_id = absint( $issue['user_id'] ?? 0 );

		if ( $user_id <= 0 ) {
			return;
		}

		$settings = self::get_task_automation_settings();

		if ( 'yes' !== $settings['enabled'] || 'yes' !== $settings['reconciliation_enabled'] ) {
			return;
		}

		$customer_id = self::sync_user_to_customer_profile( $user_id );

		if ( $customer_id <= 0 ) {
			return;
		}

		self::maybe_create_reconciliation_issue_task( $customer_id, $user_id, $issue, $context );
	}

	private static function sync_user_to_customer_profile(
		int $user_id,
		string $role_slug = '',
		bool $touch_activity = true,
		string $initial_activity_date = ''
	): int {
		$user_id = absint( $user_id );

		if ( $user_id <= 0 ) {
			return 0;
		}

		$customer_id = self::find_or_create_customer_for_user( $user_id, $initial_activity_date );

		if ( $customer_id <= 0 ) {
			return 0;
		}

		$customer_data = array_merge(
			array( 'wp_user_id' => $user_id ),
			self::get_user_loyalty_customer_data( $user_id, $role_slug )
		);

		if ( $touch_activity ) {
			$customer_data['last_activity_date'] = YoOhw_COS_DB::now();
		}

		YoOhw_COS_Customers::update_customer( $customer_id, $customer_data );

		return $customer_id;
	}

	private static function find_or_create_customer_for_user( int $user_id, string $initial_activity_date = '' ): int {
		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User ) {
			return 0;
		}

		$email = sanitize_email( (string) $user->user_email );

		$customer_id = YoOhw_COS_Customers::find_customer_id(
			array(
				'wp_user_id' => $user_id,
				'email'      => $email,
			)
		);

		if ( $customer_id > 0 ) {
			$customer = YoOhw_COS_Customers::get_customer( $customer_id );

			if ( empty( $customer['wp_user_id'] ) ) {
				YoOhw_COS_Customers::update_customer( $customer_id, array( 'wp_user_id' => $user_id ) );
			}

			return $customer_id;
		}

		if ( '' === $email ) {
			return 0;
		}

		return YoOhw_COS_Customers::create_customer(
			array_merge(
				array(
					'wp_user_id'          => $user_id,
					'email'               => $email,
					'first_name'          => sanitize_text_field( (string) get_user_meta( $user_id, 'first_name', true ) ),
					'last_name'           => sanitize_text_field( (string) get_user_meta( $user_id, 'last_name', true ) ),
					'display_name'        => sanitize_text_field( (string) $user->display_name ),
					'total_orders'        => 0,
					'total_spent'         => 0,
					'average_order_value' => 0,
					'customer_status'     => 'active',
					'vip_status'          => 'none',
					'lifecycle_stage'     => 'new',
					'last_activity_date'  => self::normalize_event_date( $initial_activity_date ) ?: YoOhw_COS_DB::now(),
				),
				self::get_user_loyalty_customer_data( $user_id )
			)
		);
	}

	private static function record_points_event( int $customer_id, int $user_id, int $log_id, array $payload, array $context = array() ): bool {
		$event_type = self::map_points_event_type( sanitize_key( (string) ( $payload['action'] ?? '' ) ), (float) ( $payload['amount'] ?? 0 ) );

		$event_args = array(
			'event_key'    => YoOhw_COS_Events::make_event_key( self::EVENT_SOURCE, $event_type, 'loyalty_points_log', $log_id ),
			'customer_id'  => $customer_id,
			'wp_user_id'   => $user_id,
			'event_type'   => $event_type,
			'event_source' => self::EVENT_SOURCE,
			'severity'     => self::get_points_event_severity( $event_type ),
			'object_type'  => 'loyalty_points_log',
			'object_id'    => $log_id,
			'description'  => self::build_points_event_description( $event_type, $payload ),
			'metadata'     => self::get_user_loyalty_metadata(
				$user_id,
				array(
					'points_log_id' => $log_id,
					'log_action'    => sanitize_key( (string) ( $payload['action'] ?? '' ) ),
					'amount'        => isset( $payload['amount'] ) ? (float) $payload['amount'] : 0,
					'order_id'      => absint( $payload['order_id'] ?? 0 ),
					'context'       => $context,
				)
			),
		);
		$created_at = self::normalize_event_date( (string) ( $payload['date'] ?? '' ) );

		if ( '' !== $created_at ) {
			$event_args['created_at'] = $created_at;
		}

		return YoOhw_COS_Events::record( $event_args ) > 0;
	}

	public static function backfill_legacy_points_logs( int $limit = 200, int $page = 1 ): array {
		global $wpdb;

		$limit  = min( 500, max( 1, absint( $limit ) ) );
		$page   = max( 1, absint( $page ) );
		$table  = $wpdb->prefix . 'yo_loyalty_points_log';
		$result = array(
			'scanned'   => 0,
			'processed' => 0,
			'skipped'   => 0,
			'has_more'  => false,
			'next_page' => $page,
		);

		if ( ! self::table_exists( $table ) ) {
			return $result;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id, action, order_id, amount, description, date
				FROM %i
				ORDER BY id ASC
				LIMIT %d OFFSET %d",
				$table,
				$limit,
				( $page - 1 ) * $limit
			),
			ARRAY_A
		);

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$result['scanned']++;
			$user_id = absint( $row['user_id'] ?? 0 );

			if ( $user_id <= 0 ) {
				$result['skipped']++;
				continue;
			}

			$customer_id = self::sync_user_to_customer_profile(
				$user_id,
				'',
				false,
				(string) ( $row['date'] ?? '' )
			);

			if (
				$customer_id > 0
				&& self::record_points_event(
					$customer_id,
					$user_id,
					absint( $row['id'] ?? 0 ),
					$row,
					array( 'backfilled' => true )
				)
			) {
				$result['processed']++;
			} else {
				$result['skipped']++;
			}
		}

		$result['has_more'] = count( $rows ) >= $limit;
		$result['next_page'] = $result['has_more'] ? $page + 1 : $page;

		return $result;
	}

	public static function process_legacy_points_backfill(): void {
		$state = get_option( self::BACKFILL_STATE_OPTION, array() );
		$state = is_array( $state ) ? $state : array();

		if (
			self::BACKFILL_VERSION === absint( $state['version'] ?? 0 )
			&& 'completed' === (string) ( $state['status'] ?? '' )
		) {
			return;
		}

		$previous_state = $state;
		$page           = max( 1, absint( $previous_state['next_page'] ?? 1 ) );
		$result         = self::backfill_legacy_points_logs( 200, $page );

		$state = array(
			'version'        => self::BACKFILL_VERSION,
			'status'         => ! empty( $result['has_more'] ) ? 'in_progress' : 'completed',
			'next_page'      => absint( $result['next_page'] ?? $page ),
			'total_scanned'  => absint( $previous_state['total_scanned'] ?? 0 ) + absint( $result['scanned'] ?? 0 ),
			'total_imported' => absint( $previous_state['total_imported'] ?? 0 ) + absint( $result['processed'] ?? 0 ),
			'updated_at'     => YoOhw_COS_DB::now(),
		);

		update_option( self::BACKFILL_STATE_OPTION, $state, false );

		if ( 'in_progress' === $state['status'] && ! wp_next_scheduled( self::BACKFILL_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::BACKFILL_HOOK );
		}
	}

	private static function maybe_schedule_legacy_points_backfill(): void {
		$state = get_option( self::BACKFILL_STATE_OPTION, array() );
		$state = is_array( $state ) ? $state : array();

		if (
			self::BACKFILL_VERSION === absint( $state['version'] ?? 0 )
			&& 'completed' === (string) ( $state['status'] ?? '' )
		) {
			return;
		}

		if ( ! wp_next_scheduled( self::BACKFILL_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::BACKFILL_HOOK );
		}
	}

	private static function normalize_event_date( string $date ): string {
		$date = sanitize_text_field( $date );

		return '' !== $date && YoOhw_COS_DB::date_timestamp( $date ) > 0 ? $date : '';
	}

	private static function table_exists( string $table ): bool {
		global $wpdb;

		if ( '' === $table ) {
			return false;
		}

		$previous_suppression = $wpdb->suppress_errors( true );
		$wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 1', $table ) );
		$exists = '' === (string) $wpdb->last_error;
		$wpdb->suppress_errors( $previous_suppression );

		return $exists;
	}

	private static function map_points_event_type( string $action, float $amount ): string {
		if ( in_array( $action, array( 'points_used', 'points_redeem', 'points_redeem_shipping', 'points_redeem_product' ), true ) ) {
			return 'loyalty_points_redeemed';
		}

		if ( in_array( $action, array( 'points_expired', 'points_zeroed', 'points_zero_all' ), true ) || false !== strpos( $action, 'expired' ) ) {
			return 'loyalty_points_expired';
		}

		if ( in_array( $action, array( 'points_return', 'points_returned', 'inactivity_return_reward' ), true ) ) {
			return 'loyalty_points_returned';
		}

		if ( $amount < 0 || in_array( $action, array( 'admin_deduct', 'points_deducted', 'referral_reward_reversal' ), true ) ) {
			return 'loyalty_points_deducted';
		}

		if ( false !== strpos( $action, 'deduct' ) || false !== strpos( $action, 'reversal' ) ) {
			return 'loyalty_points_deducted';
		}

		return 'loyalty_points_rewarded';
	}

	private static function get_points_event_severity( string $event_type ): string {
		if ( in_array( $event_type, array( 'loyalty_points_deducted', 'loyalty_points_expired' ), true ) ) {
			return 'warning';
		}

		if ( 'loyalty_points_rewarded' === $event_type || 'loyalty_points_returned' === $event_type ) {
			return 'success';
		}

		return 'info';
	}

	private static function build_points_event_description( string $event_type, array $payload ): string {
		$amount = abs( (float) ( $payload['amount'] ?? 0 ) );

		if ( 'loyalty_points_rewarded' === $event_type ) {
			return sprintf(
				/* translators: %s: points amount. */
				__( 'Loyalty points rewarded: +%s.', 'yoohw-customer-intelligence' ),
				number_format_i18n( $amount, 0 )
			);
		}

		if ( 'loyalty_points_redeemed' === $event_type ) {
			return sprintf(
				/* translators: %s: points amount. */
				__( 'Loyalty points redeemed: %s.', 'yoohw-customer-intelligence' ),
				number_format_i18n( $amount, 0 )
			);
		}

		if ( 'loyalty_points_returned' === $event_type ) {
			return sprintf(
				/* translators: %s: points amount. */
				__( 'Loyalty points returned: %s.', 'yoohw-customer-intelligence' ),
				number_format_i18n( $amount, 0 )
			);
		}

		if ( 'loyalty_points_expired' === $event_type ) {
			return sprintf(
				/* translators: %s: points amount. */
				__( 'Loyalty points expired: %s.', 'yoohw-customer-intelligence' ),
				number_format_i18n( $amount, 0 )
			);
		}

		return sprintf(
			/* translators: %s: points amount. */
			__( 'Loyalty points deducted: %s.', 'yoohw-customer-intelligence' ),
			number_format_i18n( $amount, 0 )
		);
	}

	private static function calculate_loyalty_score( int $user_id ): float {
		if ( ! self::is_loyalty_plugin_active() ) {
			return 0.0;
		}

		$user_id        = absint( $user_id );
		$points_balance = (int) get_user_meta( $user_id, 'user_points', true );
		$earning_points = (int) get_user_meta( $user_id, 'user_earning_points', true );
		$rules          = maybe_unserialize( get_option( 'loyalty_levels_rules', array() ) );
		$rules          = is_array( $rules ) ? $rules : array();
		$highest        = 0;

		foreach ( $rules as $rule ) {
			if ( is_array( $rule ) && isset( $rule['from'] ) ) {
				$highest = max( $highest, (int) $rule['from'] );
			}
		}

		if ( $highest > 0 ) {
			return max( 0.0, min( 100.0, round( ( $earning_points / $highest ) * 100, 2 ) ) );
		}

		if ( $earning_points > 0 ) {
			return max( 0.0, min( 100.0, round( 40 + min( 60, $earning_points / 10 ), 2 ) ) );
		}

		return $points_balance > 0 ? 20.0 : 0.0;
	}

	public static function get_user_loyalty_customer_data( int $user_id, string $role_slug = '' ): array {
		if ( ! self::is_loyalty_plugin_active() ) {
			return array(
				'loyalty_score'    => 0.0,
				'loyalty_level'    => '',
				'available_points' => 0,
				'earned_points'    => 0,
			);
		}

		$user_id   = absint( $user_id );
		$role_slug = '' !== $role_slug ? sanitize_key( $role_slug ) : self::get_user_loyalty_role( $user_id );

		return array(
			'loyalty_score'    => self::calculate_loyalty_score( $user_id ),
			'loyalty_level'    => $role_slug,
			'available_points' => (int) get_user_meta( $user_id, 'user_points', true ),
			'earned_points'    => (int) get_user_meta( $user_id, 'user_earning_points', true ),
		);
	}

	public static function get_task_automation_settings(): array {
		$saved = get_option( self::TASK_AUTOMATION_OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		return self::sanitize_task_automation_settings( array_merge( self::get_task_automation_defaults(), $saved ) );
	}

	public static function update_task_automation_settings( array $source ): array {
		if ( ! isset( $source['vip_levels'] ) ) {
			$source['vip_levels'] = array();
		}

		$settings = self::sanitize_task_automation_settings( $source );

		update_option( self::TASK_AUTOMATION_OPTION, $settings, false );

		return $settings;
	}

	public static function get_task_automation_defaults(): array {
		return array(
			'enabled'                   => 'yes',
			'vip_level_up_enabled'      => 'yes',
			'vip_levels'                => self::get_default_high_value_loyalty_levels(),
			'downgrade_enabled'         => 'yes',
			'large_redemption_enabled'  => 'yes',
			'large_redemption_points'   => 500,
			'negative_balance_enabled'  => 'yes',
			'dormant_points_enabled'    => 'yes',
			'dormant_points_threshold'  => 500,
			'reconciliation_enabled'     => 'no',
			'assigned_user_id'          => 0,
			'due_days'                  => 1,
		);
	}

	private static function sanitize_task_automation_settings( array $source ): array {
		$defaults = self::get_task_automation_defaults();
		$booleans = array(
			'enabled',
			'vip_level_up_enabled',
			'downgrade_enabled',
			'large_redemption_enabled',
			'negative_balance_enabled',
			'dormant_points_enabled',
			'reconciliation_enabled',
		);
		$settings = $defaults;

		foreach ( $booleans as $key ) {
			$settings[ $key ] = ! empty( $source[ $key ] ) && 'no' !== (string) $source[ $key ] ? 'yes' : 'no';
		}

		$settings['vip_levels'] = self::sanitize_loyalty_role_list( $source['vip_levels'] ?? $defaults['vip_levels'] );

		$settings['large_redemption_points']  = max( 1, absint( $source['large_redemption_points'] ?? $defaults['large_redemption_points'] ) );
		$settings['dormant_points_threshold'] = max( 1, absint( $source['dormant_points_threshold'] ?? $defaults['dormant_points_threshold'] ) );
		$settings['due_days']                 = min( 30, max( 0, absint( $source['due_days'] ?? $defaults['due_days'] ) ) );
		$settings['assigned_user_id']         = absint( $source['assigned_user_id'] ?? 0 );

		if ( $settings['assigned_user_id'] > 0 && ! YoOhw_COS_Tasks::is_assignable_user( $settings['assigned_user_id'] ) ) {
			$settings['assigned_user_id'] = 0;
		}

		return $settings;
	}

	private static function sanitize_loyalty_role_list( $roles ): array {
		$roles = is_array( $roles ) ? $roles : array();
		$roles = array_values( array_unique( array_filter( array_map( 'sanitize_key', $roles ) ) ) );
		$configured_roles = self::get_configured_loyalty_roles();

		if ( empty( $configured_roles ) ) {
			return $roles;
		}

		return array_values( array_intersect( $roles, $configured_roles ) );
	}

	private static function maybe_create_level_change_task( int $customer_id, int $user_id, string $new_role, string $old_role = '' ): void {
		if ( ! class_exists( 'YoOhw_COS_Tasks' ) || ! is_callable( array( 'YoOhw_COS_Tasks', 'create_idempotent_task' ) ) ) {
			return;
		}

		$settings = self::get_task_automation_settings();

		if ( 'yes' !== $settings['enabled'] ) {
			return;
		}

		$new_role = sanitize_key( $new_role );
		$old_role = sanitize_key( $old_role );

		if (
			'yes' === $settings['vip_level_up_enabled']
			&& in_array( $new_role, (array) $settings['vip_levels'], true )
			&& self::compare_loyalty_levels( $new_role, $old_role ) > 0
		) {
			self::create_automation_task(
				'loyalty_level_up:' . $user_id . ':' . ( $old_role ?: 'none' ) . ':' . $new_role,
				$customer_id,
				array(
					'title'       => sprintf(
						/* translators: %s: loyalty level label. */
						__( 'Follow up new %s loyalty customer', 'yoohw-customer-intelligence' ),
						self::format_role_label( $new_role )
					),
					'description' => sprintf(
						/* translators: 1: old loyalty level, 2: new loyalty level. */
						__( 'Customer moved from %1$s to %2$s. Review whether a high-value follow-up, thank-you note, or retention offer is appropriate.', 'yoohw-customer-intelligence' ),
						self::format_role_label( $old_role ?: 'none' ),
						self::format_role_label( $new_role )
					),
					'priority'    => 'high',
				),
				$settings
			);
		}

		if (
			'yes' === $settings['downgrade_enabled']
			&& '' !== $old_role
			&& self::compare_loyalty_levels( $new_role, $old_role ) < 0
		) {
			self::create_automation_task(
				'loyalty_level_downgrade:' . $user_id . ':' . $old_role . ':' . ( $new_role ?: 'none' ),
				$customer_id,
				array(
					'title'       => sprintf(
						/* translators: 1: old loyalty level, 2: new loyalty level. */
						__( 'Review loyalty level change: %1$s to %2$s', 'yoohw-customer-intelligence' ),
						self::format_role_label( $old_role ),
						self::format_role_label( $new_role ?: 'none' )
					),
					'description' => __( 'Customer loyalty level was downgraded or reset. Check whether this was expected before the customer is contacted by future loyalty campaigns.', 'yoohw-customer-intelligence' ),
					'priority'    => 'high',
				),
				$settings
			);
		}
	}

	private static function maybe_create_dormant_points_task( int $customer_id, array $customer ): void {
		if ( ! class_exists( 'YoOhw_COS_Tasks' ) || ! is_callable( array( 'YoOhw_COS_Tasks', 'create_idempotent_task' ) ) ) {
			return;
		}

		$settings = self::get_task_automation_settings();

		if ( 'yes' !== $settings['enabled'] || 'yes' !== $settings['dormant_points_enabled'] ) {
			return;
		}

		$wp_user_id       = absint( $customer['wp_user_id'] ?? 0 );
		$lifecycle_stage  = sanitize_key( (string) ( $customer['lifecycle_stage'] ?? '' ) );
		$available_points = (int) ( $customer['available_points'] ?? 0 );
		$threshold        = max( 1, absint( $settings['dormant_points_threshold'] ?? 500 ) );

		if ( $wp_user_id <= 0 || 'dormant' !== $lifecycle_stage || $available_points < $threshold ) {
			return;
		}

		self::create_automation_task(
			'loyalty_dormant_points:' . $customer_id,
			$customer_id,
			array(
				'title'       => sprintf(
					/* translators: %s: available loyalty points. */
					__( 'Follow up dormant customer with %s loyalty points', 'yoohw-customer-intelligence' ),
					number_format_i18n( $available_points )
				),
				'description' => sprintf(
					/* translators: 1: available loyalty points, 2: threshold points. */
					__( 'Customer is dormant and still has %1$s available loyalty points, above the %2$s point threshold. Review whether a win-back task or points reminder is appropriate.', 'yoohw-customer-intelligence' ),
					number_format_i18n( $available_points ),
					number_format_i18n( $threshold )
				),
				'priority'    => 'high',
			),
			$settings
		);
	}

	private static function maybe_create_reconciliation_issue_task( int $customer_id, int $user_id, array $issue, array $context = array() ): void {
		if ( ! class_exists( 'YoOhw_COS_Tasks' ) || ! is_callable( array( 'YoOhw_COS_Tasks', 'create_idempotent_task' ) ) ) {
			return;
		}

		$settings = self::get_task_automation_settings();

		if ( 'yes' !== $settings['enabled'] || 'yes' !== $settings['reconciliation_enabled'] ) {
			return;
		}

		$issue_codes = array_values( array_filter( array_map( 'sanitize_key', (array) ( $issue['issue_codes'] ?? array() ) ) ) );
		$issue_text  = array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $issue['issues'] ?? array() ) ) ) );

		if ( empty( $issue_codes ) ) {
			return;
		}

		$is_urgent = (bool) array_intersect( $issue_codes, array( 'negative_points', 'negative_earning' ) );
		$hash      = substr( md5( implode( '|', $issue_codes ) ), 0, 12 );

		self::create_automation_task(
			'loyalty_reconciliation_issue:' . $user_id . ':' . $hash,
			$customer_id,
			array(
				'title'       => $is_urgent
					? __( 'Review urgent loyalty reconciliation issue', 'yoohw-customer-intelligence' )
					: __( 'Review loyalty reconciliation mismatch', 'yoohw-customer-intelligence' ),
				'description' => self::build_reconciliation_issue_description( $issue, $issue_text, $issue_codes ),
				'priority'    => $is_urgent ? 'urgent' : 'high',
			),
			$settings
		);
	}

	private static function maybe_create_points_log_tasks( int $customer_id, int $user_id, int $log_id, array $payload, array $context = array() ): void {
		if ( ! class_exists( 'YoOhw_COS_Tasks' ) || ! is_callable( array( 'YoOhw_COS_Tasks', 'create_idempotent_task' ) ) ) {
			return;
		}

		$settings = self::get_task_automation_settings();

		if ( 'yes' !== $settings['enabled'] ) {
			return;
		}

		$amount         = (float) ( $payload['amount'] ?? 0 );
		$absolute       = abs( $amount );
		$points_balance = (int) ( $payload['points_balance'] ?? get_user_meta( $user_id, 'user_points', true ) );
		$order_id       = absint( $payload['order_id'] ?? 0 );
		$event_type     = self::map_points_event_type( sanitize_key( (string) ( $payload['action'] ?? '' ) ), $amount );

		if (
			'yes' === $settings['large_redemption_enabled']
			&& 'loyalty_points_redeemed' === $event_type
			&& $absolute >= (int) $settings['large_redemption_points']
		) {
			self::create_automation_task(
				'loyalty_large_redemption:' . $log_id,
				$customer_id,
				array(
					'order_id'     => $order_id,
					'title'        => sprintf(
						/* translators: %s: points amount. */
						__( 'Review large loyalty redemption: %s points', 'yoohw-customer-intelligence' ),
						number_format_i18n( $absolute, 0 )
					),
					'description'  => sprintf(
						/* translators: 1: points amount, 2: remaining points balance. */
						__( 'Customer redeemed %1$s loyalty points. Current available balance is %2$s points. Review for high-value care, retention intent, or unusual redemption behavior.', 'yoohw-customer-intelligence' ),
						number_format_i18n( $absolute, 0 ),
						number_format_i18n( $points_balance )
					),
					'priority'     => 'high',
				),
				$settings
			);
		}

		if ( 'yes' === $settings['negative_balance_enabled'] && $points_balance < 0 ) {
			self::create_automation_task(
				'loyalty_negative_balance:' . $user_id,
				$customer_id,
				array(
					'order_id'     => $order_id,
					'title'        => __( 'Review negative loyalty points balance', 'yoohw-customer-intelligence' ),
					'description'  => sprintf(
						/* translators: 1: current points balance, 2: loyalty points log id. */
						__( 'Customer has a negative available points balance of %1$s after loyalty log #%2$s. Reconcile points before further loyalty adjustments.', 'yoohw-customer-intelligence' ),
						number_format_i18n( $points_balance ),
						absint( $log_id )
					),
					'priority'     => 'urgent',
				),
				$settings
			);
		}
	}

	private static function create_automation_task( string $source_key, int $customer_id, array $task_data, array $settings ): int {
		$data = array_merge(
			array(
				'customer_id'       => $customer_id,
				'assigned_user_id'  => absint( $settings['assigned_user_id'] ?? 0 ),
				'due_date'          => self::get_automation_due_date( absint( $settings['due_days'] ?? 1 ) ),
				'priority'          => 'normal',
				'status'            => YoOhw_COS_Tasks::STATUS_OPEN,
			),
			$task_data
		);

		return YoOhw_COS_Tasks::create_idempotent_task( $source_key, $data );
	}

	private static function build_reconciliation_issue_description( array $issue, array $issue_text, array $issue_codes ): string {
		$parts = array();

		if ( ! empty( $issue_text ) ) {
			$parts[] = sprintf(
				/* translators: %s: reconciliation issue descriptions. */
				__( 'Detected issues: %s.', 'yoohw-customer-intelligence' ),
				implode( '; ', $issue_text )
			);
		}

		$parts[] = sprintf(
			/* translators: 1: current points, 2: expected points, 3: current earned points, 4: expected earned points. */
			__( 'Available points: %1$s current, %2$s expected. Earned points: %3$s current, %4$s expected.', 'yoohw-customer-intelligence' ),
			number_format_i18n( (int) ( $issue['current_points'] ?? 0 ) ),
			number_format_i18n( (int) ( $issue['expected_points'] ?? 0 ) ),
			number_format_i18n( (int) ( $issue['current_earning_points'] ?? 0 ) ),
			number_format_i18n( (int) ( $issue['expected_earning_points'] ?? 0 ) )
		);

		if ( ! empty( $issue['current_level'] ) || ! empty( $issue['expected_level'] ) ) {
			$parts[] = sprintf(
				/* translators: 1: current loyalty level, 2: expected loyalty level. */
				__( 'Loyalty level: %1$s current, %2$s expected.', 'yoohw-customer-intelligence' ),
				self::format_role_label( sanitize_key( (string) ( $issue['current_level'] ?? 'none' ) ) ),
				self::format_role_label( sanitize_key( (string) ( $issue['expected_level'] ?? 'none' ) ) )
			);
		}

		$parts[] = sprintf(
			/* translators: %s: reconciliation issue codes. */
			__( 'Issue codes: %s.', 'yoohw-customer-intelligence' ),
			implode( ', ', $issue_codes )
		);

		return implode( "\n\n", $parts );
	}

	private static function get_automation_due_date( int $due_days ): string {
		$timestamp = current_time( 'timestamp' ) + ( max( 0, $due_days ) * DAY_IN_SECONDS );

		return date_i18n( 'Y-m-d H:i:s', $timestamp );
	}

	private static function compare_loyalty_levels( string $left_role, string $right_role ): int {
		$left_rank  = self::get_loyalty_level_rank( $left_role );
		$right_rank = self::get_loyalty_level_rank( $right_role );

		if ( $left_rank === $right_rank ) {
			return 0;
		}

		return $left_rank > $right_rank ? 1 : -1;
	}

	private static function get_loyalty_level_rank( string $role_slug ): float {
		if ( ! self::is_loyalty_plugin_active() ) {
			return 0;
		}

		$role_slug = sanitize_key( $role_slug );

		if ( '' === $role_slug || 'none' === $role_slug ) {
			return -1;
		}

		$rules = maybe_unserialize( get_option( 'loyalty_levels_rules', array() ) );
		$rules = is_array( $rules ) ? $rules : array();

		if ( isset( $rules[ $role_slug ] ) && is_array( $rules[ $role_slug ] ) && isset( $rules[ $role_slug ]['from'] ) ) {
			return (float) $rules[ $role_slug ]['from'];
		}

		$roles = self::get_configured_loyalty_roles();
		$index = array_search( $role_slug, $roles, true );

		return false === $index ? 0 : (float) $index;
	}

	private static function get_default_high_value_loyalty_levels(): array {
		if ( ! self::is_loyalty_plugin_active() ) {
			return array();
		}

		$roles = self::get_configured_loyalty_roles();
		$rules = maybe_unserialize( get_option( 'loyalty_levels_rules', array() ) );
		$rules = is_array( $rules ) ? $rules : array();
		$ranked = array();

		foreach ( $roles as $role ) {
			if ( 'customer' === $role ) {
				continue;
			}

			$ranked[ $role ] = isset( $rules[ $role ]['from'] ) ? (float) $rules[ $role ]['from'] : self::get_loyalty_level_rank( $role );
		}

		arsort( $ranked, SORT_NUMERIC );

		$defaults = array_slice( array_keys( $ranked ), 0, 2 );

		if ( ! empty( $defaults ) ) {
			return $defaults;
		}

		return array_values( array_intersect( array( 'gold', 'platinum' ), $roles ) );
	}

	public static function get_loyalty_level_badge_style( string $level ): string {
		if ( ! self::is_loyalty_plugin_active() ) {
			return '';
		}

		$level = sanitize_key( $level );

		if ( '' === $level ) {
			return '';
		}

		$levels = maybe_unserialize( get_option( 'loyalty_customization_levels', array() ) );

		if ( ! is_array( $levels ) || empty( $levels[ $level ]['text_color'] ) ) {
			return '';
		}

		$level_color = self::normalize_hex_color( (string) $levels[ $level ]['text_color'] );

		if ( '' === $level_color ) {
			return '';
		}

		return sprintf(
			'background:%1$s;border-color:%2$s;color:%3$s;',
			$level_color,
			self::mix_hex_color( $level_color, '#000000', 0.18 ),
			self::get_contrast_color( $level_color )
		);
	}

	private static function normalize_hex_color( string $color ): string {
		$color = sanitize_hex_color( $color );

		if ( empty( $color ) ) {
			return '';
		}

		$hex = ltrim( $color, '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		return 6 === strlen( $hex ) ? '#' . strtolower( $hex ) : '';
	}

	private static function hex_to_rgb( string $color ): array {
		$color = self::normalize_hex_color( $color );

		if ( '' === $color ) {
			return array( 0, 0, 0 );
		}

		return array(
			hexdec( substr( $color, 1, 2 ) ),
			hexdec( substr( $color, 3, 2 ) ),
			hexdec( substr( $color, 5, 2 ) ),
		);
	}

	private static function get_contrast_color( string $background_color ): string {
		list( $red, $green, $blue ) = self::hex_to_rgb( $background_color );
		$luminance                 = ( ( $red * 299 ) + ( $green * 587 ) + ( $blue * 114 ) ) / 1000;

		return $luminance > 150 ? '#1d2327' : '#ffffff';
	}

	private static function mix_hex_color( string $color, string $target_color, float $weight ): string {
		$weight = max( 0.0, min( 1.0, $weight ) );
		$rgb    = self::hex_to_rgb( $color );
		$target = self::hex_to_rgb( $target_color );
		$mixed  = array();

		foreach ( $rgb as $index => $value ) {
			$mixed[] = max( 0, min( 255, (int) round( $value + ( ( $target[ $index ] - $value ) * $weight ) ) ) );
		}

		return sprintf( '#%02x%02x%02x', $mixed[0], $mixed[1], $mixed[2] );
	}

	private static function get_user_loyalty_metadata( int $user_id, array $extra = array() ): array {
		$loyalty_data = self::get_user_loyalty_customer_data( $user_id );
		$role         = $loyalty_data['loyalty_level'];

		return array_merge(
			array(
				'points_balance' => $loyalty_data['available_points'],
				'earning_points' => $loyalty_data['earned_points'],
				'loyalty_level'  => $loyalty_data['loyalty_level'],
				'loyalty_label'  => self::format_role_label( $role ),
				'loyalty_score'  => $loyalty_data['loyalty_score'],
			),
			$extra
		);
	}

	private static function get_user_loyalty_role( int $user_id ): string {
		if ( ! self::is_loyalty_plugin_active() ) {
			return '';
		}

		if ( class_exists( 'YOWCL_Helper_Roles' ) && is_callable( array( 'YOWCL_Helper_Roles', 'get_user_loyalty_role_for_rules' ) ) ) {
			$role = sanitize_key( (string) YOWCL_Helper_Roles::get_user_loyalty_role_for_rules( $user_id ) );

			if ( '' !== $role ) {
				return $role;
			}
		}

		$stored_role = sanitize_key( (string) get_user_meta( $user_id, '_yowcl_loyalty_level', true ) );

		if ( '' !== $stored_role ) {
			return $stored_role;
		}

		$user = get_userdata( $user_id );

		if ( $user instanceof WP_User && ! empty( $user->roles ) ) {
			$configured_roles = self::get_configured_loyalty_roles();

			foreach ( (array) $user->roles as $role ) {
				$role = sanitize_key( $role );

				if ( in_array( $role, $configured_roles, true ) ) {
					return $role;
				}
			}
		}

		return 'customer';
	}

	public static function get_configured_loyalty_roles(): array {
		if ( ! self::is_loyalty_plugin_active() ) {
			return array();
		}

		if ( class_exists( 'YOWCL_Helper_Roles' ) && is_callable( array( 'YOWCL_Helper_Roles', 'get_configured_loyalty_roles' ) ) ) {
			$roles = YOWCL_Helper_Roles::get_configured_loyalty_roles();
		} else {
			$roles = maybe_unserialize( get_option( 'loyalty_levels_roles', array() ) );
			$roles = is_array( $roles ) ? $roles : array();
		}

		$roles = array_values( array_unique( array_filter( array_map( 'sanitize_key', $roles ) ) ) );

		if ( ! in_array( 'customer', $roles, true ) ) {
			array_unshift( $roles, 'customer' );
		}

		return $roles;
	}

	public static function format_role_label( string $role_slug ): string {
		$role_slug = sanitize_key( $role_slug );

		if ( '' === $role_slug || 'none' === $role_slug ) {
			return __( 'None', 'yoohw-customer-intelligence' );
		}

		if ( function_exists( 'wp_roles' ) ) {
			$wp_roles = wp_roles();

			if ( isset( $wp_roles->roles[ $role_slug ]['name'] ) ) {
				return translate_user_role( $wp_roles->roles[ $role_slug ]['name'] );
			}
		}

		return ucwords( str_replace( array( '_', '-' ), ' ', $role_slug ) );
	}
}
