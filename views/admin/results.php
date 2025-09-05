<?php
/**
 * Results page view template
 *
 * @var array $results Paginated scan results
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$current_page = (int) ( $_GET['paged'] ?? 1 );
$per_page = 20;
$total_pages = ceil( $results['total_count'] / $per_page );
?>

<div class="wrap">
    <h1>Scan Results</h1>
    
    <?php if ( empty( $results['results'] ) ): ?>
        <div class="file-integrity-alert alert-info">
            <span class="dashicons dashicons-info"></span>
            <span>No scan results found. <a href="<?php echo admin_url( 'admin.php?page=file-integrity-checker' ); ?>">Run your first scan</a> to get started.</span>
        </div>
    <?php else: ?>
        
        <!-- Results Summary -->
        <div class="file-integrity-card" style="margin-bottom: 20px;">
            <h3>Results Overview</h3>
            <div class="card-content">
                <p>Showing <?php echo number_format( count( $results['results'] ) ); ?> of <?php echo number_format( $results['total_count'] ); ?> scan results.</p>
            </div>
        </div>

        <!-- Results Table -->
        <table class="scan-results-table widefat striped">
            <thead>
                <tr>
                    <th class="column-date">Date</th>
                    <th class="column-status">Status</th>
                    <th class="column-files">Total Files</th>
                    <th class="column-changes">Changes</th>
                    <th class="column-duration">Duration</th>
                    <th class="column-actions">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $results['results'] as $scan ): ?>
                <tr>
                    <td class="column-date">
                        <strong><?php echo esc_html( date( 'M j, Y', strtotime( $scan->scan_date ) ) ); ?></strong><br>
                        <small><?php echo esc_html( date( 'H:i:s', strtotime( $scan->scan_date ) ) ); ?></small>
                    </td>
                    <td class="column-status">
                        <span class="status-badge status-<?php echo esc_attr( $scan->status ); ?>">
                            <?php echo esc_html( ucfirst( $scan->status ) ); ?>
                        </span>
                        <?php if ( $scan->scan_type !== 'manual' ): ?>
                            <br><small><?php echo esc_html( ucfirst( $scan->scan_type ) ); ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="column-files">
                        <?php echo number_format( $scan->total_files ); ?>
                    </td>
                    <td class="column-changes">
                        <?php if ( $scan->status === 'completed' ): ?>
                            <?php 
                            $total_changes = $scan->changed_files + $scan->new_files + $scan->deleted_files;
                            if ( $total_changes > 0 ):
                            ?>
                                <span class="stat-number warning"><?php echo number_format( $total_changes ); ?></span>
                                <br>
                                <small>
                                    <?php if ( $scan->changed_files > 0 ): ?>
                                        <?php echo number_format( $scan->changed_files ); ?> changed
                                    <?php endif; ?>
                                    <?php if ( $scan->new_files > 0 ): ?>
                                        <?php echo $scan->changed_files > 0 ? ', ' : ''; ?><?php echo number_format( $scan->new_files ); ?> new
                                    <?php endif; ?>
                                    <?php if ( $scan->deleted_files > 0 ): ?>
                                        <?php echo ( $scan->changed_files > 0 || $scan->new_files > 0 ) ? ', ' : ''; ?><?php echo number_format( $scan->deleted_files ); ?> deleted
                                    <?php endif; ?>
                                </small>
                            <?php else: ?>
                                <span class="stat-number success">0</span>
                                <br><small>No changes</small>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="stat-number">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="column-duration">
                        <?php if ( $scan->scan_duration > 0 ): ?>
                            <?php echo esc_html( number_format( $scan->scan_duration ) ); ?>s
                            <?php if ( $scan->memory_usage > 0 ): ?>
                                <br><small><?php echo esc_html( size_format( $scan->memory_usage ) ); ?></small>
                            <?php endif; ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td class="column-actions">
                        <?php if ( $scan->status === 'completed' ): ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=file-integrity-checker-results&scan_id=' . $scan->id ) ); ?>" 
                               class="button button-small view-scan-details" data-scan-id="<?php echo esc_attr( $scan->id ); ?>">
                                View Details
                            </a>
                        <?php elseif ( $scan->status === 'failed' ): ?>
                            <span class="status-badge status-failed" title="<?php echo esc_attr( $scan->notes ); ?>">Failed</span>
                        <?php else: ?>
                            <span class="status-badge status-running">Running</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Pagination -->
        <?php if ( $total_pages > 1 ): ?>
        <div class="file-integrity-pagination">
            <div class="pagination-info">
                Showing page <?php echo $current_page; ?> of <?php echo $total_pages; ?>
                (<?php echo number_format( $results['total_count'] ); ?> total results)
            </div>
            
            <div class="pagination-links">
                <?php if ( $current_page > 1 ): ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=file-integrity-checker-results&paged=1' ) ); ?>" class="button">First</a>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=file-integrity-checker-results&paged=' . ( $current_page - 1 ) ) ); ?>" class="button">Previous</a>
                <?php endif; ?>
                
                <span class="button button-disabled">Page <?php echo $current_page; ?></span>
                
                <?php if ( $current_page < $total_pages ): ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=file-integrity-checker-results&paged=' . ( $current_page + 1 ) ) ); ?>" class="button">Next</a>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=file-integrity-checker-results&paged=' . $total_pages ) ); ?>" class="button">Last</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Action Buttons -->
        <div class="file-integrity-card" style="margin-top: 20px;">
            <h3>Actions</h3>
            <div class="card-actions">
                <a href="<?php echo admin_url( 'admin.php?page=file-integrity-checker' ); ?>" class="button button-primary">
                    <span class="dashicons dashicons-arrow-left-alt"></span>
                    Back to Dashboard
                </a>
                
                <form method="post" style="display: inline; margin-left: 10px;">
                    <?php wp_nonce_field( 'file_integrity_action' ); ?>
                    <input type="hidden" name="action" value="cleanup_old_scans" />
                    <button type="submit" class="button cleanup-old-scans" onclick="return confirm('Delete old scan results? This will remove scan data older than your configured retention period.')">
                        <span class="dashicons dashicons-trash"></span>
                        Cleanup Old Results
                    </button>
                </form>
            </div>
        </div>

    <?php endif; ?>
</div>