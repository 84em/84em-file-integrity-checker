<?php
/**
 * Security Headers and Hardening
 * 
 * @package EightyFourEM\FileIntegrityChecker\Security
 */

namespace EightyFourEM\FileIntegrityChecker\Security;

/**
 * Adds security headers and hardening measures to the plugin
 */
class SecurityHeaders {
    
    /**
     * Initialize security headers
     */
    public function init(): void {
        // Add security headers for admin pages
        add_action( 'admin_init', [ $this, 'addAdminSecurityHeaders' ] );
        
        // Add security headers for AJAX requests
        add_action( 'wp_ajax_file_integrity_start_scan', [ $this, 'addAjaxSecurityHeaders' ], 1 );
        add_action( 'wp_ajax_file_integrity_check_progress', [ $this, 'addAjaxSecurityHeaders' ], 1 );
        add_action( 'wp_ajax_file_integrity_cancel_scan', [ $this, 'addAjaxSecurityHeaders' ], 1 );
        add_action( 'wp_ajax_file_integrity_delete_scan', [ $this, 'addAjaxSecurityHeaders' ], 1 );
        add_action( 'wp_ajax_file_integrity_bulk_delete', [ $this, 'addAjaxSecurityHeaders' ], 1 );
        add_action( 'wp_ajax_file_integrity_cleanup_old', [ $this, 'addAjaxSecurityHeaders' ], 1 );
        add_action( 'wp_ajax_file_integrity_test_slack', [ $this, 'addAjaxSecurityHeaders' ], 1 );
        add_action( 'wp_ajax_file_integrity_resend_email', [ $this, 'addAjaxSecurityHeaders' ], 1 );
        add_action( 'wp_ajax_file_integrity_resend_slack', [ $this, 'addAjaxSecurityHeaders' ], 1 );
        add_action( 'wp_ajax_file_integrity_view_file', [ $this, 'addAjaxSecurityHeaders' ], 1 );
        
        // Disable XML-RPC if not needed
        add_filter( 'xmlrpc_enabled', '__return_false' );
        
        // Remove version info from head and feeds
        add_filter( 'the_generator', '__return_empty_string' );
        
        // Disable file editing in admin
        if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
            define( 'DISALLOW_FILE_EDIT', true );
        }
    }
    
    /**
     * Add security headers for admin pages
     */
    public function addAdminSecurityHeaders(): void {
        // Only add headers on our plugin pages
        if ( ! $this->isPluginAdminPage() ) {
            return;
        }
        
        $this->setSecurityHeaders();
    }
    
    /**
     * Add security headers for AJAX requests
     */
    public function addAjaxSecurityHeaders(): void {
        $this->setSecurityHeaders();
    }
    
    /**
     * Set security headers
     */
    private function setSecurityHeaders(): void {
        // Prevent clickjacking
        if ( ! headers_sent() ) {
            header( 'X-Frame-Options: DENY' );
            
            // Prevent MIME type sniffing
            header( 'X-Content-Type-Options: nosniff' );
            
            // Enable XSS protection
            header( 'X-XSS-Protection: 1; mode=block' );
            
            // Referrer Policy
            header( 'Referrer-Policy: strict-origin-when-cross-origin' );
            
            // Content Security Policy
            $csp = $this->getContentSecurityPolicy();
            header( 'Content-Security-Policy: ' . $csp );
            
            // Permissions Policy (formerly Feature Policy)
            header( 'Permissions-Policy: geolocation=(), microphone=(), camera=()' );
            
            // Strict Transport Security (if HTTPS)
            if ( is_ssl() ) {
                header( 'Strict-Transport-Security: max-age=31536000; includeSubDomains' );
            }
        }
    }
    
    /**
     * Get Content Security Policy
     *
     * @return string CSP header value
     */
    private function getContentSecurityPolicy(): string {
        $admin_url = admin_url();
        $site_url = site_url();
        
        $policies = [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' " . $admin_url, // WordPress admin requires unsafe-inline
            "style-src 'self' 'unsafe-inline' " . $admin_url, // WordPress admin requires unsafe-inline
            "img-src 'self' data: https:",
            "font-src 'self' data:",
            "connect-src 'self' " . $admin_url,
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self' " . $admin_url,
        ];
        
        return implode( '; ', $policies );
    }
    
    /**
     * Check if current page is a plugin admin page
     *
     * @return bool
     */
    private function isPluginAdminPage(): bool {
        if ( ! is_admin() ) {
            return false;
        }
        
        $screen = get_current_screen();
        if ( ! $screen ) {
            return false;
        }
        
        // Check if it's one of our plugin pages
        $plugin_pages = [
            'toplevel_page_file-integrity-checker',
            'file-integrity_page_file-integrity-checker-settings',
            'file-integrity_page_file-integrity-checker-scans',
            'file-integrity_page_file-integrity-checker-schedules',
        ];
        
        return in_array( $screen->id, $plugin_pages, true );
    }
    
    /**
     * Additional hardening measures
     */
    public static function applyHardening(): void {
        // Disable PHP execution in uploads directory
        self::disablePhpInUploads();
        
        // Protect sensitive files
        self::protectSensitiveFiles();
        
        // Add index.php files to prevent directory browsing
        self::addIndexFiles();
    }
    
    /**
     * Disable PHP execution in uploads directory
     */
    private static function disablePhpInUploads(): void {
        $upload_dir = wp_upload_dir();
        $htaccess_file = $upload_dir['basedir'] . '/.htaccess';
        
        $rules = "# Disable PHP execution\n";
        $rules .= "<Files *.php>\n";
        $rules .= "deny from all\n";
        $rules .= "</Files>\n";
        
        // Check if rules already exist
        if ( file_exists( $htaccess_file ) ) {
            $current_content = file_get_contents( $htaccess_file );
            if ( strpos( $current_content, '# Disable PHP execution' ) === false ) {
                file_put_contents( $htaccess_file, $rules . "\n" . $current_content );
            }
        } else {
            file_put_contents( $htaccess_file, $rules );
        }
    }
    
    /**
     * Protect sensitive plugin files
     */
    private static function protectSensitiveFiles(): void {
        $plugin_dir = EIGHTYFOUREM_FILE_INTEGRITY_CHECKER_PATH;
        
        // Protect composer files
        $files_to_protect = [
            'composer.json',
            'composer.lock',
            '.env',
            'phpunit.xml',
            'phpcs.xml',
        ];
        
        $htaccess_content = "# Protect sensitive files\n";
        $htaccess_content .= "<FilesMatch \"^(" . implode( '|', array_map( 'preg_quote', $files_to_protect ) ) . ")$\">\n";
        $htaccess_content .= "Order allow,deny\n";
        $htaccess_content .= "Deny from all\n";
        $htaccess_content .= "</FilesMatch>\n";
        
        $htaccess_file = $plugin_dir . '.htaccess';
        
        // Check if rules already exist
        if ( file_exists( $htaccess_file ) ) {
            $current_content = file_get_contents( $htaccess_file );
            if ( strpos( $current_content, '# Protect sensitive files' ) === false ) {
                file_put_contents( $htaccess_file, $current_content . "\n" . $htaccess_content );
            }
        } else {
            file_put_contents( $htaccess_file, $htaccess_content );
        }
    }
    
    /**
     * Add index.php files to prevent directory browsing
     */
    private static function addIndexFiles(): void {
        $plugin_dir = EIGHTYFOUREM_FILE_INTEGRITY_CHECKER_PATH;
        
        $directories = [
            'src',
            'assets',
            'views',
            'vendor',
            'tests',
            'config',
        ];
        
        $index_content = "<?php\n// Silence is golden.\n";
        
        foreach ( $directories as $dir ) {
            $dir_path = $plugin_dir . $dir;
            if ( is_dir( $dir_path ) ) {
                $index_file = $dir_path . '/index.php';
                if ( ! file_exists( $index_file ) ) {
                    file_put_contents( $index_file, $index_content );
                }
            }
        }
    }
    
    /**
     * Validate session security
     */
    public static function validateSession(): bool {
        // Start session if not started
        if ( session_status() === PHP_SESSION_NONE ) {
            session_start();
        }
        
        // Regenerate session ID periodically
        if ( ! isset( $_SESSION['last_regeneration'] ) ) {
            $_SESSION['last_regeneration'] = time();
            session_regenerate_id( true );
        } elseif ( time() - $_SESSION['last_regeneration'] > 1800 ) { // 30 minutes
            $_SESSION['last_regeneration'] = time();
            session_regenerate_id( true );
        }
        
        // Validate user agent
        if ( ! isset( $_SESSION['user_agent'] ) ) {
            $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
        } elseif ( $_SESSION['user_agent'] !== ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ) {
            // User agent changed - possible session hijacking
            session_destroy();
            return false;
        }
        
        // Validate IP address (optional - may cause issues with mobile users)
        if ( defined( 'FILE_INTEGRITY_VALIDATE_IP' ) && FILE_INTEGRITY_VALIDATE_IP ) {
            if ( ! isset( $_SESSION['ip_address'] ) ) {
                $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? '';
            } elseif ( $_SESSION['ip_address'] !== ( $_SERVER['REMOTE_ADDR'] ?? '' ) ) {
                // IP address changed - possible session hijacking
                session_destroy();
                return false;
            }
        }
        
        return true;
    }
}