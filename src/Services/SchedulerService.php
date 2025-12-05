<?php
/**
 * Scheduler Service
 *
 * @package EightyFourEM\FileIntegrityChecker\Services
 */

namespace EightyFourEM\FileIntegrityChecker\Services;

use EightyFourEM\FileIntegrityChecker\Database\ScanSchedulesRepository;
use EightyFourEM\FileIntegrityChecker\Database\ScanResultsRepository;

/**
 * Manages scheduled scans using Action Scheduler
 */
class SchedulerService {
    /**
     * Action hook name for scheduled scans
     */
    private const SCAN_ACTION_HOOK = 'eightyfourem_file_integrity_scan';

    /**
     * Action hook name for log cleanup
     */
    private const LOG_CLEANUP_HOOK = 'eightyfourem_file_integrity_log_cleanup';

    /**
     * Action group for file integrity checker
     */
    private const ACTION_GROUP = 'file-integrity-checker';

    /**
     * Integrity service
     *
     * @var IntegrityService
     */
    private IntegrityService $integrityService;

    /**
     * Scan schedules repository
     *
     * @var ScanSchedulesRepository
     */
    private ScanSchedulesRepository $schedulesRepository;

    /**
     * Scan results repository
     *
     * @var ScanResultsRepository
     */
    private ScanResultsRepository $scanResultsRepository;

    /**
     * Logger service
     *
     * @var LoggerService
     */
    private LoggerService $logger;

    /**
     * Constructor
     *
     * @param IntegrityService        $integrityService       Integrity service
     * @param ScanSchedulesRepository $schedulesRepository    Schedules repository
     * @param ScanResultsRepository   $scanResultsRepository  Scan results repository
     * @param LoggerService           $logger                 Logger service
     */
    public function __construct(
        IntegrityService $integrityService,
        ScanSchedulesRepository $schedulesRepository,
        ScanResultsRepository $scanResultsRepository,
        LoggerService $logger,
    ) {
        $this->integrityService = $integrityService;
        $this->schedulesRepository = $schedulesRepository;
        $this->scanResultsRepository = $scanResultsRepository;
        $this->logger = $logger;
    }

    /**
     * Initialize scheduler service
     */
    public function init(): void {
        // Hook into Action Scheduler
        add_action( self::SCAN_ACTION_HOOK, [ $this, 'executeScan' ] );
        add_action( self::LOG_CLEANUP_HOOK, [ $this, 'executeLogCleanup' ] );

        // Check for due schedules every hour
        add_action( 'init', [ $this, 'registerScheduleChecker' ] );

        // Schedule daily log cleanup if enabled
        add_action( 'init', [ $this, 'scheduleLogCleanup' ] );
    }

    /**
     * Register schedule checker with Action Scheduler
     */
    public function registerScheduleChecker(): void {
        if ( ! $this->isAvailable() ) {
            return;
        }

        // Wait for Action Scheduler to be fully initialized
        add_action( 'action_scheduler_init', function() {
            // Schedule hourly check for due schedules
            if ( ! as_next_scheduled_action( 'eightyfourem_check_scan_schedules' ) ) {
                as_schedule_recurring_action(
                    time() + 60,
                    HOUR_IN_SECONDS,
                    'eightyfourem_check_scan_schedules',
                    [],
                    self::ACTION_GROUP
                );
            }
        } );

        add_action( 'eightyfourem_check_scan_schedules', [ $this, 'checkAndScheduleDueScans' ] );
    }

    /**
     * Check for due schedules and schedule scans
     */
    public function checkAndScheduleDueScans(): void {
        $due_schedules = $this->schedulesRepository->getDueSchedules();

        if ( empty( $due_schedules ) ) {
            return;
        }

        $this->logger->info(
            sprintf( 'Checking %d due schedule(s) for pending actions', count( $due_schedules ) ),
            LoggerService::CONTEXT_SCHEDULER,
            [ 'due_count' => count( $due_schedules ) ]
        );

        foreach ( $due_schedules as $schedule ) {
            // Schedule the scan - scheduleFromConfig will skip if action already exists
            // Don't update last_run yet, that happens after execution
            $this->scheduleFromConfig( $schedule );
        }
    }

