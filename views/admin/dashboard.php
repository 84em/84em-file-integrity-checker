<?php
/**
 * Dashboard view template
 *
 * @var array $stats Dashboard statistics
 * @var object|null $next_scan Next scheduled scan
 * @var bool $scheduler_available Whether Action Scheduler is available
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<div class="wrap">
    <h1>84EM File Integrity Checker</h1>

    <?php
    // Note: Scan completion messages are handled by JavaScript to avoid duplication
    // The JS checkScanCompletion() method in admin.js displays the success notice
    
    // Show other messages
    if ( isset( $_GET['message'] ) ) {
        switch ( $_GET['message'] ) {
            case 'scan_scheduled':
                echo '<div class="notice notice-success is-dismissible"><p>Scan scheduled successfully!</p></div>';
                break;
            case 'scans_cancelled':
                $count = (int) ( $_GET['count'] ?? 0 );
                echo "<div class='notice notice-success is-dismissible'><p>Cancelled $count scheduled scan(s).</p></div>";
                break;
            case 'cleanup_complete':
                $deleted = (int) ( $_GET['deleted'] ?? 0 );
                echo "<div class='notice notice-success is-dismissible'><p>Cleanup complete. Deleted $deleted old scan records.</p></div>";
                break;
        }
    }

    if ( isset( $_GET['error'] ) ) {
        switch ( $_GET['error'] ) {
            case 'schedule_failed':
                echo '<div class="notice notice-error is-dismissible"><p>Failed to schedule scan. Please check that Action Scheduler is available.</p></div>';
                break;
        }
    }
    ?>

    <!-- Scan Controls -->
    <div class="scan-controls">
        <form method="post" style="display: inline;">
            <?php wp_nonce_field( 'file_integrity_admin_action_run_scan' ); ?>
            <input type="hidden" name="action" value="run_scan" />
            <button type="submit" class="button button-primary button-large start-scan-btn">
                <span class="dashicons dashicons-search"></span>
                Run Scan Now
            </button>
        </form>
        
        <?php if ( $scheduler_available ): ?>
            <div class="scan-status">
                <?php if ( $next_scan ): ?>
                    <span class="dashicons dashicons-clock"></span>
                    Next scheduled scan: <strong><?php echo esc_html( $next_scan->time_until ); ?></strong>
                    <?php if ( isset( $next_scan->schedule_name ) ): ?>
                        (<?php echo esc_html( $next_scan->schedule_name ); ?>)
                    <?php endif; ?>
                <?php else: ?>
                    <span class="dashicons dashicons-warning"></span>
                    No scans are currently scheduled
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Dashboard Cards -->
    <div class="file-integrity-dashboard">
        
        <!-- Latest Scan Card -->
        <div class="file-integrity-card">
            <h3>Latest Scan</h3>
            <div class="card-content">
                <?php if ( $stats['latest_scan'] ): ?>
                    <table class="widefat">
                        <tr>
                            <td><strong>Date:</strong></td>
                            <td><?php echo esc_html( date( 'M j, Y H:i', strtotime( $stats['latest_scan']['date'] ) ) ); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Status:</strong></td>
                            <td>
                                <span class="status-badge status-<?php echo esc_attr( $stats['latest_scan']['status'] ); ?>">
                                    <?php echo esc_html( ucfirst( $stats['latest_scan']['status'] ) ); ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Files Scanned:</strong></td>
                            <td><?php echo number_format( $stats['latest_scan']['total_files'] ); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Changes:</strong></td>
                            <td>
                                <?php if ( $stats['latest_scan']['changed_files'] > 0 ): ?>
                                    <span class="stat-number warning"><?php echo number_format( $stats['latest_scan']['changed_files'] ); ?></span>
                                <?php else: ?>
                                    <span class="stat-number success">0</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                    
                    <?php if ( $stats['latest_scan']['changed_files'] > 0 ): ?>
                        <div class="file-integrity-alert alert-warning">
                            <span class="dashicons dashicons-warning"></span>
                            <span>Changes detected in your files!</span>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <p>No scans have been completed yet.</p>
                    <p><em>Run your first scan to establish a baseline.</em></p>
                <?php endif; ?>
            </div>
            
            <?php if ( $stats['latest_scan'] ): ?>
            <div class="card-actions">
                <a href="<?php echo admin_url( 'admin.php?page=file-integrity-checker-results&scan_id=' . $stats['latest_scan']['id'] ); ?>" class="button">
                    View Details
                </a>
            </div>
            <?php endif; ?>
        </div>

        <!-- Statistics Card -->
        <div class="file-integrity-card">
            <h3>Overview Statistics</h3>
            <div class="card-content">
                <div class="stats-grid">
                    <div class="stat-item">
                        <span class="stat-number"><?php echo number_format( $stats['total_scans'] ); ?></span>
                        <span class="stat-label">Total Scans</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number success"><?php echo number_format( $stats['completed_scans'] ); ?></span>
                        <span class="stat-label">Completed</span>
                    </div>
                    <?php if ( $stats['failed_scans'] > 0 ): ?>
                    <div class="stat-item">
                        <span class="stat-number error"><?php echo number_format( $stats['failed_scans'] ); ?></span>
                        <span class="stat-label">Failed</span>
                    </div>
                    <?php endif; ?>
                    <div class="stat-item">
                        <span class="stat-number <?php echo $stats['total_changed_files'] > 0 ? 'warning' : 'success'; ?>">
                            <?php echo number_format( $stats['total_changed_files'] ); ?>
                        </span>
                        <span class="stat-label">Total Changes</span>
                    </div>
                </div>
                
                <?php if ( $stats['avg_scan_duration'] > 0 ): ?>
                <p><strong>Average scan time:</strong> <?php echo number_format( $stats['avg_scan_duration'], 1 ); ?> seconds</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Scheduling Card -->
        <?php if ( $scheduler_available ): ?>
        <div class="file-integrity-card">
            <h3>Scheduled Scans</h3>
            <div class="card-content">
                <?php 
                // Get schedule statistics
                $schedule_stats = $scheduler_service->getScheduleStats();
                ?>
                <?php if ( $schedule_stats['active'] > 0 ): ?>
                    <p><strong>Active Schedules:</strong> <span class="status-badge status-completed"><?php echo esc_html( $schedule_stats['active'] ); ?></span></p>
                    <?php if ( $next_scan ): ?>
                        <p><strong>Next scan:</strong> <?php echo esc_html( $next_scan->time_until ); ?></p>
                    <?php endif; ?>
                <?php else: ?>
                    <p><strong>Status:</strong> <span class="status-badge status-failed">No Active Schedules</span></p>
                    <p>Set up automatic scanning to monitor your files regularly.</p>
                <?php endif; ?>
                
                <?php if ( $schedule_stats['total'] > 0 ): ?>
                    <p><strong>Total schedules:</strong> <?php echo esc_html( $schedule_stats['total'] ); ?></p>
                <?php endif; ?>
            </div>
            
            <div class="card-actions">
                <a href="<?php echo admin_url( 'admin.php?page=file-integrity-checker-schedules' ); ?>" class="button button-primary">
                    <span class="dashicons dashicons-calendar-alt"></span>
                    Manage Schedules
                </a>
            </div>
        </div>
        <?php else: ?>
        <!-- Action Scheduler Not Available -->
        <div class="file-integrity-card">
            <h3>Scheduled Scans</h3>
            <div class="card-content">
                <div class="file-integrity-alert alert-warning">
                    <span class="dashicons dashicons-warning"></span>
                    <span>Action Scheduler is required for scheduled scans. Please install WooCommerce or another plugin that provides Action Scheduler.</span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Quick Actions Card -->
        <div class="file-integrity-card">
            <h3>Quick Actions</h3>
            <div class="card-content">
                <p>Manage your file integrity monitoring.</p>
            </div>
            
            <div class="card-actions">
                <a href="<?php echo admin_url( 'admin.php?page=file-integrity-checker-results' ); ?>" class="button">
                    <span class="dashicons dashicons-list-view"></span>
                    View All Results
                </a>
                <br><br>
                <a href="<?php echo admin_url( 'admin.php?page=file-integrity-checker-settings' ); ?>" class="button">
                    <span class="dashicons dashicons-admin-settings"></span>
                    Settings
                </a>
                <br><br>
                <form method="post" style="display: inline;">
                    <?php wp_nonce_field( 'file_integrity_admin_action_cleanup_old_scans' ); ?>
                    <input type="hidden" name="action" value="cleanup_old_scans" />
                    <button type="submit" class="button cleanup-old-scans" onclick="event.preventDefault(); FICModal.confirm('Delete old scan results? This cannot be undone.', 'Delete Old Scans', 'Yes, Delete', 'Cancel').then(confirmed => { if(confirmed) this.form.submit(); }); return false;">
                        <span class="dashicons dashicons-trash"></span>
                        Cleanup Old Scans
                    </button>
                </form>
            </div>
        </div>
        
    </div>

    <!-- Quick Start Guide -->
    <?php if ( $stats['total_scans'] === 0 ): ?>
    <div class="file-integrity-card" style="margin-top: 20px;">
        <h3>Getting Started</h3>
        <div class="card-content">
            <ol>
                <li><strong>Run your first scan</strong> - Click "Run Scan Now" to establish a baseline of your files.</li>
                <li><strong>Review the results</strong> - Check what files were found and their current checksums.</li>
                <li><strong>Set up scheduling</strong> - Configure automatic scans to monitor changes over time.</li>
                <li><strong>Configure notifications</strong> - Get alerted when file changes are detected.</li>
            </ol>
        </div>
    </div>
    <?php endif; ?>
</div>