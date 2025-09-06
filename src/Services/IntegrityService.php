<?php
/**
 * Integrity Service
 *
 * @package EightyFourEM\FileIntegrityChecker\Services
 */

namespace EightyFourEM\FileIntegrityChecker\Services;

use EightyFourEM\FileIntegrityChecker\Scanner\FileScanner;
use EightyFourEM\FileIntegrityChecker\Database\ScanResultsRepository;
use EightyFourEM\FileIntegrityChecker\Database\FileRecordRepository;
use EightyFourEM\FileIntegrityChecker\Utils\Security;

/**
 * Main service for file integrity operations
 */
class IntegrityService {
    /**
     * File scanner
     *
     * @var FileScanner
     */
    private FileScanner $fileScanner;

    /**
     * Scan results repository
     *
     * @var ScanResultsRepository
     */
    private ScanResultsRepository $scanResultsRepository;

    /**
     * File record repository
     *
     * @var FileRecordRepository
     */
    private FileRecordRepository $fileRecordRepository;

    /**
     * Settings service
     *
     * @var SettingsService
     */
    private SettingsService $settingsService;

    /**
     * Logger service
     *
     * @var LoggerService
     */
    private LoggerService $logger;

    /**
     * Notification service
     *
     * @var NotificationService
     */
    private NotificationService $notificationService;

    /**
     * Constructor
     *
     * @param FileScanner           $fileScanner           File scanner
     * @param ScanResultsRepository $scanResultsRepository Scan results repository
     * @param FileRecordRepository  $fileRecordRepository  File record repository
     * @param SettingsService       $settingsService       Settings service
     * @param LoggerService         $logger                Logger service
     * @param NotificationService   $notificationService   Notification service
     */
    public function __construct(
        FileScanner $fileScanner,
        ScanResultsRepository $scanResultsRepository,
        FileRecordRepository $fileRecordRepository,
        SettingsService $settingsService,
        LoggerService $logger,
        NotificationService $notificationService
    ) {
        $this->fileScanner           = $fileScanner;
        $this->scanResultsRepository = $scanResultsRepository;
        $this->fileRecordRepository  = $fileRecordRepository;
        $this->settingsService       = $settingsService;
        $this->logger                = $logger;
        $this->notificationService   = $notificationService;
    }

