<?php
/**
 * WP-CLI Integrity Command
 *
 * @package EightyFourEM\FileIntegrityChecker\CLI
 */

namespace EightyFourEM\FileIntegrityChecker\CLI;

use EightyFourEM\FileIntegrityChecker\Services\IntegrityService;
use EightyFourEM\FileIntegrityChecker\Services\SettingsService;
use EightyFourEM\FileIntegrityChecker\Services\SchedulerService;
use EightyFourEM\FileIntegrityChecker\Database\ScanResultsRepository;
use EightyFourEM\FileIntegrityChecker\Database\LogRepository;
use EightyFourEM\FileIntegrityChecker\Database\FileRecordRepository;

/**
 * WP-CLI commands for file integrity checking
 */
class IntegrityCommand {
    /**
     * Integrity service
     *
     * @var IntegrityService
     */
    private IntegrityService $integrityService;

    /**
     * Settings service
     *
     * @var SettingsService
     */
    private SettingsService $settingsService;

    /**
     * Scheduler service
     *
     * @var SchedulerService
     */
    private SchedulerService $schedulerService;

    /**
     * Scan results repository
     *
     * @var ScanResultsRepository
     */
    private ScanResultsRepository $scanResultsRepository;

    /**
     * Log repository
     *
     * @var LogRepository
     */
    private LogRepository $logRepository;

    /**
     * File record repository
     *
     * @var FileRecordRepository
     */
    private FileRecordRepository $fileRecordRepository;

    /**
     * Constructor
     *
     * @param IntegrityService      $integrityService      Integrity service
     * @param SettingsService       $settingsService       Settings service
     * @param SchedulerService      $schedulerService      Scheduler service
     * @param ScanResultsRepository $scanResultsRepository Scan results repository
     * @param LogRepository         $logRepository         Log repository
     * @param FileRecordRepository  $fileRecordRepository  File record repository
     */
    public function __construct(
        IntegrityService $integrityService,
        SettingsService $settingsService,
        SchedulerService $schedulerService,
        ScanResultsRepository $scanResultsRepository,
        LogRepository $logRepository,
        FileRecordRepository $fileRecordRepository
    ) {
        $this->integrityService      = $integrityService;
        $this->settingsService       = $settingsService;
        $this->schedulerService      = $schedulerService;
        $this->scanResultsRepository = $scanResultsRepository;
        $this->logRepository         = $logRepository;
        $this->fileRecordRepository  = $fileRecordRepository;
    }

    /**
     * Run a file integrity scan
     *
     * ## OPTIONS
     *
     * [--type=<type>]
     * : Type of scan to run (manual, scheduled)
     * ---
     * default: manual
     * options:
     *   - manual
     *   - scheduled
     * ---
     *
     * [--format=<format>]
     * : Output format
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp 84em integrity scan
     *     wp 84em integrity scan --type=scheduled
     *     wp 84em integrity scan --format=json
     *
     * @param array $args       Command arguments
     * @param array $assoc_args Associative arguments
     */
    public function scan( array $args, array $assoc_args ): void {
        $scan_type = $assoc_args['type'] ?? 'manual';
        $format = $assoc_args['format'] ?? 'table';

        \WP_CLI::line( "Starting file integrity scan (type: $scan_type)..." );

        $progress = \WP_CLI\Utils\make_progress_bar( 'Scanning files', 1000 );

        $scan_result = $this->integrityService->runScan( $scan_type, function ( $message, $current_file ) use ( $progress ) {
            $progress->tick();
            if ( strpos( $message, 'Scanning files:' ) === 0 ) {
                // Extract count from message
                preg_match( '/Scanning files: (\d+) processed/', $message, $matches );
                if ( isset( $matches[1] ) ) {
                    $count = (int) $matches[1];
                    $progress->current = min( $count, $progress->total );
                }
            }
        } );

        $progress->finish();

        if ( ! $scan_result ) {
            \WP_CLI::error( 'Scan failed to complete.' );
            return;
        }

        \WP_CLI::success( "Scan completed successfully!" );

        $output_data = [
            [
                'Scan ID' => $scan_result['scan_id'],
                'Status' => ucfirst( $scan_result['status'] ),
                'Duration' => $scan_result['duration'] . 's',
                'Memory Usage' => size_format( $scan_result['memory_usage'] ),
                'Total Files' => number_format( $scan_result['total_files'] ),
                'Changed Files' => number_format( $scan_result['changed_files'] ),
                'New Files' => number_format( $scan_result['new_files'] ),
                'Deleted Files' => number_format( $scan_result['deleted_files'] ),
            ]
        ];

        \WP_CLI\Utils\format_items( $format, $output_data, array_keys( $output_data[0] ) );

        if ( $scan_result['changed_files'] > 0 || $scan_result['new_files'] > 0 || $scan_result['deleted_files'] > 0 ) {
            \WP_CLI::warning( "Changes detected! Run 'wp 84em integrity results {$scan_result['scan_id']}' for details." );
        }
    }