    /**
     * Create a new scan schedule
     *
     * @param array $config Schedule configuration
     * @return int|false Schedule ID on success, false on failure
     */
    public function createSchedule( array $config ) {
        // Validate configuration
        $valid_frequencies = [ 'hourly', 'daily', 'weekly' ];
        if ( ! in_array( $config['frequency'], $valid_frequencies, true ) ) {
            return false;
        }

        // Create schedule in database
        $schedule_id = $this->schedulesRepository->create( $config );

        if ( $schedule_id ) {
            $this->logger->info(
                sprintf( 'Created new scan schedule: %s (%s)', $config['name'], $config['frequency'] ),
                LoggerService::CONTEXT_SCHEDULER,
                [
                    'schedule_id' => $schedule_id,
                    'name' => $config['name'],
                    'frequency' => $config['frequency'],
                    'is_active' => $config['is_active'] ?? true,
                ]
            );

            if ( ! empty( $config['is_active'] ) ) {
                // Get the created schedule
                $schedule = $this->schedulesRepository->get( $schedule_id );
                if ( $schedule ) {
                    // Schedule the first scan
                    $this->scheduleFromConfig( $schedule );
                }
            }
        } else {
            $this->logger->error(
                'Failed to create scan schedule',
                LoggerService::CONTEXT_SCHEDULER,
                [ 'config' => $config ]
            );
        }

        return $schedule_id;
    }

    /**
     * Update an existing scan schedule
     *
     * @param int   $id     Schedule ID
     * @param array $config New configuration
     * @return bool True on success, false on failure
     */
    public function updateSchedule( int $id, array $config ): bool {
        // Get existing schedule
        $existing = $this->schedulesRepository->get( $id );
        if ( ! $existing ) {
            return false;
        }

        // Cancel all existing Action Scheduler actions for this schedule
        $this->cancelScheduleActions( $id );

        // Update schedule in database
        $result = $this->schedulesRepository->update( $id, $config );

        if ( $result ) {
            $this->logger->info(
                sprintf( 'Updated scan schedule #%d', $id ),
                LoggerService::CONTEXT_SCHEDULER,
                [
                    'schedule_id' => $id,
                    'config' => $config,
                ]
            );

            // Reschedule if active
            $updated = $this->schedulesRepository->get( $id );
            if ( $updated && $updated->is_active ) {
                $this->scheduleFromConfig( $updated );
            }
        } else {
            $this->logger->error(
                sprintf( 'Failed to update scan schedule #%d', $id ),
                LoggerService::CONTEXT_SCHEDULER,
                [
                    'schedule_id' => $id,
                    'config' => $config,
                ]
            );
        }

        return $result;
    }

    /**
     * Delete a scan schedule
     *
     * @param int $id Schedule ID
     * @return bool True on success, false on failure
     */
    public function deleteSchedule( int $id ): bool {
        // Get schedule to cancel all Action Scheduler actions for this schedule
        $schedule = $this->schedulesRepository->get( $id );
        if ( $schedule ) {
            $this->cancelScheduleActions( $id );
        }

        $result = $this->schedulesRepository->delete( $id );

        if ( $result ) {
            $this->logger->info(
                sprintf( 'Deleted scan schedule #%d', $id ),
                LoggerService::CONTEXT_SCHEDULER,
                [ 'schedule_id' => $id ]
            );
        } else {
            $this->logger->error(
                sprintf( 'Failed to delete scan schedule #%d', $id ),
                LoggerService::CONTEXT_SCHEDULER,
                [ 'schedule_id' => $id ]
            );
        }

        return $result;
    }

