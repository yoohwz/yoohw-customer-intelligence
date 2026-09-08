<?php
defined( 'ABSPATH' ) || exit;

final class YoOhw_COS_Install {

	private const SCHEMA_STATUS_OPTION = 'yoohw_cos_schema_status';
	private static $ensure_failed = false;

	public static function install(): void {
		self::upgrade_schema( (string) get_option( 'yoohw_cos_db_version', '' ) );
		update_option( 'yoohw_cos_version', YOOHW_COS_VERSION );
	}

	private static function upgrade_schema( string $from_version ): void {
		global $wpdb;
		self::$ensure_failed = false;
		$previous = $wpdb->suppress_errors();
		try {
			self::create_tables();
			self::ensure_customer_schema();
			self::ensure_task_schema();
			self::ensure_event_schema();
		} catch ( Throwable $exception ) {
			self::$ensure_failed = true;
		} finally {
			$wpdb->suppress_errors( $previous );
		}
		if ( ! self::check_schema( true, self::$ensure_failed ? array( 'ddl.ensure' ) : array() ) ) {
			return;
		}
		if ( ! YoOhw_COS_Migration_Runner::register_upgrade( $from_version, self::db_version() ) ) {
			return;
		}
		update_option( 'yoohw_cos_db_version', self::db_version() );
	}

	/** Re-read actual schema; persisted readiness is diagnostic, never authorization. */
	public static function schema_is_ready(): bool {
		return self::check_schema();
	}

