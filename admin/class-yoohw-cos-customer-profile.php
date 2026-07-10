<?php
defined( 'ABSPATH' ) || exit;

final class YoOhw_COS_Customer_Profile {

	public static function render( int $customer_id ): void {
		$customer = YoOhw_COS_Customers::get_customer( $customer_id );

		if ( ! $customer ) {
			echo '<div class="wrap yoohw-cos-admin">';
			echo '<h1>' . esc_html__( 'Customer not found', 'yoohw-customer-intelligence' ) . '</h1>';
			echo '<p>' . esc_html__( 'The requested customer profile does not exist.', 'yoohw-customer-intelligence' ) . '</p>';
			echo '</div>';
			return;
		}

		$events = YoOhw_COS_Events::get_customer_events( $customer_id, array(
			'limit' => 20,
		) );

		$tags = YoOhw_COS_Tags::get_customer_tags( $customer_id );
		$notes = YoOhw_COS_Notes::get_customer_notes( $customer_id );
		$risk_factors = YoOhw_COS_Intelligence::get_risk_factors( $customer );
		$trust_factors = YoOhw_COS_Intelligence::get_trust_factors( $customer );
		$orders = YoOhw_COS_Customers::get_customer_orders( $customer, 10 );
		$lifecycle_factors = YoOhw_COS_Intelligence::get_lifecycle_factors( $customer );
		$segments = YoOhw_COS_Segments::get_customer_segments( $customer_id );
		$tasks = YoOhw_COS_Tasks::get_customer_tasks( $customer_id, array(
			'limit'  => 8,
			'status' => 'open',
		) );

		echo '<div class="wrap yoohw-cos-admin yoohw-cos-profile">';

		self::render_profile_header( $customer );
		self::render_summary_cards( $customer );
		self::render_profile_grid( $customer, $events, $tags, $notes, $risk_factors, $trust_factors, $lifecycle_factors, $orders, $segments, $tasks );

		echo '</div>';
	}

	private static function render_profile_header( array $customer ): void {
		$name  = self::get_customer_name( $customer );
		$email = $customer['email'] ?? '';
		$phone = $customer['phone'] ?? '';
		$list_url = add_query_arg(
			array(
				'page' => 'yoohw-customer-intelligence',
			),
			admin_url( 'admin.php' )
		);

		echo '<div class="yoohw-cos-profile-heading">';
		echo '<h1 class="wp-heading-inline">' . esc_html( $name ) . '</h1>';
		echo ' <a class="page-title-action" href="' . esc_url( $list_url ) . '">' . esc_html__( 'Back to customers', 'yoohw-customer-intelligence' ) . '</a>';
		echo '<hr class="wp-header-end">';

		echo '<div class="yoohw-cos-profile-toolbar">';
		echo '<div class="yoohw-cos-profile-meta">';

		if ( $email ) {
			echo '<span><span class="dashicons dashicons-email-alt"></span><a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a></span>';
		}

		if ( $phone ) {
			echo '<span><span class="dashicons dashicons-phone"></span><a href="tel:' . esc_attr( $phone ) . '">' . esc_html( $phone ) . '</a></span>';
		}

		if ( ! empty( $customer['wp_user_id'] ) ) {
			$user_url = get_edit_user_link( absint( $customer['wp_user_id'] ) );

			if ( $user_url ) {
				echo '<span><span class="dashicons dashicons-admin-users"></span><a href="' . esc_url( $user_url ) . '">' . esc_html__( 'Edit WP user', 'yoohw-customer-intelligence' ) . '</a></span>';
			}
		}

		echo '</div>';

		echo '<div class="yoohw-cos-profile-badges">';

		$status          = sanitize_key( (string) ( $customer['customer_status'] ?? '' ) );
		$lifecycle_stage = sanitize_key( (string) ( $customer['lifecycle_stage'] ?? 'new' ) );
		$vip_status      = sanitize_key( (string) ( $customer['vip_status'] ?? 'none' ) );
		$has_vip_tier    = '' !== $vip_status && 'none' !== $vip_status;

		if ( ! ( $has_vip_tier && 'vip' === $status ) ) {
			echo wp_kses_post( self::render_status_badge( $status ) );
		}

		if (
			! ( $has_vip_tier && 'vip' === $lifecycle_stage )
			&& strtolower( self::format_lifecycle_label( $lifecycle_stage ) ) !== strtolower( self::format_status_label( $status ) )
		) {
			echo wp_kses_post( self::render_lifecycle_badge( $lifecycle_stage ) );
		}

		echo wp_kses_post( self::render_risk_badge( (float) ( $customer['risk_score'] ?? 0 ) ) );
		echo wp_kses_post( self::render_trust_badge( (float) ( $customer['trust_score'] ?? 0 ) ) );
		echo wp_kses_post( self::render_blacklist_status_badge( $customer ) );

		if ( $has_vip_tier ) {
			echo wp_kses_post( self::render_vip_badge( $vip_status ) );
		}

		if ( self::is_loyalty_integration_active() ) {
			echo wp_kses( self::render_loyalty_level_badge( $customer ), self::get_detail_row_allowed_html() );
		}

		echo '</div>';

		echo '</div>';
		echo '</div>';
	}

	private static function render_summary_cards( array $customer ): void {
		echo '<div class="postbox yoohw-cos-profile-summary">';
		echo '<div class="postbox-header"><h2 class="hndle">' . esc_html__( 'Customer summary', 'yoohw-customer-intelligence' ) . '</h2></div>';
		echo '<div class="inside">';
		echo '<div class="yoohw-cos-summary-cards">';

		self::render_card(
			__( 'Total spent', 'yoohw-customer-intelligence' ),
			function_exists( 'wc_price' ) ? wc_price( (float) $customer['total_spent'] ) : esc_html( $customer['total_spent'] )
		);

		self::render_card(
			__( 'Orders', 'yoohw-customer-intelligence' ),
			number_format_i18n( (int) $customer['total_orders'] )
		);

		self::render_card(
			__( 'Average order value', 'yoohw-customer-intelligence' ),
			function_exists( 'wc_price' ) ? wc_price( (float) $customer['average_order_value'] ) : esc_html( $customer['average_order_value'] )
		);

		self::render_card(
			__( 'Risk score', 'yoohw-customer-intelligence' ),
			number_format_i18n( (float) $customer['risk_score'], 2 )
		);

		self::render_card(
			__( 'Trust score', 'yoohw-customer-intelligence' ),
			number_format_i18n( (float) $customer['trust_score'], 2 )
		);

		echo '</div>';
		echo '</div>';
		echo '</div>';
	}

	private static function render_card( string $label, string $value ): void {
		echo '<div class="yoohw-cos-stat-card">';
		echo '<div class="yoohw-cos-stat-card__label">' . esc_html( $label ) . '</div>';
		echo '<div class="yoohw-cos-stat-card__value">' . wp_kses_post( $value ) . '</div>';
		echo '</div>';
	}

	private static function render_profile_grid(
		array $customer,
		array $events,
		array $tags,
		array $notes,
		array $risk_factors,
		array $trust_factors,
		array $lifecycle_factors,
		array $orders,
		array $segments,
		array $tasks
	): void {
		echo '<div class="yoohw-cos-profile-sections">';

		self::render_profile_section_header( __( 'Overview', 'yoohw-customer-intelligence' ) );
		$loyalty_active = self::is_loyalty_integration_active();
		echo '<div class="yoohw-cos-profile-section-grid yoohw-cos-profile-section-grid--overview ' . esc_attr( $loyalty_active ? 'yoohw-cos-profile-section-grid--overview-with-loyalty' : 'yoohw-cos-profile-section-grid--overview-without-loyalty' ) . '">';
		self::render_commerce_summary( $customer );

		if ( $loyalty_active ) {
			self::render_loyalty_panel( $customer );
		}

		self::render_identity_panel( $customer );
		echo '</div>';

		self::render_profile_section_header( __( 'Commerce', 'yoohw-customer-intelligence' ) );
		echo '<div class="yoohw-cos-profile-section-grid yoohw-cos-profile-section-grid--commerce">';
		self::render_customer_orders( $orders, $customer );
		self::render_address_panel( $customer );
		self::render_acquisition_panel( $customer );
		echo '</div>';

		self::render_profile_section_header( __( 'Operations', 'yoohw-customer-intelligence' ) );
		echo '<div class="yoohw-cos-profile-section-grid yoohw-cos-profile-section-grid--operations">';
		echo '<div>';
		self::render_customer_tasks( (int) $customer['id'], $tasks );
		self::render_customer_notes( (int) $customer['id'], $notes );
		echo '</div>';

		echo '<div>';
		self::render_customer_tags( (int) $customer['id'], $tags );
		self::render_customer_segments( (int) $customer['id'], $segments );
		echo '</div>';
		echo '</div>';

		self::render_profile_section_header( __( 'Customer', 'yoohw-customer-intelligence' ) );
		echo '<div class="yoohw-cos-profile-section-grid yoohw-cos-profile-section-grid--intelligence">';
		self::render_risk_panel( $customer, $risk_factors );
		self::render_trust_panel( $customer, $trust_factors );
		self::render_lifecycle_panel( $customer, $lifecycle_factors );
		echo '</div>';

		if ( self::is_blacklist_manager_premium_integration_active() ) {
			self::render_profile_section_header( __( 'Security', 'yoohw-customer-intelligence' ) );
			self::render_security_signals_panel( (int) $customer['id'] );
		}

		self::render_profile_section_header( __( 'Activity', 'yoohw-customer-intelligence' ) );
		self::render_timeline( (int) $customer['id'], $events );

		echo '</div>';
	}

