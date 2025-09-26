<?php
/**
 * File Scanner
 *
 * @package EightyFourEM\FileIntegrityChecker\Scanner
 */

namespace EightyFourEM\FileIntegrityChecker\Scanner;

use EightyFourEM\FileIntegrityChecker\Services\SettingsService;
use EightyFourEM\FileIntegrityChecker\Security\FileAccessSecurity;
use EightyFourEM\FileIntegrityChecker\Database\ChecksumCacheRepository;
use EightyFourEM\FileIntegrityChecker\Utils\DiffGenerator;

/**
 * Scans the filesystem for files to check integrity
 */
class FileScanner {
    /**
     * Checksum generator
     *
     * @var ChecksumGenerator
     */
    private ChecksumGenerator $checksumGenerator;

    /**
     * Settings service
     *
     * @var SettingsService
     */
    private SettingsService $settingsService;

    /**
     * Checksum cache repository
     *
     * @var ChecksumCacheRepository
     */
    private ChecksumCacheRepository $cacheRepository;

    /**
     * File access security service
     *
     * @var FileAccessSecurity
     */
    private FileAccessSecurity $fileAccessSecurity;

    /**
     * Diff generator
     * @var DiffGenerator
     */
    private DiffGenerator $diffGenerator;

    /**
     * Constructor
     *
     * @param ChecksumGenerator $checksumGenerator Checksum generator
     * @param SettingsService   $settingsService   Settings service
     * @param FileAccessSecurity $fileAccessSecurity File access security service
     * @param ChecksumCacheRepository $cacheRepository Cache repository
     */
    public function __construct(
        ChecksumGenerator $checksumGenerator,
        SettingsService $settingsService,
        FileAccessSecurity $fileAccessSecurity,
        ChecksumCacheRepository $cacheRepository,
        DiffGenerator $diffGenerator
    ) {
        $this->checksumGenerator = $checksumGenerator;
        $this->settingsService   = $settingsService;
        $this->fileAccessSecurity = $fileAccessSecurity;
        $this->cacheRepository = $cacheRepository;
        $this->diffGenerator = $diffGenerator;
    }

    /**
     * Scan directory for files
     *
     * @param string $directory      Directory to scan
     * @param callable|null $progress_callback Progress callback function
     * @return array Array of file information
     */
    public function scanDirectory( string $directory = ABSPATH, ?callable $progress_callback = null ): array {
        $directory = rtrim( $directory, '/' ) . '/';
        $files = [];
        $processed_count = 0;

        $file_extensions = $this->settingsService->getScanFileTypes();
        $exclude_patterns = $this->settingsService->getExcludePatterns();
        $max_file_size = $this->settingsService->getMaxFileSize();

        $iterator = $this->createFileIterator( $directory, $file_extensions, $exclude_patterns );

        foreach ( $iterator as $file_info ) {
            $file_path = $file_info->getPathname();

            // Skip if file is too large
            if ( $file_info->getSize() > $max_file_size ) {
                continue;
            }

            // Generate checksum
            $checksum = $this->checksumGenerator->generateChecksum( $file_path );
            if ( $checksum === false ) {
                continue;
            }

            $files[] = [
                'file_path' => $file_path,
                'file_size' => $file_info->getSize(),
                'checksum' => $checksum,
                'last_modified' => date( 'Y-m-d H:i:s', $file_info->getMTime() ),
                'status' => 'new', // Will be determined during comparison
            ];

            $processed_count++;

            // Call progress callback if provided
            if ( $progress_callback && $processed_count % 100 === 0 ) {
                call_user_func( $progress_callback, $processed_count, $file_path );
            }
        }

        // Final progress callback
        if ( $progress_callback ) {
            call_user_func( $progress_callback, $processed_count, 'Scan complete' );
        }

        return $files;
    }

