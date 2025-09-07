<?php
/**
 * Scan details view template
 *
 * @var array $scan_summary Detailed scan information
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use EightyFourEM\FileIntegrityChecker\Plugin;

$plugin = Plugin::getInstance();
$fileRecordRepository = $plugin->getContainer()->get( \EightyFourEM\FileIntegrityChecker\Database\FileRecordRepository::class );
$settingsService = $plugin->getContainer()->get( \EightyFourEM\FileIntegrityChecker\Services\SettingsService::class );
$fileAccessSecurity = $plugin->getContainer()->get( \EightyFourEM\FileIntegrityChecker\Security\FileAccessSecurity::class );

// Get changed files for this scan
$changed_files = $fileRecordRepository->getChangedFiles( $scan_summary['scan_id'] );

// Pagination for file records
$files_page = (int) ( $_GET['files_page'] ?? 1 );
$files_per_page = 50;
// Default to 'changed' if no filter is specified
$status_filter = isset( $_GET['status_filter'] ) ? sanitize_text_field( $_GET['status_filter'] ) : 'changed';

$file_results = $fileRecordRepository->getPaginated( 
    $scan_summary['scan_id'], 
    $files_page, 
    $files_per_page, 
    $status_filter 
);

$files_total_pages = ceil( $file_results['total_count'] / $files_per_page );
?>

<div class="wrap">
    <h1>
        Scan Details #<?php echo esc_html( $scan_summary['scan_id'] ); ?>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=file-integrity-checker-results' ) ); ?>" class="page-title-action">
            &larr; Back to Results
        </a>
    </h1>

    <!-- Scan Summary -->
    <div class="file-integrity-dashboard" style="margin-bottom: 30px;">
        
        <!-- Basic Info Card -->
        <div class="file-integrity-card">
            <h3>Scan Information</h3>
            <div class="card-content">
                <table class="widefat">
                    <tr>
                        <td><strong>Date:</strong></td>
                        <td><?php 
                            // Use mysql2date for proper timezone handling
                            echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $scan_summary['scan_date'] ) ); 
                        ?></td>
                    </tr>
                    <tr>
                        <td><strong>Status:</strong></td>
                        <td>
                            <span class="status-badge status-<?php echo esc_attr( $scan_summary['status'] ); ?>">
                                <?php echo esc_html( ucfirst( $scan_summary['status'] ) ); ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Type:</strong></td>
                        <td><?php echo esc_html( ucfirst( $scan_summary['scan_type'] ) ); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Duration:</strong></td>
                        <td><?php echo esc_html( number_format( $scan_summary['duration'] ) ); ?> seconds</td>
                    </tr>
                    <tr>
                        <td><strong>Memory Usage:</strong></td>
                        <td><?php echo esc_html( size_format( $scan_summary['memory_usage'] ) ); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Total Size:</strong></td>
                        <td><?php echo esc_html( size_format( $scan_summary['total_size'] ) ); ?></td>
                    </tr>
                </table>
                
                <?php if ( ! empty( $scan_summary['notes'] ) ): ?>
                <div style="margin-top: 15px;">
                    <strong>Notes:</strong><br>
                    <em><?php echo esc_html( $scan_summary['notes'] ); ?></em>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Statistics Card -->
        <div class="file-integrity-card">
            <h3>File Statistics</h3>
            <div class="card-content">
                <div class="stats-grid">
                    <div class="stat-item">
                        <span class="stat-number"><?php echo esc_html( number_format( $scan_summary['total_files'] ) ); ?></span>
                        <span class="stat-label">Total Files</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number success"><?php echo esc_html( number_format( $scan_summary['total_files'] - $scan_summary['changed_files'] - $scan_summary['new_files'] - $scan_summary['deleted_files'] ) ); ?></span>
                        <span class="stat-label">Unchanged</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number <?php echo esc_attr( $scan_summary['changed_files'] > 0 ? 'warning' : '' ); ?>"><?php echo esc_html( number_format( $scan_summary['changed_files'] ) ); ?></span>
                        <span class="stat-label">Changed</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number <?php echo esc_attr( $scan_summary['new_files'] > 0 ? 'warning' : '' ); ?>"><?php echo esc_html( number_format( $scan_summary['new_files'] ) ); ?></span>
                        <span class="stat-label">New Files</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number <?php echo esc_attr( $scan_summary['deleted_files'] > 0 ? 'error' : '' ); ?>"><?php echo esc_html( number_format( $scan_summary['deleted_files'] ) ); ?></span>
                        <span class="stat-label">Deleted</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Actions Card -->
        <div class="file-integrity-card">
            <h3>Actions</h3>
        <div class="card-content">
            <div class="card-actions" style="display: flex; flex-direction: column; gap: 10px;">
                <?php 
                // Check if there are changes to notify about
                $has_changes = $scan_summary['changed_files'] > 0 || $scan_summary['new_files'] > 0 || $scan_summary['deleted_files'] > 0;
                
                // Check if email notifications are enabled
                $email_enabled = $settingsService->isNotificationEnabled() && $has_changes;
                
                // Check if Slack notifications are enabled
                $slack_enabled = $settingsService->isSlackEnabled() && 
                                !empty( $settingsService->getSlackWebhookUrl() ) && 
                                $has_changes;
                ?>
                
                <?php if ( $email_enabled ): ?>
                <button type="button" class="button button-secondary resend-email-notification" 
                        data-scan-id="<?php echo esc_attr( $scan_summary['scan_id'] ); ?>"
                        style="width: 100%; justify-content: center;">
                    <span class="dashicons dashicons-email"></span>
                    Resend Email Notification
                </button>
                <?php endif; ?>
                
                <?php if ( $slack_enabled ): ?>
                <button type="button" class="button button-secondary resend-slack-notification" 
                        data-scan-id="<?php echo esc_attr( $scan_summary['scan_id'] ); ?>"
                        style="width: 100%; justify-content: center;">
                    <span class="dashicons dashicons-admin-comments"></span>
                    Resend Slack Notification
                </button>
                <?php endif; ?>
                
                <hr style="margin: 5px 0; border: none; border-top: 1px solid #ddd;">
                
                <button type="button" class="button button-link-delete delete-scan-details" 
                        data-scan-id="<?php echo esc_attr( $scan_summary['scan_id'] ); ?>"
                        style="width: 100%; justify-content: center; color: #d63638;">
                    <span class="dashicons dashicons-trash"></span>
                    Delete This Scan
                </button>
            </div>
            
            <?php if ( ! $has_changes && ( $settingsService->isNotificationEnabled() || $settingsService->isSlackEnabled() ) ): ?>
            <p class="description" style="margin-top: 10px;">
                <em>Notifications are only available for scans with detected changes.</em>
            </p>
            <?php endif; ?>
        </div>
    </div>

    </div>

    <?php if ( ! empty( $changed_files ) ): ?>
    <!-- Changed Files Alert -->
    <div class="file-integrity-alert alert-warning">
        <span class="dashicons dashicons-warning"></span>
        <span>
            <strong><?php echo esc_html( number_format( count( $changed_files ) ) ); ?> file(s) have changes.</strong>
            Review these files to ensure they are legitimate modifications.
        </span>
    </div>

    <!-- Quick Summary of Changes -->
    <div class="file-integrity-card" style="margin-bottom: 20px;">
        <h3>Change Summary</h3>
        <div class="card-content">
            <ul>
                <?php
                $change_counts = [];
                foreach ( $changed_files as $file ) {
                    $change_counts[ $file->status ] = ( $change_counts[ $file->status ] ?? 0 ) + 1;
                }
                
                foreach ( $change_counts as $status => $count ):
                ?>
                    <li>
                        <span class="status-badge status-<?php echo esc_attr( $status ); ?>">
                            <?php echo esc_html( ucfirst( $status ) ); ?>
                        </span>
                        <?php echo esc_html( number_format( $count ) ); ?> file(s)
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <!-- File Records -->
    <div class="file-integrity-card">
        <h3>
            File Records
            <?php if ( $file_results['total_count'] > 0 ): ?>
                <span style="font-weight: normal; font-size: 14px;">
                    (<?php echo esc_html( number_format( $file_results['total_count'] ) ); ?> total)
                </span>
            <?php endif; ?>
        </h3>
        
        <!-- Filter Controls -->
        <div style="margin: 15px 0;">
            <form method="get" style="display: inline-block;">
                <input type="hidden" name="page" value="file-integrity-checker-results" />
                <input type="hidden" name="scan_id" value="<?php echo esc_attr( $scan_summary['scan_id'] ); ?>" />
                <select name="status_filter" onchange="this.form.submit()">
                    <option value="all" <?php selected( $status_filter, 'all' ); ?>>All Files</option>
                    <option value="changed" <?php selected( $status_filter, 'changed' ); ?>>Changed Files</option>
                    <option value="new" <?php selected( $status_filter, 'new' ); ?>>New Files</option>
                    <option value="deleted" <?php selected( $status_filter, 'deleted' ); ?>>Deleted Files</option>
                    <option value="unchanged" <?php selected( $status_filter, 'unchanged' ); ?>>Unchanged Files</option>
                </select>
            </form>
        </div>
        
        <div class="card-content">
            <?php if ( empty( $file_results['results'] ) ): ?>
                <p>No files found<?php echo $status_filter ? esc_html( ' with status "' . $status_filter . '"' ) : ''; ?>.</p>
            <?php else: ?>
                
                <!-- Files Table -->
                <table class="scan-results-table widefat striped">
                    <thead>
                        <tr>
                            <th style="width: 60%;">File Path</th>
                            <th style="width: 12%;">Status</th>
                            <th style="width: 12%;">Size</th>
                            <th style="width: 16%;">Last Modified</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $file_results['results'] as $file ): ?>
                        <tr>
                            <td>
                                <div class="file-path"><?php echo esc_html( $file->file_path ); ?></div>
                                <?php if ( ! empty( $file->checksum ) ): ?>
                                    <div class="file-checksum" title="Current checksum">
                                        <?php echo esc_html( substr( $file->checksum, 0, 16 ) ); ?>...
                                    </div>
                                <?php endif; ?>
                                <?php if ( ! empty( $file->previous_checksum ) && $file->previous_checksum !== $file->checksum ): ?>
                                    <div class="file-checksum" title="Previous checksum" style="color: #999;">
                                        Was: <?php echo esc_html( substr( $file->previous_checksum, 0, 16 ) ); ?>...
                                    </div>
                                <?php endif; ?>
                                <?php 
                                // Check if file is blocked for security reasons
                                $security_check = $fileAccessSecurity->isFileAccessible( $file->file_path );
                                $is_blocked = ! $security_check['allowed'];
                                ?>
                                
                                <?php if ( $is_blocked ): ?>
                                    <?php if ( $file->status === 'changed' ): ?>
                                        <div style="margin-top: 5px; padding: 8px 12px; background: #f0f0f1; border-left: 3px solid #72aee6; border-radius: 2px;">
                                            <span class="dashicons dashicons-lock" style="color: #72aee6; vertical-align: middle;"></span>
                                            <span style="color: #50575e; font-size: 13px;">
                                                This file contains sensitive information and cannot be viewed for security reasons
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php if ( ! empty( $file->diff_content ) && $file->status === 'changed' ): ?>
                                        <button type="button" 
                                                class="button button-small view-diff-btn" 
                                                data-file-path="<?php echo esc_attr( $file->file_path ); ?>"
                                                data-diff="<?php echo esc_attr( json_encode( $file->diff_content ) ); ?>"
                                                style="margin-top: 5px;">
                                            <span class="dashicons dashicons-visibility"></span> View Changes
                                        </button>
                                    <?php endif; ?>
                                    <?php if ( $file->status !== 'deleted' ): ?>
                                        <button type="button" 
                                                class="button button-small view-file-btn" 
                                                data-file-path="<?php echo esc_attr( $file->file_path ); ?>"
                                                data-scan-id="<?php echo esc_attr( $scan_summary['scan_id'] ); ?>"
                                                style="margin-top: 5px; margin-left: 5px;">
                                            <span class="dashicons dashicons-media-code"></span> View File
                                        </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge status-<?php echo esc_attr( $file->status ); ?>">
                                    <?php echo esc_html( ucfirst( $file->status ) ); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ( $file->status !== 'deleted' ): ?>
                                    <?php echo esc_html( size_format( $file->file_size ) ); ?>
                                <?php else: ?>
                                    <span style="color: #999;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $file->last_modified ) ); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Files Pagination -->
                <?php if ( $files_total_pages > 1 ): ?>
                <div class="file-integrity-pagination">
                    <div class="pagination-info">
                        Showing page <?php echo esc_html( $files_page ); ?> of <?php echo esc_html( $files_total_pages ); ?>
                        (<?php echo esc_html( number_format( $file_results['total_count'] ) ); ?> files)
                    </div>
                    
                    <div class="pagination-links">
                        <?php 
                        $base_url = admin_url( 'admin.php?page=file-integrity-checker-results&scan_id=' . $scan_summary['scan_id'] );
                        if ( $status_filter ) {
                            $base_url .= '&status_filter=' . urlencode( $status_filter );
                        }
                        ?>
                        
                        <?php if ( $files_page > 1 ): ?>
                            <a href="<?php echo esc_url( $base_url . '&files_page=1' ); ?>" class="button">First</a>
                            <a href="<?php echo esc_url( $base_url . '&files_page=' . ( $files_page - 1 ) ); ?>" class="button">Previous</a>
                        <?php endif; ?>
                        
                        <span class="button button-disabled">Page <?php echo esc_html( $files_page ); ?></span>
                        
                        <?php if ( $files_page < $files_total_pages ): ?>
                            <a href="<?php echo esc_url( $base_url . '&files_page=' . ( $files_page + 1 ) ); ?>" class="button">Next</a>
                            <a href="<?php echo esc_url( $base_url . '&files_page=' . $files_total_pages ); ?>" class="button">Last</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

            <?php endif; ?>
        </div>
    </div>

    <!-- Action Buttons -->
    <div style="margin-top: 20px;">
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=file-integrity-checker-results' ) ); ?>" class="button button-primary">
            <span class="dashicons dashicons-arrow-left-alt"></span>
            Back to All Results
        </a>
        
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=file-integrity-checker' ) ); ?>" class="button">
            <span class="dashicons dashicons-dashboard"></span>
            Dashboard
        </a>
    </div>

</div>

<!-- Diff Modal HTML -->
<div id="diff-modal" class="fic-diff-modal" style="display: none;">
    <div class="fic-diff-modal-overlay"></div>
    <div class="fic-diff-modal-content">
        <div class="fic-diff-modal-header">
            <h3 id="diff-modal-title">File Changes</h3>
            <button type="button" class="fic-diff-modal-close">
                <span class="dashicons dashicons-no-alt"></span>
            </button>
        </div>
        <div class="fic-diff-modal-body">
            <div id="diff-content"></div>
        </div>
        <div class="fic-modal-footer">
            <button type="button" class="button fic-diff-modal-close-btn">Close</button>
        </div>
    </div>
</div>

<style>
.fic-diff-modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 100000;
}

.fic-diff-modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.7);
}

.fic-diff-modal-content {
    position: relative;
    background: white;
    max-width: 90%;
    max-height: 80%;
    margin: 5% auto;
    border-radius: 4px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
    display: flex;
    flex-direction: column;
}

.fic-diff-modal-header {
    padding: 15px 20px;
    border-bottom: 1px solid #ddd;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.fic-diff-modal-header h3 {
    margin: 0;
}

.fic-diff-modal-close {
    background: none;
    border: none;
    cursor: pointer;
    padding: 0;
    font-size: 20px;
    color: #666;
}

.fic-diff-modal-close:hover {
    color: #000;
}

.fic-diff-modal-body {
    padding: 20px;
    overflow-y: auto;
    flex-grow: 1;
}

.diff-info {
    margin-bottom: 15px;
    padding: 10px;
    background: #f0f0f0;
    border-radius: 4px;
}

.diff-preview {
    font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
    background: #f8f8f8;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
    white-space: pre;
    overflow-x: auto;
    font-size: 13px;
    line-height: 1.4;
}

/* Diff syntax highlighting */
.diff-header {
    color: #666;
    font-weight: bold;
}

