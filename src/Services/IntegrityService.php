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
     * Constructor
     *
     * @param FileScanner           $fileScanner           File scanner
     * @param ScanResultsRepository $scanResultsRepository Scan results repository
     * @param FileRecordRepository  $fileRecordRepository  File record repository
     * @param SettingsService       $settingsService       Settings service
     */
    public function __construct(
        FileScanner $fileScanner,
        ScanResultsRepository $scanResultsRepository,
        FileRecordRepository $fileRecordRepository,
        SettingsService $settingsService
    ) {
        $this->fileScanner           = $fileScanner;
        $this->scanResultsRepository = $scanResultsRepository;
        $this->fileRecordRepository  = $fileRecordRepository;
        $this->settingsService       = $settingsService;
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
            return false;
        }

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
                error_log( "Failed to update scan result with final statistics for scan ID: $scan_id" );
            }

            if ( $progress_callback ) {
                call_user_func( $progress_callback, "Scan completed successfully!", '' );
            }

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
            $this->scanResultsRepository->update( $scan_id, [
                'status' => 'failed',
                'notes' => 'Scan failed: ' . $e->getMessage(),
            ] );

            error_log( "File integrity scan failed: " . $e->getMessage() );

            if ( $progress_callback ) {
                call_user_func( $progress_callback, "Scan failed: " . $e->getMessage(), '' );
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
     * Send change notification email
     *
     * @param int $scan_id Scan result ID
     * @return bool True if email sent successfully, false otherwise
     */
    public function sendChangeNotification( int $scan_id ): bool {
        if ( ! $this->settingsService->isNotificationEnabled() ) {
            return false;
        }

        $scan_summary = $this->getScanSummary( $scan_id );
        if ( ! $scan_summary ) {
            return false;
        }

        $changed_files = $this->fileRecordRepository->getChangedFiles( $scan_id );
        
        $email_to = $this->settingsService->getNotificationEmail();
        $subject = sprintf( 
            '[%s] File Integrity Scan - Changes Detected', 
            get_bloginfo( 'name' ) 
        );

        $message = $this->buildNotificationMessage( $scan_summary, $changed_files );

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: File Integrity Checker <' . get_option( 'admin_email' ) . '>',
        ];

        return wp_mail( $email_to, $subject, $message, $headers );
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

    /**
     * Build notification email message
     *
     * @param array $scan_summary Scan summary
     * @param array $changed_files Changed files
     * @return string Email message HTML
     */
    private function buildNotificationMessage( array $scan_summary, array $changed_files ): string {
        $site_name = get_bloginfo( 'name' );
        $site_url = get_home_url();
        
        $message = "<html><body>";
        $message .= "<h2>File Integrity Scan Results</h2>";
        $message .= "<p><strong>Site:</strong> <a href=\"$site_url\">$site_name</a></p>";
        $message .= "<p><strong>Scan Date:</strong> " . $scan_summary['scan_date'] . "</p>";
        $message .= "<p><strong>Status:</strong> " . ucfirst( $scan_summary['status'] ) . "</p>";
        
        $message .= "<h3>Summary</h3>";
        $message .= "<ul>";
        $message .= "<li><strong>Total Files:</strong> " . number_format( $scan_summary['total_files'] ) . "</li>";
        $message .= "<li><strong>Changed Files:</strong> " . number_format( $scan_summary['changed_files'] ) . "</li>";
        $message .= "<li><strong>New Files:</strong> " . number_format( $scan_summary['new_files'] ) . "</li>";
        $message .= "<li><strong>Deleted Files:</strong> " . number_format( $scan_summary['deleted_files'] ) . "</li>";
        $message .= "<li><strong>Scan Duration:</strong> " . $scan_summary['duration'] . " seconds</li>";
        $message .= "</ul>";

        if ( ! empty( $changed_files ) ) {
            $message .= "<h3>Changed Files</h3>";
            $message .= "<table border='1' cellpadding='5' cellspacing='0'>";
            $message .= "<tr><th>File Path</th><th>Status</th><th>Size</th></tr>";
            
            foreach ( array_slice( $changed_files, 0, 50 ) as $file ) { // Limit to first 50 files
                $status_color = match( $file->status ) {
                    'new' => 'green',
                    'changed' => 'orange',
                    'deleted' => 'red',
                    default => 'black'
                };
                
                $message .= "<tr>";
                $message .= "<td>" . esc_html( $file->file_path ) . "</td>";
                $message .= "<td style='color: $status_color'>" . ucfirst( $file->status ) . "</td>";
                $message .= "<td>" . size_format( $file->file_size ) . "</td>";
                $message .= "</tr>";
            }
            
            $message .= "</table>";
            
            if ( count( $changed_files ) > 50 ) {
                $message .= "<p><em>... and " . ( count( $changed_files ) - 50 ) . " more files. Check the admin panel for full details.</em></p>";
            }
        }

        $admin_url = admin_url( 'admin.php?page=file-integrity-checker-results&scan_id=' . $scan_summary['scan_id'] );
        $message .= "<p><a href=\"$admin_url\">View Full Scan Results</a></p>";
        
        $message .= "</body></html>";

        return $message;
    }
}