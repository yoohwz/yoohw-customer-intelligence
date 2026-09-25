<?php
require_once dirname( __DIR__ ) . '/environment.php';
yci_test_environment();
require_once dirname( __DIR__ ) . '/fixtures/example-extension-provider.php';

/** @group yoohw-customer-intelligence */
final class YCI_Extension_Contracts_Test extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
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
		$this->assertSame( 1, YoOhw_COS_Extensions::version() );
		$this->assertSame( YoOhw_COS_Extensions::VERSION, YoOhw_COS_Extensions::version() );
		$today = YoOhw_COS_DB::now();
		$one = YoOhw_COS_Customers::create_customer( array( 'email' => 'extension-one@example.test', 'phone' => '123', 'total_orders' => 1, 'last_activity_date' => $today ) );
		$two = YoOhw_COS_Customers::create_customer( array( 'email' => 'extension-two@example.test', 'phone' => '123', 'total_orders' => 2, 'last_activity_date' => $today ) );
		$three = YoOhw_COS_Customers::create_customer( array( 'email' => 'extension-three@example.test', 'phone' => '123', 'total_orders' => 3, 'last_activity_date' => $today ) );
		$this->assertGreaterThan( 0, $three );
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
		$customer = YoOhw_COS_Customers::get_customer( $two );
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
		$reasons = YoOhw_COS_Attention::reasons( $customer, array( 'overdue_tasks' => 1 ) );
		$this->assertSame( 'core/overdue_follow_up', $reasons[0]['id'] );
		$this->assertSame( 'example-provider/check', $reasons[1]['id'] );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( array(), YoOhw_COS_Extensions::actions( $customer ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertCount( 1, YoOhw_COS_Extensions::actions( $customer ) );
		ob_start();
		YoOhw_COS_Customer_Profile::render( $two );
		$profile = ob_get_clean();
		$this->assertStringContainsString( 'Synthetic action', $profile );
		$this->assertStringContainsString( 'Synthetic provider reason', $profile );
		YoOhw_COS_Extensions::register_action( 'example-provider/unsafe', static fn() => array( 'id' => 'example-provider/unsafe', 'label' => 'Unsafe', 'url' => 'javascript:alert(1)', 'capability' => 'manage_options' ) );
		YoOhw_COS_Extensions::register_action( 'example-provider/traversal', static fn() => array( 'id' => 'example-provider/traversal', 'label' => 'Traversal', 'url' => admin_url( '../outside' ), 'capability' => 'manage_options' ) );
		$this->assertCount( 1, YoOhw_COS_Extensions::actions( $customer ) );
		$this->assertFalse( wp_next_scheduled( 'example-provider/open' ) );
	}
}