	private static function render_profile_section_header( string $title ): void {
		echo '<div class="yoohw-cos-profile-section-header">';
		echo '<h2>' . esc_html( $title ) . '</h2>';
		echo '</div>';
	}

	private static function render_commerce_summary( array $customer ): void {
		echo '<div class="postbox">';
		echo '<div class="postbox-header"><h2 class="hndle">' . esc_html__( 'Commerce summary', 'yoohw-customer-intelligence' ) . '</h2></div>';
		echo '<div class="inside">';

		echo '<table class="widefat striped">';

		self::render_detail_row(
			__( 'Status', 'yoohw-customer-intelligence' ),
			self::render_status_badge( $customer['customer_status'] ?? '' ),
			true
		);

		self::render_detail_row(
			__( 'Lifecycle stage', 'yoohw-customer-intelligence' ),
			self::render_lifecycle_badge( $customer['lifecycle_stage'] ?? 'new' ),
			true
		);

		self::render_detail_row(
			__( 'Value tier', 'yoohw-customer-intelligence' ),
			YoOhw_COS_Intelligence::get_value_tier_label( (string) ( $customer['vip_status'] ?? 'none' ) )
		);

		self::render_detail_row(
			__( 'Total orders', 'yoohw-customer-intelligence' ),
			number_format_i18n( (int) ( $customer['total_orders'] ?? 0 ) )
		);

		self::render_detail_row(
			__( 'Total spent', 'yoohw-customer-intelligence' ),
			function_exists( 'wc_price' )
				? wc_price( (float) ( $customer['total_spent'] ?? 0 ) )
				: number_format_i18n( (float) ( $customer['total_spent'] ?? 0 ), 2 ),
			true
		);

		self::render_detail_row(
			__( 'Average order value', 'yoohw-customer-intelligence' ),
			function_exists( 'wc_price' )
				? wc_price( (float) ( $customer['average_order_value'] ?? 0 ) )
				: number_format_i18n( (float) ( $customer['average_order_value'] ?? 0 ), 2 ),
			true
		);

		self::render_detail_row(
			__( 'Last order', 'yoohw-customer-intelligence' ),
			self::render_order_link( absint( $customer['last_order_id'] ?? 0 ) ),
			true
		);

		self::render_detail_row(
			__( 'Last order date', 'yoohw-customer-intelligence' ),
			self::format_date( $customer['last_order_date'] ?? '' )
		);

		self::render_detail_row(
			__( 'Last activity', 'yoohw-customer-intelligence' ),
			self::format_date( $customer['last_activity_date'] ?? '' )
		);

		echo '</table>';

		echo '</div>';
		echo '</div>';
	}

