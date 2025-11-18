<?php
/**
 * File Record Repository
 *
 * @package EightyFourEM\FileIntegrityChecker\Database
 */

namespace EightyFourEM\FileIntegrityChecker\Database;

/**
 * Repository for managing file record data
 */
class FileRecordRepository {
    /**
     * Table name
     */
    private const TABLE_NAME = 'eightyfourem_integrity_file_records';

    /**
     * Create a new file record
     *
     * @param array $data File record data
     * @return int|false File record ID on success, false on failure
     */
    public function create( array $data ): int|false {
        global $wpdb;

        $required_fields = [ 'scan_result_id', 'file_path', 'file_size', 'checksum', 'last_modified' ];
        
        foreach ( $required_fields as $field ) {
            if ( ! isset( $data[ $field ] ) ) {
                return false;
            }
        }

        $defaults = [
            'status' => 'unchanged',
            'previous_checksum' => null,
            'diff_content' => null,
            'is_sensitive' => 0,
        ];

        $data = wp_parse_args( $data, $defaults );

        $result = $wpdb->insert(
            $wpdb->prefix . self::TABLE_NAME,
            $data,
            [
                '%d', // scan_result_id
                '%s', // file_path
                '%d', // file_size
                '%s', // checksum
                '%s', // status
                '%s', // previous_checksum
                '%s', // diff_content
                '%d', // is_sensitive
                '%s', // last_modified
            ]
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Create multiple file records efficiently
     *
     * @param array $records Array of file record data
     * @return bool True on success, false on failure
     */
    public function createBatch( array $records ): bool {
        global $wpdb;

        if ( empty( $records ) ) {
            return true;
        }

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $values = [];
        $placeholders = [];

        foreach ( $records as $record ) {
            $values[] = $record['scan_result_id'];
            $values[] = $record['file_path'];
            $values[] = $record['file_size'];
            $values[] = $record['checksum'];
            $values[] = $record['status'] ?? 'unchanged';
            $values[] = $record['previous_checksum'] ?? null;
            $values[] = $record['diff_content'] ?? null;
            $values[] = $record['is_sensitive'] ?? 0;
            $values[] = $record['last_modified'];

            $placeholders[] = '(%d, %s, %d, %s, %s, %s, %s, %d, %s)';
        }

        $sql = "INSERT INTO $table_name
                (scan_result_id, file_path, file_size, checksum, status, previous_checksum, diff_content, is_sensitive, last_modified)
                VALUES " . implode( ', ', $placeholders );

        $result = $wpdb->query( $wpdb->prepare( $sql, $values ) );

        return $result !== false;
    }

    /**
     * Get file records for a specific scan
     *
     * @param int $scan_result_id Scan result ID
     * @param int $limit          Optional limit
     * @return array Array of file record objects
     */
    public function getByScanId( int $scan_result_id, ?int $limit = null ): array {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}" . self::TABLE_NAME . 
            " WHERE scan_result_id = %d ORDER BY file_path",
            $scan_result_id
        );

        if ( $limit ) {
            $sql .= $wpdb->prepare( " LIMIT %d", $limit );
        }

        $results = $wpdb->get_results( $sql );

        return $results ?: [];
    }

