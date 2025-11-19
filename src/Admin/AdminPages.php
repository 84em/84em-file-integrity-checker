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
use EightyFourEM\FileIntegrityChecker\Database\FileRecordRepository;
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
     * @param LoggerService         $logger                Logger service
     * @param NotificationService   $notificationService   Notification service
     * @param FileRecordRepository  $fileRecordRepository  File record repository
     */
    public function __construct(
        IntegrityService $integrityService,
        SettingsService $settingsService,
        SchedulerService $schedulerService,
        ScanResultsRepository $scanResultsRepository,
        LoggerService $logger,
        NotificationService $notificationService,
        FileRecordRepository $fileRecordRepository
    ) {
        $this->integrityService = $integrityService;
        $this->settingsService  = $settingsService;
        $this->schedulerService = $schedulerService;
        $this->scanResultsRepository = $scanResultsRepository;
        $this->logger = $logger;
        $this->notificationService = $notificationService;
        $this->fileRecordRepository = $fileRecordRepository;

        $this->checkWordPressUpdateAndSuggestBaseline();
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
        add_action( 'wp_ajax_file_integrity_mark_baseline', [ $this, 'ajaxMarkBaseline' ] );
        add_action( 'wp_ajax_file_integrity_clear_baseline', [ $this, 'handleClearBaseline' ] );
        add_action( 'wp_ajax_file_integrity_set_baseline', [ $this, 'handleSetBaseline' ] );
        add_action( 'wp_ajax_file_integrity_dismiss_baseline_suggestion', [ $this, 'handleDismissBaselineSuggestion' ] );
        add_action( 'wp_ajax_file_integrity_dismiss_plugin_changes', [ $this, 'handleDismissPluginChanges' ] );
        add_action( 'wp_ajax_file_integrity_refresh_database_health', [ $this, 'ajaxRefreshDatabaseHealth' ] );

        // Register admin notices
        add_action( 'admin_notices', [ $this, 'displayBaselineRefreshNotice' ] );
        add_action( 'admin_notices', [ $this, 'displayPluginChangeNotice' ] );
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
            'nonce' => Security::create_nonce( 'file_integrity_admin' ),
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
                'clear_baseline' => Security::create_nonce( 'ajax_clear_baseline' ),
                'set_baseline' => Security::create_nonce( 'ajax_set_baseline' ),
                'refresh_database_health' => Security::create_nonce( 'ajax_refresh_database_health' ),
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

        // Get database health statistics
        $table_stats = $this->fileRecordRepository->getTableStatistics();

        // Check for bloat using same logic as analyze-bloat command
        // Bloat exists if there are records older than Tier 2 retention period
        $tier2_days = $this->settingsService->getRetentionTier2Days();
        $distribution = $this->fileRecordRepository->getRecordDistributionByAge();

        $records_older_than_tier2 = 0;
        foreach ( $distribution as $range ) {
            // Count records in ranges beyond tier2_days (default 30 days)
            if ( in_array( $range['age_range'], [ '31-90 days', '91-180 days', '180+ days' ], true ) ) {
                $records_older_than_tier2 += $range['record_count'];
            }
        }

        $has_bloat = $records_older_than_tier2 > 0;

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
        $this->addSettingsContextualHelp();

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

        // Tiered retention settings
        if ( isset( $_POST['retention_tier2_days'] ) ) {
            $settings['retention_tier2_days'] = (int) $_POST['retention_tier2_days'];
        }

        if ( isset( $_POST['retention_tier3_days'] ) ) {
            $settings['retention_tier3_days'] = (int) $_POST['retention_tier3_days'];
        }

        $settings['retention_keep_baseline'] = isset( $_POST['retention_keep_baseline'] );

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

        // Prevent baseline deletion
        if ( $this->scanResultsRepository->isBaseline( $scan_id ) ) {
            wp_send_json_error( 'Cannot delete baseline scan. Use "Clear Baseline" from Settings to remove baseline designation first.' );
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

        // Remove baseline from deletion list if present
        $baseline_id = $this->scanResultsRepository->getBaselineScanId();
        $baseline_skipped = false;

        if ( $baseline_id && in_array( $baseline_id, $scan_ids, true ) ) {
            $scan_ids = array_diff( $scan_ids, [ $baseline_id ] );
            $baseline_skipped = true;

            if ( empty( $scan_ids ) ) {
                wp_send_json_error( sprintf( 'Cannot delete baseline scan #%d. No other scans were selected.', $baseline_id ) );
            }
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

            if ( $baseline_skipped ) {
                $message = sprintf( 'Deleted %d scan(s). Baseline scan #%d was skipped (protected from deletion).', $deleted_count, $baseline_id );
            } elseif ( $failed_count > 0 ) {
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
            // Create initial scan record with queued status
            $scan_id = $this->scanResultsRepository->create( [
                'scan_date' => current_time( 'mysql' ),
                'status' => 'queued',
                'scan_type' => 'manual',
                'notes' => 'Scan queued at ' . current_time( 'mysql' ),
            ] );

            if ( ! $scan_id ) {
                wp_send_json_error( 'Failed to create scan record' );
                return;
            }

            // Schedule the scan to run immediately in the background using async action
            $action_id = as_enqueue_async_action(
                'eightyfourem_file_integrity_scan',
                [
                    [
                        'type' => 'manual',
                        'scan_id' => $scan_id,
                        'queued' => true
                    ]
                ],
                'file-integrity-checker'
            );

            if ( $action_id !== false ) {
                $this->logger->info(
                    'Scan queued successfully for background processing',
                    LoggerService::CONTEXT_ADMIN,
                    [ 'scan_id' => $scan_id, 'action_id' => $action_id ]
                );

                wp_send_json_success( [
                    'scan_id' => $scan_id,
                    'message' => 'Scan has been queued and will run in the background',
                    'results_url' => admin_url( 'admin.php?page=file-integrity-checker-results' ),
                    'background' => true
                ] );
            } else {
                // If scheduling failed, delete the scan record and return error
                $this->scanResultsRepository->delete( $scan_id );
                wp_send_json_error( 'Failed to queue scan for background processing' );
            }
        } catch ( \Exception $e ) {
            $this->logger->error(
                'Error queuing scan: ' . $e->getMessage(),
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
        
        // Use NotificationService to resend only email notification
        $result = $this->notificationService->resendEmailNotification( $scan_id );

        if ( $result['success'] && isset( $result['channels']['email'] ) && $result['channels']['email']['success'] ) {
            wp_send_json_success( 'Email notification sent successfully' );
        } else {
            $error_msg = isset( $result['channels']['email']['error'] )
                ? $result['channels']['email']['error']
                : ( $result['error'] ?? 'Failed to send email notification' );
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
        
        // Use NotificationService to resend only Slack notification
        $result = $this->notificationService->resendSlackNotification( $scan_id );

        if ( $result['success'] && isset( $result['channels']['slack'] ) && $result['channels']['slack']['success'] ) {
            wp_send_json_success( 'Slack notification sent successfully' );
        } else {
            $error_msg = isset( $result['channels']['slack']['error'] )
                ? $result['channels']['slack']['error']
                : ( $result['error'] ?? 'Failed to send Slack notification' );
            wp_send_json_error( $error_msg );
        }
    }

    /**
     * AJAX handler to mark a scan as baseline
     */
    public function ajaxMarkBaseline(): void {
        // Check action-specific nonce
        if ( ! Security::check_ajax_referer( 'ajax_mark_baseline', '_wpnonce', false ) ) {
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

        // Get scan to validate it exists
        $scan = $this->scanResultsRepository->getById( $scan_id );
        if ( ! $scan ) {
            wp_send_json_error( 'Scan not found' );
        }

        // Mark as baseline
        $result = $this->scanResultsRepository->markAsBaseline( $scan_id );

        if ( $result ) {
            // Log the action
            $this->logger->info(
                sprintf( 'Scan #%d marked as baseline by user', $scan_id ),
                'baseline',
                [ 'scan_id' => $scan_id ]
            );

            wp_send_json_success( [
                'message' => 'Scan marked as baseline successfully',
                'scan_id' => $scan_id,
            ] );
        } else {
            wp_send_json_error( 'Failed to mark scan as baseline' );
        }
    }

    /**
     * Handle clear baseline AJAX request
     */
    public function handleClearBaseline(): void {
        // Check action-specific nonce
        if ( ! Security::check_ajax_referer( 'ajax_clear_baseline', '_wpnonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Invalid security token' ] );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
        }

        $scan_id = (int) $_POST['scan_id'];

        // Clear baseline
        $success = $this->scanResultsRepository->clearBaseline();

        if ( $success ) {
            $this->logger->info(
                message: sprintf( 'Baseline cleared for scan #%d', $scan_id ),
                context: LoggerService::CONTEXT_GENERAL,
                data: [ 'scan_id' => $scan_id ]
            );

            wp_send_json_success( [ 'message' => 'Baseline designation cleared' ] );
        } else {
            wp_send_json_error( [ 'message' => 'Failed to clear baseline' ] );
        }
    }

    /**
     * Handle set baseline AJAX request
     */
    public function handleSetBaseline(): void {
        // Check action-specific nonce
        if ( ! Security::check_ajax_referer( 'ajax_set_baseline', '_wpnonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Invalid security token' ] );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
        }

        $scan_id = (int) $_POST['scan_id'];

        // Verify scan exists
        $scan = $this->scanResultsRepository->getById( $scan_id );
        if ( ! $scan ) {
            wp_send_json_error( [ 'message' => 'Scan not found' ] );
        }

        // Set as baseline
        $success = $this->scanResultsRepository->markAsBaseline( $scan_id );

        if ( $success ) {
            $this->logger->info(
                message: sprintf( 'Scan #%d marked as baseline', $scan_id ),
                context: LoggerService::CONTEXT_GENERAL,
                data: [ 'scan_id' => $scan_id ]
            );

            wp_send_json_success( [ 'message' => 'Scan marked as baseline' ] );
        } else {
            wp_send_json_error( [ 'message' => 'Failed to set baseline' ] );
        }
    }

    /**
     * Check if WordPress was recently updated and suggest baseline refresh
     */
    private function checkWordPressUpdateAndSuggestBaseline(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $stored_wp_version = get_option( 'eightyfourem_last_wp_version', '' );
        $current_wp_version = get_bloginfo( 'version' );

        if ( $stored_wp_version !== $current_wp_version && ! empty( $stored_wp_version ) ) {
            $baseline_scan = $this->scanResultsRepository->getBaselineScan();

            if ( $baseline_scan ) {
                set_transient( 'eightyfourem_suggest_baseline_refresh', [
                    'old_version' => $stored_wp_version,
                    'new_version' => $current_wp_version,
                    'baseline_date' => $baseline_scan->scan_date,
                    'baseline_id' => $baseline_scan->id,
                ], 7 * DAY_IN_SECONDS );
            }
        }

        if ( $stored_wp_version !== $current_wp_version ) {
            update_option( 'eightyfourem_last_wp_version', $current_wp_version );
        }
    }

    /**
     * Display baseline refresh suggestion notice
     */
    public function displayBaselineRefreshNotice(): void {
        $suggestion = get_transient( 'eightyfourem_suggest_baseline_refresh' );

        if ( ! $suggestion ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || strpos( $screen->id, 'file-integrity-checker' ) === false ) {
            return;
        }

        ?>
        <div class="notice notice-info is-dismissible" id="baseline-refresh-notice">
            <p>
                <strong>File Integrity Checker:</strong> WordPress has been updated from
                <?php echo esc_html( $suggestion['old_version'] ); ?> to
                <?php echo esc_html( $suggestion['new_version'] ); ?>.
                Your current baseline scan is from <?php echo esc_html( date( 'M j, Y', strtotime( $suggestion['baseline_date'] ) ) ); ?>.
            </p>
            <p>
                Consider creating a new baseline scan after verifying all changes are legitimate.
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=file-integrity-checker' ) ); ?>" class="button button-secondary">
                    Run New Scan
                </a>
                <button type="button" class="button" id="dismiss-baseline-suggestion">Dismiss</button>
            </p>
        </div>
        <script>
        jQuery(document).ready(function($) {
            $('#dismiss-baseline-suggestion').on('click', function() {
                $.post(ajaxurl, {
                    action: 'file_integrity_dismiss_baseline_suggestion',
                    _wpnonce: '<?php echo \EightyFourEM\FileIntegrityChecker\Utils\Security::create_nonce( 'ajax_dismiss_baseline_suggestion' ); ?>'
                });
                $('#baseline-refresh-notice').fadeOut();
            });
        });
        </script>
        <?php
    }

    /**
     * Handle dismissing baseline refresh suggestion
     */
    public function handleDismissBaselineSuggestion(): void {
        // Check action-specific nonce
        if ( ! Security::check_ajax_referer( 'ajax_dismiss_baseline_suggestion', '_wpnonce', false ) ) {
            wp_send_json_error( 'Invalid security token' );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }

        delete_transient( 'eightyfourem_suggest_baseline_refresh' );
        wp_send_json_success();
    }

    /**
     * Display plugin change baseline suggestion
     */
    public function displayPluginChangeNotice(): void {
        $changes = get_transient( 'eightyfourem_plugin_changes_for_baseline' );

        if ( ! $changes || empty( $changes['changes'] ) ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || strpos( $screen->id, 'file-integrity-checker' ) === false ) {
            return;
        }

        $count = count( $changes['changes'] );
        ?>
        <div class="notice notice-info is-dismissible" id="plugin-change-notice">
            <p>
                <strong>File Integrity Checker:</strong>
                <?php echo esc_html( $count ); ?> plugin<?php echo $count > 1 ? 's have' : ' has'; ?> been
                activated or deactivated recently. Consider creating a new baseline scan.
            </p>
            <p>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=file-integrity-checker' ) ); ?>" class="button button-secondary">
                    Run New Scan
                </a>
                <button type="button" class="button" id="dismiss-plugin-changes">Dismiss</button>
            </p>
        </div>
        <script>
        jQuery(document).ready(function($) {
            $('#dismiss-plugin-changes').on('click', function() {
                $.post(ajaxurl, {
                    action: 'file_integrity_dismiss_plugin_changes',
                    _wpnonce: '<?php echo \EightyFourEM\FileIntegrityChecker\Utils\Security::create_nonce( 'ajax_dismiss_plugin_changes' ); ?>'
                });
                $('#plugin-change-notice').fadeOut();
            });
        });
        </script>
        <?php
    }

    /**
     * Handle dismissing plugin change suggestion
     */
    public function handleDismissPluginChanges(): void {
        // Check action-specific nonce
        if ( ! Security::check_ajax_referer( 'ajax_dismiss_plugin_changes', '_wpnonce', false ) ) {
            wp_send_json_error( 'Invalid security token' );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }

        delete_transient( 'eightyfourem_plugin_changes_for_baseline' );
        wp_send_json_success();
    }

    /**
     * AJAX handler to refresh database health statistics
     */
    public function ajaxRefreshDatabaseHealth(): void {
        // Check action-specific nonce
        if ( ! Security::check_ajax_referer( 'ajax_refresh_database_health', '_wpnonce', false ) ) {
            wp_send_json_error( 'Invalid security token' );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
        }

        // Clear the cached table statistics
        $this->fileRecordRepository->clearTableStatisticsCache();

        wp_send_json_success( [ 'message' => 'Database health cache cleared' ] );
    }

    /**
     * Register contextual help for settings page
     */
    private function addSettingsContextualHelp(): void {
        $screen = get_current_screen();

        if ( ! $screen ) {
            return;
        }

        $screen->add_help_tab( [
            'id' => 'baseline-management',
            'title' => 'Baseline Management',
            'content' => '
                <h3>What is a Baseline Scan?</h3>
                <p>The baseline scan serves as the reference point for all file integrity comparisons. It represents a known-good state of your WordPress installation.</p>

                <h3>How Baselines Work</h3>
                <ul>
                    <li>Your first scan automatically becomes the baseline</li>
                    <li>Baseline scans are protected from automatic deletion</li>
                    <li>All subsequent scans are compared against the most recent scan, falling back to baseline for missing files</li>
                    <li>Only changed, new, and deleted files are stored after the baseline (98% storage reduction)</li>
                </ul>

                <h3>When to Create a New Baseline</h3>
                <ul>
                    <li>After major WordPress core updates</li>
                    <li>After installing or removing multiple plugins</li>
                    <li>After verifying and accepting legitimate file changes</li>
                    <li>If baseline is more than 6 months old</li>
                </ul>

                <h3>How to Create a New Baseline</h3>
                <ol>
                    <li>Run a new scan from the Dashboard page</li>
                    <li>Review scan results to ensure all changes are legitimate</li>
                    <li>Click "Set as Baseline" on the scan results page</li>
                </ol>

                <h3>WP-CLI Commands</h3>
                <ul>
                    <li><code>wp 84em integrity baseline show</code> - View current baseline</li>
                    <li><code>wp 84em integrity baseline mark &lt;scan-id&gt;</code> - Set a specific scan as baseline</li>
                    <li><code>wp 84em integrity baseline refresh</code> - Run new scan and set as baseline</li>
                    <li><code>wp 84em integrity baseline clear</code> - Clear baseline designation</li>
                </ul>
            '
        ] );

        $screen->add_help_tab( [
            'id' => 'retention-policy',
            'title' => 'Retention Policy',
            'content' => '
                <h3>Tiered Retention System</h3>
                <p>The plugin uses a three-tier retention system to optimize database size while maintaining historical data:</p>

                <h4>Tier 1: Baseline Scan</h4>
                <ul>
                    <li>Retained forever</li>
                    <li>Protected from all cleanup operations</li>
                    <li>Contains complete file records for all files</li>
                </ul>

                <h4>Tier 2: Recent Scans (30 days)</h4>
                <ul>
                    <li>Full scan details retained</li>
                    <li>Only changed/new/deleted files stored</li>
                    <li>Diff content available for changed files</li>
                </ul>

                <h4>Tier 3: Historical Scans (31-90 days)</h4>
                <ul>
                    <li>Summary metadata retained</li>
                    <li>Diff content removed to save space</li>
                    <li>File change counts preserved</li>
                </ul>

                <h4>Deleted: Old Scans (90+ days)</h4>
                <ul>
                    <li>Automatically deleted during cleanup</li>
                    <li>Exception: Scans with critical priority files are retained</li>
                </ul>

                <h3>Database Optimization</h3>
                <p>Automated cleanup runs every 6 hours to maintain optimal database size. You can also manually run cleanup using:</p>
                <ul>
                    <li><code>wp 84em integrity cleanup</code> - Clean old scan records</li>
                    <li><code>wp 84em integrity analyze-bloat</code> - Analyze database bloat</li>
                </ul>
            '
        ] );

        $screen->set_help_sidebar(
            '<p><strong>For More Information:</strong></p>' .
            '<p><a href="https://github.com/84emllc/84em-file-integrity-checker" target="_blank">Plugin Documentation</a></p>' .
            '<p><a href="https://github.com/84emllc/84em-file-integrity-checker/issues" target="_blank">Report Issues</a></p>'
        );
    }

}