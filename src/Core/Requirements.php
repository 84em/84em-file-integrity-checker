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

        // Check OpenSSL extension for encryption
        if ( ! extension_loaded( 'openssl' ) ) {
            $errors[] = 'OpenSSL PHP extension is required for encryption but not found.';
        }

        // Check if AES-256-GCM cipher is available
        if ( extension_loaded( 'openssl' ) ) {
            $available_ciphers = openssl_get_cipher_methods();
            if ( ! in_array( 'aes-256-gcm', $available_ciphers, true ) ) {
                $errors[] = 'AES-256-GCM cipher is required but not available in OpenSSL.';
            }
        }

        // check for adequate memory
        $adequate_memory = $this->hasAdequateMemory();
        if ( ! $adequate_memory ) {
            $errors[] = 'Adequate memory is required for the plugin to run. Please increase memory_limit in php.ini to at least 64MB.';
        }

        // Check WordPress salts are properly configured
        $salts_configured = $this->checkWordPressSalts();
        if ( ! $salts_configured ) {
            $errors[] = 'WordPress security salts are not properly configured. Please define AUTH_KEY, SECURE_AUTH_KEY, LOGGED_IN_KEY, and NONCE_KEY in wp-config.php with unique values.';
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
        return $memory_in_bytes >= ( 64 * 1024 * 1024 ); // 64MB minimum
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

    /**
     * Check if WordPress salts are properly configured
     *
     * @return bool
     */
    private function checkWordPressSalts(): bool {
        $required_salts = [
            'AUTH_KEY',
            'SECURE_AUTH_KEY',
            'LOGGED_IN_KEY',
            'NONCE_KEY',
        ];

        $salt_values = [];
        foreach ( $required_salts as $salt ) {
            if ( ! defined( $salt ) ) {
                return false;
            }
            $value = constant( $salt );
            if ( empty( $value ) || strlen( $value ) < 32 ) {
                return false;
            }
            // Check for default/placeholder values
            if ( strpos( $value, 'put your unique phrase here' ) !== false ) {
                return false;
            }
            $salt_values[] = $value;
        }

        // Ensure salts are unique
        if ( count( array_unique( $salt_values ) ) !== count( $salt_values ) ) {
            return false;
        }

        // Check combined length for adequate entropy
        $combined = implode( '', $salt_values );
        if ( strlen( $combined ) < 128 ) {
            return false;
        }

        return true;
    }
}
