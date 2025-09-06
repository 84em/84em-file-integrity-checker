<?php
/**
 * Admin Pages
 *
 * @package EightyFourEM\FileIntegrityChecker\Admin
 */

namespace EightyFourEM\FileIntegrityChecker\Admin;

use EightyFourEM\FileIntegrityChecker\Services\IntegrityService;
use EightyFourEM\FileIntegrityChecker\Services\SettingsService;
use EightyFourEM\FileIntegrityChecker\Services\SchedulerService;
use EightyFourEM\FileIntegrityChecker\Services\FileViewerService;
use EightyFourEM\FileIntegrityChecker\Database\ScanResultsRepository;

/**
 * Manages admin pages for the plugin
 */
class AdminPages {
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
     * File viewer service
     *
     * @var FileViewerService
     */
    private FileViewerService $fileViewerService;

    /**
     * Constructor
     *
     * @param IntegrityService      $integrityService      Integrity service
     * @param SettingsService       $settingsService       Settings service
     * @param SchedulerService      $schedulerService      Scheduler service
     * @param ScanResultsRepository $scanResultsRepository Scan results repository
     * @param FileViewerService     $fileViewerService     File viewer service
     */
    public function __construct(
        IntegrityService $integrityService,
        SettingsService $settingsService,
        SchedulerService $schedulerService,
        ScanResultsRepository $scanResultsRepository,
        FileViewerService $fileViewerService
    ) {
        $this->integrityService = $integrityService;
        $this->settingsService  = $settingsService;
        $this->schedulerService = $schedulerService;
        $this->scanResultsRepository = $scanResultsRepository;
        $this->fileViewerService = $fileViewerService;
    }

