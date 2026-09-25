<?php
defined( 'ABSPATH' ) || exit;

/** Personal query definitions for the Customers screen. */
final class YoOhw_COS_Saved_Views {
	private const META_KEY = '_yoohw_cos_saved_customer_views';
	private const LIMIT = 20;
	private const MAX_NAME = 80;
	private const MAX_SEARCH = 200;

	public static function definition( array $source ): array {
		$allowed = array_diff( array_keys( YoOhw_COS_Customer_Query::sanitize_args( array() ) ), array( 'paged', 'per_page', 'offset', 'extensions' ) );
		$input = array();
		foreach ( $allowed as $key ) {
			$value = array_key_exists( $key, $source ) ? $source[ $key ] : '';
			$input[ $key ] = is_scalar( $value ) && ! ( 0 === strpos( $key, 'rfm_' ) && is_bool( $value ) ) && strlen( (string) $value ) <= self::MAX_SEARCH ? (string) $value : ( 0 === strpos( $key, 'rfm_' ) ? 'invalid' : '' );
		}
		$canonical = YoOhw_COS_Customer_Query::sanitize_args( $input );
		return array_intersect_key( $canonical, array_fill_keys( $allowed, true ) );
	}

	public static function stale_reason( $stored ): string {
		if ( ! is_array( $stored ) ) {
			return 'invalid';
		}
		// Definitions written before RFM have no RFM keys and retain empty thresholds.
		foreach ( array( 'rfm_recency_max_days', 'rfm_frequency_min', 'rfm_monetary_min' ) as $key ) {
			if ( array_key_exists( $key, $stored ) && ! is_string( $stored[ $key ] ) ) {
				return 'invalid';
			}
			if ( ! array_key_exists( $key, $stored ) ) {
				$stored[ $key ] = '';
			}
		}
		$canonical = self::definition( $stored );
		foreach ( array( 'rfm_recency_max_days', 'rfm_frequency_min', 'rfm_monetary_min' ) as $key ) {
			if ( 'invalid' === $canonical[ $key ] ) {
				return 'invalid';
			}
		}
		if ( array_diff_key( $canonical, $stored ) || array_diff_key( $stored, $canonical ) ) {
			return 'invalid';
		}
		foreach ( $canonical as $key => $value ) {
			if ( ! is_scalar( $stored[ $key ] ) || (string) $value !== (string) $stored[ $key ] ) {
				return 'invalid';
			}
		}
		if ( $canonical['customer_tag'] && ! YoOhw_COS_Tags::tag_exists( $canonical['customer_tag'] ) ) {
			return 'tag';
		}
		if ( $canonical['customer_segment'] && ! YoOhw_COS_Segments::segment_exists( $canonical['customer_segment'] ) ) {
			return 'segment';
		}
		if ( ! YoOhw_COS_Integrations::loyalty_active() && ( '' !== $stored['loyalty_level'] || '' !== $stored['loyalty_score'] ) ) {
			return 'loyalty';
		}
		return '';
	}

	public static function all(): array {
		return self::all_for_user( get_current_user_id() );
	}

	/** Read only the named owner's stored preferences, independent of the operator. */
	public static function all_for_user( int $user_id ): array {
		if ( $user_id < 1 ) {
			return array();
		}
		$stored = get_user_meta( $user_id, self::META_KEY, true );
		if ( ! is_array( $stored ) || count( $stored ) > self::LIMIT ) {
			return array();
		}
		$views = array();
		foreach ( $stored as $id => $view ) {
			if ( ! is_string( $id ) || ! preg_match( '/^[a-f0-9-]{36}$/', $id ) || ! is_array( $view ) || ! isset( $view['name'], $view['definition'] ) || ! is_string( $view['name'] ) || '' === trim( $view['name'] ) || mb_strlen( $view['name'] ) > self::MAX_NAME ) {
				continue;
			}
			$views[ $id ] = $view;
		}
		return $views;
	}

	public static function get( string $id ): array {
		return self::all()[ $id ] ?? array();
	}

