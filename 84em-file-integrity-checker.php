<?php
/**
 * Plugin Name: 84EM File Integrity Checker
 * Plugin URI: https://github.com/84emllc/84em-file-integrity-checker
 * Description: Scans WordPress installation to generate and track file checksums, detecting file changes with Action Scheduler support.
 * Version: 2.4.5
 * Author: 84EM
 * Author URI: https://84em.com
 * License: MIT
 * Requires at least: 6.8
 * Requires PHP: 8.0
 * Text Domain: 84em-file-integrity-checker
 */

defined( 'ABSPATH' ) or die;

const EIGHTYFOUREM_FILE_INTEGRITY_CHECKER_VERSION = '2.4.4';
define( 'EIGHTYFOUREM_FILE_INTEGRITY_CHECKER_PATH', plugin_dir_path( __FILE__ ) );
define( 'EIGHTYFOUREM_FILE_INTEGRITY_CHECKER_URL', plugin_dir_url( __FILE__ ) );

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    require_once __DIR__ . '/vendor/autoload.php';
}

if ( file_exists( __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php' ) ) {
    require_once __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
}

if ( class_exists( 'EightyFourEM\FileIntegrityChecker\Plugin' ) ) {

    add_action( 'plugins_loaded', function () {
        $plugin = EightyFourEM\FileIntegrityChecker\Plugin::getInstance();
        $plugin->run();
    } );

    register_activation_hook( __FILE__, [ 'EightyFourEM\FileIntegrityChecker\Plugin', 'activate' ] );

    register_deactivation_hook( __FILE__, [ 'EightyFourEM\FileIntegrityChecker\Plugin', 'deactivate' ] );
}
else {

    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>84EM File Integrity Checker Error:</strong> ' .
             'Failed to load plugin dependencies. Please run <code>composer install</code> in the plugin directory.</p></div>';
    } );
}
