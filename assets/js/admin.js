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
            $(document).on('click', '.schedule-scan-btn', this.handleScheduleScan.bind(this));
            $(document).on('click', '.view-scan-details', this.handleViewScanDetails.bind(this));
            $(document).on('change', '.file-extension-checkbox', this.handleFileExtensionChange.bind(this));
            $(document).on('click', '.cleanup-old-scans', this.handleCleanupOldScans.bind(this));
            
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
                _wpnonce: fileIntegrityChecker.nonce
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
                    _wpnonce: fileIntegrityChecker.nonce
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
                        _wpnonce: fileIntegrityChecker.nonce
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
                _wpnonce: fileIntegrityChecker.nonce
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
                    _wpnonce: fileIntegrityChecker.nonce
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

        // Validate settings form
        validateSettingsForm: function(e) {
            let isValid = true;
            
            // Validate email
            const email = $('#notification_email').val();
            if (email && !this.isValidEmail(email)) {
                this.showError('Please enter a valid email address');
                isValid = false;
            }
            
            // Validate file size
            const maxFileSize = parseInt($('#max_file_size').val());
            if (maxFileSize <= 0 || maxFileSize > 104857600) {
                this.showError('Max file size must be between 1 byte and 100MB');
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