	private static function render_loyalty_panel( array $customer ): void {
		$loyalty_data     = self::get_loyalty_display_data( $customer );
		$level            = sanitize_key( (string) ( $loyalty_data['loyalty_level'] ?? '' ) );
		$available_points = (int) ( $loyalty_data['available_points'] ?? 0 );
		$earned_points    = (int) ( $loyalty_data['earned_points'] ?? 0 );
		$has_loyalty_data = '' !== $level || 0 !== $available_points || 0 !== $earned_points;

		echo '<div class="postbox">';
		echo '<div class="postbox-header"><h2 class="hndle">' . esc_html__( 'Loyalty', 'yoohw-customer-intelligence' ) . '</h2></div>';
		echo '<div class="inside">';

		if ( ! $has_loyalty_data ) {
			YoOhw_COS_Admin_UI::render_empty_state(
				__( 'No loyalty data yet.', 'yoohw-customer-intelligence' ),
				__( 'Loyalty level and points will appear after this profile is linked to a registered loyalty user.', 'yoohw-customer-intelligence' ),
				array(),
				'compact'
			);
			echo '</div>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped">';

		self::render_detail_row(
			__( 'Level', 'yoohw-customer-intelligence' ),
			self::render_loyalty_level_badge( $loyalty_data ),
			true
		);

		self::render_detail_row(
			__( 'Available points', 'yoohw-customer-intelligence' ),
			number_format_i18n( $available_points )
		);

		self::render_detail_row(
			__( 'Earned points', 'yoohw-customer-intelligence' ),
			number_format_i18n( $earned_points )
		);

		echo '</table>';

		echo '</div>';
		echo '</div>';
	}

	private static function render_detail_row( string $label, string $value, bool $allow_html = false ): void {
		echo '<tr>';
		echo '<th class="yoohw-cos-detail-label">' . esc_html( $label ) . '</th>';
		echo '<td>';

		if ( $allow_html ) {
			echo wp_kses( $value ?: '—', self::get_detail_row_allowed_html() );
		} else {
			echo esc_html( $value ?: '—' );
		}

		echo '</td>';
		echo '</tr>';
	}

	private static function get_detail_row_allowed_html(): array {
		$allowed = wp_kses_allowed_html( 'post' );

		$allowed['button'] = array(
			'aria-label' => true,
			'class'      => true,
			'data-copy'  => true,
			'title'      => true,
			'type'       => true,
		);

		$allowed['span'] = array_merge(
			$allowed['span'] ?? array(),
			array(
				'aria-hidden' => true,
				'class'       => true,
				'style'       => true,
			)
		);

		return $allowed;
	}

	private static function render_identity_panel( array $customer ): void {
		echo '<div class="postbox">';
		echo '<div class="postbox-header"><h2 class="hndle">' . esc_html__( 'Identity', 'yoohw-customer-intelligence' ) . '</h2></div>';
		echo '<div class="inside">';

		echo '<table class="widefat striped">';

		self::render_detail_row(
			__( 'Customer ID', 'yoohw-customer-intelligence' ),
			'#' . absint( $customer['id'] ?? 0 )
		);

		self::render_detail_row(
			__( 'WP User ID', 'yoohw-customer-intelligence' ),
			! empty( $customer['wp_user_id'] ) ? '#' . absint( $customer['wp_user_id'] ) : '—'
		);

		self::render_detail_row(
			__( 'Email', 'yoohw-customer-intelligence' ),
			self::format_identity_copyable_value(
				sanitize_email( (string) ( $customer['email'] ?? '' ) ),
				__( 'Copy email', 'yoohw-customer-intelligence' )
			),
			true
		);

		self::render_detail_row(
			__( 'Phone', 'yoohw-customer-intelligence' ),
			self::format_identity_copyable_value(
				sanitize_text_field( (string) ( $customer['phone'] ?? '' ) ),
				__( 'Copy phone', 'yoohw-customer-intelligence' )
			),
			true
		);

		self::render_detail_row(
			__( 'Created', 'yoohw-customer-intelligence' ),
			self::format_date( $customer['created_at'] ?? '' )
		);

		self::render_detail_row(
			__( 'Updated', 'yoohw-customer-intelligence' ),
			self::format_date( $customer['updated_at'] ?? '' )
		);

		echo '</table>';

		echo '<p class="yoohw-cos-action-row">';

		if ( ! empty( $customer['email'] ) ) {
			echo '<a class="button" href="mailto:' . esc_attr( $customer['email'] ) . '">';
			echo esc_html__( 'Email customer', 'yoohw-customer-intelligence' );
			echo '</a>';
		}

		if ( ! empty( $customer['phone'] ) ) {
			echo '<a class="button" href="tel:' . esc_attr( $customer['phone'] ) . '">';
			echo esc_html__( 'Call customer', 'yoohw-customer-intelligence' );
			echo '</a>';
		}

		echo '</p>';

		echo '</div>';
		echo '</div>';
	}

	private static function format_identity_copyable_value( string $value, string $copy_label ): string {
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		return sprintf(
			'<span class="yoohw-cos-identity-copyable"><span class="yoohw-cos-identity-value">%1$s</span><button type="button" class="yoohw-cos-copy yoohw-cos-copy-icon" data-copy="%2$s" aria-label="%3$s" title="%3$s"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span></button></span>',
			esc_html( $value ),
			esc_attr( $value ),
			esc_attr( $copy_label )
		);
	}

	private static function render_address_panel( array $customer ): void {
		$order = null;

		if ( ! empty( $customer['last_order_id'] ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( absint( $customer['last_order_id'] ) );
		}

		if ( ! $order instanceof WC_Order ) {
			$orders = YoOhw_COS_Customers::get_customer_orders( $customer, 1 );
			$order  = ! empty( $orders[0] ) && $orders[0] instanceof WC_Order ? $orders[0] : null;
		}

		echo '<div class="postbox">';
		echo '<div class="postbox-header"><h2 class="hndle">' . esc_html__( 'Addresses', 'yoohw-customer-intelligence' ) . '</h2></div>';
		echo '<div class="inside">';

		if ( ! $order instanceof WC_Order ) {
			YoOhw_COS_Admin_UI::render_empty_state(
				__( 'No address data yet.', 'yoohw-customer-intelligence' ),
				__( 'Billing and shipping addresses will appear after this customer has a synced WooCommerce order.', 'yoohw-customer-intelligence' ),
				array(),
				'compact'
			);
			echo '</div>';
			echo '</div>';
			return;
		}

		echo '<div class="yoohw-cos-address-grid">';

		self::render_address_card(
			__( 'Billing address', 'yoohw-customer-intelligence' ),
			$order->get_formatted_billing_address()
		);

		self::render_address_card(
			__( 'Shipping address', 'yoohw-customer-intelligence' ),
			$order->get_formatted_shipping_address()
		);

		echo '</div>';

		echo '<p class="yoohw-cos-help-text yoohw-cos-help-text--spaced">';
		printf(
			/* translators: %s: WooCommerce order number. */
			esc_html__( 'Address data is based on the latest WooCommerce order: #%s.', 'yoohw-customer-intelligence' ),
			esc_html( $order->get_order_number() )
		);
		echo '</p>';

		echo '</div>';
		echo '</div>';
	}

	private static function render_address_card( string $title, string $address_html ): void {
		echo '<div class="yoohw-cos-address-card">';
		echo '<h3>' . esc_html( $title ) . '</h3>';

		if ( empty( $address_html ) ) {
			echo '<p class="yoohw-cos-muted">' . esc_html__( 'No address available.', 'yoohw-customer-intelligence' ) . '</p>';
		} else {
			echo '<div class="yoohw-cos-address-content">' . wp_kses_post( $address_html ) . '</div>';
		}

		echo '</div>';
	}

	private static function render_acquisition_panel( array $customer ): void {
		echo '<div class="postbox">';
		echo '<div class="postbox-header"><h2 class="hndle">' . esc_html__( 'Acquisition', 'yoohw-customer-intelligence' ) . '</h2></div>';
		echo '<div class="inside">';

		echo '<table class="widefat striped">';

		self::render_detail_row(
			__( 'First seen', 'yoohw-customer-intelligence' ),
			self::format_date( $customer['created_at'] ?? '' )
		);

		self::render_detail_row(
			__( 'First order', 'yoohw-customer-intelligence' ),
			self::render_order_link( absint( $customer['first_order_id'] ?? 0 ) ),
			true
		);

		self::render_detail_row(
			__( 'First order date', 'yoohw-customer-intelligence' ),
			self::format_date( $customer['first_order_date'] ?? '' )
		);

		self::render_detail_row(
			__( 'Latest order', 'yoohw-customer-intelligence' ),
			self::render_order_link( absint( $customer['last_order_id'] ?? 0 ) ),
			true
		);

		self::render_detail_row(
			__( 'Source', 'yoohw-customer-intelligence' ),
			__( 'Unknown', 'yoohw-customer-intelligence' )
		);

		self::render_detail_row(
			__( 'Campaign', 'yoohw-customer-intelligence' ),
			__( 'Not tracked yet', 'yoohw-customer-intelligence' )
		);

		echo '</table>';

		echo '</div>';
		echo '</div>';
	}

	private static function get_customer_orders_admin_url( array $customer ): string {
		$email      = sanitize_email( $customer['email'] ?? '' );
		$wp_user_id = absint( $customer['wp_user_id'] ?? 0 );

		if (
			class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
			&& method_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class, 'custom_orders_table_usage_is_enabled' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
		) {
			return add_query_arg(
				array_filter(
					array(
						'page' => 'wc-orders',
						's'    => $email,
					)
				),
				admin_url( 'admin.php' )
			);
		}

		return add_query_arg(
			array_filter(
				array(
					'post_type'      => 'shop_order',
					'_customer_user' => $wp_user_id,
					's'              => $email,
				)
			),
			admin_url( 'edit.php' )
		);
	}

	private static function get_profile_url( int $customer_id, string $anchor = '' ): string {
		$url = add_query_arg(
			array(
				'page'        => 'yoohw-customer-intelligence',
				'customer_id' => absint( $customer_id ),
			),
			admin_url( 'admin.php' )
		);

		if ( '' !== $anchor ) {
			$url .= '#' . sanitize_html_class( $anchor );
		}

		return $url;
	}

	private static function render_customer_orders( array $orders, array $customer ): void {
		$total_orders = absint( $customer['total_orders'] ?? 0 );
		$orders_url   = self::get_customer_orders_admin_url( $customer );
		$shown_orders = 0;

		echo '<div class="postbox">';
		echo '<div class="postbox-header"><h2 class="hndle">' . esc_html__( 'Recent orders', 'yoohw-customer-intelligence' ) . '</h2></div>';
		echo '<div class="inside">';

		if ( empty( $orders ) ) {
			YoOhw_COS_Admin_UI::render_empty_state(
				__( 'No orders found.', 'yoohw-customer-intelligence' ),
				__( 'Recent WooCommerce orders will appear here after this customer is synced.', 'yoohw-customer-intelligence' ),
				array(),
				'compact'
			);
			echo '</div>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead>';
		echo '<tr>';
		echo '<th>' . esc_html__( 'Order', 'yoohw-customer-intelligence' ) . '</th>';
		echo '<th>' . esc_html__( 'Date', 'yoohw-customer-intelligence' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'yoohw-customer-intelligence' ) . '</th>';
		echo '<th>' . esc_html__( 'Total', 'yoohw-customer-intelligence' ) . '</th>';
		echo '</tr>';
		echo '</thead>';
		echo '<tbody>';

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			++$shown_orders;

			$edit_url  = $order->get_edit_order_url();
			$date      = $order->get_date_created() ? $order->get_date_created()->date_i18n( get_option( 'date_format' ) ) : '—';
			$status    = wc_get_order_status_name( $order->get_status() );
			$total     = $order->get_formatted_order_total();

			echo '<tr>';

			echo '<td>';
			echo '<a href="' . esc_url( $edit_url ) . '"><strong>#' . esc_html( $order->get_order_number() ) . '</strong></a>';
			echo '</td>';

			echo '<td>' . esc_html( $date ) . '</td>';
			echo '<td>' . esc_html( $status ) . '</td>';
			echo '<td>' . wp_kses_post( $total ) . '</td>';

			echo '</tr>';
		}

		echo '</tbody>';
		echo '</table>';

		if ( $total_orders > $shown_orders ) {
			echo '<p class="yoohw-cos-panel-actions">';
			printf(
				/* translators: 1: number of orders currently shown, 2: total number of customer orders. */
				esc_html__( 'Showing the latest %1$s of %2$s orders.', 'yoohw-customer-intelligence' ),
				esc_html( number_format_i18n( $shown_orders ) ),
				esc_html( number_format_i18n( $total_orders ) )
			);
			echo ' <a class="button button-small" href="' . esc_url( $orders_url ) . '">' . esc_html__( 'View all orders', 'yoohw-customer-intelligence' ) . '</a>';
			echo '</p>';
		}

		echo '</div>';
		echo '</div>';
	}

	private static function render_security_signals_panel( int $customer_id ): void {
		if ( ! self::is_blacklist_manager_premium_integration_active() ) {
			return;
		}

		$summary = array();

		if (
			class_exists( 'YoOhw_COS_Blacklist_Manager_Premium_Integration' )
			&& is_callable( array( 'YoOhw_COS_Blacklist_Manager_Premium_Integration', 'get_customer_security_summary' ) )
		) {
			$summary = YoOhw_COS_Blacklist_Manager_Premium_Integration::get_customer_security_summary( $customer_id );
		}

		echo '<div class="postbox">';
		echo '<div class="postbox-header"><h2 class="hndle">' . esc_html__( 'Security signals', 'yoohw-customer-intelligence' ) . '</h2></div>';
		echo '<div class="inside">';

		if ( empty( $summary ) ) {
			YoOhw_COS_Admin_UI::render_empty_state(
				__( 'No premium security signals yet.', 'yoohw-customer-intelligence' ),
				__( 'Premium order risk, anti-bot, payment, and device signals will appear here when matched to this customer.', 'yoohw-customer-intelligence' ),
				array(),
				'compact'
			);
			echo '</div>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped">';

		self::render_detail_row(
			__( 'Latest premium order risk', 'yoohw-customer-intelligence' ),
			self::format_security_order_risk( (array) ( $summary['latest_order_risk'] ?? array() ) ),
			true
		);

		self::render_detail_row(
			__( 'Highest risk order', 'yoohw-customer-intelligence' ),
			self::format_security_order_risk( (array) ( $summary['highest_order_risk'] ?? array() ) ),
			true
		);

		self::render_detail_row(
			__( 'Matched premium rules', 'yoohw-customer-intelligence' ),
			self::format_security_rule_list( (array) ( $summary['matched_rules'] ?? array() ) ),
			true
		);

		self::render_detail_row(
			__( 'Recent anti-bot/payment/device events', 'yoohw-customer-intelligence' ),
			self::format_security_event_list( (array) ( $summary['recent_events'] ?? array() ) ),
			true
		);

		echo '</table>';
		echo '</div>';
		echo '</div>';
	}

	private static function format_security_order_risk( array $item ): string {
		$order_id = absint( $item['order_id'] ?? 0 );

		if ( $order_id <= 0 && empty( $item['risk_score'] ) ) {
			return '—';
		}

		$parts = array();

		if ( $order_id > 0 ) {
			$parts[] = self::render_order_link( $order_id );
		}

		$parts[] = sprintf(
			/* translators: %d: risk score. */
			esc_html__( 'Risk %d', 'yoohw-customer-intelligence' ),
			max( 0, (int) ( $item['risk_score'] ?? 0 ) )
		);

		if ( ! empty( $item['created_at'] ) ) {
			$parts[] = esc_html( self::format_date( (string) $item['created_at'] ) );
		}

		return implode( ' · ', $parts );
	}

	private static function format_security_rule_list( array $rules ): string {
		if ( empty( $rules ) ) {
			return '—';
		}

		$html = '<ul class="yoohw-cos-factor-list yoohw-cos-factor-list--compact">';

		foreach ( array_slice( $rules, 0, 8 ) as $rule ) {
			$label = sanitize_text_field( (string) ( $rule['label'] ?? '' ) );

			if ( '' === $label ) {
				continue;
			}

			$html .= '<li>';
			$html .= '<div class="yoohw-cos-factor-list__row">';
			$html .= '<strong>' . esc_html( $label ) . '</strong>';
			$html .= '<span class="yoohw-cos-factor-impact yoohw-cos-factor-impact--negative">+' . esc_html( number_format_i18n( max( 0, (int) ( $rule['score'] ?? 0 ) ) ) ) . '</span>';
			$html .= '</div>';

			if ( ! empty( $rule['category'] ) ) {
				$html .= '<div class="yoohw-cos-factor-description">' . esc_html( ucwords( str_replace( '_', ' ', sanitize_key( (string) $rule['category'] ) ) ) ) . '</div>';
			}

			$html .= '</li>';
		}

		$html .= '</ul>';

		return $html;
	}

	private static function format_security_event_list( array $events ): string {
		if ( empty( $events ) ) {
			return '—';
		}

		$html = '<ul class="yoohw-cos-factor-list yoohw-cos-factor-list--compact">';

		foreach ( $events as $event ) {
			$label       = sanitize_text_field( (string) ( $event['label'] ?? self::format_event_type_label( (string) ( $event['event_type'] ?? '' ) ) ) );
			$description = wp_kses_post( (string) ( $event['description'] ?? '' ) );
			$metadata    = is_array( $event['metadata'] ?? null ) ? $event['metadata'] : array();
			$details     = array();

			if ( isset( $metadata['score'] ) ) {
				$details[] = sprintf(
					/* translators: %d: score. */
					__( 'Score %d', 'yoohw-customer-intelligence' ),
					(int) $metadata['score']
				);
			}

			if ( isset( $metadata['threshold'] ) ) {
				$details[] = sprintf(
					/* translators: %d: threshold. */
					__( 'Threshold %d', 'yoohw-customer-intelligence' ),
					(int) $metadata['threshold']
				);
			}

			if ( ! empty( $metadata['reasons'] ) && is_array( $metadata['reasons'] ) ) {
				$details[] = implode( ', ', array_slice( array_map( 'sanitize_key', $metadata['reasons'] ), 0, 3 ) );
			}

			$html .= '<li>';
			$html .= '<div class="yoohw-cos-factor-list__row">';
			$html .= '<strong>' . esc_html( $label ) . '</strong>';
			$html .= '<span class="yoohw-cos-badge yoohw-cos-badge--severity-' . esc_attr( sanitize_html_class( (string) ( $event['severity'] ?? 'info' ) ) ) . '">' . esc_html( ucfirst( sanitize_key( (string) ( $event['severity'] ?? 'info' ) ) ) ) . '</span>';
			$html .= '</div>';

			if ( '' !== $description ) {
				$html .= '<div class="yoohw-cos-factor-description">' . $description . '</div>';
			}

			if ( ! empty( $details ) || ! empty( $event['created_at'] ) ) {
				$tail = $details;

				if ( ! empty( $event['created_at'] ) ) {
					$tail[] = self::format_date( (string) $event['created_at'] );
				}

				$html .= '<div class="yoohw-cos-factor-description">' . esc_html( implode( ' · ', $tail ) ) . '</div>';
			}

			$html .= '</li>';
		}

		$html .= '</ul>';

		return $html;
	}

	private static function render_timeline( int $customer_id, array $events ): void {
		$total_events = YoOhw_COS_Events::get_customer_event_count( $customer_id );
		$activity_url = add_query_arg(
			array(
				'page'        => 'yoohw-customer-intelligence-activity',
				'customer_id' => $customer_id,
			),
			admin_url( 'admin.php' )
		);

		echo '<div class="postbox">';
		echo '<div class="postbox-header"><h2 class="hndle">' . esc_html__( 'Timeline', 'yoohw-customer-intelligence' ) . '</h2></div>';
		echo '<div class="inside">';

		if ( empty( $events ) ) {
			YoOhw_COS_Admin_UI::render_empty_state(
				__( 'No activity yet.', 'yoohw-customer-intelligence' ),
				__( 'Orders, notes, tags, segments, and tasks will appear here as activity is recorded.', 'yoohw-customer-intelligence' ),
				array(),
				'compact'
			);
			echo '</div></div>';
			return;
		}

		echo '<p class="yoohw-cos-muted">';
		printf(
			/* translators: 1: number of events currently shown, 2: total number of customer activity events. */
			esc_html__( 'Showing the latest %1$s of %2$s activity events.', 'yoohw-customer-intelligence' ),
			esc_html( number_format_i18n( count( $events ) ) ),
			esc_html( number_format_i18n( $total_events ) )
		);
		echo ' <a href="' . esc_url( $activity_url ) . '">' . esc_html__( 'View all activity', 'yoohw-customer-intelligence' ) . '</a>';
		echo '</p>';

		echo '<ol class="yoohw-cos-timeline">';

		foreach ( $events as $event ) {
			$event_type   = sanitize_key( (string) ( $event['event_type'] ?? '' ) );
			$event_source = sanitize_key( (string) ( $event['event_source'] ?? '' ) );

			echo '<li>';
			echo '<div class="yoohw-cos-timeline__heading">';
			echo '<strong>' . esc_html( self::format_event_type_label( $event_type ) ) . '</strong>';

			if ( 'wc_blacklist_manager' === $event_source && self::is_blacklist_manager_integration_active() ) {
				echo '<span class="yoohw-cos-badge yoohw-cos-badge--blacklist-source">' . esc_html__( 'Blacklist Manager', 'yoohw-customer-intelligence' ) . '</span>';
			} elseif ( 'wc_blacklist_manager_premium' === $event_source && self::is_blacklist_manager_premium_integration_active() ) {
				echo '<span class="yoohw-cos-badge yoohw-cos-badge--blacklist-premium-source">' . esc_html__( 'Blacklist Manager Premium', 'yoohw-customer-intelligence' ) . '</span>';
			}

			echo '</div>';
			echo '<div>' . wp_kses_post( $event['description'] ?? '' ) . '</div>';
			echo '<small>' . esc_html( self::format_date( $event['created_at'] ?? '' ) ) . '</small>';
			echo '</li>';
		}

		echo '</ol>';
		echo '</div>';
		echo '</div>';
	}

	private static function get_customer_name( array $customer ): string {
		if ( ! empty( $customer['display_name'] ) ) {
			return $customer['display_name'];
		}

		$name = trim( ( $customer['first_name'] ?? '' ) . ' ' . ( $customer['last_name'] ?? '' ) );

		return $name ?: __( '(No name)', 'yoohw-customer-intelligence' );
	}

	private static function format_event_type_label( string $event_type ): string {
		$event_type = sanitize_key( $event_type );

		$labels = array(
			'blacklist_blocked'        => __( 'Blacklist blocked', 'yoohw-customer-intelligence' ),
			'blacklist_match_detected' => __( 'Blacklist match', 'yoohw-customer-intelligence' ),
			'blacklist_removed'        => __( 'Blacklist cleared', 'yoohw-customer-intelligence' ),
			'blacklist_suspect'        => __( 'Blacklist suspect', 'yoohw-customer-intelligence' ),
			'bulk_customer_action'     => __( 'Bulk action', 'yoohw-customer-intelligence' ),
			'premium_order_risk_scored' => __( 'Premium order risk scored', 'yoohw-customer-intelligence' ),
			'premium_risk_rule_matched' => __( 'Premium risk rule matched', 'yoohw-customer-intelligence' ),
			'premium_antibot_blocked' => __( 'Premium anti-bot blocked', 'yoohw-customer-intelligence' ),
			'premium_antibot_would_block' => __( 'Premium anti-bot challenge', 'yoohw-customer-intelligence' ),
			'premium_payment_abuse_detected' => __( 'Premium payment abuse', 'yoohw-customer-intelligence' ),
			'premium_device_signal_detected' => __( 'Premium device signal', 'yoohw-customer-intelligence' ),
			'premium_gateway_fraud_signal' => __( 'Premium gateway fraud signal', 'yoohw-customer-intelligence' ),
			'note_added'               => __( 'Note added', 'yoohw-customer-intelligence' ),
			'note_deleted'             => __( 'Note deleted', 'yoohw-customer-intelligence' ),
			'note_updated'             => __( 'Note updated', 'yoohw-customer-intelligence' ),
			'order_synced'             => __( 'Order synced', 'yoohw-customer-intelligence' ),
			'segment_assigned'         => __( 'Segment added', 'yoohw-customer-intelligence' ),
			'segment_removed'          => __( 'Segment removed', 'yoohw-customer-intelligence' ),
			'task_completed'           => __( 'Task completed', 'yoohw-customer-intelligence' ),
			'task_created'             => __( 'Task created', 'yoohw-customer-intelligence' ),
			'tag_assigned'             => __( 'Tag added', 'yoohw-customer-intelligence' ),
			'tag_removed'              => __( 'Tag removed', 'yoohw-customer-intelligence' ),
		);

		if ( isset( $labels[ $event_type ] ) ) {
			return $labels[ $event_type ];
		}

		return '' !== $event_type ? ucwords( str_replace( '_', ' ', $event_type ) ) : __( 'Activity', 'yoohw-customer-intelligence' );
	}

	private static function format_date( ?string $date ): string {
		return YoOhw_COS_DB::format_admin_date( $date, '—' );
	}

	private static function render_term_assignment_box( int $customer_id, array $assigned_terms, array $available_terms, array $args ): void {
		$box_id      = sanitize_html_class( (string) $args['box_id'] );
		$input_id    = sanitize_html_class( 'new-tag-' . $box_id );
		$textarea_id = sanitize_html_class( 'tax-input-' . $box_id );
		$help_id     = sanitize_html_class( $input_id . '-desc' );
		$cloud_id    = sanitize_html_class( $box_id . '-tagcloud' );
		$field_name  = sanitize_key( (string) $args['field_name'] );
		$nojs_name   = sanitize_key( (string) $args['nojs_field_name'] );
		$term_names  = array();

		foreach ( $available_terms as $term ) {
			$name = sanitize_text_field( (string) ( $term['name'] ?? '' ) );

			if ( '' !== $name ) {
				$term_names[] = $name;
			}
		}

		echo '<form class="yoohw-cos-term-box-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $args['assign_action'] ) . '" />';
		echo '<input type="hidden" name="customer_id" value="' . esc_attr( $customer_id ) . '" />';
		wp_nonce_field( (string) $args['assign_nonce'] );

		echo '<div class="tagsdiv yoohw-cos-term-add-form" id="' . esc_attr( $box_id ) . '">';
		echo '<div class="jaxtag">';
		echo '<div class="nojs-tags hide-if-js">';
		echo '<label for="' . esc_attr( $textarea_id ) . '">' . esc_html( $args['add_or_remove_label'] ) . '</label>';
		echo '<p><textarea name="' . esc_attr( $nojs_name ) . '" rows="3" cols="20" class="the-tags" id="' . esc_attr( $textarea_id ) . '" aria-describedby="' . esc_attr( $help_id ) . '"></textarea></p>';
		echo '</div>';
		echo '<div class="ajaxtag hide-if-no-js">';
		echo '<label class="screen-reader-text" for="' . esc_attr( $input_id ) . '">' . esc_html( $args['add_label'] ) . '</label>';
		echo '<input type="text" id="' . esc_attr( $input_id ) . '" name="' . esc_attr( $field_name ) . '" class="newtag form-input-tip" size="16" autocomplete="off" aria-describedby="' . esc_attr( $help_id ) . '" value="" data-yoohw-cos-term-suggest="1" data-yoohw-cos-term-source="' . esc_attr( wp_json_encode( $term_names ) ) . '" />';
		echo '<input type="submit" class="button tagadd" value="' . esc_attr__( 'Add', 'yoohw-customer-intelligence' ) . '" />';
		echo '</div>';
		echo '<p class="howto" id="' . esc_attr( $help_id ) . '">' . esc_html( $args['separate_label'] ) . '</p>';
		echo '</div>';
		echo '<ul class="tagchecklist" role="list">';

		foreach ( $assigned_terms as $term ) {
			self::render_term_checklist_item( $customer_id, $term, $args );
		}

		echo '</ul>';
		echo '</div>';
		echo '</form>';

		if ( empty( $available_terms ) ) {
			return;
		}

		echo '<p class="hide-if-no-js"><button type="button" class="button-link tagcloud-link" id="link-' . esc_attr( $box_id ) . '" aria-expanded="false" data-yoohw-cos-term-cloud-toggle data-yoohw-cos-term-cloud-target="' . esc_attr( $cloud_id ) . '">';
		echo esc_html( $args['choose_label'] );
		echo '</button></p>';
		echo '<div class="the-tagcloud yoohw-cos-term-cloud" id="' . esc_attr( $cloud_id ) . '" hidden>';
		echo '<ul>';

		foreach ( $term_names as $name ) {
			echo '<li><a href="#" data-yoohw-cos-term-name="' . esc_attr( $name ) . '" data-yoohw-cos-term-input="' . esc_attr( $input_id ) . '">' . esc_html( $name ) . '</a></li>';
		}

		echo '</ul>';
		echo '</div>';
	}

	private static function render_term_checklist_item( int $customer_id, array $term, array $args ): void {
		$term_id = absint( $term['id'] ?? 0 );
		$name    = sanitize_text_field( (string) ( $term['name'] ?? '' ) );

		if ( $term_id <= 0 || '' === $name ) {
			return;
		}

		$remove_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'                  => $args['remove_action'],
					'customer_id'             => $customer_id,
					$args['remove_id_param']  => $term_id,
				),
				admin_url( 'admin-post.php' )
			),
			(string) $args['remove_nonce']
		);

		echo '<li>';
		$chip_classes = 'yoohw-cos-chip yoohw-cos-term-chip';
		$chip_style   = '';

		if ( ! empty( $args['chip_class'] ) ) {
			$chip_classes .= ' ' . sanitize_html_class( (string) $args['chip_class'] );
		}

		if ( array_key_exists( 'color', $term ) ) {
			$color = ! empty( $term['color'] ) ? sanitize_hex_color( (string) $term['color'] ) : '#f0f0f1';

			if ( $color ) {
				$chip_style = YoOhw_COS_Admin_UI::get_readable_chip_style( $color );
			}
		}

		echo '<span class="' . esc_attr( $chip_classes ) . '"' . ( $chip_style ? ' style="' . esc_attr( $chip_style ) . '"' : '' ) . '>';
		echo '<a class="ntdelbutton" href="' . esc_url( $remove_url ) . '" role="button" data-yoohw-cos-confirm="' . esc_attr( $args['remove_confirm'] ) . '">';
		echo '<span class="remove-tag-icon" aria-hidden="true"></span>';
		echo '<span class="screen-reader-text">';
		printf(
			/* translators: %s: term name. */
			esc_html__( 'Remove term: %s', 'yoohw-customer-intelligence' ),
			esc_html( $name )
		);
		echo '</span>';
		echo '</a>';
		echo esc_html( $name );
		echo '</span>';
		echo '</li>';
	}

