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
    private const TABLE_FILE_CONTENT = 'eightyfourem_integrity_file_content'; // To be removed after migration
    private const TABLE_CHECKSUM_CACHE = 'eightyfourem_integrity_checksum_cache';
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
            is_sensitive tinyint(1) NOT NULL DEFAULT 0,
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
            day_of_week varchar(100) DEFAULT NULL,
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

        // Legacy table for file content - to be removed after migration
        $file_content_sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}" . self::TABLE_FILE_CONTENT . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            checksum varchar(64) NOT NULL,
            content longblob NOT NULL,
            file_size int unsigned NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY checksum (checksum),
            KEY created_at (created_at)
        ) $charset_collate;";

        // New checksum cache table for temporary content storage
        $checksum_cache_table = $wpdb->prefix . self::TABLE_CHECKSUM_CACHE;
        $checksum_cache_sql = "CREATE TABLE $checksum_cache_table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            file_path varchar(500) NOT NULL,
            checksum varchar(64) NOT NULL,
            file_content longblob NOT NULL,
            is_sensitive tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY file_checksum (file_path(191), checksum),
            KEY expires_at (expires_at),
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
        dbDelta( $file_content_sql ); // Legacy table
        dbDelta( $checksum_cache_sql );
        dbDelta( $logs_sql );

        // Run migration if needed
        $this->migrateExistingData();

        // Set database version
        update_option( 'eightyfourem_file_integrity_db_version', EIGHTYFOUREM_FILE_INTEGRITY_CHECKER_VERSION );
    }

    /**
     * Check database version and upgrade if needed
     */
    public function checkDatabaseVersion(): void {
        $installed_version = get_option( 'eightyfourem_file_integrity_db_version', '0.0.0' );
        
        if ( version_compare( $installed_version, EIGHTYFOUREM_FILE_INTEGRITY_CHECKER_VERSION, '<' ) ) {
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
        $checksum_cache_table = $wpdb->prefix . self::TABLE_CHECKSUM_CACHE;
        $logs_table = $wpdb->prefix . self::TABLE_LOGS;

        // Drop tables in reverse order due to foreign key constraints
        $wpdb->query( "DROP TABLE IF EXISTS $file_records_table" );
        $wpdb->query( "DROP TABLE IF EXISTS $file_content_table" );
        $wpdb->query( "DROP TABLE IF EXISTS $checksum_cache_table" );
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

    /**
     * Migrate existing data from old structure to new
     */
    private function migrateExistingData(): void {
        global $wpdb;

        // Check if migration has already been done
        $migration_done = get_option( 'eightyfourem_file_integrity_diff_migration_complete', false );
        if ( $migration_done ) {
            return;
        }

        // Check if old file_content table exists and has data
        $file_content_table = $wpdb->prefix . self::TABLE_FILE_CONTENT;
        $has_content = $wpdb->get_var( "SELECT COUNT(*) FROM $file_content_table" );

        if ( $has_content > 0 ) {
            // Generate diffs for existing records that don't have them
            $file_records_table = $wpdb->prefix . self::TABLE_FILE_RECORDS;

            // Get records that need diff generation
            $records_needing_diffs = $wpdb->get_results(
                "SELECT fr.id, fr.file_path, fr.checksum, fr.previous_checksum, fr.status
                FROM $file_records_table fr
                WHERE fr.status = 'changed'
                AND fr.diff_content IS NULL
                AND fr.previous_checksum IS NOT NULL
                LIMIT 100"
            );

            // Process records in batches
            foreach ( $records_needing_diffs as $record ) {
                $this->generateAndStoreDiff( $record );
            }

            // Schedule cleanup of old file_content table after 30 days
            if ( function_exists( 'as_schedule_single_action' ) ) {
                as_schedule_single_action(
                    time() + ( 30 * DAY_IN_SECONDS ),
                    'file_integrity_cleanup_legacy_content_table',
                    [],
                    'file-integrity-checker'
                );
            }
        }

        // Mark migration as complete
        update_option( 'eightyfourem_file_integrity_diff_migration_complete', true );
    }

    /**
     * Generate and store diff for a single record during migration
     *
     * @param object $record File record needing diff
     */
    private function generateAndStoreDiff( $record ): void {
        global $wpdb;

        // Skip if we can't generate a diff
        if ( ! file_exists( $record->file_path ) ) {
            return;
        }

        $file_content_table = $wpdb->prefix . self::TABLE_FILE_CONTENT;
        $file_records_table = $wpdb->prefix . self::TABLE_FILE_RECORDS;

        // Get previous content from old table
        $previous_content = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT content FROM $file_content_table WHERE checksum = %s",
                $record->previous_checksum
            )
        );

        if ( $previous_content ) {
            // Decompress content
            $previous_content = gzuncompress( $previous_content );

            if ( $previous_content !== false ) {
                // Get current content
                $current_content = file_get_contents( $record->file_path );

                if ( $current_content !== false ) {
                    // Generate diff
                    $diff_generator = new \EightyFourEM\FileIntegrityChecker\Utils\DiffGenerator();
                    $diff = $diff_generator->generateUnifiedDiff(
                        $previous_content,
                        $current_content,
                        $record->file_path
                    );

                    // Store diff
                    $wpdb->update(
                        $file_records_table,
                        [ 'diff_content' => $diff ],
                        [ 'id' => $record->id ],
                        [ '%s' ],
                        [ '%d' ]
                    );
                }
            }
        }
    }
}