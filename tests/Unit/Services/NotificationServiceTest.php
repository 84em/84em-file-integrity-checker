<?php
/**
 * Notification Service Test
 */

namespace EightyFourEM\FileIntegrityChecker\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use EightyFourEM\FileIntegrityChecker\Services\NotificationService;
use EightyFourEM\FileIntegrityChecker\Services\SettingsService;
use EightyFourEM\FileIntegrityChecker\Services\LoggerService;
use EightyFourEM\FileIntegrityChecker\Database\ScanResultsRepository;
use EightyFourEM\FileIntegrityChecker\Database\FileRecordRepository;

class NotificationServiceTest extends TestCase {
    private NotificationService $service;
    private $settingsService;
    private $scanResultsRepository;
    private $fileRecordRepository;
    private $logger;

    protected function setUp(): void {
        parent::setUp();

        // Create mocks
        $this->settingsService = $this->createMock( SettingsService::class );
        $this->scanResultsRepository = $this->createMock( ScanResultsRepository::class );
        $this->fileRecordRepository = $this->createMock( FileRecordRepository::class );
        $this->logger = $this->createMock( LoggerService::class );

        // Create service with mocks
        $this->service = new NotificationService(
            $this->settingsService,
            $this->scanResultsRepository,
            $this->fileRecordRepository,
            $this->logger
        );
    }

    /**
     * Test that resendEmailNotification only sends email, not Slack
     */
    public function testResendEmailNotificationOnlySendsEmail() {
        $scan_id = 123;

        // Mock scan data
        $scan_data = (object) [
            'id' => $scan_id,
            'status' => 'completed',
            'scan_date' => '2024-01-01 12:00:00',
            'scan_type' => 'manual',
            'total_files' => 100,
            'changed_files' => 5,
            'new_files' => 2,
            'deleted_files' => 1,
            'scan_duration' => 10,
            'memory_usage' => 1000000,
            'notes' => ''
        ];

        // Setup repository to return scan data
        $this->scanResultsRepository->expects( $this->once() )
            ->method( 'getById' )
            ->with( $scan_id )
            ->willReturn( $scan_data );

        // Setup settings - email enabled, Slack enabled
        $this->settingsService->expects( $this->once() )
            ->method( 'isNotificationEnabled' )
            ->willReturn( true );

        // Slack should NOT be checked for email-only resend
        $this->settingsService->expects( $this->never() )
            ->method( 'isSlackEnabled' );

        $this->settingsService->expects( $this->once() )
            ->method( 'getNotificationEmail' )
            ->willReturn( 'admin@example.com' );

        $this->settingsService->expects( $this->once() )
            ->method( 'shouldIncludeFileList' )
            ->willReturn( false );

        // Mock other required methods
        $this->settingsService->expects( $this->once() )
            ->method( 'getEmailNotificationSubject' )
            ->willReturn( 'File Integrity Scan - %site_name%' );

        $this->settingsService->expects( $this->once() )
            ->method( 'getEmailFromAddress' )
            ->willReturn( 'noreply@example.com' );

        // File repository
        $this->fileRecordRepository->expects( $this->once() )
            ->method( 'getStatistics' )
            ->with( $scan_id )
            ->willReturn( [] );

        // Create a partial mock to verify sendEmail is called but sendSlack is not
        $service = $this->getMockBuilder( NotificationService::class )
            ->setConstructorArgs( [
                $this->settingsService,
                $this->scanResultsRepository,
                $this->fileRecordRepository,
                $this->logger
            ] )
            ->onlyMethods( [ 'sendEmail', 'sendSlack' ] )
            ->getMock();

        // Expect email to be sent
        $service->expects( $this->once() )
            ->method( 'sendEmail' )
            ->willReturn( true );

        // Slack should NOT be called
        $service->expects( $this->never() )
            ->method( 'sendSlack' );

        // Call the method
        $result = $service->resendEmailNotification( $scan_id );

        // Verify result
        $this->assertTrue( $result['success'] );
        $this->assertArrayHasKey( 'email', $result['channels'] );
        $this->assertArrayNotHasKey( 'slack', $result['channels'] );
    }

    /**
     * Test that resendSlackNotification only sends Slack, not email
     */
    public function testResendSlackNotificationOnlySendsSlack() {
        $scan_id = 456;

        // Mock scan data
        $scan_data = (object) [
            'id' => $scan_id,
            'status' => 'completed',
            'scan_date' => '2024-01-01 12:00:00',
            'scan_type' => 'manual',
            'total_files' => 100,
            'changed_files' => 5,
            'new_files' => 2,
            'deleted_files' => 1,
            'scan_duration' => 10,
            'memory_usage' => 1000000,
            'notes' => ''
        ];

        // Setup repository to return scan data
        $this->scanResultsRepository->expects( $this->once() )
            ->method( 'getById' )
            ->with( $scan_id )
            ->willReturn( $scan_data );

        // Setup settings - Slack enabled, email enabled
        $this->settingsService->expects( $this->once() )
            ->method( 'isSlackEnabled' )
            ->willReturn( true );

        // Email should NOT be checked for Slack-only resend
        $this->settingsService->expects( $this->never() )
            ->method( 'isNotificationEnabled' );

        $this->settingsService->expects( $this->once() )
            ->method( 'getSlackWebhookUrl' )
            ->willReturn( 'https://hooks.slack.com/services/TEST' );

        $this->settingsService->expects( $this->once() )
            ->method( 'shouldIncludeFileList' )
            ->willReturn( false );

        // Mock other required methods
        $this->settingsService->expects( $this->once() )
            ->method( 'getSlackNotificationHeader' )
            ->willReturn( 'File Integrity Alert' );

        $this->settingsService->expects( $this->once() )
            ->method( 'getSlackMessageTemplate' )
            ->willReturn( 'Changes detected on %site_name%' );

        // File repository
        $this->fileRecordRepository->expects( $this->once() )
            ->method( 'getStatistics' )
            ->with( $scan_id )
            ->willReturn( [] );

        // Create a partial mock to verify sendSlack is called but sendEmail is not
        $service = $this->getMockBuilder( NotificationService::class )
            ->setConstructorArgs( [
                $this->settingsService,
                $this->scanResultsRepository,
                $this->fileRecordRepository,
                $this->logger
            ] )
            ->onlyMethods( [ 'sendEmail', 'sendSlack' ] )
            ->getMock();

        // Slack should be sent
        $service->expects( $this->once() )
            ->method( 'sendSlack' )
            ->willReturn( [ 'success' => true ] );

        // Email should NOT be called
        $service->expects( $this->never() )
            ->method( 'sendEmail' );

        // Call the method
        $result = $service->resendSlackNotification( $scan_id );

        // Verify result
        $this->assertTrue( $result['success'] );
        $this->assertArrayHasKey( 'slack', $result['channels'] );
        $this->assertArrayNotHasKey( 'email', $result['channels'] );
    }
}