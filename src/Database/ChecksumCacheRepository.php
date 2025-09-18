<?php
/**
 * Checksum Cache Repository
 *
 * @package EightyFourEM\FileIntegrityChecker\Database
 */

namespace EightyFourEM\FileIntegrityChecker\Database;

use EightyFourEM\FileIntegrityChecker\Services\EncryptionService;

/**
 * Repository for managing temporary file content cache
 *
 * Storage Strategy:
 * - Temporarily stores content for changed files during scans
 * - Content is compressed (gzcompress) for space efficiency
 * - Auto-expires after specified TTL (typically 48 hours)
 * - Never stores sensitive file content
 */
class ChecksumCacheRepository {
    /**
     * Table name
     */
    private const TABLE_NAME = 'eightyfourem_integrity_checksum_cache';

    /**
     * Default TTL in hours
     */
    private const DEFAULT_TTL_HOURS = 2160;

    /**
     * Encryption service
     *
     * @var EncryptionService
     */
    private EncryptionService $encryptionService;

    /**
     * Constructor
     *
     * @param EncryptionService $encryptionService Encryption service (required)
     */
    public function __construct( EncryptionService $encryptionService ) {
        $this->encryptionService = $encryptionService;
    }

    /**
     * Store file content temporarily
     *
     * @param string $file_path File path
     * @param string $checksum File checksum
     * @param string $content File content
     * @param int $ttl_hours Time to live in hours (default 48)
     * @param bool $is_sensitive Whether file contains sensitive data
     * @return bool True on success, false on failure
     */
    public function storeTemporary( string $file_path, string $checksum, string $content, int $ttl_hours = self::DEFAULT_TTL_HOURS, bool $is_sensitive = false ): bool {
        global $wpdb;

        // Never store sensitive content
        if ( $is_sensitive ) {
            return false;
        }

        // Check if already cached
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}" . self::TABLE_NAME . " WHERE file_path = %s AND checksum = %s",
                $file_path,
                $checksum
            )
        );

        if ( $exists ) {
            // Update expiration time
            return $this->updateExpiration( $file_path, $checksum, $ttl_hours );
        }

        // Encrypt content first (mandatory for security)
        $encrypted = $this->encryptionService->encrypt( $content );
        if ( $encrypted === false ) {
            return false;
        }

        // Compress the encrypted data
        $compressed = gzcompress( $encrypted, 9 );
        if ( $compressed === false ) {
            return false;
        }

        // Calculate expiration time
        $expires_at = gmdate( 'Y-m-d H:i:s', time() + ( $ttl_hours * HOUR_IN_SECONDS ) );

        $result = $wpdb->insert(
            $wpdb->prefix . self::TABLE_NAME,
            [
                'file_path' => $file_path,
                'checksum' => $checksum,
                'file_content' => $compressed,
                'is_sensitive' => 0, // Already checked above
                'expires_at' => $expires_at,
            ],
            [ '%s', '%s', '%s', '%d', '%s' ]
        );

        return $result !== false;
    }

    /**
     * Retrieve cached file content
     *
     * @param string $file_path File path
     * @param string $checksum File checksum
     * @return string|null File content or null if not found/expired
     */
    public function getContent( string $file_path, string $checksum ): ?string {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT file_content, expires_at FROM {$wpdb->prefix}" . self::TABLE_NAME .
                " WHERE file_path = %s AND checksum = %s",
                $file_path,
                $checksum
            )
        );

        if ( ! $row ) {
            return null;
        }

        // Check if expired
        if ( strtotime( $row->expires_at ) < time() ) {
            // Clean up expired entry
            $this->deleteEntry( $file_path, $checksum );
            return null;
        }

        // Decompress content
        $decompressed = gzuncompress( $row->file_content );

        if ( $decompressed === false ) {
            return null;
        }

        // Decrypt the content (mandatory)
        $content = $this->encryptionService->decrypt( $decompressed );
        if ( $content === false ) {
            // Decryption failure - remove corrupted entry
            $this->deleteEntry( $file_path, $checksum );
            return null;
        }

        // Verify checksum still matches
        if ( hash( 'sha256', $content ) !== $checksum ) {
            // Data corruption, remove entry
            $this->deleteEntry( $file_path, $checksum );
            return null;
        }

        return $content;
    }

    /**
     * Update expiration time for cached entry
     *
     * @param string $file_path File path
     * @param string $checksum File checksum
     * @param int $ttl_hours New TTL in hours
     * @return bool True on success
     */
    private function updateExpiration( string $file_path, string $checksum, int $ttl_hours ): bool {
        global $wpdb;

        $expires_at = gmdate( 'Y-m-d H:i:s', time() + ( $ttl_hours * HOUR_IN_SECONDS ) );

        $result = $wpdb->update(
            $wpdb->prefix . self::TABLE_NAME,
            [ 'expires_at' => $expires_at ],
            [
                'file_path' => $file_path,
                'checksum' => $checksum,
            ],
            [ '%s' ],
            [ '%s', '%s' ]
        );

        return $result !== false;
    }

    /**
     * Delete specific cache entry
     *
     * @param string $file_path File path
     * @param string $checksum File checksum
     * @return bool True on success
     */
    private function deleteEntry( string $file_path, string $checksum ): bool {
        global $wpdb;

        $result = $wpdb->delete(
            $wpdb->prefix . self::TABLE_NAME,
            [
                'file_path' => $file_path,
                'checksum' => $checksum,
            ],
            [ '%s', '%s' ]
        );

        return $result !== false;
    }

    /**
     * Clean up expired cache entries
     *
     * @return int Number of deleted entries
     */
    public function cleanupExpired(): int {
        global $wpdb;

        $current_time = current_time( 'mysql', true );

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}" . self::TABLE_NAME . " WHERE expires_at < %s",
                $current_time
            )
        );

        return $deleted ?: 0;
    }

    /**
     * Clear all cache entries for a specific scan
     * Called after successful scan completion
     *
     * @param array $file_paths Array of file paths to clear
     * @return int Number of deleted entries
     */
    public function clearScanCache( array $file_paths ): int {
        global $wpdb;

        if ( empty( $file_paths ) ) {
            return 0;
        }

        // Build placeholders for IN clause
        $placeholders = array_fill( 0, count( $file_paths ), '%s' );
        $placeholder_string = implode( ', ', $placeholders );

        $sql = $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}" . self::TABLE_NAME . " WHERE file_path IN ($placeholder_string)",
            $file_paths
        );

        $deleted = $wpdb->query( $sql );

        return $deleted ?: 0;
    }

    /**
     * Get cache statistics
     *
     * @return array Statistics array
     */
    public function getStatistics(): array {
        global $wpdb;

        $stats = $wpdb->get_row(
            "SELECT
                COUNT(*) as total_entries,
                SUM(LENGTH(file_content)) as total_size,
                MIN(created_at) as oldest_entry,
                MAX(created_at) as newest_entry,
                MIN(expires_at) as next_expiration
            FROM {$wpdb->prefix}" . self::TABLE_NAME
        );

        $expired_count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}" . self::TABLE_NAME . " WHERE expires_at < %s",
                current_time( 'mysql', true )
            )
        );

        return [
            'total_entries' => (int) $stats->total_entries,
            'total_size' => (int) $stats->total_size,
            'expired_entries' => (int) $expired_count,
            'oldest_entry' => $stats->oldest_entry,
            'newest_entry' => $stats->newest_entry,
            'next_expiration' => $stats->next_expiration,
        ];
    }

    /**
     * Clear all cache entries
     *
     * @return bool True on success
     */
    public function clearAll(): bool {
        global $wpdb;

        $result = $wpdb->query(
            "TRUNCATE TABLE {$wpdb->prefix}" . self::TABLE_NAME
        );

        return $result !== false;
    }

    /**
     * Get encryption statistics
     *
     * @return array
     */
    public function getEncryptionStatistics(): array {
        return $this->encryptionService->getStatistics();
    }
}
