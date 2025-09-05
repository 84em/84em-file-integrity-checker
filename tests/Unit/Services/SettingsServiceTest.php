<?php
/**
 * Tests for SettingsService
 */

namespace EightyFourEM\FileIntegrityChecker\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use EightyFourEM\FileIntegrityChecker\Services\SettingsService;

class SettingsServiceTest extends TestCase {
    private SettingsService $settingsService;

    protected function setUp(): void {
        $this->settingsService = new SettingsService();
        
        // Reset options for each test
        $this->clearAllOptions();
    }

    protected function tearDown(): void {
        $this->clearAllOptions();
    }

    private function clearAllOptions(): void {
        $options = [
            'eightyfourem_file_integrity_scan_types',
            'eightyfourem_file_integrity_scan_interval',
            'eightyfourem_file_integrity_exclude_patterns',
            'eightyfourem_file_integrity_max_file_size',
            'eightyfourem_file_integrity_notification_enabled',
            'eightyfourem_file_integrity_notification_email',
            'eightyfourem_file_integrity_auto_schedule',
            'eightyfourem_file_integrity_retention_period',
        ];

        foreach ( $options as $option ) {
            delete_option( $option );
        }
    }

    public function testGetScanFileTypesDefault(): void {
        $types = $this->settingsService->getScanFileTypes();
        
        $this->assertEquals( [ '.js', '.css', '.html', '.php' ], $types );
    }

    public function testSetScanFileTypes(): void {
        $types = [ '.txt', '.md', '.json' ];
        
        $result = $this->settingsService->setScanFileTypes( $types );
        
        $this->assertTrue( $result );
        $this->assertEquals( $types, $this->settingsService->getScanFileTypes() );
    }

    public function testSetScanFileTypesNormalizesDotPrefix(): void {
        $types = [ 'txt', '.md', 'json' ];
        $expected = [ '.txt', '.md', '.json' ];
        
        $this->settingsService->setScanFileTypes( $types );
        
        $this->assertEquals( $expected, $this->settingsService->getScanFileTypes() );
    }

    public function testSetScanFileTypesRemovesDuplicates(): void {
        $types = [ '.txt', '.txt', '.md', '.md' ];
        $expected = [ '.txt', '.md' ];
        
        $this->settingsService->setScanFileTypes( $types );
        
        $this->assertEquals( $expected, $this->settingsService->getScanFileTypes() );
    }

    public function testGetScanIntervalDefault(): void {
        $interval = $this->settingsService->getScanInterval();
        
        $this->assertEquals( 'daily', $interval );
    }

    public function testSetScanIntervalValid(): void {
        $result = $this->settingsService->setScanInterval( 'weekly' );
        
        $this->assertTrue( $result );
        $this->assertEquals( 'weekly', $this->settingsService->getScanInterval() );
    }

    public function testSetScanIntervalInvalid(): void {
        $result = $this->settingsService->setScanInterval( 'invalid' );
        
        $this->assertFalse( $result );
        // Should maintain default
        $this->assertEquals( 'daily', $this->settingsService->getScanInterval() );
    }

    public function testGetExcludePatternsDefault(): void {
        $patterns = $this->settingsService->getExcludePatterns();
        
        $expected = [
            '*/cache/*',
            '*/logs/*',
            '*/uploads/*',
            '*/wp-content/cache/*',
            '*/wp-content/backup*',
        ];
        
        $this->assertEquals( $expected, $patterns );
    }

    public function testSetExcludePatterns(): void {
        $patterns = [ '*/temp/*', '*/node_modules/*' ];
        
        $result = $this->settingsService->setExcludePatterns( $patterns );
        
        $this->assertTrue( $result );
        $this->assertEquals( $patterns, $this->settingsService->getExcludePatterns() );
    }

    public function testSetExcludePatternsFiltersEmpty(): void {
        $patterns = [ '*/temp/*', '', '*/node_modules/*', '   ' ];
        $expected = [ '*/temp/*', '*/node_modules/*' ];
        
        $this->settingsService->setExcludePatterns( $patterns );
        
        $this->assertEquals( $expected, $this->settingsService->getExcludePatterns() );
    }

    public function testGetMaxFileSizeDefault(): void {
        $size = $this->settingsService->getMaxFileSize();
        
        $this->assertEquals( 10485760, $size ); // 10MB
    }

    public function testSetMaxFileSizeValid(): void {
        $size = 5242880; // 5MB
        
        $result = $this->settingsService->setMaxFileSize( $size );
        
        $this->assertTrue( $result );
        $this->assertEquals( $size, $this->settingsService->getMaxFileSize() );
    }

