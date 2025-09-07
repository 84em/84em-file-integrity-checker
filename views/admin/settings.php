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
                                           <?php echo esc_attr( $checked ); ?> />
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
                    
                    <!-- Email Settings Section (conditionally shown) -->
                    <tbody class="email-notification-settings" style="<?php echo $settings['notification_enabled'] ? '' : 'display:none;'; ?>">
                        <tr>
                            <td colspan="2" style="padding: 0;">
                                <h4 class="email-settings-header">📧 Email Settings</h4>
                            </td>
                        </tr>
                        <tr>
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
                        <tr>
                            <th scope="row">
                                <label for="email_subject">Email Subject Line</label>
                            </th>
                            <td>
                                <input type="text" 
                                       name="email_subject" 
                                       id="email_subject" 
                                       value="<?php echo esc_attr( $settings['email_subject'] ); ?>" 
                                       class="large-text"
                                       placeholder="[%site_name%] File Integrity Scan - Changes Detected" />
                                <p class="description">
                                    Customize the email subject line. Available variables: 
                                    <code>%site_name%</code>, <code>%site_url%</code>, <code>%scan_date%</code>, 
                                    <code>%changed_files%</code>, <code>%new_files%</code>, <code>%deleted_files%</code>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="email_from_address">From Email Address</label>
                            </th>
                            <td>
                                <input type="email" 
                                       name="email_from_address" 
                                       id="email_from_address" 
                                       value="<?php echo esc_attr( $settings['email_from_address'] ); ?>" 
                                       class="regular-text"
                                       placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" />
                                <p class="description">
                                    Email address to send notifications from. Leave empty to use the admin email.
                                </p>
                            </td>
                        </tr>
                    </tbody>
                    
                    <!-- Slack Settings Section (conditionally shown) -->
                    <tbody class="slack-notification-settings" style="<?php echo $settings['slack_enabled'] ? '' : 'display:none;'; ?>">
                        <tr>
                            <td colspan="2" style="padding: 0;">
                                <h4 class="slack-settings-header">💬 Slack Settings</h4>
                            </td>
                        </tr>
                        <tr>
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
                                <button type="button" class="button button-secondary" id="test-slack-notification" style="margin-top: 10px;">
                                    Test Slack Notification
                                </button>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="slack_header">Notification Title/Header</label>
                            </th>
                            <td>
                                <input type="text" 
                                       name="slack_header" 
                                       id="slack_header" 
                                       value="<?php echo esc_attr( $settings['slack_header'] ); ?>" 
                                       class="regular-text"
                                       placeholder="File Integrity Alert" />
                                <p class="description">
                                    Customize the header/title shown at the top of Slack notifications.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="slack_message_template">First Line of Message</label>
                            </th>
                            <td>
                                <input type="text" 
                                       name="slack_message_template" 
                                       id="slack_message_template" 
                                       value="<?php echo esc_attr( $settings['slack_message_template'] ); ?>" 
                                       class="large-text"
                                       placeholder="Changes detected on %site_name%" />
                                <p class="description">
                                    Customize the first line of the Slack message. Available variables: 
                                    <code>%site_name%</code>, <code>%site_url%</code>, <code>%scan_date%</code>, 
                                    <code>%changed_files%</code>, <code>%new_files%</code>, <code>%deleted_files%</code>
                                </p>
                            </td>
                        </tr>
                    </tbody>
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

        <!-- Uninstall Settings -->
        <div class="file-integrity-card" style="margin-bottom: 30px; border: 2px solid #dc3232;">
            <h3 style="color: #dc3232;">⚠️ Uninstall Settings</h3>
            <div class="card-content">
                <div class="file-integrity-alert alert-warning" style="margin-bottom: 20px;">
                    <span class="dashicons dashicons-warning"></span>
                    <span><strong>DANGER ZONE:</strong> These settings control what happens when the plugin is uninstalled.</span>
                </div>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="delete_data_on_uninstall">Delete Data on Uninstall</label>
                        </th>
                        <td>
                            <label style="display: block; padding: 10px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px;">
                                <input type="checkbox" 
                                       name="delete_data_on_uninstall" 
                                       id="delete_data_on_uninstall" 
                                       value="1" 
                                       <?php checked( $settings['delete_data_on_uninstall'] ?? false ); ?>
                                       onchange="toggleUninstallWarning(this)" />
                                <strong style="color: #dc3232;">
                                    Delete ALL plugin data when uninstalling
                                </strong>
                            </label>
                            <div id="uninstall-warning" style="display: <?php echo esc_attr( ($settings['delete_data_on_uninstall'] ?? false) ? 'block' : 'none' ); ?>; margin-top: 10px; padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px;">
                                <strong style="color: #721c24;">⚠️ WARNING:</strong>
                                <ul style="margin: 10px 0 0 20px; color: #721c24;">
                                    <li>All scan history will be permanently deleted</li>
                                    <li>All schedules will be removed</li>
                                    <li>All settings will be erased</li>
                                    <li>All database tables will be dropped</li>
                                    <li>All log entries will be deleted</li>
                                    <li><strong>This action CANNOT be undone!</strong></li>
                                </ul>
                                <p style="margin-top: 10px; color: #721c24;">
                                    <strong>Only check this box if you want to completely remove all traces of this plugin from your database.</strong>
                                </p>
                            </div>
                            <p class="description" style="margin-top: 10px;">
                                When <strong>UNCHECKED</strong> (recommended): Your data will be preserved if you uninstall the plugin, allowing you to reinstall later without losing your scan history and settings.<br>
                                When <strong>CHECKED</strong>: All plugin data will be permanently deleted when you uninstall the plugin.
                            </p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Submit Button -->
        <p class="submit">
            <input type="submit" name="submit" id="submit" class="button button-primary" value="Save Settings" />
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=file-integrity-checker' ) ); ?>" class="button">
                Cancel
            </a>
        </p>
    </form>
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

