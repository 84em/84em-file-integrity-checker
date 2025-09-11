<?php
/**
 * Centralized File Access Security Service
 *
 * @package EightyFourEM\FileIntegrityChecker\Security
 */

namespace EightyFourEM\FileIntegrityChecker\Security;

/**
 * Centralized security service for file access control
 * Used by both FileViewerService and FileScanner to ensure consistent security
 */
class FileAccessSecurity {
    
    /**
     * Files that should never be viewable or have diffs generated
     * This is the single source of truth for completely blocked files
     *
     * @var array
     */
    private const BLOCKED_FILES = [
        // WordPress configuration
        'wp-config.php',
        'wp-config-sample.php',
        'wp-config-local.php',
        'wp-cache-config.php',
        'advanced-cache.php',
        'object-cache.php',
        'db-error.php',
        'sunrise.php',
        
        // Environment files
        '.env',
        '.env.local',
        '.env.production',
        '.env.development',
        '.env.staging',
        '.env.test',
        
        // Server configuration
        '.htaccess',
        '.htpasswd',
        '.user.ini',
        'php.ini',
        '.my.cnf',
        'nginx.conf',
        'httpd.conf',
        
        // Authentication and credentials
        'auth.json',
        'local.php',
        'config.php',
        'database.php',
        'db.php',
        'credentials.php',
        'secrets.php',
        'api-keys.php',
        
        // SSH keys
        'id_rsa',
        'id_rsa.pub',
        'id_dsa',
        'id_dsa.pub',
        'id_ecdsa',
        'id_ecdsa.pub',
        'id_ed25519',
        'id_ed25519.pub',
        'known_hosts',
        'authorized_keys',
        
        // System files
        'passwd',
        'shadow',
        'group',
        'sudoers',
    ];
    
    /**
     * File extensions that should never be accessible
     * Single source of truth for blocked extensions
     *
     * @var array
     */
    private const BLOCKED_EXTENSIONS = [
        // Private keys and certificates
        'key',
        'pem',
        'crt',
        'csr',
        'p12',
        'pfx',
        'cer',
        'der',
        'p7b',
        'p7c',
        'jks',
        'keystore',
        'ppk',
        
        // Database dumps and backups
        'sql',
        'dump',
        'bak',
        'backup',
        'old',
        'orig',
        'save',
        'swp',
        'tmp',
        'temp',
        'cache',
        
        // Password files
        'passwd',
        'shadow',
        'pwd',
        'psw',
        
        // Archive files (may contain sensitive data)
        'tar',
        'gz',
        'bz2',
        'zip',
        'rar',
        '7z',
        
        // Binary and executable files
        'exe',
        'dll',
        'so',
        'dylib',
        'bin',
        'dat',
    ];
    
    /**
     * Path patterns that should be blocked
     * Single source of truth for directory patterns
     *
     * @var array
     */
    private const BLOCKED_PATTERNS = [
        // Version control
        '/.git/',
        '/.svn/',
        '/.hg/',
        '/.bzr/',
        
        // SSH and keys
        '/.ssh/',
        '/keys/',
        '/certs/',
        '/certificates/',
        '/private/',
        
        // Cloud provider credentials
        '/.aws/',
        '/.azure/',
        '/.gcloud/',
        '/.docker/',
        '/.kube/',
        
        // Sensitive directories
        '/credentials/',
        '/secrets/',
        '/passwords/',
        '/backup/',
        '/backups/',
        '/config/',
        '/configs/',
        '/settings/',
        
        // System directories
        '/.well-known/',
        '/node_modules/',
        '/vendor/composer/auth.json',
        
        // WordPress sensitive areas
        '/wp-content/upgrade/',
        '/wp-content/updraft/',
        '/wp-content/wflogs/',
        '/wp-content/cache/',
        '/wp-content/w3tc-config/',
    ];
    
    
    /**
     * Allowed file extensions for viewing
     * These are considered safe to view
     *
     * @var array
     */
    private const ALLOWED_EXTENSIONS = [
        'php', 'js', 'css', 'html', 'htm', 'xml', 'json', 
        'txt', 'md', 'yml', 'yaml', 'ini', 'conf', 'log',
        'scss', 'sass', 'less', 'vue', 'jsx', 'tsx', 'ts',
        'tpl', 'twig', 'blade.php', 'phtml'
    ];
    
    /**
     * Maximum file size for viewing (in bytes)
     *
     * @var int
     */
    private const MAX_FILE_SIZE = 1048576; // 1MB
    
    /**
     * Check if a file is accessible based on security rules
     *
     * @param string $file_path File path to check
     * @return array Result with 'allowed' boolean and 'reason' if not allowed
     */
    public function isFileAccessible( string $file_path ): array {
        // Normalize path for security
        $file_path = $this->normalizePath( $file_path );
        
        // Check completely blocked files (never viewable)
        $basename = basename( $file_path );
        if ( $this->isBlockedFile( $basename ) ) {
            return [
                'allowed' => false,
                'reason' => 'This file contains sensitive information and cannot be viewed for security reasons'
            ];
        }
        
        // Check blocked extensions
        $extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
        if ( $this->isBlockedExtension( $extension ) ) {
            return [
                'allowed' => false,
                'reason' => 'This file type cannot be accessed for security reasons'
            ];
        }
        
        // Check blocked patterns
        if ( $this->matchesBlockedPattern( $file_path ) ) {
            return [
                'allowed' => false,
                'reason' => 'Files in this directory cannot be accessed for security reasons'
            ];
        }
        
        // Check for sensitive keywords in filename
        if ( $this->hasSensitiveKeywords( $basename ) ) {
            return [
                'allowed' => false,
                'reason' => 'File name suggests sensitive content'
            ];
        }
        
        // Check if extension is allowed for viewing
        if ( ! $this->isAllowedExtension( $extension ) && ! empty( $extension ) ) {
            return [
                'allowed' => false,
                'reason' => 'This file type cannot be viewed'
            ];
        }
        
        return [
            'allowed' => true
        ];
    }
    
