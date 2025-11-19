<?php
/**
 * Dashboard Widget
 *
 * @package EightyFourEM\FileIntegrityChecker\Admin
 */

namespace EightyFourEM\FileIntegrityChecker\Admin;

use EightyFourEM\FileIntegrityChecker\Services\IntegrityService;
use EightyFourEM\FileIntegrityChecker\Database\ScanResultsRepository;
use EightyFourEM\FileIntegrityChecker\Database\FileRecordRepository;

/**
 * WordPress dashboard widget for file integrity status
 */
class DashboardWidget {
    /**
     * Integrity service
     *
     * @var IntegrityService
     */
    private IntegrityService $integrityService;

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
     * Constructor
     *
     * @param IntegrityService      $integrityService      Integrity service
     * @param ScanResultsRepository $scanResultsRepository Scan results repository
     * @param FileRecordRepository  $fileRecordRepository  File record repository
     */
    public function __construct( IntegrityService $integrityService, ScanResultsRepository $scanResultsRepository, FileRecordRepository $fileRecordRepository ) {
        $this->integrityService      = $integrityService;
        $this->scanResultsRepository = $scanResultsRepository;
        $this->fileRecordRepository  = $fileRecordRepository;
    }

    /**
     * Initialize dashboard widget
     */
    public function init(): void {
        add_action( 'wp_dashboard_setup', [ $this, 'addDashboardWidget' ] );
    }

    /**
     * Add dashboard widget
     */
    public function addDashboardWidget(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        wp_add_dashboard_widget(
            'file_integrity_checker_widget',
            '84EM File Integrity Checker',
            [ $this, 'renderWidget' ]
        );
    }

