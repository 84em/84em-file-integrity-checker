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
}
