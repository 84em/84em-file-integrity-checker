<?php
/**
 * Cleanup Action Migration
 *
 * @package EightyFourEM\FileIntegrityChecker\Database\Migrations
 */

namespace EightyFourEM\FileIntegrityChecker\Database\Migrations;

use EightyFourEM\FileIntegrityChecker\Services\LoggerService;

/**
 * Migration to clean up old cleanup action hook name
 *
 * Unschedules legacy 'file_integrity_cleanup_cache' actions that were renamed
 * to 'eightyfourem_file_integrity_cleanup_cache' in v2.3.2
 */
class CleanupActionMigration {
    /**
     * Migration version
     */
    private const MIGRATION_VERSION = '2.4.2';

    /**
     * Option key for tracking migration status
     */
    private const MIGRATION_OPTION = 'eightyfourem_file_integrity_cleanup_action_migration';

    /**
     * Legacy action hook name
     */
    private const LEGACY_ACTION = 'file_integrity_cleanup_cache';

    /**
     * Logger service
     *
     * @var LoggerService|null
     */
    private ?LoggerService $loggerService = null;

    /**
     * Set logger service
     *
     * @param LoggerService $loggerService Logger service
     */
    public function setLoggerService( LoggerService $loggerService ): void {
        $this->loggerService = $loggerService;
    }

    /**
     * Check if migration has been applied
     *
     * @return bool True if already applied, false otherwise
     */
    public static function isApplied(): bool {
        return (bool) get_option( self::MIGRATION_OPTION, false );
    }

    /**
     * Run the migration
     *
     * @return bool True on success, false on failure
     */
    public function up(): bool {
        // Check if Action Scheduler functions are available
        if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
            if ( $this->loggerService ) {
                $this->loggerService->warning(
                    message: 'Cleanup Action Migration skipped: Action Scheduler not available',
                    context: LoggerService::CONTEXT_DATABASE
                );
            }
            // Mark as applied even though we couldn't run it
            // The ScheduledCacheCleanup service has defensive logic to handle this
            update_option( self::MIGRATION_OPTION, self::MIGRATION_VERSION );
            return true;
        }

        // Count legacy actions before removal for logging
        $legacy_count = 0;
        if ( function_exists( 'as_get_scheduled_actions' ) ) {
            $legacy_actions = as_get_scheduled_actions(
                args: [
                    'hook' => self::LEGACY_ACTION,
                    'status' => [ 'pending', 'failed', 'in-progress' ],
                ],
                return_format: 'ids'
            );
            $legacy_count = is_array( $legacy_actions ) ? count( $legacy_actions ) : 0;
        }

        // Unschedule all legacy cleanup actions
        as_unschedule_all_actions( self::LEGACY_ACTION );

        if ( $this->loggerService ) {
            $this->loggerService->info(
                message: sprintf(
                    'Cleanup Action Migration completed: Unscheduled %d legacy action(s)',
                    $legacy_count
                ),
                context: LoggerService::CONTEXT_DATABASE,
                data: [
                    'legacy_hook' => self::LEGACY_ACTION,
                    'actions_removed' => $legacy_count,
                ]
            );
        }

        // Mark migration as applied
        update_option( self::MIGRATION_OPTION, self::MIGRATION_VERSION );

        return true;
    }

    /**
     * Rollback the migration
     *
     * Note: Rollback is a no-op since we can't restore deleted scheduled actions
     *
     * @return bool True on success
     */
    public function down(): bool {
        // Remove migration marker
        delete_option( self::MIGRATION_OPTION );

        if ( $this->loggerService ) {
            $this->loggerService->info(
                message: 'Cleanup Action Migration rolled back (marker removed)',
                context: LoggerService::CONTEXT_DATABASE
            );
        }

        return true;
    }
}
