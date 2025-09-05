<?php
/**
 * File Scanner
 *
 * @package EightyFourEM\FileIntegrityChecker\Scanner
 */

namespace EightyFourEM\FileIntegrityChecker\Scanner;

use EightyFourEM\FileIntegrityChecker\Services\SettingsService;

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
     * Constructor
     *
     * @param ChecksumGenerator $checksumGenerator Checksum generator
     * @param SettingsService   $settingsService   Settings service
     */
    public function __construct( ChecksumGenerator $checksumGenerator, SettingsService $settingsService ) {
        $this->checksumGenerator = $checksumGenerator;
        $this->settingsService   = $settingsService;
    }

    /**
     * Scan directory for files
     *
     * @param string $directory      Directory to scan
     * @param callable|null $progress_callback Progress callback function
     * @return array Array of file information
     */
    public function scanDirectory( string $directory = ABSPATH, callable $progress_callback = null ): array {
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

        // Build lookup array for previous files
        $previous_lookup = [];
        foreach ( $previous_files as $file_record ) {
            $previous_lookup[ $file_record->file_path ] = $file_record;
        }

        // Check current files against previous
        foreach ( $current_files as $file_data ) {
            $file_path = $file_data['file_path'];

            if ( isset( $previous_lookup[ $file_path ] ) ) {
                $previous_record = $previous_lookup[ $file_path ];
                
                if ( $file_data['checksum'] !== $previous_record->checksum ) {
                    // File has changed
                    $file_data['status'] = 'changed';
                    $file_data['previous_checksum'] = $previous_record->checksum;
                    
                    // Generate diff for text files
                    $file_data['diff_content'] = $this->generateFileDiff( 
                        $file_path, 
                        $previous_record->checksum 
                    );
                } else {
                    // File is unchanged
                    $file_data['status'] = 'unchanged';
                }
            } else {
                // New file
                $file_data['status'] = 'new';
            }

            $updated_files[] = $file_data;
        }

        // Check for deleted files
        $current_paths = array_column( $current_files, 'file_path' );
        foreach ( $previous_lookup as $file_path => $previous_record ) {
            if ( ! in_array( $file_path, $current_paths, true ) ) {
                // File was deleted
                $updated_files[] = [
                    'file_path' => $file_path,
                    'file_size' => $previous_record->file_size,
                    'checksum' => '', // Empty for deleted files
                    'last_modified' => $previous_record->last_modified,
                    'status' => 'deleted',
                    'previous_checksum' => $previous_record->checksum,
                ];
            }
        }

        return $updated_files;
    }

    /**
     * Generate diff content for a changed file
     *
     * @param string $file_path Path to the file
     * @param string $previous_checksum Previous checksum to compare
     * @return string|null Diff content or null if unable to generate
     */
    private function generateFileDiff( string $file_path, string $previous_checksum ): ?string {
        // Only generate diffs for text files
        $text_extensions = [ 'php', 'js', 'css', 'html', 'htm', 'txt', 'json', 'xml', 'ini', 'htaccess', 'sql', 'md' ];
        $extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
        
        if ( ! in_array( $extension, $text_extensions, true ) ) {
            return null;
        }
        
        // Check if file is too large for diff (limit to 1MB)
        if ( filesize( $file_path ) > 1048576 ) {
            return 'File too large for diff generation';
        }
        
        // Get current content
        $current_content = file_get_contents( $file_path );
        if ( $current_content === false ) {
            return null;
        }
        
        // Try to get previous content from most recent backup or previous scan
        // For now, we'll store a simple summary of changes
        // In a production environment, you might want to store full file snapshots
        // or integrate with a version control system
        
        $diff_summary = [
            'timestamp' => current_time( 'mysql' ),
            'checksum_changed' => [
                'from' => $previous_checksum,
                'to' => hash_file( 'sha256', $file_path )
            ],
            'file_size' => filesize( $file_path ),
            'lines_count' => substr_count( $current_content, "\n" ) + 1,
        ];
        
        // Store first/last few lines for context
        $lines = explode( "\n", $current_content );
        $diff_summary['preview'] = [
            'first_lines' => array_slice( $lines, 0, 5 ),
            'last_lines' => array_slice( $lines, -5 ),
            'total_lines' => count( $lines )
        ];
        
        return json_encode( $diff_summary );
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

        // Check file extension
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

        // Additional WordPress-specific exclusions
        $wp_exclusions = [
            '/wp-content/cache/',
            '/wp-content/backups/',
            '/wp-content/upgrade/',
            '/.git/',
            '/.svn/',
            '/node_modules/',
            '/vendor/',
        ];

        foreach ( $wp_exclusions as $exclusion ) {
            if ( strpos( $file_path, $exclusion ) !== false ) {
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