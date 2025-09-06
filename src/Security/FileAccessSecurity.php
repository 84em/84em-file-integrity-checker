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
     * Files that require mandatory redaction when viewed
     * These files can be viewed but sensitive data must be redacted
     *
     * @var array
     */
    private const REDACTED_FILES = [
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
     * Sensitive content patterns that indicate credentials
     * Used for content inspection
     *
     * @var array
     */
    private const SENSITIVE_CONTENT_PATTERNS = [
        // Database credentials
        '/define\s*\(\s*[\'"]DB_(USER|PASSWORD|HOST|NAME)[\'"]/i',
        
        // WordPress keys and salts
        '/define\s*\(\s*[\'"][A-Z_]*(KEY|SALT)[\'"]/i',
        
        // API keys and tokens
        '/(api[_-]?key|api[_-]?secret|token|password|secret[_-]?key|client[_-]?secret|private[_-]?key)\s*[=:]/i',
        
        // AWS credentials
        '/(aws[_-]?access[_-]?key[_-]?id|aws[_-]?secret[_-]?access[_-]?key)/i',
        
        // Database connection strings
        '/(mysql|postgres|postgresql|mongodb|redis|memcached):\/\//i',
        
        // Bearer tokens
        '/Bearer\s+[a-zA-Z0-9\-_]+\.[a-zA-Z0-9\-_]+\.[a-zA-Z0-9\-_]+/i',
        
        // Basic auth in URLs
        '/https?:\/\/[^:]+:[^@]+@/i',
        
        // SSH keys
        '/-----BEGIN\s+(RSA|DSA|EC|OPENSSH|ENCRYPTED)?\s*PRIVATE\s+KEY-----/i',
        
        // SSL certificates
        '/-----BEGIN\s+CERTIFICATE-----/i',
        
        // Environment variables with sensitive names
        '/^(DB_|DATABASE_|MYSQL_|POSTGRES_|REDIS_|AWS_|S3_|SECRET_|API_|TOKEN_|KEY_|PASS|PWD)/m',
        
        // OAuth tokens
        '/(oauth[_-]?token|access[_-]?token|refresh[_-]?token)\s*[=:]\s*[\'"][^\'"]+[\'"]/i',
        
        // Private IPs and internal URLs
        '/(10\.\d{1,3}\.\d{1,3}\.\d{1,3}|172\.(1[6-9]|2[0-9]|3[01])\.\d{1,3}\.\d{1,3}|192\.168\.\d{1,3}\.\d{1,3})/i',
    ];
    
    /**
     * Redaction patterns for sanitizing sensitive data
     * Maps patterns to their replacement strings
     *
     * @var array
     */
    private const REDACTION_PATTERNS = [
        // Database credentials - more specific pattern to preserve the constant name
        '/define\s*\(\s*[\'"]DB_USER[\'"]\s*,\s*[\'"][^\'\"]*[\'\"]\s*\)/i' 
            => "define('DB_USER', '[REDACTED]')",
        '/define\s*\(\s*[\'"]DB_PASSWORD[\'"]\s*,\s*[\'"][^\'\"]*[\'\"]\s*\)/i' 
            => "define('DB_PASSWORD', '[REDACTED]')",
        '/define\s*\(\s*[\'"]DB_HOST[\'"]\s*,\s*[\'"][^\'\"]*[\'\"]\s*\)/i' 
            => "define('DB_HOST', '[REDACTED]')",
        '/define\s*\(\s*[\'"]DB_NAME[\'"]\s*,\s*[\'"][^\'\"]*[\'\"]\s*\)/i' 
            => "define('DB_NAME', '[REDACTED]')",
        
        // WordPress keys and salts - preserve the key name
        '/define\s*\(\s*[\'"]([A-Z_]*(?:KEY|SALT))[\'"]\s*,\s*[\'"][^\'\"]*[\'\"]\s*\)/i' 
            => "define('$1', '[REDACTED]')",
        
        // API keys and tokens
        '/(api[_-]?key|api[_-]?secret|token|password|secret[_-]?key|client[_-]?secret)\s*[=:]\s*[\'"]([^\'\"]{8,})[\'\"]/i' 
            => '$1 = \'[REDACTED]\'',
        
        // AWS credentials
        '/(aws[_-]?access[_-]?key[_-]?id|aws[_-]?secret[_-]?access[_-]?key)\s*[=:]\s*[\'"][^\'\"]+[\'\"]/i' 
            => '$1 = \'[REDACTED]\'',
        
        // Database connection strings
        '/(mysql|postgres|postgresql|mongodb|redis):\/\/[^@]+@[^\s]+/i' 
            => '$1://[REDACTED]@[REDACTED]',
        
        // Bearer tokens
        '/Bearer\s+[a-zA-Z0-9\-_]+\.[a-zA-Z0-9\-_]+\.[a-zA-Z0-9\-_]+/i' 
            => 'Bearer [REDACTED]',
        
        // Basic auth in URLs
        '/https?:\/\/[^:]+:[^@]+@/i' 
            => 'https://[REDACTED]:[REDACTED]@',
        
        // SSH private keys
        '/-----BEGIN\s+(RSA|DSA|EC|OPENSSH|ENCRYPTED)?\s*PRIVATE\s+KEY-----[\s\S]+?-----END\s+(RSA|DSA|EC|OPENSSH|ENCRYPTED)?\s*PRIVATE\s+KEY-----/i' 
            => '-----BEGIN PRIVATE KEY-----[REDACTED]-----END PRIVATE KEY-----',
        
        // Environment variables
        '/^([A-Z_]+(PASSWORD|SECRET|KEY|TOKEN|API))\s*=\s*(.+)$/m' 
            => '$1=[REDACTED]',
        
        // OAuth tokens
        '/(oauth[_-]?token|access[_-]?token|refresh[_-]?token)\s*[=:]\s*[\'"][^\'"]+[\'"]/i'
            => '$1 = \'[REDACTED]\'',
        
        // Email passwords
        '/(smtp[_-]?password|mail[_-]?password|email[_-]?password)\s*[=:]\s*[\'"][^\'"]+[\'"]/i'
            => '$1 = \'[REDACTED]\'',
        
        // FTP credentials
        '/(ftp[_-]?password|ftp[_-]?user)\s*[=:]\s*[\'"][^\'"]+[\'"]/i'
            => '$1 = \'[REDACTED]\'',
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
                'reason' => 'This file contains sensitive configuration and cannot be accessed for security reasons'
            ];
        }
        
        // Check if this is a redacted file (viewable with redaction)
        // These files bypass extension checks since they need special handling
        if ( $this->isRedactedFile( $basename ) ) {
            return [
                'allowed' => true,
                'needs_redaction' => true,
                'redaction_notice' => 'This file contains sensitive information. All passwords, keys, and credentials have been redacted for security.'
            ];
        }
        
        // Check blocked extensions (skip for files already checked above)
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
        // Special case: files starting with dot (like .env, .htaccess) that are in the redacted list
        // are already handled above, so we don't need to check extensions for them
        if ( ! $this->isAllowedExtension( $extension ) && ! empty( $extension ) ) {
            return [
                'allowed' => false,
                'reason' => 'This file type cannot be viewed'
            ];
        }
        
        return [
            'allowed' => true,
            'needs_redaction' => $this->needsRedaction( $file_path )
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
        if ( strpos( $file_path, $wp_root ) === 0 ) {
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
        if ( strpos( $real_path, $real_wp_root ) !== 0 ) {
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
     * Check if content contains sensitive data
     *
     * @param string $content Content to check
     * @return bool True if content contains sensitive data
     */
    public function containsSensitiveData( string $content ): bool {
        foreach ( self::SENSITIVE_CONTENT_PATTERNS as $pattern ) {
            if ( preg_match( $pattern, $content ) ) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Redact sensitive data from content
     *
     * @param string $content Content to redact
     * @param string $file_path File path for context
     * @return string Redacted content
     */
    public function redactSensitiveData( string $content, string $file_path ): string {
        // Apply all redaction patterns
        foreach ( self::REDACTION_PATTERNS as $pattern => $replacement ) {
            $content = preg_replace( $pattern, $replacement, $content );
        }
        
        // Additional file-specific redaction
        $content = $this->applyFileSpecificRedaction( $content, $file_path );
        
        return $content;
    }
    
    /**
     * Redact sensitive data from diff content
     *
     * @param string $diff_content Diff content to redact
     * @param string $file_path File path for context
     * @return string Redacted diff content
     */
    public function redactDiffContent( string $diff_content, string $file_path ): string {
        // First apply standard redaction
        $diff_content = $this->redactSensitiveData( $diff_content, $file_path );
        
        // Additional diff-specific redaction for .env files
        $basename = basename( $file_path );
        if ( strpos( $basename, '.env' ) !== false ) {
            // Redact added lines
            $diff_content = preg_replace( '/^\+([A-Z_]+)=(.+)$/m', '+$1=[REDACTED]', $diff_content );
            // Redact removed lines
            $diff_content = preg_replace( '/^-([A-Z_]+)=(.+)$/m', '-$1=[REDACTED]', $diff_content );
            // Redact context lines
            $diff_content = preg_replace( '/^ ([A-Z_]+)=(.+)$/m', ' $1=[REDACTED]', $diff_content );
        }
        
        return $diff_content;
    }
    
    /**
     * Check if file needs redaction
     *
     * @param string $file_path File path
     * @return bool True if file may contain sensitive data
     */
    public function needsRedaction( string $file_path ): bool {
        $sensitive_keywords = [
            'config', 'settings', 'env', 'database', 
            'credentials', 'auth', 'secret', 'api',
            'smtp', 'mail', 'email', 'ftp', 'ssh',
            'token', 'key', 'password', 'oauth'
        ];
        
        $basename = strtolower( basename( $file_path ) );
        
        // Check filename for sensitive keywords
        foreach ( $sensitive_keywords as $keyword ) {
            if ( strpos( $basename, $keyword ) !== false ) {
                return true;
            }
        }
        
        // Check if it's in sensitive directories
        $sensitive_dirs = [
            '/config/', '/settings/', '/conf/', '/includes/config/',
            '/credentials/', '/secrets/', '/private/', '/secure/',
            '/wp-admin/includes/', '/wp-admin/network/'
        ];
        
        foreach ( $sensitive_dirs as $dir ) {
            if ( stripos( $file_path, $dir ) !== false ) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get list of blocked files
     *
     * @return array
     */
    public function getBlockedFiles(): array {
        return self::BLOCKED_FILES;
    }
    
    /**
     * Get list of blocked extensions
     *
     * @return array
     */
    public function getBlockedExtensions(): array {
        return self::BLOCKED_EXTENSIONS;
    }
    
    /**
     * Get list of blocked patterns
     *
     * @return array
     */
    public function getBlockedPatterns(): array {
        return self::BLOCKED_PATTERNS;
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
     * Check if a file requires mandatory redaction
     *
     * @param string $basename File basename
     * @return bool
     */
    private function isRedactedFile( string $basename ): bool {
        return in_array( strtolower( $basename ), array_map( 'strtolower', self::REDACTED_FILES ) );
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
        // Remove any directory traversal attempts
        $file_path = str_replace( '..', '', $file_path );
        $file_path = str_replace( '//', '/', $file_path );
        
        // Only trim ./ if it's at the beginning of a relative path (not for files starting with .)
        if ( strpos( $file_path, './' ) === 0 && strlen( $file_path ) > 2 ) {
            $file_path = substr( $file_path, 2 );
        }
        
        return $file_path;
    }
    
    /**
     * Apply file-specific redaction rules
     *
     * @param string $content Content to redact
     * @param string $file_path File path for context
     * @return string Redacted content
     */
    private function applyFileSpecificRedaction( string $content, string $file_path ): string {
        $basename = basename( $file_path );
        $extension = pathinfo( $file_path, PATHINFO_EXTENSION );
        
        // For .env files, redact all values
        if ( strpos( $basename, '.env' ) !== false ) {
            $content = preg_replace( '/^([A-Z_]+)=(.+)$/m', '$1=[REDACTED]', $content );
        }
        
        // For JSON files, redact common sensitive keys
        if ( $extension === 'json' ) {
            $json_keys = [
                'password', 'secret', 'token', 'key', 'apiKey', 'api_key',
                'client_secret', 'private_key', 'access_token', 'refresh_token',
                'bearer', 'authorization', 'auth_token', 'session_id'
            ];
            
            foreach ( $json_keys as $key ) {
                $content = preg_replace( 
                    '/"' . preg_quote( $key, '/' ) . '"\s*:\s*"[^"]+"/i',
                    '"' . $key . '": "[REDACTED]"',
                    $content
                );
            }
        }
        
        // For XML files, redact sensitive elements
        if ( $extension === 'xml' ) {
            $xml_elements = [
                'password', 'secret', 'token', 'key', 'apikey',
                'credentials', 'auth', 'authorization'
            ];
            
            foreach ( $xml_elements as $element ) {
                $content = preg_replace(
                    '/<' . preg_quote( $element, '/' ) . '>([^<]+)<\/' . preg_quote( $element, '/' ) . '>/i',
                    '<' . $element . '>[REDACTED]</' . $element . '>',
                    $content
                );
            }
        }
        
        return $content;
    }
    
    // Security event logging has been intentionally removed
    // to avoid performance overhead and unnecessary data storage
}