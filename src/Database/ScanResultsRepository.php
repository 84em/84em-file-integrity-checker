<?php
/**
 * Scan Results Repository
 *
 * @package EightyFourEM\FileIntegrityChecker\Database
 */

namespace EightyFourEM\FileIntegrityChecker\Database;

use EightyFourEM\FileIntegrityChecker\Database\FileRecordRepository;

/**
 * Repository for managing scan results data
 */
class ScanResultsRepository {
    /**
     * Table name
     */
    private const TABLE_NAME = 'eightyfourem_integrity_scan_results';

    /**
     * Create a new scan result record
     *
     * @param array $data Scan result data
     * @return int|false Scan result ID on success, false on failure
     */
    public function create( array $data ): int|false {
        global $wpdb;

        $defaults = [
            'scan_date' => current_time( 'mysql' ),
            'status' => 'running',
            'total_files' => 0,
            'changed_files' => 0,
            'new_files' => 0,
            'deleted_files' => 0,
            'scan_duration' => 0,
            'memory_usage' => 0,
            'scan_type' => 'manual',
            'notes' => '',
        ];

        $data = wp_parse_args( $data, $defaults );

        $result = $wpdb->insert(
            $wpdb->prefix . self::TABLE_NAME,
            $data,
            [
                '%s', // scan_date
                '%s', // status
                '%d', // total_files
                '%d', // changed_files
                '%d', // new_files
                '%d', // deleted_files
                '%d', // scan_duration
                '%d', // memory_usage
                '%s', // scan_type
                '%s', // notes
            ]
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Update a scan result record
     *
     * @param int   $id   Scan result ID
     * @param array $data Data to update
     * @return bool True on success, false on failure
     */
    public function update( int $id, array $data ): bool {
        global $wpdb;

        $allowed_fields = [
            'status', 'total_files', 'changed_files', 'new_files', 
            'deleted_files', 'scan_duration', 'memory_usage', 'notes'
        ];

        $update_data = [];
        $format = [];

        foreach ( $data as $field => $value ) {
            if ( in_array( $field, $allowed_fields, true ) ) {
                $update_data[ $field ] = $value;
                $format[] = in_array( $field, [ 'status', 'notes' ], true ) ? '%s' : '%d';
            }
        }

        if ( empty( $update_data ) ) {
            return false;
        }

        $result = $wpdb->update(
            $wpdb->prefix . self::TABLE_NAME,
            $update_data,
            [ 'id' => $id ],
            $format,
            [ '%d' ]
        );

        return $result !== false;
    }

    /**
     * Get a scan result by ID
     *
     * @param int $id Scan result ID
     * @return object|null Scan result object or null if not found
     */
    public function getById( int $id ): ?object {
        global $wpdb;

        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}" . self::TABLE_NAME . " WHERE id = %d",
                $id
            )
        );