	private static function render_customer_tags( int $customer_id, array $tags ): void {
		$all_tags         = YoOhw_COS_Tags::get_all_tags();
		$assigned_tag_ids = array_map( 'absint', wp_list_pluck( $tags, 'id' ) );
		$available_tags   = array_values(
			array_filter(
				$all_tags,
				static function ( array $tag ) use ( $assigned_tag_ids ): bool {
					return ! in_array( absint( $tag['id'] ?? 0 ), $assigned_tag_ids, true );
				}
			)
		);

		echo '<div class="postbox" id="yoohw-cos-add-tag">';
		echo '<div class="postbox-header"><h2 class="hndle">' . esc_html__( 'Tags', 'yoohw-customer-intelligence' ) . '</h2></div>';
		echo '<div class="inside">';

		if ( ! empty( $_GET['tag_added'] ) ) {
			echo '<div class="notice notice-success inline"><p>';
			echo esc_html__( 'Tag assigned successfully.', 'yoohw-customer-intelligence' );
			echo '</p></div>';
		}

		if ( ! empty( $_GET['tag_removed'] ) ) {
			echo '<div class="notice notice-warning inline"><p>';
			echo esc_html__( 'Tag removed successfully.', 'yoohw-customer-intelligence' );
			echo '</p></div>';
		}

		self::render_term_assignment_box(
			$customer_id,
			$tags,
			$available_tags,
			array(
				'add_label'           => __( 'Add tag', 'yoohw-customer-intelligence' ),
				'add_or_remove_label' => __( 'Add or remove tags', 'yoohw-customer-intelligence' ),
				'assign_action'       => 'yoohw_cos_assign_customer_tag',
				'assign_nonce'        => 'yoohw_cos_assign_customer_tag',
				'box_id'              => 'yoohw-cos-profile-tags',
				'chip_class'          => 'yoohw-cos-term-chip--tag',
				'choose_label'        => __( 'Choose from existing tags', 'yoohw-customer-intelligence' ),
				'field_name'          => 'tag_name',
				'nojs_field_name'     => 'tag_name_nojs',
				'remove_action'       => 'yoohw_cos_remove_customer_tag',
				'remove_confirm'      => __( 'Remove this tag?', 'yoohw-customer-intelligence' ),
				'remove_id_param'     => 'tag_id',
				'remove_nonce'        => 'yoohw_cos_remove_customer_tag',
				'separate_label'      => __( 'Separate tags with commas', 'yoohw-customer-intelligence' ),
			)
		);

		echo '</div>';
		echo '</div>';
	}

