<?php
/**
 * PHPUnit Bootstrap File for File Integrity Checker Tests
 */

// Define plugin constants that would normally be defined by WordPress
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', '/tmp/wordpress/' );
}

define( 'EIGHTYFOUREM_FILE_INTEGRITY_CHECKER_VERSION', '1.0.0' );
define( 'EIGHTYFOUREM_FILE_INTEGRITY_CHECKER_PATH', __DIR__ . '/../' );
define( 'EIGHTYFOUREM_FILE_INTEGRITY_CHECKER_URL', 'http://localhost/wp-content/plugins/84em-file-integrity-checker/' );

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Create mock WordPress functions for testing
if ( ! function_exists( 'wp_parse_args' ) ) {
    function wp_parse_args( $args, $defaults = '' ) {
        if ( is_object( $args ) ) {
            $args = get_object_vars( $args );
        } elseif ( ! is_array( $args ) ) {
            $args = [];
        }

        if ( is_array( $defaults ) ) {
            return array_merge( $defaults, $args );
        }

        return $args;
    }
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $str ) {
        return strip_tags( trim( $str ) );
    }
}

if ( ! function_exists( 'sanitize_email' ) ) {
    function sanitize_email( $email ) {
        return filter_var( trim( $email ), FILTER_SANITIZE_EMAIL );
    }
}

if ( ! function_exists( 'is_email' ) ) {
    function is_email( $email ) {
        return filter_var( $email, FILTER_VALIDATE_EMAIL ) !== false;
    }
}

if ( ! function_exists( 'size_format' ) ) {
    function size_format( $bytes, $decimals = 0 ) {
        $sizes = [ 'B', 'KB', 'MB', 'GB', 'TB', 'PB' ];
        for ( $i = 0; $bytes > 1024 && $i < ( count( $sizes ) - 1 ); $i++ ) {
            $bytes /= 1024;
        }
        return round( $bytes, $decimals ) . ' ' . $sizes[ $i ];
    }
}

if ( ! function_exists( 'current_time' ) ) {
    function current_time( $type, $gmt = 0 ) {
        if ( $type === 'mysql' ) {
            return date( 'Y-m-d H:i:s' );
        }
        return time();
    }
}

if ( ! function_exists( 'get_option' ) ) {
    function get_option( $option, $default = false ) {
        static $options = [];
        return $options[ $option ] ?? $default;
    }
}

if ( ! function_exists( 'update_option' ) ) {
    function update_option( $option, $value ) {
        static $options = [];
        $options[ $option ] = $value;
        return true;
    }
}

if ( ! function_exists( 'add_option' ) ) {
    function add_option( $option, $value ) {
        return update_option( $option, $value );
    }
}

if ( ! function_exists( 'delete_option' ) ) {
    function delete_option( $option ) {
        static $options = [];
        unset( $options[ $option ] );
        return true;
    }
}

// Create test directories
$test_dirs = [
    __DIR__ . '/results',
    __DIR__ . '/fixtures',
];

foreach ( $test_dirs as $dir ) {
    if ( ! is_dir( $dir ) ) {
        mkdir( $dir, 0755, true );
    }
}