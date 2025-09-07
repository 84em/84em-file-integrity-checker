<?php
/**
 * Notification Service
 *
 * @package EightyFourEM\FileIntegrityChecker\Services
 */

namespace EightyFourEM\FileIntegrityChecker\Services;

use EightyFourEM\FileIntegrityChecker\Database\ScanResultsRepository;
use EightyFourEM\FileIntegrityChecker\Database\FileRecordRepository;

/**
 * Service for handling all notifications (email, Slack, and future channels)
 */
class NotificationService {
    /**
     * Settings service
     *
     * @var SettingsService
     */
    private SettingsService $settingsService;

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
     * Logger service
     *
     * @var LoggerService
     */
    private LoggerService $logger;

    /**
     * Constructor
     *
     * @param SettingsService       $settingsService       Settings service
     * @param ScanResultsRepository $scanResultsRepository Scan results repository
     * @param FileRecordRepository  $fileRecordRepository  File record repository
     * @param LoggerService         $logger                Logger service
     */
    public function __construct(
        SettingsService $settingsService,
        ScanResultsRepository $scanResultsRepository,
        FileRecordRepository $fileRecordRepository,
        LoggerService $logger
    ) {
        $this->settingsService       = $settingsService;
        $this->scanResultsRepository = $scanResultsRepository;
        $this->fileRecordRepository  = $fileRecordRepository;
        $this->logger                = $logger;
    }

    /**
     * Send notifications for a scan (main entry point)
     *
     * @param int $scan_id Scan result ID
     * @return array Status array with results for each channel
     */
    public function sendScanNotification( int $scan_id ): array {
        $scan_data = $this->getScanData( $scan_id );
        
        if ( ! $scan_data ) {
            $this->logger->error(
                'Failed to send notifications: Scan not found',
                LoggerService::CONTEXT_NOTIFICATIONS,
                [ 'scan_id' => $scan_id ]
            );
            return [
                'success' => false,
                'error' => 'Scan not found',
                'channels' => []
            ];
        }

        // Check if notifications should be sent
        if ( ! $this->shouldSendNotifications( $scan_data ) ) {
            $this->logger->info(
                'No notifications sent: No changes detected',
                LoggerService::CONTEXT_NOTIFICATIONS,
                [ 'scan_id' => $scan_id ]
            );
            return [
                'success' => false,
                'error' => 'No changes to notify about',
                'channels' => []
            ];
        }

        // Format the scan results once for all channels
        $formatted_data = $this->formatScanResults( $scan_data );
        
        $results = [
            'success' => false,
            'channels' => []
        ];

        // Send email notification if enabled
        if ( $this->settingsService->isNotificationEnabled() ) {
            $email_result = $this->sendEmailNotification( $formatted_data );
            $results['channels']['email'] = $email_result;
            if ( $email_result['success'] ) {
                $results['success'] = true;
            }
        }

        // Send Slack notification if enabled
        if ( $this->settingsService->isSlackEnabled() ) {
            $slack_result = $this->sendSlackNotification( $formatted_data );
            $results['channels']['slack'] = $slack_result;
            if ( $slack_result['success'] ) {
                $results['success'] = true;
            }
        }

        // Log the notification results
        if ( $results['success'] ) {
            $successful_channels = array_keys( array_filter( 
                $results['channels'], 
                fn($r) => $r['success'] ?? false 
            ) );
            
            $this->logger->success(
                'Notifications sent successfully',
                LoggerService::CONTEXT_NOTIFICATIONS,
                [
                    'scan_id' => $scan_id,
                    'channels' => $successful_channels,
                    'changed_files' => $scan_data['summary']['changed_files'],
                    'new_files' => $scan_data['summary']['new_files'],
                    'deleted_files' => $scan_data['summary']['deleted_files']
                ]
            );
        } else {
            $this->logger->error(
                'All notification channels failed',
                LoggerService::CONTEXT_NOTIFICATIONS,
                [
                    'scan_id' => $scan_id,
                    'channels' => $results['channels']
                ]
            );
        }

        return $results;
    }

    /**
     * Resend notification for a scan (alias for sendScanNotification for clarity)
     *
     * @param int $scan_id Scan result ID
     * @return array Status array with results for each channel
     */
    public function resendNotification( int $scan_id ): array {
        $this->logger->info(
            'Resending notifications for scan',
            LoggerService::CONTEXT_NOTIFICATIONS,
            [ 'scan_id' => $scan_id ]
        );
        
        return $this->sendScanNotification( $scan_id );
    }

    /**
     * Check if notifications should be sent based on scan data
     *
     * @param array $scan_data Scan data
     * @return bool True if notifications should be sent
     */
    public function shouldSendNotifications( array $scan_data ): bool {
        // Don't send if scan failed
        if ( $scan_data['summary']['status'] !== 'completed' ) {
            return false;
        }

        // Check if there are any changes
        return $scan_data['summary']['changed_files'] > 0 || 
               $scan_data['summary']['new_files'] > 0 || 
               $scan_data['summary']['deleted_files'] > 0;
    }