    /**
     * Compare current scan with previous scan to detect changes
     *
     * @param array $current_files  Current scan files
     * @param array $previous_files Previous scan files (file_path => file_record)
     * @return array Updated files with status information
     */
    public function compareScans( array $current_files, array $previous_files ): array {
        $updated_files = [];

        // Get current scan settings to filter previous files
        $current_file_types = $this->settingsService->getScanFileTypes();

        // Build lookup array for previous files that match current scan criteria
        $previous_lookup = [];
        foreach ( $previous_files as $file_record ) {
            // Skip files that were already marked as deleted in the previous scan
            // This prevents deleted files from showing up as deleted again in subsequent scans
            if ( isset( $file_record->status ) && $file_record->status === 'deleted' ) {
                continue;
            }

            // Only include previous files that would be scanned with current settings
            if ( $this->shouldIncludeFileInComparison( $file_record->file_path, $current_file_types ) ) {
                $previous_lookup[ $file_record->file_path ] = $file_record;
            }
        }

        // Check current files against previous
        foreach ( $current_files as $file_data ) {
            $file_path = $file_data['file_path'];

            // Check if file is sensitive
            $security_check = $this->fileAccessSecurity->isFileAccessible( $file_path );
            $is_sensitive = ! $security_check['allowed'];

            if ( isset( $previous_lookup[ $file_path ] ) ) {
                $previous_record = $previous_lookup[ $file_path ];

                if ( $file_data['checksum'] !== $previous_record->checksum ) {
                    // File has changed
                    $file_data['status'] = 'changed';
                    $file_data['previous_checksum'] = $previous_record->checksum;
                    $file_data['is_sensitive'] = $is_sensitive ? 1 : 0;

                    if ( $is_sensitive ) {
                        // Sensitive file - no diff generation
                        $file_data['diff_content'] = 'Content redacted: ' . $security_check['reason'];
                    } else {
                        // Generate diff immediately during scan
                        $file_data['diff_content'] = $this->generateFileDiffAtScanTime(
                            $file_path,
                            $file_data['checksum'],
                            $previous_record->checksum
                        );
                    }
                } else {
                    // File is unchanged
                    $file_data['status'] = 'unchanged';
                    $file_data['is_sensitive'] = $is_sensitive ? 1 : 0;
                }
            } else {
                // New file
                $file_data['status'] = 'new';
                $file_data['is_sensitive'] = $is_sensitive ? 1 : 0;

                if ( ! $is_sensitive ) {
                    // Cache content for future comparisons (non-sensitive files only)
                    $this->cacheFileContent( $file_path, $file_data['checksum'] );
                }

                $file_data['diff_content'] = $is_sensitive
                    ? 'Content redacted: ' . $security_check['reason']
                    : 'New file added';
            }

            $updated_files[] = $file_data;
        }

        // Check for deleted files - only among files that match current scan criteria
        $current_paths = array_column( $current_files, 'file_path' );
        foreach ( $previous_lookup as $file_path => $previous_record ) {
            if ( ! in_array( $file_path, $current_paths, true ) ) {
                // Check if file still exists on filesystem
                if ( ! file_exists( $file_path ) ) {
                    // File was actually deleted
                    $updated_files[] = [
                        'file_path' => $file_path,
                        'file_size' => $previous_record->file_size,
                        'checksum' => '', // Empty for deleted files
                        'last_modified' => $previous_record->last_modified,
                        'status' => 'deleted',
                        'previous_checksum' => $previous_record->checksum,
                        'is_sensitive' => 0, // Deleted files don't need sensitivity check
                        'diff_content' => 'File deleted',
                    ];
                }
                // If file exists but wasn't in current scan, it's likely excluded by filters
                // Don't mark it as deleted
            }
        }

        return $updated_files;
    }

    /**
     * Check if a file should be included in comparison based on current scan settings
     *
     * @param string $file_path File path to check
     * @param array $file_types Current file type filters
     * @return bool True if file should be included in comparison
     */
    private function shouldIncludeFileInComparison( string $file_path, array $file_types ): bool {
        // If no file types specified, include all
        if ( empty( $file_types ) ) {
            return true;
        }

        // Check file extension
        $extension = '.' . strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
        return in_array( $extension, $file_types, true );
    }

