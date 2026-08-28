<?php
/**
 * Plugin Name: YoOhw Customer Intelligence for WooCommerce
 * Plugin URI: https://yoohw.com/product/customer-intelligence/
 * Description: Unified customer intelligence and operations platform for WooCommerce.
 * Version: 1.3.0
 * Requires at least: 6.9
 * Tested up to: 7.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.2
 * WC tested up to: 10.8
 * Author: YoOhw Studio
 * Author URI: https://yoohw.com
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: yoohw-customer-intelligence
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'YOOHW_COS_VERSION', '1.3.0' );
define( 'YOOHW_COS_DB_VERSION', '0.2.1' );
define( 'YOOHW_COS_FILE', __FILE__ );
define( 'YOOHW_COS_PATH', plugin_dir_path( __FILE__ ) );
define( 'YOOHW_COS_URL', plugin_dir_url( __FILE__ ) );
define( 'YOOHW_COS_BASENAME', plugin_basename( __FILE__ ) );

add_action(
	'before_woocommerce_init',
	static function(): void {
		if (
			class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class )
			&& method_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class, 'declare_compatibility' )
		) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				__FILE__,
				true
			);
		}
	}
);

require_once YOOHW_COS_PATH . 'includes/class-yoohw-cos-loader.php';

register_activation_hook( __FILE__, array( 'YoOhw_COS_Loader', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'YoOhw_COS_Loader', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'YoOhw_COS_Loader', 'init' ) );
