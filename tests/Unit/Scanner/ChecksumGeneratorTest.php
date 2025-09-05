<?php
/**
 * Tests for ChecksumGenerator
 */

namespace EightyFourEM\FileIntegrityChecker\Tests\Unit\Scanner;

use PHPUnit\Framework\TestCase;
use EightyFourEM\FileIntegrityChecker\Scanner\ChecksumGenerator;

class ChecksumGeneratorTest extends TestCase {
    private ChecksumGenerator $checksumGenerator;
    private string $testFilePath;
    private string $testFileContent = "This is test content for checksum generation.";

    protected function setUp(): void {
        $this->checksumGenerator = new ChecksumGenerator();
        
        // Create a temporary test file
        $this->testFilePath = sys_get_temp_dir() . '/test_file_' . uniqid() . '.txt';
        file_put_contents( $this->testFilePath, $this->testFileContent );
    }

    protected function tearDown(): void {
        if ( file_exists( $this->testFilePath ) ) {
            unlink( $this->testFilePath );
        }
    }

    public function testGenerateChecksumForValidFile(): void {
        $checksum = $this->checksumGenerator->generateChecksum( $this->testFilePath );
        
        $this->assertIsString( $checksum );
        $this->assertEquals( 64, strlen( $checksum ) ); // SHA256 is 64 characters
        $this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $checksum );
    }

    public function testGenerateChecksumForNonexistentFile(): void {
        $checksum = $this->checksumGenerator->generateChecksum( '/nonexistent/file.txt' );
        
        $this->assertFalse( $checksum );
    }

    public function testGenerateChecksumConsistency(): void {
        $checksum1 = $this->checksumGenerator->generateChecksum( $this->testFilePath );
        $checksum2 = $this->checksumGenerator->generateChecksum( $this->testFilePath );
        
        $this->assertEquals( $checksum1, $checksum2, 'Checksums should be identical for the same file' );
    }

    public function testGenerateChecksumDetectsChanges(): void {
        $originalChecksum = $this->checksumGenerator->generateChecksum( $this->testFilePath );
        
        // Modify the file
        file_put_contents( $this->testFilePath, $this->testFileContent . ' Modified!' );
        
        $modifiedChecksum = $this->checksumGenerator->generateChecksum( $this->testFilePath );
        
        $this->assertNotEquals( $originalChecksum, $modifiedChecksum, 'Checksums should differ for modified files' );
    }

    public function testGenerateBatchChecksums(): void {
        // Create multiple test files
        $testFiles = [];
        for ( $i = 0; $i < 3; $i++ ) {
            $filePath = sys_get_temp_dir() . '/batch_test_' . $i . '_' . uniqid() . '.txt';
            file_put_contents( $filePath, "Test content $i" );
            $testFiles[] = $filePath;
        }

        $checksums = $this->checksumGenerator->generateBatchChecksums( $testFiles );

        $this->assertCount( 3, $checksums );
        
        foreach ( $testFiles as $file ) {
            $this->assertArrayHasKey( $file, $checksums );
            $this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $checksums[$file] );
        }

        // Clean up test files
        foreach ( $testFiles as $file ) {
            unlink( $file );
        }
    }

    public function testVerifyChecksumCorrect(): void {
        $checksum = $this->checksumGenerator->generateChecksum( $this->testFilePath );
        
        $isValid = $this->checksumGenerator->verifyChecksum( $this->testFilePath, $checksum );
        
        $this->assertTrue( $isValid );
    }

    public function testVerifyChecksumIncorrect(): void {
        $wrongChecksum = str_repeat( 'a', 64 );
        
        $isValid = $this->checksumGenerator->verifyChecksum( $this->testFilePath, $wrongChecksum );
        
        $this->assertFalse( $isValid );
    }

    public function testGetHashAlgorithm(): void {
        $algorithm = $this->checksumGenerator->getHashAlgorithm();
        
        $this->assertEquals( 'sha256', $algorithm );
    }

    public function testIsAlgorithmSupported(): void {
        $isSupported = $this->checksumGenerator->isAlgorithmSupported();
        
        $this->assertTrue( $isSupported );
    }

    public function testGetSupportedAlgorithms(): void {
        $algorithms = $this->checksumGenerator->getSupportedAlgorithms();
        
        $this->assertIsArray( $algorithms );
        $this->assertContains( 'sha256', $algorithms );
    }

    public function testGenerateChecksumForEmptyFile(): void {
        $emptyFilePath = sys_get_temp_dir() . '/empty_test_' . uniqid() . '.txt';
        file_put_contents( $emptyFilePath, '' );
        
        $checksum = $this->checksumGenerator->generateChecksum( $emptyFilePath );
        
        // Empty files should return false as per the implementation
        $this->assertFalse( $checksum );
        
        unlink( $emptyFilePath );
    }

    public function testBatchChecksumHandlesNonexistentFiles(): void {
        $files = [
            $this->testFilePath,
            '/nonexistent/file1.txt',
            '/nonexistent/file2.txt'
        ];

        $checksums = $this->checksumGenerator->generateBatchChecksums( $files );

        // Should only include the valid file
        $this->assertCount( 1, $checksums );
        $this->assertArrayHasKey( $this->testFilePath, $checksums );
    }
}