    /**
     * Generate diff content at scan time for better performance
     *
     * @param string $file_path Path to the file
     * @param string $current_checksum Current file checksum
     * @param string $previous_checksum Previous checksum to compare
     * @return string|null Diff content or null if unable to generate
     */
    private function generateFileDiffAtScanTime( string $file_path, string $current_checksum, string $previous_checksum ): ?string {
        // Only generate diffs for text files (using configured file types)
        $text_extensions = $this->getTextFileExtensions();
        // Convert to extension without dot for comparison
        $text_extensions_no_dot = array_map( function( $ext ) {
            return ltrim( $ext, '.' );
        }, $text_extensions );
        $extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

        if ( ! in_array( $extension, $text_extensions_no_dot, true ) ) {
            return 'Binary file changed';
        }

        // Check if file is too large for diff (using configured max file size)
        if ( filesize( $file_path ) > $this->settingsService->getMaxFileSize() ) {
            return 'File too large for diff generation';
        }

        // Get current content
        $current_content = file_get_contents( $file_path );
        if ( $current_content === false ) {
            return 'Unable to read current file content';
        }

        // Try to get previous content from cache
        $previous_content = $this->cacheRepository->getContent( $file_path, $previous_checksum );

        if ( $previous_content !== null ) {
            // Generate a unified diff
            $diff = $this->generateUnifiedDiff( $previous_content, $current_content, $file_path );

            // Cache current content for next scan (TTL matches scan retention period)
            $retention_days = $this->settingsService->getRetentionPeriod();
            $retention_hours = $retention_days * 24;
            $this->cacheRepository->storeTemporary( $file_path, $current_checksum, $current_content, $retention_hours );

            return $diff;
        }

        // No previous content available, cache current for next time
        $retention_days = $this->settingsService->getRetentionPeriod();
        $retention_hours = $retention_days * 24;
        $this->cacheRepository->storeTemporary( $file_path, $current_checksum, $current_content, $retention_hours );

        // Return a summary since we don't have previous content
        $diff_summary = [
            'type' => 'summary',
            'timestamp' => current_time( 'mysql' ),
            'checksum_changed' => [
                'from' => $previous_checksum,
                'to' => $current_checksum
            ],
            'file_size' => filesize( $file_path ),
            'lines_count' => substr_count( $current_content, "\n" ) + 1,
            'message' => 'Previous version not available. Full diff will be available on next change.'
        ];

        return json_encode( $diff_summary );
    }

    /**
     * Cache file content for future diff generation
     *
     * @param string $file_path Path to the file
     * @param string $checksum File checksum
     * @return void
     */
    private function cacheFileContent( string $file_path, string $checksum ): void {
        // Only cache text files under configured max file size
        $text_extensions = $this->getTextFileExtensions();
        // Convert to extension without dot for comparison
        $text_extensions_no_dot = array_map( function( $ext ) {
            return ltrim( $ext, '.' );
        }, $text_extensions );
        $extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

        if ( ! in_array( $extension, $text_extensions_no_dot, true ) ) {
            return;
        }

        if ( ! file_exists( $file_path ) || filesize( $file_path ) > $this->settingsService->getMaxFileSize() ) {
            return;
        }

        // Read and cache the content temporarily (TTL matches scan retention period)
        $content = file_get_contents( $file_path );
        if ( $content !== false ) {
            $retention_days = $this->settingsService->getRetentionPeriod();
            $retention_hours = $retention_days * 24;
            $this->cacheRepository->storeTemporary( $file_path, $checksum, $content, $retention_hours );
        }
    }

    /**
     * Generate a unified diff between two strings
     *
     * @param string $old_content Old content
     * @param string $new_content New content
     * @param string $file_path File path for context
     * @return string Unified diff
     */
    private function generateUnifiedDiff( string $old_content, string $new_content, string $file_path ): string {
        return $this->diffGenerator->generateUnifiedDiff( $old_content, $new_content, $file_path );
    }

