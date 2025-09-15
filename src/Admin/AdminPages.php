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
use EightyFourEM\FileIntegrityChecker\Services\LoggerService;
use EightyFourEM\FileIntegrityChecker\Services\NotificationService;
use EightyFourEM\FileIntegrityChecker\Database\ScanResultsRepository;
use EightyFourEM\FileIntegrityChecker\Utils\Security;

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
     * @param IntegrityService      $integrityService      Integrity service
     * @param SettingsService       $settingsService       Settings service
     * @param SchedulerService      $schedulerService      Scheduler service
     * @param ScanResultsRepository $scanResultsRepository Scan results repository
     * @param LoggerService         $logger                Logger service
     * @param NotificationService   $notificationService   Notification service
     */
    public function __construct(
        IntegrityService $integrityService,
        SettingsService $settingsService,
        SchedulerService $schedulerService,
        ScanResultsRepository $scanResultsRepository,
        LoggerService $logger,
        NotificationService $notificationService
    ) {
        $this->integrityService = $integrityService;
        $this->settingsService  = $settingsService;
        $this->schedulerService = $schedulerService;
        $this->scanResultsRepository = $scanResultsRepository;
        $this->logger = $logger;
        $this->notificationService = $notificationService;
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

        add_submenu_page(
            'file-integrity-checker',
            'System Logs',
            'System Logs',
            'manage_options',
            'file-integrity-checker-logs',
            [ $this, 'renderLogsPage' ]
        );
    }

    /**
     * Handle admin actions
     */
    public function handleActions(): void {
        if ( ! isset( $_POST['action'] ) || ! isset( $_POST['_wpnonce'] ) ) {
            return;
        }
        
        $action = sanitize_text_field( $_POST['action'] );
        
        // Verify action-specific nonce
        if ( ! Security::verify_nonce( $_POST['_wpnonce'], 'admin_action_' . $action ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You do not have sufficient permissions to perform this action.' );
        }

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

        // Use minified versions in production (when they exist)
        $css_file = 'assets/css/admin.css';
        $modal_js_file = 'assets/js/modal.js';
        $admin_js_file = 'assets/js/admin.js';
        
        // Check if minified versions exist and use them
        if ( file_exists( EIGHTYFOUREM_FILE_INTEGRITY_CHECKER_PATH . 'assets/css/admin.min.css' ) ) {
            $css_file = 'assets/css/admin.min.css';
        }
        if ( file_exists( EIGHTYFOUREM_FILE_INTEGRITY_CHECKER_PATH . 'assets/js/modal.min.js' ) ) {
            $modal_js_file = 'assets/js/modal.min.js';
        }
        if ( file_exists( EIGHTYFOUREM_FILE_INTEGRITY_CHECKER_PATH . 'assets/js/admin.min.js' ) ) {
            $admin_js_file = 'assets/js/admin.min.js';
        }
        
        wp_enqueue_style(
            'file-integrity-checker-admin',
            EIGHTYFOUREM_FILE_INTEGRITY_CHECKER_URL . $css_file,
            [],
            EIGHTYFOUREM_FILE_INTEGRITY_CHECKER_VERSION
        );

        // Enqueue modal system first
        wp_enqueue_script(
            'file-integrity-checker-modal',
            EIGHTYFOUREM_FILE_INTEGRITY_CHECKER_URL . $modal_js_file,
            [],
            EIGHTYFOUREM_FILE_INTEGRITY_CHECKER_VERSION,
            true
        );

        wp_enqueue_script(
            'file-integrity-checker-admin',
            EIGHTYFOUREM_FILE_INTEGRITY_CHECKER_URL . $admin_js_file,
            [ 'jquery', 'file-integrity-checker-modal' ],
            EIGHTYFOUREM_FILE_INTEGRITY_CHECKER_VERSION,
            true
        );

        // Localize script with AJAX data
        wp_localize_script( 'file-integrity-checker-admin', 'fileIntegrityChecker', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonces' => [
                'start_scan' => Security::create_nonce( 'ajax_start_scan' ),
                'check_progress' => Security::create_nonce( 'ajax_check_progress' ),
                'cancel_scan' => Security::create_nonce( 'ajax_cancel_scan' ),
                'delete_scan' => Security::create_nonce( 'ajax_delete_scan' ),
                'bulk_delete' => Security::create_nonce( 'ajax_bulk_delete_scans' ),
                'cleanup_old' => Security::create_nonce( 'ajax_cleanup_old_scans' ),
                'test_slack' => Security::create_nonce( 'ajax_test_slack' ),
                'resend_email' => Security::create_nonce( 'ajax_resend_email' ),
                'resend_slack' => Security::create_nonce( 'ajax_resend_slack' ),
                'view_file' => Security::create_nonce( 'ajax_view_file' ),
            ],
        ] );
    }

    /**
     * Render dashboard page
     */
    public function renderDashboardPage(): void {
        $stats = $this->integrityService->getDashboardStats();
        
        // Get next scheduled scan from active schedules in database
        $active_schedules = $this->schedulerService->getSchedules( [ 'is_active' => 1 ] );
        $next_scan = null;
        
        if ( ! empty( $active_schedules ) ) {
            // Find the schedule with the earliest next_run time
            $earliest_schedule = null;
            foreach ( $active_schedules as $schedule ) {
                if ( ! empty( $schedule->next_run ) ) {
                    if ( $earliest_schedule === null || $schedule->next_run < $earliest_schedule->next_run ) {
                        $earliest_schedule = $schedule;
                    }
                }
            }
            
            if ( $earliest_schedule && $earliest_schedule->next_run ) {
                // Convert UTC next_run to site timezone for display
                $next_utc = new \DateTime( $earliest_schedule->next_run, new \DateTimeZone( 'UTC' ) );
                $next_local = clone $next_utc;
                $next_local->setTimezone( wp_timezone() );
                
                $now = current_datetime(); // Already in site timezone
                
                // Format the time_until the same way as in schedules.php
                $time_until = '';
                if ( $next_local > $now ) {
                    $diff = $now->diff( $next_local );
                    $hours = $diff->h + ($diff->days * 24);
                    
                    if ( $diff->days > 0 ) {
                        $time_until = sprintf( 'In %d day%s, %d hour%s', 
                            $diff->days, 
                            $diff->days > 1 ? 's' : '',
                            $diff->h,
                            $diff->h != 1 ? 's' : ''
                        );
                    } elseif ( $hours > 0 ) {
                        $time_until = sprintf( 'In %d hour%s, %d minute%s', 
                            $hours, 
                            $hours > 1 ? 's' : '',
                            $diff->i,
                            $diff->i != 1 ? 's' : ''
                        );
                    } else {
                        $time_until = sprintf( 'In %d minute%s', 
                            $diff->i,
                            $diff->i != 1 ? 's' : ''
                        );
                    }
                }
                
                $next_scan = (object) [
                    'datetime' => $next_local->format( 'Y-m-d H:i:s' ),
                    'time_until' => $time_until,
                    'schedule_name' => $earliest_schedule->name
                ];
            }
        }
        
        $scheduler_available = $this->schedulerService->isAvailable();
        $scheduler_service = $this->schedulerService;
        
        // Note: Scan completion notifications are handled entirely by JavaScript
        // to avoid duplicate notices. The JS checkScanCompletion() method in admin.js
        // uses sessionStorage to display the success message when scan_completed=1 is in URL

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
            $scan_id = filter_var( $_GET['scan_id'], FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 1 ] ] );
            if ( $scan_id === false ) {
                wp_die( 'Invalid scan ID' );
            }
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
            $emails = Security::validate_emails( $_POST['notification_email'] );
            if ( ! empty( $emails ) ) {
                $settings['notification_email'] = implode( ', ', $emails );
            }
        }
        
        // Email customization settings
        if ( isset( $_POST['email_subject'] ) ) {
            $settings['email_subject'] = sanitize_text_field( $_POST['email_subject'] );
        }
        
        if ( isset( $_POST['email_from_address'] ) && ! empty( $_POST['email_from_address'] ) ) {
            $settings['email_from_address'] = sanitize_email( $_POST['email_from_address'] );
        }
        
        // Slack settings
        $settings['slack_enabled'] = isset( $_POST['slack_enabled'] );
        
        if ( isset( $_POST['slack_webhook_url'] ) ) {
            $settings['slack_webhook_url'] = sanitize_url( $_POST['slack_webhook_url'] );
        }
        
        // Slack customization settings
        if ( isset( $_POST['slack_header'] ) ) {
            $settings['slack_header'] = sanitize_text_field( $_POST['slack_header'] );
        }
        
        if ( isset( $_POST['slack_message_template'] ) ) {
            $settings['slack_message_template'] = sanitize_text_field( $_POST['slack_message_template'] );
        }

        // Retention period
        if ( isset( $_POST['retention_period'] ) ) {
            $settings['retention_period'] = (int) $_POST['retention_period'];
        }

        // Content retention limit
        if ( isset( $_POST['content_retention_limit'] ) ) {
            $settings['content_retention_limit'] = (int) $_POST['content_retention_limit'];
        }
        
        // Log settings
        if ( isset( $_POST['log_levels'] ) && is_array( $_POST['log_levels'] ) ) {
            $settings['log_levels'] = array_map( 'sanitize_text_field', $_POST['log_levels'] );
        } else {
            $settings['log_levels'] = [];
        }
        
        if ( isset( $_POST['log_retention_days'] ) ) {
            $settings['log_retention_days'] = (int) $_POST['log_retention_days'];
        }
        
        $settings['auto_log_cleanup'] = isset( $_POST['auto_log_cleanup'] );
        $settings['debug_mode'] = isset( $_POST['debug_mode'] );
        
        // Uninstall settings
        $settings['delete_data_on_uninstall'] = isset( $_POST['delete_data_on_uninstall'] );

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
        if ( $has_failures ) {
            $this->logger->warning(
                'Failed to update some settings: ' . implode( ', ', $failed_settings ),
                LoggerService::CONTEXT_SETTINGS,
                [ 'failed_settings' => $failed_settings ]
            );
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
        // Check action-specific nonce
        if ( ! Security::check_ajax_referer( 'ajax_cleanup_old_scans', '_wpnonce', false ) ) {
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
            $this->logger->error(
                'Failed to cleanup old scans: ' . $e->getMessage(),
                LoggerService::CONTEXT_ADMIN,
                [ 'exception' => $e->getMessage() ]
            );
            wp_send_json_error( Security::sanitize_error_message( $e->getMessage() ) );
        }
    }

    /**
     * AJAX handler for canceling a scan
     */
    public function ajaxCancelScan(): void {
        // Check action-specific nonce
        if ( ! Security::check_ajax_referer( 'ajax_cancel_scan', '_wpnonce', false ) ) {
            wp_send_json_error( 'Invalid security token' );
        }
        
        // Check permissions
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        
        $scan_id = isset( $_POST['scan_id'] ) ? filter_var( $_POST['scan_id'], FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 1 ] ] ) : false;
        
        if ( $scan_id === false ) {
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
            $this->logger->error(
                'Failed to cancel scan: ' . $e->getMessage(),
                LoggerService::CONTEXT_ADMIN,
                [ 'scan_id' => $scan_id, 'exception' => $e->getMessage() ]
            );
            wp_send_json_error( Security::sanitize_error_message( $e->getMessage() ) );
        }
    }
    
    /**
     * AJAX handler for deleting a scan
     */
    public function ajaxDeleteScan(): void {
        // Check action-specific nonce
        if ( ! Security::check_ajax_referer( 'ajax_delete_scan', '_wpnonce', false ) ) {
            wp_send_json_error( 'Invalid security token' );
        }
        
        // Check permissions
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        
        $scan_id = isset( $_POST['scan_id'] ) ? filter_var( $_POST['scan_id'], FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 1 ] ] ) : false;
        
        if ( $scan_id === false ) {
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
            $this->logger->error(
                'Failed to delete scan: ' . $e->getMessage(),
                LoggerService::CONTEXT_ADMIN,
                [ 'scan_id' => $scan_id, 'exception' => $e->getMessage() ]
            );
            wp_send_json_error( Security::sanitize_error_message( $e->getMessage() ) );
        }
    }
    
    /**
     * AJAX handler for testing Slack webhook
     */
    public function ajaxTestSlack(): void {
        // Check action-specific nonce and rate limiting
        if ( ! Security::check_ajax_referer( 'test_slack', '_wpnonce', false ) ) {
            wp_send_json_error( 'Invalid security token' );
        }
        
        // Rate limit webhook tests
        if ( ! Security::check_rate_limit( 'test_slack', 3, 60 ) ) {
            wp_send_json_error( 'Too many requests. Please wait before trying again.' );
        }
        
        // Check permissions
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        
        $webhook_url = isset( $_POST['webhook_url'] ) ? $_POST['webhook_url'] : '';
        
        // Use Security utility for proper webhook validation
        $validated_url = Security::validate_webhook_url( $webhook_url, 'slack' );
        if ( ! $validated_url ) {
            wp_send_json_error( 'Invalid Slack webhook URL format' );
        }
        $webhook_url = $validated_url;
        
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
                            'text' => 'Site: ' . site_url() . ' | Time: ' . wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) )
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
        // Check action-specific nonce
        if ( ! Security::check_ajax_referer( 'ajax_bulk_delete_scans', '_wpnonce', false ) ) {
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
                    $this->logger->error(
                        'Failed to delete scan ' . $scan_id . ': ' . $e->getMessage(),
                        LoggerService::CONTEXT_ADMIN,
                        [ 'scan_id' => $scan_id, 'exception' => $e->getMessage() ]
                    );
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
     * Render logs page
     */
    public function renderLogsPage(): void {
        include plugin_dir_path( dirname( __DIR__ ) ) . 'views/admin/logs.php';
    }

    /**
     * AJAX handler for starting a scan
     */
    public function ajaxStartScan(): void {
        // Check action-specific nonce and rate limiting
        if ( ! Security::check_ajax_referer( 'ajax_start_scan', '_wpnonce', false ) ) {
            wp_send_json_error( 'Invalid security token' );
        }
        
        // Rate limit scan starts
        if ( ! Security::check_rate_limit( 'start_scan', 5, 300 ) ) {
            wp_send_json_error( 'Too many scan attempts. Please wait before trying again.' );
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
            $this->logger->error(
                'Error starting scan: ' . $e->getMessage(),
                LoggerService::CONTEXT_ADMIN,
                [ 'exception' => $e->getMessage() ]
            );
            wp_send_json_error( Security::sanitize_error_message( $e->getMessage() ) );
        }
    }

    /**
     * AJAX handler for checking scan progress
     */
    public function ajaxCheckProgress(): void {
        // Check action-specific nonce
        if ( ! Security::check_ajax_referer( 'ajax_check_progress', '_wpnonce', false ) ) {
            wp_send_json_error( 'Invalid security token' );
        }
        
        // Check permissions
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        
        $scan_id = isset( $_POST['scan_id'] ) ? filter_var( $_POST['scan_id'], FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 1 ] ] ) : false;
        
        if ( $scan_id === false ) {
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
                
                // Store scan completion data in transient for fallback
                $transient_key = 'file_integrity_scan_completed_' . get_current_user_id();
                set_transient( $transient_key, [
                    'scan_id' => $scan_id,
                    'total_files' => $scan->total_files,
                    'changed_files' => $scan->changed_files,
                    'new_files' => $scan->new_files,
                    'deleted_files' => $scan->deleted_files,
                    'completed_at' => current_time( 'mysql' )
                ], 60 ); // Expire after 60 seconds
            } elseif ( $scan->status === 'failed' ) {
                $response['message'] = 'Scan failed: ' . ( $scan->notes ?: 'Unknown error' );
            } else {
                $response['message'] = 'Scan in progress...';
                $response['progress'] = 50; // Approximate progress
            }
            
            wp_send_json_success( $response );
            
        } catch ( \Exception $e ) {
            $this->logger->error(
                'Error checking scan progress: ' . $e->getMessage(),
                LoggerService::CONTEXT_ADMIN,
                [ 'scan_id' => $scan_id, 'exception' => $e->getMessage() ]
            );
            wp_send_json_error( Security::sanitize_error_message( $e->getMessage() ) );
        }
    }

    /**
     * AJAX handler for resending email notification
     */
    public function ajaxResendEmailNotification(): void {
        // Check action-specific nonce and rate limiting
        if ( ! Security::check_ajax_referer( 'ajax_resend_email', '_wpnonce', false ) ) {
            wp_send_json_error( 'Invalid security token' );
        }
        
        // Rate limit email resends
        if ( ! Security::check_rate_limit( 'resend_email', 3, 300 ) ) {
            wp_send_json_error( 'Too many email requests. Please wait before trying again.' );
        }
        
        // Check permissions
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        
        $scan_id = isset( $_POST['scan_id'] ) ? filter_var( $_POST['scan_id'], FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 1 ] ] ) : false;
        
        if ( $scan_id === false ) {
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
        
        // Use NotificationService to resend notifications
        $result = $this->notificationService->resendNotification( $scan_id );
        
        if ( $result['success'] && isset( $result['channels']['email'] ) && $result['channels']['email']['success'] ) {
            wp_send_json_success( 'Email notification sent successfully' );
        } else {
            $error_msg = isset( $result['channels']['email']['error'] ) 
                ? $result['channels']['email']['error'] 
                : 'Failed to send email notification';
            wp_send_json_error( $error_msg );
        }
    }

    /**
     * AJAX handler for resending Slack notification  
     */
    public function ajaxResendSlackNotification(): void {
        // Check action-specific nonce and rate limiting
        if ( ! Security::check_ajax_referer( 'ajax_resend_slack', '_wpnonce', false ) ) {
            wp_send_json_error( 'Invalid security token' );
        }
        
        // Rate limit Slack resends
        if ( ! Security::check_rate_limit( 'resend_slack', 3, 300 ) ) {
            wp_send_json_error( 'Too many Slack requests. Please wait before trying again.' );
        }
        
        // Check permissions
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        
        $scan_id = isset( $_POST['scan_id'] ) ? filter_var( $_POST['scan_id'], FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 1 ] ] ) : false;
        
        if ( $scan_id === false ) {
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
        
        // Use NotificationService to resend notifications
        $result = $this->notificationService->resendNotification( $scan_id );
        
        if ( $result['success'] && isset( $result['channels']['slack'] ) && $result['channels']['slack']['success'] ) {
            wp_send_json_success( 'Slack notification sent successfully' );
        } else {
            $error_msg = isset( $result['channels']['slack']['error'] ) 
                ? $result['channels']['slack']['error'] 
                : 'Failed to send Slack notification';
            wp_send_json_error( $error_msg );
        }
    }

}