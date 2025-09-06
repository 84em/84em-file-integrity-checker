<?php
/**
 * File Scanner
 *
 * @package EightyFourEM\FileIntegrityChecker\Scanner
 */

namespace EightyFourEM\FileIntegrityChecker\Scanner;

use EightyFourEM\FileIntegrityChecker\Services\SettingsService;
use EightyFourEM\FileIntegrityChecker\Security\FileAccessSecurity;
use EightyFourEM\FileIntegrityChecker\Database\FileContentRepository;
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
     * File content repository
     *
     * @var FileContentRepository
     */
    private FileContentRepository $fileContentRepository;
    
    /**
     * File access security service
     *
     * @var FileAccessSecurity
     */
    private FileAccessSecurity $fileAccessSecurity;

    /**
     * Constructor
     *
     * @param ChecksumGenerator $checksumGenerator Checksum generator
     * @param SettingsService   $settingsService   Settings service
     * @param FileAccessSecurity $fileAccessSecurity File access security service
     */
    public function __construct( ChecksumGenerator $checksumGenerator, SettingsService $settingsService, FileAccessSecurity $fileAccessSecurity ) {
        $this->checksumGenerator = $checksumGenerator;
        $this->settingsService   = $settingsService;
        $this->fileAccessSecurity = $fileAccessSecurity;
        $this->fileContentRepository = new FileContentRepository();
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
        $exclude_patterns = $this->settingsService->getExcludePatterns();
        $max_file_size = $this->settingsService->getMaxFileSize();

        // Build lookup array for previous files that match current scan criteria
        $previous_lookup = [];
        foreach ( $previous_files as $file_record ) {
            // Only include previous files that would be scanned with current settings
            if ( $this->shouldIncludeFileInComparison( $file_record->file_path, $current_file_types ) ) {
                $previous_lookup[ $file_record->file_path ] = $file_record;
            }
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
                    
                    // Store content if we don't have it yet
                    $this->storeFileContentIfNeeded( $file_path, $file_data['checksum'] );
                }
            } else {
                // New file - store its content for future diffs
                $file_data['status'] = 'new';
                
                // Store content for future comparisons
                $this->storeFileContentIfNeeded( $file_path, $file_data['checksum'] );
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
     * Generate diff content for a changed file
     *
     * @param string $file_path Path to the file
     * @param string $previous_checksum Previous checksum to compare
     * @return string|null Diff content or null if unable to generate
     */
    private function generateFileDiff( string $file_path, string $previous_checksum ): ?string {
        // First check if this file is allowed to have diffs generated
        $security_check = $this->fileAccessSecurity->isFileAccessible( $file_path );
        if ( ! $security_check['allowed'] ) {
            // Return null for blocked files - no diff will be generated
            // The UI will handle showing the security message
            return null;
        }
        
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
        
        // Get the previous content from database
        $previous_content = $this->fileContentRepository->get( $previous_checksum );
        
        if ( $previous_content !== null ) {
            // Generate a unified diff
            $diff = $this->generateUnifiedDiff( $previous_content, $current_content, $file_path );
            
            // Store current content for next time
            $current_checksum = hash( 'sha256', $current_content );
            $this->fileContentRepository->store( $current_checksum, $current_content );
            
            return $diff;
        }
        
        // If we can't get the previous content, store current content for next time
        $current_checksum = hash( 'sha256', $current_content );
        $this->fileContentRepository->store( $current_checksum, $current_content );
        
        // Return a summary since we don't have previous content
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
     * Store file content if needed for future diffs
     *
     * @param string $file_path Path to the file
     * @param string $checksum File checksum
     * @return void
     */
    private function storeFileContentIfNeeded( string $file_path, string $checksum ): void {
        // Only store text files under 1MB
        $text_extensions = [ 'php', 'js', 'css', 'html', 'htm', 'txt', 'json', 'xml', 'ini', 'htaccess', 'sql', 'md' ];
        $extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
        
        if ( ! in_array( $extension, $text_extensions, true ) ) {
            return;
        }
        
        if ( ! file_exists( $file_path ) || filesize( $file_path ) > 1048576 ) {
            return;
        }
        
        // Check if we already have this content stored
        if ( $this->fileContentRepository->exists( $checksum ) ) {
            return;
        }
        
        // Read and store the content
        $content = file_get_contents( $file_path );
        if ( $content !== false ) {
            $this->fileContentRepository->store( $checksum, $content );
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
        // Use secure PHP-native diff generation instead of shell commands
        $diff_generator = new DiffGenerator();
        return $diff_generator->generateUnifiedDiff( $old_content, $new_content, $file_path );
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
        
        // Additional MIME type validation for executable files
        // This helps prevent scanning of renamed malicious files
        if ( $this->isPotentiallyDangerousFile( $file_path, $file_extension ) ) {
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
     * Check if a file is potentially dangerous based on MIME type
     *
     * @param string $file_path Path to the file
     * @param string $file_extension File extension with dot
     * @return bool True if file is potentially dangerous
     */
    private function isPotentiallyDangerousFile( string $file_path, string $file_extension ): bool {
        // Whitelist of safe text-based extensions that don't need MIME checking
        $safe_text_extensions = [
            '.php', '.js', '.css', '.html', '.htm', '.txt', '.json', 
            '.xml', '.ini', '.htaccess', '.sql', '.md', '.yml', '.yaml',
            '.scss', '.less', '.ts', '.tsx', '.jsx', '.vue'
        ];
        
        // If it's a known safe text file, allow it
        if ( in_array( $file_extension, $safe_text_extensions, true ) ) {
            return false;
        }
        
        // Check MIME type for other files
        if ( function_exists( 'finfo_open' ) && file_exists( $file_path ) ) {
            $finfo = finfo_open( FILEINFO_MIME_TYPE );
            $mime_type = finfo_file( $finfo, $file_path );
            finfo_close( $finfo );
            
            // Block executable MIME types even if renamed
            $dangerous_mime_types = [
                'application/x-executable',
                'application/x-sharedlib',
                'application/x-elf',
                'application/x-mach-binary',
                'application/x-msdownload',
                'application/x-msdos-program',
                'application/x-dosexec'
            ];
            
            if ( in_array( $mime_type, $dangerous_mime_types, true ) ) {
                return true;
            }
            
            // Check for PHP code in files with non-PHP extensions
            if ( $file_extension !== '.php' && strpos( $mime_type, 'php' ) !== false ) {
                return true;
            }
        }
        
        return false;
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