<?php
/**
 * Checksum Generator
 *
 * @package EightyFourEM\FileIntegrityChecker\Scanner
 */

namespace EightyFourEM\FileIntegrityChecker\Scanner;

/**
 * Generates checksums for files
 */
class ChecksumGenerator {
    /**
     * Hash algorithm to use
     */
    private const HASH_ALGORITHM = 'sha256';

    /**
     * Maximum file size to process (in bytes)
     */
    private const MAX_FILE_SIZE = 100 * 1024 * 1024; // 100MB

    /**
     * Generate checksum for a file
     *
     * @param string $file_path Full path to the file
     * @return string|false Checksum string or false on failure
     */
    public function generateChecksum( string $file_path ): string|false {
        if ( ! $this->isValidFile( $file_path ) ) {
            return false;
        }

        try {
            // Use hash_file for better memory efficiency on large files
            $checksum = hash_file( self::HASH_ALGORITHM, $file_path );
            
            if ( $checksum === false ) {
                error_log( "Failed to generate checksum for file: $file_path" );
                return false;
            }

            return $checksum;
        } catch ( \Exception $e ) {
            error_log( "Error generating checksum for $file_path: " . $e->getMessage() );
            return false;
        }
    }

    /**
     * Generate checksums for multiple files efficiently
     *
     * @param array $file_paths Array of file paths
     * @return array Associative array of file_path => checksum
     */
    public function generateBatchChecksums( array $file_paths ): array {
        $checksums = [];

        foreach ( $file_paths as $file_path ) {
            $checksum = $this->generateChecksum( $file_path );
            if ( $checksum !== false ) {
                $checksums[ $file_path ] = $checksum;
            } else {
                // Log the failure but continue processing other files
                error_log( "Skipping file due to checksum failure: $file_path" );
            }
        }

        return $checksums;
    }

    /**
     * Check if file is valid for checksum generation
     *
     * @param string $file_path File path
     * @return bool True if file is valid, false otherwise
     */
    private function isValidFile( string $file_path ): bool {
        // Check if file exists and is readable
        if ( ! is_file( $file_path ) || ! is_readable( $file_path ) ) {
            return false;
        }

        // Check file size
        $file_size = filesize( $file_path );
        if ( $file_size === false || $file_size > self::MAX_FILE_SIZE ) {
            return false;
        }

        // Skip empty files
        if ( $file_size === 0 ) {
            return false;
        }

        // Check if file is locked or being written to
        $handle = fopen( $file_path, 'r' );
        if ( ! $handle ) {
            return false;
        }

        $can_lock = flock( $handle, LOCK_SH | LOCK_NB );
        fclose( $handle );

        return $can_lock;
    }

    /**
     * Verify a file against a known checksum
     *
     * @param string $file_path File path
     * @param string $expected_checksum Expected checksum
     * @return bool True if checksums match, false otherwise
     */
    public function verifyChecksum( string $file_path, string $expected_checksum ): bool {
        $current_checksum = $this->generateChecksum( $file_path );
        
        if ( $current_checksum === false ) {
            return false;
        }

        return hash_equals( $expected_checksum, $current_checksum );
    }

    /**
     * Get the hash algorithm being used
     *
     * @return string Hash algorithm name
     */
    public function getHashAlgorithm(): string {
        return self::HASH_ALGORITHM;
    }

    /**
     * Get supported hash algorithms
     *
     * @return array Array of supported hash algorithms
     */
    public function getSupportedAlgorithms(): array {
        return hash_algos();
    }

    /**
     * Check if the system supports the hash algorithm
     *
     * @return bool True if supported, false otherwise
     */
    public function isAlgorithmSupported(): bool {
        return in_array( self::HASH_ALGORITHM, hash_algos(), true );
    }
}