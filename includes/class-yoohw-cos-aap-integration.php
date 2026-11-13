<?php
defined( 'ABSPATH' ) || exit;

/** Optional, request-local AAP presentation. Never links identities or saves provider data. */
final class YoOhw_COS_AAP_Integration {
	public static function render_panel( array $customer ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$provider = 'WCAAP_Customer_Summary';
		if ( ! class_exists( $provider ) && ! class_exists( 'YOAA_WC_Advanced_Accounts_Premium' ) ) {
			return;
		}
		echo '<section class="postbox yoohw-cos-profile-aap"><div class="postbox-header"><h2 class="hndle">' . esc_html__( 'Advanced Accounts', 'yoohw-customer-intelligence' ) . '</h2></div><div class="inside">';
		$user_id = self::linked_user( $customer );
		if ( ! $user_id ) {
			echo '<p>' . esc_html__( 'No confirmed account link. AAP information is unavailable.', 'yoohw-customer-intelligence' ) . '</p>';
		} elseif ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_user', $user_id ) ) {
			echo '<p>' . esc_html__( 'You do not have permission to view AAP information for this account.', 'yoohw-customer-intelligence' ) . '</p>';
		} else {
			try {
				$data = defined( $provider . '::VERSION' ) && 1 === $provider::VERSION && is_callable( array( $provider, 'read' ) ) ? $provider::read( $user_id ) : null;
			} catch ( Throwable $error ) {
				$data = null;
			}
			if ( ! self::valid_summary( $data, $user_id ) ) {
				echo '<p>' . esc_html__( 'AAP information is unavailable. Check that Advanced Accounts Premium is active and supports this integration.', 'yoohw-customer-intelligence' ) . '</p>';
			} else {
				self::render_summary( $data, $user_id );
			}
		}
		echo '</div></section>';
	}

	/** A CRM ID and a WordPress user ID are separate namespaces. No email/phone fallback. */
	private static function linked_user( array $customer ): int {
		global $wpdb;
		$user_id = self::positive_id( $customer['wp_user_id'] ?? null );
		$customer_id = self::positive_id( $customer['id'] ?? null );
		if ( ! $user_id || ! $customer_id || ! get_userdata( $user_id ) ) {
			return 0;
		}
		$ids = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Confirm the persisted, unique CRM link without mutating identity state.
			'SELECT id FROM %i WHERE wp_user_id = %d ORDER BY id LIMIT 2', YoOhw_COS_DB::customers_table(), $user_id
		) );
		return ! $wpdb->last_error && is_array( $ids ) && 1 === count( $ids ) && $customer_id === self::positive_id( $ids[0] ) ? $user_id : 0;
	}

	private static function positive_id( $value ): int {
		if ( ! is_int( $value ) && ! is_string( $value ) ) {
			return 0;
		}
		return preg_match( '/^[1-9][0-9]*$/D', (string) $value ) && (string) (int) $value === (string) $value ? (int) $value : 0;
	}

	private static function valid_summary( $data, int $user_id ): bool {
		if ( ! is_array( $data ) || 1 !== ( $data['version'] ?? null ) || $user_id !== ( $data['wp_user_id'] ?? null )
			|| ! is_array( $data['verification'] ?? null ) || ! is_array( $data['memberships'] ?? null )
			|| ! is_array( $data['program'] ?? null ) || ! is_array( $data['links'] ?? null ) ) {
			return false;
		}
		foreach ( array( 'email', 'phone' ) as $channel ) {
			if ( ! in_array( $data['verification'][ $channel ] ?? null, array( 'verified', 'unverified', 'unknown' ), true ) ) { return false; }
		}
		if ( ! in_array( $data['program']['status'] ?? null, array( 'none', 'unknown', 'draft', 'pending', 'needs_info', 'approved', 'rejected' ), true ) || ! is_string( $data['program']['title'] ?? null ) ) { return false; }
		foreach ( $data['memberships'] as $membership ) {
			if ( ! is_array( $membership ) || ! is_string( $membership['role'] ?? null ) || ! is_string( $membership['label'] ?? null )
				|| ! is_bool( $membership['loyalty'] ?? null ) || ! is_bool( $membership['effective'] ?? null ) || ! is_bool( $membership['unknown'] ?? null ) || ! is_array( $membership['sources'] ?? null ) ) { return false; }
			foreach ( $membership['sources'] as $source ) {
				if ( ! is_array( $source ) || ! is_string( $source['type'] ?? null ) || ! is_string( $source['status'] ?? null )
					|| ! is_bool( $source['effective'] ?? null ) || ! is_string( $source['end'] ?? null ) || ! is_string( $source['duration_type'] ?? null ) ) { return false; }
			}
		}
		return true;
	}

	private static function render_summary( array $data, int $user_id ): void {
		$labels = array( 'verified' => __( 'Verified', 'yoohw-customer-intelligence' ), 'unverified' => __( 'Not verified', 'yoohw-customer-intelligence' ), 'unknown' => __( 'Unknown', 'yoohw-customer-intelligence' ) );
		echo '<dl>';
		foreach ( array( 'email' => __( 'Email verification', 'yoohw-customer-intelligence' ), 'phone' => __( 'Phone verification', 'yoohw-customer-intelligence' ) ) as $channel => $label ) {
			echo '<dt><strong>' . esc_html( $label ) . '</strong></dt><dd>' . esc_html( $labels[ $data['verification'][ $channel ] ] ) . '</dd>';
		}
		echo '</dl><h3>' . esc_html__( 'Membership', 'yoohw-customer-intelligence' ) . '</h3>';
		if ( ! $data['memberships'] ) {
			echo '<p>' . esc_html__( 'No recorded AAP Membership.', 'yoohw-customer-intelligence' ) . '</p>';
		}
		foreach ( $data['memberships'] as $membership ) {
			$status = $membership['effective'] ? __( 'Active', 'yoohw-customer-intelligence' ) : ( $membership['unknown'] ? __( 'Unconfirmed', 'yoohw-customer-intelligence' ) : __( 'Inactive', 'yoohw-customer-intelligence' ) );
			echo '<p><strong>' . esc_html( $membership['label'] ) . '</strong> — ' . esc_html( $status ) . '</p>';
			if ( $membership['loyalty'] ) {
				echo '<p class="description">' . esc_html__( 'Includes Loyalty access evidence; this is not proof of Membership payment.', 'yoohw-customer-intelligence' ) . '</p>';
			}
			if ( $membership['unknown'] ) {
				echo '<p class="description">' . esc_html__( 'Some Membership source or term information is unknown.', 'yoohw-customer-intelligence' ) . '</p>';
			}
			if ( $membership['sources'] ) {
				echo '<ul>';
				$types = array( 'order' => __( 'Order', 'yoohw-customer-intelligence' ), 'subscription' => __( 'Subscription', 'yoohw-customer-intelligence' ), 'legacy' => __( 'Legacy source', 'yoohw-customer-intelligence' ) );
				foreach ( $membership['sources'] as $source ) {
					$term = '' !== $source['end'] ? sprintf( __( 'Ends: %s', 'yoohw-customer-intelligence' ), $source['end'] ) : ( 'unlimited' === $source['duration_type'] ? __( 'No fixed end', 'yoohw-customer-intelligence' ) : __( 'Term managed by source or unknown', 'yoohw-customer-intelligence' ) );
					echo '<li>' . esc_html( ( $types[ $source['type'] ] ?? __( 'Other source', 'yoohw-customer-intelligence' ) ) . ' — ' . ( $source['effective'] ? __( 'Active', 'yoohw-customer-intelligence' ) : __( 'Inactive', 'yoohw-customer-intelligence' ) ) . ' — ' . $term ) . '</li>';
				}
				echo '</ul>';
			}
		}
		$statuses = array( 'none' => __( 'No application', 'yoohw-customer-intelligence' ), 'unknown' => __( 'Unknown', 'yoohw-customer-intelligence' ), 'draft' => __( 'Draft', 'yoohw-customer-intelligence' ), 'pending' => __( 'Awaiting review', 'yoohw-customer-intelligence' ), 'needs_info' => __( 'More information needed', 'yoohw-customer-intelligence' ), 'approved' => __( 'Profile approved', 'yoohw-customer-intelligence' ), 'rejected' => __( 'Application declined', 'yoohw-customer-intelligence' ) );
		echo '<h3>' . esc_html__( 'Program application', 'yoohw-customer-intelligence' ) . '</h3><p>' . esc_html( $data['program']['title'] ) . '</p><p>' . esc_html( $statuses[ $data['program']['status'] ] ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Profile approval, identity verification and Membership are separate. Manage changes in Advanced Accounts.', 'yoohw-customer-intelligence' ) . '</p>';
		foreach ( array( 'account' => __( 'Manage account in AAP', 'yoohw-customer-intelligence' ), 'program' => __( 'Review program application', 'yoohw-customer-intelligence' ) ) as $key => $label ) {
			$url = $data['links'][ $key ] ?? '';
			if ( is_string( $url ) && self::safe_admin_url( $url ) && current_user_can( 'manage_woocommerce' ) && current_user_can( 'edit_user', $user_id ) ) {
				echo '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></p>';
			}
		}
	}

	private static function safe_admin_url( string $url ): bool {
		$base = wp_parse_url( admin_url() );
		$target = wp_parse_url( $url );
		$path = is_array( $target ) ? rawurldecode( $target['path'] ?? '' ) : '';
		return is_array( $base ) && is_array( $target ) && in_array( $target['scheme'] ?? '', array( 'http', 'https' ), true )
			&& ( $base['scheme'] ?? '' ) === ( $target['scheme'] ?? '' ) && ( $base['host'] ?? '' ) === ( $target['host'] ?? '' )
			&& ( $base['port'] ?? null ) === ( $target['port'] ?? null ) && ! isset( $target['user'] ) && ! isset( $target['pass'] )
			&& 0 === strpos( $path, $base['path'] ?? '/wp-admin/' ) && ! in_array( '..', explode( '/', $path ), true )
			&& ! in_array( '.', explode( '/', $path ), true ) && false === strpos( $url, '\\' );
	}
}