    /**
     * Format scan results for notifications
     *
     * @param array $scan_data Raw scan data
     * @return array Formatted data for notifications
     */
    public function formatScanResults( array $scan_data ): array {
        $site_name = get_bloginfo( 'name' );
        $site_url = get_home_url();
        $admin_url = admin_url( 'admin.php?page=file-integrity-checker-results&scan_id=' . $scan_data['summary']['scan_id'] );
        
        // Determine if we should include file lists
        $include_file_list = $this->settingsService->shouldIncludeFileList();
        $max_files_to_show = 50; // Maximum files to show in detailed list
        $preview_files = 5; // Files to show in preview/summary
        
        $formatted = [
            'scan_id' => $scan_data['summary']['scan_id'],
            'site_name' => $site_name,
            'site_url' => $site_url,
            'admin_url' => $admin_url,
            'scan_date' => mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $scan_data['summary']['scan_date'] ),
            'scan_type' => $scan_data['summary']['scan_type'],
            'statistics' => [
                'total_files' => $scan_data['summary']['total_files'],
                'changed_files' => $scan_data['summary']['changed_files'],
                'new_files' => $scan_data['summary']['new_files'],
                'deleted_files' => $scan_data['summary']['deleted_files'],
                'scan_duration' => $scan_data['summary']['scan_duration'],
                'memory_usage' => $scan_data['summary']['memory_usage']
            ],
            'changes' => []
        ];

        // Include file details if enabled
        if ( $include_file_list && ! empty( $scan_data['changed_files'] ) ) {
            $formatted['changes']['detailed'] = array_slice( $scan_data['changed_files'], 0, $max_files_to_show );
            $formatted['changes']['preview'] = array_slice( $scan_data['changed_files'], 0, $preview_files );
            $formatted['changes']['total_count'] = count( $scan_data['changed_files'] );
            $formatted['changes']['has_more'] = count( $scan_data['changed_files'] ) > $max_files_to_show;
            $formatted['changes']['remaining_count'] = max( 0, count( $scan_data['changed_files'] ) - $max_files_to_show );
        }

