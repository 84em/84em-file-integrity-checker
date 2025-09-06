/**
 * Admin JavaScript for 84EM File Integrity Checker
 */

(function($) {
    'use strict';

    // Main application object
    const FileIntegrityChecker = {
        
        // Configuration
        config: {
            progressCheckInterval: 2000, // Check progress every 2 seconds
            maxProgressChecks: 300       // Max 10 minutes of checking
        },

        // Initialize the application
        init: function() {
            this.bindEvents();
            this.initComponents();
        },

        // Bind event handlers
        bindEvents: function() {
            $(document).on('click', '.start-scan-btn', this.handleStartScan.bind(this));
            $(document).on('click', '.cancel-scan-btn', this.handleCancelScan.bind(this));
            $(document).on('click', '.cancel-scan', this.handleCancelSpecificScan.bind(this));
            $(document).on('click', '.delete-scan', this.handleDeleteScan.bind(this));
            $(document).on('click', '.schedule-scan-btn', this.handleScheduleScan.bind(this));
            $(document).on('click', '.view-scan-details', this.handleViewScanDetails.bind(this));
            $(document).on('change', '.file-extension-checkbox', this.handleFileExtensionChange.bind(this));
            $(document).on('click', '.cleanup-old-scans', this.handleCleanupOldScans.bind(this));
            
            // Notification settings
            $(document).on('change', '#notification_enabled', this.handleEmailNotificationToggle.bind(this));
            $(document).on('change', '#slack_enabled', this.handleSlackNotificationToggle.bind(this));
            $(document).on('click', '#test-slack-notification', this.handleTestSlackNotification.bind(this));
            
            // Bulk actions
            $(document).on('change', '#select-all-scans', this.handleSelectAllScans.bind(this));
            $(document).on('change', '.scan-checkbox', this.handleScanCheckboxChange.bind(this));
            $(document).on('click', '.bulk-delete-btn', this.handleBulkDelete.bind(this));
            
            // Scan details page actions
            $(document).on('click', '.delete-scan-details', this.handleDeleteScanDetails.bind(this));
            $(document).on('click', '.resend-email-notification', this.handleResendEmailNotification.bind(this));
            $(document).on('click', '.resend-slack-notification', this.handleResendSlackNotification.bind(this));
            $(document).on('click', '.view-file-btn', this.handleViewFile.bind(this));
            
            // Settings form validation
            $(document).on('submit', '.file-integrity-settings form', this.validateSettingsForm.bind(this));
            
            // Auto-refresh dashboard if scan is running
            this.startAutoRefresh();
        },

        // Initialize components
        initComponents: function() {
            this.initProgressBars();
            this.initTooltips();
            this.checkScanStatus();
        },

        // Handle start scan button click
        handleStartScan: function(e) {
            e.preventDefault();
            
            const button = $(e.target);
            const originalText = button.text();
            
            // Show confirmation dialog
            FICModal.confirm(
                'This will scan your entire WordPress installation. This may take several minutes. Continue?',
                'Start Integrity Scan',
                'Start Scan',
                'Cancel'
            ).then(confirmed => {
                if (!confirmed) {
                    return;
                }
            
                // Update button state
                button.prop('disabled', true)
                      .text('Starting Scan...')
                      .addClass('button-disabled');
                
                // Start the scan via AJAX
                this.startScan().then(
                    (response) => {
                        if (response.success) {
                            this.showScanProgress(response.data.scan_id);
                        } else {
                            this.showError(response.data || 'Failed to start scan');
                            this.resetScanButton(button, originalText);
                        }
                    },
                    (error) => {
                        this.showError('Failed to start scan: ' + error.statusText);
                        this.resetScanButton(button, originalText);
                    }
                );
            });
        },

        // Start scan via AJAX
        startScan: function() {
            return $.post(fileIntegrityChecker.ajaxUrl, {
                action: 'file_integrity_start_scan',
                _wpnonce: fileIntegrityChecker.nonces.start_scan
            });
        },

        // Show scan progress
        showScanProgress: function(scanId) {
            const progressContainer = this.createProgressContainer();
            $('.scan-controls').after(progressContainer);
            
            let checkCount = 0;
            const checkProgress = () => {
                if (checkCount >= this.config.maxProgressChecks) {
                    this.showError('Scan progress check timed out');
                    return;
                }
                
                $.post(fileIntegrityChecker.ajaxUrl, {
                    action: 'file_integrity_check_progress',
                    scan_id: scanId,
                    _wpnonce: fileIntegrityChecker.nonces.check_progress
                }).then((response) => {
                    if (response.success) {
                        this.updateProgress(response.data);
                        
                        if (response.data.status === 'completed' || response.data.status === 'failed') {
                            this.handleScanComplete(response.data);
                        } else {
                            checkCount++;
                            setTimeout(checkProgress, this.config.progressCheckInterval);
                        }
                    } else {
                        this.showError('Failed to check scan progress');
                    }
                }).catch((error) => {
                    this.showError('Progress check error: ' + error.statusText);
                });
            };
            
            checkProgress();
        },

        // Create progress container
        createProgressContainer: function() {
            return $(`
                <div class="file-integrity-progress-container">
                    <h3>Scan in Progress</h3>
                    <div class="file-integrity-progress">
                        <div class="file-integrity-progress-bar" style="width: 0%">
                            <span class="file-integrity-progress-text">Starting...</span>
                        </div>
                    </div>
                    <p class="progress-message">Initializing scan...</p>
                    <button type="button" class="button cancel-scan-btn">Cancel Scan</button>
                </div>
            `);
        },

        // Update progress display
        updateProgress: function(data) {
            const progressBar = $('.file-integrity-progress-bar');
            const progressText = $('.file-integrity-progress-text');
            const messageEl = $('.progress-message');
            
            // Calculate progress percentage (rough estimate based on files processed)
            let percentage = 0;
            if (data.total_files && data.total_files > 0) {
                percentage = Math.min(95, (data.processed_files / data.total_files) * 100);
            }
            
            progressBar.css('width', percentage + '%');
            progressText.text(percentage.toFixed(0) + '%');
            
            if (data.message) {
                messageEl.text(data.message);
            }
        },

        // Handle scan completion
        handleScanComplete: function(data) {
            $('.file-integrity-progress-container').remove();
            
            if (data.status === 'completed') {
                this.showSuccess('Scan completed successfully!');
                this.displayScanResults(data);
            } else {
                this.showError('Scan failed: ' + (data.error || 'Unknown error'));
            }
            
            // Reset scan button
            const button = $('.start-scan-btn');
            this.resetScanButton(button, 'Run Scan Now');
        },

        // Display scan results
        displayScanResults: function(data) {
            // Extract stats from nested object or use direct properties
            const stats = data.stats || data;
            const total_files = stats.total_files || 0;
            const changed_files = stats.changed_files || 0;
            const new_files = stats.new_files || 0;
            const deleted_files = stats.deleted_files || 0;
            
            const resultsHtml = `
                <div class="file-integrity-results">
                    <h3>Scan Results</h3>
                    <div class="stats-grid">
                        <div class="stat-item">
                            <span class="stat-number">${this.formatNumber(total_files)}</span>
                            <span class="stat-label">Total Files</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-number ${changed_files > 0 ? 'warning' : 'success'}">${this.formatNumber(changed_files)}</span>
                            <span class="stat-label">Changed</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-number ${new_files > 0 ? 'warning' : ''}">${this.formatNumber(new_files)}</span>
                            <span class="stat-label">New</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-number ${deleted_files > 0 ? 'error' : ''}">${this.formatNumber(deleted_files)}</span>
                            <span class="stat-label">Deleted</span>
                        </div>
                    </div>
                    <p><a href="admin.php?page=file-integrity-checker-results&scan_id=${data.scan_id}" class="button">View Detailed Results</a></p>
                </div>
            `;
            
            $('.scan-controls').after(resultsHtml);
        },

        // Reset scan button state
        resetScanButton: function(button, originalText) {
            button.prop('disabled', false)
                  .text(originalText)
                  .removeClass('button-disabled');
        },

        // Handle cancel scan
        handleCancelScan: function(e) {
            e.preventDefault();
            
            FICModal.confirm(
                'Are you sure you want to cancel the current scan?',
                'Cancel Scan',
                'Yes, Cancel',
                'Continue Scanning'
            ).then(confirmed => {
                if (confirmed) {
                    $.post(fileIntegrityChecker.ajaxUrl, {
                        action: 'file_integrity_cancel_scan',
                        _wpnonce: fileIntegrityChecker.nonces.cancel_scan
                    }).then((response) => {
                        if (response.success) {
                            $('.file-integrity-progress-container').remove();
                            this.showSuccess('Scan cancelled successfully');
                            this.resetScanButton($('.start-scan-btn'), 'Run Scan Now');
                        } else {
                            this.showError('Failed to cancel scan');
                        }
                    });
                }
            });
        },

        // Handle schedule scan
        handleScheduleScan: function(e) {
            e.preventDefault();
            
            const interval = $(e.target).data('interval') || 'daily';
            
            $.post(fileIntegrityChecker.ajaxUrl, {
                action: 'file_integrity_schedule_scan',
                interval: interval,
                _wpnonce: fileIntegrityChecker.nonces.schedule_scan || fileIntegrityChecker.nonce
            }).then((response) => {
                if (response.success) {
                    this.showSuccess('Scan scheduled successfully');
                    setTimeout(() => window.location.reload(), 2000);
                } else {
                    this.showError('Failed to schedule scan');
                }
            });
        },

        // Handle view scan details
        handleViewScanDetails: function(e) {
            e.preventDefault();
            
            const scanId = $(e.target).data('scan-id');
            window.location.href = `admin.php?page=file-integrity-checker-results&scan_id=${scanId}`;
        },

        // Handle file extension checkbox changes
        handleFileExtensionChange: function(e) {
            const checkbox = $(e.target);
            const extension = checkbox.val();
            const isChecked = checkbox.is(':checked');
            
            // Update hidden input field with selected extensions
            this.updateSelectedExtensions();
        },

        // Update selected extensions hidden input
        updateSelectedExtensions: function() {
            const selected = [];
            $('.file-extension-checkbox:checked').each(function() {
                selected.push($(this).val());
            });
            
            $('#selected_extensions').val(selected.join(','));
        },

        // Handle cancel specific scan from results table
        handleCancelSpecificScan: function(e) {
            e.preventDefault();
            
            const button = $(e.currentTarget);
            const scanId = button.data('scan-id');
            
            FICModal.confirm(
                'Are you sure you want to cancel this running scan?',
                'Cancel Scan',
                'Yes, Cancel',
                'No, Keep Running'
            ).then(confirmed => {
                if (confirmed) {
                    button.prop('disabled', true);
                    
                    $.post(fileIntegrityChecker.ajaxUrl, {
                        action: 'file_integrity_cancel_scan',
                        scan_id: scanId,
                        _wpnonce: fileIntegrityChecker.nonces.cancel_scan
                    }).then((response) => {
                        if (response.success) {
                            this.showSuccess('Scan cancelled successfully');
                            setTimeout(() => window.location.reload(), 1500);
                        } else {
                            this.showError('Failed to cancel scan: ' + (response.data || 'Unknown error'));
                            button.prop('disabled', false);
                        }
                    }).catch((error) => {
                        this.showError('Failed to cancel scan');
                        button.prop('disabled', false);
                    });
                }
            });
        },
        
        // Handle delete scan
        handleDeleteScan: function(e) {
            e.preventDefault();
            
            const button = $(e.currentTarget);
            const scanId = button.data('scan-id');
            
            FICModal.confirm(
                'Are you sure you want to delete this scan result? This action cannot be undone.',
                'Delete Scan Result',
                'Yes, Delete',
                'Cancel'
            ).then(confirmed => {
                if (confirmed) {
                    button.prop('disabled', true);
                    
                    $.post(fileIntegrityChecker.ajaxUrl, {
                        action: 'file_integrity_delete_scan',
                        scan_id: scanId,
                        _wpnonce: fileIntegrityChecker.nonces.delete_scan
                    }).then((response) => {
                        if (response.success) {
                            this.showSuccess('Scan result deleted successfully');
                            // Remove the row from the table
                            button.closest('tr').fadeOut(500, function() {
                                $(this).remove();
                            });
                        } else {
                            this.showError('Failed to delete scan: ' + (response.data || 'Unknown error'));
                            button.prop('disabled', false);
                        }
                    }).catch((error) => {
                        this.showError('Failed to delete scan');
                        button.prop('disabled', false);
                    });
                }
            });
        },

        // Handle cleanup old scans
        handleCleanupOldScans: function(e) {
            e.preventDefault();
            
            FICModal.confirm(
                'This will permanently delete old scan results. Continue?',
                'Delete Old Scans',
                'Yes, Delete',
                'Cancel'
            ).then(confirmed => {
                if (!confirmed) {
                    return;
                }
            
                const button = $(e.target);
                button.prop('disabled', true).text('Cleaning up...');
                
                $.post(fileIntegrityChecker.ajaxUrl, {
                    action: 'file_integrity_cleanup_old_scans',
                    _wpnonce: fileIntegrityChecker.nonces.cleanup_old
                }).then((response) => {
                    if (response.success) {
                        this.showSuccess(`Deleted ${response.data.deleted_scans} old scan results`);
                        setTimeout(() => window.location.reload(), 2000);
                    } else {
                        this.showError('Failed to cleanup old scans');
                    }
                    
                    button.prop('disabled', false).text('Cleanup Old Scans');
                });
            });
        },

        // Handle email notification toggle
        handleEmailNotificationToggle: function(e) {
            const isChecked = $(e.target).is(':checked');
            if (isChecked) {
                $('.email-notification-row').show();
            } else {
                $('.email-notification-row').hide();
            }
        },
        
        // Handle Slack notification toggle
        handleSlackNotificationToggle: function(e) {
            const isChecked = $(e.target).is(':checked');
            if (isChecked) {
                $('.slack-notification-row').show();
            } else {
                $('.slack-notification-row').hide();
            }
        },
        
        // Handle test Slack notification
        handleTestSlackNotification: function(e) {
            e.preventDefault();
            
            const button = $(e.target);
            const webhookUrl = $('#slack_webhook_url').val();
            
            if (!webhookUrl) {
                this.showError('Please enter a Slack webhook URL first');
                return;
            }
            
            button.prop('disabled', true).text('Testing...');
            
            $.post(fileIntegrityChecker.ajaxUrl, {
                action: 'file_integrity_test_slack',
                webhook_url: webhookUrl,
                _wpnonce: fileIntegrityChecker.nonces.test_slack
            }).then((response) => {
                if (response.success) {
                    this.showSuccess('Test message sent successfully! Check your Slack channel.');
                } else {
                    this.showError('Failed to send test message: ' + (response.data || 'Unknown error'));
                }
                button.prop('disabled', false).text('Test Slack Connection');
            }).catch((error) => {
                this.showError('Failed to test Slack connection');
                button.prop('disabled', false).text('Test Slack Connection');
            });
        },
        
        // Handle select all scans checkbox
        handleSelectAllScans: function(e) {
            const isChecked = $(e.target).is(':checked');
            $('.scan-checkbox').prop('checked', isChecked);
            this.updateSelectedCount();
        },
        
        // Handle individual scan checkbox change
        handleScanCheckboxChange: function(e) {
            const totalCheckboxes = $('.scan-checkbox').length;
            const checkedCheckboxes = $('.scan-checkbox:checked').length;
            
            // Update select all checkbox state
            $('#select-all-scans').prop('checked', totalCheckboxes === checkedCheckboxes);
            $('#select-all-scans').prop('indeterminate', checkedCheckboxes > 0 && checkedCheckboxes < totalCheckboxes);
            
            this.updateSelectedCount();
        },
        
        // Update selected count display
        updateSelectedCount: function() {
            const count = $('.scan-checkbox:checked').length;
            $('.selected-count .count').text(count);
            
            if (count > 0) {
                $('.selected-count').show();
            } else {
                $('.selected-count').hide();
            }
        },
        
        // Handle bulk delete
        handleBulkDelete: function(e) {
            e.preventDefault();
            
            const action = $('#bulk-action-selector-top').val();
            if (action !== 'delete') {
                return;
            }
            
            const selectedScans = $('.scan-checkbox:checked').map(function() {
                return $(this).val();
            }).get();
            
            if (selectedScans.length === 0) {
                this.showError('Please select at least one scan to delete');
                return;
            }
            
            const confirmMessage = selectedScans.length === 1 
                ? 'Are you sure you want to delete this scan result?'
                : `Are you sure you want to delete ${selectedScans.length} scan results?`;
            
            FICModal.confirm(
                confirmMessage + ' This action cannot be undone.',
                'Delete Scan Results',
                'Yes, Delete',
                'Cancel'
            ).then(confirmed => {
                if (!confirmed) {
                    return;
                }
                
                const button = $(e.target);
                button.prop('disabled', true).text('Deleting...');
                
                // Send AJAX request to delete scans
                $.post(fileIntegrityChecker.ajaxUrl, {
                    action: 'file_integrity_bulk_delete_scans',
                    scan_ids: selectedScans,
                    _wpnonce: fileIntegrityChecker.nonces.bulk_delete
                }).then((response) => {
                    if (response.success) {
                        this.showSuccess(`Successfully deleted ${response.data.deleted} scan results`);
                        
                        // Remove deleted rows from table
                        selectedScans.forEach(scanId => {
                            $(`tr[data-scan-id="${scanId}"]`).fadeOut(500, function() {
                                $(this).remove();
                            });
                        });
                        
                        // Reset checkboxes
                        $('#select-all-scans').prop('checked', false);
                        this.updateSelectedCount();
                    } else {
                        this.showError('Failed to delete scans: ' + (response.data || 'Unknown error'));
                    }
                    
                    button.prop('disabled', false).text('Apply');
                }).catch((error) => {
                    this.showError('Failed to delete scans');
                    button.prop('disabled', false).text('Apply');
                });
            });
        },
        
        // Handle delete scan from details page
        handleDeleteScanDetails: function(e) {
            e.preventDefault();
            
            const button = $(e.currentTarget);
            const scanId = button.data('scan-id');
            
            FICModal.confirm(
                'Are you sure you want to delete this scan result? This action cannot be undone.',
                'Delete Scan Result',
                'Yes, Delete',
                'Cancel'
            ).then(confirmed => {
                if (confirmed) {
                    button.prop('disabled', true).text('Deleting...');
                    
                    $.post(fileIntegrityChecker.ajaxUrl, {
                        action: 'file_integrity_delete_scan',
                        scan_id: scanId,
                        _wpnonce: fileIntegrityChecker.nonces.delete_scan
                    }).then((response) => {
                        if (response.success) {
                            this.showSuccess('Scan deleted successfully. Redirecting...');
                            setTimeout(() => {
                                window.location.href = 'admin.php?page=file-integrity-checker-results';
                            }, 1500);
                        } else {
                            this.showError('Failed to delete scan: ' + (response.data || 'Unknown error'));
                            button.prop('disabled', false).html('<span class="dashicons dashicons-trash"></span> Delete This Scan');
                        }
                    }).catch((error) => {
                        this.showError('Failed to delete scan');
                        button.prop('disabled', false).html('<span class="dashicons dashicons-trash"></span> Delete This Scan');
                    });
                }
            });
        },
        
        // Handle resend email notification
        handleResendEmailNotification: function(e) {
            e.preventDefault();
            
            const button = $(e.currentTarget);
            const scanId = button.data('scan-id');
            const originalHtml = button.html();
            
            button.prop('disabled', true).html('<span class="dashicons dashicons-email"></span> Sending...');
            
            $.post(fileIntegrityChecker.ajaxUrl, {
                action: 'file_integrity_resend_email',
                scan_id: scanId,
                _wpnonce: fileIntegrityChecker.nonces.resend_email
            }).then((response) => {
                if (response.success) {
                    this.showSuccess('Email notification sent successfully');
                    button.prop('disabled', false).html(originalHtml);
                } else {
                    this.showError('Failed to send email: ' + (response.data || 'Unknown error'));
                    button.prop('disabled', false).html(originalHtml);
                }
            }).catch((error) => {
                this.showError('Failed to send email notification');
                button.prop('disabled', false).html(originalHtml);
            });
        },
        
        // Handle resend Slack notification
        handleResendSlackNotification: function(e) {
            e.preventDefault();
            
            const button = $(e.currentTarget);
            const scanId = button.data('scan-id');
            const originalHtml = button.html();
            
            button.prop('disabled', true).html('<span class="dashicons dashicons-admin-comments"></span> Sending...');
            
            $.post(fileIntegrityChecker.ajaxUrl, {
                action: 'file_integrity_resend_slack',
                scan_id: scanId,
                _wpnonce: fileIntegrityChecker.nonces.resend_slack
            }).then((response) => {
                if (response.success) {
                    this.showSuccess('Slack notification sent successfully');
                    button.prop('disabled', false).html(originalHtml);
                } else {
                    this.showError('Failed to send Slack notification: ' + (response.data || 'Unknown error'));
                    button.prop('disabled', false).html(originalHtml);
                }
            }).catch((error) => {
                this.showError('Failed to send Slack notification');
                button.prop('disabled', false).html(originalHtml);
            });
        },
        
        // Handle view file
        handleViewFile: function(e) {
            e.preventDefault();
            
            const button = $(e.currentTarget);
            const filePath = button.data('file-path');
            const scanId = button.data('scan-id');
            const originalHtml = button.html();
            
            button.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Loading...');
            
            $.post(fileIntegrityChecker.ajaxUrl, {
                action: 'file_integrity_view_file',
                file_path: filePath,
                scan_id: scanId,
                _wpnonce: fileIntegrityChecker.nonces.view_file
            }).then((response) => {
                button.prop('disabled', false).html(originalHtml);
                
                if (response.success) {
                    this.showFileModal(response.data);
                } else {
                    this.showError(response.data || 'Failed to load file');
                }
            }).catch((error) => {
                button.prop('disabled', false).html(originalHtml);
                this.showError('Failed to load file: ' + error.statusText);
            });
        },
        
        // Show file content in modal
        showFileModal: function(data) {
            // Create modal HTML
            const modalHtml = `
                <div id="file-viewer-modal" class="file-integrity-modal">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h2>File Viewer</h2>
                            <button type="button" class="modal-close" title="Close">
                                <span class="dashicons dashicons-no-alt"></span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <div class="file-info">
                                <div class="file-path"><strong>File:</strong> ${this.escapeHtml(data.file_path)}</div>
                                <div class="file-stats">
                                    <span><strong>Size:</strong> ${this.formatBytes(data.file_size)}</span>
                                    <span><strong>Lines:</strong> ${data.lines}</span>
                                    <span><strong>Language:</strong> ${data.language}</span>
                                    ${data.redacted ? '<span class="warning"><strong>Note:</strong> Sensitive data has been redacted</span>' : ''}
                                </div>
                            </div>
                            <div class="file-content-wrapper">
                                <pre class="file-content language-${data.language}"><code>${this.escapeHtml(data.content)}</code></pre>
                            </div>
                        </div>
                        <div class="fic-modal-footer">
                            <button type="button" class="button modal-close">Close</button>
                        </div>
                    </div>
                </div>
            `;
            
            // Remove any existing modal
            $('#file-viewer-modal').remove();
            
            // Add modal to body
            $('body').append(modalHtml);
            
            // Add modal styles if not already present
            if (!$('#file-viewer-styles').length) {
                const modalStyles = `
                    <style id="file-viewer-styles">
                        .file-integrity-modal {
                            display: block;
                            position: fixed;
                            z-index: 100000;
                            left: 0;
                            top: 0;
                            width: 100%;
                            height: 100%;
                            background-color: rgba(0,0,0,0.5);
                            animation: fadeIn 0.3s;
                        }
                        
                        .file-integrity-modal .modal-content {
                            background-color: #fff;
                            margin: 2% auto;
                            border-radius: 4px;
                            width: 90%;
                            max-width: 1200px;
                            max-height: 90vh;
                            display: flex;
                            flex-direction: column;
                            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
                        }
                        
                        .file-integrity-modal .modal-header {
                            padding: 15px 20px;
                            border-bottom: 1px solid #ddd;
                            display: flex;
                            justify-content: space-between;
                            align-items: center;
                        }
                        
                        .file-integrity-modal .modal-header h2 {
                            margin: 0;
                            font-size: 20px;
                        }
                        
                        .file-integrity-modal .modal-close {
                            background: #f0f0f1;
                            border: 1px solid #ddd;
                            border-radius: 3px;
                            cursor: pointer;
                            color: #666;
                            padding: 4px;
                            width: 32px;
                            height: 32px;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            transition: all 0.2s;
                        }
                        
                        .file-integrity-modal .modal-close:hover {
                            background: #e5e5e5;
                            border-color: #999;
                            color: #333;
                        }
                        
                        .file-integrity-modal .modal-close:focus {
                            outline: 1px solid #2271b1;
                            outline-offset: 1px;
                        }
                        
                        .file-integrity-modal .modal-close .dashicons {
                            font-size: 20px;
                            width: 20px;
                            height: 20px;
                        }
                        
                        .file-integrity-modal .modal-body {
                            padding: 20px;
                            overflow-y: auto;
                            flex: 1;
                        }
                        
                        .file-integrity-modal .file-info {
                            background: #f5f5f5;
                            padding: 15px;
                            border-radius: 4px;
                            margin-bottom: 20px;
                        }
                        
                        .file-integrity-modal .file-path {
                            font-family: monospace;
                            margin-bottom: 10px;
                            word-break: break-all;
                        }
                        
                        .file-integrity-modal .file-stats {
                            display: flex;
                            gap: 20px;
                            font-size: 13px;
                            color: #666;
                        }
                        
                        .file-integrity-modal .file-stats .warning {
                            color: #d63638;
                        }
                        
                        .file-integrity-modal .file-content-wrapper {
                            background: #282c34;
                            border-radius: 4px;
                            overflow: auto;
                            max-height: 500px;
                        }
                        
                        .file-integrity-modal .file-content {
                            margin: 0;
                            padding: 20px;
                            color: #abb2bf;
                            font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
                            font-size: 13px;
                            line-height: 1.5;
                            white-space: pre;
                            overflow-x: auto;
                        }
                        
                        @keyframes fadeIn {
                            from { opacity: 0; }
                            to { opacity: 1; }
                        }
                        
                        .dashicons.spin {
                            animation: spin 1s linear infinite;
                        }
                        
                        @keyframes spin {
                            from { transform: rotate(0deg); }
                            to { transform: rotate(360deg); }
                        }
                    </style>
                `;
                $('head').append(modalStyles);
            }
            
            // Bind close handlers
            $('#file-viewer-modal .modal-close').on('click', function() {
                $('#file-viewer-modal').fadeOut(300, function() {
                    $(this).remove();
                });
            });
            
            // Close on overlay click
            $('#file-viewer-modal').on('click', function(e) {
                if (e.target === this) {
                    $(this).fadeOut(300, function() {
                        $(this).remove();
                    });
                }
            });
            
            // Close on ESC key
            $(document).on('keydown.fileModal', function(e) {
                if (e.key === 'Escape') {
                    $('#file-viewer-modal').fadeOut(300, function() {
                        $(this).remove();
                    });
                    $(document).off('keydown.fileModal');
                }
            });
        },
        
        // Escape HTML for safe display
        escapeHtml: function(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, m => map[m]);
        },
        
        // Format bytes to human readable
        formatBytes: function(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        },
        
        // Validate settings form
        validateSettingsForm: function(e) {
            let isValid = true;
            
            // Validate email
            const email = $('#notification_email').val();
            if (email && !this.isValidEmail(email)) {
                this.showError('Please enter a valid email address');
                isValid = false;
            }
            
            // Validate file size in MB
            const maxFileSizeMB = parseFloat($('#max_file_size_mb').val());
            if (maxFileSizeMB <= 0 || maxFileSizeMB > 100) {
                this.showError('Max file size must be between 1 MB and 100 MB');
                isValid = false;
            }
            
            // Validate retention period
            const retentionPeriod = parseInt($('#retention_period').val());
            if (retentionPeriod < 1 || retentionPeriod > 365) {
                this.showError('Retention period must be between 1 and 365 days');
                isValid = false;
            }
            
            if (!isValid) {
                e.preventDefault();
            }
        },

        // Check if scan is currently running
        checkScanStatus: function() {
            const scanStatusEl = $('.scan-status');
            if (scanStatusEl.length && scanStatusEl.data('status') === 'running') {
                // If a scan is running, start monitoring it
                const scanId = scanStatusEl.data('scan-id');
                if (scanId) {
                    this.showScanProgress(scanId);
                }
            }
        },

        // Initialize progress bars (for existing data)
        initProgressBars: function() {
            $('.file-integrity-progress').each(function() {
                const progress = $(this);
                const percentage = progress.data('percentage') || 0;
                progress.find('.file-integrity-progress-bar').css('width', percentage + '%');
            });
        },

        // Initialize tooltips
        initTooltips: function() {
            // Add tooltips to status badges and other elements
            $('.status-badge').each(function() {
                const badge = $(this);
                const status = badge.text().toLowerCase();
                
                let tooltip = '';
                switch(status) {
                    case 'completed':
                        tooltip = 'Scan completed successfully';
                        break;
                    case 'failed':
                        tooltip = 'Scan encountered an error';
                        break;
                    case 'running':
                        tooltip = 'Scan is currently in progress';
                        break;
                }
                
                if (tooltip) {
                    badge.attr('title', tooltip);
                }
            });
        },

        // Auto-refresh dashboard if needed
        startAutoRefresh: function() {
            const refreshInterval = $('.auto-refresh').data('interval');
            
            if (refreshInterval && refreshInterval > 0) {
                setTimeout(() => {
                    window.location.reload();
                }, refreshInterval * 1000);
            }
        },

        // Utility functions
        formatNumber: function(num) {
            return new Intl.NumberFormat().format(num);
        },

        isValidEmail: function(email) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return emailRegex.test(email);
        },

        formatFileSize: function(bytes) {
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            if (bytes === 0) return '0 Byte';
            const i = parseInt(Math.floor(Math.log(bytes) / Math.log(1024)));
            return Math.round(bytes / Math.pow(1024, i) * 100) / 100 + ' ' + sizes[i];
        },

        // Show success message
        showSuccess: function(message) {
            this.showNotice(message, 'success');
        },

        // Show error message
        showError: function(message) {
            this.showNotice(message, 'error');
        },

        // Show info message
        showInfo: function(message) {
            this.showNotice(message, 'info');
        },

        // Show notice
        showNotice: function(message, type) {
            const alertClass = 'alert-' + type;
            const iconClass = type === 'error' ? 'warning' : type;
            
            const notice = $(`
                <div class="file-integrity-alert ${alertClass}">
                    <span class="dashicons dashicons-${iconClass}"></span>
                    <span>${message}</span>
                </div>
            `);
            
            // Remove existing notices
            $('.file-integrity-alert').remove();
            
            // Add new notice
            $('.wrap > h1').after(notice);
            
            // Auto-remove success messages
            if (type === 'success' || type === 'info') {
                setTimeout(() => {
                    notice.fadeOut(500, function() {
                        $(this).remove();
                    });
                }, 5000);
            }
            
            // Scroll to top to ensure notice is visible
            $('html, body').animate({ scrollTop: 0 }, 500);
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        FileIntegrityChecker.init();
    });

})(jQuery);