	public static function mutate( string $action, string $id, $name, array $source ): string {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return 'permission';
		}
		$raw = get_user_meta( get_current_user_id(), self::META_KEY, true );
		if ( is_array( $raw ) && count( $raw ) > self::LIMIT ) {
			return 'invalid';
		}
		$views = self::all();
		if ( 'create' !== $action && ! isset( $views[ $id ] ) ) {
			return 'missing';
		}
		if ( in_array( $action, array( 'create', 'rename' ), true ) ) {
			if ( ! is_scalar( $name ) || strlen( (string) $name ) > 400 ) {
				return 'name';
			}
			$name = trim( sanitize_text_field( (string) $name ) );
			if ( '' === $name || mb_strlen( $name ) > self::MAX_NAME ) {
				return 'name';
			}
			foreach ( $views as $other_id => $view ) {
				if ( $other_id !== $id && mb_strtolower( $view['name'] ) === mb_strtolower( $name ) ) {
					return 'duplicate';
				}
			}
		}
		if ( in_array( $action, array( 'create', 'update' ), true ) && ! self::source_is_valid( $source ) ) {
			return 'invalid';
		}
		if ( 'create' === $action ) {
			if ( count( $views ) >= self::LIMIT ) {
				return 'limit';
			}
			$id = wp_generate_uuid4();
			$views[ $id ] = array( 'name' => $name, 'definition' => self::definition( $source ) );
		} elseif ( 'update' === $action ) {
			$views[ $id ]['definition'] = self::definition( $source );
		} elseif ( 'rename' === $action ) {
			$views[ $id ]['name'] = $name;
		} elseif ( 'delete' === $action ) {
			unset( $views[ $id ] );
		} else {
			return 'invalid';
		}
		update_user_meta( get_current_user_id(), self::META_KEY, $views );
		return 'ok';
	}

	public static function context_id( array $source ): string {
		if ( ! isset( $source['saved_view_id'] ) ) {
			return '';
		}
		if ( ! is_string( $source['saved_view_id'] ) ) {
			return 'invalid';
		}
		return substr( sanitize_text_field( wp_unslash( $source['saved_view_id'] ) ), 0, 80 );
	}

	public static function active_id( array $source ): string {
		$id = $source['saved_view_id'] ?? '';
		return is_string( $id ) && preg_match( '/^[a-f0-9-]{36}$/', $id ) ? $id : '';
	}

	/** Opening by ID restores the definition; later requests carry editable inputs. */
	public static function apply_open_request(): void {
		$id = self::active_id( $_GET );
		if ( '' === $id || isset( $_GET['saved_view_context'] ) ) {
			return;
		}
		$view = self::get( $id );
		if ( ! $view || self::stale_reason( $view['definition'] ) ) {
			return;
		}
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => 'yoohw-customer-intelligence', 'saved_view_id' => $id, 'saved_view_context' => '1' ), $view['definition'] ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function request_is_stale( array $source ): bool {
		if ( ! isset( $source['saved_view_id'] ) ) {
			return false;
		}
		$id = self::active_id( $source );
		if ( '' === $id && '' === $source['saved_view_id'] ) {
			return false;
		}
		$view = self::get( $id );
		return ! $view || '' !== self::stale_reason( $view['definition'] );
	}
	private static function source_is_valid( array $source ): bool {
		if ( count( $source ) > 40 ) {
			return false;
		}
		$canonical = self::definition( $source );
		foreach ( $canonical as $key => $value ) {
			if ( ! isset( $source[ $key ] ) ) {
				continue;
			}
			if ( ! is_scalar( $source[ $key ] ) || strlen( (string) $source[ $key ] ) > self::MAX_SEARCH ) {
				return false;
			}
			if ( 0 === strpos( $key, 'rfm_' ) ) {
				if ( 'invalid' === $value ) {
					return false;
				}
				continue;
			}
			if ( (string) $source[ $key ] !== (string) $value ) {
				return false;
			}
		}
		return '' === self::stale_reason( $canonical );
	}

}
