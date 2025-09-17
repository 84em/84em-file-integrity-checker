<?php
/**
 * Plugin Name: 84EM File Integrity Checker
 * Description: Scans WordPress installation to generate and track file checksums, detecting file changes with Action Scheduler support.
 * Version: 2.1.0
 * Author: 84EM
 * Requires at least: 6.8
 * Requires PHP: 8.0
 * Text Domain: 84em-file-integrity-checker
 */

defined( 'ABSPATH' ) or die;

// Define plugin constants
const EIGHTYFOUREM_FILE_INTEGRITY_CHECKER_VERSION = '2.1.0';
define( 'EIGHTYFOUREM_FILE_INTEGRITY_CHECKER_PATH', plugin_dir_path( __FILE__ ) );
define( 'EIGHTYFOUREM_FILE_INTEGRITY_CHECKER_URL', plugin_dir_url( __FILE__ ) );

// Load Composer autoloader
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Load Action Scheduler library
if ( file_exists( __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php' ) ) {
    require_once __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
}

// Initialize and run the plugin using the new architecture
if ( class_exists( 'EightyFourEM\FileIntegrityChecker\Plugin' ) ) {
    // Get plugin instance and run
    add_action( 'plugins_loaded', function () {
        $plugin = EightyFourEM\FileIntegrityChecker\Plugin::getInstance();
        $plugin->run();
    } );

    // Register activation hook
    register_activation_hook( __FILE__, [ 'EightyFourEM\FileIntegrityChecker\Plugin', 'activate' ] );

    // Register deactivation hook
    register_deactivation_hook( __FILE__, [ 'EightyFourEM\FileIntegrityChecker\Plugin', 'deactivate' ] );
}
else {
    // Fatal error if autoloader failed
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>84EM File Integrity Checker Error:</strong> ' .
             'Failed to load plugin dependencies. Please run <code>composer install</code> in the plugin directory.</p></div>';
    } );
}