    /**
     * View scan results
     *
     * ## OPTIONS
     *
     * [<scan-id>]
     * : Specific scan ID to view. If omitted, shows recent scans.
     *
     * [--limit=<limit>]
     * : Number of results to show when listing recent scans
     * ---
     * default: 10
     * ---
     *
     * [--status=<status>]
     * : Filter by scan status
     * ---
     * options:
     *   - completed
     *   - failed
     *   - running
     * ---
     *
     * [--format=<format>]
     * : Output format
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp 84em integrity results
     *     wp 84em integrity results 123
     *     wp 84em integrity results --status=completed --limit=5
     *
     * @param array $args       Command arguments
     * @param array $assoc_args Associative arguments
     */
    public function results( array $args, array $assoc_args ): void {
        $scan_id = $args[0] ?? null;
        $format = $assoc_args['format'] ?? 'table';

        if ( $scan_id ) {
            $this->showScanDetails( (int) $scan_id, $format );
        } else {
            $this->showRecentScans( $assoc_args, $format );
        }
    }

    /**
     * Manage scan scheduling
     *
     * ## OPTIONS
     *
     * <action>
     * : Action to perform
     * ---
     * options:
     *   - enable
     *   - disable
     *   - status
     * ---
     *
     * [--interval=<interval>]
     * : Scan interval for enable action
     * ---
     * default: daily
     * options:
     *   - hourly
     *   - daily
     *   - weekly
     *   - monthly
     * ---
     *
     * ## EXAMPLES
     *
     *     wp 84em integrity schedule enable
     *     wp 84em integrity schedule enable --interval=weekly
     *     wp 84em integrity schedule disable
     *     wp 84em integrity schedule status
     *
     * @param array $args       Command arguments
     * @param array $assoc_args Associative arguments
     */
    public function schedule( array $args, array $assoc_args ): void {
        if ( ! $this->schedulerService->isAvailable() ) {
            \WP_CLI::error( 'Action Scheduler is not available. Please install a plugin that provides it (e.g., WooCommerce).' );
            return;
        }

        $action = $args[0] ?? '';

        switch ( $action ) {
            case 'enable':
                $this->enableScheduling( $assoc_args );
                break;
            case 'disable':
                $this->disableScheduling();
                break;
            case 'status':
                $this->showScheduleStatus();
                break;
            default:
                \WP_CLI::error( "Invalid action '$action'. Use 'enable', 'disable', or 'status'." );
        }
    }

    /**
     * Manage plugin configuration
     *
     * ## OPTIONS
     *
     * <action>
     * : Action to perform
     * ---
     * options:
     *   - get
     *   - set
     *   - list
     * ---
     *
     * [<setting>]
     * : Setting name (required for get/set actions)
     *
     * [<value>]
     * : Setting value (required for set action)
     *
     * [--format=<format>]
     * : Output format for list action
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp 84em integrity config list
     *     wp 84em integrity config get scan_interval
     *     wp 84em integrity config set scan_interval weekly
     *
     * @param array $args       Command arguments
     * @param array $assoc_args Associative arguments
     */
    public function config( array $args, array $assoc_args ): void {
        $action = $args[0] ?? '';

        switch ( $action ) {
            case 'list':
                $this->listSettings( $assoc_args );
                break;
            case 'get':
                $this->getSetting( $args[1] ?? '' );
                break;
            case 'set':
                $this->setSetting( $args[1] ?? '', $args[2] ?? '' );
                break;
            default:
                \WP_CLI::error( "Invalid action '$action'. Use 'list', 'get', or 'set'." );
        }
    }

