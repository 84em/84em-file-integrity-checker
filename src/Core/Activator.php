<?php
/**
 * Plugin Activator
 *
 * @package EightyFourEM\FileIntegrityChecker\Core
 */

namespace EightyFourEM\FileIntegrityChecker\Core;

use EightyFourEM\FileIntegrityChecker\Database\DatabaseManager;
use EightyFourEM\FileIntegrityChecker\Services\SchedulerService;

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
     * Scheduler service
     *
     * @var SchedulerService
     */
    private SchedulerService $schedulerService;

    /**
     * Constructor
     *
     * @param DatabaseManager  $databaseManager  Database manager
     * @param SchedulerService $schedulerService Scheduler service
     */
    public function __construct( DatabaseManager $databaseManager, SchedulerService $schedulerService ) {
        $this->databaseManager = $databaseManager;
        $this->schedulerService = $schedulerService;
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
        // Use SchedulerService to schedule the initial scan
        $this->schedulerService->scheduleOnetimeScan( time() + 300 ); // Schedule 5 minutes from activation
    }
}