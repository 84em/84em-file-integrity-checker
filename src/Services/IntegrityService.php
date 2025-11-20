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
            // Get previous files using hybrid approach (latest + baseline)
            $previous_files = $this->getPreviousFilesForComparison();

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

            // Calculate priority statistics
            $priority_stats = $this->calculatePriorityStatistics( $compared_files );

            // Update scan result with final data
            $update_data = [
                'status' => 'completed',
                'total_files' => $stats['total_files'],
                'changed_files' => $stats['changed_files'],
                'new_files' => $stats['new_files'],
                'deleted_files' => $stats['deleted_files'],
                'scan_duration' => $scan_duration,
                'memory_usage' => $memory_usage,
                'notes' => "Scan completed successfully at " . current_time( 'mysql' ),
            ];

            // Add priority statistics if available
            if ( ! empty( $priority_stats ) ) {
                $update_data['priority_stats'] = wp_json_encode( $priority_stats );
                $update_data['critical_files_changed'] = $priority_stats['critical_changed'] ?? 0;
                $update_data['high_priority_files_changed'] = $priority_stats['high_changed'] ?? 0;
            }

            $update_success = $this->scanResultsRepository->update( $scan_id, $update_data );

            if ( ! $update_success ) {
                $this->logger->error(
                    "Failed to update scan result with final statistics for scan ID: $scan_id",
                    LoggerService::CONTEXT_SCANNER,
                    [ 'scan_id' => $scan_id ]
                );
            }

            // After scan completion, ensure baseline exists
            $this->ensureBaselineExists( $scan_id );

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
            'is_baseline' => isset( $scan_result->is_baseline ) ? (bool) $scan_result->is_baseline : false,
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
                'new_files' => $latest_scan->new_files,
                'deleted_files' => $latest_scan->deleted_files,
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
     * Save file records for a scan, filtering unchanged files after baseline
     *
     * @param int   $scan_id Scan result ID
     * @param array $files   File data array
     * @return bool Success status
     */
    private function saveFileRecords( int $scan_id, array $files ): bool {
        // Check if this is the baseline scan
        $is_baseline = $this->scanResultsRepository->getBaselineScanId() === null;

        if ( ! $is_baseline ) {
            // Filter out unchanged files for all non-baseline scans
            $original_count = count( $files );
            $files = array_filter( $files, function( $file ) {
                return $file['status'] !== 'unchanged';
            });
            $filtered_count = $original_count - count( $files );

            if ( $filtered_count > 0 ) {
                $this->logger->debug(
                    message: sprintf( 'Filtered %d unchanged files from storage (baseline optimization)', $filtered_count ),
                    context: LoggerService::CONTEXT_SCANNER,
                    data: [ 'original_count' => $original_count, 'stored_count' => count( $files ) ]
                );
            }
        } else {
            $this->logger->info(
                message: sprintf( 'Storing all %d files (baseline scan)', count( $files ) ),
                context: LoggerService::CONTEXT_SCANNER
            );
        }

        // Prepare records for batch insert
        $records = [];
        foreach ( $files as $file_data ) {
            $records[] = array_merge( $file_data, [ 'scan_result_id' => $scan_id ] );
        }

        if ( empty( $records ) ) {
            $this->logger->info(
                message: 'No file records to save',
                context: LoggerService::CONTEXT_SCANNER
            );
            return true;
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

    /**
     * Ensure a baseline scan exists, creating one if necessary
     *
     * @param int $scan_id Current scan ID
     */
    private function ensureBaselineExists( int $scan_id ): void {
        // Check if a baseline already exists
        $baseline_id = $this->scanResultsRepository->getBaselineScanId();

        if ( ! $baseline_id ) {
            // No baseline exists - mark this scan as baseline
            $this->scanResultsRepository->markAsBaseline( $scan_id );

            $this->logger->success(
                message: 'Automatically marked scan as baseline (first scan)',
                context: LoggerService::CONTEXT_SCANNER,
                data: [ 'scan_id' => $scan_id ]
            );
        }
    }

    /**
     * Get previous files for comparison using hybrid approach
     * Combines latest scan with baseline for complete file history
     *
     * @return array Previous file records
     */
    private function getPreviousFilesForComparison(): array {
        // Get the most recent checksum for each file across all scans
        // This ensures we compare against the latest known state, not baseline
        $previous_files = $this->fileRecordRepository->getLatestChecksumForAllFiles();

        if ( ! empty( $previous_files ) ) {
            $this->logger->debug(
                message: sprintf( 'Retrieved latest checksums for %d files', count( $previous_files ) ),
                context: LoggerService::CONTEXT_SCANNER
            );
        }

        return $previous_files;
    }

    /**
     * Find the latest scan that has stored file records
     *
     * @param int $exclude_scan_id Scan ID to exclude from search
     * @return object|null Scan result object or null if not found
     */
    private function findLatestScanWithRecords( int $exclude_scan_id ): ?object {
        global $wpdb;

        // Find the most recent completed scan (before the excluded one) that has file records
        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT sr.* FROM {$wpdb->prefix}eightyfourem_integrity_scan_results sr
                 WHERE sr.status = 'completed'
                   AND sr.id < %d
                   AND EXISTS (
                       SELECT 1 FROM {$wpdb->prefix}eightyfourem_integrity_file_records fr
                       WHERE fr.scan_result_id = sr.id
                   )
                 ORDER BY sr.scan_date DESC
                 LIMIT 1",
                $exclude_scan_id
            )
        );

        return $result ?: null;
    }

    /**
     * Calculate priority statistics for files
     *
     * @param array $files Array of file data
     * @return array Priority statistics
     */
    private function calculatePriorityStatistics( array $files ): array {
        $stats = [
            'critical_total'   => 0,
            'critical_changed' => 0,
            'critical_new'     => 0,
            'high_total'       => 0,
            'high_changed'     => 0,
            'high_new'         => 0,
            'normal_total'     => 0,
            'normal_changed'   => 0,
            'normal_new'       => 0,
        ];

        foreach ( $files as $file ) {
            $priority = $file['priority_level'] ?? null;
            $status = $file['status'] ?? 'unchanged';

            if ( ! $priority ) {
                continue;
            }

            // Count totals by priority
            switch ( $priority ) {
                case 'critical':
                    $stats['critical_total']++;
                    if ( $status === 'changed' ) {
                        $stats['critical_changed']++;
                    } elseif ( $status === 'new' ) {
                        $stats['critical_new']++;
                    }
                    break;

                case 'high':
                    $stats['high_total']++;
                    if ( $status === 'changed' ) {
                        $stats['high_changed']++;
                    } elseif ( $status === 'new' ) {
                        $stats['high_new']++;
                    }
                    break;

                case 'normal':
                    $stats['normal_total']++;
                    if ( $status === 'changed' ) {
                        $stats['normal_changed']++;
                    } elseif ( $status === 'new' ) {
                        $stats['normal_new']++;
                    }
                    break;
            }
        }

        return $stats;
    }
}