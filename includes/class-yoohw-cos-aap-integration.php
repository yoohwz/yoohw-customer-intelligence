<?php
defined( 'ABSPATH' ) || exit;

/** Optional, request-local AAP presentation. Never links identities or saves provider data. */
final class YoOhw_COS_AAP_Integration {
	public static function render_panel( array $customer ): void {
		$provider = 'WCAAP_Customer_Summary';
		if ( ! current_user_can( 'manage_woocommerce' ) || ! defined( $provider . '::VERSION' ) || 2 !== $provider::VERSION || ! is_callable( array( $provider, 'read' ) ) ) {
			return;
		}
		$user_id = self::linked_user( $customer );
		if ( ! $user_id || ! current_user_can( 'edit_user', $user_id ) ) { return; }
		try { $data = $provider::read( $user_id ); }
		catch ( Throwable $error ) { return; }
		if ( ! self::valid_summary( $data, $user_id ) || ! in_array( true, $data['features'], true ) ) { return; }

		echo '<section class="postbox yoohw-cos-profile-aap"><div class="postbox-header"><h2 class="hndle">' . esc_html__( 'Advanced Accounts', 'yoohw-customer-intelligence' ) . '</h2>';
		self::management_link( $data['links']['account'] ?? '', __( 'Manage account', 'yoohw-customer-intelligence' ), 'yoohw-cos-aap-manage', $user_id );
		echo '</div><div class="inside">';
		self::render_summary( $data, $user_id );
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
		if ( ! is_array( $data ) || 2 !== ( $data['version'] ?? null ) || $user_id !== ( $data['wp_user_id'] ?? null )
			|| ! is_array( $data['features'] ?? null ) || 4 !== count( $data['features'] ) || ! is_array( $data['verification'] ?? null ) || ! is_array( $data['memberships'] ?? null )
			|| ! is_array( $data['program'] ?? null ) || ! is_array( $data['links'] ?? null ) ) {
			return false;
		}
		foreach ( array( 'email_verification', 'phone_verification', 'membership', 'program' ) as $feature ) {
			if ( ! is_bool( $data['features'][ $feature ] ?? null ) ) { return false; }
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

	private static function badge( string $label, string $tone = 'muted' ): void {
		echo '<span class="yoohw-cos-aap-status yoohw-cos-aap-status--' . esc_attr( $tone ) . '">' . esc_html( $label ) . '</span>';
	}

	private static function render_summary( array $data, int $user_id ): void {
		$features = $data['features'];
		if ( $features['email_verification'] || $features['phone_verification'] ) {
			$labels = array( 'verified' => __( 'Verified', 'yoohw-customer-intelligence' ), 'unverified' => __( 'Not verified', 'yoohw-customer-intelligence' ), 'unknown' => __( 'Unknown', 'yoohw-customer-intelligence' ) );
			echo '<section class="yoohw-cos-aap-section"><h3>' . esc_html__( 'Verification', 'yoohw-customer-intelligence' ) . '</h3><dl class="yoohw-cos-aap-checks">';
			foreach ( array( 'email' => __( 'Email', 'yoohw-customer-intelligence' ), 'phone' => __( 'Phone', 'yoohw-customer-intelligence' ) ) as $channel => $label ) {
				if ( ! $features[ $channel . '_verification' ] ) { continue; }
				$status = $data['verification'][ $channel ];
				echo '<div class="yoohw-cos-aap-row"><dt>' . esc_html( $label ) . '</dt><dd>';
				self::badge( $labels[ $status ], 'verified' === $status ? 'positive' : ( 'unverified' === $status ? 'warning' : 'muted' ) );
				echo '</dd></div>';
			}
			echo '</dl></section>';
		}
		if ( $features['membership'] ) {
			echo '<section class="yoohw-cos-aap-section"><h3>' . esc_html__( 'Membership', 'yoohw-customer-intelligence' ) . '</h3>';
			if ( ! $data['memberships'] ) { echo '<p class="yoohw-cos-aap-note">' . esc_html__( 'No Membership recorded for this account.', 'yoohw-customer-intelligence' ) . '</p>'; }
			foreach ( $data['memberships'] as $membership ) {
				$status = $membership['effective'] ? __( 'Active', 'yoohw-customer-intelligence' ) : ( $membership['unknown'] ? __( 'Unconfirmed', 'yoohw-customer-intelligence' ) : __( 'Inactive', 'yoohw-customer-intelligence' ) );
				echo '<div class="yoohw-cos-aap-membership"><div class="yoohw-cos-aap-row"><strong>' . esc_html( $membership['label'] ) . '</strong>';
				self::badge( $status, $membership['effective'] ? 'positive' : 'muted' );
				echo '</div>';
				if ( $membership['loyalty'] ) { echo '<p class="yoohw-cos-aap-note">' . esc_html__( 'Includes Loyalty access; payment is not confirmed here.', 'yoohw-customer-intelligence' ) . '</p>'; }
				if ( $membership['unknown'] ) { echo '<p class="yoohw-cos-aap-note">' . esc_html__( 'Source or term not confirmed.', 'yoohw-customer-intelligence' ) . '</p>'; }
				if ( $membership['sources'] ) { self::render_sources( $membership['sources'] ); }
				echo '</div>';
			}
			echo '</section>';
		}
		if ( $features['program'] ) {
			$statuses = array( 'none' => __( 'Not submitted', 'yoohw-customer-intelligence' ), 'unknown' => __( 'Unknown', 'yoohw-customer-intelligence' ), 'draft' => __( 'Draft', 'yoohw-customer-intelligence' ), 'pending' => __( 'Awaiting review', 'yoohw-customer-intelligence' ), 'needs_info' => __( 'More information needed', 'yoohw-customer-intelligence' ), 'approved' => __( 'Profile approved', 'yoohw-customer-intelligence' ), 'rejected' => __( 'Application declined', 'yoohw-customer-intelligence' ) );
			$status = $data['program']['status'];
			echo '<section class="yoohw-cos-aap-section"><h3>' . esc_html__( 'Program application', 'yoohw-customer-intelligence' ) . '</h3><div class="yoohw-cos-aap-row">';
			if ( '' !== $data['program']['title'] ) { echo '<strong>' . esc_html( $data['program']['title'] ) . '</strong>'; }
			self::badge( $statuses[ $status ], 'approved' === $status ? 'positive' : ( in_array( $status, array( 'pending', 'needs_info' ), true ) ? 'warning' : 'muted' ) );
			echo '</div>';
			if ( $features['membership'] ) { echo '<p class="yoohw-cos-aap-note">' . esc_html__( 'Profile approval does not grant Membership.', 'yoohw-customer-intelligence' ) . '</p>'; }
			if ( in_array( $status, array( 'pending', 'needs_info', 'approved', 'rejected' ), true ) ) {
				self::management_link( $data['links']['program'] ?? '', __( 'Review application', 'yoohw-customer-intelligence' ), 'yoohw-cos-aap-review', $user_id );
			}
			echo '</section>';
		}
	}

	private static function render_sources( array $sources ): void {
		echo '<details class="yoohw-cos-aap-sources"><summary>' . esc_html__( 'Sources & terms', 'yoohw-customer-intelligence' ) . '</summary><ul>';
		$types = array( 'order' => __( 'Order', 'yoohw-customer-intelligence' ), 'subscription' => __( 'Subscription', 'yoohw-customer-intelligence' ), 'legacy' => __( 'Legacy source', 'yoohw-customer-intelligence' ) );
		foreach ( $sources as $source ) {
			if ( 'subscription' === $source['type'] || 'subscription' === $source['duration_type'] ) {
				$term = __( 'Term and renewal managed by subscription', 'yoohw-customer-intelligence' );
			} elseif ( 'unlimited' === $source['duration_type'] ) {
				$term = __( 'No fixed end', 'yoohw-customer-intelligence' );
			} else {
				/* translators: %s: Membership source end date supplied by Advanced Accounts. */
				$term = '' !== $source['end'] ? sprintf( __( 'Ends: %s', 'yoohw-customer-intelligence' ), $source['end'] ) : __( 'Term not confirmed', 'yoohw-customer-intelligence' );
			}
			echo '<li><div class="yoohw-cos-aap-row"><span>' . esc_html( $types[ $source['type'] ] ?? __( 'Other source', 'yoohw-customer-intelligence' ) ) . '</span>';
			self::badge( $source['effective'] ? __( 'Active', 'yoohw-customer-intelligence' ) : __( 'Inactive', 'yoohw-customer-intelligence' ), $source['effective'] ? 'positive' : 'muted' );
			echo '</div><span class="yoohw-cos-aap-note">' . esc_html( $term ) . '</span></li>';
		}
		echo '</ul></details>';
	}

	private static function management_link( $url, string $label, string $class, int $user_id ): void {
		if ( is_string( $url ) && self::safe_admin_url( $url ) && current_user_can( 'manage_woocommerce' ) && current_user_can( 'edit_user', $user_id ) ) {
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
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
