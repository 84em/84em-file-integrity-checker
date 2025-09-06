<?php
/**
 * Scheduler Service
 *
 * @package EightyFourEM\FileIntegrityChecker\Services
 */

namespace EightyFourEM\FileIntegrityChecker\Services;

use EightyFourEM\FileIntegrityChecker\Database\ScanSchedulesRepository;

/**
 * Manages scheduled scans using Action Scheduler
 */
class SchedulerService {
    /**
     * Action hook name for scheduled scans
     */
    private const SCAN_ACTION_HOOK = 'eightyfourem_file_integrity_scan';

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
     * Constructor
     *
     * @param IntegrityService        $integrityService    Integrity service
     * @param ScanSchedulesRepository $schedulesRepository Schedules repository
     */
    public function __construct( IntegrityService $integrityService, ScanSchedulesRepository $schedulesRepository ) {
        $this->integrityService = $integrityService;
        $this->schedulesRepository = $schedulesRepository;
    }

    /**
     * Initialize scheduler service
     */
    public function init(): void {
        // Hook into Action Scheduler
        add_action( self::SCAN_ACTION_HOOK, [ $this, 'executeScan' ] );
        
        // Check for due schedules every hour
        add_action( 'init', [ $this, 'registerScheduleChecker' ] );
    }

    /**
     * Register schedule checker with Action Scheduler
     */
    public function registerScheduleChecker(): void {
        if ( ! $this->isAvailable() ) {
            return;
        }

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

        add_action( 'eightyfourem_check_scan_schedules', [ $this, 'checkAndScheduleDueScans' ] );
    }

    /**
     * Check for due schedules and schedule scans
     */
    public function checkAndScheduleDueScans(): void {
        $due_schedules = $this->schedulesRepository->getDueSchedules();

        foreach ( $due_schedules as $schedule ) {
            // Schedule the scan - don't update last_run yet, that happens after execution
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
        $valid_frequencies = [ 'hourly', 'daily', 'weekly', 'monthly' ];
        if ( ! in_array( $config['frequency'], $valid_frequencies, true ) ) {
            return false;
        }

        // Create schedule in database
        $schedule_id = $this->schedulesRepository->create( $config );

        if ( $schedule_id && ! empty( $config['is_active'] ) ) {
            // Get the created schedule
            $schedule = $this->schedulesRepository->get( $schedule_id );
            if ( $schedule ) {
                // Schedule the first scan
                $this->scheduleFromConfig( $schedule );
            }
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

        // Cancel existing Action Scheduler action if it exists
        if ( $existing->action_scheduler_id ) {
            $this->cancelScheduledAction( $existing->action_scheduler_id );
        }

        // Update schedule in database
        $result = $this->schedulesRepository->update( $id, $config );

        if ( $result ) {
            // Reschedule if active
            $updated = $this->schedulesRepository->get( $id );
            if ( $updated && $updated->is_active ) {
                $this->scheduleFromConfig( $updated );
            }
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
        // Get schedule to cancel Action Scheduler action
        $schedule = $this->schedulesRepository->get( $id );
        if ( $schedule && $schedule->action_scheduler_id ) {
            $this->cancelScheduledAction( $schedule->action_scheduler_id );
        }

        return $this->schedulesRepository->delete( $id );
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
        $schedule = $this->schedulesRepository->get( $id );
        
        if ( $schedule && $schedule->action_scheduler_id ) {
            $this->cancelScheduledAction( $schedule->action_scheduler_id );
        }

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
     * Cancel a scheduled action
     *
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
            error_log( 'Failed to cancel scheduled action: ' . $e->getMessage() );
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
        
        try {
            // Run the integrity scan, passing schedule_id if available
            $scan_result = $this->integrityService->runScan( $scan_type, null, $schedule_id );
            
            if ( $scan_result && $scan_result['status'] === 'completed' ) {
                // Log successful scan
                error_log( "File integrity scan completed. ID: {$scan_result['scan_id']}, Schedule ID: " . ($schedule_id ?: 'none') );
                
                // Send notification if there are changes
                if ( $scan_result['changed_files'] > 0 || $scan_result['new_files'] > 0 || $scan_result['deleted_files'] > 0 ) {
                    $this->integrityService->sendChangeNotification( $scan_result['scan_id'] );
                }

                // If this was a scheduled scan, update last run and schedule next
                if ( $schedule_id ) {
                    $this->schedulesRepository->updateLastRun( $schedule_id );
                    $schedule = $this->schedulesRepository->get( $schedule_id );
                    if ( $schedule && $schedule->is_active ) {
                        $this->scheduleFromConfig( $schedule );
                    }
                }
            } else {
                error_log( "File integrity scan failed" );
            }
        } catch ( \Exception $e ) {
            error_log( "File integrity scan error: " . $e->getMessage() );
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