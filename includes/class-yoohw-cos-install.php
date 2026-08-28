<?php
defined( 'ABSPATH' ) || exit;

final class YoOhw_COS_Install {

	public static function install(): void {
		self::create_tables();
		self::ensure_customer_schema();
		self::ensure_task_schema();
		self::ensure_event_schema();

		update_option( 'yoohw_cos_version', YOOHW_COS_VERSION );
		YoOhw_COS_Migration_Runner::register_upgrade( '', self::db_version() );
		update_option( 'yoohw_cos_db_version', self::db_version() );
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
			created_at DATETIME NOT NULL,
			sent_at DATETIME NULL,
			expires_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY notification_key (notification_key),
			KEY expires_at (expires_at),
			KEY task_lookup (task_id, notification_type)
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
	}

	public static function maybe_update(): void {
		$current_db_version = get_option( 'yoohw_cos_db_version', '' );

		if ( version_compare( $current_db_version, self::db_version(), '<' ) ) {
			self::create_tables();
			self::ensure_customer_schema();
			self::ensure_task_schema();
			self::ensure_event_schema();
			YoOhw_COS_Migration_Runner::register_upgrade( $current_db_version, self::db_version() );
			update_option( 'yoohw_cos_db_version', self::db_version() );
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
			$wpdb->query(
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
			$wpdb->query(
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
			$wpdb->query(
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
			$wpdb->query(
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
			$wpdb->query(
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
			$wpdb->query(
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
			$wpdb->query(
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
			$wpdb->query(
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
			$wpdb->query(
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
			$wpdb->query(
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
			$wpdb->query(
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
			$wpdb->query(
				$wpdb->prepare( 'ALTER TABLE %i ADD event_key VARCHAR(191) NULL AFTER metadata_json', $table )
			);
		}

		$index_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, 'event_key' )
		);

		if ( empty( $index_exists ) ) {
			$wpdb->query(
				$wpdb->prepare( 'ALTER TABLE %i ADD UNIQUE KEY %i (%i)', $table, 'event_key', 'event_key' )
			);
		}
	}

	private static function table_exists( string $table ): bool {
		global $wpdb;

		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$table
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
			$wpdb->query(
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
			$wpdb->query(
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
