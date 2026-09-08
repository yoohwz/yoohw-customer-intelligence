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
	private static $reported = false;

	public static function init(): void {
		self::$request_epoch = self::epoch();
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
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
	public static function enter(): bool {
		global $wpdb;
		if ( self::$resetting ) {
			return self::deferred();
		}
		if ( null === self::$request_epoch ) {
			self::init();
		}
		if ( self::$depth > 0 ) {
			if ( ! self::owns_lock() || ! self::ready() || self::$request_epoch !== self::epoch() ) {
				return self::deferred();
			}
			self::$depth++;
			return true;
		}
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 2)', self::lock_name() ) ) ) {
			return self::deferred();
		}
		self::$connection = (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
		if ( ! self::ready() || self::$request_epoch !== self::epoch() ) {
			self::unlock();
			return self::deferred();
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
		$entered = self::enter();
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
		if ( ! self::enter() ) { return array(); }
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

	private static function deferred(): bool {
		if ( ! self::$reported && ! self::$resetting ) {
			self::$reported = true;
			set_transient( 'yoohw_cos_reset_deferred', 1, DAY_IN_SECONDS );
			// No customer payload or credentials are retained in this operational notice.
			error_log( 'YCI reset boundary deferred a CRM writer; retry sync/backfill or replay the upstream event.' );
		}
		return false;
	}

	public static function render_notice(): void {
		if ( current_user_can( 'manage_woocommerce' ) && ( ! self::ready() || get_transient( 'yoohw_cos_reset_deferred' ) ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'A customer data operation was deferred. If Reset was interrupted, retry Reset first. Then retry order sync and relevant integration backfills; transient integration events require replay from their source.', 'yoohw-customer-intelligence' ) . '</p></div>';
		}
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
			return true;
		} finally {
			self::$resetting = false;
			self::unlock();
		}
	}
}