    /**
     * Manage scan schedules
     *
     * ## OPTIONS
     *
     * <action>
     * : Action to perform
     * ---
     * options:
     *   - list
     *   - create
     *   - delete
     *   - enable
     *   - disable
     * ---
     *
     * [--id=<id>]
     * : Schedule ID (required for delete/enable/disable actions)
     *
     * [--name=<name>]
     * : Schedule name (required for create action)
     *
     * [--frequency=<frequency>]
     * : Schedule frequency (required for create action)
     * ---
     * options:
     *   - hourly
     *   - daily
     *   - weekly
     *   - monthly
     * ---
     *
     * [--time=<time>]
     * : Time in HH:MM format (optional for create action)
     *
     * [--day-of-week=<day>]
     * : Day of week (0-6, 0=Sunday) for weekly schedules
     *
     * [--day-of-month=<day>]
     * : Day of month (1-31) for monthly schedules
     *
     * [--active]
     * : Whether to activate the schedule immediately (for create action)
     *
     * [--format=<format>]
     * : Output format for list action
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp 84em integrity schedules list
     *     wp 84em integrity schedules create --name="Daily Scan" --frequency=daily --time=02:00 --active
     *     wp 84em integrity schedules delete --id=1
     *     wp 84em integrity schedules enable --id=1
     *     wp 84em integrity schedules disable --id=1
     *
     * @param array $args       Command arguments
     * @param array $assoc_args Associative arguments
     */
    public function schedules( array $args, array $assoc_args ): void {
        $action = $args[0] ?? 'list';

        switch ( $action ) {
            case 'list':
                $this->listSchedules( $assoc_args );
                break;
            case 'create':
                $this->createSchedule( $assoc_args );
                break;
            case 'delete':
                $this->deleteSchedule( $assoc_args );
                break;
            case 'enable':
                $this->enableScheduleCommand( $assoc_args );
                break;
            case 'disable':
                $this->disableScheduleCommand( $assoc_args );
                break;
            default:
                \WP_CLI::error( "Invalid action '$action'. Use 'list', 'create', 'delete', 'enable', or 'disable'." );
        }
    }

    /**
     * Show details for a specific scan
     *
     * @param int    $scan_id Scan ID
     * @param string $format  Output format
     */
    private function showScanDetails( int $scan_id, string $format ): void {
        $scan_summary = $this->integrityService->getScanSummary( $scan_id );

        if ( ! $scan_summary ) {
            \WP_CLI::error( "Scan ID $scan_id not found." );
            return;
        }

        \WP_CLI::line( "Scan Details for ID: $scan_id" );

        $details = [
            [
                'Property' => 'Date',
                'Value' => $scan_summary['scan_date'],
            ],
            [
                'Property' => 'Status',
                'Value' => ucfirst( $scan_summary['status'] ),
            ],
            [
                'Property' => 'Type',
                'Value' => ucfirst( $scan_summary['scan_type'] ),
            ],
            [
                'Property' => 'Duration',
                'Value' => $scan_summary['duration'] . 's',
            ],
            [
                'Property' => 'Memory Usage',
                'Value' => size_format( $scan_summary['memory_usage'] ),
            ],
            [
                'Property' => 'Total Files',
                'Value' => number_format( $scan_summary['total_files'] ),
            ],
            [
                'Property' => 'Changed Files',
                'Value' => number_format( $scan_summary['changed_files'] ),
            ],
            [
                'Property' => 'New Files',
                'Value' => number_format( $scan_summary['new_files'] ),
            ],
            [
                'Property' => 'Deleted Files',
                'Value' => number_format( $scan_summary['deleted_files'] ),
            ],
            [
                'Property' => 'Total Size',
                'Value' => size_format( $scan_summary['total_size'] ),
            ],
        ];

        \WP_CLI\Utils\format_items( $format, $details, [ 'Property', 'Value' ] );

        if ( $scan_summary['changed_files'] > 0 ) {
            \WP_CLI::line( "\nChanged Files:" );
            // Note: In a full implementation, you'd show the actual changed files here
            \WP_CLI::line( "Use the admin interface to view detailed file changes." );
        }
    }

    /**
     * Show recent scans
     *
     * @param array  $assoc_args Associative arguments
     * @param string $format     Output format
     */
    private function showRecentScans( array $assoc_args, string $format ): void {
        $limit = (int) ( $assoc_args['limit'] ?? 10 );
        $scans = $this->scanResultsRepository->getRecent( $limit );

        if ( empty( $scans ) ) {
            \WP_CLI::line( 'No scans found.' );
            return;
        }

        $output_data = [];
        foreach ( $scans as $scan ) {
            $output_data[] = [
                'ID' => $scan->id,
                'Date' => $scan->scan_date,
                'Status' => ucfirst( $scan->status ),
                'Type' => ucfirst( $scan->scan_type ),
                'Total Files' => number_format( $scan->total_files ),
                'Changed' => number_format( $scan->changed_files ),
                'Duration' => $scan->scan_duration . 's',
            ];
        }

        \WP_CLI\Utils\format_items( $format, $output_data, array_keys( $output_data[0] ) );
    }

    /**
     * Enable scan scheduling
     *
     * @param array $assoc_args Associative arguments
     */
    private function enableScheduling( array $assoc_args ): void {
        $interval = $assoc_args['interval'] ?? 'daily';

        if ( $this->schedulerService->scheduleRecurringScan( $interval ) ) {
            $this->settingsService->setScanInterval( $interval );
            \WP_CLI::success( "Scheduled scans enabled with $interval interval." );
        } else {
            \WP_CLI::error( 'Failed to enable scheduled scans.' );
        }
    }

    /**
     * Disable scan scheduling
     */
    private function disableScheduling(): void {
        $cancelled = $this->schedulerService->cancelAllScans();
        \WP_CLI::success( "Disabled scheduled scans. Cancelled $cancelled scheduled actions." );
    }