    /**
     * Get changed files for a specific scan
     *
     * @param int $scan_result_id Scan result ID
     * @return array Array of changed file records
     */
    public function getChangedFiles( int $scan_result_id ): array {
        global $wpdb;

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}" . self::TABLE_NAME .
                " WHERE scan_result_id = %d AND status != 'unchanged'
                ORDER BY
                    CASE status
                        WHEN 'changed' THEN 1
                        WHEN 'new' THEN 2
                        WHEN 'deleted' THEN 3
                        ELSE 4
                    END,
                    file_path",
                $scan_result_id
            )
        );

        return $results ?: [];
    }

    /**
     * Get the latest record for a specific file
     *
     * @param string $file_path File path
     * @return object|null Latest file record or null if not found
     */
    public function getLatestByPath( string $file_path ): ?object {
        global $wpdb;

        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT fr.* FROM {$wpdb->prefix}" . self::TABLE_NAME . " fr
                JOIN {$wpdb->prefix}eightyfourem_integrity_scan_results sr ON fr.scan_result_id = sr.id
                WHERE fr.file_path = %s AND sr.status = 'completed'
                ORDER BY sr.scan_date DESC LIMIT 1",
                $file_path
            )
        );

        return $result ?: null;
    }

    /**
     * Get file records with pagination
     *
     * @param int $scan_result_id Scan result ID
     * @param int $page           Page number (1-based)
     * @param int $per_page       Results per page
     * @param string $status      Optional status filter
     * @return array Array with 'results' and 'total_count'
     */
    public function getPaginated( int $scan_result_id, int $page = 1, int $per_page = 50, string $status = '' ): array {
        global $wpdb;

        $offset = ( $page - 1 ) * $per_page;
        $where_clause = "scan_result_id = %d";
        $params = [ $scan_result_id ];

        // Whitelist validation for status parameter
        $allowed_statuses = [ 'new', 'changed', 'deleted' ];
        
        if ( $status === 'all' ) {
            // "All Changes" - exclude unchanged files
            $where_clause .= " AND status != 'unchanged'";
        } elseif ( ! empty( $status ) ) {
            if ( ! in_array( $status, $allowed_statuses, true ) ) {
                // Invalid status, return empty results
                return [
                    'results' => [],
                    'total_count' => 0,
                ];
            }
            $where_clause .= " AND status = %s";
            $params[] = $status;
        }

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}" . self::TABLE_NAME . 
                " WHERE $where_clause ORDER BY file_path LIMIT %d OFFSET %d",
                array_merge( $params, [ $per_page, $offset ] )
            )
        );

        $total_count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}" . self::TABLE_NAME . " WHERE $where_clause",
                $params
            )
        );

        return [
            'results' => $results ?: [],
            'total_count' => (int) $total_count,
        ];
    }

    /**
     * Get file statistics for a scan
     *
     * @param int $scan_result_id Scan result ID
     * @return array Statistics array
     */
    public function getStatistics( int $scan_result_id ): array {
        global $wpdb;

        $stats = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT 
                    COUNT(*) as total_files,
                    SUM(CASE WHEN status = 'changed' THEN 1 ELSE 0 END) as changed_files,
                    SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) as new_files,
                    SUM(CASE WHEN status = 'deleted' THEN 1 ELSE 0 END) as deleted_files,
                    SUM(file_size) as total_size
                FROM {$wpdb->prefix}" . self::TABLE_NAME . " WHERE scan_result_id = %d",
                $scan_result_id
            )
        );

        return [
            'total_files' => (int) $stats->total_files,
            'changed_files' => (int) $stats->changed_files,
            'new_files' => (int) $stats->new_files,
            'deleted_files' => (int) $stats->deleted_files,
            'total_size' => (int) $stats->total_size,
        ];
    }

    /**
     * Delete file records for a specific scan
     *
     * @param int $scan_result_id Scan result ID
     * @return bool True on success, false on failure
     */
    public function deleteByScanId( int $scan_result_id ): bool {
        global $wpdb;

        $result = $wpdb->delete(
            $wpdb->prefix . self::TABLE_NAME,
            [ 'scan_result_id' => $scan_result_id ],
            [ '%d' ]
        );

        return $result !== false;
    }

    /**
     * Check if a file exists in a specific scan
     *
     * @param int    $scan_result_id Scan result ID
     * @param string $file_path      File path
     * @return bool True if file exists in scan, false otherwise
     */
    public function fileExistsInScan( int $scan_result_id, string $file_path ): bool {
        global $wpdb;

        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}" . self::TABLE_NAME .
                " WHERE scan_result_id = %d AND file_path = %s",
                $scan_result_id,
                $file_path
            )
        );

        return (int) $count > 0;
    }

    /**
     * Remove diff content from file records for scans older than specified days
     * This is used for tiered retention to keep file metadata but remove large diff content
     *
     * @param int $days_old Remove diff content for records in scans older than this many days
     * @return int Number of records updated
     */
    public function removeDiffContentForOldScans( int $days_old ): int {
        global $wpdb;

        $scan_results_table = $wpdb->prefix . 'eightyfourem_integrity_scan_results';
        $cutoff_date = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days_old} days" ) );

        $sql = "UPDATE {$wpdb->prefix}" . self::TABLE_NAME . " fr
                JOIN {$scan_results_table} sr ON fr.scan_result_id = sr.id
                SET fr.diff_content = NULL
                WHERE sr.scan_date < %s
                AND fr.diff_content IS NOT NULL";

        $result = $wpdb->query( $wpdb->prepare( $sql, $cutoff_date ) );

        return (int) $result;
    }

    /**
     * Get count of file records with diff content
     *
     * @param int $scan_result_id Scan result ID
     * @return int Number of records with diff content
     */
    public function getCountWithDiffContent( int $scan_result_id ): int {
        global $wpdb;

        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}" . self::TABLE_NAME .
                " WHERE scan_result_id = %d AND diff_content IS NOT NULL",
                $scan_result_id
            )
        );

        return (int) $count;
    }

    /**
     * Get total size of diff content for a scan
     *
     * @param int $scan_result_id Scan result ID
     * @return int Total size in bytes
     */
    public function getDiffContentSize( int $scan_result_id ): int {
        global $wpdb;

        $size = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT SUM(LENGTH(diff_content)) FROM {$wpdb->prefix}" . self::TABLE_NAME .
                " WHERE scan_result_id = %d AND diff_content IS NOT NULL",
                $scan_result_id
            )
        );

        return (int) $size;
    }
}