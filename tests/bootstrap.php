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

// Define WordPress time constants
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
    define( 'HOUR_IN_SECONDS', 3600 );
}

// Define WordPress database output constants
if ( ! defined( 'ARRAY_A' ) ) {
    define( 'ARRAY_A', 'ARRAY_A' );
}

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

// Global options storage for testing
$GLOBALS['test_options'] = [];

if ( ! function_exists( 'get_option' ) ) {
    function get_option( $option, $default = false ) {
        global $test_options;
        return $test_options[ $option ] ?? $default;
    }
}

if ( ! function_exists( 'update_option' ) ) {
    function update_option( $option, $value ) {
        global $test_options;
        $test_options[ $option ] = $value;
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
        global $test_options;
        unset( $test_options[ $option ] );
        return true;
    }
}

if ( ! function_exists( 'wp_generate_password' ) ) {
    function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        if ( $special_chars ) {
            $chars .= '!@#$%^&*()';
        }
        if ( $extra_special_chars ) {
            $chars .= '-_ []{}<>~`+=,.;:/?|';
        }

        $password = '';
        $chars_length = strlen( $chars );
        for ( $i = 0; $i < $length; $i++ ) {
            $password .= $chars[ random_int( 0, $chars_length - 1 ) ];
        }

        return $password;
    }
}

if ( ! function_exists( 'get_bloginfo' ) ) {
    function get_bloginfo( $show = '', $filter = 'raw' ) {
        switch ( $show ) {
            case 'name':
                return 'Test Site';
            case 'admin_email':
                return 'admin@example.com';
            case 'url':
                return 'http://example.com';
            case 'wpurl':
                return 'http://example.com/wp';
            case 'version':
                return '6.8.0';
            default:
                return 'Test Site';
        }
    }
}

if ( ! function_exists( 'get_home_url' ) ) {
    function get_home_url( $blog_id = null, $path = '', $scheme = null ) {
        return 'http://example.com' . $path;
    }
}

if ( ! function_exists( 'admin_url' ) ) {
    function admin_url( $path = '', $scheme = 'admin' ) {
        return 'http://example.com/wp-admin/' . ltrim( $path, '/' );
    }
}

if ( ! function_exists( 'mysql2date' ) ) {
    function mysql2date( $format, $date_string, $translate = true ) {
        if ( empty( $date_string ) ) {
            return false;
        }
        $datetime = new DateTime( $date_string );
        return $datetime->format( $format );
    }
}

if ( ! function_exists( 'absint' ) ) {
    function absint( $maybeint ) {
        return abs( (int) $maybeint );
    }
}

// Create mock $wpdb object for tests
if ( ! class_exists( 'wpdb' ) ) {
    class wpdb {
        public $prefix = 'wp_';
        private $data = [];

        public function get_var( $query ) {
            return null; // Return null for simplicity
        }

        public function get_row( $query ) {
            return null; // Return null for simplicity
        }

        public function get_results( $query ) {
            return []; // Return empty array for simplicity
        }

        public function insert( $table, $data, $format = null ) {
            return 1; // Return success
        }

        public function update( $table, $data, $where, $format = null, $where_format = null ) {
            return 1; // Return success
        }

        public function delete( $table, $where, $where_format = null ) {
            return 1; // Return success
        }

        public function query( $query ) {
            return 0; // Return 0 affected rows
        }

        public function prepare( $query, ...$args ) {
            return vsprintf( str_replace( ['%s', '%d'], ['%s', '%d'], $query ), $args );
        }

        public function get_charset_collate() {
            return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        }
    }
}

// Create global $wpdb instance
$GLOBALS['wpdb'] = new wpdb();

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