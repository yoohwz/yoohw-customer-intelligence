<?php
defined( 'ABSPATH' ) || exit;

final class YoOhw_COS_Loader {

	public static function activate(): void {
		require_once YOOHW_COS_PATH . 'includes/class-yoohw-cos-install.php';
		require_once YOOHW_COS_PATH . 'includes/class-yoohw-cos-email-notifications.php';
		YoOhw_COS_Install::install();
		YoOhw_COS_Email_Notifications::activate();
	}

	public static function deactivate(): void {
		require_once YOOHW_COS_PATH . 'includes/class-yoohw-cos-email-notifications.php';
		YoOhw_COS_Email_Notifications::deactivate();
	}

	public static function init(): void {
		self::includes();

		YoOhw_COS_Install::maybe_update();

		if ( ! self::is_woocommerce_active() ) {
			if ( is_admin() ) {
				add_action( 'admin_notices', array( __CLASS__, 'render_woocommerce_missing_notice' ) );
			}

			return;
		}

		YoOhw_COS_Events::init();
		YoOhw_COS_Customers::init();
		YoOhw_COS_Tags::init();
		YoOhw_COS_Notes::init();
		YoOhw_COS_Tasks::init();
		YoOhw_COS_Email_Notifications::init();
		YoOhw_COS_Intelligence::init();
		YoOhw_COS_Blacklist_Manager_Integration::init();
		YoOhw_COS_Blacklist_Manager_Premium_Integration::init();
		YoOhw_COS_Segments::init();
		YoOhw_COS_Loyalty_Integration::init();

		if ( is_admin() ) {
			YoOhw_COS_Admin_Menu::init();
			YoOhw_COS_Admin_Tools::init();
			YoOhw_COS_Order_Admin::init();
		}
	}

	private static function includes(): void {
		require_once YOOHW_COS_PATH . 'includes/class-yoohw-cos-install.php';
		require_once YOOHW_COS_PATH . 'includes/class-yoohw-cos-db.php';
		require_once YOOHW_COS_PATH . 'includes/class-yoohw-cos-customer-query.php';
		require_once YOOHW_COS_PATH . 'includes/class-yoohw-cos-events.php';
		require_once YOOHW_COS_PATH . 'includes/class-yoohw-cos-customers.php';
		require_once YOOHW_COS_PATH . 'includes/class-yoohw-cos-tags.php';
		require_once YOOHW_COS_PATH . 'includes/class-yoohw-cos-notes.php';
		require_once YOOHW_COS_PATH . 'includes/class-yoohw-cos-tasks.php';
		require_once YOOHW_COS_PATH . 'includes/class-yoohw-cos-email-notifications.php';
		require_once YOOHW_COS_PATH . 'includes/class-yoohw-cos-intelligence.php';
		require_once YOOHW_COS_PATH . 'includes/class-yoohw-cos-blacklist-manager-integration.php';
		require_once YOOHW_COS_PATH . 'includes/class-yoohw-cos-blacklist-manager-premium-integration.php';
		require_once YOOHW_COS_PATH . 'includes/class-yoohw-cos-segments.php';
		require_once YOOHW_COS_PATH . 'includes/class-yoohw-cos-loyalty-integration.php';

		require_once YOOHW_COS_PATH . 'admin/class-yoohw-cos-admin-ui.php';
		require_once YOOHW_COS_PATH . 'admin/class-yoohw-cos-customers-list.php';
		require_once YOOHW_COS_PATH . 'admin/class-yoohw-cos-customer-exporter.php';
		require_once YOOHW_COS_PATH . 'admin/class-yoohw-cos-customer-profile.php';
		require_once YOOHW_COS_PATH . 'admin/class-yoohw-cos-tags-list.php';
		require_once YOOHW_COS_PATH . 'admin/class-yoohw-cos-segments-list.php';
		require_once YOOHW_COS_PATH . 'admin/class-yoohw-cos-activity-list.php';
		require_once YOOHW_COS_PATH . 'admin/class-yoohw-cos-tasks-list.php';
		require_once YOOHW_COS_PATH . 'admin/class-yoohw-cos-admin-menu.php';
		require_once YOOHW_COS_PATH . 'admin/class-yoohw-cos-admin-tools.php';
		require_once YOOHW_COS_PATH . 'admin/class-yoohw-cos-order-admin.php';
	}

	private static function is_woocommerce_active(): bool {
		return class_exists( 'WooCommerce' ) || function_exists( 'WC' );
	}

	public static function render_woocommerce_missing_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'YoOhw Customer Intelligence for WooCommerce requires WooCommerce to be active.', 'yoohw-customer-intelligence' );
		echo '</p></div>';
	}
}