    /**
     * Initialize admin pages
     */
    public function init(): void {
        add_action( 'admin_menu', [ $this, 'addAdminPages' ] );
        add_action( 'admin_init', [ $this, 'handleActions' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueueAssets' ] );
        
        // Register AJAX handlers
        add_action( 'wp_ajax_file_integrity_start_scan', [ $this, 'ajaxStartScan' ] );
        add_action( 'wp_ajax_file_integrity_check_progress', [ $this, 'ajaxCheckProgress' ] );
        add_action( 'wp_ajax_file_integrity_cleanup_old_scans', [ $this, 'ajaxCleanupOldScans' ] );
        add_action( 'wp_ajax_file_integrity_cancel_scan', [ $this, 'ajaxCancelScan' ] );
        add_action( 'wp_ajax_file_integrity_delete_scan', [ $this, 'ajaxDeleteScan' ] );
        add_action( 'wp_ajax_file_integrity_test_slack', [ $this, 'ajaxTestSlack' ] );
        add_action( 'wp_ajax_file_integrity_bulk_delete_scans', [ $this, 'ajaxBulkDeleteScans' ] );
        add_action( 'wp_ajax_file_integrity_resend_email', [ $this, 'ajaxResendEmailNotification' ] );
        add_action( 'wp_ajax_file_integrity_resend_slack', [ $this, 'ajaxResendSlackNotification' ] );
        add_action( 'wp_ajax_file_integrity_view_file', [ $this, 'ajaxViewFile' ] );
    }

    /**
     * Add admin pages to WordPress admin menu
     */
    public function addAdminPages(): void {
        // Main page
        add_menu_page(
            '84EM File Integrity Checker',
            'File Integrity',
            'manage_options',
            'file-integrity-checker',
            [ $this, 'renderDashboardPage' ],
            'dashicons-shield-alt',
            75
        );

        // Sub-pages
        add_submenu_page(
            'file-integrity-checker',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'file-integrity-checker',
            [ $this, 'renderDashboardPage' ]
        );

        add_submenu_page(
            'file-integrity-checker',
            'Scan Results',
            'Scan Results',
            'manage_options',
            'file-integrity-checker-results',
            [ $this, 'renderResultsPage' ]
        );

        add_submenu_page(
            'file-integrity-checker',
            'Schedules',
            'Schedules',
            'manage_options',
            'file-integrity-checker-schedules',
            [ $this, 'renderSchedulesPage' ]
        );

        add_submenu_page(
            'file-integrity-checker',
            'Settings',
            'Settings',
            'manage_options',
            'file-integrity-checker-settings',
            [ $this, 'renderSettingsPage' ]
        );
    }

    /**
     * Handle admin actions
     */
    public function handleActions(): void {
        if ( ! isset( $_POST['action'] ) || ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'file_integrity_action' ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You do not have sufficient permissions to perform this action.' );
        }

        $action = sanitize_text_field( $_POST['action'] );

        switch ( $action ) {
            case 'run_scan':
                $this->handleRunScan();
                break;
            // Deprecated: Use the new Schedules page instead
            // case 'schedule_scan':
            //     $this->handleScheduleScan();
            //     break;
            // case 'cancel_scheduled_scans':
            //     $this->handleCancelScheduledScans();
            //     break;
            case 'update_settings':
                $this->handleUpdateSettings();
                break;
            case 'cleanup_old_scans':
                $this->handleCleanupOldScans();
                break;
        }
    }

    /**
     * Enqueue admin assets
     *
     * @param string $hook Current admin page hook
     */
    public function enqueueAssets( string $hook ): void {
        // Only enqueue on our plugin pages
        if ( strpos( $hook, 'file-integrity-checker' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'file-integrity-checker-admin',
            EIGHTYFOUREM_FILE_INTEGRITY_CHECKER_URL . 'assets/css/admin.css',
            [],
            EIGHTYFOUREM_FILE_INTEGRITY_CHECKER_VERSION
        );

        // Enqueue modal system first
        wp_enqueue_script(
            'file-integrity-checker-modal',
            EIGHTYFOUREM_FILE_INTEGRITY_CHECKER_URL . 'assets/js/modal.js',
            [],
            EIGHTYFOUREM_FILE_INTEGRITY_CHECKER_VERSION,
            true
        );

        wp_enqueue_script(
            'file-integrity-checker-admin',
            EIGHTYFOUREM_FILE_INTEGRITY_CHECKER_URL . 'assets/js/admin.js',
            [ 'jquery', 'file-integrity-checker-modal' ],
            EIGHTYFOUREM_FILE_INTEGRITY_CHECKER_VERSION,
            true
        );

        // Localize script with AJAX data
        wp_localize_script( 'file-integrity-checker-admin', 'fileIntegrityChecker', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'file_integrity_ajax' ),
        ] );
    }

    /**
     * Render dashboard page
     */
    public function renderDashboardPage(): void {
        $stats = $this->integrityService->getDashboardStats();
        $next_scan = $this->schedulerService->getNextScheduledScan();
        $scheduler_available = $this->schedulerService->isAvailable();
        $scheduler_service = $this->schedulerService;

        include EIGHTYFOUREM_FILE_INTEGRITY_CHECKER_PATH . 'views/admin/dashboard.php';
    }

    /**
     * Render results page
     */
    public function renderResultsPage(): void {
        $page = (int) ( $_GET['paged'] ?? 1 );
        $per_page = 20;
        
        if ( isset( $_GET['scan_id'] ) ) {
            // Show individual scan details
            $scan_id = (int) $_GET['scan_id'];
            $scan_summary = $this->integrityService->getScanSummary( $scan_id );
            
            if ( $scan_summary ) {
                include EIGHTYFOUREM_FILE_INTEGRITY_CHECKER_PATH . 'views/admin/scan-details.php';
                return;
            }
        }

        // Show list of all scans
        $results = $this->scanResultsRepository->getPaginated( $page, $per_page );
        
        include EIGHTYFOUREM_FILE_INTEGRITY_CHECKER_PATH . 'views/admin/results.php';
    }

    /**
     * Render settings page
     */
    public function renderSettingsPage(): void {
        $settings = $this->settingsService->getAllSettings();
        $scheduler_available = $this->schedulerService->isAvailable();

        include EIGHTYFOUREM_FILE_INTEGRITY_CHECKER_PATH . 'views/admin/settings.php';
    }

    /**
     * Handle run scan action
     */
    private function handleRunScan(): void {
        // Use AJAX for long-running scans to avoid timeouts
        wp_redirect( admin_url( 'admin.php?page=file-integrity-checker&action=start_scan' ) );
        exit;
    }

    /**
     * Handle schedule scan action
     */
    private function handleScheduleScan(): void {
        $interval = sanitize_text_field( $_POST['scan_interval'] ?? 'daily' );
        
        if ( $this->schedulerService->scheduleRecurringScan( $interval ) ) {
            $this->settingsService->setScanInterval( $interval );
            wp_redirect( admin_url( 'admin.php?page=file-integrity-checker&message=scan_scheduled' ) );
        } else {
            wp_redirect( admin_url( 'admin.php?page=file-integrity-checker&error=schedule_failed' ) );
        }
        exit;
    }

    /**
     * Handle cancel scheduled scans action
     */
    private function handleCancelScheduledScans(): void {
        $cancelled = $this->schedulerService->cancelAllScans();
        
        wp_redirect( admin_url( "admin.php?page=file-integrity-checker&message=scans_cancelled&count=$cancelled" ) );
        exit;
    }

    /**
     * Handle update settings action
     */
    private function handleUpdateSettings(): void {
        $settings = [];

        // Scan file types - if empty, use empty array to clear all selections
        $settings['scan_types'] = isset( $_POST['scan_types'] ) 
            ? array_map( 'sanitize_text_field', $_POST['scan_types'] )
            : [];

        // Exclude patterns
        if ( isset( $_POST['exclude_patterns'] ) ) {
            $patterns = explode( "\n", sanitize_textarea_field( $_POST['exclude_patterns'] ) );
            $settings['exclude_patterns'] = array_map( 'trim', $patterns );
        }

        // Max file size - convert from MB to bytes
        if ( isset( $_POST['max_file_size_mb'] ) ) {
            $mb_value = (float) $_POST['max_file_size_mb'];
            $settings['max_file_size'] = (int) ( $mb_value * 1048576 ); // Convert MB to bytes
        }

        // Notification settings
        $settings['notification_enabled'] = isset( $_POST['notification_enabled'] );
        
        if ( isset( $_POST['notification_email'] ) && ! empty( $_POST['notification_email'] ) ) {
            $settings['notification_email'] = sanitize_email( $_POST['notification_email'] );
        }
        
        // Slack settings
        $settings['slack_enabled'] = isset( $_POST['slack_enabled'] );
        
        if ( isset( $_POST['slack_webhook_url'] ) ) {
            $settings['slack_webhook_url'] = sanitize_url( $_POST['slack_webhook_url'] );
        }

        // Retention period
        if ( isset( $_POST['retention_period'] ) ) {
            $settings['retention_period'] = (int) $_POST['retention_period'];
        }

        // Content retention limit
        if ( isset( $_POST['content_retention_limit'] ) ) {
            $settings['content_retention_limit'] = (int) $_POST['content_retention_limit'];
        }

        // Auto schedule
        $settings['auto_schedule'] = isset( $_POST['auto_schedule'] );

        $results = $this->settingsService->updateSettings( $settings );
        
        // Check for any failures
        $has_failures = false;
        $failed_settings = [];
        foreach ( $results as $key => $success ) {
            if ( ! $success ) {
                $has_failures = true;
                $failed_settings[] = $key;
            }
        }
        
        // Log failures for debugging
        if ( $has_failures && WP_DEBUG ) {
            error_log( 'File Integrity Checker: Failed to update settings: ' . implode( ', ', $failed_settings ) );
        }
        
        // Only show error if critical settings failed
        $critical_failures = array_intersect( $failed_settings, [ 'scan_types', 'max_file_size', 'retention_period' ] );
        if ( ! empty( $critical_failures ) ) {
            wp_redirect( admin_url( 'admin.php?page=file-integrity-checker-settings&error=settings_update_failed' ) );
        } else {
            wp_redirect( admin_url( 'admin.php?page=file-integrity-checker-settings&message=settings_updated' ) );
        }
        exit;
    }

    /**
     * Handle cleanup old scans action
     */
    private function handleCleanupOldScans(): void {
        $cleanup_stats = $this->integrityService->cleanupOldScans();
        
        wp_redirect( admin_url( 
            'admin.php?page=file-integrity-checker&message=cleanup_complete&deleted=' . $cleanup_stats['deleted_scans'] 
        ) );
        exit;
    }

    /**
     * AJAX handler for cleanup old scans
     */
    public function ajaxCleanupOldScans(): void {
        // Check nonce
        if ( ! check_ajax_referer( 'file_integrity_ajax', '_wpnonce', false ) ) {
            wp_send_json_error( 'Invalid security token' );
        }

        // Check permissions
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }

        try {
            $cleanup_stats = $this->integrityService->cleanupOldScans();
            
            wp_send_json_success( [
                'deleted_scans' => $cleanup_stats['deleted_scans'],
                'retention_period' => $cleanup_stats['retention_period']
            ] );
        } catch ( \Exception $e ) {
            wp_send_json_error( 'Failed to cleanup old scans: ' . $e->getMessage() );
        }
    }