    /**
     * Enable a scan schedule
     *
     * @param int $id Schedule ID
     * @return bool True on success, false on failure
     */
    public function enableSchedule( int $id ): bool {
        $result = $this->schedulesRepository->activate( $id );

        if ( $result ) {
            $schedule = $this->schedulesRepository->get( $id );
            if ( $schedule ) {
                $this->scheduleFromConfig( $schedule );
            }
        }

        return $result;
    }

    /**
     * Disable a scan schedule
     *
     * @param int $id Schedule ID
     * @return bool True on success, false on failure
     */
    public function disableSchedule( int $id ): bool {
        // Cancel all pending Action Scheduler actions for this schedule
        $this->cancelScheduleActions( $id );

        return $this->schedulesRepository->deactivate( $id );
    }

    /**
     * Schedule a scan from schedule configuration
     *
     * @param object $schedule Schedule object from database
     * @return bool True on success, false on failure
     */
    private function scheduleFromConfig( object $schedule ): bool {
        if ( ! $this->isAvailable() ) {
            return false;
        }

        // Convert next_run to timestamp
        $next_run = strtotime( $schedule->next_run );
        if ( ! $next_run ) {
            return false;
        }

        // Check if there's already a pending action for this schedule
        // This prevents duplicate scheduling from race conditions
        if ( $this->hasPendingActionForSchedule( (int) $schedule->id ) ) {
            $this->logger->info(
                sprintf( 'Skipping duplicate scheduling for schedule #%d - pending action already exists', $schedule->id ),
                LoggerService::CONTEXT_SCHEDULER,
                [ 'schedule_id' => $schedule->id ]
            );
            return true; // Return true since an action already exists
        }

        // Schedule with Action Scheduler
        // Wrap args in array so executeScan receives them as a single array parameter
        $action_id = as_schedule_single_action(
            $next_run,
            self::SCAN_ACTION_HOOK,
            [
                [
                    'type' => 'scheduled',
                    'schedule_id' => $schedule->id,
                    'schedule_name' => $schedule->name,
                    'frequency' => $schedule->frequency,
                ]
            ],
            self::ACTION_GROUP
        );

        if ( $action_id ) {
            // Update schedule with Action Scheduler ID
            $this->schedulesRepository->update(
                $schedule->id,
                [ 'action_scheduler_id' => $action_id ]
            );
            return true;
        }

        return false;
    }

    /**
     * Check if a pending action already exists for a specific schedule
     *
     * @param int $schedule_id Schedule ID to check
     * @return bool True if a pending action exists, false otherwise
     */
    private function hasPendingActionForSchedule( int $schedule_id ): bool {
        if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
            return false;
        }

        try {
            $actions = as_get_scheduled_actions( [
                'hook' => self::SCAN_ACTION_HOOK,
                'group' => self::ACTION_GROUP,
                'status' => \ActionScheduler_Store::STATUS_PENDING,
                'per_page' => 100, // Reasonable limit
            ] );

            foreach ( $actions as $action ) {
                $args = $action->get_args();
                // Args are wrapped in an array, so we need to check the first element
                if ( is_array( $args ) && ! empty( $args ) && isset( $args[0]['schedule_id'] ) ) {
                    if ( (int) $args[0]['schedule_id'] === $schedule_id ) {
                        return true;
                    }
                }
            }
        } catch ( \Exception $e ) {
            $this->logger->error(
                'Error checking for pending actions: ' . $e->getMessage(),
                LoggerService::CONTEXT_SCHEDULER,
                [
                    'schedule_id' => $schedule_id,
                    'exception' => $e->getMessage(),
                ]
            );
        }

