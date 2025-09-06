<?php
/**
 * Security Utilities
 * 
 * @package EightyFourEM\FileIntegrityChecker\Utils
 */

namespace EightyFourEM\FileIntegrityChecker\Utils;

/**
 * Security utility functions for the plugin
 */
class Security {
    
    /**
     * Nonce prefix for all plugin nonces
     */
    const NONCE_PREFIX = 'file_integrity_';
    
    /**
     * Create an action-specific nonce
     *
     * @param string $action The action name
     * @return string The nonce
     */
    public static function create_nonce( string $action ): string {
        return wp_create_nonce( self::NONCE_PREFIX . $action );
    }
    
    /**
     * Verify an action-specific nonce
     *
     * @param string $nonce The nonce to verify
     * @param string $action The action name
     * @return bool True if valid, false otherwise
     */
    public static function verify_nonce( string $nonce, string $action ): bool {
        return wp_verify_nonce( $nonce, self::NONCE_PREFIX . $action ) !== false;
    }
    
    /**
     * Check AJAX referer with action-specific nonce
     *
     * @param string $action The action name
     * @param string $query_arg The query argument name
     * @param bool $die Whether to die on failure
     * @return bool True if valid, false otherwise
     */
    public static function check_ajax_referer( string $action, string $query_arg = '_wpnonce', bool $die = false ): bool {
        return check_ajax_referer( self::NONCE_PREFIX . $action, $query_arg, $die ) !== false;
    }
    
    /**
     * Validate and sanitize file path input
     *
     * @param string $file_path The file path to validate
     * @return string|false Sanitized path or false if invalid
     */
    public static function sanitize_file_path( $file_path ) {
        if ( ! is_string( $file_path ) ) {
            return false;
        }
        
        // Remove null bytes
        $file_path = str_replace( chr(0), '', $file_path );
        
        // Remove directory traversal attempts
        $file_path = str_replace( '..', '', $file_path );
        $file_path = str_replace( '//', '/', $file_path );
        
        // Remove URL protocols
        $file_path = preg_replace( '#^[a-z]+://#i', '', $file_path );
        
        return $file_path;
    }
    
    /**
     * Validate webhook URL
     *
     * @param string $url The webhook URL to validate
     * @param string $service The service type (slack, discord, etc.)
     * @return string|false Validated URL or false if invalid
     */
    public static function validate_webhook_url( string $url, string $service = 'slack' ): string|false {
        // Filter and validate URL
        $url = filter_var( $url, FILTER_VALIDATE_URL );
        if ( ! $url ) {
            return false;
        }
        
        // Ensure HTTPS
        if ( ! str_starts_with( $url, 'https://' ) ) {
            return false;
        }
        
        // Service-specific validation
        switch ( $service ) {
            case 'slack':
                // Slack webhook pattern: https://hooks.slack.com/services/[TOKEN]
                if ( ! preg_match( '#^https://hooks\.slack\.com/services/[A-Z0-9/]+$#', $url ) ) {
                    return false;
                }
                break;
                
            case 'discord':
                // Discord webhook pattern: https://discord.com/api/webhooks/[ID]/[TOKEN]
                if ( ! preg_match( '#^https://discord(?:app)?\.com/api/webhooks/\d+/[\w-]+$#', $url ) ) {
                    return false;
                }
                break;
                
            default:
                // Generic HTTPS URL validation
                break;
        }
        
        return $url;
    }
    
    /**
     * Sanitize and validate email addresses
     *
     * @param string|array $emails Email address(es) to validate
     * @return array Array of valid email addresses
     */
    public static function validate_emails( $emails ): array {
        if ( ! is_array( $emails ) ) {
            $emails = array_map( 'trim', explode( ',', $emails ) );
        }
        
        $valid_emails = [];
        foreach ( $emails as $email ) {
            $email = sanitize_email( trim( $email ) );
            if ( is_email( $email ) ) {
                $valid_emails[] = $email;
            }
        }
        
        return $valid_emails;
    }
    
    /**
     * Rate limiting check
     *
     * @param string $action The action to rate limit
     * @param int $limit Maximum attempts allowed
     * @param int $window Time window in seconds
     * @return bool True if within rate limit, false if exceeded
     */
    public static function check_rate_limit( string $action, int $limit = 10, int $window = 60 ): bool {
        $user_id = get_current_user_id();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        
        // Create unique key for this user/IP and action
        $key = 'rate_limit_' . md5( $action . '_' . $user_id . '_' . $ip );
        
        // Get current attempt count
        $attempts = get_transient( $key );
        
        if ( $attempts === false ) {
            // First attempt
            set_transient( $key, 1, $window );
            return true;
        }
        
        if ( $attempts >= $limit ) {
            // Rate limit exceeded
            return false;
        }
        
        // Increment attempts
        set_transient( $key, $attempts + 1, $window );
        return true;
    }
    
    /**
     * Generate secure random token
     *
     * @param int $length Token length
     * @return string Random token
     */
    public static function generate_token( int $length = 32 ): string {
        if ( function_exists( 'random_bytes' ) ) {
            return bin2hex( random_bytes( $length / 2 ) );
        }
        
        // Fallback for older PHP versions
        return wp_generate_password( $length, false, false );
    }
    
    /**
     * Validate file extension against allowed list
     *
     * @param string $filename The filename to check
     * @param array $allowed_extensions List of allowed extensions
     * @return bool True if allowed, false otherwise
     */
    public static function validate_file_extension( string $filename, array $allowed_extensions ): bool {
        $extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
        return in_array( $extension, $allowed_extensions, true );
    }
    
    /**
     * Check file MIME type
     *
     * @param string $file_path Path to the file
     * @param array $allowed_types Allowed MIME types
     * @return bool True if allowed, false otherwise
     */
    public static function validate_mime_type( string $file_path, array $allowed_types ): bool {
        if ( ! file_exists( $file_path ) ) {
            return false;
        }
        
        $finfo = finfo_open( FILEINFO_MIME_TYPE );
        $mime_type = finfo_file( $finfo, $file_path );
        finfo_close( $finfo );
        
        return in_array( $mime_type, $allowed_types, true );
    }
    
    /**
     * Sanitize error messages to prevent information disclosure
     *
     * @param string $message The error message
     * @param bool $log_full Whether to log the full error
     * @return string Sanitized error message
     */
    public static function sanitize_error_message( string $message, bool $log_full = true ): string {
        // Log the full error for debugging
        if ( $log_full && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'File Integrity Checker Error: ' . $message );
        }
        
        // Return generic message to user
        // Check for specific error types and return appropriate generic messages
        if ( stripos( $message, 'permission' ) !== false ) {
            return __( 'Permission denied. Please check file permissions.', 'file-integrity-checker' );
        }
        
        if ( stripos( $message, 'not found' ) !== false || stripos( $message, 'does not exist' ) !== false ) {
            return __( 'The requested resource could not be found.', 'file-integrity-checker' );
        }
        
        if ( stripos( $message, 'database' ) !== false || stripos( $message, 'mysql' ) !== false ) {
            return __( 'A database error occurred. Please try again later.', 'file-integrity-checker' );
        }
        
        // Default generic message
        return __( 'An error occurred. Please try again or contact support if the problem persists.', 'file-integrity-checker' );
    }
}