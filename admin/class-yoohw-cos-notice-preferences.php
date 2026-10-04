<?php
defined( 'ABSPATH' ) || exit;

/** Fixed state reminders. Dismissal is a user preference, never recovery authority. */
final class YoOhw_COS_Notice_Preferences {
	private const META_KEY = '_yoohw_cos_notice_preferences';
	private const KEYS = array( 'commerce_update', 'operational_recovery' );

	public static function init(): void {
		add_action( 'admin_notices', array( __CLASS__, 'render_commerce_notice' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_ajax_yoohw_cos_dismiss_notice', array( __CLASS__, 'dismiss_request' ) );
	}

	public static function descriptor( string $key ): ?array {
		if ( ! in_array( $key, self::KEYS, true ) ) { return null; }
		if ( 'operational_recovery' === $key ) {
			$items = YoOhw_COS_Reset_Guard::operational_incidents();
			if ( ! $items ) { return null; }
			$ids = array_column( $items, 'id' );
			sort( $ids, SORT_STRING );
			return array( 'state' => 'attention', 'revision' => hash( 'sha256', wp_json_encode( $ids ) ) );
		}
		$site = YoOhw_COS_Commerce_Metrics_Policy::site_readiness();
		if ( 'ready' === $site['state'] ) { return null; }
		$m = $site['migration'];
		$identity = array( $site['state'], $m['started_at'] ?? '', $m['status'] ?? '', $m['completed_at'] ?? '' );
		if ( 'attention' === $site['state'] ) {
			$identity = array_merge( $identity, array(
				$m['phase'] ?? '', $m['pending_issues'] ?? 0, $m['unresolved_issues'] ?? 0, $site['issues'],
				$m['last_error'] ?? '', $m['last_progress_at'] ?? '',
				$site['scheduled_at'] ? ( $site['scheduled_at'] < time() - 15 * MINUTE_IN_SECONDS ? 'overdue' : 'scheduled' ) : 'missing',
			) );
		}
		// Stable preparing revision across normal worker batches and cron rescheduling.
		if ( 'preparing' === $site['state'] ) { $identity = array( 'preparing', $m['started_at'] ?? '' ); }
		return array( 'state' => $site['state'], 'revision' => hash( 'sha256', wp_json_encode( $identity ) ) );
	}

	public static function is_dismissed( string $key, string $revision ): bool {
		$preferences = get_user_meta( get_current_user_id(), self::META_KEY, true );
		return is_array( $preferences ) && ( $preferences[ $key ]['revision'] ?? '' ) === $revision;
	}

	/** CAS retries keep simultaneous dismissals from losing another notice preference. */
	public static function dismiss( string $key, string $revision ): bool {
		if ( ! get_current_user_id() || ! current_user_can( 'manage_woocommerce' ) ) { return false; }
		$lock = YoOhw_COS_DB::acquire_work_locks( array( 'identity|notice-preference|' . get_current_user_id() ) );
		if ( '' === $lock ) { return false; }
		try {
			wp_cache_delete( get_current_user_id(), 'user_meta' );
			return self::dismiss_guarded( $key, $revision );
		} finally { YoOhw_COS_DB::release_work_locks( $lock ); }
	}

	private static function dismiss_guarded( string $key, string $revision ): bool {
		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$current = self::descriptor( $key );
			if ( ! $current || ! hash_equals( $current['revision'], $revision ) ) { return false; }
			$old = get_user_meta( get_current_user_id(), self::META_KEY, true );
			$next = array_intersect_key( is_array( $old ) ? $old : array(), array_flip( self::KEYS ) );
			$next[ $key ] = array( 'revision' => $revision, 'dismissed_at' => time() );
			if ( '' === $old ) {
				$saved = add_user_meta( get_current_user_id(), self::META_KEY, $next, true );
			} else {
				$saved = update_user_meta( get_current_user_id(), self::META_KEY, $next, $old );
			}
			if ( $saved || self::is_dismissed( $key, $revision ) ) { return true; }
			wp_cache_delete( get_current_user_id(), 'user_meta' );
		}
		return false;
	}

	public static function dismiss_request(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! get_current_user_id() ) { wp_send_json_error( array(), 403 ); }
		check_ajax_referer( 'yoohw_cos_dismiss_notice', 'nonce' );
		if ( isset( $_POST['user_id'] ) || ! is_string( $_POST['key'] ?? null ) || ! is_string( $_POST['revision'] ?? null ) ) { wp_send_json_error( array(), 400 ); }
		$key = wp_unslash( $_POST['key'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Unslashed key must match a known descriptor and revision must be exactly 64 hex characters/current revision; do not normalize replay input.
		$revision = wp_unslash( $_POST['revision'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Unslashed key must match a known descriptor and revision must be exactly 64 hex characters/current revision; do not normalize replay input.
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $revision ) || ! self::dismiss( $key, $revision ) ) { wp_send_json_error( array(), 409 ); }
		wp_send_json_success();
	}

	public static function opening_markup( string $key, string $type = 'warning' ): string {
		$current = self::descriptor( $key );
		if ( ! $current || self::is_dismissed( $key, $current['revision'] ) ) { return ''; }
		return '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible" data-yoohw-cos-notice="' . esc_attr( $key ) . '" data-yoohw-cos-revision="' . esc_attr( $current['revision'] ) . '" role="status">';
	}

	public static function render_commerce_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin display/filter context; mutation handlers verify their own capability, nonce and Reset epoch.
		if ( 0 !== strpos( $page, 'yoohw-customer-intelligence' ) ) { return; }
		$current = self::descriptor( 'commerce_update' );
		if ( ! $current ) { return; }
		$preparing = 'preparing' === $current['state'];
		$open = self::opening_markup( 'commerce_update', $preparing ? 'info' : 'warning' );
		if ( '' === $open ) { return; }
		echo $open; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped fixed markup.
		echo '<p><strong>' . esc_html( $preparing ? __( 'Customer Intelligence is updating historical commerce data.', 'yoohw-customer-intelligence' ) : __( 'Customer Intelligence data update needs attention.', 'yoohw-customer-intelligence' ) ) . '</strong> ';
		if ( $preparing ) { echo esc_html__( 'Monetary insights may be temporarily unavailable while this finishes automatically.', 'yoohw-customer-intelligence' ) . ' '; }
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=yoohw-customer-intelligence-settings#yoohw-cos-diagnostics' ) ) . '">' . esc_html( $preparing ? __( 'View progress', 'yoohw-customer-intelligence' ) : __( 'Review in Settings', 'yoohw-customer-intelligence' ) ) . '</a></p></div>';
	}

	public static function enqueue(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
		wp_enqueue_script( 'yoohw-cos-notice-preferences', YOOHW_COS_URL . 'assets/js/notice-preferences.js', array( 'jquery' ), YOOHW_COS_VERSION, true );
		wp_localize_script( 'yoohw-cos-notice-preferences', 'yoohwCosNoticePreferences', array( 'url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'yoohw_cos_dismiss_notice' ) ) );
	}
}