	private static function render_customer_segments( int $customer_id, array $segments ): void {
		$all_segments         = YoOhw_COS_Segments::get_all_segments();
		$assigned_segment_ids = array_map( 'absint', wp_list_pluck( $segments, 'id' ) );
		$available_segments   = array_values(
			array_filter(
				$all_segments,
				static function ( array $segment ) use ( $assigned_segment_ids ): bool {
					return ! in_array( absint( $segment['id'] ?? 0 ), $assigned_segment_ids, true );
				}
			)
		);

		echo '<div class="postbox" id="yoohw-cos-add-segment">';
		echo '<div class="postbox-header"><h2 class="hndle">' . esc_html__( 'Segments', 'yoohw-customer-intelligence' ) . '</h2></div>';
		echo '<div class="inside">';

		if ( ! empty( $_GET['segment_added'] ) ) {
			echo '<div class="notice notice-success inline"><p>';
			echo esc_html__( 'Segment assigned successfully.', 'yoohw-customer-intelligence' );
			echo '</p></div>';
		}

		if ( ! empty( $_GET['segment_removed'] ) ) {
			echo '<div class="notice notice-warning inline"><p>';
			echo esc_html__( 'Segment removed successfully.', 'yoohw-customer-intelligence' );
			echo '</p></div>';
		}

		self::render_term_assignment_box(
			$customer_id,
			$segments,
			$available_segments,
			array(
				'add_label'           => __( 'Add segment', 'yoohw-customer-intelligence' ),
				'add_or_remove_label' => __( 'Add or remove segments', 'yoohw-customer-intelligence' ),
				'assign_action'       => 'yoohw_cos_assign_customer_segment',
				'assign_nonce'        => 'yoohw_cos_assign_customer_segment',
				'box_id'              => 'yoohw-cos-profile-segments',
				'chip_class'          => 'yoohw-cos-chip--segment',
				'choose_label'        => __( 'Choose from existing segments', 'yoohw-customer-intelligence' ),
				'field_name'          => 'segment_name',
				'nojs_field_name'     => 'segment_name_nojs',
				'remove_action'       => 'yoohw_cos_remove_customer_segment',
				'remove_confirm'      => __( 'Remove this segment?', 'yoohw-customer-intelligence' ),
				'remove_id_param'     => 'segment_id',
				'remove_nonce'        => 'yoohw_cos_remove_customer_segment',
				'separate_label'      => __( 'Separate segments with commas', 'yoohw-customer-intelligence' ),
			)
		);

		echo '</div>';
		echo '</div>';
	}

