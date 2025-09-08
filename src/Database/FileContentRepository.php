<?php
/**
 * File Content Repository
 *
 * @package EightyFourEM\FileIntegrityChecker\Database
 */

namespace EightyFourEM\FileIntegrityChecker\Database;

use EightyFourEM\FileIntegrityChecker\Services\SettingsService;

/**
 * Repository for managing file content storage
 * 
 * Storage Strategy:
 * - Only stores content for NEW and CHANGED files
 * - Unchanged files are NOT stored to save database space
 * - Content is stored compressed (gzcompress) for ~70-80% space savings
 * - Files are indexed by checksum for deduplication
 */
class FileContentRepository {
    /**
     * Settings service
     *
     * @var SettingsService
     */
    private SettingsService $settings;
    /**
     * Table name
     */
    private const TABLE_NAME = 'eightyfourem_integrity_file_content';

    /**
     * Constructor
     */
    public function __construct() {
        $this->settings = new SettingsService();
    }

    /**
     * Store file content
     *
     * @param string $checksum File checksum
     * @param string $content File content
     * @return bool True on success, false on failure
     */
    public function store( string $checksum, string $content ): bool {
        global $wpdb;

        // Check if content already exists
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}" . self::TABLE_NAME . " WHERE checksum = %s",
                $checksum
            )
        );

        if ( $exists ) {
            return true; // Already stored
        }

        // Compress content to save space
        $compressed = gzcompress( $content, 9 );
        if ( $compressed === false ) {
            return false;
        }

        $result = $wpdb->insert(
            $wpdb->prefix . self::TABLE_NAME,
            [
                'checksum' => $checksum,
                'content' => $compressed,
                'file_size' => strlen( $content ),
            ],
            [ '%s', '%s', '%d' ]
        );

        // Clean up old entries based on configured limit
        $retention_limit = $this->settings->getContentRetentionLimit();
        $this->cleanup( $retention_limit );

        return $result !== false;
    }

    /**
     * Retrieve file content by checksum
     *
     * @param string $checksum File checksum
     * @return string|null File content or null if not found
     */
    public function get( string $checksum ): ?string {
        global $wpdb;

        $compressed = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT content FROM {$wpdb->prefix}" . self::TABLE_NAME . " WHERE checksum = %s",
                $checksum
            )
        );

        if ( ! $compressed ) {
            return null;
        }

        $content = gzuncompress( $compressed );
        
        if ( $content === false ) {
            return null;
        }

        // Verify checksum matches
        if ( hash( 'sha256', $content ) !== $checksum ) {
            return null;
        }

        return $content;
    }

    /**
     * Check if content exists
     *
     * @param string $checksum File checksum
     * @return bool True if exists, false otherwise
     */
    public function exists( string $checksum ): bool {
        global $wpdb;

        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}" . self::TABLE_NAME . " WHERE checksum = %s",
                $checksum
            )
        );

        return (int) $count > 0;
    }

    /**
     * Clean up old entries to prevent unlimited growth
     *
     * @param int $keep Number of entries to keep (optional, uses setting if not provided)
     * @return int Number of deleted entries
     */
    public function cleanup( int $keep = 0 ): int {
        // Use configured limit if not provided
        if ( $keep === 0 ) {
            $keep = $this->settings->getContentRetentionLimit();
        }
        global $wpdb;

        // Get total count
        $total = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}" . self::TABLE_NAME
        );

        if ( $total <= $keep ) {
            return 0;
        }

        // Get the cutoff date
        $cutoff_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}" . self::TABLE_NAME . 
                " ORDER BY created_at DESC LIMIT %d, 1",
                $keep - 1
            )
        );

        if ( ! $cutoff_id ) {
            return 0;
        }

        // Delete older entries
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}" . self::TABLE_NAME . " WHERE id < %d",
                $cutoff_id
            )
        );

        return $deleted ?: 0;
    }

    /**
     * Get storage statistics
     *
     * @return array Statistics array
     */
    public function getStatistics(): array {
        global $wpdb;

        $stats = $wpdb->get_row(
            "SELECT 
                COUNT(*) as total_entries,
                SUM(file_size) as total_uncompressed_size,
                SUM(LENGTH(content)) as total_compressed_size,
                MIN(created_at) as oldest_entry,
                MAX(created_at) as newest_entry
            FROM {$wpdb->prefix}" . self::TABLE_NAME
        );

        return [
            'total_entries' => (int) $stats->total_entries,
            'total_uncompressed_size' => (int) $stats->total_uncompressed_size,
            'total_compressed_size' => (int) $stats->total_compressed_size,
            'compression_ratio' => $stats->total_uncompressed_size > 0 
                ? round( ( 1 - $stats->total_compressed_size / $stats->total_uncompressed_size ) * 100, 2 )
                : 0,
            'oldest_entry' => $stats->oldest_entry,
            'newest_entry' => $stats->newest_entry,
        ];
    }

    /**
     * Clear all stored content
     *
     * @return bool True on success, false on failure
     */
    public function clearAll(): bool {
        global $wpdb;

        $result = $wpdb->query(
            "TRUNCATE TABLE {$wpdb->prefix}" . self::TABLE_NAME
        );

        return $result !== false;
    }
}