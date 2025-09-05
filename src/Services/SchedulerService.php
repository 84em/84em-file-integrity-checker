<?php
/**
 * Scheduler Service
 *
 * @package EightyFourEM\FileIntegrityChecker\Services
 */

namespace EightyFourEM\FileIntegrityChecker\Services;

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
     * Constructor
     *
     * @param IntegrityService $integrityService Integrity service
     */
    public function __construct( IntegrityService $integrityService ) {
        $this->integrityService = $integrityService;
    }

    /**
     * Initialize scheduler service
     */
    public function init(): void {
        // Hook into Action Scheduler
        add_action( self::SCAN_ACTION_HOOK, [ $this, 'executeScan' ] );
    }

    /**
     * Schedule a recurring scan
     *
     * @param string $interval Scan interval (hourly, daily, weekly, monthly)
     * @return bool True on success, false on failure
     */
    public function scheduleRecurringScan( string $interval ): bool {
        if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
            return false;
        }

        // Cancel existing recurring scans first
        $this->cancelRecurringScans();

        $timestamp = $this->getNextScheduleTime( $interval );
        $recurrence = $this->getRecurrenceInterval( $interval );

        if ( ! $timestamp || ! $recurrence ) {
            return false;
        }

        $action_id = as_schedule_recurring_action(
            $timestamp,
            $recurrence,
            self::SCAN_ACTION_HOOK,
            [ 'type' => 'scheduled', 'interval' => $interval ],
            self::ACTION_GROUP
        );

        if ( $action_id ) {
            update_option( 'eightyfourem_file_integrity_scheduled_action_id', $action_id );
            return true;
        }

        return false;
    }

    /**
     * Schedule a one-time scan
     *
     * @param int $timestamp Unix timestamp when to run the scan
     * @return bool True on success, false on failure
     */
    public function scheduleOnetimeScan( int $timestamp = null ): bool {
        if ( ! function_exists( 'as_schedule_single_action' ) ) {
            return false;
        }

        $timestamp = $timestamp ?: ( time() + 60 ); // Default to 1 minute from now

        $action_id = as_schedule_single_action(
            $timestamp,
            self::SCAN_ACTION_HOOK,
            [ 'type' => 'manual' ],
            self::ACTION_GROUP
        );

        return $action_id !== false;
    }

    /**
     * Cancel all scheduled scans
     *
     * @return int Number of cancelled actions
     */
    public function cancelAllScans(): int {
        if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
            return 0;
        }

        $cancelled = as_unschedule_all_actions( self::SCAN_ACTION_HOOK, [], self::ACTION_GROUP );
        
        // Remove stored action ID
        delete_option( 'eightyfourem_file_integrity_scheduled_action_id' );

        return $cancelled;
    }

    /**
     * Cancel recurring scans only
     *
     * @return int Number of cancelled actions
     */
    public function cancelRecurringScans(): int {
        if ( ! function_exists( 'as_get_scheduled_actions' ) || ! function_exists( 'as_unschedule_action' ) ) {
            return 0;
        }

        $scheduled_actions = as_get_scheduled_actions( [
            'hook' => self::SCAN_ACTION_HOOK,
            'group' => self::ACTION_GROUP,
            'status' => \ActionScheduler_Store::STATUS_PENDING,
            'per_page' => 100,
        ] );

        $cancelled = 0;
        foreach ( $scheduled_actions as $action_id => $action ) {
            if ( $action->get_schedule() instanceof \ActionScheduler_IntervalSchedule ) {
                as_unschedule_action( self::SCAN_ACTION_HOOK, $action->get_args(), self::ACTION_GROUP );
                $cancelled++;
            }
        }

        return $cancelled;
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
        
        try {
            // Run the integrity scan
            $scan_result = $this->integrityService->runScan( $scan_type );
            
            if ( $scan_result && $scan_result['status'] === 'completed' ) {
                // Log successful scan
                error_log( "File integrity scan completed. ID: {$scan_result['scan_id']}" );
                
                // Send notification if there are changes
                if ( $scan_result['changed_files'] > 0 || $scan_result['new_files'] > 0 || $scan_result['deleted_files'] > 0 ) {
                    $this->integrityService->sendChangeNotification( $scan_result['scan_id'] );
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

    /**
     * Get next schedule time based on interval
     *
     * @param string $interval Interval name
     * @return int|false Next schedule timestamp or false on failure
     */
    private function getNextScheduleTime( string $interval ): int|false {
        switch ( $interval ) {
            case 'hourly':
                return strtotime( '+1 hour' );
            case 'daily':
                return strtotime( 'tomorrow 2:00 AM' );
            case 'weekly':
                return strtotime( 'next monday 2:00 AM' );
            case 'monthly':
                return strtotime( 'first day of next month 2:00 AM' );
            default:
                return false;
        }
    }

    /**
     * Get recurrence interval in seconds
     *
     * @param string $interval Interval name
     * @return int|false Recurrence interval in seconds or false on failure
     */
    private function getRecurrenceInterval( string $interval ): int|false {
        switch ( $interval ) {
            case 'hourly':
                return HOUR_IN_SECONDS;
            case 'daily':
                return DAY_IN_SECONDS;
            case 'weekly':
                return WEEK_IN_SECONDS;
            case 'monthly':
                return MONTH_IN_SECONDS;
            default:
                return false;
        }
    }
}