.diff-hunk {
    color: #00a0a0;
    background: #f0f0f0;
    display: inline-block;
    width: 100%;
    padding: 2px 5px;
    margin: 5px 0;
}

.diff-added {
    color: #008000;
    background: #e6ffed;
    display: inline-block;
    width: 100%;
}

.diff-removed {
    color: #d00;
    background: #ffebe9;
    display: inline-block;
    width: 100%;
}

.diff-context {
    color: #666;
}

.diff-section {
    margin: 15px 0;
}

.diff-section h4 {
    margin: 10px 0 5px;
    color: #333;
}
</style>

<script>
jQuery(document).ready(function($) {
    // Handle view diff button clicks
    $('.view-diff-btn').on('click', function() {
        var filePath = $(this).data('file-path');
        var diffData = $(this).data('diff');
        
        // The data is JSON encoded, so parse it first to get the actual diff content
        try {
            // First parse to get the actual diff string
            var actualDiff = typeof diffData === 'string' ? JSON.parse(diffData) : diffData;
            
            // Then check if the diff itself is JSON (for summary format)
            try {
                var parsedDiff = typeof actualDiff === 'string' ? JSON.parse(actualDiff) : actualDiff;
                showDiffModal(filePath, parsedDiff);
            } catch (e) {
                // Not JSON, it's a plain diff string
                showDiffModal(filePath, actualDiff);
            }
        } catch (e) {
            // Fallback if parsing fails
            console.error('Failed to parse diff data:', e);
            showDiffModal(filePath, diffData);
        }
    });
    
    // Function to show diff modal
    function showDiffModal(filePath, diffData) {
        $('#diff-modal-title').text('Changes in: ' + filePath);
        
        var content = '';
        
        // Check if it's a unified diff (contains diff markers)
        if (typeof diffData === 'string' && (diffData.includes('---') || diffData.includes('+++') || diffData.includes('@@'))) {
            // Unified diff format
            content = '<div class="diff-content">';
            content += '<pre class="diff-preview">' + formatDiff(diffData) + '</pre>';
            content += '</div>';
        } else if (typeof diffData === 'object' && diffData !== null) {
            // Structured diff data (fallback for when we can't get the previous version)
            if (diffData.type === 'summary') {
                content += '<div class="diff-info">';
                if (diffData.message) {
                    content += '<div class="notice notice-warning" style="padding: 10px; margin-bottom: 15px;">';
                    content += '<strong>' + escapeHtml(diffData.message) + '</strong>';
                    content += '</div>';
                }
                content += '<strong>Timestamp:</strong> ' + (diffData.timestamp || 'Unknown') + '<br>';
                content += '<strong>File Size:</strong> ' + formatBytes(diffData.file_size || 0) + '<br>';
                content += '<strong>Lines:</strong> ' + (diffData.lines_count || 'Unknown') + '<br>';
                
                if (diffData.checksum_changed) {
                    content += '<strong>Checksum Changed:</strong><br>';
                    content += '<span style="color: #d00;">- From: ' + (diffData.checksum_changed.from || 'N/A').substring(0, 32) + '...</span><br>';
                    content += '<span style="color: #0a0;">+ To: ' + (diffData.checksum_changed.to || 'N/A').substring(0, 32) + '...</span>';
                }
                content += '</div>';
            }
        } else {
            // Plain text (simple diff)
            content = '<div class="diff-content">';
            content += '<pre class="diff-preview">' + formatDiff(String(diffData)) + '</pre>';
            content += '</div>';
        }
        
        $('#diff-content').html(content);
        $('#diff-modal').fadeIn(200);
    }
    
    // Format diff output with syntax highlighting
    function formatDiff(diff) {
        if (!diff) return '';
        
        var lines = diff.split('\n');
        var formatted = [];
        
        lines.forEach(function(line) {
            var escapedLine = escapeHtml(line);
            
            if (line.startsWith('+++') || line.startsWith('---')) {
                // File headers
                formatted.push('<span class="diff-header">' + escapedLine + '</span>');
            } else if (line.startsWith('@@')) {
                // Hunk headers
                formatted.push('<span class="diff-hunk">' + escapedLine + '</span>');
            } else if (line.startsWith('+')) {
                // Added lines
                formatted.push('<span class="diff-added">' + escapedLine + '</span>');
            } else if (line.startsWith('-')) {
                // Removed lines
                formatted.push('<span class="diff-removed">' + escapedLine + '</span>');
            } else if (line.startsWith(' ')) {
                // Context lines
                formatted.push('<span class="diff-context">' + escapedLine + '</span>');
            } else {
                // Other lines
                formatted.push(escapedLine);
            }
        });
        
        return formatted.join('\n');
    }
    
    // Close modal handlers
    $('.fic-diff-modal-close, .fic-diff-modal-close-btn, .fic-diff-modal-overlay').on('click', function() {
        $('#diff-modal').fadeOut(200);
    });
    
    // ESC key to close
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape' && $('#diff-modal').is(':visible')) {
            $('#diff-modal').fadeOut(200);
        }
    });
    
    // Helper functions
    function formatBytes(bytes) {
        if (bytes === 0) return '0 Bytes';
        var k = 1024;
        var sizes = ['Bytes', 'KB', 'MB', 'GB'];
        var i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }
    
    function escapeHtml(text) {
        var map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }
});
</script>