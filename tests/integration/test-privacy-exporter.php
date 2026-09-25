<?php
require_once dirname( __DIR__ ) . '/environment.php';
yci_test_environment();

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WP_UnitTestCase' ) ) {
	return;
}

/** @group yoohw-customer-intelligence */
final class YCI_Privacy_Exporter_Test extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		delete_option( YoOhw_COS_Reset_Guard::OPTION );
		YoOhw_COS_Reset_Guard::init();
		YoOhw_COS_Privacy_Exporter::init();
	}

	public function tear_down(): void {
		global $wpdb;
		foreach ( YoOhw_COS_Install::expected_table_keys() as $key ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', YoOhw_COS_DB::table( $key ) ) );
		}
		delete_option( YoOhw_COS_Reset_Guard::OPTION );
		parent::tear_down();
	}

	private function customer( string $email, int $user_id = 0, array $extra = array() ): int {
		global $wpdb;
		$wpdb->insert( YoOhw_COS_DB::customers_table(), array_merge( array(
			'email' => $email,
			'wp_user_id' => $user_id ?: null,
			'created_at' => '2026-01-01 00:00:00',
			'updated_at' => '2026-01-01 00:00:00',
		), $extra ) );
		$this->assertSame( '', $wpdb->last_error );
		return (int) $wpdb->insert_id;
	}

	private function record( string $table, array $fields ): int {
		global $wpdb;
		$wpdb->insert( YoOhw_COS_DB::table( $table ), array_merge( array( 'created_at' => '2026-01-02 00:00:00' ), $fields ) );
		$this->assertSame( '', $wpdb->last_error );
		return (int) $wpdb->insert_id;
	}

	private function all_pages( string $email ): array {
		$items = array();
		for ( $page = 1; $page <= 20; $page++ ) {
			$result = YoOhw_COS_Privacy_Exporter::export( $email, $page );
			$items = array_merge( $items, $result['data'] );
			if ( $result['done'] ) {
				return $items;
			}
		}
		$this->fail( 'Exporter did not terminate within the fixture bound.' );
	}

	private function text( array $items ): string {
		return wp_json_encode( $items );
	}

	public function test_native_registration_exact_identity_and_owned_fields(): void {
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$this->assertSame( array( YoOhw_COS_Privacy_Exporter::class, 'export' ), $exporters['yoohw-customer-intelligence']['callback'] );
		$subject_id = self::factory()->user->create( array( 'user_email' => 'subject@example.test' ) );
		$other_id = self::factory()->user->create( array( 'user_email' => 'operator@example.test' ) );
		$guest = $this->customer( 'subject@example.test', 0, array( 'display_name' => 'Guest Subject', 'money_state' => 'mixed', 'total_spent' => 999, 'total_orders' => 2, 'archived_at' => '2026-01-03 00:00:00' ) );
		$linked = $this->customer( 'old-address@example.test', $subject_id, array( 'display_name' => 'Linked Subject' ) );
		$unrelated = $this->customer( 'subject-other@example.test', $other_id, array( 'display_name' => 'Secret Other' ) );
		$this->record( 'notes', array( 'customer_id' => $guest, 'author_id' => $other_id, 'note_content' => 'Subject note <script>alert(1)</script>', 'updated_at' => '2026-01-02 00:00:00' ) );
		$this->record( 'tasks', array( 'customer_id' => $linked, 'assigned_user_id' => $other_id, 'created_by' => $other_id, 'completed_by' => $other_id, 'source_key' => 'private-token-123', 'title' => 'Follow subject', 'description' => 'Call next week', 'updated_at' => '2026-01-02 00:00:00' ) );
		$this->record( 'events', array( 'customer_id' => $guest, 'event_type' => 'profile.updated', 'description' => 'Subject activity', 'object_type' => 'user', 'object_id' => $other_id, 'metadata_json' => '{"secret":"private-token-456"}' ) );
		$tag_id = $this->record( 'tags', array( 'name' => 'VIP', 'slug' => 'vip', 'updated_at' => '2026-01-02 00:00:00' ) );
		$this->record( 'customer_tags', array( 'customer_id' => $guest, 'tag_id' => $tag_id ) );
		$segment_id = $this->record( 'segments', array( 'name' => 'Regulars', 'slug' => 'regulars', 'segment_type' => 'static', 'updated_at' => '2026-01-02 00:00:00' ) );
		$this->record( 'customer_segments', array( 'customer_id' => $linked, 'segment_id' => $segment_id ) );
		$this->record( 'notes', array( 'customer_id' => $unrelated, 'note_content' => 'Other secret', 'updated_at' => '2026-01-02 00:00:00' ) );
		$text = $this->text( $this->all_pages( 'subject@example.test' ) );
		$this->assertStringContainsString( 'Guest Subject', $text );
		$this->assertStringContainsString( 'Linked Subject', $text );
		$this->assertStringContainsString( 'Archived date', $text );
		$this->assertStringContainsString( 'Unavailable (mixed or unknown currency)', $text );
		$this->assertStringContainsString( 'Subject note', $text );
		$this->assertStringContainsString( 'Follow subject', $text );
		$this->assertStringContainsString( 'Subject activity', $text );
		$this->assertStringContainsString( 'VIP', $text );
		$this->assertStringContainsString( 'Regulars', $text );
		$this->assertStringNotContainsString( 'Secret Other', $text );
		$this->assertStringNotContainsString( 'Other secret', $text );
		$this->assertStringNotContainsString( 'private-token', $text );
		$this->assertStringNotContainsString( 'author_id', $text );
		$this->assertStringNotContainsString( 'Object reference', $text );
		$this->assertStringNotContainsString( 'alert(1)', $text );
		$this->assertEmpty( $this->all_pages( 'ject@example.test' ) );
		$this->assertEmpty( $this->all_pages( 'invalid address' ) );
	}

	public function test_pagination_and_reset_id_reuse(): void {
		global $wpdb;
		$customer = $this->customer( 'paged@example.test' );
		for ( $i = 0; $i < 31; $i++ ) {
			$this->record( 'notes', array( 'customer_id' => $customer, 'note_content' => 'note-' . $i, 'updated_at' => '2026-01-02 00:00:00' ) );
			$this->record( 'tasks', array( 'customer_id' => $customer, 'title' => 'task-' . $i, 'updated_at' => '2026-01-02 00:00:00' ) );
			$this->record( 'events', array( 'customer_id' => $customer, 'event_type' => 'test', 'description' => 'event-' . $i ) );
		}
		$items = $this->all_pages( 'paged@example.test' );
		$this->assertCount( 94, $items );
		$this->assertCount( 94, array_unique( array_column( $items, 'item_id' ) ) );
		$this->assertStringContainsString( 'note-30', $this->text( $items ) );
		$this->assertStringContainsString( 'task-30', $this->text( $items ) );
		$this->assertStringContainsString( 'event-30', $this->text( $items ) );
		$this->assertFalse( YoOhw_COS_Privacy_Exporter::export( 'paged@example.test', 1 )['done'] );
		YoOhw_COS_Reset_Guard::reset( function() use ( $wpdb ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', YoOhw_COS_DB::notes_table() ) );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', YoOhw_COS_DB::customers_table() ) );
		} );
		$wpdb->insert( YoOhw_COS_DB::customers_table(), array( 'id' => $customer, 'email' => 'new-person@example.test', 'created_at' => '2026-01-03 00:00:00', 'updated_at' => '2026-01-03 00:00:00' ) );
		$this->record( 'notes', array( 'customer_id' => $customer, 'note_content' => 'Reused ID secret', 'updated_at' => '2026-01-03 00:00:00' ) );
		$this->assertEmpty( YoOhw_COS_Privacy_Exporter::export( 'paged@example.test', 2 )['data'] );
	}

	public function test_saved_views_are_owner_scoped_without_resolving_customer_results(): void {
		$owner = self::factory()->user->create( array( 'user_email' => 'view-owner@example.test' ) );
		$other = self::factory()->user->create( array( 'user_email' => 'other-view-owner@example.test' ) );
		$definition = YoOhw_COS_Saved_Views::definition( array() );
		update_user_meta( $owner, '_yoohw_cos_saved_customer_views', array(
			'11111111-1111-4111-8111-111111111111' => array( 'name' => 'My private view', 'definition' => $definition ),
		) );
		update_user_meta( $other, '_yoohw_cos_saved_customer_views', array(
			'22222222-2222-4222-8222-222222222222' => array( 'name' => 'Other private view', 'definition' => $definition ),
		) );
		$this->customer( 'matching-view-result@example.test' );
		$text = $this->text( $this->all_pages( 'view-owner@example.test' ) );
		$this->assertStringContainsString( 'My private view', $text );
		$this->assertStringNotContainsString( 'Other private view', $text );
		$this->assertStringNotContainsString( 'matching-view-result', $text );
	}

	public function test_pending_reset_reports_retry_instead_of_false_completion(): void {
		$this->customer( 'pending@example.test' );
		update_option( YoOhw_COS_Reset_Guard::OPTION, array( 'epoch' => wp_generate_uuid4(), 'status' => 'pending' ), false );
		$this->assertWPError( YoOhw_COS_Privacy_Exporter::export( 'pending@example.test', 1 ) );
	}

	public function test_missing_wordpress_user_does_not_match_zero_link(): void {
		$this->customer( 'guest@example.test', 0, array( 'display_name' => 'Matching guest' ) );
		$this->customer( 'unrelated@example.test', 0, array( 'wp_user_id' => 0, 'display_name' => 'Zero-link secret' ) );
		$text = $this->text( $this->all_pages( 'guest@example.test' ) );
		$this->assertStringContainsString( 'Matching guest', $text );
		$this->assertStringNotContainsString( 'Zero-link secret', $text );
	}
}