    /**
     * Run a complete file integrity scan
     *
     * @param string        $scan_type         Scan type ('manual' or 'scheduled')
     * @param callable|null $progress_callback Progress callback function
     * @return array|false Scan result array or false on failure
     */
    public function runScan( string $scan_type = 'manual', ?callable $progress_callback = null, ?int $schedule_id = null ): array|false {
        $start_time = time();
        $start_memory = memory_get_usage();

        // Create initial scan result record
        $scan_data = [
            'scan_date' => current_time( 'mysql' ),
            'status' => 'running',
            'scan_type' => $scan_type,
            'notes' => "Scan started at " . current_time( 'mysql' ),
        ];
        
        // Add schedule_id if provided
        if ( $schedule_id !== null ) {
            $scan_data['schedule_id'] = $schedule_id;
        }
        
        $scan_id = $this->scanResultsRepository->create( $scan_data );

        if ( ! $scan_id ) {
            $this->logger->error(
                'Failed to create scan result record',
                LoggerService::CONTEXT_SCANNER,
                [ 'scan_type' => $scan_type ]
            );
            return false;
        }
        
        $this->logger->info(
            "Starting $scan_type scan (ID: $scan_id)",
            LoggerService::CONTEXT_SCANNER,
            [ 'scan_id' => $scan_id, 'scan_type' => $scan_type ]
        );

        try {
            // Get the latest completed scan for comparison
            $latest_scan = $this->scanResultsRepository->getLatestCompleted();
            $previous_files = [];
            
            if ( $latest_scan ) {
                $previous_files = $this->fileRecordRepository->getByScanId( $latest_scan->id );
            }

            // Progress callback for directory scanning
            $scan_progress_callback = null;
            if ( $progress_callback ) {
                $scan_progress_callback = function ( $count, $current_file ) use ( $progress_callback ) {
                    call_user_func( $progress_callback, "Scanning files: $count processed", $current_file );
                };
            }

            // Scan the filesystem
            $current_files = $this->fileScanner->scanDirectory( ABSPATH, $scan_progress_callback );

            if ( $progress_callback ) {
                call_user_func( $progress_callback, "Comparing with previous scan...", '' );
            }

            // Compare with previous scan to detect changes
            $compared_files = $this->fileScanner->compareScans( $current_files, $previous_files );

            if ( $progress_callback ) {
                call_user_func( $progress_callback, "Saving scan results to database...", '' );
            }

            // Save file records to database
            $this->saveFileRecords( $scan_id, $compared_files );

            // Calculate final statistics
            $stats = $this->fileScanner->getStatistics( $compared_files );
            $scan_duration = time() - $start_time;
            $memory_usage = memory_get_peak_usage() - $start_memory;

            // Update scan result with final data
            $update_success = $this->scanResultsRepository->update( $scan_id, [
                'status' => 'completed',
                'total_files' => $stats['total_files'],
                'changed_files' => $stats['changed_files'],
                'new_files' => $stats['new_files'],
                'deleted_files' => $stats['deleted_files'],
                'scan_duration' => $scan_duration,
                'memory_usage' => $memory_usage,
                'notes' => "Scan completed successfully at " . current_time( 'mysql' ),
            ] );

            if ( ! $update_success ) {
                $this->logger->error( 
                    "Failed to update scan result with final statistics for scan ID: $scan_id",
                    LoggerService::CONTEXT_SCANNER,
                    [ 'scan_id' => $scan_id ]
                );
            }

            if ( $progress_callback ) {
                call_user_func( $progress_callback, "Scan completed successfully!", '' );
            }

            // Send notifications if changes were detected
            if ( ( $stats['changed_files'] > 0 || $stats['new_files'] > 0 || $stats['deleted_files'] > 0 ) ) {
                $this->notificationService->sendScanNotification( $scan_id );
            }
            
            // Log successful completion
            $this->logger->success(
                sprintf(
                    'Scan completed successfully - Total: %d, Changed: %d, New: %d, Deleted: %d',
                    $stats['total_files'],
                    $stats['changed_files'],
                    $stats['new_files'],
                    $stats['deleted_files']
                ),
                LoggerService::CONTEXT_SCANNER,
                [
                    'scan_id' => $scan_id,
                    'scan_type' => $scan_type,
                    'duration' => $scan_duration,
                    'memory_usage' => $memory_usage,
                    'stats' => $stats,
                ]
            );

            return [
                'scan_id' => $scan_id,
                'status' => 'completed',
                'duration' => $scan_duration,
                'memory_usage' => $memory_usage,
                'total_files' => $stats['total_files'],
                'changed_files' => $stats['changed_files'],
                'new_files' => $stats['new_files'],
                'deleted_files' => $stats['deleted_files'],
                'unchanged_files' => $stats['unchanged_files'],
            ];

        } catch ( \Exception $e ) {
            // Update scan result with error
            // Log detailed error for debugging
            $this->logger->error(
                "File integrity scan failed: " . $e->getMessage(),
                LoggerService::CONTEXT_SCANNER,
                [
                    'scan_id' => $scan_id,
                    'exception' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );
            
            // Store sanitized error message
            $sanitized_message = Security::sanitize_error_message( $e->getMessage() );
            
            $this->scanResultsRepository->update( $scan_id, [
                'status' => 'failed',
                'notes' => $sanitized_message,
            ] );

            if ( $progress_callback ) {
                call_user_func( $progress_callback, $sanitized_message, '' );
            }

            return false;
        }
    }

    /**
     * Get scan summary for display
     *
     * @param int $scan_id Scan result ID
     * @return array|null Scan summary or null if not found
     */
    public function getScanSummary( int $scan_id ): ?array {
        $scan_result = $this->scanResultsRepository->getById( $scan_id );
        
        if ( ! $scan_result ) {
            return null;
        }

        $file_stats = $this->fileRecordRepository->getStatistics( $scan_id );

        return [
            'scan_id' => $scan_result->id,
            'scan_date' => $scan_result->scan_date,
            'status' => $scan_result->status,
            'scan_type' => $scan_result->scan_type,
            'duration' => $scan_result->scan_duration,
            'memory_usage' => $scan_result->memory_usage,
            'total_files' => $file_stats['total_files'],
            'changed_files' => $file_stats['changed_files'],
            'new_files' => $file_stats['new_files'],
            'deleted_files' => $file_stats['deleted_files'],
            'total_size' => $file_stats['total_size'],
            'notes' => $scan_result->notes,
        ];
    }

    /**
     * Get dashboard statistics
     *
     * @return array Dashboard statistics
     */
    public function getDashboardStats(): array {
        $latest_scan = $this->scanResultsRepository->getLatestCompleted();
        $repository_stats = $this->scanResultsRepository->getStatistics();

        return [
            'latest_scan' => $latest_scan ? [
                'id' => $latest_scan->id,
                'date' => $latest_scan->scan_date,
                'total_files' => $latest_scan->total_files,
                'changed_files' => $latest_scan->changed_files,
                'status' => $latest_scan->status,
            ] : null,
            'total_scans' => $repository_stats['total_scans'],
            'completed_scans' => $repository_stats['completed_scans'],
            'failed_scans' => $repository_stats['failed_scans'],
            'avg_scan_duration' => $repository_stats['avg_scan_duration'],
            'total_changed_files' => $repository_stats['total_changed_files'],
        ];
    }


    /**
     * Clean up old scan data
     *
     * @return array Cleanup statistics
     */
    public function cleanupOldScans(): array {
        $retention_period = $this->settingsService->getRetentionPeriod();
        $deleted_scans = $this->scanResultsRepository->deleteOld( $retention_period );

        return [
            'deleted_scans' => $deleted_scans,
            'retention_period' => $retention_period,
        ];
    }

    /**
     * Save file records to database
     *
     * @param int   $scan_id Scan result ID
     * @param array $files   Array of file data
     * @return bool True on success, false on failure
     */
    private function saveFileRecords( int $scan_id, array $files ): bool {
        // Prepare records for batch insert
        $records = [];
        foreach ( $files as $file_data ) {
            $records[] = array_merge( $file_data, [ 'scan_result_id' => $scan_id ] );
        }

        // Save in batches to avoid memory issues
        $batch_size = 1000;
        $batches = array_chunk( $records, $batch_size );

        foreach ( $batches as $batch ) {
            if ( ! $this->fileRecordRepository->createBatch( $batch ) ) {
                return false;
            }
        }

        return true;
    }
}