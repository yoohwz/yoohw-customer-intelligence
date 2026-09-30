<?php
defined( 'ABSPATH' ) || exit;

/** Consume redirect results once without changing state-derived admin notices. */
final class YoOhw_COS_Flash_Notices {
	private const TOKEN = 'yoohw_cos_flash';
	private static $shown = false;

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
		if ( ! array_intersect( self::flags(), array_keys( $params ) ) ) {
			return $location;
		}
		$token = wp_generate_uuid4();
		set_transient( self::TOKEN . '_' . $token, get_current_user_id(), 5 * MINUTE_IN_SECONDS );
		return add_query_arg( self::TOKEN, $token, $location );
	}

	public static function consume(): void {
		$token = isset( $_GET[ self::TOKEN ] ) && is_string( $_GET[ self::TOKEN ] )
			? wp_unslash( $_GET[ self::TOKEN ] ) : '';
		if ( ! preg_match( '/^[a-f0-9-]{36}$/D', $token ) ) {
			self::remove_flags();
			return;
		}
		$key = self::TOKEN . '_' . $token;
		$owner = get_transient( $key );
		delete_transient( $key );
		if ( (int) $owner !== get_current_user_id() || 0 === (int) $owner ) {
			self::remove_flags();
			return;
		}
		self::$shown = true;
	}

	private static function remove_flags(): void {
		foreach ( self::flags() as $flag ) {
			unset( $_GET[ $flag ] );
		}
	}

	public static function clean_url(): void {
		if ( ! self::$shown ) { return; }
		$flags = array_merge( self::flags(), array( self::TOKEN ) );
		echo '<script>(function(){var url=new URL(window.location.href);';
		echo 'var flags=' . wp_json_encode( $flags ) . ';flags.forEach(function(flag){url.searchParams.delete(flag);});';
		echo 'window.history.replaceState(window.history.state,"",url.href);})();</script>';
		self::$shown = false;
	}
}