	private static function check_schema( bool $attempted = false, array $failures = array() ): bool {
		global $wpdb;
		$previous = $wpdb->suppress_errors();
		try {
			foreach ( self::schema_contract() as $key => $contract ) {
				$table = YoOhw_COS_DB::table( $key );
				if ( ! self::table_exists( $table ) ) {
					$failures[] = $key . '.table';
					continue;
				}
				$columns = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), OBJECT_K );
				foreach ( $contract['columns'] as $name => $definition ) {
					$column = $columns[ $name ] ?? null;
					$type = $column ? preg_replace( '/\b(tinyint|smallint|int|bigint)\(\d+\)/', '$1', strtolower( $column->Type ) ) : '';
					if ( ! $column || $type !== $definition[0] || ( 'YES' === $column->Null ) !== $definition[1]
						|| $column->Default !== $definition[2]
						|| ( 'id' === $name && false === strpos( $column->Extra, 'auto_increment' ) ) ) {
						$failures[] = $key . '.column.' . $name;
					}
				}
				$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), ARRAY_A );
				$indexes = array();
				foreach ( (array) $rows as $row ) {
					$indexes[ $row['Key_name'] ][ (int) $row['Seq_in_index'] ] = $row;
				}
				foreach ( $contract['indexes'] as $name => $definition ) {
					$parts = $indexes[ $name ] ?? array();
					ksort( $parts );
					$valid = count( $parts ) === count( $definition[1] );
					foreach ( array_values( $parts ) as $offset => $part ) {
						$valid = $valid && ( $definition[1][ $offset ] ?? '' ) === $part['Column_name']
							&& ( 0 === (int) $part['Non_unique'] ) === $definition[0]
							&& null === $part['Sub_part'] && 'BTREE' === $part['Index_type']
							&& 'A' === $part['Collation'] && 'YES' === ( $part['Visible'] ?? 'YES' );
					}
					if ( ! $valid ) { $failures[] = $key . '.index.' . $name; }
				}
			}
		} catch ( Throwable $exception ) {
			$failures[] = 'schema.read';
		} finally {
			$wpdb->suppress_errors( $previous );
		}
		// Only fixed manifest identifiers are persisted: no raw SQL/error/row data.
		$status = array(
			'status' => empty( $failures ) ? 'ready' : 'blocked',
			'target_version' => self::db_version(),
			'requirements' => array_values( array_unique( $failures ) ),
		);
		$stored = get_option( self::SCHEMA_STATUS_OPTION, array() );
		$last_attempt = is_array( $stored ) ? ( $stored['last_attempt_at'] ?? '' ) : '';
		$status['last_attempt_at'] = $attempted || '' === $last_attempt ? YoOhw_COS_DB::now() : $last_attempt;
		update_option( self::SCHEMA_STATUS_OPTION, $status, false );
		return empty( $failures );
	}

	private static function execute_ensure_ddl( string $sql ): void {
		global $wpdb;
		if ( false === $wpdb->query( $sql ) ) { self::$ensure_failed = true; }
	}

	/** Current 0.2.1 schema only; column tuples are type, nullable, default. */
	private static function schema_contract(): array {
		return array(
			'customers' => array(
				'columns' => array(
					'id' => array( 'bigint unsigned', false, null ),
					'wp_user_id' => array( 'bigint unsigned', true, null ),
					'email' => array( 'varchar(191)', true, null ),
					'phone' => array( 'varchar(50)', true, null ),
					'first_name' => array( 'varchar(100)', true, null ),
					'last_name' => array( 'varchar(100)', true, null ),
					'display_name' => array( 'varchar(191)', true, null ),
					'total_orders' => array( 'bigint unsigned', false, '0' ),
					'total_spent' => array( 'decimal(20,6)', false, '0.000000' ),
					'average_order_value' => array( 'decimal(20,6)', false, '0.000000' ),
					'commerce_metrics_version' => array( 'smallint unsigned', false, '0' ),
					'risk_score' => array( 'decimal(5,2)', false, '0.00' ),
					'trust_score' => array( 'decimal(5,2)', false, '0.00' ),
					'loyalty_score' => array( 'decimal(5,2)', false, '0.00' ),
					'loyalty_level' => array( 'varchar(100)', false, '' ),
					'available_points' => array( 'bigint', false, '0' ),
					'earned_points' => array( 'bigint', false, '0' ),
					'customer_status' => array( 'varchar(50)', false, 'active' ),
					'vip_status' => array( 'varchar(50)', false, 'none' ),
					'first_order_id' => array( 'bigint unsigned', true, null ),
					'first_order_date' => array( 'datetime', true, null ),
					'last_order_id' => array( 'bigint unsigned', true, null ),
					'last_order_date' => array( 'datetime', true, null ),
					'last_activity_date' => array( 'datetime', true, null ),
					'lifecycle_stage' => array( 'varchar(50)', false, 'new' ),
					'archived_at' => array( 'datetime', true, null ),
					'archived_by' => array( 'bigint unsigned', true, null ),
					'archive_reason' => array( 'text', true, null ),
					'created_at' => array( 'datetime', false, null ),
					'updated_at' => array( 'datetime', false, null ),
				),
				'indexes' => array(
					'PRIMARY' => array( true, array( 'id' ) ),
					'wp_user_id' => array( false, array( 'wp_user_id' ) ),
					'email' => array( false, array( 'email' ) ),
					'phone' => array( false, array( 'phone' ) ),
					'customer_status' => array( false, array( 'customer_status' ) ),
					'vip_status' => array( false, array( 'vip_status' ) ),
					'first_order_id' => array( false, array( 'first_order_id' ) ),
					'first_order_date' => array( false, array( 'first_order_date' ) ),
					'risk_score' => array( false, array( 'risk_score' ) ),
					'trust_score' => array( false, array( 'trust_score' ) ),
					'loyalty_score' => array( false, array( 'loyalty_score' ) ),
					'loyalty_level' => array( false, array( 'loyalty_level' ) ),
					'last_order_date' => array( false, array( 'last_order_date' ) ),
					'last_activity_date' => array( false, array( 'last_activity_date' ) ),
					'lifecycle_stage' => array( false, array( 'lifecycle_stage' ) ),
					'archived_at' => array( false, array( 'archived_at' ) ),
				),
			),
			'events' => array(
				'columns' => array(
					'id' => array( 'bigint unsigned', false, null ),
					'customer_id' => array( 'bigint unsigned', true, null ),
					'wp_user_id' => array( 'bigint unsigned', true, null ),
					'event_type' => array( 'varchar(100)', false, null ),
					'event_source' => array( 'varchar(100)', false, 'system' ),
					'severity' => array( 'varchar(30)', false, 'info' ),
					'object_type' => array( 'varchar(50)', true, null ),
					'object_id' => array( 'bigint unsigned', true, null ),
					'description' => array( 'text', true, null ),
					'metadata_json' => array( 'longtext', true, null ),
					'event_key' => array( 'varchar(191)', true, null ),
					'created_at' => array( 'datetime', false, null ),
				),
				'indexes' => array(
					'PRIMARY' => array( true, array( 'id' ) ),
					'customer_id' => array( false, array( 'customer_id' ) ),
					'wp_user_id' => array( false, array( 'wp_user_id' ) ),
					'event_type' => array( false, array( 'event_type' ) ),
					'event_source' => array( false, array( 'event_source' ) ),
					'severity' => array( false, array( 'severity' ) ),
					'object_lookup' => array( false, array( 'object_type', 'object_id' ) ),
					'event_key' => array( true, array( 'event_key' ) ),
					'created_at' => array( false, array( 'created_at' ) ),
				),
			),
			'notes' => array(
				'columns' => array(
					'id' => array( 'bigint unsigned', false, null ),
					'customer_id' => array( 'bigint unsigned', false, null ),
					'wp_user_id' => array( 'bigint unsigned', true, null ),
					'author_id' => array( 'bigint unsigned', true, null ),
					'note_type' => array( 'varchar(50)', false, 'internal' ),
					'note_content' => array( 'longtext', false, null ),
					'visibility' => array( 'varchar(30)', false, 'private' ),
					'created_at' => array( 'datetime', false, null ),
					'updated_at' => array( 'datetime', false, null ),
				),
				'indexes' => array(
					'PRIMARY' => array( true, array( 'id' ) ),
					'customer_id' => array( false, array( 'customer_id' ) ),
					'wp_user_id' => array( false, array( 'wp_user_id' ) ),
					'author_id' => array( false, array( 'author_id' ) ),
					'note_type' => array( false, array( 'note_type' ) ),
					'visibility' => array( false, array( 'visibility' ) ),
					'created_at' => array( false, array( 'created_at' ) ),
				),
			),
			'tasks' => array(
				'columns' => array(
					'id' => array( 'bigint unsigned', false, null ),
					'customer_id' => array( 'bigint unsigned', false, null ),
					'order_id' => array( 'bigint unsigned', true, null ),
					'assigned_user_id' => array( 'bigint unsigned', true, null ),
					'created_by' => array( 'bigint unsigned', true, null ),
					'title' => array( 'varchar(191)', false, null ),
					'description' => array( 'longtext', true, null ),
					'source_key' => array( 'varchar(191)', true, null ),
					'status' => array( 'varchar(30)', false, 'open' ),
					'priority' => array( 'varchar(30)', false, 'normal' ),
					'due_date' => array( 'datetime', true, null ),
					'completed_at' => array( 'datetime', true, null ),
					'completed_by' => array( 'bigint unsigned', true, null ),
					'created_at' => array( 'datetime', false, null ),
					'updated_at' => array( 'datetime', false, null ),
				),
				'indexes' => array(
					'PRIMARY' => array( true, array( 'id' ) ),
					'customer_id' => array( false, array( 'customer_id' ) ),
					'order_id' => array( false, array( 'order_id' ) ),
					'assigned_user_id' => array( false, array( 'assigned_user_id' ) ),
					'created_by' => array( false, array( 'created_by' ) ),
					'source_key' => array( true, array( 'source_key' ) ),
					'status' => array( false, array( 'status' ) ),
					'priority' => array( false, array( 'priority' ) ),
					'due_date' => array( false, array( 'due_date' ) ),
					'completed_at' => array( false, array( 'completed_at' ) ),
					'task_queue' => array( false, array( 'status', 'due_date' ) ),
					'notification_queue' => array( false, array( 'assigned_user_id', 'status', 'due_date', 'id' ) ),
				),
			),
			'tags' => array(
				'columns' => array(
					'id' => array( 'bigint unsigned', false, null ),
					'name' => array( 'varchar(100)', false, null ),
					'slug' => array( 'varchar(120)', false, null ),
					'color' => array( 'varchar(20)', true, null ),
					'description' => array( 'text', true, null ),
					'created_at' => array( 'datetime', false, null ),
					'updated_at' => array( 'datetime', false, null ),
				),
				'indexes' => array(
					'PRIMARY' => array( true, array( 'id' ) ),
					'slug' => array( true, array( 'slug' ) ),
					'name' => array( false, array( 'name' ) ),
				),
			),
			'customer_tags' => array(
				'columns' => array(
					'id' => array( 'bigint unsigned', false, null ),
					'customer_id' => array( 'bigint unsigned', false, null ),
					'tag_id' => array( 'bigint unsigned', false, null ),
					'created_by' => array( 'bigint unsigned', true, null ),
					'created_at' => array( 'datetime', false, null ),
				),
				'indexes' => array(
					'PRIMARY' => array( true, array( 'id' ) ),
					'customer_tag' => array( true, array( 'customer_id', 'tag_id' ) ),
					'customer_id' => array( false, array( 'customer_id' ) ),
					'tag_id' => array( false, array( 'tag_id' ) ),
				),
			),
			'segments' => array(
				'columns' => array(
					'id' => array( 'bigint unsigned', false, null ),
					'name' => array( 'varchar(100)', false, null ),
					'slug' => array( 'varchar(120)', false, null ),
					'segment_type' => array( 'varchar(50)', false, 'static' ),
					'description' => array( 'text', true, null ),
					'rules_json' => array( 'longtext', true, null ),
					'created_at' => array( 'datetime', false, null ),
					'updated_at' => array( 'datetime', false, null ),
				),
				'indexes' => array(
					'PRIMARY' => array( true, array( 'id' ) ),
					'slug' => array( true, array( 'slug' ) ),
					'segment_type' => array( false, array( 'segment_type' ) ),
				),
			),
			'customer_segments' => array(
				'columns' => array(
					'id' => array( 'bigint unsigned', false, null ),
					'customer_id' => array( 'bigint unsigned', false, null ),
					'segment_id' => array( 'bigint unsigned', false, null ),
					'created_by' => array( 'bigint unsigned', true, null ),
					'created_at' => array( 'datetime', false, null ),
				),
				'indexes' => array(
					'PRIMARY' => array( true, array( 'id' ) ),
					'customer_segment' => array( true, array( 'customer_id', 'segment_id' ) ),
					'customer_id' => array( false, array( 'customer_id' ) ),
					'segment_id' => array( false, array( 'segment_id' ) ),
				),
			),
			'order_facts' => array(
				'columns' => array(
					'id' => array( 'bigint unsigned', false, null ),
					'order_id' => array( 'bigint unsigned', false, null ),
					'customer_id' => array( 'bigint unsigned', false, null ),
					'order_status' => array( 'varchar(30)', false, null ),
					'order_total' => array( 'decimal(20,6)', false, '0.000000' ),
					'revenue_amount' => array( 'decimal(20,6)', false, '0.000000' ),
					'counts_as_order' => array( 'tinyint', false, '0' ),
					'counts_as_revenue' => array( 'tinyint', false, '0' ),
					'order_date' => array( 'datetime', false, null ),
					'policy_version' => array( 'smallint unsigned', false, '1' ),
					'updated_at' => array( 'datetime', false, null ),
				),
				'indexes' => array(
					'PRIMARY' => array( true, array( 'id' ) ),
					'order_id' => array( true, array( 'order_id' ) ),
					'customer_order' => array( false, array( 'customer_id', 'order_date', 'order_id' ) ),
					'contribution' => array( false, array( 'customer_id', 'counts_as_order' ) ),
				),
			),
			'notification_log' => array(
				'columns' => array(
					'id' => array( 'bigint unsigned', false, null ),
					'notification_key' => array( 'varchar(191)', false, null ),
					'notification_type' => array( 'varchar(100)', false, null ),
					'task_id' => array( 'bigint unsigned', true, null ),
					'recipient_user_id' => array( 'bigint unsigned', true, null ),
					'status' => array( 'varchar(20)', false, 'pending' ),
					'claim_token' => array( 'varchar(64)', true, null ),
					'lease_until' => array( 'datetime', true, null ),
					'attempts' => array( 'int unsigned', false, '0' ),
					'created_at' => array( 'datetime', false, null ),
					'updated_at' => array( 'datetime', true, null ),
					'sent_at' => array( 'datetime', true, null ),
					'expires_at' => array( 'datetime', false, null ),
				),
				'indexes' => array(
					'PRIMARY' => array( true, array( 'id' ) ),
					'notification_key' => array( true, array( 'notification_key' ) ),
					'expires_at' => array( false, array( 'expires_at' ) ),
					'status_lease' => array( false, array( 'status', 'lease_until' ) ),
					'task_lookup' => array( false, array( 'task_id', 'notification_type' ) ),
				),
			),
			'migration_issues' => array(
				'columns' => array(
					'id' => array( 'bigint unsigned', false, null ),
					'migration_id' => array( 'varchar(100)', false, null ),
					'object_type' => array( 'varchar(50)', false, null ),
					'object_id' => array( 'bigint unsigned', false, null ),
					'error_code' => array( 'varchar(100)', false, null ),
					'last_error' => array( 'text', true, null ),
					'status' => array( 'varchar(20)', false, 'pending' ),
					'attempts' => array( 'int unsigned', false, '1' ),
					'created_at' => array( 'datetime', false, null ),
					'updated_at' => array( 'datetime', false, null ),
					'resolved_at' => array( 'datetime', true, null ),
				),
				'indexes' => array(
					'PRIMARY' => array( true, array( 'id' ) ),
					'migration_object' => array( true, array( 'migration_id', 'object_type', 'object_id' ) ),
					'retry_queue' => array( false, array( 'migration_id', 'status', 'id' ) ),
				),
			),
		);
	}


	public static function expected_table_keys(): array {
		return array(
			'customers',
			'events',
			'notes',
			'tasks',
			'tags',
			'customer_tags',
			'segments',
			'customer_segments',
			'order_facts',
			'notification_log',
			'migration_issues',
		);
	}

	private static function db_version(): string {
		return defined( 'YOOHW_COS_DB_VERSION' ) ? YOOHW_COS_DB_VERSION : '0.1.5';
	}

	private static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$customers_table         = $wpdb->prefix . 'yoohw_cos_customers';
		$events_table            = $wpdb->prefix . 'yoohw_cos_events';
		$notes_table             = $wpdb->prefix . 'yoohw_cos_notes';
		$tasks_table             = $wpdb->prefix . 'yoohw_cos_tasks';
		$tags_table              = $wpdb->prefix . 'yoohw_cos_tags';
		$customer_tags_table     = $wpdb->prefix . 'yoohw_cos_customer_tags';
		$segments_table          = $wpdb->prefix . 'yoohw_cos_segments';
		$customer_segments_table = $wpdb->prefix . 'yoohw_cos_customer_segments';
		$order_facts_table       = $wpdb->prefix . 'yoohw_cos_customer_order_facts';
		$notification_log_table  = $wpdb->prefix . 'yoohw_cos_notification_log';
		$migration_issues_table  = $wpdb->prefix . 'yoohw_cos_migration_issues';

		$sql_customers = "CREATE TABLE {$customers_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			wp_user_id BIGINT UNSIGNED NULL,
			email VARCHAR(191) NULL,
			phone VARCHAR(50) NULL,
			first_name VARCHAR(100) NULL,
			last_name VARCHAR(100) NULL,
			display_name VARCHAR(191) NULL,
			total_orders BIGINT UNSIGNED NOT NULL DEFAULT 0,
			total_spent DECIMAL(20,6) NOT NULL DEFAULT 0.000000,
			average_order_value DECIMAL(20,6) NOT NULL DEFAULT 0.000000,
			commerce_metrics_version SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			risk_score DECIMAL(5,2) NOT NULL DEFAULT 0.00,
			trust_score DECIMAL(5,2) NOT NULL DEFAULT 0.00,
			loyalty_score DECIMAL(5,2) NOT NULL DEFAULT 0.00,
			loyalty_level VARCHAR(100) NOT NULL DEFAULT '',
			available_points BIGINT NOT NULL DEFAULT 0,
			earned_points BIGINT NOT NULL DEFAULT 0,
			customer_status VARCHAR(50) NOT NULL DEFAULT 'active',
			vip_status VARCHAR(50) NOT NULL DEFAULT 'none',
			first_order_id BIGINT UNSIGNED NULL,
			first_order_date DATETIME NULL,
			last_order_id BIGINT UNSIGNED NULL,
			last_order_date DATETIME NULL,
			last_activity_date DATETIME NULL,
			lifecycle_stage VARCHAR(50) NOT NULL DEFAULT 'new',
			archived_at DATETIME NULL,
			archived_by BIGINT UNSIGNED NULL,
			archive_reason TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY wp_user_id (wp_user_id),
			KEY email (email),
			KEY phone (phone),
			KEY customer_status (customer_status),
			KEY vip_status (vip_status),
			KEY first_order_id (first_order_id),
			KEY first_order_date (first_order_date),
			KEY risk_score (risk_score),
			KEY trust_score (trust_score),
			KEY loyalty_score (loyalty_score),
			KEY loyalty_level (loyalty_level),
			KEY last_order_date (last_order_date),
			KEY last_activity_date (last_activity_date),
			KEY lifecycle_stage (lifecycle_stage),
			KEY archived_at (archived_at)
		) {$charset_collate};";

		$sql_events = "CREATE TABLE {$events_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			customer_id BIGINT UNSIGNED NULL,
			wp_user_id BIGINT UNSIGNED NULL,
			event_type VARCHAR(100) NOT NULL,
			event_source VARCHAR(100) NOT NULL DEFAULT 'system',
			severity VARCHAR(30) NOT NULL DEFAULT 'info',
			object_type VARCHAR(50) NULL,
			object_id BIGINT UNSIGNED NULL,
			description TEXT NULL,
			metadata_json LONGTEXT NULL,
			event_key VARCHAR(191) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY customer_id (customer_id),
			KEY wp_user_id (wp_user_id),
			KEY event_type (event_type),
			KEY event_source (event_source),
			KEY severity (severity),
			KEY object_lookup (object_type, object_id),
			UNIQUE KEY event_key (event_key),
			KEY created_at (created_at)
		) {$charset_collate};";

		$sql_notes = "CREATE TABLE {$notes_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			customer_id BIGINT UNSIGNED NOT NULL,
			wp_user_id BIGINT UNSIGNED NULL,
			author_id BIGINT UNSIGNED NULL,
			note_type VARCHAR(50) NOT NULL DEFAULT 'internal',
			note_content LONGTEXT NOT NULL,
			visibility VARCHAR(30) NOT NULL DEFAULT 'private',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY customer_id (customer_id),
			KEY wp_user_id (wp_user_id),
			KEY author_id (author_id),
			KEY note_type (note_type),
			KEY visibility (visibility),
			KEY created_at (created_at)
		) {$charset_collate};";

		$sql_tasks = "CREATE TABLE {$tasks_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			customer_id BIGINT UNSIGNED NOT NULL,
			order_id BIGINT UNSIGNED NULL,
			assigned_user_id BIGINT UNSIGNED NULL,
			created_by BIGINT UNSIGNED NULL,
			title VARCHAR(191) NOT NULL,
			description LONGTEXT NULL,
			source_key VARCHAR(191) NULL,
			status VARCHAR(30) NOT NULL DEFAULT 'open',
			priority VARCHAR(30) NOT NULL DEFAULT 'normal',
			due_date DATETIME NULL,
			completed_at DATETIME NULL,
			completed_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY customer_id (customer_id),
			KEY order_id (order_id),
			KEY assigned_user_id (assigned_user_id),
			KEY created_by (created_by),
			UNIQUE KEY source_key (source_key),
			KEY status (status),
			KEY priority (priority),
			KEY due_date (due_date),
			KEY completed_at (completed_at),
			KEY task_queue (status, due_date),
			KEY notification_queue (assigned_user_id, status, due_date, id)
		) {$charset_collate};";

		$sql_tags = "CREATE TABLE {$tags_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(100) NOT NULL,
			slug VARCHAR(120) NOT NULL,
			color VARCHAR(20) NULL,
			description TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY name (name)
		) {$charset_collate};";

		$sql_customer_tags = "CREATE TABLE {$customer_tags_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			customer_id BIGINT UNSIGNED NOT NULL,
			tag_id BIGINT UNSIGNED NOT NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY customer_tag (customer_id, tag_id),
			KEY customer_id (customer_id),
			KEY tag_id (tag_id)
		) {$charset_collate};";

		$sql_segments = "CREATE TABLE {$segments_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(100) NOT NULL,
			slug VARCHAR(120) NOT NULL,
			segment_type VARCHAR(50) NOT NULL DEFAULT 'static',
			description TEXT NULL,
			rules_json LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY segment_type (segment_type)
		) {$charset_collate};";

		$sql_customer_segments = "CREATE TABLE {$customer_segments_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			customer_id BIGINT UNSIGNED NOT NULL,
			segment_id BIGINT UNSIGNED NOT NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY customer_segment (customer_id, segment_id),
			KEY customer_id (customer_id),
			KEY segment_id (segment_id)
		) {$charset_collate};";

		$sql_order_facts = "CREATE TABLE {$order_facts_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL,
			customer_id BIGINT UNSIGNED NOT NULL,
			order_status VARCHAR(30) NOT NULL,
			order_total DECIMAL(20,6) NOT NULL DEFAULT 0.000000,
			revenue_amount DECIMAL(20,6) NOT NULL DEFAULT 0.000000,
			counts_as_order TINYINT(1) NOT NULL DEFAULT 0,
			counts_as_revenue TINYINT(1) NOT NULL DEFAULT 0,
			order_date DATETIME NOT NULL,
			policy_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY order_id (order_id),
			KEY customer_order (customer_id, order_date, order_id),
			KEY contribution (customer_id, counts_as_order)
		) {$charset_collate};";

		$sql_notification_log = "CREATE TABLE {$notification_log_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			notification_key VARCHAR(191) NOT NULL,
			notification_type VARCHAR(100) NOT NULL,
			task_id BIGINT UNSIGNED NULL,
			recipient_user_id BIGINT UNSIGNED NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			claim_token VARCHAR(64) NULL,
			lease_until DATETIME NULL,
			attempts INT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			sent_at DATETIME NULL,
			expires_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY notification_key (notification_key),
			KEY expires_at (expires_at),
			KEY status_lease (status, lease_until),
			KEY task_lookup (task_id, notification_type)
		) {$charset_collate};";

		$sql_migration_issues = "CREATE TABLE {$migration_issues_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			migration_id VARCHAR(100) NOT NULL,
			object_type VARCHAR(50) NOT NULL,
			object_id BIGINT UNSIGNED NOT NULL,
			error_code VARCHAR(100) NOT NULL,
			last_error TEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			attempts INT UNSIGNED NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			resolved_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY migration_object (migration_id, object_type, object_id),
			KEY retry_queue (migration_id, status, id)
		) {$charset_collate};";

		dbDelta( $sql_customers );
		dbDelta( $sql_events );
		dbDelta( $sql_notes );
		dbDelta( $sql_tasks );
		dbDelta( $sql_tags );
		dbDelta( $sql_customer_tags );
		dbDelta( $sql_segments );
		dbDelta( $sql_customer_segments );
		dbDelta( $sql_order_facts );
		dbDelta( $sql_notification_log );
		dbDelta( $sql_migration_issues );
	}

	public static function maybe_update(): void {
		$current_db_version = (string) get_option( 'yoohw_cos_db_version', '' );
		if ( version_compare( $current_db_version, self::db_version(), '<' ) || ! self::schema_is_ready() ) {
			self::upgrade_schema( $current_db_version );
		}
	}

	private static function add_first_order_columns(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'yoohw_cos_customers';

		$first_order_id_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW COLUMNS FROM %i LIKE %s',
				$table,
				'first_order_id'
			)
		);

		if ( empty( $first_order_id_exists ) && self::table_exists( $table ) ) {
			self::execute_ensure_ddl(
				$wpdb->prepare(
					'ALTER TABLE %i ADD first_order_id BIGINT UNSIGNED NULL AFTER vip_status',
					$table
				)
			);
		}

		self::maybe_add_index( $table, 'first_order_id', 'first_order_id' );

		$first_order_date_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW COLUMNS FROM %i LIKE %s',
				$table,
				'first_order_date'
			)
		);

		if ( empty( $first_order_date_exists ) && self::table_exists( $table ) ) {
			self::execute_ensure_ddl(
				$wpdb->prepare(
					'ALTER TABLE %i ADD first_order_date DATETIME NULL AFTER first_order_id',
					$table
				)
			);
		}

		self::maybe_add_index( $table, 'first_order_date', 'first_order_date' );
	}

	private static function add_lifecycle_stage_column(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'yoohw_cos_customers';

		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW COLUMNS FROM %i LIKE %s',
				$table,
				'lifecycle_stage'
			)
		);

		if ( empty( $exists ) && self::table_exists( $table ) ) {
			self::execute_ensure_ddl(
				$wpdb->prepare(
					"ALTER TABLE %i ADD lifecycle_stage VARCHAR(50) NOT NULL DEFAULT 'new' AFTER last_activity_date",
					$table
				)
			);
		}

		self::maybe_add_index( $table, 'lifecycle_stage', 'lifecycle_stage' );
	}

	private static function add_segments_tables(): void {
		self::create_tables();
	}

	private static function add_archive_columns(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'yoohw_cos_customers';

		$archived_at_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW COLUMNS FROM %i LIKE %s',
				$table,
				'archived_at'
			)
		);

		if ( empty( $archived_at_exists ) && self::table_exists( $table ) ) {
			self::execute_ensure_ddl(
				$wpdb->prepare(
					'ALTER TABLE %i ADD archived_at DATETIME NULL AFTER lifecycle_stage',
					$table
				)
			);
		}

		$archived_by_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW COLUMNS FROM %i LIKE %s',
				$table,
				'archived_by'
			)
		);

		if ( empty( $archived_by_exists ) && self::table_exists( $table ) ) {
			self::execute_ensure_ddl(
				$wpdb->prepare(
					'ALTER TABLE %i ADD archived_by BIGINT UNSIGNED NULL AFTER archived_at',
					$table
				)
			);
		}

		$archive_reason_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW COLUMNS FROM %i LIKE %s',
				$table,
				'archive_reason'
			)
		);

		if ( empty( $archive_reason_exists ) && self::table_exists( $table ) ) {
			self::execute_ensure_ddl(
				$wpdb->prepare(
					'ALTER TABLE %i ADD archive_reason TEXT NULL AFTER archived_by',
					$table
				)
			);
		}

		self::maybe_add_index( $table, 'archived_at', 'archived_at' );
	}

	private static function add_loyalty_columns(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'yoohw_cos_customers';

		$loyalty_level_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW COLUMNS FROM %i LIKE %s',
				$table,
				'loyalty_level'
			)
		);

		if ( empty( $loyalty_level_exists ) && self::table_exists( $table ) ) {
			self::execute_ensure_ddl(
				$wpdb->prepare(
					"ALTER TABLE %i ADD loyalty_level VARCHAR(100) NOT NULL DEFAULT '' AFTER loyalty_score",
					$table
				)
			);
		}

		$available_points_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW COLUMNS FROM %i LIKE %s',
				$table,
				'available_points'
			)
		);

		if ( empty( $available_points_exists ) && self::table_exists( $table ) ) {
			self::execute_ensure_ddl(
				$wpdb->prepare(
					'ALTER TABLE %i ADD available_points BIGINT NOT NULL DEFAULT 0 AFTER loyalty_level',
					$table
				)
			);
		}

		$earned_points_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW COLUMNS FROM %i LIKE %s',
				$table,
				'earned_points'
			)
		);

		if ( empty( $earned_points_exists ) && self::table_exists( $table ) ) {
			self::execute_ensure_ddl(
				$wpdb->prepare(
					'ALTER TABLE %i ADD earned_points BIGINT NOT NULL DEFAULT 0 AFTER available_points',
					$table
				)
			);
		}

		self::maybe_add_index( $table, 'loyalty_level', 'loyalty_level' );
	}

	private static function ensure_customer_schema(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'yoohw_cos_customers';

		if ( ! self::table_exists( $table ) ) {
			return;
		}

		self::add_first_order_columns();
		self::add_lifecycle_stage_column();
		self::add_archive_columns();
		self::add_loyalty_columns();

		$metrics_version_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW COLUMNS FROM %i LIKE %s',
				$table,
				'commerce_metrics_version'
			)
		);

		if ( empty( $metrics_version_exists ) ) {
			self::execute_ensure_ddl(
				$wpdb->prepare(
					'ALTER TABLE %i ADD commerce_metrics_version SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER average_order_value',
					$table
				)
			);
		}

		self::maybe_add_index( $table, 'last_activity_date', 'last_activity_date' );
		self::maybe_add_index( $table, 'lifecycle_stage', 'lifecycle_stage' );
		self::maybe_add_index( $table, 'loyalty_score', 'loyalty_score' );
		self::maybe_add_index( $table, 'loyalty_level', 'loyalty_level' );
		self::maybe_add_index( $table, 'archived_at', 'archived_at' );
	}

	private static function ensure_task_schema(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'yoohw_cos_tasks';

		if ( ! self::table_exists( $table ) ) {
			return;
		}

		$source_key_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW COLUMNS FROM %i LIKE %s',
				$table,
				'source_key'
			)
		);

		if ( empty( $source_key_exists ) ) {
			self::execute_ensure_ddl(
				$wpdb->prepare(
					'ALTER TABLE %i ADD source_key VARCHAR(191) NULL AFTER description',
					$table
				)
			);
		}

		self::maybe_add_unique_index( $table, 'source_key', 'source_key' );
	}

	private static function ensure_event_schema(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'yoohw_cos_events';

		if ( ! self::table_exists( $table ) ) {
			return;
		}

		$event_key_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'event_key' )
		);

		if ( empty( $event_key_exists ) ) {
			self::execute_ensure_ddl(
				$wpdb->prepare( 'ALTER TABLE %i ADD event_key VARCHAR(191) NULL AFTER metadata_json', $table )
			);
		}

		$index_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, 'event_key' )
		);

		if ( empty( $index_exists ) ) {
			self::execute_ensure_ddl(
				$wpdb->prepare( 'ALTER TABLE %i ADD UNIQUE KEY %i (%i)', $table, 'event_key', 'event_key' )
			);
		}
	}

	private static function table_exists( string $table ): bool {
		global $wpdb;

		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$wpdb->esc_like( $table )
			)
		);

		return $exists === $table;
	}

	private static function maybe_add_index( string $table, string $index_name, string $column_name ): void {
		global $wpdb;

		if ( ! self::table_exists( $table ) ) {
			return;
		}

		$allowed_indexes = array(
			'first_order_id'     => 'first_order_id',
			'first_order_date'   => 'first_order_date',
			'last_activity_date' => 'last_activity_date',
			'lifecycle_stage'    => 'lifecycle_stage',
			'loyalty_score'      => 'loyalty_score',
			'loyalty_level'      => 'loyalty_level',
			'archived_at'        => 'archived_at',
		);

		if ( ! isset( $allowed_indexes[ $index_name ] ) || $allowed_indexes[ $index_name ] !== $column_name ) {
			return;
		}

		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW INDEX FROM %i WHERE Key_name = %s',
				$table,
				$index_name
			)
		);

		if ( empty( $exists ) ) {
			self::execute_ensure_ddl(
				$wpdb->prepare(
					'ALTER TABLE %i ADD KEY %i (%i)',
					$table,
					$index_name,
					$column_name
				)
			);
		}
	}

	private static function maybe_add_unique_index( string $table, string $index_name, string $column_name ): void {
		global $wpdb;

		if ( ! self::table_exists( $table ) ) {
			return;
		}

		if ( 'source_key' !== $index_name || 'source_key' !== $column_name ) {
			return;
		}

		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW INDEX FROM %i WHERE Key_name = %s',
				$table,
				$index_name
			)
		);

		if ( empty( $exists ) ) {
			self::execute_ensure_ddl(
				$wpdb->prepare(
					'ALTER TABLE %i ADD UNIQUE KEY %i (%i)',
					$table,
					$index_name,
					$column_name
				)
			);
		}
	}
}
