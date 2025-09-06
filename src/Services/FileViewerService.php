<?php
/**
 * File Viewer Service
 *
 * @package EightyFourEM\FileIntegrityChecker\Services
 */

namespace EightyFourEM\FileIntegrityChecker\Services;

use EightyFourEM\FileIntegrityChecker\Database\FileRecordRepository;

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
     * Files that should never be viewable
     *
     * @var array
     */
    private const BLOCKED_FILES = [
        'wp-config.php',
        'wp-config-sample.php',
        '.env',
        '.env.local',
        '.env.production',
        '.htaccess',
        '.htpasswd',
        'auth.json',
        'local.php',
        'wp-config-local.php',
        'config.php',
        'database.php',
        'db.php',
    ];

    /**
     * Path patterns that should be blocked
     *
     * @var array
     */
    private const BLOCKED_PATTERNS = [
        '/.git/',
        '/.svn/',
        '/.ssh/',
        '/credentials/',
        '/secrets/',
        '/private/',
        '/passwords/',
        '/keys/',
    ];

    /**
     * Sensitive data patterns for redaction
     *
     * @var array
     */
    private const REDACTION_PATTERNS = [
        // Database credentials
        '/define\s*\(\s*[\'"]DB_(USER|PASSWORD|HOST|NAME)[\'"][^)]+\)/i' => "define('DB_$1', '[REDACTED]')",
        
        // WordPress keys and salts
        '/define\s*\(\s*[\'"][A-Z_]*(KEY|SALT)[\'"][^)]+\)/i' => "define('[KEY_REDACTED]', '[REDACTED]')",
        
        // API keys and tokens
        '/(api[_-]?key|api[_-]?secret|token|password|secret[_-]?key|client[_-]?secret)\s*[=:]\s*[\'"]([^\'\"]{8,})[\'\"]/i' => '$1 = \'[REDACTED]\'',
        
        // AWS credentials
        '/(aws[_-]?access[_-]?key[_-]?id|aws[_-]?secret[_-]?access[_-]?key)\s*[=:]\s*[\'"][^\'\"]+[\'\"]/i' => '$1 = \'[REDACTED]\'',
        
        // Database connection strings
        '/(mysql|postgres|mongodb):\/\/[^@]+@[^\s]+/i' => '$1://[REDACTED]@[REDACTED]',
        
        // Bearer tokens
        '/Bearer\s+[a-zA-Z0-9\-_]+\.[a-zA-Z0-9\-_]+\.[a-zA-Z0-9\-_]+/i' => 'Bearer [REDACTED]',
        
        // Basic auth in URLs
        '/https?:\/\/[^:]+:[^@]+@/i' => 'https://[REDACTED]:[REDACTED]@',
    ];

    /**
     * Allowed file extensions for viewing
     *
     * @var array
     */
    private const ALLOWED_EXTENSIONS = [
        'php', 'js', 'css', 'html', 'htm', 'xml', 'json', 
        'txt', 'md', 'yml', 'yaml', 'ini', 'conf', 'log',
        'scss', 'sass', 'less', 'vue', 'jsx', 'tsx', 'ts'
    ];

    /**
     * Maximum file size for viewing (in bytes)
     *
     * @var int
     */
    private const MAX_FILE_SIZE = 1048576; // 1MB

    /**
     * Constructor
     *
     * @param FileRecordRepository $fileRecordRepository File record repository
     */
    public function __construct( FileRecordRepository $fileRecordRepository ) {
        $this->fileRecordRepository = $fileRecordRepository;
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

        // Check blocked files
        $basename = basename( $file_path );
        if ( in_array( strtolower( $basename ), array_map( 'strtolower', self::BLOCKED_FILES ) ) ) {
            return [
                'allowed' => false,
                'reason' => 'This file contains sensitive configuration and cannot be viewed for security reasons'
            ];
        }

        // Check blocked patterns
        foreach ( self::BLOCKED_PATTERNS as $pattern ) {
            if ( strpos( $file_path, $pattern ) !== false ) {
                return [
                    'allowed' => false,
                    'reason' => 'Files in this directory cannot be viewed for security reasons'
                ];
            }
        }

        // Check file extension
        $extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
        if ( ! in_array( $extension, self::ALLOWED_EXTENSIONS ) ) {
            return [
                'allowed' => false,
                'reason' => 'This file type cannot be viewed'
            ];
        }

        // Validate path is within WordPress installation
        $wp_root = ABSPATH;
        $full_path = $wp_root . ltrim( $file_path, '/' );
        $real_path = realpath( $full_path );
        
        if ( ! $real_path || strpos( $real_path, $wp_root ) !== 0 ) {
            return [
                'allowed' => false,
                'reason' => 'Invalid file path'
            ];
        }

        // Check if file exists
        if ( ! file_exists( $real_path ) ) {
            return [
                'allowed' => false,
                'reason' => 'File not found on filesystem'
            ];
        }

        // Check file size
        $file_size = filesize( $real_path );
        if ( $file_size > self::MAX_FILE_SIZE ) {
            return [
                'allowed' => false,
                'reason' => 'File is too large to view (max ' . size_format( self::MAX_FILE_SIZE ) . ')'
            ];
        }

        return [
            'allowed' => true,
            'path' => $real_path,
            'needs_redaction' => $this->needsRedaction( $file_path )
        ];
    }

    /**
     * Check if file needs redaction
     *
     * @param string $file_path File path
     * @return bool True if file may contain sensitive data
     */
    private function needsRedaction( string $file_path ): bool {
        $sensitive_files = [
            'config', 'settings', 'env', 'database', 
            'credentials', 'auth', 'secret'
        ];
        
        $basename = strtolower( basename( $file_path ) );
        
        foreach ( $sensitive_files as $keyword ) {
            if ( strpos( $basename, $keyword ) !== false ) {
                return true;
            }
        }
        
        // Check if it's in wp-content/plugins or themes config directories
        if ( preg_match( '/\/(config|settings|conf)\//i', $file_path ) ) {
            return true;
        }
        
        return false;
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
        if ( $can_view['needs_redaction'] ) {
            $content = $this->redactSensitiveData( $content, $file_path );
        }
        
        // Detect language for syntax highlighting
        $language = $this->detectLanguage( $file_path );
        
        return [
            'success' => true,
            'content' => $content,
            'language' => $language,
            'redacted' => $can_view['needs_redaction'],
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
        foreach ( self::REDACTION_PATTERNS as $pattern => $replacement ) {
            $content = preg_replace( $pattern, $replacement, $content );
        }
        
        // Additional file-specific redaction
        $basename = basename( $file_path );
        
        // For .env files, redact all values
        if ( strpos( $basename, '.env' ) !== false ) {
            $content = preg_replace( '/^([A-Z_]+)=(.+)$/m', '$1=[REDACTED]', $content );
        }
        
        // For JSON files, redact common sensitive keys
        if ( pathinfo( $file_path, PATHINFO_EXTENSION ) === 'json' ) {
            $content = preg_replace( 
                '/"(password|secret|token|key|apiKey|api_key|client_secret|private_key)"\s*:\s*"[^"]+"/i',
                '"$1": "[REDACTED]"',
                $content
            );
        }
        
        return $content;
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