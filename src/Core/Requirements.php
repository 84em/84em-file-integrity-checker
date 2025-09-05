<?php
/**
 * Plugin Requirements Checker
 *
 * @package EightyFourEM\FileIntegrityChecker\Core
 */

namespace EightyFourEM\FileIntegrityChecker\Core;

/**
 * Checks plugin requirements before initialization
 */
class Requirements {
    /**
     * Check if plugin requirements are met
     *
     * @return bool
     */
    public function check(): bool {
        $errors = [];

        // Check PHP version
        if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
            $errors[] = sprintf(
                'PHP version 8.0 or higher is required. Current version: %s',
                PHP_VERSION
            );
        }

        // Check WordPress version
        global $wp_version;
        if ( version_compare( $wp_version, '6.8', '<' ) ) {
            $errors[] = sprintf(
                'WordPress version 6.8 or higher is required. Current version: %s',
                $wp_version
            );
        }

        // Check if Action Scheduler is available
        if ( ! class_exists( 'ActionScheduler' ) ) {
            $errors[] = 'Action Scheduler is required but not found. Please install a plugin that provides Action Scheduler (e.g., WooCommerce).';
        }

        // Display errors if any
        if ( ! empty( $errors ) ) {
            add_action( 'admin_notices', function () use ( $errors ) {
                foreach ( $errors as $error ) {
                    echo '<div class="notice notice-error"><p><strong>84EM File Integrity Checker:</strong> ' . esc_html( $error ) . '</p></div>';
                }
            } );
            return false;
        }

        return true;
    }

    /**
     * Check if file system is writable
     *
     * @return bool
     */
    public function isFileSystemWritable(): bool {
        return is_writable( ABSPATH );
    }

    /**
     * Check available memory
     *
     * @return bool
     */
    public function hasAdequateMemory(): bool {
        $memory_limit = ini_get( 'memory_limit' );
        if ( $memory_limit === '-1' ) {
            return true; // Unlimited
        }

        $memory_in_bytes = $this->convertToBytes( $memory_limit );
        return $memory_in_bytes >= ( 256 * 1024 * 1024 ); // 256MB minimum
    }

    /**
     * Convert memory limit string to bytes
     *
     * @param string $value Memory limit string (e.g., '256M')
     * @return int Memory in bytes
     */
    private function convertToBytes( string $value ): int {
        $value = trim( $value );
        $last  = strtolower( $value[ strlen( $value ) - 1 ] );
        $number = (int) $value;

        switch ( $last ) {
            case 'g':
                $number *= 1024;
                // Fall through
            case 'm':
                $number *= 1024;
                // Fall through
            case 'k':
                $number *= 1024;
        }

        return $number;
    }
}