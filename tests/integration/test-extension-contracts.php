<?php
require_once dirname( __DIR__ ) . '/environment.php';
yci_test_environment();
require_once dirname( __DIR__ ) . '/fixtures/example-extension-provider.php';

/** @group yoohw-customer-intelligence */
final class YCI_Extension_Contracts_Test extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		YCI_Example_Extension_Provider::$observed = array();
		delete_option( YoOhw_COS_Reset_Guard::OPTION );
		YoOhw_COS_Reset_Guard::init();
	}

	public function tear_down(): void {
		foreach ( array( 'query', 'facts', 'attention', 'actions' ) as $name ) {
			$property = new ReflectionProperty( YoOhw_COS_Extensions::class, $name );
			$property->setAccessible( true );
			$property->setValue( null, array() );
		}
		parent::tear_down();
	}

	public function test_external_provider_contracts(): void {
		global $wpdb;
		$this->assertSame( 1, YoOhw_COS_Extensions::version() );
		$this->assertSame( YoOhw_COS_Extensions::VERSION, YoOhw_COS_Extensions::version() );
		$today = YoOhw_COS_DB::now();
		$one = YoOhw_COS_Customers::create_customer( array( 'email' => 'extension-one@example.test', 'phone' => '123', 'total_orders' => 1, 'last_activity_date' => $today ) );
		$two = YoOhw_COS_Customers::create_customer( array( 'email' => 'extension-two@example.test', 'phone' => '123', 'total_orders' => 2, 'last_activity_date' => $today ) );
		$three = YoOhw_COS_Customers::create_customer( array( 'email' => 'extension-three@example.test', 'phone' => '123', 'total_orders' => 3, 'last_activity_date' => $today ) );
		$this->assertGreaterThan( 0, $three );
		$options_before = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s ORDER BY option_name', $wpdb->options, $wpdb->esc_like( 'yoohw_cos_' ) . '%' ) );
		$tables_before = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'yoohw_cos_' ) . '%' ) );
		$cron_before = get_option( 'cron' );
		$baseline = YoOhw_COS_Customer_Query::query( array( 's' => 'extension-', 'per_page' => 1 ) );
		$this->assertSame( 3, $baseline['total_items'] );
		YCI_Example_Extension_Provider::register();
		$this->assertFalse( YoOhw_COS_Extensions::register_query( 'example-provider/min-orders', static fn( $value ) => $value, static fn( $value ) => $value ) );
		$this->assertFalse( YoOhw_COS_Extensions::register_facts( 'core/status', static fn() => array() ) );
		$this->assertFalse( YoOhw_COS_Extensions::register_attention( 'Bad ID', static fn() => array() ) );
		$input = array( 's' => 'extension-', 'extensions' => array( 'example-provider/min-orders' => '2' ), 'per_page' => 1 );
		$first = YoOhw_COS_Customer_Query::query( $input );
		$second = YoOhw_COS_Customer_Query::query( $input + array( 'paged' => 2 ) );
		$this->assertSame( 2, $first['total_items'] );
		$this->assertSame( 2, $second['total_items'] );
		$this->assertCount( 1, $first['items'] );
		$this->assertCount( 1, $second['items'] );
		$this->assertNotSame( $first['items'][0]['id'], $second['items'][0]['id'] );
		$this->assertNotContains( $one, array( $first['items'][0]['id'], $second['items'][0]['id'] ) );
		$this->assertSame( 0, YoOhw_COS_Customer_Query::query( array( 'extensions' => array( 'unknown/filter' => '1' ) ) )['total_items'] );
		$this->assertSame( 0, YoOhw_COS_Customer_Query::query( array( 'extensions' => array( 'example-provider/min-orders' => array( '2' ) ) ) )['total_items'] );
		$this->assertSame( 0, YoOhw_COS_Customer_Query::query( array( 'extensions' => 'bad' ) )['total_items'] );
		YoOhw_COS_Extensions::register_query( 'example-provider/throw', static function () { throw new RuntimeException(); }, static fn() => array() );
		$this->assertSame( 0, YoOhw_COS_Customer_Query::query( array( 'extensions' => array( 'example-provider/throw' => '1' ) ) )['total_items'] );
		YoOhw_COS_Extensions::register_query( 'example-provider/bad-predicate', static fn( $value ) => $value, static function () { throw new RuntimeException(); } );
		$this->assertSame( 0, YoOhw_COS_Customer_Query::query( array( 'extensions' => array( 'example-provider/bad-predicate' => '1' ) ) )['total_items'] );
		$this->assertSame( 3, YoOhw_COS_Customer_Query::query( array( 's' => 'extension-' ) )['total_items'] );
		$builder_calls = 0;
		YoOhw_COS_Extensions::register_query( 'example-provider/count-calls', static fn( $value ) => $value, static function ( $value ) use ( &$builder_calls ): array {
			++$builder_calls;
			return array( 'field' => 'total_orders', 'operator' => '>=', 'value' => $value );
		} );
		YoOhw_COS_Customer_Query::query( array( 's' => 'extension-', 'extensions' => array( 'example-provider/count-calls' => '1' ), 'per_page' => 1 ) );
		YoOhw_COS_Customer_Query::query( array( 's' => 'extension-', 'extensions' => array( 'example-provider/count-calls' => '1' ), 'per_page' => 3 ) );
		$this->assertSame( 2, $builder_calls );
		$this->assertArrayNotHasKey( 'extensions', YoOhw_COS_Saved_Views::definition( $input ) );
		$canonical_rows = YoOhw_COS_Customer_Query::query( array( 's' => 'extension-', 'per_page' => 3 ) )['items'];
		YoOhw_COS_Customer_Facts::snapshot( $canonical_rows[0] ); // Warm shared policy options before measuring row scaling.
		$before_snapshots = $wpdb->num_queries;
		foreach ( $canonical_rows as $canonical_row ) {
			YoOhw_COS_Customer_Facts::snapshot( $canonical_row );
			YoOhw_COS_Attention::reasons( $canonical_row );
		}
		$this->assertSame( $before_snapshots, $wpdb->num_queries, 'Canonical list snapshots must not issue per-customer SQL.' );
		$customer = YoOhw_COS_Customers::get_customer( $two );
		$private_values = array(
			'email' => 'private-customer@example.test',
			'phone' => '555-PII-PHONE',
			'first_name' => 'PrivateFirst',
			'last_name' => 'PrivateLast',
			'display_name' => 'PrivateDisplay',
			'address_line_1' => 'Private Address 123',
			'notes' => 'Private note text',
			'payment_token' => 'PrivatePaymentToken',
			'privacy_suppression_digest' => 'PrivatePrivacyDigest',
		);
		$customer = array_merge( $customer, $private_values );
		$customer['money_state'] = 'mixed';
		$customer['total_spent'] = 500;
		$facts = YoOhw_COS_Customer_Facts::snapshot( $customer, array( 'open_tasks' => 2 ) );
		$this->assertNull( $facts['core/total_spent'] );
		$this->assertSame( 'unavailable', $facts['core/money_state'] );
		$this->assertSame( 2, $facts['core/rfm_frequency'] );
		$this->assertSame( 2, $facts['core/open_follow_up_count'] );
		$this->assertTrue( $facts['example-provider/repeat'] );
		$this->assertNotSame( 'override', $facts['core/status'] );
		foreach ( array_keys( $facts ) as $key ) {
			$this->assertDoesNotMatchRegularExpression( '/email|phone|name|address|note|payment|privacy/i', $key === 'core/email_present' || $key === 'core/phone_present' ? '' : $key );
		}
		$this->assertStringNotContainsString( 'extension-two@example.test', wp_json_encode( $facts ) );
		$comparable = $customer;
		$comparable['money_state'] = 'comparable';
		$comparable['money_currency'] = get_woocommerce_currency();
		$comparable['commerce_metrics_version'] = YoOhw_COS_Commerce_Metrics_Policy::VERSION;
		$comparable['last_order_date'] = current_time( 'Y-m-d' ) . ' 12:00:00';
		$comparable['total_spent'] = '125.50';
		$comparable['average_order_value'] = '62.75';
		$money_facts = YoOhw_COS_Customer_Facts::snapshot( $comparable );
		if ( YoOhw_COS_Commerce_Metrics_Policy::money_is_comparable( $comparable ) ) {
			$this->assertSame( 'comparable', $money_facts['core/money_state'] );
			$this->assertSame( 125.5, $money_facts['core/rfm_monetary'] );
			$this->assertSame( 62.75, $money_facts['core/average_order_value'] );
		} else {
			$this->assertSame( 'unavailable', $money_facts['core/money_state'] );
			$this->assertNull( $money_facts['core/rfm_monetary'] );
			$this->assertNull( $money_facts['core/average_order_value'] );
		}
		$this->assertSame( 0, $money_facts['core/rfm_recency_days'] );
		$reasons = YoOhw_COS_Attention::reasons( $customer, array( 'overdue_tasks' => 1, 'raw_note' => 'PrivateAttentionContext' ) );
		$this->assertSame( 'core/overdue_follow_up', $reasons[0]['id'] );
		$this->assertSame( 'example-provider/check', $reasons[1]['id'] );
		$this->assertSame( array( 'overdue_tasks' => 1 ), YCI_Example_Extension_Provider::$observed['attention']['context'] );
		YoOhw_COS_Extensions::register_attention( 'example-provider/too-long', static fn() => array( 'id' => 'example-provider/too-long', 'message' => str_repeat( 'x', 241 ) ) );
		YoOhw_COS_Extensions::register_attention( 'example-provider/wrong-id', static fn() => array( 'id' => 'core/overdue_follow_up', 'message' => 'Override' ) );
		$this->assertCount( 2, YoOhw_COS_Attention::reasons( $customer, array( 'overdue_tasks' => 1 ) ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( array(), YoOhw_COS_Extensions::actions( $customer ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$actions = YoOhw_COS_Extensions::actions( $customer );
		$this->assertCount( 1, $actions );
		$this->assertStringContainsString( 'customer_id=' . $two, $actions[0]['url'] );
		$this->assertSame( $two, YCI_Example_Extension_Provider::$observed['action']['customer_id'] );
		$observed = wp_json_encode( YCI_Example_Extension_Provider::$observed );
		foreach ( $private_values as $private_value ) {
			$this->assertStringNotContainsString( $private_value, $observed );
		}
		$this->assertStringNotContainsString( 'PrivateAttentionContext', $observed );
		$this->assertTrue( YCI_Example_Extension_Provider::$observed['facts']['core/email_present'] );
		$this->assertTrue( YCI_Example_Extension_Provider::$observed['facts']['core/phone_present'] );
		$this->assertSame( 2, YCI_Example_Extension_Provider::$observed['facts']['core/rfm_frequency'] );
		$this->assertArrayHasKey( 'core/money_state', YCI_Example_Extension_Provider::$observed['facts'] );
		$this->assertArrayHasKey( 'core/status', YCI_Example_Extension_Provider::$observed['facts'] );
		ob_start();
		YoOhw_COS_Customer_Profile::render( $two );
		$profile = ob_get_clean();
		$this->assertStringContainsString( 'Synthetic action', $profile );
		$this->assertStringContainsString( 'Synthetic provider reason', $profile );
		YoOhw_COS_Extensions::register_action( 'example-provider/unsafe', static fn() => array( 'id' => 'example-provider/unsafe', 'label' => 'Unsafe', 'url' => 'javascript:alert(1)', 'capability' => 'manage_options' ) );
		YoOhw_COS_Extensions::register_action( 'example-provider/traversal', static fn() => array( 'id' => 'example-provider/traversal', 'label' => 'Traversal', 'url' => admin_url( '../outside' ), 'capability' => 'manage_options' ) );
		$this->assertCount( 1, YoOhw_COS_Extensions::actions( $customer ) );
		$this->assertFalse( wp_next_scheduled( 'example-provider/open' ) );
		$this->assertSame( $options_before, $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s ORDER BY option_name', $wpdb->options, $wpdb->esc_like( 'yoohw_cos_' ) . '%' ) ) );
		$this->assertSame( $tables_before, $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'yoohw_cos_' ) . '%' ) ) );
		$this->assertSame( $cron_before, get_option( 'cron' ) );
	}
}
