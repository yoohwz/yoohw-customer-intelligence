<?php
require_once dirname( __DIR__ ) . '/environment.php';
yci_test_environment();

/** @group yoohw-customer-intelligence */
final class YCI_AAP_Integration_Test extends WP_UnitTestCase {
	private function render_panel( array $customer ): string {
		ob_start();
		try { YoOhw_COS_AAP_Integration::render_panel( $customer ); return ob_get_contents(); }
		finally { ob_end_clean(); }
	}

	public function test_optional_provider_identity_permissions_rendering_and_failure(): void {
		global $wpdb;
		delete_option( YoOhw_COS_Reset_Guard::OPTION );
		YoOhw_COS_Reset_Guard::init();
		$manager = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user = self::factory()->user->create( array( 'role' => 'customer', 'user_email' => 'aap-linked@example.test' ) );
		$other = self::factory()->user->create( array( 'role' => 'customer' ) );
		get_user_by( 'id', $manager )->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $manager );
		$this->assertTrue( current_user_can( 'manage_woocommerce' ) );
		$id = YoOhw_COS_Customers::create_customer( array( 'wp_user_id' => $user, 'email' => 'different-contact@example.test' ) );
		$customer = YoOhw_COS_Customers::get_customer( $id );
		$this->assertGreaterThan( 0, $id );
		$this->assertSame( '', $this->render_panel( $customer ), 'Absent AAP must not add a broken panel.' );
		// Model an installed old AAP without the summary API before loading the v1 provider.
		if ( ! class_exists( 'YOAA_WC_Advanced_Accounts_Premium' ) ) { eval( 'class YOAA_WC_Advanced_Accounts_Premium {}' ); }
		$this->assertStringContainsString( 'AAP information is unavailable', $this->render_panel( $customer ) );
		require_once dirname( __DIR__ ) . '/fixtures/aap-summary-provider.php';
		$summary = array(
			'version' => 1, 'wp_user_id' => $user,
			'verification' => array( 'email' => 'verified', 'phone' => 'unknown' ),
			'memberships' => array(
				array( 'role' => 'gold', 'label' => 'Gold <script>alert(1)</script>', 'effective' => true, 'unknown' => false, 'loyalty' => false,
					'sources' => array(
						array( 'type' => 'order', 'status' => 'active', 'effective' => true, 'end' => '2030-01-01 00:00:00', 'duration_type' => 'period' ),
						array( 'type' => 'order', 'status' => 'active', 'effective' => true, 'end' => '2020-01-01 00:00:00', 'duration_type' => 'unlimited' ),
						array( 'type' => 'subscription', 'status' => 'active', 'effective' => true, 'end' => '2021-01-01 00:00:00', 'duration_type' => 'period' ),
					) ),
				array( 'role' => 'expired', 'label' => 'Expired membership', 'effective' => false, 'unknown' => false, 'loyalty' => false, 'sources' => array() ),
				array( 'role' => 'legacy', 'label' => 'Legacy membership', 'effective' => false, 'unknown' => true, 'loyalty' => true, 'sources' => array() ),
			),
			'program' => array( 'status' => 'approved', 'title' => 'Trade <b>program</b>' ),
			'links' => array( 'account' => get_edit_user_link( $user ), 'program' => admin_url( 'users.php?page=aap-program-applications&customer=' . $user ) ),
			'internal_note' => 'DO-NOT-DISPLAY-PRIVATE-NOTE', 'otp' => 'DO-NOT-DISPLAY-SECRET',
		);
		WCAAP_Customer_Summary::$response = $summary;
		WCAAP_Customer_Summary::$calls = array();
		$before_customer = YoOhw_COS_Customers::get_customer( $id );
		$before_meta = get_user_meta( $user );
		$before_cron = get_option( 'cron' );
		$html = $this->render_panel( $customer );
		$this->assertSame( array( $user ), WCAAP_Customer_Summary::$calls );
		$this->assertStringContainsString( 'Email verification', $html );
		$this->assertStringContainsString( 'Unknown', $html );
		$this->assertStringContainsString( 'Profile approved', $html );
		$this->assertStringContainsString( 'Ends: 2030-01-01', $html );
		$this->assertStringContainsString( 'No fixed end', $html );
		$this->assertStringContainsString( 'Term and renewal managed by subscription', $html );
		$this->assertStringNotContainsString( 'Ends: 2020', $html );
		$this->assertStringNotContainsString( 'Ends: 2021', $html );
		$this->assertStringContainsString( 'Inactive', $html );
		$this->assertStringContainsString( 'Unconfirmed', $html );
		$this->assertStringContainsString( 'Loyalty access evidence', $html );
		$this->assertStringContainsString( 'Manage account in AAP', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( 'DO-NOT-DISPLAY', $html );
		$this->assertSame( $before_customer, YoOhw_COS_Customers::get_customer( $id ) );
		$this->assertSame( $before_meta, get_user_meta( $user ) );
		$this->assertSame( $before_cron, get_option( 'cron' ) );

		// The real profile surface includes the panel in the existing side column.
		ob_start();
		try { YoOhw_COS_Customer_Profile::render( $id ); $profile = ob_get_contents(); }
		finally { ob_end_clean(); }
		$this->assertStringContainsString( 'yoohw-cos-profile-aap', $profile );
		$this->assertStringContainsString( 'Profile approved', $profile );

		// Guest/contact matches, nonexistent users, malformed IDs and stale/duplicate links never query AAP.
		$unlinked = $customer;
		$unlinked['wp_user_id'] = 0;
		$unlinked['email'] = 'aap-linked@example.test';
		$before_calls = WCAAP_Customer_Summary::$calls;
		foreach ( array( $unlinked, array_merge( $customer, array( 'wp_user_id' => 99999999 ) ), array_merge( $customer, array( 'wp_user_id' => $other ) ), array_merge( $customer, array( 'wp_user_id' => $user . 'bad' ) ) ) as $bad_link ) {
			$this->assertStringContainsString( 'No confirmed account link', $this->render_panel( $bad_link ) );
		}
		$wpdb->insert( YoOhw_COS_DB::customers_table(), array( 'wp_user_id' => $user, 'email' => 'aap-duplicate@example.test', 'created_at' => YoOhw_COS_DB::now(), 'updated_at' => YoOhw_COS_DB::now() ) );
		$duplicate = (int) $wpdb->insert_id;
		$this->assertGreaterThan( 0, $duplicate );
		$this->assertStringContainsString( 'No confirmed account link', $this->render_panel( $customer ) );
		$this->assertSame( $before_calls, WCAAP_Customer_Summary::$calls );
		$wpdb->delete( YoOhw_COS_DB::customers_table(), array( 'id' => $duplicate ) );

		$staff = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $staff );
		$this->assertSame( '', $this->render_panel( $customer ) );
		$crm_only = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $crm_only )->add_cap( 'manage_options' );
		wp_set_current_user( $crm_only );
		$this->assertSame( '', $this->render_panel( $customer ) );
		wp_get_current_user()->add_cap( 'manage_woocommerce' );
		$this->assertStringContainsString( 'do not have permission', $this->render_panel( $customer ) );
		$this->assertSame( $before_calls, WCAAP_Customer_Summary::$calls );
		wp_set_current_user( 0 );
		$this->assertSame( '', $this->render_panel( $customer ) );
		wp_set_current_user( $manager );

		$bad_summaries = array( null, new WP_Error( 'unavailable', 'PRIVATE-PROVIDER-ERROR' ), new RuntimeException( 'PRIVATE-PROVIDER-ERROR' ), array_merge( $summary, array( 'version' => 2 ) ), array_merge( $summary, array( 'wp_user_id' => $other ) ), array_merge( $summary, array( 'verification' => array( 'email' => true, 'phone' => 'verified' ) ) ), array_merge( $summary, array( 'memberships' => array( array( 'effective' => 'yes' ) ) ) ) );
		foreach ( $bad_summaries as $bad ) {
			WCAAP_Customer_Summary::$response = $bad;
			$html = $this->render_panel( $customer );
			$this->assertStringContainsString( 'AAP information is unavailable', $html );
			$this->assertStringNotContainsString( 'PRIVATE-PROVIDER-ERROR', $html );
			$this->assertStringNotContainsString( 'Profile approved', $html );
		}
		$summary['links'] = array( 'account' => 'https://attacker.example/wp-admin/user-edit.php', 'program' => admin_url( '../secret' ) );
		$summary['verification'] = array( 'email' => 'unverified', 'phone' => 'unknown' );
		$summary['memberships'] = array();
		$summary['program'] = array( 'status' => 'none', 'title' => '' );
		WCAAP_Customer_Summary::$response = $summary;
		$html = $this->render_panel( $customer );
		$this->assertStringContainsString( 'Not verified', $html );
		$this->assertStringContainsString( 'No recorded AAP Membership', $html );
		$this->assertStringContainsString( 'No application', $html );
		$this->assertStringNotContainsString( 'Manage account in AAP', $html );
		$this->assertStringNotContainsString( 'Review program application', $html );
		WCAAP_Customer_Summary::$response = null;
		wp_set_current_user( 0 );
	}
}