    /**
     * Render dashboard widget content
     */
    public function renderWidget(): void {
        $stats = $this->integrityService->getDashboardStats();
        $latest_scan = $stats['latest_scan'];
        $recent_scans = $this->scanResultsRepository->getRecent( 5 );
        ?>
        <div class="file-integrity-widget">
            <?php if ( $latest_scan ): ?>
                <div class="latest-scan">
                    <h4>Latest Scan</h4>
                    <table class="widefat">
                        <tr>
                            <td><strong>Date:</strong></td>
                            <td><?php echo esc_html( date( 'M j, Y H:i', strtotime( $latest_scan['date'] ) ) ); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Status:</strong></td>
                            <td>
                                <span class="status-badge status-<?php echo esc_attr( $latest_scan['status'] ); ?>">
                                    <?php echo esc_html( ucfirst( $latest_scan['status'] ) ); ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Files Scanned:</strong></td>
                            <td><?php echo number_format( $latest_scan['total_files'] ); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Changes:</strong></td>
                            <td>
                                <?php
                                $total_changes = $latest_scan['changed_files'] + $latest_scan['new_files'] + $latest_scan['deleted_files'];
                                if ( $total_changes > 0 ):
                                ?>
                                    <div class="changes-breakdown">
                                        <?php if ( $latest_scan['new_files'] > 0 ): ?>
                                            <span class="changes-new" title="New files">
                                                <span class="dashicons dashicons-plus-alt"></span>
                                                <?php echo number_format( $latest_scan['new_files'] ); ?> added
                                            </span>
                                        <?php endif; ?>
                                        <?php if ( $latest_scan['changed_files'] > 0 ): ?>
                                            <span class="changes-modified" title="Modified files">
                                                <span class="dashicons dashicons-warning"></span>
                                                <?php echo number_format( $latest_scan['changed_files'] ); ?> changed
                                            </span>
                                        <?php endif; ?>
                                        <?php if ( $latest_scan['deleted_files'] > 0 ): ?>
                                            <span class="changes-deleted" title="Deleted files">
                                                <span class="dashicons dashicons-trash"></span>
                                                <?php echo number_format( $latest_scan['deleted_files'] ); ?> deleted
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="no-changes">No changes</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>

                    <?php if ( $total_changes > 0 ): ?>
                        <p class="widget-alert">
                            <span class="dashicons dashicons-warning"></span>
                            File system changes detected!
                            <a href="<?php echo admin_url( 'admin.php?page=file-integrity-checker-results&scan_id=' . $latest_scan['id'] ); ?>">
                                View Details
                            </a>
                        </p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="no-scans">
                    <p>No scans have been completed yet.</p>
                    <p>
                        <a href="<?php echo admin_url( 'admin.php?page=file-integrity-checker' ); ?>" class="button button-primary">
                            Run First Scan
                        </a>
                    </p>
                </div>
            <?php endif; ?>

            <?php
            // Add database health indicator
            $table_stats = $this->fileRecordRepository->getTableStatistics();
            $total_rows = $table_stats['total_rows'];
            $total_size_mb = $table_stats['total_size_mb'];

            // Calculate if bloat is present (rough heuristic: >100k rows or >500MB)
            $has_bloat = $total_rows > 100000 || $total_size_mb > 500;
            ?>
            <div class="database-health" style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #eee;">
                <h4 style="margin-bottom: 10px;">Database Health</h4>
                <table class="widefat">
                    <tr>
                        <td><strong>File Records:</strong></td>
                        <td><?php echo number_format( $total_rows ); ?> rows</td>
                    </tr>
                    <tr>
                        <td><strong>Table Size:</strong></td>
                        <td>
                            <?php echo number_format( $total_size_mb, 2 ); ?> MB
                            <?php if ( $has_bloat ): ?>
                                <span class="status-badge status-failed" style="margin-left: 5px; font-size: 11px;">High</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                <?php if ( $has_bloat ): ?>
                    <p style="margin-top: 10px; padding: 8px; background: #fff3cd; border-left: 3px solid #ffc107; font-size: 12px;">
                        <strong>Bloat Detected:</strong> Consider running cleanup to optimize database size.
                        <?php if ( defined( 'WP_CLI' ) ): ?>
                            Run: <code>wp 84em integrity analyze-bloat</code>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>

            <div class="widget-actions">
                <p>
                    <a href="<?php echo admin_url( 'admin.php?page=file-integrity-checker' ); ?>" class="button">
                        Dashboard
                    </a>
                    <a href="<?php echo admin_url( 'admin.php?page=file-integrity-checker-results' ); ?>" class="button">
                        All Results
                    </a>
                </p>
            </div>
        </div>

        <style>
        .file-integrity-widget .status-badge {
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .file-integrity-widget .status-completed {
            background: #d1f2a5;
            color: #23881f;
        }

        .file-integrity-widget .status-failed {
            background: #f8d7da;
            color: #721c24;
        }

        .file-integrity-widget .status-running {
            background: #fff3cd;
            color: #856404;
        }

        .file-integrity-widget .changes-detected {
            color: #dc3545;
            font-weight: bold;
        }

        .file-integrity-widget .no-changes {
            color: #28a745;
        }

        .file-integrity-widget .changes-breakdown {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .file-integrity-widget .changes-breakdown > span {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 12px;
        }

        .file-integrity-widget .changes-new {
            background: #d4edda;
            color: #155724;
        }

        .file-integrity-widget .changes-modified {
            background: #fff3cd;
            color: #856404;
        }

        .file-integrity-widget .changes-deleted {
            background: #f8d7da;
            color: #721c24;
        }

        .file-integrity-widget .changes-breakdown .dashicons {
            font-size: 14px;
            width: 14px;
            height: 14px;
            line-height: 14px;
        }

        .file-integrity-widget .widget-alert {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-left: 4px solid #f39c12;
            padding: 8px 12px;
            margin: 10px 0;
        }

        .file-integrity-widget .widget-alert .dashicons {
            color: #f39c12;
            margin-right: 5px;
        }

        .file-integrity-widget .stats-grid {
            display: flex;
            gap: 15px;
            margin: 10px 0;
        }

        .file-integrity-widget .stat-item {
            text-align: center;
            flex: 1;
        }

        .file-integrity-widget .stat-number {
            font-size: 20px;
            font-weight: bold;
            color: #23282d;
        }

        .file-integrity-widget .stat-number.error {
            color: #dc3545;
        }

        .file-integrity-widget .stat-label {
            font-size: 11px;
            color: #666;
            text-transform: uppercase;
        }

        .file-integrity-widget .scan-list {
            margin: 0;
        }

        .file-integrity-widget .scan-list li {
            display: flex;
            align-items: center;
            padding: 5px 0;
            border-bottom: 1px solid #eee;
            gap: 10px;
        }

        .file-integrity-widget .scan-list li:last-child {
            border-bottom: none;
        }

        .file-integrity-widget .scan-date {
            font-size: 12px;
            color: #666;
            min-width: 60px;
        }

        .file-integrity-widget .changes-count {
            font-size: 11px;
            color: #dc3545;
        }

        .file-integrity-widget .scan-link {
            margin-left: auto;
            font-size: 11px;
        }

        .file-integrity-widget .widget-actions {
            border-top: 1px solid #eee;
            padding-top: 10px;
            margin-top: 15px;
        }
        </style>
        <?php
    }
}