    /**
     * AJAX handler for canceling a scan
     */
    public function ajaxCancelScan(): void {
        // Check nonce
        if ( ! check_ajax_referer( 'file_integrity_ajax', '_wpnonce', false ) ) {
            wp_send_json_error( 'Invalid security token' );
        }
        
        // Check permissions
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        
        $scan_id = isset( $_POST['scan_id'] ) ? intval( $_POST['scan_id'] ) : 0;
        
        if ( ! $scan_id ) {
            wp_send_json_error( 'Invalid scan ID' );
        }
        
        try {
            // Update scan status to cancelled
            $updated = $this->scanResultsRepository->update( $scan_id, [
                'status' => 'cancelled',
                'notes' => 'Scan cancelled by user at ' . current_time( 'mysql' )
            ] );
            
            if ( $updated ) {
                wp_send_json_success( [
                    'message' => 'Scan cancelled successfully',
                    'scan_id' => $scan_id
                ] );
            } else {
                wp_send_json_error( 'Failed to cancel scan' );
            }
        } catch ( \Exception $e ) {
            wp_send_json_error( 'Failed to cancel scan: ' . $e->getMessage() );
        }
    }
    
    /**
     * AJAX handler for deleting a scan
     */
    public function ajaxDeleteScan(): void {
        // Check nonce
        if ( ! check_ajax_referer( 'file_integrity_ajax', '_wpnonce', false ) ) {
            wp_send_json_error( 'Invalid security token' );
        }
        
        // Check permissions
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        
        $scan_id = isset( $_POST['scan_id'] ) ? intval( $_POST['scan_id'] ) : 0;
        
        if ( ! $scan_id ) {
            wp_send_json_error( 'Invalid scan ID' );
        }
        
        try {
            // Delete scan and all associated file records
            $deleted = $this->scanResultsRepository->delete( $scan_id );
            
            if ( $deleted ) {
                wp_send_json_success( [
                    'message' => 'Scan deleted successfully',
                    'scan_id' => $scan_id
                ] );
            } else {
                wp_send_json_error( 'Failed to delete scan' );
            }
        } catch ( \Exception $e ) {
            wp_send_json_error( 'Failed to delete scan: ' . $e->getMessage() );
        }
    }
    