    /**
     * Show schedule status
     */
    private function showScheduleStatus(): void {
        $next_scan = $this->schedulerService->getNextScheduledScan();

        if ( $next_scan ) {
            \WP_CLI::line( "Next scheduled scan: {$next_scan->datetime} ({$next_scan->time_until})" );
            \WP_CLI::line( "Current interval: " . $this->settingsService->getScanInterval() );
        } else {
            \WP_CLI::line( "No scans are currently scheduled." );
        }
    }

    /**
     * List all settings
     *
     * @param array $assoc_args Associative arguments
     */
    private function listSettings( array $assoc_args ): void {
        $format = $assoc_args['format'] ?? 'table';
        $settings = $this->settingsService->getAllSettings();

        $output_data = [];
        foreach ( $settings as $key => $value ) {
            $display_value = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
            if ( is_bool( $value ) ) {
                $display_value = $value ? 'true' : 'false';
            }

            $output_data[] = [
                'Setting' => $key,
                'Value' => $display_value,
            ];
        }

        \WP_CLI\Utils\format_items( $format, $output_data, [ 'Setting', 'Value' ] );
    }

    /**
     * Get a specific setting
     *
     * @param string $setting Setting name
     */
    private function getSetting( string $setting ): void {
        if ( empty( $setting ) ) {
            \WP_CLI::error( 'Setting name is required.' );
            return;
        }

        $settings = $this->settingsService->getAllSettings();

        if ( ! array_key_exists( $setting, $settings ) ) {
            \WP_CLI::error( "Setting '$setting' not found." );
            return;
        }

        $value = $settings[ $setting ];
        if ( is_array( $value ) ) {
            $value = implode( ', ', $value );
        } elseif ( is_bool( $value ) ) {
            $value = $value ? 'true' : 'false';
        }

        \WP_CLI::line( (string) $value );
    }

    /**
     * Set a specific setting
     *
     * @param string $setting Setting name
     * @param string $value   Setting value
     */
    private function setSetting( string $setting, string $value ): void {
        if ( empty( $setting ) || empty( $value ) ) {
            \WP_CLI::error( 'Setting name and value are required.' );
            return;
        }

        $result = false;

        switch ( $setting ) {
            case 'scan_interval':
                $result = $this->settingsService->setScanInterval( $value );
                break;
            case 'notification_email':
                $result = $this->settingsService->setNotificationEmail( $value );
                break;
            case 'max_file_size':
                $result = $this->settingsService->setMaxFileSize( (int) $value );
                break;
            case 'retention_period':
                $result = $this->settingsService->setRetentionPeriod( (int) $value );
                break;
            case 'notification_enabled':
                $result = $this->settingsService->setNotificationEnabled( $value === 'true' );
                break;
            default:
                \WP_CLI::error( "Setting '$setting' cannot be modified via CLI." );
                return;
        }

        if ( $result ) {
            \WP_CLI::success( "Updated $setting to: $value" );
        } else {
            \WP_CLI::error( "Failed to update $setting. Check that the value is valid." );
        }
    }

    /**
     * List all schedules
     *
     * @param array $assoc_args Associative arguments
     */
    private function listSchedules( array $assoc_args ): void {
        $format = $assoc_args['format'] ?? 'table';
        $schedules = $this->schedulerService->getSchedules();

        if ( empty( $schedules ) ) {
            \WP_CLI::line( 'No schedules found.' );
            return;
        }

        $output_data = [];
        foreach ( $schedules as $schedule ) {
            $output_data[] = [
                'ID' => $schedule->id,
                'Name' => $schedule->name,
                'Frequency' => ucfirst( $schedule->frequency ),
                'Status' => $schedule->is_active ? 'Active' : 'Inactive',
                'Last Run' => $schedule->last_run ?: 'Never',
                'Next Run' => $schedule->next_run ?: 'N/A',
            ];
        }

        \WP_CLI\Utils\format_items( $format, $output_data, [ 'ID', 'Name', 'Frequency', 'Status', 'Last Run', 'Next Run' ] );
    }

    /**
     * Create a new schedule
     *
     * @param array $assoc_args Associative arguments
     */
    private function createSchedule( array $assoc_args ): void {
        if ( empty( $assoc_args['name'] ) || empty( $assoc_args['frequency'] ) ) {
            \WP_CLI::error( 'Schedule name and frequency are required.' );
            return;
        }

        $config = [
            'name' => $assoc_args['name'],
            'frequency' => $assoc_args['frequency'],
            'time' => $assoc_args['time'] ?? null,
            'day_of_week' => isset( $assoc_args['day-of-week'] ) ? (int) $assoc_args['day-of-week'] : null,
            'day_of_month' => isset( $assoc_args['day-of-month'] ) ? (int) $assoc_args['day-of-month'] : null,
            'is_active' => isset( $assoc_args['active'] ) ? 1 : 0,
        ];

        $schedule_id = $this->schedulerService->createSchedule( $config );

        if ( $schedule_id ) {
            \WP_CLI::success( "Schedule created successfully with ID: $schedule_id" );
        } else {
            \WP_CLI::error( 'Failed to create schedule. Please check your parameters.' );
        }
    }

