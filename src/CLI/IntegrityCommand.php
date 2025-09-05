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
     * Constructor
     *
     * @param IntegrityService      $integrityService      Integrity service
     * @param SettingsService       $settingsService       Settings service
     * @param SchedulerService      $schedulerService      Scheduler service
     * @param ScanResultsRepository $scanResultsRepository Scan results repository
     */
    public function __construct(
        IntegrityService $integrityService,
        SettingsService $settingsService,
        SchedulerService $schedulerService,
        ScanResultsRepository $scanResultsRepository
    ) {
        $this->integrityService      = $integrityService;
        $this->settingsService       = $settingsService;
        $this->schedulerService      = $schedulerService;
        $this->scanResultsRepository = $scanResultsRepository;
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
            case 'auto_schedule':
                $result = $this->settingsService->setAutoScheduleEnabled( $value === 'true' );
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
}