	private static function render_customer_tasks( int $customer_id, array $tasks ): void {
		$total_open = YoOhw_COS_Tasks::get_customer_task_count( $customer_id, 'open' );
		$tasks_url  = add_query_arg(
			array(
				'page'        => 'yoohw-customer-intelligence-tasks',
				'task_view'   => 'open',
				'customer_id' => $customer_id,
			),
			admin_url( 'admin.php' )
		);
		$profile_url = self::get_profile_url( $customer_id, 'yoohw-cos-add-task' );

		echo '<div class="postbox" id="yoohw-cos-add-task">';
		echo '<div class="postbox-header"><h2 class="hndle">' . esc_html__( 'Tasks', 'yoohw-customer-intelligence' ) . '</h2></div>';
		echo '<div class="inside">';

		if ( ! empty( $_GET['yoohw_task_created'] ) ) {
			echo '<div class="notice notice-success inline"><p>';
			echo esc_html__( 'Task created successfully.', 'yoohw-customer-intelligence' );
			echo '</p></div>';
		}

		if ( ! empty( $_GET['yoohw_task_completed'] ) ) {
			echo '<div class="notice notice-success inline"><p>';
			echo esc_html__( 'Task marked complete.', 'yoohw-customer-intelligence' );
			echo '</p></div>';
		}

		if ( ! empty( $_GET['yoohw_task_reopened'] ) ) {
			echo '<div class="notice notice-success inline"><p>';
			echo esc_html__( 'Task reopened.', 'yoohw-customer-intelligence' );
			echo '</p></div>';
		}

		if ( ! empty( $_GET['yoohw_task_error'] ) ) {
			echo '<div class="notice notice-error inline"><p>';
			echo esc_html__( 'Task action could not be completed.', 'yoohw-customer-intelligence' );
			echo '</p></div>';
		}

		echo '<form class="yoohw-cos-profile-task-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="yoohw_cos_create_task" />';
		echo '<input type="hidden" name="customer_id" value="' . esc_attr( $customer_id ) . '" />';
		echo '<input type="hidden" name="_redirect" value="' . esc_attr( $profile_url ) . '" />';
		wp_nonce_field( 'yoohw_cos_create_task' );

		echo '<p>';
		echo '<label for="yoohw_cos_profile_task_title"><strong>' . esc_html__( 'Add task', 'yoohw-customer-intelligence' ) . '</strong></label>';
		echo '</p>';

		echo '<p>';
		echo '<input type="text" id="yoohw_cos_profile_task_title" name="task_title" class="large-text" placeholder="' . esc_attr__( 'Follow up with this customer...', 'yoohw-customer-intelligence' ) . '" required />';
		echo '</p>';

		echo '<div class="yoohw-cos-profile-task-fields">';
		echo '<div class="yoohw-cos-profile-task-field">';
		echo '<label for="yoohw_cos_profile_task_priority">' . esc_html__( 'Priority', 'yoohw-customer-intelligence' ) . '</label>';
		echo '<select id="yoohw_cos_profile_task_priority" name="task_priority">';

		foreach ( YoOhw_COS_Tasks::get_priorities() as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( 'normal', $value, false ) . '>' . esc_html( $label ) . '</option>';
		}

		echo '</select>';
		echo '</div>';

		echo '<div class="yoohw-cos-profile-task-field">';
		echo '<label for="yoohw_cos_profile_task_due_date">' . esc_html__( 'Due date', 'yoohw-customer-intelligence' ) . '</label>';
		echo '<input type="datetime-local" id="yoohw_cos_profile_task_due_date" name="task_due_date" />';
		echo '</div>';

		echo '<div class="yoohw-cos-profile-task-field">';
		echo '<label for="yoohw_cos_profile_task_assignee">' . esc_html__( 'Assigned to', 'yoohw-customer-intelligence' ) . '</label>';
		self::render_task_assignee_select( get_current_user_id(), 'yoohw_cos_profile_task_assignee' );
		echo '</div>';
		echo '</div>';

		echo '<p>';
		echo '<textarea name="task_description" rows="2" class="large-text" placeholder="' . esc_attr__( 'Optional task notes...', 'yoohw-customer-intelligence' ) . '"></textarea>';
		echo '</p>';

		submit_button(
			__( 'Add task', 'yoohw-customer-intelligence' ),
			'secondary',
			'submit',
			false
		);

		echo '</form>';
		echo '<hr />';

		if ( empty( $tasks ) ) {
			YoOhw_COS_Admin_UI::render_empty_state(
				__( 'No open tasks.', 'yoohw-customer-intelligence' ),
				__( 'Add a task above to schedule a follow-up.', 'yoohw-customer-intelligence' ),
				array(),
				'compact'
			);
		} else {
			echo '<p class="yoohw-cos-muted">';
			printf(
				/* translators: 1: number of tasks currently shown, 2: total number of open tasks. */
				esc_html__( 'Showing %1$s of %2$s open tasks.', 'yoohw-customer-intelligence' ),
				esc_html( number_format_i18n( count( $tasks ) ) ),
				esc_html( number_format_i18n( $total_open ) )
			);
			echo ' <a href="' . esc_url( $tasks_url ) . '">' . esc_html__( 'View all tasks', 'yoohw-customer-intelligence' ) . '</a>';
			echo '</p>';

			echo '<div class="yoohw-cos-profile-task-list">';

			foreach ( $tasks as $task ) {
				self::render_profile_task_item( $customer_id, $task );
			}

			echo '</div>';
		}

		echo '</div>';
		echo '</div>';
	}

	private static function render_profile_task_item( int $customer_id, array $task ): void {
		$task_id     = absint( $task['id'] ?? 0 );
		$status      = YoOhw_COS_Tasks::normalize_status( (string) ( $task['status'] ?? YoOhw_COS_Tasks::STATUS_OPEN ) );
		$is_complete = YoOhw_COS_Tasks::STATUS_COMPLETED === $status;
		$action      = $is_complete ? 'reopen' : 'complete';
		$nonce       = 'yoohw_cos_' . $action . '_task';
		$action_url  = wp_nonce_url(
			add_query_arg(
				array(
					'action'    => 'yoohw_cos_' . $action . '_task',
					'task_id'   => $task_id,
					'_redirect' => rawurlencode( self::get_profile_url( $customer_id, 'yoohw-cos-add-task' ) ),
				),
				admin_url( 'admin-post.php' )
			),
			$nonce
		);

		$due_date   = YoOhw_COS_DB::format_admin_date( $task['due_date'] ?? '', '&mdash;' );
		$is_overdue = ! $is_complete
			&& YoOhw_COS_DB::date_timestamp( $task['due_date'] ?? '' ) > 0
			&& YoOhw_COS_DB::date_timestamp( $task['due_date'] ?? '' ) < current_time( 'timestamp' );

		echo '<div class="yoohw-cos-profile-task-item">';
		echo '<div class="yoohw-cos-profile-task-item__main">';
		echo '<strong>' . esc_html( $task['title'] ?? '' ) . '</strong>';
		echo '<div class="yoohw-cos-profile-task-meta">';
		echo '<span class="yoohw-cos-badge yoohw-cos-badge--task-priority-' . esc_attr( sanitize_html_class( YoOhw_COS_Tasks::normalize_priority( (string) ( $task['priority'] ?? 'normal' ) ) ) ) . '">';
		echo esc_html( YoOhw_COS_Tasks::get_priorities()[ YoOhw_COS_Tasks::normalize_priority( (string) ( $task['priority'] ?? 'normal' ) ) ] ?? __( 'Normal', 'yoohw-customer-intelligence' ) );
		echo '</span>';

		if ( '&mdash;' !== $due_date ) {
			echo '<span class="' . esc_attr( $is_overdue ? 'yoohw-cos-task-overdue' : 'yoohw-cos-muted' ) . '">' . wp_kses_post( $due_date ) . '</span>';
		}

		if ( ! empty( $task['assignee_name'] ) ) {
			echo '<span class="yoohw-cos-muted">' . esc_html( $task['assignee_name'] ) . '</span>';
		}

		echo '</div>';
		echo '</div>';
		echo '<a class="button button-small" href="' . esc_url( $action_url ) . '">' . esc_html( $is_complete ? __( 'Reopen', 'yoohw-customer-intelligence' ) : __( 'Complete', 'yoohw-customer-intelligence' ) ) . '</a>';
		echo '</div>';
	}

	private static function render_task_assignee_select( int $selected_user_id, string $field_id = 'yoohw_cos_task_assignee' ): void {
		$users = YoOhw_COS_Tasks::get_assignable_users();

		echo '<select id="' . esc_attr( $field_id ) . '" name="assigned_user_id" class="yoohw-cos-task-assignee-search" data-placeholder="' . esc_attr__( 'Search assignee', 'yoohw-customer-intelligence' ) . '">';
		echo '<option value="0">' . esc_html__( 'Unassigned', 'yoohw-customer-intelligence' ) . '</option>';

		foreach ( $users as $user ) {
			echo '<option value="' . esc_attr( absint( $user->ID ) ) . '" ' . selected( $selected_user_id, absint( $user->ID ), false ) . '>';
			echo esc_html( $user->display_name );
			echo '</option>';
		}

		echo '</select>';
	}