    /**
     * Delete a schedule
     *
     * @param array $assoc_args Associative arguments
     */
    private function deleteSchedule( array $assoc_args ): void {
        if ( empty( $assoc_args['id'] ) ) {
            \WP_CLI::error( 'Schedule ID is required.' );
            return;
        }

        $schedule_id = (int) $assoc_args['id'];
        
        if ( $this->schedulerService->deleteSchedule( $schedule_id ) ) {
            \WP_CLI::success( "Schedule $schedule_id deleted successfully." );
        } else {
            \WP_CLI::error( "Failed to delete schedule $schedule_id." );
        }
    }

    /**
     * Enable a schedule
     *
     * @param array $assoc_args Associative arguments
     */
    private function enableScheduleCommand( array $assoc_args ): void {
        if ( empty( $assoc_args['id'] ) ) {
            \WP_CLI::error( 'Schedule ID is required.' );
            return;
        }

        $schedule_id = (int) $assoc_args['id'];
        
        if ( $this->schedulerService->enableSchedule( $schedule_id ) ) {
            \WP_CLI::success( "Schedule $schedule_id enabled successfully." );
        } else {
            \WP_CLI::error( "Failed to enable schedule $schedule_id." );
        }
    }

    /**
     * Disable a schedule
     *
     * @param array $assoc_args Associative arguments
     */
    private function disableScheduleCommand( array $assoc_args ): void {
        if ( empty( $assoc_args['id'] ) ) {
            \WP_CLI::error( 'Schedule ID is required.' );
            return;
        }

        $schedule_id = (int) $assoc_args['id'];
        
        if ( $this->schedulerService->disableSchedule( $schedule_id ) ) {
            \WP_CLI::success( "Schedule $schedule_id disabled successfully." );
        } else {
            \WP_CLI::error( "Failed to disable schedule $schedule_id." );
        }
    }

    /**
     * Recalculate next run times for all schedules
     *
     * ## EXAMPLES
     *
     *     wp 84em integrity recalculate-schedules
     *
     * @param array $args       Command arguments
     * @param array $assoc_args Associative arguments
     */
    public function recalculate_schedules( array $args, array $assoc_args ): void {
        $schedules_repo = new \EightyFourEM\FileIntegrityChecker\Database\ScanSchedulesRepository(
            new \EightyFourEM\FileIntegrityChecker\Database\DatabaseManager()
        );

        \WP_CLI::line( 'Recalculating next run times for all active schedules...' );

        $updated = $schedules_repo->recalculateAllNextRuns();

        if ( $updated > 0 ) {
            \WP_CLI::success( "Updated $updated schedule(s)." );
        } else {
            \WP_CLI::line( 'No schedules needed updating.' );
        }
    }

    /**
     * Mark a scan as baseline
     *
     * ## OPTIONS
     *
     * <scan-id>
     * : The scan ID to mark as baseline
     *
     * ## EXAMPLES
     *
     *     wp 84em integrity baseline mark 123
     *
     * @param array $args       Command arguments
     * @param array $assoc_args Associative arguments
     */
    public function baseline( array $args, array $assoc_args ): void {
        if ( empty( $args[0] ) ) {
            \WP_CLI::error( 'Please specify a subcommand: mark, show, or clear' );
        }

        $subcommand = $args[0];

        switch ( $subcommand ) {
            case 'mark':
                $this->markBaseline( $args, $assoc_args );
                break;
            case 'show':
                $this->showBaseline( $args, $assoc_args );
                break;
            case 'clear':
                $this->clearBaseline( $args, $assoc_args );
                break;
            default:
                \WP_CLI::error( "Unknown subcommand: $subcommand. Use mark, show, or clear." );
        }
    }

    /**
     * Mark a scan as baseline
     *
     * @param array $args       Command arguments
     * @param array $assoc_args Associative arguments
     */
    private function markBaseline( array $args, array $assoc_args ): void {
        if ( empty( $args[1] ) ) {
            \WP_CLI::error( 'Please specify a scan ID' );
        }

        $scan_id = (int) $args[1];

        // Validate scan exists
        $scan = $this->scanResultsRepository->getById( $scan_id );
        if ( ! $scan ) {
            \WP_CLI::error( "Scan #$scan_id not found" );
        }

        // Mark as baseline
        $result = $this->scanResultsRepository->markAsBaseline( $scan_id );

        if ( $result ) {
            \WP_CLI::success( "Scan #$scan_id marked as baseline" );
        } else {
            \WP_CLI::error( "Failed to mark scan #$scan_id as baseline" );
        }
    }

