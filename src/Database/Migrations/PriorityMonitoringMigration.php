<?php
/**
 * Priority Monitoring Migration
 *
 * Creates database tables and modifies existing tables for priority monitoring feature.
 *
 * @package EightyFourEM\FileIntegrityChecker\Database\Migrations
 */

namespace EightyFourEM\FileIntegrityChecker\Database\Migrations;

/**
 * Priority Monitoring Migration
 */
class PriorityMonitoringMigration {
	/**
	 * Table names
	 */
	private const TABLE_PRIORITY_RULES = 'eightyfourem_integrity_priority_rules';
	private const TABLE_VELOCITY_LOG = 'eightyfourem_integrity_velocity_log';
	private const TABLE_FILE_RECORDS = 'eightyfourem_integrity_file_records';
	private const TABLE_SCAN_RESULTS = 'eightyfourem_integrity_scan_results';

	/**
	 * Migration version
	 */
	const VERSION = '1.0.0';

	/**
	 * Run the migration
	 */
	public function up(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Create priority rules table
		$this->createPriorityRulesTable( $charset_collate );

		// Create velocity log table
		$this->createVelocityLogTable( $charset_collate );

		// Modify existing tables
		$this->modifyFileRecordsTable();
		$this->modifyScanResultsTable();

		// Update database version
		update_option( 'eightyfourem_integrity_db_version', self::VERSION );
	}

	/**
	 * Rollback the migration
	 */
	public function down(): void {
		global $wpdb;

		$priority_rules_table = $wpdb->prefix . self::TABLE_PRIORITY_RULES;
		$velocity_log_table = $wpdb->prefix . self::TABLE_VELOCITY_LOG;
		$file_records_table = $wpdb->prefix . self::TABLE_FILE_RECORDS;
		$scan_results_table = $wpdb->prefix . self::TABLE_SCAN_RESULTS;

		// Drop new tables
		$wpdb->query( "DROP TABLE IF EXISTS $velocity_log_table" );
		$wpdb->query( "DROP TABLE IF EXISTS $priority_rules_table" );

		// Remove added columns (note: ALTER TABLE DROP COLUMN not always safe in production)
		// These are commented out for safety - manual intervention may be required
		// $wpdb->query( "ALTER TABLE $file_records_table DROP COLUMN IF EXISTS priority_level" );
		// $wpdb->query( "ALTER TABLE $scan_results_table DROP COLUMN IF EXISTS priority_stats" );
	}