	private static function render_customer_notes( int $customer_id, array $notes ): void {
		$total_notes = YoOhw_COS_Notes::get_customer_note_count( $customer_id );

		echo '<div class="postbox" id="yoohw-cos-add-note">';
		echo '<div class="postbox-header"><h2 class="hndle">' . esc_html__( 'Internal notes', 'yoohw-customer-intelligence' ) . '</h2></div>';
		echo '<div class="inside">';

		if ( ! empty( $_GET['note_added'] ) ) {
			echo '<div class="notice notice-success inline"><p>';
			echo esc_html__( 'Note added successfully.', 'yoohw-customer-intelligence' );
			echo '</p></div>';
		}

		if ( ! empty( $_GET['note_updated'] ) ) {
			echo '<div class="notice notice-success inline"><p>';
			echo esc_html__( 'Note updated successfully.', 'yoohw-customer-intelligence' );
			echo '</p></div>';
		}

		if ( ! empty( $_GET['note_deleted'] ) ) {
			echo '<div class="notice notice-warning inline"><p>';
			echo esc_html__( 'Note deleted successfully.', 'yoohw-customer-intelligence' );
			echo '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="yoohw_cos_add_customer_note" />';
		echo '<input type="hidden" name="customer_id" value="' . esc_attr( $customer_id ) . '" />';
		wp_nonce_field( 'yoohw_cos_add_customer_note' );

		echo '<p>';
		echo '<textarea name="customer_note" rows="4" class="large-text" placeholder="' . esc_attr__( 'Add an internal note about this customer...', 'yoohw-customer-intelligence' ) . '"></textarea>';
		echo '</p>';

		submit_button(
			__( 'Add note', 'yoohw-customer-intelligence' ),
			'secondary',
			'submit',
			false
		);

		echo '</form>';

		echo '<hr />';

		if ( empty( $notes ) ) {
			YoOhw_COS_Admin_UI::render_empty_state(
				__( 'No internal notes.', 'yoohw-customer-intelligence' ),
				__( 'Add the first note above.', 'yoohw-customer-intelligence' ),
				array(),
				'compact'
			);
		} else {
			echo '<p class="yoohw-cos-muted">';
			printf(
				/* translators: 1: number of notes currently shown, 2: total number of customer notes. */
				esc_html__( 'Showing the latest %1$s of %2$s internal notes.', 'yoohw-customer-intelligence' ),
				esc_html( number_format_i18n( count( $notes ) ) ),
				esc_html( number_format_i18n( $total_notes ) )
			);
			echo '</p>';

			foreach ( $notes as $note ) {
				$note_id = absint( $note['id'] );
				$author  = ! empty( $note['author_id'] ) ? get_userdata( absint( $note['author_id'] ) ) : null;

				$delete_url = wp_nonce_url(
					add_query_arg(
						array(
							'action'      => 'yoohw_cos_delete_customer_note',
							'customer_id' => $customer_id,
							'note_id'     => $note_id,
						),
						admin_url( 'admin-post.php' )
					),
					'yoohw_cos_delete_customer_note'
				);

				echo '<div class="yoohw-cos-note-card">';

				echo '<div class="yoohw-cos-note-card__header">';

				echo '<div class="yoohw-cos-note-card__meta">';

				if ( $author ) {
					echo '<strong>' . esc_html( $author->display_name ) . '</strong> · ';
				}

				echo esc_html( self::format_date( $note['created_at'] ?? '' ) );

				if ( ! empty( $note['updated_at'] ) && $note['updated_at'] !== $note['created_at'] ) {
					echo ' · ' . esc_html__( 'Updated', 'yoohw-customer-intelligence' );
				}

				echo '</div>';

				echo '<div>';
				echo '<a href="#" class="button button-small yoohw-cos-edit-note-toggle" data-note-id="' . esc_attr( $note_id ) . '">';
				echo esc_html__( 'Edit', 'yoohw-customer-intelligence' );
				echo '</a> ';

				echo '<a href="' . esc_url( $delete_url ) . '" class="button button-small button-link-delete" data-yoohw-cos-confirm="' . esc_attr__( 'Delete this note?', 'yoohw-customer-intelligence' ) . '">';
				echo esc_html__( 'Delete', 'yoohw-customer-intelligence' );
				echo '</a>';
				echo '</div>';

				echo '</div>';

				echo '<div class="yoohw-cos-note-content" id="yoohw-cos-note-content-' . esc_attr( $note_id ) . '">';
				echo wp_kses_post( wpautop( $note['note_content'] ?? '' ) );
				echo '</div>';

				echo '<div class="yoohw-cos-note-edit" id="yoohw-cos-note-edit-' . esc_attr( $note_id ) . '">';

				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				echo '<input type="hidden" name="action" value="yoohw_cos_update_customer_note" />';
				echo '<input type="hidden" name="customer_id" value="' . esc_attr( $customer_id ) . '" />';
				echo '<input type="hidden" name="note_id" value="' . esc_attr( $note_id ) . '" />';
				wp_nonce_field( 'yoohw_cos_update_customer_note' );

				echo '<textarea name="customer_note" rows="4" class="large-text yoohw-cos-textarea-spaced">' . esc_textarea( $note['note_content'] ?? '' ) . '</textarea>';

				submit_button(
					__( 'Save note', 'yoohw-customer-intelligence' ),
					'primary small',
					'submit',
					false
				);

				echo ' <a href="#" class="button button-small yoohw-cos-cancel-note-edit" data-note-id="' . esc_attr( $note_id ) . '">';
				echo esc_html__( 'Cancel', 'yoohw-customer-intelligence' );
				echo '</a>';

				echo '</form>';
				echo '</div>';

				echo '</div>';

			}
		}

		echo '</div>';
		echo '</div>';
	}

	private static function format_status_label( string $status ): string {
		$labels = array(
			'new'      => __( 'New', 'yoohw-customer-intelligence' ),
			'active'   => __( 'Active', 'yoohw-customer-intelligence' ),
			'at_risk'  => __( 'At risk', 'yoohw-customer-intelligence' ),
			'inactive' => __( 'Inactive', 'yoohw-customer-intelligence' ),
			'vip'      => __( 'VIP', 'yoohw-customer-intelligence' ),
		);

		return $labels[ $status ] ?? ucfirst( str_replace( '_', ' ', $status ) );
	}

	private static function render_status_badge( string $status ): string {
		$labels = array(
			'new'      => __( 'New', 'yoohw-customer-intelligence' ),
			'active'   => __( 'Active', 'yoohw-customer-intelligence' ),
			'at_risk'  => __( 'At risk', 'yoohw-customer-intelligence' ),
			'inactive' => __( 'Inactive', 'yoohw-customer-intelligence' ),
			'vip'      => __( 'VIP', 'yoohw-customer-intelligence' ),
		);

		$label = $labels[ $status ] ?? ucfirst( str_replace( '_', ' ', $status ) );

		return '<span class="yoohw-cos-badge yoohw-cos-badge--status-' . esc_attr( sanitize_html_class( $status ) ) . '">' . esc_html( $label ) . '</span>';
	}

	private static function render_loyalty_level_badge( array $customer ): string {
		if ( ! self::is_loyalty_integration_active() ) {
			return '';
		}

		$loyalty_data = self::get_loyalty_display_data( $customer );
		$level        = sanitize_key( (string) ( $loyalty_data['loyalty_level'] ?? '' ) );

		if ( '' === $level ) {
			return '';
		}

		$style      = self::get_loyalty_level_badge_style( $level );
		$style_attr = '' !== $style ? ' style="' . esc_attr( $style ) . '"' : '';

		return '<span class="yoohw-cos-badge yoohw-cos-badge--loyalty-level yoohw-cos-badge--loyalty-level-' . esc_attr( sanitize_html_class( $level ) ) . '"' . $style_attr . '>' . esc_html( self::format_loyalty_level_label( $level ) ) . '</span>';
	}

	private static function get_loyalty_level_badge_style( string $level ): string {
		if (
			class_exists( 'YoOhw_COS_Loyalty_Integration' )
			&& is_callable( array( 'YoOhw_COS_Loyalty_Integration', 'is_loyalty_plugin_active' ) )
			&& YoOhw_COS_Loyalty_Integration::is_loyalty_plugin_active()
			&& is_callable( array( 'YoOhw_COS_Loyalty_Integration', 'get_loyalty_level_badge_style' ) )
		) {
			return YoOhw_COS_Loyalty_Integration::get_loyalty_level_badge_style( $level );
		}

		return '';
	}

	private static function get_loyalty_display_data( array $customer ): array {
		if ( ! self::is_loyalty_integration_active() ) {
			return array(
				'loyalty_level'    => '',
				'available_points' => 0,
				'earned_points'    => 0,
			);
		}

		$user_id = absint( $customer['wp_user_id'] ?? 0 );

		if (
			$user_id <= 0
			|| ! class_exists( 'YoOhw_COS_Loyalty_Integration' )
			|| ! is_callable( array( 'YoOhw_COS_Loyalty_Integration', 'get_user_loyalty_customer_data' ) )
		) {
			return $customer;
		}

		$loyalty_data = YoOhw_COS_Loyalty_Integration::get_user_loyalty_customer_data( $user_id );

		return array_merge(
			$customer,
			array(
				'loyalty_level'    => sanitize_key( (string) ( $loyalty_data['loyalty_level'] ?? $customer['loyalty_level'] ?? '' ) ),
				'available_points' => (int) ( $loyalty_data['available_points'] ?? $customer['available_points'] ?? 0 ),
				'earned_points'    => (int) ( $loyalty_data['earned_points'] ?? $customer['earned_points'] ?? 0 ),
			)
		);
	}

	private static function is_loyalty_integration_active(): bool {
		return class_exists( 'YoOhw_COS_Loyalty_Integration' )
			&& is_callable( array( 'YoOhw_COS_Loyalty_Integration', 'is_loyalty_plugin_active' ) )
			&& YoOhw_COS_Loyalty_Integration::is_loyalty_plugin_active();
	}

	private static function is_blacklist_manager_integration_active(): bool {
		return class_exists( 'YoOhw_COS_Blacklist_Manager_Integration' )
			&& is_callable( array( 'YoOhw_COS_Blacklist_Manager_Integration', 'is_active' ) )
			&& YoOhw_COS_Blacklist_Manager_Integration::is_active();
	}

	private static function is_blacklist_manager_premium_integration_active(): bool {
		return self::is_blacklist_manager_integration_active()
			&& class_exists( 'YoOhw_COS_Blacklist_Manager_Premium_Integration' )
			&& is_callable( array( 'YoOhw_COS_Blacklist_Manager_Premium_Integration', 'is_active' ) )
			&& YoOhw_COS_Blacklist_Manager_Premium_Integration::is_active();
	}

	private static function format_loyalty_level_label( string $level ): string {
		$level = sanitize_key( $level );

		if ( class_exists( 'YoOhw_COS_Loyalty_Integration' ) && is_callable( array( 'YoOhw_COS_Loyalty_Integration', 'format_role_label' ) ) ) {
			return YoOhw_COS_Loyalty_Integration::format_role_label( $level );
		}

		if ( '' === $level || 'none' === $level ) {
			return __( 'No loyalty level', 'yoohw-customer-intelligence' );
		}

		if ( function_exists( 'wp_roles' ) ) {
			$wp_roles = wp_roles();

			if ( isset( $wp_roles->roles[ $level ]['name'] ) ) {
				return translate_user_role( $wp_roles->roles[ $level ]['name'] );
			}
		}

		return ucwords( str_replace( array( '_', '-' ), ' ', $level ) );
	}

	private static function render_risk_badge( float $risk_score ): string {
		$level = YoOhw_COS_Intelligence::calculate_risk_level( $risk_score );

		$labels = array(
			'none'   => __( 'No risk', 'yoohw-customer-intelligence' ),
			'low'    => __( 'Low risk', 'yoohw-customer-intelligence' ),
			'medium' => __( 'Medium risk', 'yoohw-customer-intelligence' ),
			'high'   => __( 'High risk', 'yoohw-customer-intelligence' ),
		);

		return '<span class="yoohw-cos-badge yoohw-cos-badge--risk-' . esc_attr( sanitize_html_class( $level ) ) . '">' . esc_html( $labels[ $level ] ?? $level ) . ' · ' . esc_html( number_format_i18n( $risk_score, 0 ) ) . '</span>';
	}

	private static function render_blacklist_status_badge( array $customer ): string {
		$customer_id = absint( $customer['id'] ?? 0 );

		if (
			$customer_id <= 0
			|| ! self::is_blacklist_manager_integration_active()
			|| ! class_exists( 'YoOhw_COS_Blacklist_Manager_Integration' )
			|| ! is_callable( array( 'YoOhw_COS_Blacklist_Manager_Integration', 'get_customer_blacklist_status' ) )
		) {
			return '';
		}

		$status = YoOhw_COS_Blacklist_Manager_Integration::get_customer_blacklist_status( $customer_id );

		if ( empty( $status['status'] ) || empty( $status['label'] ) ) {
			return '';
		}

		return '<span class="yoohw-cos-badge yoohw-cos-badge--blacklist-' . esc_attr( sanitize_html_class( (string) $status['status'] ) ) . '">' . esc_html( (string) $status['label'] ) . '</span>';
	}

	private static function render_risk_panel( array $customer, array $risk_factors ): void {
		$risk_score = (float) ( $customer['risk_score'] ?? 0 );

		echo '<div class="postbox">';
		echo '<div class="postbox-header"><h2 class="hndle">' . esc_html__( 'Risk score', 'yoohw-customer-intelligence' ) . '</h2></div>';
		echo '<div class="inside">';

		echo '<p>';
		echo wp_kses_post( self::render_risk_badge( $risk_score ) );
		echo '</p>';

		echo '<ul class="yoohw-cos-factor-list">';

		foreach ( $risk_factors as $factor ) {
			$impact = (float) ( $factor['impact'] ?? 0 );

			echo '<li>';
			echo '<div class="yoohw-cos-factor-list__row">';

			echo '<strong>' . esc_html( $factor['label'] ?? '' ) . '</strong>';

			if ( $impact > 0 ) {
				echo '<span class="yoohw-cos-factor-impact yoohw-cos-factor-impact--negative">+';
				echo esc_html( number_format_i18n( $impact, 0 ) );
				echo '</span>';
			} else {
				echo '<span class="yoohw-cos-factor-impact yoohw-cos-factor-impact--positive">0</span>';
			}

			echo '</div>';

			if ( ! empty( $factor['description'] ) ) {
				echo '<div class="yoohw-cos-factor-description">';
				echo esc_html( $factor['description'] );
				echo '</div>';
			}

			echo '</li>';
		}

		echo '</ul>';

		echo '</div>';
		echo '</div>';
	}

	private static function render_trust_badge( float $trust_score ): string {
		if ( $trust_score >= 80 ) {
			$level = 'high';
			$label = __( 'High trust', 'yoohw-customer-intelligence' );
		} elseif ( $trust_score >= 60 ) {
			$level = 'medium';
			$label = __( 'Medium trust', 'yoohw-customer-intelligence' );
		} elseif ( $trust_score >= 40 ) {
			$level = 'low';
			$label = __( 'Low trust', 'yoohw-customer-intelligence' );
		} else {
			$level = 'limited';
			$label = __( 'Limited trust', 'yoohw-customer-intelligence' );
		}

		return '<span class="yoohw-cos-badge yoohw-cos-badge--trust-' . esc_attr( sanitize_html_class( $level ) ) . '">' . esc_html( $label ) . ' · ' . esc_html( number_format_i18n( $trust_score, 0 ) ) . '</span>';
	}

	private static function render_trust_panel( array $customer, array $trust_factors ): void {
		$trust_score = (float) ( $customer['trust_score'] ?? 0 );

		echo '<div class="postbox">';
		echo '<div class="postbox-header"><h2 class="hndle">' . esc_html__( 'Trust score', 'yoohw-customer-intelligence' ) . '</h2></div>';
		echo '<div class="inside">';

		echo '<p>';
		echo wp_kses_post( self::render_trust_badge( $trust_score ) );
		echo '</p>';

		echo '<ul class="yoohw-cos-factor-list">';

		foreach ( $trust_factors as $factor ) {
			$impact = (float) ( $factor['impact'] ?? 0 );

			echo '<li>';
			echo '<div class="yoohw-cos-factor-list__row">';

			echo '<strong>' . esc_html( $factor['label'] ?? '' ) . '</strong>';

			if ( $impact > 0 ) {
				echo '<span class="yoohw-cos-factor-impact yoohw-cos-factor-impact--positive">+';
				echo esc_html( number_format_i18n( $impact, 0 ) );
				echo '</span>';
			} else {
				echo '<span class="yoohw-cos-factor-impact">0</span>';
			}

			echo '</div>';

			if ( ! empty( $factor['description'] ) ) {
				echo '<div class="yoohw-cos-factor-description">';
				echo esc_html( $factor['description'] );
				echo '</div>';
			}

			echo '</li>';
		}

		echo '</ul>';

		echo '</div>';
		echo '</div>';
	}

	private static function render_order_link( int $order_id ): string {
		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return '—';
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return '#' . absint( $order_id );
		}

		return '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a>';
	}