    /**
     * Show the current baseline scan
     *
     * @param array $args       Command arguments
     * @param array $assoc_args Associative arguments
     */
    private function showBaseline( array $args, array $assoc_args ): void {
        $baseline_scan = $this->scanResultsRepository->getBaselineScan();

        if ( ! $baseline_scan ) {
            \WP_CLI::line( 'No baseline scan is currently set' );
            return;
        }

        $format = $assoc_args['format'] ?? 'table';

        $data = [
            [
                'Scan ID'       => $baseline_scan->id,
                'Date'          => $baseline_scan->scan_date,
                'Status'        => $baseline_scan->status,
                'Total Files'   => $baseline_scan->total_files,
                'Changed Files' => $baseline_scan->changed_files,
                'New Files'     => $baseline_scan->new_files,
                'Deleted Files' => $baseline_scan->deleted_files,
            ],
        ];

        \WP_CLI\Utils\format_items( $format, $data, array_keys( $data[0] ) );
    }

    /**
     * Clear the baseline scan
     *
     * @param array $args       Command arguments
     * @param array $assoc_args Associative arguments
     */
    private function clearBaseline( array $args, array $assoc_args ): void {
        $baseline_id = $this->scanResultsRepository->getBaselineScanId();

        if ( ! $baseline_id ) {
            \WP_CLI::line( 'No baseline scan is currently set' );
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'eightyfourem_integrity_scan_results';

        $result = $wpdb->update(
            $table,
            [ 'is_baseline' => 0 ],
            [ 'id' => $baseline_id ],
            [ '%d' ],
            [ '%d' ]
        );

        if ( $result !== false ) {
            \WP_CLI::success( "Baseline cleared (scan #$baseline_id is no longer baseline)" );
        } else {
            \WP_CLI::error( 'Failed to clear baseline' );
        }
    }

    /**
     * View system logs
     *
     * ## OPTIONS
     *
     * [--level=<level>]
     * : Filter by log level
     * ---
     * options:
     *   - success
     *   - error
     *   - warning
     *   - info
     *   - debug
     * ---
     *
     * [--context=<context>]
     * : Filter by context (e.g., scanner, scheduler, notifications, admin, cli, settings, database, security, general, cache_cleanup)
     *
     * [--search=<search>]
     * : Search in log messages
     *
     * [--limit=<limit>]
     * : Number of logs to display
     * ---
     * default: 50
     * ---
     *
     * [--format=<format>]
     * : Output format
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp 84em integrity logs
     *     wp 84em integrity logs --level=error
     *     wp 84em integrity logs --context=database
     *     wp 84em integrity logs --search="migration"
     *     wp 84em integrity logs --limit=100 --format=json
     *
     * @param array $args       Command arguments
     * @param array $assoc_args Associative arguments
     */
    public function logs( array $args, array $assoc_args ): void {
        $query_args = [
            'limit' => isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 50,
        ];

        if ( isset( $assoc_args['level'] ) ) {
            $query_args['log_level'] = $assoc_args['level'];
        }

        if ( isset( $assoc_args['context'] ) ) {
            $query_args['context'] = $assoc_args['context'];
        }

        if ( isset( $assoc_args['search'] ) ) {
            $query_args['search'] = $assoc_args['search'];
        }

        $logs = $this->logRepository->getAll( $query_args );

        if ( empty( $logs ) ) {
            \WP_CLI::warning( 'No logs found' );
            return;
        }

        $format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

        // Format logs for display
        $formatted_logs = array_map(
            function ( $log ) {
                return [
                    'ID' => $log['id'],
                    'Date' => $log['created_at'],
                    'Level' => strtoupper( $log['log_level'] ),
                    'Context' => $log['context'],
                    'Message' => $log['message'],
                    'User ID' => $log['user_id'] ?: 'N/A',
                ];
            },
            $logs
        );

        \WP_CLI\Utils\format_items(
            format: $format,
            items: $formatted_logs,
            fields: [ 'ID', 'Date', 'Level', 'Context', 'Message', 'User ID' ]
        );
    }

    /**
     * Analyze database bloat in file_records table
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp 84em integrity analyze-bloat
     *     wp 84em integrity analyze_bloat
     *     wp 84em integrity analyze-bloat --format=json
     *
     * @subcommand analyze-bloat
     *
     * @param array $args       Command arguments
     * @param array $assoc_args Associative arguments
     */
    public function analyze_bloat( array $args, array $assoc_args ): void {
        \WP_CLI::line( 'Analyzing file_records table bloat...' );
        \WP_CLI::line( '' );

        $format = $assoc_args['format'] ?? 'table';

        // Get table statistics
        $table_stats = $this->fileRecordRepository->getTableStatistics();

        \WP_CLI::line( '=== TABLE SIZE STATISTICS ===' );
        \WP_CLI::line( sprintf( 'Total Rows: %s', number_format( $table_stats['total_rows'] ) ) );
        \WP_CLI::line( sprintf( 'Total Size: %.2f MB', $table_stats['total_size_mb'] ) );
        \WP_CLI::line( sprintf( 'Data Size: %.2f MB', $table_stats['data_size_mb'] ) );
        \WP_CLI::line( sprintf( 'Index Size: %.2f MB', $table_stats['index_size_mb'] ) );
        \WP_CLI::line( sprintf( 'Avg Row Size: %.2f bytes', $table_stats['avg_row_size_bytes'] ) );
        \WP_CLI::line( '' );

        // Get distribution by age
        \WP_CLI::line( '=== RECORD DISTRIBUTION BY AGE ===' );
        $distribution = $this->fileRecordRepository->getRecordDistributionByAge();

        if ( $format === 'table' ) {
            \WP_CLI\Utils\format_items(
                format: 'table',
                items: $distribution,
                fields: [ 'age_range', 'scan_count', 'record_count' ]
            );
        } else {
            \WP_CLI\Utils\format_items(
                format: $format,
                items: $distribution,
                fields: [ 'age_range', 'scan_count', 'record_count' ]
            );
        }

        \WP_CLI::line( '' );

        // Get status counts
        \WP_CLI::line( '=== RECORD COUNT BY STATUS ===' );
        $status_counts = $this->fileRecordRepository->getRecordCountByStatus();

        // Calculate total records from status counts
        $total_records = array_sum( $status_counts );

        $status_data = [];
        foreach ( $status_counts as $status => $count ) {
            $status_data[] = [
                'Status' => $status,
                'Count' => number_format( $count ),
                'Percentage' => $total_records > 0 ? sprintf( '%.2f%%', ( $count / $total_records ) * 100 ) : '0.00%',
            ];
        }

        \WP_CLI\Utils\format_items(
            format: $format,
            items: $status_data,
            fields: [ 'Status', 'Count', 'Percentage' ]
        );

        \WP_CLI::line( '' );

        // Get largest diffs
        \WP_CLI::line( '=== TOP 10 LARGEST DIFF RECORDS ===' );
        $largest_diffs = $this->fileRecordRepository->getLargestDiffRecords( limit: 10 );

        if ( empty( $largest_diffs ) ) {
            \WP_CLI::line( 'No diff content found.' );
        } else {
            $diff_data = [];
            foreach ( $largest_diffs as $record ) {
                $diff_data[] = [
                    'Scan ID' => $record['scan_result_id'],
                    'File Path' => substr( $record['file_path'], 0, 50 ) . ( strlen( $record['file_path'] ) > 50 ? '...' : '' ),
                    'Scan Date' => $record['scan_date'],
                    'Diff Size' => sprintf( '%.2f KB', $record['diff_size_kb'] ),
                ];
            }

            \WP_CLI\Utils\format_items(
                format: $format,
                items: $diff_data,
                fields: [ 'Scan ID', 'File Path', 'Scan Date', 'Diff Size' ]
            );
        }

        \WP_CLI::line( '' );

        // Get retention settings
        $tier2_days = $this->settingsService->getRetentionTier2Days();
        $tier3_days = $this->settingsService->getRetentionTier3Days();

        \WP_CLI::line( '=== CURRENT RETENTION POLICY ===' );
        \WP_CLI::line( sprintf( 'Tier 2 (full detail): %d days', $tier2_days ) );
        \WP_CLI::line( sprintf( 'Tier 3 (summary only): %d days', $tier3_days ) );
        \WP_CLI::line( sprintf( 'Keep baseline: %s', $this->settingsService->shouldKeepBaseline() ? 'Yes' : 'No' ) );
        \WP_CLI::line( '' );

        // Calculate potential savings
        $records_older_than_tier2 = 0;
        foreach ( $distribution as $range ) {
            if ( in_array( $range['age_range'], [ '31-90 days', '91-180 days', '180+ days' ], true ) ) {
                $records_older_than_tier2 += $range['record_count'];
            }
        }

        if ( $records_older_than_tier2 > 0 ) {
            $estimated_savings_mb = ( $records_older_than_tier2 * $table_stats['avg_row_size_bytes'] ) / 1024 / 1024;

            \WP_CLI::warning( sprintf(
                'BLOAT DETECTED: %s records older than %d days could be cleaned up',
                number_format( $records_older_than_tier2 ),
                $tier2_days
            ) );
            \WP_CLI::line( sprintf( 'Estimated space savings: %.2f MB', $estimated_savings_mb ) );
            \WP_CLI::line( '' );
            \WP_CLI::line( 'Run cleanup with: wp 84em integrity cleanup --dry-run' );
        } else {
            \WP_CLI::success( 'No significant bloat detected. Table is within retention policy.' );
        }
    }

    /**
     * Clean up old file_records to reduce database bloat
     *
     * ## OPTIONS
     *
     * [--days=<days>]
     * : Delete file_records for scans older than this many days (default: 30)
     * ---
     * default: 30
     * ---
     *
     * [--dry-run]
     * : Show what would be deleted without actually deleting
     *
     * [--force]
     * : Skip confirmation prompt
     *
     * ## EXAMPLES
     *
     *     wp 84em integrity cleanup --dry-run
     *     wp 84em integrity cleanup --days=30
     *     wp 84em integrity cleanup --days=60 --force
     *
     * @param array $args       Command arguments
     * @param array $assoc_args Associative arguments
     */
    public function cleanup( array $args, array $assoc_args ): void {
        $days = isset( $assoc_args['days'] ) ? (int) $assoc_args['days'] : 30;
        $dry_run = isset( $assoc_args['dry-run'] );
        $force = isset( $assoc_args['force'] );

        if ( $days < 1 ) {
            \WP_CLI::error( 'Days must be at least 1' );
            return;
        }

        \WP_CLI::line( sprintf( 'Analyzing file_records older than %d days...', $days ) );
        \WP_CLI::line( '' );

        // Get protected scan IDs (baseline and critical)
        $protected_ids = [];

        if ( $this->settingsService->shouldKeepBaseline() ) {
            $baseline_id = $this->scanResultsRepository->getBaselineScanId();
            if ( $baseline_id ) {
                $protected_ids[] = $baseline_id;
                \WP_CLI::line( sprintf( 'Protecting baseline scan #%d', $baseline_id ) );
            }
        }

        $critical_scan_ids = $this->scanResultsRepository->getScansWithCriticalFiles();
        if ( ! empty( $critical_scan_ids ) ) {
            $protected_ids = array_merge( $protected_ids, $critical_scan_ids );
            \WP_CLI::line( sprintf( 'Protecting %d scan(s) with critical priority files', count( $critical_scan_ids ) ) );
        }

        $protected_ids = array_unique( $protected_ids );

        if ( ! empty( $protected_ids ) ) {
            \WP_CLI::line( sprintf( 'Total protected scans: %d', count( $protected_ids ) ) );
        }

        \WP_CLI::line( '' );

        // Calculate what would be deleted using repository method
        $records_to_delete = $this->fileRecordRepository->countFileRecordsForOldScans(
            days_old: $days,
            protected_ids: $protected_ids
        );

        if ( $records_to_delete < 1 ) {
            \WP_CLI::success( 'No file_records found matching criteria. Nothing to clean up.' );
            return;
        }

        // Get size estimate
        $table_stats = $this->fileRecordRepository->getTableStatistics();
        $estimated_size_mb = ( $records_to_delete * $table_stats['avg_row_size_bytes'] ) / 1024 / 1024;

        $cutoff_date = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

        \WP_CLI::line( sprintf( 'Records to delete: %s', number_format( $records_to_delete ) ) );
        \WP_CLI::line( sprintf( 'Estimated space freed: %.2f MB', $estimated_size_mb ) );
        \WP_CLI::line( sprintf( 'Cutoff date: %s', $cutoff_date ) );
        \WP_CLI::line( '' );

        if ( $dry_run ) {
            \WP_CLI::success( 'DRY RUN: No records were deleted. Remove --dry-run to perform actual cleanup.' );
            return;
        }

        // Confirm before deletion
        if ( ! $force ) {
            \WP_CLI::confirm( sprintf(
                'Are you sure you want to delete %s file_records?',
                number_format( $records_to_delete )
            ) );
        }

        // Perform cleanup
        \WP_CLI::line( 'Starting cleanup...' );

        $deleted = $this->fileRecordRepository->deleteFileRecordsForOldScans(
            days_old: $days,
            protected_ids: $protected_ids
        );

        if ( $deleted > 0 ) {
            \WP_CLI::success( sprintf(
                'Cleanup complete! Deleted %s file_records (freed approximately %.2f MB)',
                number_format( $deleted ),
                $estimated_size_mb
            ) );

            // Show updated stats
            \WP_CLI::line( '' );
            \WP_CLI::line( 'Updated table statistics:' );
            $new_stats = $this->fileRecordRepository->getTableStatistics();
            \WP_CLI::line( sprintf( 'Total Rows: %s', number_format( $new_stats['total_rows'] ) ) );
            \WP_CLI::line( sprintf( 'Total Size: %.2f MB', $new_stats['total_size_mb'] ) );
        } else {
            \WP_CLI::warning( 'No records were deleted. Check your parameters.' );
        }
    }
}