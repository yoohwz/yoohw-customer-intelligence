<?php
defined( 'ABSPATH' ) || exit;

/**
 * Canonical customer identity normalization and deterministic resolution.
 */
final class YoOhw_COS_Customer_Identity {
	private const LOCK_TTL = 60;

	public static function normalize_email( string $email ): string {
		return strtolower( sanitize_email( trim( $email ) ) );
	}

	public static function normalize_phone( string $phone ): string {
		$phone = trim( wp_strip_all_tags( $phone ) );

		if ( '' === $phone ) {
			return '';
		}

		$has_plus = '+' === substr( $phone, 0, 1 );
		$digits   = preg_replace( '/[^0-9]/', '', $phone );
		$digits   = is_string( $digits ) ? $digits : '';

		if ( 0 === strpos( $digits, '00' ) ) {
			$digits   = substr( $digits, 2 );
			$has_plus = true;
		}

		if ( strlen( $digits ) < 7 ) {
			return sanitize_text_field( $phone );
		}

		return ( $has_plus ? '+' : '' ) . $digits;
	}

	public static function normalize( array $identity ): array {
		return array(
			'customer_id' => absint( $identity['customer_id'] ?? 0 ),
			'wp_user_id'  => absint( $identity['wp_user_id'] ?? 0 ),
			'email'       => self::normalize_email( (string) ( $identity['email'] ?? '' ) ),
			'phone'       => self::normalize_phone( (string) ( $identity['phone'] ?? '' ) ),
		);
	}

	public static function from_order( WC_Order $order ): array {
		return self::normalize(
			array(
				'customer_id' => self::get_persisted_order_customer_id( $order ),
				'wp_user_id'  => $order->get_customer_id(),
				'email'       => $order->get_billing_email(),
				'phone'       => $order->get_billing_phone(),
			)
		);
	}

	/**
	 * Read the explicit CRM link from the active WooCommerce data store.
	 *
	 * This bypasses a potentially stale in-request WC_Order object cache while
	 * remaining compatible with both HPOS and legacy post storage.
	 */
	public static function get_persisted_order_customer_ids( WC_Order $order ): array {
		$data_store = $order->get_data_store();
		$customer_ids = array();

		if ( $order->get_id() > 0 && is_object( $data_store ) && method_exists( $data_store, 'read_meta' ) ) {
			for ( $attempt = 0; $attempt < 2 && empty( $customer_ids ); $attempt++ ) {
				$metadata = $data_store->read_meta( $order );

				foreach ( is_array( $metadata ) ? $metadata : array() as $meta ) {
					if ( is_object( $meta ) && method_exists( $meta, 'get_data' ) ) {
						$meta = $meta->get_data();
					}

					$meta_key = is_object( $meta )
						? (string) ( $meta->meta_key ?? $meta->key ?? '' )
						: (string) ( $meta['meta_key'] ?? $meta['key'] ?? '' );
					$meta_value = is_object( $meta )
						? ( $meta->meta_value ?? $meta->value ?? 0 )
						: ( $meta['meta_value'] ?? $meta['value'] ?? 0 );

					if ( YoOhw_COS_Customers::ORDER_CUSTOMER_META_KEY === $meta_key ) {
						$customer_ids[] = absint( maybe_unserialize( $meta_value ) );
					}
				}
			}
		}

		return array_values( array_filter( $customer_ids ) );
	}

	private static function get_persisted_order_customer_id( WC_Order $order ): int {
		$customer_ids = self::get_persisted_order_customer_ids( $order );

		if ( ! empty( $customer_ids ) ) {
			return absint( end( $customer_ids ) );
		}

		return absint( $order->get_meta( YoOhw_COS_Customers::ORDER_CUSTOMER_META_KEY, true ) );
	}

	public static function acquire_creation_lock( array $identity ): string {
		$identity = self::normalize( $identity );
		$basis = '';

		foreach ( array( 'wp_user_id', 'email', 'phone' ) as $kind ) {
			if ( ! empty( $identity[ $kind ] ) ) {
				$basis = $kind . '|' . (string) $identity[ $kind ];
				break;
			}
		}

		if ( '' === $basis ) {
			return '';
		}

		$option = 'yoohw_cos_identity_lock_' . md5( $basis );
		$expires = absint( get_option( $option, 0 ) );

		if ( $expires > 0 && $expires < time() ) {
			delete_option( $option );
		}

		return add_option( $option, time() + self::LOCK_TTL, '', false ) ? $option : '';
	}

