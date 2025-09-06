<?php
/**
 * File Viewer Service
 *
 * @package EightyFourEM\FileIntegrityChecker\Services
 */

namespace EightyFourEM\FileIntegrityChecker\Services;

use EightyFourEM\FileIntegrityChecker\Database\FileRecordRepository;
use EightyFourEM\FileIntegrityChecker\Security\FileAccessSecurity;

/**
 * Handles secure file viewing with proper security measures
 */
class FileViewerService {
    /**
     * File record repository
     *
     * @var FileRecordRepository
     */
    private FileRecordRepository $fileRecordRepository;

    /**
     * File access security service
     *
     * @var FileAccessSecurity
     */
    private FileAccessSecurity $fileAccessSecurity;

    // All security constants and patterns have been moved to FileAccessSecurity class
    // for centralized management and to avoid duplication of security logic

    /**
     * Constructor
     *
     * @param FileRecordRepository $fileRecordRepository File record repository
     * @param FileAccessSecurity   $fileAccessSecurity   File access security service
     */
    public function __construct( FileRecordRepository $fileRecordRepository, FileAccessSecurity $fileAccessSecurity ) {
        $this->fileRecordRepository = $fileRecordRepository;
        $this->fileAccessSecurity = $fileAccessSecurity;
    }

    /**
     * Check if a file can be viewed
     *
     * @param string $file_path File path relative to WordPress root
     * @param int    $scan_id   Scan ID to verify file association
     * @return array Result with 'allowed' boolean and 'reason' if not allowed
     */
    public function canViewFile( string $file_path, int $scan_id ): array {
        // Check if file is in scan record
        if ( ! $this->fileRecordRepository->fileExistsInScan( $scan_id, $file_path ) ) {
            return [
                'allowed' => false,
                'reason' => 'File not found in scan record'
            ];
        }

        // First validate the file path is within WordPress installation
        $path_validation = $this->fileAccessSecurity->validateFilePath( $file_path );
        if ( ! $path_validation['valid'] ) {
            return [
                'allowed' => false,
                'reason' => $path_validation['reason']
            ];
        }

        // Now check if the file is accessible according to security rules
        $security_check = $this->fileAccessSecurity->isFileAccessible( $file_path );
        if ( ! $security_check['allowed'] ) {
            return [
                'allowed' => false,
                'reason' => $security_check['reason']
            ];
        }

        return [
            'allowed' => true,
            'path' => $path_validation['path'],
            'needs_redaction' => $security_check['needs_redaction'] ?? false
        ];
    }

    /**
     * Check if file needs redaction
     *
     * @param string $file_path File path
     * @return bool True if file may contain sensitive data
     */
    private function needsRedaction( string $file_path ): bool {
        // Now delegates to the centralized security service
        return $this->fileAccessSecurity->needsRedaction( $file_path );
    }

    /**
     * Get file content with security measures
     *
     * @param string $file_path File path relative to WordPress root
     * @param int    $scan_id   Scan ID to verify file association
     * @return array Result with success status and content or error
     */
    public function getFileContent( string $file_path, int $scan_id ): array {
        // Check if file can be viewed
        $can_view = $this->canViewFile( $file_path, $scan_id );
        
        if ( ! $can_view['allowed'] ) {
            return [
                'success' => false,
                'error' => $can_view['reason']
            ];
        }
        
        // Read file content
        $content = file_get_contents( $can_view['path'] );
        
        if ( $content === false ) {
            return [
                'success' => false,
                'error' => 'Failed to read file content'
            ];
        }
        
        // Apply redaction if needed
        $redaction_notice = null;
        if ( $can_view['needs_redaction'] ) {
            $content = $this->redactSensitiveData( $content, $file_path );
            // Get redaction notice from security check
            $security_check = $this->fileAccessSecurity->isFileAccessible( $file_path );
            $redaction_notice = $security_check['redaction_notice'] ?? 'Sensitive data has been redacted for security.';
        }
        
        // Detect language for syntax highlighting
        $language = $this->detectLanguage( $file_path );
        
        return [
            'success' => true,
            'content' => $content,
            'language' => $language,
            'redacted' => $can_view['needs_redaction'],
            'redaction_notice' => $redaction_notice,
            'file_path' => $file_path,
            'file_size' => filesize( $can_view['path'] ),
            'lines' => substr_count( $content, "\n" ) + 1
        ];
    }

    /**
     * Redact sensitive data from content
     *
     * @param string $content   File content
     * @param string $file_path File path for context
     * @return string Redacted content
     */
    private function redactSensitiveData( string $content, string $file_path ): string {
        // Now delegates to the centralized security service
        return $this->fileAccessSecurity->redactSensitiveData( $content, $file_path );
    }

    /**
     * Detect language for syntax highlighting
     *
     * @param string $file_path File path
     * @return string Language identifier
     */
    private function detectLanguage( string $file_path ): string {
        $extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
        
        $language_map = [
            'php' => 'php',
            'js' => 'javascript',
            'jsx' => 'jsx',
            'ts' => 'typescript',
            'tsx' => 'tsx',
            'css' => 'css',
            'scss' => 'scss',
            'sass' => 'sass',
            'less' => 'less',
            'html' => 'html',
            'htm' => 'html',
            'xml' => 'xml',
            'json' => 'json',
            'yml' => 'yaml',
            'yaml' => 'yaml',
            'md' => 'markdown',
            'txt' => 'plaintext',
            'log' => 'plaintext',
            'ini' => 'ini',
            'conf' => 'plaintext',
            'vue' => 'vue',
        ];
        
        return $language_map[ $extension ] ?? 'plaintext';
    }
}