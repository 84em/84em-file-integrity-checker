<?php
/**
 * Test script for notification settings customization
 *
 * Run with: wp eval-file test-notification-settings.php
 */

// Load plugin dependencies
require_once __DIR__ . '/vendor/autoload.php';

use EightyFourEM\FileIntegrityChecker\Services\SettingsService;
use EightyFourEM\FileIntegrityChecker\Services\NotificationService;

// Initialize settings service
$settings_service = new SettingsService();

echo "Testing Notification Settings Customization\n";
echo "==========================================\n\n";

// Test email customization settings
echo "1. Testing Email Customization Settings:\n";
echo "-----------------------------------------\n";

// Set custom email subject
$custom_subject = '[%site_name%] Security Alert - %changed_files% files modified on %scan_date%';
$result = $settings_service->setEmailNotificationSubject( $custom_subject );
echo "Setting custom email subject: " . ( $result ? 'SUCCESS' : 'FAILED' ) . "\n";
echo "Subject template: " . $settings_service->getEmailNotificationSubject() . "\n\n";

// Set custom from address
$custom_from = 'security@example.com';
$result = $settings_service->setEmailFromAddress( $custom_from );
echo "Setting custom from address: " . ( $result ? 'SUCCESS' : 'FAILED' ) . "\n";
echo "From address: " . $settings_service->getEmailFromAddress() . "\n\n";

// Test Slack customization settings
echo "2. Testing Slack Customization Settings:\n";
echo "-----------------------------------------\n";

// Set custom Slack header
$custom_header = '🔒 Security Alert for %site_name%';
$result = $settings_service->setSlackNotificationHeader( $custom_header );
echo "Setting custom Slack header: " . ( $result ? 'SUCCESS' : 'FAILED' ) . "\n";
echo "Header: " . $settings_service->getSlackNotificationHeader() . "\n\n";

// Set custom Slack message template
$custom_message = 'Alert: %changed_files% files changed, %new_files% added, %deleted_files% removed on %site_name%';
$result = $settings_service->setSlackMessageTemplate( $custom_message );
echo "Setting custom Slack message: " . ( $result ? 'SUCCESS' : 'FAILED' ) . "\n";
echo "Message template: " . $settings_service->getSlackMessageTemplate() . "\n\n";

// Test template variable replacement
echo "3. Testing Template Variable Replacement:\n";
echo "-----------------------------------------\n";

// Create mock data for testing
$test_data = [
    'site_name' => 'Test WordPress Site',
    'site_url' => 'https://example.com',
    'scan_date' => '2025-01-07 10:30:00',
    'statistics' => [
        'total_files' => 1500,
        'changed_files' => 25,
        'new_files' => 3,
        'deleted_files' => 2,
        'scan_duration' => 45
    ]
];

// Use reflection to test private method
$reflection = new ReflectionClass( NotificationService::class );
$method = $reflection->getMethod( 'replaceTemplateVariables' );
$method->setAccessible( true );

// Create a mock notification service (we need the other dependencies too)
$logger = new \EightyFourEM\FileIntegrityChecker\Services\LoggerService();
$scan_repo = new \EightyFourEM\FileIntegrityChecker\Database\ScanResultsRepository();
$file_repo = new \EightyFourEM\FileIntegrityChecker\Database\FileRecordRepository();
$notification_service = new NotificationService( $settings_service, $scan_repo, $file_repo, $logger );

// Test email subject replacement
$processed_subject = $method->invoke( $notification_service, $custom_subject, $test_data );
echo "Processed email subject: $processed_subject\n";

// Test Slack message replacement
$processed_message = $method->invoke( $notification_service, $custom_message, $test_data );
echo "Processed Slack message: $processed_message\n\n";

// Test default values
echo "4. Testing Default Values:\n";
echo "--------------------------\n";

// Reset to empty to test defaults
$settings_service->setEmailNotificationSubject( '' );
$settings_service->setEmailFromAddress( '' );
$settings_service->setSlackNotificationHeader( '' );
$settings_service->setSlackMessageTemplate( '' );

echo "Default email subject: " . $settings_service->getEmailNotificationSubject() . "\n";
echo "Default from address: " . $settings_service->getEmailFromAddress() . "\n";
echo "Default Slack header: " . $settings_service->getSlackNotificationHeader() . "\n";
echo "Default Slack message: " . $settings_service->getSlackMessageTemplate() . "\n\n";

// Test validation
echo "5. Testing Input Validation:\n";
echo "-----------------------------\n";

// Test invalid email
$invalid_email = 'not-an-email';
$result = $settings_service->setEmailFromAddress( $invalid_email );
echo "Setting invalid email '$invalid_email': " . ( $result ? 'ACCEPTED (ERROR!)' : 'REJECTED (correct)' ) . "\n";

// Test valid email
$valid_email = 'test@example.com';
$result = $settings_service->setEmailFromAddress( $valid_email );
echo "Setting valid email '$valid_email': " . ( $result ? 'ACCEPTED (correct)' : 'REJECTED (ERROR!)' ) . "\n";

echo "\n✅ All tests completed!\n";