<?php
/**
 * Plugin Activator
 *
 * @package EightyFourEM\FileIntegrityChecker\Core
 */

namespace EightyFourEM\FileIntegrityChecker\Core;

use EightyFourEM\FileIntegrityChecker\Database\DatabaseManager;

/**
 * Handles plugin activation
 */
class Activator {
    /**
     * Database manager
     *
     * @var DatabaseManager
     */
    private DatabaseManager $databaseManager;

    /**
     * Constructor
     *
     * @param DatabaseManager $databaseManager Database manager
     */
    public function __construct( DatabaseManager $databaseManager ) {
        $this->databaseManager = $databaseManager;
    }

    /**
     * Activate the plugin
     */
    public function activate(): void {
        // Store plugin version for potential future use
        if ( false === get_option( 'eightyfourem_file_integrity_version' ) ) {
            add_option( 'eightyfourem_file_integrity_version', EIGHTYFOUREM_FILE_INTEGRITY_CHECKER_VERSION );
        }

        // Create database tables
        $this->databaseManager->createTables();

        // Set default options
        $this->setDefaultOptions();

        // Schedule initial scan if configured
        if ( get_option( 'eightyfourem_file_integrity_auto_schedule', true ) ) {
            $this->scheduleInitialScan();
        }
    }

    /**
     * Set default plugin options
     */
    private function setDefaultOptions(): void {
        $default_options = [
            'eightyfourem_file_integrity_scan_types' => [ '.js', '.css', '.html', '.php' ],
            'eightyfourem_file_integrity_scan_interval' => 'daily',
            'eightyfourem_file_integrity_exclude_patterns' => [
                '*/cache/*',
                '*/logs/*',
                '*/uploads/*',
                '*/wp-content/cache/*',
                '*/wp-content/backup*',
            ],
            'eightyfourem_file_integrity_max_file_size' => 10485760, // 10MB
            'eightyfourem_file_integrity_notification_enabled' => true,
            'eightyfourem_file_integrity_notification_email' => get_option( 'admin_email' ),
            'eightyfourem_file_integrity_auto_schedule' => true,
        ];

        foreach ( $default_options as $option_name => $default_value ) {
            if ( false === get_option( $option_name ) ) {
                add_option( $option_name, $default_value );
            }
        }
    }

    /**
     * Schedule initial scan
     */
    private function scheduleInitialScan(): void {
        if ( class_exists( 'ActionScheduler' ) && function_exists( 'as_has_scheduled_action' ) ) {
            if ( ! as_has_scheduled_action( 'eightyfourem_file_integrity_scan' ) ) {
                as_schedule_single_action( 
                    time() + 300, // Schedule 5 minutes from activation
                    'eightyfourem_file_integrity_scan',
                    [],
                    'file-integrity-checker'
                );
            }
        }
    }
}