        return false;
    }

    /**
     * Cancel all Action Scheduler actions for a specific schedule
     *
     * @param int $schedule_id Schedule ID
     * @return int Number of actions cancelled
     */
    private function cancelScheduleActions( int $schedule_id ): int {
        if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
            return 0;
        }

        $cancelled = 0;

        try {
            // Get all pending actions for this schedule
            $actions = as_get_scheduled_actions( [
                'hook' => self::SCAN_ACTION_HOOK,
                'group' => self::ACTION_GROUP,
                'status' => \ActionScheduler_Store::STATUS_PENDING,
                'per_page' => -1,
            ] );

            $this->logger->info(
                sprintf( 'Found %d pending actions to check for schedule #%d', count( $actions ), $schedule_id ),
                LoggerService::CONTEXT_SCHEDULER,
                [
                    'schedule_id' => $schedule_id,
                    'total_pending_actions' => count( $actions ),
                ]
            );

            // Cancel actions that match this schedule_id
            foreach ( $actions as $action_id => $action ) {
                $args = $action->get_args();
                // Args are wrapped in an array, so we need to check the first element
                if ( is_array($args) && !empty($args) && isset( $args[0]['schedule_id'] ) && (int) $args[0]['schedule_id'] === $schedule_id ) {
                    as_unschedule_action( self::SCAN_ACTION_HOOK, $args, self::ACTION_GROUP );
                    $cancelled++;

                    $this->logger->info(
                        sprintf( 'Cancelled action #%d for schedule #%d', $action_id, $schedule_id ),
                        LoggerService::CONTEXT_SCHEDULER,
                        [
                            'action_id' => $action_id,
                            'schedule_id' => $schedule_id,
                        ]
                    );
                }
            }

            if ( $cancelled > 0 ) {
                $this->logger->info(
                    sprintf( 'Cancelled %d Action Scheduler action(s) for schedule #%d', $cancelled, $schedule_id ),
                    LoggerService::CONTEXT_SCHEDULER,
                    [
                        'schedule_id' => $schedule_id,
                        'cancelled_count' => $cancelled,
                    ]
                );
            } else {
                $this->logger->info(
                    sprintf( 'No actions to cancel for schedule #%d', $schedule_id ),
                    LoggerService::CONTEXT_SCHEDULER,
                    [ 'schedule_id' => $schedule_id ]
                );
            }
        } catch ( \Exception $e ) {
            $this->logger->error(
                'Failed to cancel scheduled actions: ' . $e->getMessage(),
                LoggerService::CONTEXT_SCHEDULER,
                [
                    'schedule_id' => $schedule_id,
                    'exception' => $e->getMessage(),
                ]
            );
        }

        return $cancelled;
    }

    /**
     * Cancel a scheduled action
     *
     * @deprecated Use cancelScheduleActions() instead
     * @param int $action_id Action Scheduler action ID
     * @return bool True on success, false on failure
     */
    private function cancelScheduledAction( int $action_id ): bool {
        if ( ! function_exists( 'as_unschedule_action' ) ) {
            return false;
        }

        try {
            // Use the global ActionScheduler class with proper namespace
            if ( class_exists( '\ActionScheduler' ) ) {
                $store = \ActionScheduler::store();
                $action = $store->fetch_action( $action_id );
                if ( $action ) {
                    as_unschedule_action( $action->get_hook(), $action->get_args(), self::ACTION_GROUP );
                    return true;
                }
            }
        } catch ( \Exception $e ) {
            $this->logger->error(
                'Failed to cancel scheduled action: ' . $e->getMessage(),
                LoggerService::CONTEXT_SCHEDULER,
                [
                    'action_id' => $action_id,
                    'exception' => $e->getMessage(),
                ]
            );
        }

        return false;
    }

    /**
     * Get all scan schedules
     *
     * @param array $args Query arguments
     * @return array Array of schedule objects
     */
    public function getSchedules( array $args = [] ): array {
        return $this->schedulesRepository->getAll( $args );
    }

    /**
     * Get a single scan schedule
     *
     * @param int $id Schedule ID
     * @return object|null Schedule object or null if not found
     */
    public function getSchedule( int $id ) {
        return $this->schedulesRepository->get( $id );
    }

    /**
     * Get schedule statistics
     *
     * @return array Statistics array
     */
    public function getScheduleStats(): array {
        return [
            'total' => $this->schedulesRepository->getCount(),
            'active' => $this->schedulesRepository->getCount( true ),
            'inactive' => $this->schedulesRepository->getCount( false ),
        ];
    }

    /**
     * Schedule a one-time scan
     *
     * @param int $timestamp Unix timestamp when to run the scan
     * @return bool True on success, false on failure
     */
    public function scheduleOnetimeScan( ?int $timestamp = null ): bool {
        if ( ! function_exists( 'as_schedule_single_action' ) ) {
            return false;
        }

        $timestamp = $timestamp ?: ( time() + 60 ); // Default to 1 minute from now

        $action_id = as_schedule_single_action(
            $timestamp,
            self::SCAN_ACTION_HOOK,
            [ [ 'type' => 'manual' ] ],
            self::ACTION_GROUP
        );

        return $action_id !== false;
    }

    /**
     * Get next scheduled scan
     *
     * @return object|null Next scheduled scan or null if none
     */
    public function getNextScheduledScan(): ?object {
        if ( ! function_exists( 'as_next_scheduled_action' ) ) {
            return null;
        }

        $timestamp = as_next_scheduled_action( self::SCAN_ACTION_HOOK, [], self::ACTION_GROUP );

        if ( ! $timestamp ) {
            return null;
        }

        return (object) [
            'timestamp' => $timestamp,
            'datetime' => date( 'Y-m-d H:i:s', $timestamp ),
            'time_until' => human_time_diff( time(), $timestamp ),
        ];
    }

    /**
     * Get scheduled scan history
     *
     * @param int $limit Number of records to retrieve
     * @return array Array of scheduled scan history
     */
    public function getScheduledScanHistory( int $limit = 20 ): array {
        if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
            return [];
        }

        $actions = as_get_scheduled_actions( [
            'hook' => self::SCAN_ACTION_HOOK,
            'group' => self::ACTION_GROUP,
            'per_page' => $limit,
            'orderby' => 'date',
            'order' => 'DESC',
        ] );

        $history = [];
        foreach ( $actions as $action_id => $action ) {
            $history[] = [
                'action_id' => $action_id,
                'status' => $action->get_status(),
                'scheduled_date' => $action->get_schedule()->get_date()->format( 'Y-m-d H:i:s' ),
                'args' => $action->get_args(),
            ];
        }

        return $history;
    }

    /**
     * Execute a scheduled scan
     *
     * @param array $args Action arguments
     */
    public function executeScan( array $args = [] ): void {
        $scan_type = $args['type'] ?? 'manual';
        $schedule_id = $args['schedule_id'] ?? null;
        $scan_id = $args['scan_id'] ?? null;
        $queued = $args['queued'] ?? false;

        try {
            // If we have a pre-created scan ID (from queued background scan)
            if ( $scan_id && $queued ) {
                // The scan record already exists with status 'queued'
                // Just run the normal scan which will create its own scan record
                // We'll delete the queued one to avoid duplicates
                $scan_result = $this->integrityService->runScan( $scan_type, null, $schedule_id );

                // Delete the queued scan record since we created a new one
                if ( $scan_result && isset( $scan_result['scan_id'] ) ) {
                    $this->scanResultsRepository->delete( $scan_id );
                }
            } else {
                // Normal scan execution (creates new scan record)
                $scan_result = $this->integrityService->runScan( $scan_type, null, $schedule_id );
            }

            if ( $scan_result && $scan_result['status'] === 'completed' ) {
                // Log successful scan
                $this->logger->success(
                    "Scheduled scan completed successfully. ID: {$scan_result['scan_id']}, Schedule ID: " . ($schedule_id ?: 'none'),
                    LoggerService::CONTEXT_SCHEDULER,
                    [
                        'scan_id' => $scan_result['scan_id'],
                        'schedule_id' => $schedule_id,
                        'scan_type' => $scan_type,
                        'queued' => $queued,
                        'stats' => [
                            'changed_files' => $scan_result['changed_files'],
                            'new_files' => $scan_result['new_files'],
                            'deleted_files' => $scan_result['deleted_files'],
                        ],
                    ]
                );

                // If this was a scheduled scan, update last run and schedule next
                if ( $schedule_id ) {
                    $this->schedulesRepository->updateLastRun( $schedule_id );
                    $schedule = $this->schedulesRepository->get( $schedule_id );
                    if ( $schedule && $schedule->is_active ) {
                        $this->scheduleFromConfig( $schedule );
                    }
                }
            } else {
                $this->logger->error(
                    'Scheduled scan failed',
                    LoggerService::CONTEXT_SCHEDULER,
                    [
                        'scan_id' => $scan_id,
                        'schedule_id' => $schedule_id,
                        'scan_type' => $scan_type,
                        'queued' => $queued,
                    ]
                );
            }
        } catch ( \Exception $e ) {
            // If we have a pre-created scan ID, update it to failed status
            if ( $scan_id && $queued ) {
                $this->scanResultsRepository->update( $scan_id, [
                    'status' => 'failed',
                    'notes' => 'Scan failed: ' . $e->getMessage(),
                ] );
            }

            $this->logger->error(
                'File integrity scan error: ' . $e->getMessage(),
                LoggerService::CONTEXT_SCHEDULER,
                [
                    'scan_id' => $scan_id,
                    'schedule_id' => $schedule_id,
                    'scan_type' => $scan_type,
                    'queued' => $queued,
                    'exception' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );
        }
    }

    /**
     * Schedule log cleanup task
     */
    public function scheduleLogCleanup(): void {
        if ( ! function_exists( 'as_next_scheduled_action' ) ) {
            return;
        }

        // Wait for Action Scheduler to be fully initialized
        add_action( 'action_scheduler_init', function() {
            // Get settings service to check if auto cleanup is enabled
            $settings = new SettingsService();

            if ( ! $settings->isAutoLogCleanupEnabled() ) {
                // Cancel any existing cleanup schedule
                as_unschedule_all_actions( self::LOG_CLEANUP_HOOK, [], self::ACTION_GROUP );
                return;
            }

            // Check if already scheduled
            $next_cleanup = as_next_scheduled_action( self::LOG_CLEANUP_HOOK, [], self::ACTION_GROUP );

            if ( false === $next_cleanup ) {
                // Schedule daily cleanup at 3 AM
                $timestamp = strtotime( 'tomorrow 3:00 AM' );
                as_schedule_recurring_action(
                    $timestamp,
                    DAY_IN_SECONDS,
                    self::LOG_CLEANUP_HOOK,
                    [],
                    self::ACTION_GROUP
                );

                $this->logger->info(
                    'Scheduled daily log cleanup task',
                    LoggerService::CONTEXT_SCHEDULER
                );
            }
        } );
    }

    /**
     * Execute log cleanup
     */
    public function executeLogCleanup(): void {
        try {
            $deleted_count = $this->logger->cleanupOldLogs();

            if ( $deleted_count > 0 ) {
                $this->logger->info(
                    sprintf( 'Log cleanup completed. Deleted %d old log entries.', $deleted_count ),
                    LoggerService::CONTEXT_SCHEDULER,
                    [ 'deleted_count' => $deleted_count ]
                );
            }
        } catch ( \Exception $e ) {
            $this->logger->error(
                'Log cleanup failed: ' . $e->getMessage(),
                LoggerService::CONTEXT_SCHEDULER,
                [ 'exception' => $e->getMessage() ]
            );
        }
    }

    /**
     * Check if Action Scheduler is available
     *
     * @return bool True if available, false otherwise
     */
    public function isAvailable(): bool {
        return class_exists( 'ActionScheduler' ) && function_exists( 'as_schedule_single_action' );
    }
}