	private static function render_vip_badge( string $vip_status ): string {
		$label = YoOhw_COS_Intelligence::get_value_tier_label( $vip_status );
		$class = YoOhw_COS_Intelligence::get_value_tier_badge_class( $vip_status );

		return '<span class="yoohw-cos-badge yoohw-cos-badge--value-tier-' . esc_attr( $class ) . '">' . esc_html( $label ) . '</span>';
	}

	private static function format_lifecycle_label( string $stage ): string {
		$labels = array(
			'new'     => __( 'New', 'yoohw-customer-intelligence' ),
			'repeat'  => __( 'Repeat', 'yoohw-customer-intelligence' ),
			'loyal'   => __( 'Loyal', 'yoohw-customer-intelligence' ),
			'vip'     => __( 'VIP', 'yoohw-customer-intelligence' ),
			'dormant' => __( 'Dormant', 'yoohw-customer-intelligence' ),
		);

		return $labels[ $stage ] ?? ucfirst( str_replace( '_', ' ', $stage ) );
	}

	private static function render_lifecycle_badge( string $stage ): string {
		$label = self::format_lifecycle_label( $stage );

		return '<span class="yoohw-cos-badge yoohw-cos-badge--lifecycle-' . esc_attr( sanitize_html_class( $stage ) ) . '">' . esc_html( $label ) . '</span>';
	}

	private static function render_lifecycle_panel( array $customer, array $lifecycle_factors ): void {
		echo '<div class="postbox">';
		echo '<div class="postbox-header"><h2 class="hndle">' . esc_html__( 'Lifecycle', 'yoohw-customer-intelligence' ) . '</h2></div>';
		echo '<div class="inside">';

		echo '<p>';
		echo wp_kses_post( self::render_lifecycle_badge( $customer['lifecycle_stage'] ?? 'new' ) );
		echo '</p>';

		echo '<ul class="yoohw-cos-factor-list">';

		foreach ( $lifecycle_factors as $factor ) {
			echo '<li>';
			echo '<strong>' . esc_html( $factor['label'] ?? '' ) . '</strong>';

			if ( ! empty( $factor['description'] ) ) {
				echo '<div class="yoohw-cos-factor-description">';
				echo esc_html( $factor['description'] );
				echo '</div>';
			}

			echo '</li>';
		}

		echo '</ul>';

		echo '</div>';
		echo '</div>';
	}
}
