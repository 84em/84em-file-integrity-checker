<?php
/**
 * Tiered Retention Migration
 *
 * @package EightyFourEM\FileIntegrityChecker\Database\Migrations
 */

namespace EightyFourEM\FileIntegrityChecker\Database\Migrations;

/**
 * Migration to add is_baseline column for tiered retention
 */
class TieredRetentionMigration {
    /**
     * Migration version
     */
    private const MIGRATION_VERSION = '2.3.0';

    /**
     * Option key for tracking migration status
     */
    private const MIGRATION_OPTION = 'eightyfourem_file_integrity_tiered_retention_migration';

    /**
     * Check if migration has been applied
     *
     * @return bool True if already applied, false otherwise
     */
    public static function isApplied(): bool {
        return (bool) get_option( self::MIGRATION_OPTION, false );
    }

    /**
     * Run the migration
     *
     * @return bool True on success, false on failure
     */
    public function up(): bool {
        global $wpdb;

        $scan_results_table = $wpdb->prefix . 'eightyfourem_integrity_scan_results';

        // Check if column already exists
        $column_exists = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = %s
                AND TABLE_NAME = %s
                AND COLUMN_NAME = 'is_baseline'",
                DB_NAME,
                $scan_results_table
            )
        );

        if ( empty( $column_exists ) ) {
            // Add is_baseline column
            $sql = "ALTER TABLE {$scan_results_table}
                    ADD COLUMN is_baseline TINYINT(1) NOT NULL DEFAULT 0 AFTER notes,
                    ADD INDEX idx_is_baseline (is_baseline)";

            $wpdb->query( $sql );

            // Check if column was added successfully
            if ( $wpdb->last_error ) {
                error_log( 'Tiered Retention Migration failed: ' . $wpdb->last_error );
                return false;
            }
        }

        // Automatically mark the most recent clean scan as baseline
        $this->markInitialBaseline();

        // Mark migration as applied
        update_option( self::MIGRATION_OPTION, self::MIGRATION_VERSION );

        return true;
    }

    /**
     * Mark the most recent clean scan as baseline
     *
     * @return bool True on success, false on failure
     */
    private function markInitialBaseline(): bool {
        global $wpdb;

        $scan_results_table = $wpdb->prefix . 'eightyfourem_integrity_scan_results';

        // Find the most recent completed scan with 0 changes
        $baseline_scan = $wpdb->get_var(
            "SELECT id FROM {$scan_results_table}
            WHERE status = 'completed'
            AND changed_files = 0
            AND new_files = 0
            AND deleted_files = 0
            ORDER BY scan_date DESC
            LIMIT 1"
        );

        if ( $baseline_scan ) {
            $wpdb->update(
                $scan_results_table,
                [ 'is_baseline' => 1 ],
                [ 'id' => $baseline_scan ],
                [ '%d' ],
                [ '%d' ]
            );

            return true;
        }

        return false;
    }

    /**
     * Rollback the migration
     *
     * @return bool True on success, false on failure
     */
    public function down(): bool {
        global $wpdb;

        $scan_results_table = $wpdb->prefix . 'eightyfourem_integrity_scan_results';

        // Drop the is_baseline column
        $sql = "ALTER TABLE {$scan_results_table}
                DROP COLUMN is_baseline,
                DROP INDEX idx_is_baseline";

        $wpdb->query( $sql );

        // Remove migration marker
        delete_option( self::MIGRATION_OPTION );

        return true;
    }
}
