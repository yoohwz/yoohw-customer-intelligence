<?php

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WP_UnitTestCase' ) ) {
	return;
}

/**
 * @group yoohw-customer-intelligence
 */
final class YoOhw_COS_Integration_Smoke_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		$this->load_plugin_classes();
		YoOhw_COS_Install::install();
	}

	public function tear_down(): void {
		if ( class_exists( 'YoOhw_COS_Customers' ) ) {
			YoOhw_COS_Customers::reset_data();
		}

		parent::tear_down();
	}

	public function test_install_creates_expected_custom_tables(): void {
		global $wpdb;

		foreach ( YoOhw_COS_Install::expected_table_keys() as $table_key ) {
			$table = YoOhw_COS_DB::table( $table_key );
			$found = $wpdb->get_var(
				$wpdb->prepare(
					'SHOW TABLES LIKE %s',
					$table
				)
			);

			$this->assertSame( $table, $found, "Missing customer table: {$table_key}" );
		}
	}

	public function test_customer_partial_update_keeps_format_mapping_stable(): void {
		$customer_id = YoOhw_COS_Customers::create_customer(
			array(
				'email'          => 'format-check@example.test',
				'display_name'   => 'Format check',
				'total_orders'   => 1,
				'total_spent'    => 25.50,
				'trust_score'    => 50,
				'risk_score'     => 10,
				'customer_status' => 'active',
				'vip_status'     => 'none',
			)
		);

		$this->assertGreaterThan( 0, $customer_id );

		YoOhw_COS_Customers::update_customer(
			$customer_id,
			array(
				'vip_status'  => 'gold',
				'trust_score' => 75.25,
				'risk_score'  => 5,
			)
		);

		$customer = YoOhw_COS_Customers::get_customer( $customer_id );

		$this->assertSame( 'gold', $customer['vip_status'] );
		$this->assertSame( 75.25, (float) $customer['trust_score'] );
		$this->assertSame( 5.0, (float) $customer['risk_score'] );
	}

	public function test_reset_clears_customer_relationship_tables(): void {
		global $wpdb;

		$customer_id = YoOhw_COS_Customers::create_customer(
			array(
				'email'        => 'reset-check@example.test',
				'display_name' => 'Reset check',
			)
		);
		$tag_id      = YoOhw_COS_Tags::create_tag( 'Reset check tag' );
		$segment_id  = YoOhw_COS_Segments::create_segment( 'Reset check segment' );

		YoOhw_COS_Tags::assign_tag( $customer_id, $tag_id, 0, false );
		YoOhw_COS_Segments::assign_customer( $customer_id, $segment_id, 0, false );
		$wpdb->insert(
			YoOhw_COS_DB::tasks_table(),
			array(
				'customer_id' => $customer_id,
				'title'       => 'Reset check follow-up',
				'status'      => 'open',
				'priority'    => 'normal',
				'created_at'  => YoOhw_COS_DB::now(),
				'updated_at'  => YoOhw_COS_DB::now(),
			),
			array(
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
			)
		);

		YoOhw_COS_Customers::reset_data();

		$this->assertSame(
			0,
			(int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i', YoOhw_COS_DB::tasks_table() )
			)
		);
		$this->assertSame(
			0,
			(int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i', YoOhw_COS_DB::customer_tags_table() )
			)
		);
		$this->assertSame(
			0,
			(int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i', YoOhw_COS_DB::customer_segments_table() )
			)
		);
	}

	public function test_archive_hides_customer_from_default_query_and_restore_returns_it(): void {
		$customer_id = YoOhw_COS_Customers::create_customer(
			array(
				'email'          => 'archive-check@example.test',
				'display_name'   => 'Archive check',
				'customer_status' => 'active',
			)
		);

		$this->assertGreaterThan( 0, $customer_id );
		$this->assertTrue( YoOhw_COS_Customers::archive_customer( $customer_id ) );

		$default_query = YoOhw_COS_Customer_Query::query(
			array(
				's'        => 'archive-check@example.test',
				'per_page' => 10,
			)
		);
		$archive_query = YoOhw_COS_Customer_Query::query(
			array(
				's'             => 'archive-check@example.test',
				'customer_view' => 'archived',
				'per_page'      => 10,
			)
		);

		$this->assertSame( 0, (int) $default_query['total_items'] );
		$this->assertSame( 1, (int) $archive_query['total_items'] );

		$this->assertTrue( YoOhw_COS_Customers::restore_customer( $customer_id ) );

		$restored_query = YoOhw_COS_Customer_Query::query(
			array(
				's'        => 'archive-check@example.test',
				'per_page' => 10,
			)
		);

		$this->assertSame( 1, (int) $restored_query['total_items'] );
	}

	public function test_order_sync_uses_woocommerce_crud_when_available(): void {
		if ( ! function_exists( 'wc_create_order' ) ) {
			$this->markTestSkipped( 'WooCommerce test helpers are not available.' );
		}

		$order = wc_create_order();
		$order->set_billing_email( 'sync-check@example.test' );
		$order->set_billing_phone( '555-0100' );
		$order->set_billing_first_name( 'Sync' );
		$order->set_billing_last_name( 'Check' );
		$order->set_total( '19.99' );
		$order->set_status( 'processing' );
		$order->save();

		$customer_id = YoOhw_COS_Customers::sync_from_order( $order );
		$customer    = YoOhw_COS_Customers::get_customer( $customer_id );

		$this->assertGreaterThan( 0, $customer_id );
		$this->assertSame( 'sync-check@example.test', $customer['email'] );
		$this->assertSame( absint( $order->get_id() ), absint( $customer['last_order_id'] ) );
		$this->assertSame(
			$order->get_date_created()->date( 'Y-m-d H:i:s' ),
			$customer['last_activity_date']
		);
	}

	public function test_existing_order_sync_processes_oldest_orders_first(): void {
		if ( ! function_exists( 'wc_create_order' ) || ! class_exists( 'WC_DateTime' ) ) {
			$this->markTestSkipped( 'WooCommerce test helpers are not available.' );
		}

		global $wpdb;

		$email     = 'sync-order-sequence@example.test';
		$old_order = wc_create_order();
		$old_order->set_billing_email( $email );
		$old_order->set_billing_first_name( 'Sync' );
		$old_order->set_billing_last_name( 'Old' );
		$old_order->set_total( '10.00' );
		$old_order->set_status( 'processing' );
		$old_order->set_date_created( new WC_DateTime( '2024-01-01 00:00:00' ) );
		$old_order->save();

		$new_order = wc_create_order();
		$new_order->set_billing_email( $email );
		$new_order->set_billing_first_name( 'Sync' );
		$new_order->set_billing_last_name( 'New' );
		$new_order->set_total( '20.00' );
		$new_order->set_status( 'processing' );
		$new_order->set_date_created( new WC_DateTime( '2024-02-01 00:00:00' ) );
		$new_order->save();

		$result = YoOhw_COS_Customers::sync_existing_orders( 100, 1 );

		$this->assertGreaterThanOrEqual( 2, absint( $result['scanned'] ) );

		$customer_id = YoOhw_COS_Customers::find_customer_id(
			array(
				'email' => $email,
			)
		);
		$customer    = YoOhw_COS_Customers::get_customer( $customer_id );

		$this->assertGreaterThan( 0, $customer_id );
		$this->assertSame( absint( $old_order->get_id() ), absint( $customer['first_order_id'] ) );
		$this->assertSame( absint( $new_order->get_id() ), absint( $customer['last_order_id'] ) );
		$this->assertSame( '2024-02-01 00:00:00', $customer['last_activity_date'] );

		$synced_order_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT object_id
				FROM %i
				WHERE customer_id = %d
					AND event_type = %s
					AND object_type = %s
				ORDER BY id ASC",
				YoOhw_COS_DB::events_table(),
				$customer_id,
				'order_synced',
				'order'
			)
		);

		$this->assertSame(
			array(
				absint( $old_order->get_id() ),
				absint( $new_order->get_id() ),
			),
			array_map( 'absint', $synced_order_ids )
		);
	}

	public function test_inactive_status_takes_priority_over_value_tier(): void {
		$customer = array(
			'total_orders'       => 20,
			'total_spent'        => 5000,
			'last_order_date'    => date_i18n( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( 120 * DAY_IN_SECONDS ) ),
			'last_activity_date' => '',
		);

		$this->assertSame( 'inactive', YoOhw_COS_Intelligence::calculate_customer_status( $customer ) );
		$this->assertSame( 'platinum', YoOhw_COS_Intelligence::calculate_vip_status( $customer ) );
	}

	public function test_overview_summary_and_action_filters_use_existing_customer_data(): void {
		$repeat_id = YoOhw_COS_Customers::create_customer(
			array(
				'email'             => 'repeat-overview@example.test',
				'phone'             => '555-0110',
				'display_name'      => 'Repeat overview',
				'total_orders'      => 3,
				'total_spent'       => 300,
				'customer_status'   => 'active',
				'vip_status'        => 'silver',
				'lifecycle_stage'   => 'repeat',
				'last_activity_date' => YoOhw_COS_DB::now(),
			)
		);
		$missing_id = YoOhw_COS_Customers::create_customer(
			array(
				'email'             => 'missing-overview@example.test',
				'display_name'      => 'Missing overview',
				'total_orders'      => 1,
				'total_spent'       => 50,
				'customer_status'   => 'new',
				'vip_status'        => 'none',
				'lifecycle_stage'   => 'new',
				'last_activity_date' => YoOhw_COS_DB::now(),
			)
		);

		$this->assertGreaterThan( 0, $repeat_id );
		$this->assertGreaterThan( 0, $missing_id );

		$summary = YoOhw_COS_Overview::get_summary();
		$repeat  = YoOhw_COS_Customer_Query::query(
			array(
				'customer_cohort' => 'repeat',
				'per_page'        => 20,
			)
		);
		$missing = YoOhw_COS_Customer_Query::query(
			array(
				'customer_attention' => 'missing_contact',
				'per_page'           => 20,
			)
		);

		$this->assertSame( 2, absint( $summary['total_customers'] ) );
		$this->assertSame( 1, absint( $summary['repeat_customers'] ) );
		$this->assertSame( 50.0, (float) $summary['repeat_rate'] );
		$this->assertSame( 1, absint( $summary['high_value_customers'] ) );
		$this->assertSame( 1, absint( $repeat['total_items'] ) );
		$this->assertSame( 1, absint( $missing['total_items'] ) );
	}

	private function load_plugin_classes(): void {
		$root = dirname( __DIR__, 2 );

		if ( ! defined( 'YOOHW_COS_VERSION' ) ) {
			define( 'YOOHW_COS_VERSION', '1.2.1' );
		}

		if ( ! defined( 'YOOHW_COS_DB_VERSION' ) ) {
			define( 'YOOHW_COS_DB_VERSION', '0.1.10' );
		}

		if ( ! defined( 'YOOHW_COS_PATH' ) ) {
			define( 'YOOHW_COS_PATH', $root . '/' );
		}

		$files = array(
			'includes/class-yoohw-cos-install.php',
			'includes/class-yoohw-cos-db.php',
			'includes/class-yoohw-cos-customer-query.php',
			'includes/class-yoohw-cos-events.php',
			'includes/class-yoohw-cos-customers.php',
			'includes/class-yoohw-cos-tags.php',
			'includes/class-yoohw-cos-notes.php',
			'includes/class-yoohw-cos-tasks.php',
			'includes/class-yoohw-cos-overview.php',
			'includes/class-yoohw-cos-intelligence.php',
			'includes/class-yoohw-cos-segments.php',
		);

		foreach ( $files as $file ) {
			require_once $root . '/' . $file;
		}
	}
}
