<?php
/**
 * Scan Results Repository
 *
 * @package EightyFourEM\FileIntegrityChecker\Database
 */

namespace EightyFourEM\FileIntegrityChecker\Database;

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

        // File records will be automatically deleted due to foreign key constraint
        $result = $wpdb->delete(
            $wpdb->prefix . self::TABLE_NAME,
            [ 'id' => $id ],
            [ '%d' ]
        );

        return $result !== false;
    }
}