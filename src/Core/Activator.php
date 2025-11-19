<?php
/**
 * Plugin Activator
 *
 * @package EightyFourEM\FileIntegrityChecker\Core
 */

namespace EightyFourEM\FileIntegrityChecker\Core;

use EightyFourEM\FileIntegrityChecker\Database\DatabaseManager;
use EightyFourEM\FileIntegrityChecker\Services\SchedulerService;
use EightyFourEM\FileIntegrityChecker\Services\LoggerService;

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
     * Logger service
     *
     * @var LoggerService
     */
    private LoggerService $loggerService;

    /**
     * Constructor
     *
     * @param DatabaseManager  $databaseManager  Database manager
     * @param SchedulerService $schedulerService Scheduler service
     * @param LoggerService    $loggerService    Logger service
     */
    public function __construct( DatabaseManager $databaseManager, SchedulerService $schedulerService, LoggerService $loggerService ) {
        $this->databaseManager = $databaseManager;
        $this->schedulerService = $schedulerService;
        $this->loggerService = $loggerService;
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

        // Only schedule migrations if needed and not already scheduled
        $this->scheduleMigrationsIfNeeded();

        // Set default options
        $this->setDefaultOptions();
    }

    /**
     * Schedule migrations if needed
     *
     * Schedules database migrations asynchronously via Action Scheduler only if:
     * 1. Migrations have not already been applied
     * 2. No pending migration task exists in Action Scheduler queue
     * 3. Action Scheduler is available (fallback to synchronous if not)
     */
    private function scheduleMigrationsIfNeeded(): void {
        // Check if migrations are already applied
        if ( $this->areMigrationsApplied() ) {
            $this->loggerService->info(
                message: 'Database migrations already applied, skipping scheduling',
                context: LoggerService::CONTEXT_DATABASE
            );
            return;
        }

        // Check if Action Scheduler is available
        if ( ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_next_scheduled_action' ) ) {
            // Fallback: run migrations synchronously if Action Scheduler is not available
            $this->loggerService->info(
                message: 'Action Scheduler not available, running migrations synchronously',
                context: LoggerService::CONTEXT_DATABASE
            );

            $migration_result = $this->databaseManager->runMigrations();
            if ( ! $migration_result ) {
                $this->loggerService->warning(
                    message: 'Plugin activation completed with migration warnings',
                    context: LoggerService::CONTEXT_DATABASE
                );
            }
            return;
        }

        // Check if a pending migration task already exists
        $pending_migration = as_next_scheduled_action(
            hook: 'eightyfourem_file_integrity_run_migrations',
            args: [],
            group: 'eightyfourem-file-integrity'
        );

        if ( false !== $pending_migration ) {
            $this->loggerService->info(
                message: 'Migration task already scheduled, skipping duplicate scheduling',
                context: LoggerService::CONTEXT_DATABASE,
                data: [ 'scheduled_time' => $pending_migration ]
            );
            return;
        }

        // Schedule migrations to run asynchronously
        as_schedule_single_action(
            timestamp: time(),
            hook: 'eightyfourem_file_integrity_run_migrations',
            args: [],
            group: 'eightyfourem-file-integrity'
        );

        $this->loggerService->info(
            message: 'Database migrations scheduled for background execution',
            context: LoggerService::CONTEXT_DATABASE
        );
    }

    /**
     * Check if all migrations have been applied
     *
     * @return bool True if all migrations are applied, false otherwise
     */
    private function areMigrationsApplied(): bool {
        // Check if migration classes exist and have been applied
        $priority_monitoring_applied = class_exists( 'EightyFourEM\FileIntegrityChecker\Database\Migrations\PriorityMonitoringMigration' )
            && \EightyFourEM\FileIntegrityChecker\Database\Migrations\PriorityMonitoringMigration::isApplied();

        $tiered_retention_applied = class_exists( 'EightyFourEM\FileIntegrityChecker\Database\Migrations\TieredRetentionMigration' )
            && \EightyFourEM\FileIntegrityChecker\Database\Migrations\TieredRetentionMigration::isApplied();

        $cleanup_action_applied = class_exists( 'EightyFourEM\FileIntegrityChecker\Database\Migrations\CleanupActionMigration' )
            && \EightyFourEM\FileIntegrityChecker\Database\Migrations\CleanupActionMigration::isApplied();

        return $priority_monitoring_applied && $tiered_retention_applied && $cleanup_action_applied;
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
        ];

        foreach ( $default_options as $option_name => $default_value ) {
            if ( false === get_option( $option_name ) ) {
                add_option( $option_name, $default_value );
            }
        }
    }

}