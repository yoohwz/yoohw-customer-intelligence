<?php
defined( 'ABSPATH' ) || exit;

final class YoOhw_COS_Install {

	public static function install(): void {
		self::create_tables();
		self::ensure_customer_schema();

		update_option( 'yoohw_cos_version', YOOHW_COS_VERSION );
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
			risk_score DECIMAL(5,2) NOT NULL DEFAULT 0.00,
			trust_score DECIMAL(5,2) NOT NULL DEFAULT 0.00,
			loyalty_score DECIMAL(5,2) NOT NULL DEFAULT 0.00,
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
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY customer_id (customer_id),
			KEY wp_user_id (wp_user_id),
			KEY event_type (event_type),
			KEY event_source (event_source),
			KEY severity (severity),
			KEY object_lookup (object_type, object_id),
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
			KEY status (status),
			KEY priority (priority),
			KEY due_date (due_date),
			KEY completed_at (completed_at),
			KEY task_queue (status, due_date)
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

		dbDelta( $sql_customers );
		dbDelta( $sql_events );
		dbDelta( $sql_notes );
		dbDelta( $sql_tasks );
		dbDelta( $sql_tags );
		dbDelta( $sql_customer_tags );
		dbDelta( $sql_segments );
		dbDelta( $sql_customer_segments );
	}

	public static function maybe_update(): void {
		$current_db_version = get_option( 'yoohw_cos_db_version', '' );

		if ( version_compare( $current_db_version, self::db_version(), '<' ) ) {
			self::create_tables();
			self::ensure_customer_schema();
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

	private static function ensure_customer_schema(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'yoohw_cos_customers';

		if ( ! self::table_exists( $table ) ) {
			return;
		}

		self::add_first_order_columns();
		self::add_lifecycle_stage_column();
		self::add_archive_columns();

		self::maybe_add_index( $table, 'last_activity_date', 'last_activity_date' );
		self::maybe_add_index( $table, 'lifecycle_stage', 'lifecycle_stage' );
		self::maybe_add_index( $table, 'archived_at', 'archived_at' );
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
}
