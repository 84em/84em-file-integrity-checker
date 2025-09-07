<?php
/**
 * Settings page view template
 *
 * @var array $settings Plugin settings
 * @var bool $scheduler_available Whether Action Scheduler is available
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<div class="wrap file-integrity-settings">
    <h1>File Integrity Checker Settings</h1>

    <?php
    // Show messages
    if ( isset( $_GET['message'] ) ) {
        switch ( $_GET['message'] ) {
            case 'settings_updated':
                echo '<div class="notice notice-success is-dismissible"><p>Settings updated successfully!</p></div>';
                break;
        }
    }

    if ( isset( $_GET['error'] ) ) {
        switch ( $_GET['error'] ) {
            case 'settings_update_failed':
                echo '<div class="notice notice-error is-dismissible"><p>Some settings could not be updated. Please check your input values.</p></div>';
                break;
        }
    }
    ?>

    <form method="post" action="">
        <?php wp_nonce_field( 'file_integrity_admin_action_update_settings' ); ?>
        <input type="hidden" name="action" value="update_settings" />

        <!-- Scan Configuration -->
        <div class="file-integrity-card" style="margin-bottom: 30px;">
            <h3>Scan Configuration</h3>
            <div class="card-content">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="scan_types">File Types to Scan</label>
                        </th>
                        <td>
                            <div class="file-extensions-list">
                                <?php 
                                $common_types = [ '.php', '.js', '.css', '.html', '.htm', '.txt', '.json', '.xml', '.ini', '.htaccess' ];
                                $selected_types = $settings['scan_types'];
                                
                                foreach ( $common_types as $type ):
                                    $checked = in_array( $type, $selected_types, true ) ? 'checked' : '';
                                ?>
                                <div class="file-extension-item">
                                    <input type="checkbox" 
                                           name="scan_types[]" 
                                           value="<?php echo esc_attr( $type ); ?>" 
                                           id="type_<?php echo esc_attr( str_replace( '.', '', $type ) ); ?>"
                                           class="file-extension-checkbox"
                                           <?php echo $checked; ?> />
                                    <label for="type_<?php echo esc_attr( str_replace( '.', '', $type ) ); ?>">
                                        <?php echo esc_html( $type ); ?>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <p class="description">
                                Select which file types to include in scans. Default types are recommended for security monitoring.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="exclude_patterns">Exclude Patterns</label>
                        </th>
                        <td>
                            <textarea name="exclude_patterns" 
                                      id="exclude_patterns" 
                                      rows="8" 
                                      placeholder="One pattern per line"><?php echo esc_textarea( implode( "\n", $settings['exclude_patterns'] ) ); ?></textarea>
                            <p class="description">
                                Glob patterns for files/directories to exclude from scans. One pattern per line.<br>
                                Examples: <code>*/cache/*</code>, <code>*/logs/*</code>, <code>*/node_modules/*</code>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="max_file_size_mb">Maximum File Size (MB)</label>
                        </th>
                        <td>
                            <input type="number" 
                                   name="max_file_size_mb" 
                                   id="max_file_size_mb" 
                                   value="<?php echo esc_attr( round( $settings['max_file_size'] / 1048576, 1 ) ); ?>" 
                                   min="1" 
                                   max="100"
                                   step="0.5" /> MB
                            <p class="description">
                                Maximum size of files to scan in megabytes. Files larger than this will be skipped.
                            </p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Scheduling Configuration -->
        <?php if ( $scheduler_available ): ?>
        <div class="file-integrity-card" style="margin-bottom: 30px;">
            <h3>Scheduling Configuration</h3>
            <div class="card-content">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="scan_interval">Default Scan Interval</label>
                        </th>
                        <td>
                            <select name="scan_interval" id="scan_interval">
                                <option value="hourly" <?php selected( $settings['scan_interval'], 'hourly' ); ?>>Hourly</option>
                                <option value="daily" <?php selected( $settings['scan_interval'], 'daily' ); ?>>Daily</option>
                                <option value="weekly" <?php selected( $settings['scan_interval'], 'weekly' ); ?>>Weekly</option>
                                <option value="monthly" <?php selected( $settings['scan_interval'], 'monthly' ); ?>>Monthly</option>
                            </select>
                            <p class="description">Default interval for scheduled scans. You can override this when scheduling.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="auto_schedule">Auto-Schedule on Activation</label>
                        </th>
                        <td>
                            <input type="checkbox" 
                                   name="auto_schedule" 
                                   id="auto_schedule" 
                                   value="1" 
                                   <?php checked( $settings['auto_schedule'] ); ?> />
                            <label for="auto_schedule">Automatically schedule scans when the plugin is activated</label>
                            <p class="description">When enabled, scans will be automatically scheduled using the default interval.</p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        <?php else: ?>
        <div class="file-integrity-card" style="margin-bottom: 30px;">
            <h3>Scheduling Configuration</h3>
            <div class="card-content">
                <div class="file-integrity-alert alert-warning">
                    <span class="dashicons dashicons-warning"></span>
                    <span>Action Scheduler is required for scheduled scans. Please install WooCommerce or another plugin that provides Action Scheduler to enable scheduling features.</span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Notification Settings -->
        <div class="file-integrity-card" style="margin-bottom: 30px;">
            <h3>Notification Settings</h3>
            <div class="card-content">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label>Notification Methods</label>
                        </th>
                        <td>
                            <fieldset>
                                <label style="display: block; margin-bottom: 10px;">
                                    <input type="checkbox" 
                                           name="notification_enabled" 
                                           id="notification_enabled" 
                                           value="1" 
                                           <?php checked( $settings['notification_enabled'] ); ?> />
                                    Enable email notifications
                                </label>
                                <label style="display: block;">
                                    <input type="checkbox" 
                                           name="slack_enabled" 
                                           id="slack_enabled" 
                                           value="1" 
                                           <?php checked( $settings['slack_enabled'] ); ?> />
                                    Enable Slack notifications
                                </label>
                            </fieldset>
                            <p class="description">Choose how you want to be notified when file changes are detected.</p>
                        </td>
                    </tr>
                    <tr class="email-notification-row" <?php echo $settings['notification_enabled'] ? '' : 'style="display:none;"'; ?>>
                        <th scope="row">
                            <label for="notification_email">Email Address</label>
                        </th>
                        <td>
                            <input type="email" 
                                   name="notification_email" 
                                   id="notification_email" 
                                   value="<?php echo esc_attr( $settings['notification_email'] ); ?>" 
                                   class="regular-text" />
                            <p class="description">Email address to receive notifications. Leave empty to use the admin email.</p>
                        </td>
                    </tr>
                    <tr class="slack-notification-row" <?php echo $settings['slack_enabled'] ? '' : 'style="display:none;"'; ?>>
                        <th scope="row">
                            <label for="slack_webhook_url">Slack Webhook URL</label>
                        </th>
                        <td>
                            <input type="url" 
                                   name="slack_webhook_url" 
                                   id="slack_webhook_url" 
                                   value="<?php echo esc_attr( $settings['slack_webhook_url'] ); ?>" 
                                   class="regular-text code"
                                   placeholder="https://hooks.slack.com/services/..." />
                            <p class="description">
                                Enter your Slack webhook URL. 
                                <a href="https://api.slack.com/messaging/webhooks" target="_blank">Learn how to create a webhook</a>
                            </p>
                            <?php if ( $settings['slack_enabled'] && ! empty( $settings['slack_webhook_url'] ) ): ?>
                            <button type="button" class="button button-secondary" id="test-slack-notification" style="margin-top: 10px;">
                                Test Slack Connection
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Data Management -->
        <div class="file-integrity-card" style="margin-bottom: 30px;">
            <h3>Data Management</h3>
            <div class="card-content">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="retention_period">Data Retention Period</label>
                        </th>
                        <td>
                            <input type="number" 
                                   name="retention_period" 
                                   id="retention_period" 
                                   value="<?php echo esc_attr( $settings['retention_period'] ); ?>" 
                                   min="1" 
                                   max="365" />
                            days
                            <p class="description">
                                How long to keep old scan results. Older results will be automatically deleted.
                                Recommended: 90 days.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="content_retention_limit">File Content History Limit</label>
                        </th>
                        <td>
                            <input type="number" 
                                   name="content_retention_limit" 
                                   id="content_retention_limit" 
                                   value="<?php echo esc_attr( $settings['content_retention_limit'] ?? 50000 ); ?>" 
                                   min="1000" 
                                   max="500000" 
                                   step="1000" />
                            entries
                            <p class="description">
                                Maximum number of file content versions to store for diff generation.
                                Higher values allow more historical comparisons but use more database space.
                                Recommended: 50,000 entries.
                            </p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Logging Configuration -->
        <div class="file-integrity-card" style="margin-bottom: 30px;">
            <h3>Logging Configuration</h3>
            <div class="card-content">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label>Log Levels to Record</label>
                        </th>
                        <td>
                            <?php 
                            $enabled_levels = $settings['log_levels'] ?? [ 'success', 'error', 'warning', 'info' ];
                            $all_levels = [
                                'success' => 'Success',
                                'error' => 'Error',
                                'warning' => 'Warning',
                                'info' => 'Info',
                                'debug' => 'Debug'
                            ];
                            ?>
                            <fieldset>
                                <?php foreach ( $all_levels as $level => $label ) : ?>
                                    <label style="display: block; margin-bottom: 5px;">
                                        <input type="checkbox" 
                                               name="log_levels[]" 
                                               value="<?php echo esc_attr( $level ); ?>"
                                               <?php checked( in_array( $level, $enabled_levels ) ); ?> />
                                        <?php echo esc_html( $label ); ?>
                                    </label>
                                <?php endforeach; ?>
                            </fieldset>
                            <p class="description">Select which types of log messages to record in the database.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="log_retention_days">Log Retention Period</label>
                        </th>
                        <td>
                            <input type="number" 
                                   name="log_retention_days" 
                                   id="log_retention_days" 
                                   value="<?php echo esc_attr( $settings['log_retention_days'] ?? 30 ); ?>" 
                                   min="1" 
                                   max="365" /> days
                            <p class="description">
                                Automatically delete logs older than this many days. Set to 0 to disable auto-deletion.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="auto_log_cleanup">Auto Log Cleanup</label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" 
                                       name="auto_log_cleanup" 
                                       id="auto_log_cleanup" 
                                       value="1" 
                                       <?php checked( $settings['auto_log_cleanup'] ?? true ); ?> />
                                Enable automatic daily cleanup of old logs
                            </label>
                            <p class="description">
                                Runs daily at 3 AM to remove logs older than the retention period.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="debug_mode">Debug Mode</label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" 
                                       name="debug_mode" 
                                       id="debug_mode" 
                                       value="1" 
                                       <?php checked( $settings['debug_mode'] ?? false ); ?> />
                                Enable debug logging
                            </label>
                            <p class="description">
                                Records additional debug information. Only enable when troubleshooting issues.
                            </p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Submit Button -->
        <p class="submit">
            <input type="submit" name="submit" id="submit" class="button button-primary" value="Save Settings" />
            <a href="<?php echo admin_url( 'admin.php?page=file-integrity-checker' ); ?>" class="button">
                Cancel
            </a>
        </p>
    </form>

    <!-- Additional Actions -->
    <div class="file-integrity-card">
        <h3>Advanced Actions</h3>
        <div class="card-content">
            <p>These actions affect all plugin data and cannot be undone.</p>
            
            <div style="margin: 20px 0;">
                <form method="post" style="display: inline;">
                    <?php wp_nonce_field( 'file_integrity_admin_action_cleanup_old_scans' ); ?>
                    <input type="hidden" name="action" value="cleanup_old_scans" />
                    <button type="submit" class="button cleanup-old-scans" 
                            onclick="event.preventDefault(); FICModal.confirm('This will delete scan results older than <?php echo $settings['retention_period']; ?> days. Continue?', 'Delete Old Scans', 'Yes, Delete', 'Cancel').then(confirmed => { if(confirmed) this.form.submit(); }); return false;">
                        <span class="dashicons dashicons-trash"></span>
                        Cleanup Old Scans Now
                    </button>
                </form>
                <p class="description">Manually remove old scan results based on the retention period above.</p>
            </div>
            
            <div style="margin: 20px 0;">
                <button type="button" class="button" onclick="resetToDefaults()">
                    <span class="dashicons dashicons-undo"></span>
                    Reset to Defaults
                </button>
                <p class="description">Reset all settings to their default values.</p>
            </div>
        </div>
    </div>
</div>

<script>
function updateFileSizeInput(multiplier) {
    const input = document.getElementById('max_file_size');
    const currentValue = parseInt(input.value);
    const currentMultiplier = getCurrentMultiplier(currentValue);
    const actualBytes = currentValue * currentMultiplier;
    const newValue = Math.round(actualBytes / parseInt(multiplier));
    input.value = newValue;
}

function getCurrentMultiplier(value) {
    if (value >= 1048576) return 1048576; // MB
    if (value >= 1024) return 1024; // KB
    return 1; // Bytes
}

function resetToDefaults() {
    FICModal.confirm(
        'This will reset all settings to their default values. Continue?',
        'Reset Settings',
        'Yes, Reset',
        'Cancel'
    ).then(confirmed => {
        if (confirmed) {
            // Reset form fields to defaults
            document.querySelector('select[name="scan_interval"]').value = 'daily';
            document.querySelector('input[name="max_file_size"]').value = '10485760';
            document.querySelector('input[name="notification_enabled"]').checked = true;
            document.querySelector('input[name="auto_schedule"]').checked = true;
            document.querySelector('input[name="retention_period"]').value = '90';
            document.querySelector('input[name="notification_email"]').value = '';
            
            // Reset file type checkboxes
            const defaultTypes = ['.js', '.css', '.html', '.php'];
            document.querySelectorAll('.file-extension-checkbox').forEach(checkbox => {
                checkbox.checked = defaultTypes.includes(checkbox.value);
            });
            
            // Reset exclude patterns
            const defaultPatterns = [
                '*/cache/*',
                '*/logs/*', 
                '*/uploads/*',
                '*/wp-content/cache/*',
                '*/wp-content/backup*'
            ].join('\n');
            document.querySelector('textarea[name="exclude_patterns"]').value = defaultPatterns;
            
            FICModal.success('Settings reset to defaults. Click "Save Settings" to apply changes.');
        }
    });
}

// Toggle notification email field based on checkbox
document.addEventListener('DOMContentLoaded', function() {
    const enabledCheckbox = document.getElementById('notification_enabled');
    const emailField = document.getElementById('notification_email');
    
    function toggleEmailField() {
        emailField.disabled = !enabledCheckbox.checked;
    }
    
    enabledCheckbox.addEventListener('change', toggleEmailField);
    toggleEmailField(); // Set initial state
});
</script>