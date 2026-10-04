<?php
defined( 'ABSPATH' ) || exit;

/** Consume redirect results once without changing state-derived admin notices. */
final class YoOhw_COS_Flash_Notices {
	private const TOKEN = 'yoohw_cos_flash';
	private static $shown = array();

	private static function flags(): array {
		return array(
			'note_added', 'note_updated', 'note_deleted',
			'tag_added', 'tag_removed', 'segment_added', 'segment_removed',
			'saved_view_notice', 'yoohw_customers_bulk', 'yoohw_customers_bulk_err',
			'yoohw_task_created', 'yoohw_task_updated', 'yoohw_task_completed',
			'yoohw_task_reopened', 'yoohw_task_deleted', 'yoohw_task_error',
			'yoohw_tasks_bulk_done', 'yoohw_tasks_bulk_missing',
			'yoohw_tag_created', 'yoohw_tag_updated', 'yoohw_tag_deleted',
			'yoohw_tag_error', 'yoohw_tags_bulk_deleted', 'yoohw_tags_bulk_missing',
			'yoohw_tag_delete_block', 'yoohw_segment_created', 'yoohw_segment_updated',
			'yoohw_segment_deleted', 'yoohw_segment_error', 'yoohw_segments_bulk_deleted',
			'yoohw_segments_bulk_missing', 'yoohw_segment_delete_block',
			'yoohw_cos_processed', 'yoohw_cos_reset', 'yoohw_cos_backfilled',
			'yoohw_cos_recalculated', 'yoohw_cos_blacklist_synced',
			'yoohw_cos_scoring_settings_saved', 'yoohw_cos_loyalty_task_automation_saved',
		);
	}

	/** Generic result names belong to CIT only on its own admin pages. */
	private static function owned_flags( string $script, array $params ): array {
		$page = isset( $params['page'] ) && is_string( $params['page'] ) ? $params['page'] : '';
		if ( 'admin.php' === $script && in_array( $page, array(
			'yoohw-customer-intelligence-overview', 'yoohw-customer-intelligence',
			'yoohw-customer-intelligence-tasks', 'yoohw-customer-intelligence-tags',
			'yoohw-customer-intelligence-segments', 'yoohw-customer-intelligence-activity',
			'yoohw-customer-intelligence-settings', 'yoohw-customer-intelligence-email-settings',
		), true ) ) {
			return self::flags();
		}
		$action = isset( $params['action'] ) && is_string( $params['action'] ) ? $params['action'] : '';
		if ( 'edit' !== $action ) { return array(); }
		$is_order = false;
		if ( 'post.php' === $script && isset( $params['post'] ) && is_scalar( $params['post'] ) ) {
			$is_order = 'shop_order' === get_post_type( absint( $params['post'] ) );
		} elseif ( 'admin.php' === $script && 'wc-orders' === $page
			&& isset( $params['id'] ) && is_scalar( $params['id'] ) && function_exists( 'wc_get_order' ) ) {
			$is_order = wc_get_order( absint( $params['id'] ) ) instanceof WC_Order;
		}
		return $is_order ? array( 'yoohw_task_created', 'yoohw_task_completed', 'yoohw_task_reopened', 'yoohw_task_error' ) : array();
	}

	private static function request_flags(): array {
		global $pagenow;
		return self::owned_flags( is_string( $pagenow ) ? $pagenow : '', $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display receipt only; consume() checks the bounded token and current-user transient owner before showing notices.
	}

	public static function init(): void {
		add_filter( 'wp_redirect', array( __CLASS__, 'tokenize_redirect' ) );
		add_action( 'admin_init', array( __CLASS__, 'consume' ), 0 );
		add_action( 'admin_footer', array( __CLASS__, 'clean_url' ) );
	}

	public static function tokenize_redirect( string $location ): string {
		$path = wp_parse_url( $location, PHP_URL_PATH );
		$query = wp_parse_url( $location, PHP_URL_QUERY );
		$host = wp_parse_url( $location, PHP_URL_HOST );
		$admin_host = wp_parse_url( admin_url(), PHP_URL_HOST );
		if ( ! is_string( $path ) || ! is_string( $query )
			|| ! in_array( basename( $path ), array( 'admin.php', 'post.php' ), true )
			|| ( is_string( $host ) && $host !== $admin_host )
			|| ! get_current_user_id() ) {
			return $location;
		}
		parse_str( $query, $params );
		$script = basename( $path );
		$admin_path = wp_parse_url( admin_url( $script ), PHP_URL_PATH );
		if ( ( $path !== $script && $path !== $admin_path )
			|| ! array_intersect( self::owned_flags( $script, $params ), array_keys( $params ) ) ) {
			return $location;
		}
		$token = wp_generate_uuid4();
		set_transient( self::TOKEN . '_' . $token, get_current_user_id(), 5 * MINUTE_IN_SECONDS );
		return add_query_arg( self::TOKEN, $token, $location );
	}

	public static function consume(): void {
		self::$shown = array();
		$flags = self::request_flags();
		if ( ! $flags ) { return; }
		$token = isset( $_GET[ self::TOKEN ] ) && is_string( $_GET[ self::TOKEN ] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display receipt only; consume() checks the bounded token and current-user transient owner before showing notices.
			? wp_unslash( $_GET[ self::TOKEN ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Display receipt only; consume() checks the bounded token and current-user transient owner before showing notices. The unslashed token is checked against the exact bounded UUID-shaped pattern before transient lookup.
		if ( ! preg_match( '/^[a-f0-9-]{36}$/D', $token ) ) {
			self::remove_flags( $flags );
			return;
		}
		$key = self::TOKEN . '_' . $token;
		$owner = get_transient( $key );
		delete_transient( $key );
		if ( (int) $owner !== get_current_user_id() || 0 === (int) $owner ) {
			self::remove_flags( $flags );
			return;
		}
		self::$shown = $flags;
	}

	private static function remove_flags( array $flags ): void {
		foreach ( $flags as $flag ) {
			unset( $_GET[ $flag ] );
		}
	}

	public static function clean_url(): void {
		$owned = array_intersect( self::$shown, self::request_flags() );
		if ( ! $owned ) { self::$shown = array(); return; }
		$flags = array_merge( array_values( $owned ), array( self::TOKEN ) );
		echo '<script>(function(){var url=new URL(window.location.href);';
		echo 'var flags=' . wp_json_encode( $flags ) . ';flags.forEach(function(flag){url.searchParams.delete(flag);});';
		echo 'window.history.replaceState(window.history.state,"",url.href);})();</script>';
		self::$shown = array();
	}
}
