<?php
/**
 * Database Manager
 *
 * @package EightyFourEM\FileIntegrityChecker\Database
 */

namespace EightyFourEM\FileIntegrityChecker\Database;

/**
 * Manages database schema creation and updates
 */
class DatabaseManager {
    /**
     * Table names
     */
    private const TABLE_SCAN_RESULTS = 'eightyfourem_integrity_scan_results';
    private const TABLE_FILE_RECORDS = 'eightyfourem_integrity_file_records';
    private const TABLE_SCAN_SCHEDULES = 'eightyfourem_integrity_scan_schedules';
    private const TABLE_FILE_CONTENT = 'eightyfourem_integrity_file_content';
    private const TABLE_LOGS = 'eightyfourem_integrity_logs';

    /**
     * Initialize database tables
     */
    public function init(): void {
        // Hook for database upgrades
        add_action( 'init', [ $this, 'checkDatabaseVersion' ] );
    }

    /**
     * Create database tables
     */
    public function createTables(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Scan results table
        $scan_results_table = $wpdb->prefix . self::TABLE_SCAN_RESULTS;
        $scan_results_sql = "CREATE TABLE $scan_results_table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            scan_date datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            status varchar(20) NOT NULL DEFAULT 'running',
            total_files int(11) UNSIGNED NOT NULL DEFAULT 0,
            changed_files int(11) UNSIGNED NOT NULL DEFAULT 0,
            new_files int(11) UNSIGNED NOT NULL DEFAULT 0,
            deleted_files int(11) UNSIGNED NOT NULL DEFAULT 0,
            scan_duration int(11) UNSIGNED NOT NULL DEFAULT 0,
            memory_usage bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            scan_type varchar(20) NOT NULL DEFAULT 'manual',
            schedule_id bigint(20) UNSIGNED DEFAULT NULL,
            notes text,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY scan_date (scan_date),
            KEY status (status),
            KEY scan_type (scan_type),
            KEY schedule_id (schedule_id)
        ) $charset_collate;";

        // File records table
        $file_records_table = $wpdb->prefix . self::TABLE_FILE_RECORDS;
        $file_records_sql = "CREATE TABLE $file_records_table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            scan_result_id bigint(20) UNSIGNED NOT NULL,
            file_path varchar(500) NOT NULL,
            file_size bigint(20) UNSIGNED NOT NULL,
            checksum varchar(64) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'unchanged',
            previous_checksum varchar(64) DEFAULT NULL,
            diff_content LONGTEXT DEFAULT NULL,
            last_modified datetime NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY scan_result_id (scan_result_id),
            KEY file_path (file_path(191)),
            KEY checksum (checksum),
            KEY status (status),
            KEY last_modified (last_modified)
        ) $charset_collate;";

        // Scan schedules table
        $scan_schedules_table = $wpdb->prefix . self::TABLE_SCAN_SCHEDULES;
        $scan_schedules_sql = "CREATE TABLE $scan_schedules_table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL,
            frequency varchar(20) NOT NULL,
            time time DEFAULT NULL,
            day_of_week tinyint(1) DEFAULT NULL,
            day_of_month tinyint(2) DEFAULT NULL,
            hour tinyint(2) DEFAULT NULL,
            minute tinyint(2) DEFAULT NULL,
            timezone varchar(50) NOT NULL DEFAULT 'UTC',
            is_active tinyint(1) NOT NULL DEFAULT 1,
            last_run datetime DEFAULT NULL,
            next_run datetime DEFAULT NULL,
            action_scheduler_id bigint(20) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY frequency (frequency),
            KEY is_active (is_active),
            KEY next_run (next_run)
        ) $charset_collate;";

        // Table for storing file content for diff generation
        $file_content_sql = "CREATE TABLE {$wpdb->prefix}" . self::TABLE_FILE_CONTENT . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            checksum varchar(64) NOT NULL,
            content longblob NOT NULL,
            file_size int unsigned NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY checksum (checksum),
            KEY created_at (created_at)
        ) $charset_collate;";

        // Logs table
        $logs_table = $wpdb->prefix . self::TABLE_LOGS;
        $logs_sql = "CREATE TABLE $logs_table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            log_level varchar(20) NOT NULL DEFAULT 'info',
            context varchar(50) NOT NULL DEFAULT 'general',
            message text NOT NULL,
            data longtext DEFAULT NULL,
            user_id bigint(20) UNSIGNED DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY log_level (log_level),
            KEY context (context),
            KEY user_id (user_id),
            KEY created_at (created_at),
            KEY level_context (log_level, context),
            KEY context_created (context, created_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        
        dbDelta( $scan_results_sql );
        dbDelta( $file_records_sql );
        dbDelta( $scan_schedules_sql );
        dbDelta( $file_content_sql );
        dbDelta( $logs_sql );

        // Set database version
        update_option( 'eightyfourem_file_integrity_db_version', '1.5.0' );
    }

    /**
     * Check database version and upgrade if needed
     */
    public function checkDatabaseVersion(): void {
        $installed_version = get_option( 'eightyfourem_file_integrity_db_version', '0.0.0' );
        
        if ( version_compare( $installed_version, '1.5.0', '<' ) ) {
            $this->createTables();
        }
    }

    /**
     * Drop database tables (used for uninstall)
     */
    public function dropTables(): void {
        global $wpdb;

        $file_records_table = $wpdb->prefix . self::TABLE_FILE_RECORDS;
        $scan_results_table = $wpdb->prefix . self::TABLE_SCAN_RESULTS;
        $scan_schedules_table = $wpdb->prefix . self::TABLE_SCAN_SCHEDULES;
        $file_content_table = $wpdb->prefix . self::TABLE_FILE_CONTENT;
        $logs_table = $wpdb->prefix . self::TABLE_LOGS;

        // Drop tables in reverse order due to foreign key constraints
        $wpdb->query( "DROP TABLE IF EXISTS $file_records_table" );
        $wpdb->query( "DROP TABLE IF EXISTS $file_content_table" );
        $wpdb->query( "DROP TABLE IF EXISTS $scan_schedules_table" );
        $wpdb->query( "DROP TABLE IF EXISTS $scan_results_table" );
        $wpdb->query( "DROP TABLE IF EXISTS $logs_table" );

        // Remove options
        delete_option( 'eightyfourem_file_integrity_db_version' );
    }

    /**
     * Get scan results table name
     *
     * @return string
     */
    public function getScanResultsTableName(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SCAN_RESULTS;
    }

    /**
     * Get file records table name
     *
     * @return string
     */
    public function getFileRecordsTableName(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_FILE_RECORDS;
    }

    /**
     * Get scan schedules table name
     *
     * @return string
     */
    public function getScanSchedulesTableName(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SCAN_SCHEDULES;
    }
}