	public static function release_creation_lock( string $option ): void {
		if ( 0 === strpos( $option, 'yoohw_cos_identity_lock_' ) ) {
			delete_option( $option );
		}
	}

	/**
	 * Resolve by strength. Ambiguous values are never resolved with LIMIT 1.
	 */
	public static function resolve( array $identity ): array {
		$identity   = self::normalize( $identity );
		$precedence = array( 'customer_id', 'wp_user_id', 'email', 'phone' );
		$matches    = array();
		$conflicts  = array();

		foreach ( $precedence as $kind ) {
			$value = $identity[ $kind ];

			if ( empty( $value ) ) {
				continue;
			}

			$ids = self::find_matches( $kind, $value );
			$matches[ $kind ] = $ids;

			if ( count( $ids ) > 1 ) {
				$conflicts[ $kind ] = $ids;
			}
		}

		foreach ( $precedence as $kind ) {
			$ids = $matches[ $kind ] ?? array();

			if ( count( $ids ) > 1 ) {
				$result = array(
					'customer_id' => 0,
					'matched_by'  => '',
					'conflicts'   => $conflicts,
					'identity'    => $identity,
				);
				do_action( 'yoohw_cos_customer_identity_conflict', $result );

				return $result;
			}

			if ( 1 !== count( $ids ) ) {
				continue;
			}

			$customer_id = absint( $ids[0] );

			foreach ( $matches as $matched_kind => $matched_ids ) {
				if ( ! empty( $matched_ids ) && ! in_array( $customer_id, $matched_ids, true ) ) {
					$conflicts[ $matched_kind ] = $matched_ids;
				}
			}

			$result = array(
				'customer_id' => $customer_id,
				'matched_by'  => $kind,
				'conflicts'   => $conflicts,
				'identity'    => $identity,
			);

			if ( ! empty( $conflicts ) ) {
				do_action( 'yoohw_cos_customer_identity_conflict', $result );
			}

			return $result;
		}

		$result = array(
			'customer_id' => 0,
			'matched_by'  => '',
			'conflicts'   => $conflicts,
			'identity'    => $identity,
		);

		if ( ! empty( $conflicts ) ) {
			do_action( 'yoohw_cos_customer_identity_conflict', $result );
		}

		return $result;
	}

	/**
	 * Whether a canonical identity value is unowned or already belongs to the
	 * target customer. This is the write-side counterpart to resolve().
	 */
	public static function can_assign( string $kind, $value, int $customer_id ): bool {
		if ( ! in_array( $kind, array( 'wp_user_id', 'email', 'phone' ), true ) ) {
			return false;
		}

		$identity = self::normalize( array( $kind => $value ) );
		$value    = $identity[ $kind ];

		if ( empty( $value ) ) {
			return false;
		}

		$matches = self::find_matches( $kind, $value );

		return empty( $matches ) || ( 1 === count( $matches ) && absint( $matches[0] ) === absint( $customer_id ) );
	}

	private static function find_matches( string $kind, $value ): array {
		global $wpdb;

		$table = YoOhw_COS_DB::customers_table();

		if ( 'customer_id' === $kind ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare( 'SELECT id FROM %i WHERE id = %d LIMIT 2', $table, absint( $value ) )
			);
		} elseif ( 'wp_user_id' === $kind ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare( 'SELECT id FROM %i WHERE wp_user_id = %d ORDER BY id ASC LIMIT 2', $table, absint( $value ) )
			);
		} elseif ( 'email' === $kind ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare( 'SELECT id FROM %i WHERE email = %s ORDER BY id ASC LIMIT 2', $table, (string) $value )
			);
		} else {
			$ids = $wpdb->get_col(
				$wpdb->prepare( 'SELECT id FROM %i WHERE phone = %s ORDER BY id ASC LIMIT 2', $table, (string) $value )
			);
		}

		return array_values( array_unique( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) );
	}
}
