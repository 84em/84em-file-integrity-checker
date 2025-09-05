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
        
        // Try to get the previous version using git if available
        $previous_content = $this->getPreviousFileContent( $file_path );
        
        if ( $previous_content !== null ) {
            // Generate a unified diff
            $diff = $this->generateUnifiedDiff( $previous_content, $current_content, $file_path );
            return $diff;
        }
        
        // If we can't get the previous content, store current content for next time
        // and return a summary
        $diff_summary = [
            'type' => 'summary',
            'timestamp' => current_time( 'mysql' ),
            'checksum_changed' => [
                'from' => $previous_checksum,
                'to' => hash_file( 'sha256', $file_path )
            ],
            'file_size' => filesize( $file_path ),
            'lines_count' => substr_count( $current_content, "\n" ) + 1,
            'message' => 'Previous version not available. Full diff will be available on next change.'
        ];
        
        return json_encode( $diff_summary );
    }
    
    /**
     * Try to get previous file content using git or other methods
     *
     * @param string $file_path Path to the file
     * @return string|null Previous content or null if not available
     */
    private function getPreviousFileContent( string $file_path ): ?string {
        // Check if git is available and file is in a git repository
        $git_root = $this->findGitRoot( dirname( $file_path ) );
        if ( $git_root !== null ) {
            // Try to get the last committed version of the file
            $relative_path = str_replace( $git_root . '/', '', $file_path );
            $command = sprintf(
                'cd %s && git show HEAD:%s 2>/dev/null',
                escapeshellarg( $git_root ),
                escapeshellarg( $relative_path )
            );
            
            $output = shell_exec( $command );
            if ( $output !== null && $output !== '' ) {
                return $output;
            }
        }
        
        // Try to get from database if we stored it in a previous scan
        // (would need to implement file content storage in future version)
        
        return null;
    }
    
    /**
     * Find git repository root
     *
     * @param string $path Starting path
     * @return string|null Git root path or null if not in a git repo
     */
    private function findGitRoot( string $path ): ?string {
        $current = $path;
        while ( $current !== '/' && $current !== '' ) {
            if ( is_dir( $current . '/.git' ) ) {
                return $current;
            }
            $current = dirname( $current );
        }
        return null;
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
        $old_lines = explode( "\n", $old_content );
        $new_lines = explode( "\n", $new_content );
        
        // Use PHP's built-in diff algorithm or external diff command
        $temp_old = tempnam( sys_get_temp_dir(), 'diff_old_' );
        $temp_new = tempnam( sys_get_temp_dir(), 'diff_new_' );
        
        file_put_contents( $temp_old, $old_content );
        file_put_contents( $temp_new, $new_content );
        
        // Use system diff command if available
        $diff_command = sprintf(
            'diff -u %s %s 2>/dev/null',
            escapeshellarg( $temp_old ),
            escapeshellarg( $temp_new )
        );
        
        $diff = shell_exec( $diff_command );
        
        // Clean up temp files
        @unlink( $temp_old );
        @unlink( $temp_new );
        
        if ( $diff === null ) {
            // Fallback to simple line-by-line comparison
            return $this->simpleLineDiff( $old_lines, $new_lines );
        }
        
        // Replace temp file names with actual file path in diff output
        $diff = preg_replace( '/^---.*$/m', '--- ' . $file_path . ' (previous)', $diff );
        $diff = preg_replace( '/^\+\+\+.*$/m', '+++ ' . $file_path . ' (current)', $diff );
        
        return $diff;
    }
    
    /**
     * Generate a simple line-by-line diff
     *
     * @param array $old_lines Old content lines
     * @param array $new_lines New content lines
     * @return string Simple diff
     */
    private function simpleLineDiff( array $old_lines, array $new_lines ): string {
        $diff = [];
        $max_lines = max( count( $old_lines ), count( $new_lines ) );
        
        for ( $i = 0; $i < $max_lines; $i++ ) {
            $old_line = $old_lines[$i] ?? null;
            $new_line = $new_lines[$i] ?? null;
            
            if ( $old_line === $new_line ) {
                // Unchanged line (show for context)
                if ( $i < 3 || $i > $max_lines - 3 ) {
                    $diff[] = ' ' . $old_line;
                }
            } elseif ( $old_line === null ) {
                // Added line
                $diff[] = '+ ' . $new_line;
            } elseif ( $new_line === null ) {
                // Removed line
                $diff[] = '- ' . $old_line;
            } else {
                // Changed line
                $diff[] = '- ' . $old_line;
                $diff[] = '+ ' . $new_line;
            }
        }
        
        return implode( "\n", $diff );
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
        $file_extension = strtolower( $file_info->getExtension() );

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