        return $formatted;
    }

    /**
     * Send email notification
     *
     * @param array $formatted_data Formatted scan data
     * @return array Result array with success status and error message if applicable
     */
    private function sendEmailNotification( array $formatted_data ): array {
        $email_to = $this->settingsService->getNotificationEmail();
        
        if ( empty( $email_to ) ) {
            return [
                'success' => false,
                'error' => 'No email address configured'
            ];
        }

        // Get customizable subject and replace variables
        $subject_template = $this->settingsService->getEmailNotificationSubject();
        $subject = $this->replaceTemplateVariables( $subject_template, $formatted_data );

        $message = $this->buildEmailMessage( $formatted_data );

        // Get customizable from address
        $from_email = $this->settingsService->getEmailFromAddress();
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: File Integrity Checker <' . $from_email . '>',
        ];

        $sent = $this->sendEmail( $email_to, $subject, $message, $headers );

        if ( $sent ) {
            $this->logger->info(
                'Email notification sent successfully',
                LoggerService::CONTEXT_NOTIFICATIONS,
                [
                    'scan_id' => $formatted_data['scan_id'],
                    'recipient' => $email_to
                ]
            );
            return [ 'success' => true ];
        } else {
            $error = 'Failed to send email notification';
            $this->logger->error(
                $error,
                LoggerService::CONTEXT_NOTIFICATIONS,
                [
                    'scan_id' => $formatted_data['scan_id'],
                    'recipient' => $email_to
                ]
            );
            return [
                'success' => false,
                'error' => $error
            ];
        }
    }

    /**
     * Send Slack notification
     *
     * @param array $formatted_data Formatted scan data
     * @return array Result array with success status and error message if applicable
     */
    private function sendSlackNotification( array $formatted_data ): array {
        $webhook_url = $this->settingsService->getSlackWebhookUrl();
        
        if ( empty( $webhook_url ) ) {
            return [
                'success' => false,
                'error' => 'No Slack webhook URL configured'
            ];
        }

        $message = $this->buildSlackMessage( $formatted_data );
        
        $sent = $this->sendSlack( $webhook_url, $message['text'], $message['blocks'] );

        if ( $sent['success'] ) {
            $this->logger->info(
                'Slack notification sent successfully',
                LoggerService::CONTEXT_NOTIFICATIONS,
                [ 'scan_id' => $formatted_data['scan_id'] ]
            );
            return [ 'success' => true ];
        } else {
            $this->logger->error(
                'Failed to send Slack notification: ' . $sent['error'],
                LoggerService::CONTEXT_NOTIFICATIONS,
                [
                    'scan_id' => $formatted_data['scan_id'],
                    'error' => $sent['error']
                ]
            );
            return $sent;
        }
    }

    /**
     * Send email using WordPress mail function
     *
     * @param string $to      Recipient email address
     * @param string $subject Email subject
     * @param string $message Email message (HTML)
     * @param array  $headers Email headers
     * @return bool True if sent successfully
     */
    public function sendEmail( string $to, string $subject, string $message, array $headers = [] ): bool {
        return wp_mail( $to, $subject, $message, $headers );
    }

    /**
     * Send Slack notification via webhook
     *
     * @param string $webhook_url Slack webhook URL
     * @param string $text        Fallback text
     * @param array  $blocks      Slack blocks for rich formatting
     * @return array Result array with success status and error message if applicable
     */
    public function sendSlack( string $webhook_url, string $text, array $blocks = [] ): array {
        $payload = [
            'text' => $text
        ];

        if ( ! empty( $blocks ) ) {
            $payload['blocks'] = $blocks;
        }

        $response = wp_remote_post( $webhook_url, [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode( $payload ),
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            return [
                'success' => false,
                'error' => $response->get_error_message()
            ];
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        
        if ( $response_code !== 200 ) {
            return [
                'success' => false,
                'error' => 'Slack webhook returned error code: ' . $response_code
            ];
        }

        return [ 'success' => true ];
    }

    /**
     * Build email message HTML
     *
     * @param array $formatted_data Formatted scan data
     * @return string HTML email message
     */
    private function buildEmailMessage( array $formatted_data ): string {
        $stats = $formatted_data['statistics'];
        
        $message = "<html><body>";
        $message .= "<h2>File Integrity Scan Results</h2>";
        $message .= "<p><strong>Site:</strong> <a href=\"{$formatted_data['site_url']}\">{$formatted_data['site_name']}</a></p>";
        $message .= "<p><strong>Scan Date:</strong> {$formatted_data['scan_date']}</p>";
        $message .= "<p><strong>Scan Type:</strong> " . ucfirst( $formatted_data['scan_type'] ) . "</p>";
        
        $message .= "<h3>Summary</h3>";
        $message .= "<ul>";
        $message .= "<li><strong>Total Files:</strong> " . number_format( $stats['total_files'] ) . "</li>";
        $message .= "<li><strong>Changed Files:</strong> " . number_format( $stats['changed_files'] ) . "</li>";
        $message .= "<li><strong>New Files:</strong> " . number_format( $stats['new_files'] ) . "</li>";
        $message .= "<li><strong>Deleted Files:</strong> " . number_format( $stats['deleted_files'] ) . "</li>";
        $message .= "<li><strong>Scan Duration:</strong> {$stats['scan_duration']} seconds</li>";
        $message .= "</ul>";

        // Include file list if available
        if ( ! empty( $formatted_data['changes']['detailed'] ) ) {
            $message .= "<h3>Changed Files</h3>";
            $message .= "<table border='1' cellpadding='5' cellspacing='0' style='border-collapse: collapse;'>";
            $message .= "<tr><th>File Path</th><th>Status</th><th>Size</th></tr>";
            
            foreach ( $formatted_data['changes']['detailed'] as $file ) {
                $status_color = match( $file->status ) {
                    'new' => 'green',
                    'changed' => 'orange',
                    'deleted' => 'red',
                    default => 'black'
                };
                
                $message .= "<tr>";
                $message .= "<td>" . esc_html( $file->file_path ) . "</td>";
                $message .= "<td style='color: $status_color'>" . ucfirst( $file->status ) . "</td>";
                $message .= "<td>" . size_format( $file->file_size ) . "</td>";
                $message .= "</tr>";
            }
            
            $message .= "</table>";
            
            if ( $formatted_data['changes']['has_more'] ) {
                $remaining = $formatted_data['changes']['remaining_count'];
                $message .= "<p><em>... and $remaining more files. Check the admin panel for full details.</em></p>";
            }
        }

        $message .= "<p><a href=\"{$formatted_data['admin_url']}\" style='display: inline-block; padding: 10px 20px; background: #0073aa; color: white; text-decoration: none; border-radius: 3px;'>View Full Scan Results</a></p>";
        
        $message .= "</body></html>";

        return $message;
    }

    /**
     * Build Slack message with blocks
     *
     * @param array $formatted_data Formatted scan data
     * @return array Slack message data
     */
    private function buildSlackMessage( array $formatted_data ): array {
        $stats = $formatted_data['statistics'];
        
        // Get customizable header and message template
        $header = $this->settingsService->getSlackNotificationHeader();
        $message_template = $this->settingsService->getSlackMessageTemplate();
        $message_text = $this->replaceTemplateVariables( $message_template, $formatted_data );
        
        // Build Slack blocks
        $blocks = [
            [
                'type' => 'header',
                'text' => [
                    'type' => 'plain_text',
                    'text' => $header,
                    'emoji' => true
                ]
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => sprintf(
                        "*%s*\n\n" .
                        "• *Changed Files:* %d\n" .
                        "• *New Files:* %d\n" .
                        "• *Deleted Files:* %d\n" .
                        "• *Total Files Scanned:* %d",
                        $message_text,
                        $stats['changed_files'],
                        $stats['new_files'],
                        $stats['deleted_files'],
                        $stats['total_files']
                    )
                ]
            ]
        ];
        
        // Add file preview if available
        if ( ! empty( $formatted_data['changes']['preview'] ) ) {
            $file_text = "";
            
            foreach ( $formatted_data['changes']['preview'] as $file ) {
                $status_icon = match( $file->status ) {
                    'changed' => '📝',
                    'new' => '➕',
                    'deleted' => '❌',
                    default => '•'
                };
                $file_text .= sprintf( "%s `%s`\n", $status_icon, basename( $file->file_path ) );
            }
            
            if ( $formatted_data['changes']['has_more'] || 
                 $formatted_data['changes']['total_count'] > count( $formatted_data['changes']['preview'] ) ) {
                $remaining = $formatted_data['changes']['total_count'] - count( $formatted_data['changes']['preview'] );
                $file_text .= sprintf( "_...and %d more files_", $remaining );
            }
            
            $blocks[] = [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "*Affected Files:*\n" . $file_text
                ]
            ];
        }
        
        // Add action button
        $blocks[] = [
            'type' => 'actions',
            'elements' => [
                [
                    'type' => 'button',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => 'View Scan Details',
                        'emoji' => true
                    ],
                    'url' => $formatted_data['admin_url'],
                    'style' => 'primary'
                ]
            ]
        ];
        
        // Add context
        $blocks[] = [
            'type' => 'context',
            'elements' => [
                [
                    'type' => 'mrkdwn',
                    'text' => sprintf( 
                        'Site: %s | Scan Type: %s | Duration: %ds', 
                        $formatted_data['site_url'],
                        ucfirst( $formatted_data['scan_type'] ),
                        $stats['scan_duration']
                    )
                ]
            ]
        ];
        
        // Use customized header for fallback text too
        $fallback_text = sprintf( '%s: %s', $header, $message_text );
        
        return [
            'text' => $fallback_text,
            'blocks' => $blocks
        ];
    }

    /**
     * Replace template variables with actual values
     *
     * @param string $template Template string with variables
     * @param array $data Data to replace variables with
     * @return string Processed string with variables replaced
     */
    private function replaceTemplateVariables( string $template, array $data ): string {
        $replacements = [
            '%site_name%' => $data['site_name'],
            '%site_url%' => $data['site_url'],
            '%scan_date%' => $data['scan_date'],
            '%scan_type%' => ucfirst( $data['scan_type'] ),
            '%total_files%' => number_format( $data['statistics']['total_files'] ),
            '%changed_files%' => number_format( $data['statistics']['changed_files'] ),
            '%new_files%' => number_format( $data['statistics']['new_files'] ),
            '%deleted_files%' => number_format( $data['statistics']['deleted_files'] ),
            '%scan_duration%' => $data['statistics']['scan_duration'],
        ];
        
        return str_replace( array_keys( $replacements ), array_values( $replacements ), $template );
    }

    /**
     * Get scan data for notifications
     *
     * @param int $scan_id Scan result ID
     * @return array|null Scan data or null if not found
     */
    private function getScanData( int $scan_id ): ?array {
        $scan_result = $this->scanResultsRepository->getById( $scan_id );
        
        if ( ! $scan_result ) {
            return null;
        }

        // Get file statistics
        $file_stats = $this->fileRecordRepository->getStatistics( $scan_id );
        
        // Get changed files if needed
        $changed_files = [];
        if ( $this->settingsService->shouldIncludeFileList() ) {
            $changed_files = $this->fileRecordRepository->getChangedFiles( $scan_id );
        }

        return [
            'summary' => [
                'scan_id' => $scan_result->id,
                'scan_date' => $scan_result->scan_date,
                'status' => $scan_result->status,
                'scan_type' => $scan_result->scan_type,
                'total_files' => $scan_result->total_files,
                'changed_files' => $scan_result->changed_files,
                'new_files' => $scan_result->new_files,
                'deleted_files' => $scan_result->deleted_files,
                'scan_duration' => $scan_result->scan_duration,
                'memory_usage' => $scan_result->memory_usage,
                'notes' => $scan_result->notes
            ],
            'changed_files' => $changed_files
        ];
    }
}