        return $result ?: null;
    }

    /**
     * Get recent scan results
     *
     * @param int $limit Number of results to retrieve
     * @return array Array of scan result objects
     */
    public function getRecent( int $limit = 10 ): array {
        global $wpdb;

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}" . self::TABLE_NAME . 
                " ORDER BY scan_date DESC LIMIT %d",
                $limit
            )
        );

        return $results ?: [];
    }

    /**
     * Get scan results with pagination
     *
     * @param int $page     Page number (1-based)
     * @param int $per_page Results per page
     * @return array Array with 'results' and 'total_count'
     */
    public function getPaginated( int $page = 1, int $per_page = 20 ): array {
        global $wpdb;

        $offset = ( $page - 1 ) * $per_page;

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}" . self::TABLE_NAME . 
                " ORDER BY scan_date DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            )
        );

        $total_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}" . self::TABLE_NAME
        );

        return [
            'results' => $results ?: [],
            'total_count' => (int) $total_count,
        ];
    }

    /**
     * Get the latest completed scan
     *
     * @return object|null Latest completed scan or null if none found
     */
    public function getLatestCompleted(): ?object {
        global $wpdb;

        $result = $wpdb->get_row(
            "SELECT * FROM {$wpdb->prefix}" . self::TABLE_NAME . 
            " WHERE status = 'completed' ORDER BY scan_date DESC LIMIT 1"
        );

        return $result ?: null;
    }

    /**
     * Get scan statistics
     *
     * @return array Statistics array
     */
    public function getStatistics(): array {
        global $wpdb;

        $stats = $wpdb->get_row(
            "SELECT 
                COUNT(*) as total_scans,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_scans,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_scans,
                AVG(CASE WHEN status = 'completed' THEN scan_duration ELSE NULL END) as avg_scan_duration,
                SUM(CASE WHEN status = 'completed' THEN changed_files ELSE 0 END) as total_changed_files
            FROM {$wpdb->prefix}" . self::TABLE_NAME
        );

        return [
            'total_scans' => (int) $stats->total_scans,
            'completed_scans' => (int) $stats->completed_scans,
            'failed_scans' => (int) $stats->failed_scans,
            'avg_scan_duration' => (float) $stats->avg_scan_duration,
            'total_changed_files' => (int) $stats->total_changed_files,
        ];
    }

    /**
     * Delete old scan results
     *
     * @param int $days_old Delete scans older than this many days
     * @return int Number of deleted records
     */
    public function deleteOld( int $days_old = 90 ): int {
        global $wpdb;

        $result = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}" . self::TABLE_NAME . 
                " WHERE scan_date < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days_old
            )
        );

        return (int) $result;
    }

    /**
     * Delete a scan result and its associated file records
     *
     * @param int $id Scan result ID
     * @return bool True on success, false on failure
     */
    public function delete( int $id ): bool {
        global $wpdb;

        // First delete associated file records
        $file_record_repo = new FileRecordRepository();
        $file_record_repo->deleteByScanId( $id );

        // Then delete the scan result
        $result = $wpdb->delete(
            $wpdb->prefix . self::TABLE_NAME,
            [ 'id' => $id ],
            [ '%d' ]
        );

        return $result !== false;
    }

    /**
     * Mark a scan as baseline
     *
     * @param int $scan_id Scan result ID
     * @return bool True on success, false on failure
     */
    public function markAsBaseline( int $scan_id ): bool {
        global $wpdb;

        // First, unmark any existing baseline scans
        $wpdb->update(
            $wpdb->prefix . self::TABLE_NAME,
            [ 'is_baseline' => 0 ],
            [ 'is_baseline' => 1 ],
            [ '%d' ],
            [ '%d' ]
        );

        // Then mark the new baseline
        $result = $wpdb->update(
            $wpdb->prefix . self::TABLE_NAME,
            [ 'is_baseline' => 1 ],
            [ 'id' => $scan_id ],
            [ '%d' ],
            [ '%d' ]
        );

        return $result !== false;
    }

    /**
     * Get the current baseline scan ID
     *
     * @return int|null Baseline scan ID or null if none set
     */
    public function getBaselineScanId(): ?int {
        global $wpdb;

        $baseline_id = $wpdb->get_var(
            "SELECT id FROM {$wpdb->prefix}" . self::TABLE_NAME .
            " WHERE is_baseline = 1 LIMIT 1"
        );

        return $baseline_id ? (int) $baseline_id : null;
    }

    /**
     * Get the baseline scan record
     *
     * @return object|null Baseline scan record or null if none set
     */
    public function getBaselineScan(): ?object {
        global $wpdb;

        $result = $wpdb->get_row(
            "SELECT * FROM {$wpdb->prefix}" . self::TABLE_NAME .
            " WHERE is_baseline = 1 LIMIT 1"
        );

        return $result ?: null;
    }

    /**
     * Check if a scan is marked as baseline
     *
     * @param int $scan_id Scan result ID
     * @return bool True if baseline, false otherwise
     */
    public function isBaseline( int $scan_id ): bool {
        global $wpdb;

        $is_baseline = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT is_baseline FROM {$wpdb->prefix}" . self::TABLE_NAME . " WHERE id = %d",
                $scan_id
            )
        );

        return (bool) $is_baseline;
    }

    /**
     * Get scans with critical priority files
     *
     * @return array Array of scan IDs that contain critical priority files
     */
    public function getScansWithCriticalFiles(): array {
        global $wpdb;

        $file_records_table = $wpdb->prefix . 'eightyfourem_integrity_file_records';

        $scan_ids = $wpdb->get_col(
            "SELECT DISTINCT fr.scan_result_id
            FROM {$file_records_table} fr
            WHERE fr.priority_level = 'critical'"
        );

        return array_map( 'intval', $scan_ids );
    }

    /**
     * Delete old scans using tiered retention policy
     *
     * @param int  $tier2_days Days to keep detailed data (default 30)
     * @param int  $tier3_days Days to keep summary data (default 90)
     * @param bool $keep_baseline Whether to keep baseline scans (default true)
     * @return array Statistics about deleted scans
     */
    public function deleteOldScansWithTiers( int $tier2_days = 30, int $tier3_days = 90, bool $keep_baseline = true ): array {
        global $wpdb;

        $file_records_table = $wpdb->prefix . 'eightyfourem_integrity_file_records';
        $stats = [
            'tier2_removed' => 0,
            'tier3_diff_removed' => 0,
            'scans_deleted' => 0,
        ];

        // Get IDs to protect (baseline and critical)
        $protected_ids = [];

        if ( $keep_baseline ) {
            $baseline_id = $this->getBaselineScanId();
            if ( $baseline_id ) {
                $protected_ids[] = $baseline_id;
            }
        }

        // Get scans with critical files
        $critical_scan_ids = $this->getScansWithCriticalFiles();
        $protected_ids = array_merge( $protected_ids, $critical_scan_ids );
        $protected_ids = array_unique( $protected_ids );

        // Tier 2: Remove full file records for scans older than tier2_days but keep scan metadata
        // This happens for scans between tier2_days and tier3_days old
        $tier2_cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$tier2_days} days" ) );
        $tier3_cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$tier3_days} days" ) );

        // Build exclusion clause for protected scans
        $exclusion_clause = '';
        if ( ! empty( $protected_ids ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $protected_ids ), '%d' ) );
            $exclusion_clause = $wpdb->prepare( " AND sr.id NOT IN ({$placeholders})", $protected_ids );
        }

        // Tier 3: Remove diff_content from file records for scans between tier2 and tier3
        $sql = "UPDATE {$file_records_table} fr
                JOIN {$wpdb->prefix}" . self::TABLE_NAME . " sr ON fr.scan_result_id = sr.id
                SET fr.diff_content = NULL
                WHERE sr.scan_date < %s
                AND sr.scan_date >= %s
                AND fr.diff_content IS NOT NULL
                {$exclusion_clause}";

        $params = [ $tier2_cutoff, $tier3_cutoff ];
        if ( ! empty( $protected_ids ) ) {
            $params = array_merge( $params, $protected_ids );
        }

        $stats['tier3_diff_removed'] = $wpdb->query( $wpdb->prepare( $sql, $params ) );

        // Delete scans older than tier3_days (except protected ones)
        $delete_sql = "DELETE sr, fr
                      FROM {$wpdb->prefix}" . self::TABLE_NAME . " sr
                      LEFT JOIN {$file_records_table} fr ON sr.id = fr.scan_result_id
                      WHERE sr.scan_date < %s
                      {$exclusion_clause}";

        $delete_params = [ $tier3_cutoff ];
        if ( ! empty( $protected_ids ) ) {
            $delete_params = array_merge( $delete_params, $protected_ids );
        }

        $stats['scans_deleted'] = $wpdb->query( $wpdb->prepare( $delete_sql, $delete_params ) );

        return $stats;
    }
}