// Toggle notification settings sections based on checkboxes
document.addEventListener('DOMContentLoaded', function() {
    const emailCheckbox = document.getElementById('notification_enabled');
    const slackCheckbox = document.getElementById('slack_enabled');
    const emailSettingsSection = document.querySelector('.email-notification-settings');
    const slackSettingsSection = document.querySelector('.slack-notification-settings');
    
    // Function to toggle email settings section
    function toggleEmailSettings() {
        if (emailCheckbox.checked) {
            emailSettingsSection.style.display = '';
            // Smooth fade in
            emailSettingsSection.style.opacity = '0';
            emailSettingsSection.style.transition = 'opacity 0.3s ease-in-out';
            setTimeout(() => {
                emailSettingsSection.style.opacity = '1';
            }, 10);
        } else {
            emailSettingsSection.style.transition = 'opacity 0.3s ease-in-out';
            emailSettingsSection.style.opacity = '0';
            setTimeout(() => {
                emailSettingsSection.style.display = 'none';
            }, 300);
        }
    }
    
    // Function to toggle Slack settings section
    function toggleSlackSettings() {
        if (slackCheckbox.checked) {
            slackSettingsSection.style.display = '';
            // Smooth fade in
            slackSettingsSection.style.opacity = '0';
            slackSettingsSection.style.transition = 'opacity 0.3s ease-in-out';
            setTimeout(() => {
                slackSettingsSection.style.opacity = '1';
            }, 10);
        } else {
            slackSettingsSection.style.transition = 'opacity 0.3s ease-in-out';
            slackSettingsSection.style.opacity = '0';
            setTimeout(() => {
                slackSettingsSection.style.display = 'none';
            }, 300);
        }
    }
    
    // Add event listeners
    emailCheckbox.addEventListener('change', toggleEmailSettings);
    slackCheckbox.addEventListener('change', toggleSlackSettings);
    
    // Set initial opacity to 1 for visible sections
    if (emailCheckbox.checked) {
        emailSettingsSection.style.opacity = '1';
    }
    if (slackCheckbox.checked) {
        slackSettingsSection.style.opacity = '1';
    }
    
    // Handle Test Slack Notification button
    const testSlackBtn = document.getElementById('test-slack-notification');
    if (testSlackBtn) {
        testSlackBtn.addEventListener('click', function() {
            const webhookUrl = document.getElementById('slack_webhook_url').value;
            if (!webhookUrl) {
                FICModal.warning('Please enter a Slack webhook URL first.', 'Webhook URL Required');
                return;
            }
            
            // Send test notification via AJAX
            const data = new FormData();
            data.append('action', 'file_integrity_test_slack');
            data.append('webhook_url', webhookUrl);
            data.append('_wpnonce', '<?php echo esc_js( wp_create_nonce('file_integrity_test_slack') ); ?>');
            
            testSlackBtn.disabled = true;
            testSlackBtn.textContent = 'Testing...';
            
            fetch(ajaxurl, {
                method: 'POST',
                body: data
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    FICModal.success('Test notification sent successfully! Check your Slack channel.', 'Test Successful');
                } else {
                    FICModal.error('Failed to send test notification: ' + (result.data || 'Unknown error'), 'Test Failed');
                }
            })
            .catch(error => {
                FICModal.error('Error sending test notification: ' + error.message, 'Connection Error');
            })
            .finally(() => {
                testSlackBtn.disabled = false;
                testSlackBtn.textContent = 'Test Slack Notification';
            });
        });
    }
});

// Toggle uninstall warning
function toggleUninstallWarning(checkbox) {
    const warning = document.getElementById('uninstall-warning');
    if (checkbox.checked) {
        // Use custom modal for confirmation
        FICModal.confirm(
            '<strong>⚠️ WARNING:</strong><br><br>Enabling this option means that ALL plugin data will be PERMANENTLY DELETED when you uninstall the plugin.<br><br>This includes:<br>• All scan history<br>• All schedules<br>• All settings<br>• All database tables<br>• All log entries<br><br><strong>This action cannot be undone!</strong><br><br>Are you absolutely sure you want to enable data deletion on uninstall?',
            'Confirm Data Deletion Setting',
            'Yes, Enable Data Deletion',
            'Cancel'
        ).then(confirmed => {
            if (confirmed) {
                warning.style.display = 'block';
            } else {
                checkbox.checked = false;
                warning.style.display = 'none';
            }
        });
    } else {
        warning.style.display = 'none';
    }
}
</script>