	/**
	 * Create priority rules table
	 *
	 * @param string $charset_collate Charset collation
	 */
	private function createPriorityRulesTable( string $charset_collate ): void {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_PRIORITY_RULES;

		$sql = "CREATE TABLE $table_name (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			path varchar(500) NOT NULL,
			path_type enum('file', 'directory', 'pattern') NOT NULL DEFAULT 'file',
			priority_level enum('critical', 'high', 'normal') NOT NULL DEFAULT 'high',
			match_type enum('exact', 'prefix', 'suffix', 'contains', 'regex', 'glob') NOT NULL DEFAULT 'exact',
			reason text DEFAULT NULL,
			notify_immediately tinyint(1) NOT NULL DEFAULT 0,
			ignore_in_bulk_changes tinyint(1) NOT NULL DEFAULT 0,

			-- Maintenance Windows
			maintenance_window_start datetime DEFAULT NULL,
			maintenance_window_end datetime DEFAULT NULL,
			suppress_during_maintenance tinyint(1) NOT NULL DEFAULT 0,

			-- Change Velocity
			change_velocity_threshold int DEFAULT NULL,
			velocity_window_hours int DEFAULT 24,

			-- Priority Inheritance
			inherit_priority tinyint(1) NOT NULL DEFAULT 0,
			parent_rule_id bigint(20) UNSIGNED DEFAULT NULL,

			-- Rule Ordering
			execution_order int NOT NULL DEFAULT 100,

			-- Notification Throttling
			throttle_minutes int DEFAULT NULL,
			max_notifications_per_day int DEFAULT NULL,
			last_notification_sent datetime DEFAULT NULL,
			notifications_sent_today int NOT NULL DEFAULT 0,
			notification_count_reset_date date DEFAULT NULL,

			-- WordPress Version Specific
			wp_version_min varchar(20) DEFAULT NULL,
			wp_version_max varchar(20) DEFAULT NULL,

			-- Metadata
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			created_by bigint(20) UNSIGNED DEFAULT NULL,
			updated_at datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
			is_active tinyint(1) NOT NULL DEFAULT 1,

			PRIMARY KEY (id),
			KEY idx_path (path(191)),
			KEY idx_path_type (path_type),
			KEY idx_priority (priority_level),
			KEY idx_active (is_active),
			KEY idx_composite (is_active, priority_level, path_type),
			KEY idx_execution (execution_order),
			KEY idx_parent (parent_rule_id),
			KEY idx_wp_version (wp_version_min, wp_version_max),
			CONSTRAINT fk_parent_rule FOREIGN KEY (parent_rule_id)
				REFERENCES $table_name(id) ON DELETE SET NULL
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Create velocity log table
	 *
	 * @param string $charset_collate Charset collation
	 */
	private function createVelocityLogTable( string $charset_collate ): void {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_VELOCITY_LOG;
		$priority_rules_table = $wpdb->prefix . self::TABLE_PRIORITY_RULES;

		$sql = "CREATE TABLE $table_name (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			rule_id bigint(20) UNSIGNED NOT NULL,
			file_path varchar(500) NOT NULL,
			change_detected_at datetime NOT NULL,
			scan_id bigint(20) UNSIGNED NOT NULL,
			change_type varchar(20) NOT NULL DEFAULT 'modified',

			PRIMARY KEY (id),
			KEY idx_rule_file (rule_id, file_path(191)),
			KEY idx_detected_at (change_detected_at),
			KEY idx_scan (scan_id),
			KEY idx_composite (rule_id, change_detected_at),
			CONSTRAINT fk_rule FOREIGN KEY (rule_id)
				REFERENCES $priority_rules_table(id) ON DELETE CASCADE
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Modify file records table to add priority level
	 */
	private function modifyFileRecordsTable(): void {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_FILE_RECORDS;

		// Check if column already exists
		$column_exists = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
				WHERE TABLE_SCHEMA = %s
				AND TABLE_NAME = %s
				AND COLUMN_NAME = 'priority_level'",
				DB_NAME,
				$table_name
			)
		);

		if ( empty( $column_exists ) ) {
			$wpdb->query(
				"ALTER TABLE $table_name
				ADD COLUMN priority_level enum('critical', 'high', 'normal') DEFAULT NULL AFTER is_sensitive,
				ADD KEY idx_priority (priority_level)"
			);
		}
	}

	/**
	 * Modify scan results table to add priority statistics
	 */
	private function modifyScanResultsTable(): void {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_SCAN_RESULTS;

		// Check if column already exists
		$column_exists = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
				WHERE TABLE_SCHEMA = %s
				AND TABLE_NAME = %s
				AND COLUMN_NAME = 'priority_stats'",
				DB_NAME,
				$table_name
			)
		);

		if ( empty( $column_exists ) ) {
			$wpdb->query(
				"ALTER TABLE $table_name
				ADD COLUMN priority_stats JSON DEFAULT NULL AFTER notes,
				ADD COLUMN critical_files_changed int(11) UNSIGNED NOT NULL DEFAULT 0 AFTER priority_stats,
				ADD COLUMN high_priority_files_changed int(11) UNSIGNED NOT NULL DEFAULT 0 AFTER critical_files_changed"
			);
		}
	}

	/**
	 * Check if migration has been run
	 *
	 * @return bool
	 */
	public static function isApplied(): bool {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_PRIORITY_RULES;
		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" );

		return $table_exists === $table_name;
	}

	/**
	 * Get migration version
	 *
	 * @return string
	 */
	public static function getVersion(): string {
		return self::VERSION;
	}
}
