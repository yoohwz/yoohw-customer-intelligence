<?php
require_once dirname( __DIR__ ) . '/environment.php';
yci_test_environment();

/** @group yoohw-customer-intelligence */
final class YCI_Dependent_Data_Test extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		YoOhw_COS_Reset_Guard::init();
	}

	public function tear_down(): void {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		parent::tear_down();
	}

	public function test_keyset_and_recognized_order_evidence(): void {
		global $wpdb;
		$first = YoOhw_COS_Customers::create_customer( array( 'email' => 'addon-a@example.test' ) );
		$second = YoOhw_COS_Customers::create_customer( array( 'email' => 'addon-b@example.test' ) );
		$this->assertTrue( YoOhw_COS_Customers::archive_customer( $second ) );
		$rows = YoOhw_COS_Customer_Query::scan( $first, 1 );
		$this->assertSame( $second, (int) $rows[0]['id'] );
		$this->assertNotEmpty( $rows[0]['archived_at'] );
		$this->assertArrayHasKey( '_commerce_context', $rows[0] );

		$base = array(
			'customer_id' => $first,
			'order_status' => 'completed',
			'currency' => 'USD',
			'revenue_amount' => '80.000000',
			'counts_as_order' => 1,
			'counts_as_revenue' => 1,
			'policy_version' => YoOhw_COS_Commerce_Metrics_Policy::VERSION,
			'updated_at' => YoOhw_COS_DB::now(),
		);
		$table = YoOhw_COS_DB::order_facts_table();
		$this->assertSame( 1, $wpdb->insert( $table, array( 'order_id' => 800001, 'order_date' => '2025-01-02 00:00:00' ) + $base ) );
		$this->assertSame( 1, $wpdb->insert( $table, array( 'order_id' => 800002, 'order_date' => '2025-01-03 00:00:00', 'counts_as_order' => 0 ) + $base ) );
		$this->assertSame( 1, $wpdb->insert( $table, array( 'order_id' => 800003, 'order_date' => '2025-01-04 00:00:00' ) + $base ) );
		$this->assertSame( array(), YoOhw_COS_Commerce_Aggregates::first_recognized_order( $first, '2025-01-02 00:00:00', '2025-01-03 00:00:00' ) );
		$order = YoOhw_COS_Commerce_Aggregates::first_recognized_order( $first, '2025-01-02 00:00:00', '2025-01-04 00:00:00' );
		$this->assertSame( 800003, (int) $order['order_id'] );
		$this->assertSame( '80.000000', $order['revenue_amount'] );
	}

	public function test_inactive_provider_data_is_erased_before_profile_and_during_reset(): void {
		global $wpdb;
		$suffix = 'yoohw_test_dependent_data';
		$table = $wpdb->prefix . $suffix;
		$this->assertNotFalse( $wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (customer_id BIGINT UNSIGNED NOT NULL, KEY subject (customer_id)) ENGINE=InnoDB', $table ) ) );
		try {
			$this->assertFalse( YoOhw_COS_Dependent_Data::register( array( 'yoohw_cos_tasks' ) ) );
			$this->assertTrue( YoOhw_COS_Dependent_Data::register( array( $suffix ) ) );
			$subject = YoOhw_COS_Customers::create_customer( array( 'email' => 'addon-erasure@example.test' ) );
			$other = YoOhw_COS_Customers::create_customer( array( 'email' => 'addon-retained@example.test' ) );
			$this->assertSame( 1, $wpdb->insert( $table, array( 'customer_id' => $subject ) ) );
			$this->assertSame( 1, $wpdb->insert( $table, array( 'customer_id' => $other ) ) );
			$fail_delete = static function ( string $query ) use ( $table ): string {
				if ( 0 === strpos( $query, 'DELETE FROM `' . $table . '`' ) ) {
					throw new RuntimeException( 'Synthetic dependent delete failure.' );
				}
				return $query;
			};
			add_filter( 'query', $fail_delete );
			try {
				$blocked = YoOhw_COS_Privacy_Erasure::erase( 'addon-erasure@example.test' );
				$this->assertFalse( $blocked['items_removed'] );
				$this->assertFalse( $blocked['done'] );
				$this->assertNotEmpty( YoOhw_COS_Customers::get_customer( $subject ) );
			} finally {
				remove_filter( 'query', $fail_delete );
			}
			$first = YoOhw_COS_Privacy_Erasure::erase( 'addon-erasure@example.test' );
			$this->assertTrue( $first['items_removed'] );
			$this->assertFalse( $first['done'] );
			$this->assertNotEmpty( YoOhw_COS_Customers::get_customer( $subject ) );
			$this->assertSame( array( (string) $other ), array_map( 'strval', $wpdb->get_col( $wpdb->prepare( 'SELECT customer_id FROM %i', $table ) ) ) );
			$fail_truncate = static function ( string $query ) use ( $table ): string {
				if ( 0 === strpos( $query, 'TRUNCATE TABLE `' . $table . '`' ) ) {
					throw new RuntimeException( 'Synthetic dependent Reset failure.' );
				}
				return $query;
			};
			add_filter( 'query', $fail_truncate );
			try {
				YoOhw_COS_Customers::reset_data();
				$this->fail( 'Expected dependent Reset interruption.' );
			} catch ( RuntimeException $exception ) {
				$this->assertSame( 'Synthetic dependent Reset failure.', $exception->getMessage() );
				$this->assertFalse( YoOhw_COS_Reset_Guard::ready() );
			} finally {
				remove_filter( 'query', $fail_truncate );
			}
			$this->assertTrue( YoOhw_COS_Customers::reset_data() );
			$this->assertSame( array(), $wpdb->get_col( $wpdb->prepare( 'SELECT customer_id FROM %i', $table ) ) );
		} finally {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $table ) );
			delete_option( 'yoohw_cos_dependent_customer_tables' );
		}
	}

	public function test_erasure_reads_registration_after_an_older_transaction_snapshot(): void {
		global $wpdb;
		$suffix = 'yoohw_test_dependent_late';
		$table = $wpdb->prefix . $suffix;
		$option = 'yoohw_cos_dependent_customer_tables';
		$this->assertNotFalse( $wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (customer_id BIGINT UNSIGNED NOT NULL, KEY subject (customer_id)) ENGINE=InnoDB', $table ) ) );
		$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		try {
			delete_option( $option );
			$subject = YoOhw_COS_Customers::create_customer( array( 'email' => 'addon-late@example.test' ) );
			$this->assertGreaterThan( 0, $subject );
			$this->assertNotFalse( $wpdb->query( 'START TRANSACTION WITH CONSISTENT SNAPSHOT' ) );
			$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $option ) ) );
			$this->assertSame( 1, $other->insert( $wpdb->options, array( 'option_name' => $option, 'option_value' => serialize( array( $suffix ) ), 'autoload' => 'off' ) ) );
			$this->assertSame( 1, $other->insert( $table, array( 'customer_id' => $subject ) ) );
			$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $option ) ) );
			$result = YoOhw_COS_Privacy_Erasure::erase( 'addon-late@example.test' );
			$this->assertTrue( $result['items_removed'] );
			$this->assertNotEmpty( YoOhw_COS_Customers::get_customer( $subject ) );
			$this->assertSame( '0', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ) );
		} finally {
			$wpdb->query( 'ROLLBACK' );
			$other->close();
			$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $table ) );
			$wpdb->delete( $wpdb->options, array( 'option_name' => $option ) );
			wp_cache_delete( $option, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
	}


	public function test_current_profile_and_suppression_bypass_an_older_snapshot(): void {
		global $wpdb;
		$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$option = 'yoohw_cos_privacy_suppression_secret';
		$previous = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $option ) );
		$secret = $previous ?? str_repeat( 'a', 64 );
		$email = 'addon-suppression-current@example.test';
		$subject = YoOhw_COS_Customers::create_customer( array( 'email' => $email ) );
		// Make the synthetic profile visible to the second connection before the snapshot.
		$wpdb->query( 'COMMIT' );
		$wpdb->query( 'START TRANSACTION WITH CONSISTENT SNAPSHOT' );
		try {
			$this->assertNotEmpty( YoOhw_COS_Customers::get_customer( $subject ) );
			if ( null === $previous ) {
				$this->assertSame( 1, $other->insert( $wpdb->options, array(
					'option_name' => $option, 'option_value' => $secret, 'autoload' => 'off',
				) ) );
			}
			$this->assertSame( 1, $other->insert( YoOhw_COS_DB::table( 'privacy_suppression' ), array(
				'identity_kind' => 'email', 'identity_digest' => hash_hmac( 'sha256', 'email|' . $email, hex2bin( $secret ) ),
				'hash_version' => 1, 'created_at' => YoOhw_COS_DB::now(),
			) ) );
			$this->assertSame( 1, $other->delete( YoOhw_COS_DB::customers_table(), array( 'id' => $subject ) ) );
			$this->assertNotEmpty( YoOhw_COS_Customers::get_customer( $subject ) );
			$this->assertTrue( YoOhw_COS_Reset_Guard::enter() );
			try {
				$this->assertSame( array(), YoOhw_COS_Customers::get_customer( $subject, true ) );
				$this->assertTrue( YoOhw_COS_Privacy_Erasure::is_suppressed( array( 'email' => $email ), true ) );
			} finally {
				YoOhw_COS_Reset_Guard::leave();
			}
		} finally {
			$wpdb->query( 'ROLLBACK' );
			$other->close();
			$wpdb->delete( YoOhw_COS_DB::table( 'privacy_suppression' ), array( 'identity_digest' => hash_hmac( 'sha256', 'email|' . $email, hex2bin( $secret ) ) ) );
			if ( null === $previous ) {
				$wpdb->delete( $wpdb->options, array( 'option_name' => $option ) );
				wp_cache_delete( $option, 'options' );
				wp_cache_delete( 'notoptions', 'options' );
			}
		}
	}

}