    /**
     * AJAX handler for testing Slack webhook
     */
    public function ajaxTestSlack(): void {
        // Check nonce
        if ( ! check_ajax_referer( 'file_integrity_ajax', '_wpnonce', false ) ) {
            wp_send_json_error( 'Invalid security token' );
        }
        
        // Check permissions
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        
        $webhook_url = isset( $_POST['webhook_url'] ) ? sanitize_url( $_POST['webhook_url'] ) : '';
        
        if ( empty( $webhook_url ) || strpos( $webhook_url, 'https://hooks.slack.com/' ) !== 0 ) {
            wp_send_json_error( 'Invalid Slack webhook URL' );
        }
        
        // Send test message
        $message = [
            'text' => '🔍 File Integrity Checker Test',
            'blocks' => [
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => "*Test Notification*\n\nThis is a test message from the 84EM File Integrity Checker plugin on " . get_bloginfo( 'name' ) . "."
                    ]
                ],
                [
                    'type' => 'context',
                    'elements' => [
                        [
                            'type' => 'mrkdwn',
                            'text' => 'Site: ' . site_url() . ' | Time: ' . current_time( 'mysql' )
                        ]
                    ]
                ]
            ]
        ];
        
        $response = wp_remote_post( $webhook_url, [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode( $message ),
            'timeout' => 15,
        ] );
        
        if ( is_wp_error( $response ) ) {
            wp_send_json_error( 'Failed to send message: ' . $response->get_error_message() );
        }
        
        $response_code = wp_remote_retrieve_response_code( $response );
        if ( $response_code !== 200 ) {
            wp_send_json_error( 'Slack returned error code: ' . $response_code );
        }
        
        wp_send_json_success( 'Test message sent successfully' );
    }
    
    /**
     * AJAX handler for bulk deleting scans
     */
    public function ajaxBulkDeleteScans(): void {
        // Check nonce
        if ( ! check_ajax_referer( 'file_integrity_ajax', '_wpnonce', false ) ) {
            wp_send_json_error( 'Invalid security token' );
        }
        
        // Check permissions
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        
        $scan_ids = isset( $_POST['scan_ids'] ) ? array_map( 'intval', $_POST['scan_ids'] ) : [];
        
        if ( empty( $scan_ids ) ) {
            wp_send_json_error( 'No scan IDs provided' );
        }
        
        $deleted_count = 0;
        $failed_count = 0;
        
        foreach ( $scan_ids as $scan_id ) {
            if ( $scan_id > 0 ) {
                try {
                    $deleted = $this->scanResultsRepository->delete( $scan_id );
                    if ( $deleted ) {
                        $deleted_count++;
                    } else {
                        $failed_count++;
                    }
                } catch ( \Exception $e ) {
                    $failed_count++;
                    error_log( 'Failed to delete scan ' . $scan_id . ': ' . $e->getMessage() );
                }
            }
        }
        
        if ( $deleted_count > 0 ) {
            $message = sprintf( 
                'Successfully deleted %d scan%s', 
                $deleted_count, 
                $deleted_count === 1 ? '' : 's'
            );
            
            if ( $failed_count > 0 ) {
                $message .= sprintf( ' (%d failed)', $failed_count );
            }
            
            wp_send_json_success( [
                'message' => $message,
                'deleted' => $deleted_count,
                'failed' => $failed_count
            ] );
        } else {
            wp_send_json_error( 'Failed to delete any scans' );
        }
    }
    
    /**
     * Render schedules page
     */
    public function renderSchedulesPage(): void {
        $scheduler_service = $this->schedulerService;
        include plugin_dir_path( dirname( __DIR__ ) ) . 'views/admin/schedules.php';
    }

    /**
     * AJAX handler for starting a scan
     */
    public function ajaxStartScan(): void {
        // Check nonce
        if ( ! check_ajax_referer( 'file_integrity_ajax', '_wpnonce', false ) ) {
            wp_send_json_error( 'Invalid security token' );
        }
        
        // Check permissions
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        
        try {
            // Start the scan
            $scan_result = $this->integrityService->runScan( 'manual' );
            
            if ( $scan_result && isset( $scan_result['scan_id'] ) ) {
                wp_send_json_success( [
                    'scan_id' => $scan_result['scan_id'],
                    'message' => 'Scan started successfully'
                ] );
            } else {
                wp_send_json_error( 'Failed to start scan' );
            }
        } catch ( \Exception $e ) {
            wp_send_json_error( 'Error: ' . $e->getMessage() );
        }
    }

    /**
     * AJAX handler for checking scan progress
     */
    public function ajaxCheckProgress(): void {
        // Check nonce
        if ( ! check_ajax_referer( 'file_integrity_ajax', '_wpnonce', false ) ) {
            wp_send_json_error( 'Invalid security token' );
        }
        
        // Check permissions
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        
        $scan_id = isset( $_POST['scan_id'] ) ? intval( $_POST['scan_id'] ) : 0;
        
        if ( ! $scan_id ) {
            wp_send_json_error( 'Invalid scan ID' );
        }
        
        try {
            // Get scan progress
            $scan = $this->scanResultsRepository->getById( $scan_id );
            
            if ( ! $scan ) {
                wp_send_json_error( 'Scan not found' );
            }
            
            $response = [
                'status' => $scan->status,
                'scan_id' => $scan_id,
                'progress' => 100, // Since scans run synchronously, they're either complete or failed
                'message' => ''
            ];
            
            if ( $scan->status === 'completed' ) {
                $response['message'] = 'Scan completed successfully';
                $response['stats'] = [
                    'total_files' => $scan->total_files,
                    'changed_files' => $scan->changed_files,
                    'new_files' => $scan->new_files,
                    'deleted_files' => $scan->deleted_files
                ];
            } elseif ( $scan->status === 'failed' ) {
                $response['message'] = 'Scan failed: ' . ( $scan->notes ?: 'Unknown error' );
            } else {
                $response['message'] = 'Scan in progress...';
                $response['progress'] = 50; // Approximate progress
            }
            
            wp_send_json_success( $response );
            
        } catch ( \Exception $e ) {
            wp_send_json_error( 'Error: ' . $e->getMessage() );
        }
    }

    /**
     * AJAX handler for resending email notification
     */
    public function ajaxResendEmailNotification(): void {
        // Check nonce
        if ( ! check_ajax_referer( 'file_integrity_ajax', '_wpnonce', false ) ) {
            wp_send_json_error( 'Invalid security token' );
        }
        
        // Check permissions
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        
        $scan_id = isset( $_POST['scan_id'] ) ? (int) $_POST['scan_id'] : 0;
        
        if ( ! $scan_id ) {
            wp_send_json_error( 'Invalid scan ID' );
        }
        
        // Get scan summary to validate
        $scan_summary = $this->integrityService->getScanSummary( $scan_id );
        if ( ! $scan_summary ) {
            wp_send_json_error( 'Scan not found' );
        }
        
        // Check if there are changes
        if ( $scan_summary['changed_files'] === 0 && 
             $scan_summary['new_files'] === 0 && 
             $scan_summary['deleted_files'] === 0 ) {
            wp_send_json_error( 'No changes to notify about' );
        }
        
        // Check if email is enabled
        if ( ! $this->settingsService->isNotificationEnabled() ) {
            wp_send_json_error( 'Email notifications are not enabled' );
        }
        
        // Use the existing sendChangeNotification method
        $sent = $this->integrityService->sendChangeNotification( $scan_id );
        
        if ( $sent ) {
            wp_send_json_success( 'Email notification sent successfully' );
        } else {
            wp_send_json_error( 'Failed to send email notification' );
        }
    }

    /**
     * AJAX handler for resending Slack notification  
     */
    public function ajaxResendSlackNotification(): void {
        // Check nonce
        if ( ! check_ajax_referer( 'file_integrity_ajax', '_wpnonce', false ) ) {
            wp_send_json_error( 'Invalid security token' );
        }
        
        // Check permissions
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        
        $scan_id = isset( $_POST['scan_id'] ) ? (int) $_POST['scan_id'] : 0;
        
        if ( ! $scan_id ) {
            wp_send_json_error( 'Invalid scan ID' );
        }
        
        // Get scan summary to validate
        $scan_summary = $this->integrityService->getScanSummary( $scan_id );
        if ( ! $scan_summary ) {
            wp_send_json_error( 'Scan not found' );
        }
        
        // Check if there are changes
        if ( $scan_summary['changed_files'] === 0 && 
             $scan_summary['new_files'] === 0 && 
             $scan_summary['deleted_files'] === 0 ) {
            wp_send_json_error( 'No changes to notify about' );
        }
        
        // Check if Slack is enabled
        if ( ! $this->settingsService->isSlackEnabled() || 
             empty( $this->settingsService->getSlackWebhookUrl() ) ) {
            wp_send_json_error( 'Slack notifications are not configured' );
        }
        
        // Use the existing sendChangeNotification method
        $sent = $this->integrityService->sendChangeNotification( $scan_id );
        
        if ( $sent ) {
            wp_send_json_success( 'Slack notification sent successfully' );
        } else {
            wp_send_json_error( 'Failed to send Slack notification' );
        }
    }

    /**
     * AJAX handler for viewing file content
     */
    public function ajaxViewFile(): void {
        // Check nonce
        if ( ! check_ajax_referer( 'file_integrity_ajax', '_wpnonce', false ) ) {
            wp_send_json_error( 'Invalid security token' );
        }
        
        // Check permissions - require admin access
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        
        $file_path = isset( $_POST['file_path'] ) ? sanitize_text_field( $_POST['file_path'] ) : '';
        $scan_id = isset( $_POST['scan_id'] ) ? (int) $_POST['scan_id'] : 0;
        
        if ( empty( $file_path ) || ! $scan_id ) {
            wp_send_json_error( 'Invalid parameters' );
        }
        
        // Get file content using the secure FileViewerService
        $result = $this->fileViewerService->getFileContent( $file_path, $scan_id );
        
        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result['error'] );
        }
    }
}