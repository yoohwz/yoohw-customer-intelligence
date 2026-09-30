<?php
defined( 'ABSPATH' ) || exit;

/** Reset-local validity boundary; no leases, expiry takeover or order scan. */
final class YoOhw_COS_Reset_Guard {
	public const OPTION = 'yoohw_cos_reset_boundary';
	public const META_KEY = '_yoohw_cos_link_epoch';
	private static $request_epoch = null;
	private static $depth = 0;
	private static $resetting = false;
	private static $connection = 0;
	private const DEFERRED_NOTICE = 'yoohw_cos_reset_deferred';
	private const NOTICE_OPTION = 'yoohw_cos_operational_incidents';
	private const LEGACY_NOTICE_OPTION = 'yoohw_cos_reset_notice';
	private const NOTICE_LOCK_SUFFIX = '-notice';
	private const INCIDENT_TTL = 2592000;
	private const INCIDENT_LIMIT = 128;

	public static function init(): void {
		self::$request_epoch = self::epoch();
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
		add_action( 'admin_post_yoohw_cos_resolve_reset_notice', array( __CLASS__, 'resolve_notice' ) );
	}

	/** Read through SQL: persistent object caches must not hide a reset. */
	public static function state(): array {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, self::OPTION ) . ( self::$connection > 0 ? ' FOR UPDATE' : '' ) );
		if ( null === $value && '' === $wpdb->last_error ) {
			return array( 'epoch' => '', 'status' => 'ready' );
		}
		$state = maybe_unserialize( $value );
		return is_array( $state ) && isset( $state['epoch'], $state['status'] )
			&& is_string( $state['epoch'] ) && preg_match( '/^[a-f0-9-]{36}$/D', $state['epoch'] )
			&& in_array( $state['status'], array( 'ready', 'pending' ), true )
			? $state : array( 'epoch' => 'invalid', 'status' => 'pending' );
	}

	public static function epoch(): string {
		return (string) self::state()['epoch'];
	}

	public static function ready(): bool {
		return 'ready' === self::state()['status'];
	}

	public static function lock_name(): string {
		global $wpdb;
		return 'yci-reset-' . md5( DB_NAME . '|' . $wpdb->prefix );
	}

	private static function owns_lock(): bool {
		global $wpdb;
		return self::$connection > 0 && (string) self::$connection === (string) $wpdb->get_var(
			$wpdb->prepare( 'SELECT IF(IS_USED_LOCK(%s) = CONNECTION_ID(), CONNECTION_ID(), 0)', self::lock_name() )
		);
	}

	/** Every guarded operation starts before resolving any CRM reference. */
	public static function enter( $incident = null ): bool {
		global $wpdb;
		if ( self::$resetting ) {
			return self::deferred( $incident );
		}
		if ( null === self::$request_epoch ) {
			self::init();
		}
		if ( self::$depth > 0 ) {
			if ( ! self::owns_lock() || ! self::ready() || self::$request_epoch !== self::epoch() ) {
				return self::deferred( $incident );
			}
			self::$depth++;
			return true;
		}
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 2)', self::lock_name() ) ) ) {
			return self::deferred( $incident );
		}
		self::$connection = (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
		if ( ! self::ready() || self::$request_epoch !== self::epoch() ) {
			self::unlock();
			return self::deferred( $incident );
		}
		self::$depth = 1;
		return true;
	}

	public const FORM_FIELD = 'yoohw_cos_epoch';

	/** Missing and non-string transport values are never the legacy empty epoch. */
	public static function matches_submission( array $source, string $field = self::FORM_FIELD ): bool {
		return array_key_exists( $field, $source ) && is_string( $source[ $field ] )
			&& self::ready() && self::epoch() === wp_unslash( $source[ $field ] );
	}

	public static function rejection_message(): string {
		return __( 'Customer data changed or is busy. If Reset was interrupted, finish Reset recovery first. Reload this page and select the records again before retrying.', 'yoohw-customer-intelligence' );
	}

	/** Called after permission/nonce checks, before any submitted reference is read. */
	public static function require_submission( array $source, bool $ajax = false ): void {
		$entered = self::enter( false );
		if ( $entered && self::matches_submission( $source ) ) {
			return;
		}
		if ( $entered ) { self::leave(); }
		if ( $ajax ) {
			wp_send_json_error( array( 'message' => self::rejection_message() ), 409 );
		}
		wp_die( esc_html( self::rejection_message() ), '', array( 'response' => 409 ) );
	}

	/** Only render with an epoch captured alongside the referenced records. */
	public static function render_field( string $epoch, string $form = '' ): void {
		echo '<input type="hidden" name="' . esc_attr( self::FORM_FIELD ) . '" value="' . esc_attr( $epoch ) . '"' . ( '' !== $form ? ' form="' . esc_attr( $form ) . '"' : '' ) . ' />';
	}

	/** A bounded read can outlive its lock, but its action URLs keep this epoch. */
	public static function snapshot_rows( callable $read ): array {
		if ( ! self::enter( false ) ) { return array(); }
		try {
			$epoch = self::epoch();
			$rows = $read();
			foreach ( $rows as &$row ) { $row[ self::FORM_FIELD ] = $epoch; }
			return $rows;
		} finally { self::leave(); }
	}

	public static function check_bulk_size( array $ids ): void {
		if ( count( $ids ) > 100 ) {
			wp_die( esc_html__( 'Select at most 100 records per action.', 'yoohw-customer-intelligence' ), '', array( 'response' => 400 ) );
		}
	}

	private static function deferred( $incident ): bool {
		if ( is_array( $incident ) && ! self::$resetting ) {
			if ( ! self::valid_incident( $incident ) || ! self::lock_notice() ) {
				throw new RuntimeException( 'YCI could not persist a deferred operation; retry its source callback.' );
			}
			try {
				$items = self::notice_state();
				$key   = $incident['source'] . ':' . $incident['event'];
				$now   = time();
				$old   = $items[ $key ] ?? array();
				if ( isset( $items[ $key ] ) || count( $items ) < self::INCIDENT_LIMIT - 1 ) {
					$items[ $key ] = array(
						'id'         => wp_generate_uuid4(),
						'source'     => $incident['source'],
						'event'      => $incident['event'],
						'mode'       => $incident['mode'],
						'first_seen' => isset( $old['first_seen'] ) ? $old['first_seen'] : $now,
						'last_seen'  => $now,
						'count'      => min( 999999, (int) ( $old['count'] ?? 0 ) + 1 ),
						'expires'    => $now + self::INCIDENT_TTL,
					);
					if ( ! self::write_notice( $items ) ) {
						throw new RuntimeException( 'YCI could not persist a deferred operation; retry its source callback.' );
					}
				} else {
					$capacity_key = 'customer_intelligence:incident_capacity_reached';
					$capacity = $items[ $capacity_key ] ?? array();
					$items[ $capacity_key ] = array(
						'id'         => wp_generate_uuid4(),
						'source'     => 'customer_intelligence',
						'event'      => 'incident_capacity_reached',
						'mode'       => 'manual_replay_required',
						'first_seen' => $capacity['first_seen'] ?? $now,
						'last_seen'  => $now,
						'count'      => min( 999999, (int) ( $capacity['count'] ?? 0 ) + 1 ),
						'expires'    => $now + self::INCIDENT_TTL,
					);
					if ( ! self::write_notice( $items ) ) {
						throw new RuntimeException( 'YCI could not persist a deferred operation; retry its source callback.' );
					}
					error_log( 'YCI operational incident capacity reached; existing obligations retained.' );
				}
			} finally { self::unlock_notice(); }
			error_log( 'YCI reset boundary deferred an integration callback: ' . $incident['source'] . ':' . $incident['event'] );
		}
		return false;
	}

	private static function valid_incident( array $incident ): bool {
		return isset( $incident['source'], $incident['event'], $incident['mode'] )
			&& is_string( $incident['source'] ) && is_string( $incident['event'] )
			&& preg_match( '/^[a-z0-9_]{1,64}$/D', $incident['source'] )
			&& preg_match( '/^[a-z0-9_]{1,64}$/D', $incident['event'] )
			&& in_array( $incident['mode'], array( 'automatic_retry', 'backfill_available', 'manual_replay_required' ), true );
	}

	public static function render_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
		if ( ! self::ready() ) {
			echo '<div class="notice notice-warning" role="alert"><p>' . esc_html__( 'Customer Reset requires recovery. Retry Reset before customer-data operations.', 'yoohw-customer-intelligence' ) . '</p></div>';
			return;
		}
		if ( ! self::notice_exists() && false === get_transient( self::DEFERRED_NOTICE ) ) { return; }
		if ( ! self::enter( false ) ) { return; }
		try {
			if ( ! self::lock_notice() ) { return; }
			try {
				$items = self::notice_state();
				$items = self::migrate_legacy_notice( $items );
				if ( array() === $items ) { self::delete_notice(); }
			} finally { self::unlock_notice(); }
			if ( array() === $items ) { return; }
			$url = admin_url( 'admin.php?page=yoohw-customer-intelligence-settings#yoohw-cos-incidents' );
			$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
			if ( 'yoohw-customer-intelligence-settings' !== $page ) {
				echo '<div class="notice notice-warning" role="status"><p>' . esc_html( sprintf(
					/* translators: %d: number of unresolved operations. */
					_n( 'Customer Intelligence has %d unresolved operation.', 'Customer Intelligence has %d unresolved operations.', count( $items ), 'yoohw-customer-intelligence' ),
					count( $items )
				) ) . ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Review recovery options', 'yoohw-customer-intelligence' ) . '</a></p></div>';
			}
		} finally { self::leave(); }
	}

	public static function render_incidents(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
		$items = self::notice_state();
		echo '<section id="yoohw-cos-incidents"><h2>' . esc_html__( 'Operational recovery', 'yoohw-customer-intelligence' ) . '</h2>';
		if ( ! $items ) {
			echo '<p>' . esc_html__( 'No unresolved operations are recorded.', 'yoohw-customer-intelligence' ) . '</p></section>';
			return;
		}
		foreach ( $items as $key => $item ) {
			$sources = array(
				'blacklist_core'    => __( 'Blacklist Manager Core', 'yoohw-customer-intelligence' ),
				'blacklist_premium' => __( 'Blacklist Manager Premium', 'yoohw-customer-intelligence' ),
				'loyalty'           => __( 'Loyalty', 'yoohw-customer-intelligence' ),
			);
			$source_label = $sources[ $item['source'] ] ?? $item['source'];
			echo '<div class="notice notice-warning inline" role="status"><p><strong>' . esc_html( $source_label . ' / ' . str_replace( '_', ' ', $item['event'] ) ) . '</strong> — ';
			if ( 'customer_intelligence:incident_capacity_reached' === $key ) {
				echo esc_html__( 'The incident log reached its capacity. Check integration sources for missed operations before acknowledging this warning.', 'yoohw-customer-intelligence' );
			} elseif ( 'customer_intelligence:legacy_deferred_unattributed' === $key ) {
				echo esc_html__( 'A deferred callback was recorded by an earlier version without its source. Review integration activity and recover any missed operation before acknowledging this record.', 'yoohw-customer-intelligence' );
			} elseif ( 'backfill_available' === $item['mode'] ) {
				echo esc_html__( 'A source backfill is available. Run the relevant sync or backfill, then acknowledge this record.', 'yoohw-customer-intelligence' );
				$target = 'loyalty:intelligence_recalculated' === $key ? '#yoohw-cos-recalculate-intelligence' : '#yoohw-cos-sync-center';
				echo ' <a href="' . esc_url( admin_url( 'admin.php?page=yoohw-customer-intelligence-settings' . $target ) ) . '">' . esc_html__( 'Open recovery operation', 'yoohw-customer-intelligence' ) . '</a>';
			} elseif ( 'automatic_retry' === $item['mode'] ) {
				echo esc_html__( 'Retry the CIT operation, then acknowledge this record.', 'yoohw-customer-intelligence' );
				echo ' <a href="' . esc_url( admin_url( 'admin.php?page=yoohw-customer-intelligence-settings#yoohw-cos-recalculate-intelligence' ) ) . '">' . esc_html__( 'Open recalculation', 'yoohw-customer-intelligence' ) . '</a>';
			} else {
				echo esc_html__( 'This callback has no CIT replay source. Replay or recover it at the source before acknowledging this record.', 'yoohw-customer-intelligence' );
			}
			echo '</p><p>' . esc_html( sprintf(
				/* translators: %d: number of deferred callback occurrences. */
				__( 'Occurrences: %d', 'yoohw-customer-intelligence' ),
				$item['count']
			) ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="yoohw_cos_resolve_reset_notice" />';
			echo '<input type="hidden" name="incident_key" value="' . esc_attr( $key ) . '" />';
			echo '<input type="hidden" name="notice_id" value="' . esc_attr( $item['id'] ) . '" />';
			wp_nonce_field( 'yoohw_cos_resolve_reset_notice' );
			echo '<p><button type="submit" class="button">' . esc_html__( 'Acknowledge after recovery', 'yoohw-customer-intelligence' ) . '</button></p></form></div>';
		}
		echo '</section>';
	}

	public static function resolve_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'You do not have permission to perform this action.', 'yoohw-customer-intelligence' ) ); }
		check_admin_referer( 'yoohw_cos_resolve_reset_notice' );
		if ( ! self::enter( false ) ) { wp_die( esc_html( self::rejection_message() ), '', array( 'response' => 409 ) ); }
		try {
			if ( ! self::lock_notice() ) { wp_die( esc_html( self::rejection_message() ), '', array( 'response' => 409 ) ); }
			try {
				$items = self::notice_state();
				$key = isset( $_POST['incident_key'] ) && is_string( $_POST['incident_key'] ) ? wp_unslash( $_POST['incident_key'] ) : '';
				$id = isset( $_POST['notice_id'] ) && is_string( $_POST['notice_id'] ) ? wp_unslash( $_POST['notice_id'] ) : '';
				if ( isset( $items[ $key ] ) && $id === $items[ $key ]['id'] ) {
					unset( $items[ $key ] );
					self::write_notice( $items );
				}
			} finally { self::unlock_notice(); }
		} finally { self::leave(); }
		wp_safe_redirect( admin_url( 'admin.php?page=yoohw-customer-intelligence-settings#yoohw-cos-incidents' ) );
		exit;
	}

	public static function leave(): void {
		self::$depth = max( 0, self::$depth - 1 );
		if ( 0 === self::$depth ) {
			self::unlock();
		}
	}

	private static function unlock(): void {
		global $wpdb;
		if ( self::owns_lock() ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name() ) );
		}
		self::$connection = 0;
	}

	/** Serialize notice writes separately: a deferred caller cannot acquire the Reset lock. */
	private static function lock_notice(): bool {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', self::lock_name() . self::NOTICE_LOCK_SUFFIX ) );
	}

	private static function unlock_notice(): void {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name() . self::NOTICE_LOCK_SUFFIX ) );
	}

	/** Direct SQL avoids a stale request-local options cache hiding a newer callback's signal. */
	private static function notice_state( bool $current = false ): array {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, self::NOTICE_OPTION ) . ( $current ? ' FOR UPDATE' : '' ) );
		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'YCI could not read deferred operations; retry the request.' );
		}
		$stored = maybe_unserialize( $value );
		$items = array();
		if ( ! is_array( $stored ) || 1 !== ( $stored['version'] ?? null ) || ! isset( $stored['items'] ) || ! is_array( $stored['items'] ) ) {
			return $items;
		}
		foreach ( $stored['items'] as $key => $item ) {
			if ( count( $items ) >= self::INCIDENT_LIMIT ) { break; }
			if ( ! is_array( $item ) || ! self::valid_incident( $item )
				|| $key !== $item['source'] . ':' . $item['event']
				|| ! isset( $item['id'], $item['first_seen'], $item['last_seen'], $item['count'], $item['expires'] )
				|| ! is_string( $item['id'] ) || ! preg_match( '/^[a-f0-9-]{36}$/D', $item['id'] )
				|| ! is_int( $item['first_seen'] ) || ! is_int( $item['last_seen'] )
				|| ! is_int( $item['count'] ) || ! is_int( $item['expires'] ) || $item['expires'] <= time() ) {
				continue;
			}
			$items[ $key ] = $item;
		}
		return $items;
	}

	private static function notice_exists(): bool {
		global $wpdb;
		return null !== $wpdb->get_var( $wpdb->prepare( 'SELECT option_id FROM %i WHERE option_name IN (%s, %s)', $wpdb->options, self::NOTICE_OPTION, self::LEGACY_NOTICE_OPTION ) );
	}

	private static function write_notice( array $items ): bool {
		global $wpdb;
		return false !== $wpdb->replace( $wpdb->options, array( 'option_name' => self::NOTICE_OPTION, 'option_value' => maybe_serialize( array( 'version' => 1, 'items' => $items ) ), 'autoload' => 'no' ), array( '%s', '%s', '%s' ) );
	}

	private static function delete_notice(): void {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::NOTICE_OPTION ), array( '%s' ) );
	}

	private static function migrate_legacy_notice( array $items ): array {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, self::LEGACY_NOTICE_OPTION ) );
		$legacy = maybe_unserialize( $value );
		$has_transient = false !== get_transient( self::DEFERRED_NOTICE );
		$active_legacy = is_array( $legacy ) && isset( $legacy['id'], $legacy['expires'] )
			&& is_string( $legacy['id'] ) && preg_match( '/^[a-f0-9-]{36}$/D', $legacy['id'] )
			&& is_int( $legacy['expires'] ) && $legacy['expires'] > time();
		if ( ( $active_legacy || $has_transient ) && ! isset( $items['customer_intelligence:legacy_deferred_unattributed'] ) ) {
			if ( count( $items ) >= self::INCIDENT_LIMIT - 1 ) {
				// Retain the legacy signal until a slot is available; never discard a recovery obligation.
				return $items;
			}
			$now = time();
			$items['customer_intelligence:legacy_deferred_unattributed'] = array(
				'id'         => wp_generate_uuid4(),
				'source'     => 'customer_intelligence',
				'event'      => 'legacy_deferred_unattributed',
				'mode'       => 'manual_replay_required',
				'first_seen' => $now,
				'last_seen'  => $now,
				'count'      => 1,
				'expires'    => $now + self::INCIDENT_TTL,
			);
			if ( ! self::write_notice( $items ) ) { return self::notice_state(); }
		}
		if ( $has_transient ) { delete_transient( self::DEFERRED_NOTICE ); }
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::LEGACY_NOTICE_OPTION ), array( '%s' ) );
		return $items;
	}

	private static function persist( array $state ): void {
		update_option( self::OPTION, $state, false );
		if ( self::state() !== $state ) {
			throw new RuntimeException( 'Reset recovery required: could not persist the reset boundary.' );
		}
	}

	/** Pending is durable before the first nontransactional TRUNCATE. */
	public static function reset( callable $clear, ?string $expected_epoch = null ): bool {
		global $wpdb;
		if ( self::$resetting || self::$depth > 0 || '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::lock_name() ) ) ) {
			throw new RuntimeException( 'Customer data is busy. Retry Reset after the current operation finishes.' );
		}
		self::$connection = (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
		self::$resetting = true;
		try {
			$state = self::state();
			if ( null !== $expected_epoch && $expected_epoch !== $state['epoch'] && 'ready' === $state['status'] ) {
				return true; // A duplicate submission must not clear newly rebuilt data.
			}
			if ( 'ready' === $state['status'] ) {
				$state = array( 'epoch' => wp_generate_uuid4(), 'status' => 'pending' );
				self::persist( $state );
			}
			$clear();
			if ( ! self::owns_lock() ) {
				throw new RuntimeException( 'Reset recovery required: database connection changed.' );
			}
			// Completing recovery also consumes the retry form's epoch.
			$state['epoch'] = wp_generate_uuid4();
			$state['status'] = 'ready';
			self::persist( $state );
			self::$request_epoch = $state['epoch'];
			// Reset completion alone does not prove an integration callback was replayed.
			// Incidents remain until the source obligation is addressed and acknowledged.
			return true;
		} finally {
			self::$resetting = false;
			self::unlock();
		}
	}
}