    public function testSetMaxFileSizeInvalid(): void {
        // Test size too large (over 100MB)
        $result = $this->settingsService->setMaxFileSize( 104857601 );
        $this->assertFalse( $result );
        
        // Test negative size
        $result = $this->settingsService->setMaxFileSize( -1 );
        $this->assertFalse( $result );
        
        // Test zero size
        $result = $this->settingsService->setMaxFileSize( 0 );
        $this->assertFalse( $result );
    }

    public function testIsNotificationEnabledDefault(): void {
        $enabled = $this->settingsService->isNotificationEnabled();
        
        $this->assertTrue( $enabled );
    }

    public function testSetNotificationEnabled(): void {
        $result = $this->settingsService->setNotificationEnabled( false );
        
        $this->assertTrue( $result );
        $this->assertFalse( $this->settingsService->isNotificationEnabled() );
    }

    public function testSetNotificationEmailValid(): void {
        $email = 'test@example.com';
        
        $result = $this->settingsService->setNotificationEmail( $email );
        
        $this->assertTrue( $result );
        $this->assertEquals( $email, $this->settingsService->getNotificationEmail() );
    }

    public function testSetNotificationEmailInvalid(): void {
        $result = $this->settingsService->setNotificationEmail( 'invalid-email' );
        
        $this->assertFalse( $result );
    }

    public function testGetRetentionPeriodDefault(): void {
        $period = $this->settingsService->getRetentionPeriod();
        
        $this->assertEquals( 90, $period );
    }

    public function testSetRetentionPeriodValid(): void {
        $period = 30;
        
        $result = $this->settingsService->setRetentionPeriod( $period );
        
        $this->assertTrue( $result );
        $this->assertEquals( $period, $this->settingsService->getRetentionPeriod() );
    }

    public function testSetRetentionPeriodInvalid(): void {
        // Test too small
        $result = $this->settingsService->setRetentionPeriod( 0 );
        $this->assertFalse( $result );
        
        // Test too large
        $result = $this->settingsService->setRetentionPeriod( 366 );
        $this->assertFalse( $result );
    }

    public function testGetAllSettings(): void {
        $settings = $this->settingsService->getAllSettings();
        
        $this->assertIsArray( $settings );
        $this->assertArrayHasKey( 'scan_types', $settings );
        $this->assertArrayHasKey( 'scan_interval', $settings );
        $this->assertArrayHasKey( 'exclude_patterns', $settings );
        $this->assertArrayHasKey( 'max_file_size', $settings );
        $this->assertArrayHasKey( 'notification_enabled', $settings );
        $this->assertArrayHasKey( 'notification_email', $settings );
        $this->assertArrayHasKey( 'auto_schedule', $settings );
        $this->assertArrayHasKey( 'retention_period', $settings );
    }

    public function testUpdateSettings(): void {
        $settings = [
            'scan_interval' => 'weekly',
            'max_file_size' => 5242880,
            'notification_enabled' => false,
        ];
        
        $results = $this->settingsService->updateSettings( $settings );
        
        $this->assertTrue( $results['scan_interval'] );
        $this->assertTrue( $results['max_file_size'] );
        $this->assertTrue( $results['notification_enabled'] );
        
        // Verify the settings were actually updated
        $this->assertEquals( 'weekly', $this->settingsService->getScanInterval() );
        $this->assertEquals( 5242880, $this->settingsService->getMaxFileSize() );
        $this->assertFalse( $this->settingsService->isNotificationEnabled() );
    }

    public function testUpdateSettingsPartialFailure(): void {
        $settings = [
            'scan_interval' => 'weekly',      // Valid
            'max_file_size' => -1,            // Invalid
            'notification_enabled' => false,  // Valid
        ];
        
        $results = $this->settingsService->updateSettings( $settings );
        
        $this->assertTrue( $results['scan_interval'] );
        $this->assertFalse( $results['max_file_size'] );
        $this->assertTrue( $results['notification_enabled'] );
    }

    public function testResetToDefaults(): void {
        // First set some custom values
        $this->settingsService->setScanInterval( 'weekly' );
        $this->settingsService->setMaxFileSize( 1048576 );
        
        // Reset to defaults
        $result = $this->settingsService->resetToDefaults();
        
        $this->assertTrue( $result );
        
        // Verify defaults are restored
        $this->assertEquals( 'daily', $this->settingsService->getScanInterval() );
        $this->assertEquals( 10485760, $this->settingsService->getMaxFileSize() );
    }
}