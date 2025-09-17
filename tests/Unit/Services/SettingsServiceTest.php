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
        // Clear all test options
        global $test_options;
        $test_options = [];
    }

    public function testGetScanFileTypesDefault(): void {
        $types = $this->settingsService->getScanFileTypes();

        $this->assertEquals( [ '.js', '.css', '.html', '.php', '.htaccess', '.htm' ], $types );
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
        // array_unique preserves keys, so we expect [0 => '.txt', 2 => '.md']
        $expected = [ 0 => '.txt', 2 => '.md' ];

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
            '*/node_modules/*',
            '*/.git/*',
            '*/.svn/*',
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
        // array_filter preserves keys, so we expect [0 => '*/temp/*', 2 => '*/node_modules/*']
        $expected = [ 0 => '*/temp/*', 2 => '*/node_modules/*' ];

        $this->settingsService->setExcludePatterns( $patterns );

        $this->assertEquals( $expected, $this->settingsService->getExcludePatterns() );
    }

    public function testGetMaxFileSizeDefault(): void {
        $size = $this->settingsService->getMaxFileSize();

        $this->assertEquals( 1048576, $size ); // 1MB
    }

    public function testSetMaxFileSizeValid(): void {
        $size = 524288; // 512KB

        $result = $this->settingsService->setMaxFileSize( $size );

        $this->assertTrue( $result );
        $this->assertEquals( $size, $this->settingsService->getMaxFileSize() );
    }

    public function testSetMaxFileSizeInvalid(): void {
        // Test size too large (over 1MB)
        $result = $this->settingsService->setMaxFileSize( 1048577 );
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
        $this->assertArrayHasKey( 'retention_period', $settings );
    }

    public function testUpdateSettings(): void {
        $settings = [
            'scan_interval' => 'weekly',
            'max_file_size' => 524288,  // 512KB - within 1MB limit
            'notification_enabled' => false,
        ];
        
        $results = $this->settingsService->updateSettings( $settings );
        
        $this->assertTrue( $results['scan_interval'] );
        $this->assertTrue( $results['max_file_size'] );
        $this->assertTrue( $results['notification_enabled'] );
        
        // Verify the settings were actually updated
        $this->assertEquals( 'weekly', $this->settingsService->getScanInterval() );
        $this->assertEquals( 524288, $this->settingsService->getMaxFileSize() );
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
        $this->settingsService->setMaxFileSize( 524288 );  // 512KB
        
        // Reset to defaults
        $result = $this->settingsService->resetToDefaults();
        
        $this->assertTrue( $result );
        
        // Verify defaults are restored
        $this->assertEquals( 'daily', $this->settingsService->getScanInterval() );
        $this->assertEquals( 1048576, $this->settingsService->getMaxFileSize() );  // 1MB default
    }
}