    /**
     * Create file iterator based on settings
     *
     * @param string $directory Directory to scan
     * @param array  $file_extensions File extensions to include
     * @param array  $exclude_patterns Patterns to exclude
     * @return \Iterator File iterator
     */
    private function createFileIterator( string $directory, array $file_extensions, array $exclude_patterns ): \Iterator {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $directory,
                \RecursiveDirectoryIterator::SKIP_DOTS | \RecursiveDirectoryIterator::FOLLOW_SYMLINKS
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        return new \CallbackFilterIterator( $iterator, function ( $file_info ) use ( $file_extensions, $exclude_patterns ) {
            return $this->shouldIncludeFile( $file_info, $file_extensions, $exclude_patterns );
        } );
    }

    /**
     * Determine if a file should be included in the scan
     *
     * @param \SplFileInfo $file_info File info object
     * @param array        $file_extensions File extensions to include
     * @param array        $exclude_patterns Patterns to exclude
     * @return bool True if file should be included, false otherwise
     */
    private function shouldIncludeFile( \SplFileInfo $file_info, array $file_extensions, array $exclude_patterns ): bool {
        if ( ! $file_info->isFile() || ! $file_info->isReadable() ) {
            return false;
        }

        $file_path = $file_info->getPathname();
        $file_extension = '.' . strtolower( $file_info->getExtension() );

        // Check file extension - if no extensions specified, scan all files
        if ( ! empty( $file_extensions ) && ! in_array( $file_extension, $file_extensions, true ) ) {
            return false;
        }

        // Check exclude patterns
        foreach ( $exclude_patterns as $pattern ) {
            // Convert glob pattern to regex
            $regex_pattern = $this->globToRegex( $pattern );
            if ( preg_match( $regex_pattern, $file_path ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Convert glob pattern to regex
     *
     * @param string $pattern Glob pattern
     * @return string Regex pattern
     */
    private function globToRegex( string $pattern ): string {
        $pattern = preg_quote( $pattern, '/' );
        $pattern = str_replace( '\*', '.*', $pattern );
        $pattern = str_replace( '\?', '.', $pattern );

        return '/^' . $pattern . '$/';
    }

    /**
     * Get text file extensions from configured scan types
     *
     * Since all configurable file types are text-based (from the preset list),
     * we can simply return the configured types. These are used for:
     * - Determining which files can have diffs generated
     * - Determining which files should have content cached
     * - MIME type validation whitelist
     *
     * @return array Array of text file extensions with dots (e.g., ['.php', '.js'])
     */
    private function getTextFileExtensions(): array {
        $configured_types = $this->settingsService->getScanFileTypes();

        // If no types configured, return empty array to be safe
        if ( empty( $configured_types ) ) {
            return [];
        }

        // All configured types from settings are text-based by design
        // The preset list only includes: .php, .js, .css, .html, .htm, .txt, .json, .xml, .ini, .htaccess
        return $configured_types;
    }

    /**
     * Get scan statistics
     *
     * @param array $files Scanned files
     * @return array Statistics array
     */
    public function getStatistics( array $files ): array {
        $stats = [
            'total_files' => count( $files ),
            'new_files' => 0,
            'changed_files' => 0,
            'deleted_files' => 0,
            'unchanged_files' => 0,
            'total_size' => 0,
        ];

        foreach ( $files as $file_data ) {
            $stats['total_size'] += $file_data['file_size'];

            switch ( $file_data['status'] ) {
                case 'new':
                    $stats['new_files']++;
                    break;
                case 'changed':
                    $stats['changed_files']++;
                    break;
                case 'deleted':
                    $stats['deleted_files']++;
                    break;
                case 'unchanged':
                    $stats['unchanged_files']++;
                    break;
            }
        }

        return $stats;
    }
}