    /**
     * Validate file path is within WordPress installation
     *
     * @param string $file_path File path to validate
     * @return array Result with 'valid' boolean and resolved 'path' or 'reason'
     */
    public function validateFilePath( string $file_path ): array {
        $wp_root = rtrim( ABSPATH, '/' );
        
        // Normalize and validate the path BEFORE any file operations
        $file_path = $this->normalizePath( $file_path );
        
        // Handle both absolute and relative paths securely
        if ( str_starts_with( $file_path, $wp_root ) ) {
            // Path appears to be absolute
            $full_path = $file_path;
        } else {
            // Path is relative to WordPress root
            $full_path = $wp_root . '/' . $file_path;
        }
        
        // Get the real path and validate it's within WordPress root
        $real_path = realpath( $full_path );
        
        if ( $real_path === false ) {
            return [
                'valid' => false,
                'reason' => 'File path does not exist'
            ];
        }
        
        // Use realpath on wp_root too for accurate comparison
        $real_wp_root = realpath( $wp_root );
        if ( ! str_starts_with( $real_path, $real_wp_root ) ) {
            return [
                'valid' => false,
                'reason' => 'File path is outside WordPress installation'
            ];
        }
        
        // Check file exists
        if ( ! file_exists( $real_path ) ) {
            return [
                'valid' => false,
                'reason' => 'File not found on filesystem'
            ];
        }
        
        // Check file size
        $file_size = filesize( $real_path );
        if ( $file_size > self::MAX_FILE_SIZE ) {
            return [
                'valid' => false,
                'reason' => 'File is too large to view (max ' . size_format( self::MAX_FILE_SIZE ) . ')'
            ];
        }
        
        return [
            'valid' => true,
            'path' => $real_path,
            'size' => $file_size
        ];
    }
    
    
    
    
    
    
    /**
     * Check if a file is in the blocked list
     *
     * @param string $basename File basename
     * @return bool
     */
    private function isBlockedFile( string $basename ): bool {
        return in_array( strtolower( $basename ), array_map( 'strtolower', self::BLOCKED_FILES ) );
    }
    
    
    /**
     * Check if an extension is blocked
     *
     * @param string $extension File extension
     * @return bool
     */
    private function isBlockedExtension( string $extension ): bool {
        return in_array( $extension, self::BLOCKED_EXTENSIONS );
    }
    
    /**
     * Check if an extension is allowed
     *
     * @param string $extension File extension
     * @return bool
     */
    private function isAllowedExtension( string $extension ): bool {
        return in_array( $extension, self::ALLOWED_EXTENSIONS );
    }
    
    /**
     * Check if path matches blocked patterns
     *
     * @param string $file_path File path
     * @return bool
     */
    private function matchesBlockedPattern( string $file_path ): bool {
        foreach ( self::BLOCKED_PATTERNS as $pattern ) {
            if ( strpos( $file_path, $pattern ) !== false ) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Check if filename has sensitive keywords
     *
     * @param string $basename File basename
     * @return bool
     */
    private function hasSensitiveKeywords( string $basename ): bool {
        $sensitive_keywords = [
            'password', 'secret', 'credential', 'token', 
            'key', 'auth', 'private', 'cert', 'certificate',
            'rsa', 'dsa', 'ecdsa', 'ed25519', 'ppk'
        ];
        
        $lower_basename = strtolower( $basename );
        foreach ( $sensitive_keywords as $keyword ) {
            if ( strpos( $lower_basename, $keyword ) !== false ) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Normalize file path for security checks
     *
     * @param string $file_path File path
     * @return string Normalized path
     */
    private function normalizePath( string $file_path ): string {
        // First, decode any URL-encoded characters to prevent bypasses
        $file_path = urldecode( $file_path );
        
        // Remove null bytes which can be used to bypass extension checks
        $file_path = str_replace( chr(0), '', $file_path );
        
        // Remove any backslashes and convert to forward slashes for consistency
        $file_path = str_replace( '\\', '/', $file_path );
        
        // Collapse multiple slashes into single slashes
        $file_path = preg_replace( '#/+#', '/', $file_path );
        
        // Remove any protocol handlers that could be used for remote file inclusion
        $file_path = preg_replace( '#^[a-z]+://#i', '', $file_path );
        
        // Only trim ./ if it's at the beginning of a relative path (not for files starting with .)
        if ( str_starts_with( $file_path, './' ) && strlen( $file_path ) > 2 ) {
            $file_path = substr( $file_path, 2 );
        }
        
        // The actual directory traversal protection is handled by realpath() in validateFilePath()
        // We don't strip '..' here as it would break legitimate paths and create false security
        // Instead, we rely on realpath() to resolve the path and then verify it's within bounds
        
        return $file_path;
    }
    
}