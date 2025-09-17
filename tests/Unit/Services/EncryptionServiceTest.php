<?php
/**
 * Tests for EncryptionService
 *
 * @package EightyFourEM\FileIntegrityChecker\Tests\Unit\Services
 */

namespace EightyFourEM\FileIntegrityChecker\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use EightyFourEM\FileIntegrityChecker\Services\EncryptionService;
use EightyFourEM\FileIntegrityChecker\Services\LoggerService;

/**
 * Test encryption service functionality
 */
class EncryptionServiceTest extends TestCase {
    /**
     * Logger mock
     *
     * @var LoggerService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $loggerMock;

    /**
     * Encryption service
     *
     * @var EncryptionService
     */
    private EncryptionService $encryptionService;

    /**
     * Set up test environment
     */
    protected function setUp(): void {
        parent::setUp();

        // Define WordPress salts if not defined
        if ( ! defined( 'AUTH_KEY' ) ) {
            define( 'AUTH_KEY', 'test-auth-key-with-sufficient-length-for-testing-purposes' );
        }
        if ( ! defined( 'SECURE_AUTH_KEY' ) ) {
            define( 'SECURE_AUTH_KEY', 'test-secure-auth-key-with-sufficient-length-for-testing' );
        }
        if ( ! defined( 'LOGGED_IN_KEY' ) ) {
            define( 'LOGGED_IN_KEY', 'test-logged-in-key-with-sufficient-length-for-testing' );
        }
        if ( ! defined( 'NONCE_KEY' ) ) {
            define( 'NONCE_KEY', 'test-nonce-key-with-sufficient-length-for-testing-purposes' );
        }

        // Create logger mock
        $this->loggerMock = $this->createMock( LoggerService::class );

        // Create encryption service
        $this->encryptionService = new EncryptionService( $this->loggerMock );
    }

    /**
     * Test that encryption is available with proper setup
     */
    public function testEncryptionIsAvailable(): void {
        $this->assertTrue(
            $this->encryptionService->isAvailable(),
            'Encryption should be available when OpenSSL is installed and salts are configured'
        );
    }

    /**
     * Test basic encryption and decryption
     */
    public function testEncryptDecrypt(): void {
        $testData = 'This is sensitive test data that needs encryption';

        // Encrypt the data
        $encrypted = $this->encryptionService->encrypt( $testData );

        $this->assertNotFalse( $encrypted, 'Encryption should succeed' );
        $this->assertNotEquals( $testData, $encrypted, 'Encrypted data should be different from original' );
        $this->assertIsString( $encrypted, 'Encrypted data should be a string' );

        // Decrypt the data
        $decrypted = $this->encryptionService->decrypt( $encrypted );

        $this->assertNotFalse( $decrypted, 'Decryption should succeed' );
        $this->assertEquals( $testData, $decrypted, 'Decrypted data should match original' );
    }

    /**
     * Test encryption with empty data
     */
    public function testEncryptEmptyData(): void {
        $encrypted = $this->encryptionService->encrypt( '' );

        $this->assertNotFalse( $encrypted, 'Should encrypt empty string' );

        $decrypted = $this->encryptionService->decrypt( $encrypted );
        $this->assertEquals( '', $decrypted, 'Should decrypt to empty string' );
    }

    /**
     * Test encryption with large data
     */
    public function testEncryptLargeData(): void {
        // Generate 1MB of test data
        $testData = str_repeat( 'Large test data block. ', 50000 );

        $encrypted = $this->encryptionService->encrypt( $testData );

        $this->assertNotFalse( $encrypted, 'Should encrypt large data' );

        $decrypted = $this->encryptionService->decrypt( $encrypted );
        $this->assertEquals( $testData, $decrypted, 'Large data should decrypt correctly' );
    }

    /**
     * Test encryption with binary data
     */
    public function testEncryptBinaryData(): void {
        // Create binary data
        $testData = '';
        for ( $i = 0; $i < 256; $i++ ) {
            $testData .= chr( $i );
        }

        $encrypted = $this->encryptionService->encrypt( $testData );

        $this->assertNotFalse( $encrypted, 'Should encrypt binary data' );

        $decrypted = $this->encryptionService->decrypt( $encrypted );
        $this->assertEquals( $testData, $decrypted, 'Binary data should decrypt correctly' );
    }

    /**
     * Test that each encryption produces different ciphertext (due to random IV)
     */
    public function testEncryptionRandomness(): void {
        $testData = 'Test data for randomness check';

        $encrypted1 = $this->encryptionService->encrypt( $testData );
        $encrypted2 = $this->encryptionService->encrypt( $testData );

        $this->assertNotEquals(
            $encrypted1,
            $encrypted2,
            'Same data should produce different ciphertext due to random IV'
        );

        // Both should decrypt to the same value
        $this->assertEquals(
            $this->encryptionService->decrypt( $encrypted1 ),
            $this->encryptionService->decrypt( $encrypted2 ),
            'Both encryptions should decrypt to the same value'
        );
    }

    /**
     * Test decryption with invalid data
     */
    public function testDecryptInvalidData(): void {
        // Test with invalid base64
        $result = $this->encryptionService->decrypt( 'not-valid-base64!' );
        $this->assertFalse( $result, 'Should fail with invalid base64' );

        // Test with valid base64 but invalid format
        $result = $this->encryptionService->decrypt( base64_encode( 'invalid-format' ) );
        $this->assertFalse( $result, 'Should fail with invalid format' );

        // Test with wrong version
        $invalidData = base64_encode( 'v99|' . base64_encode( 'iv' ) . '|' . base64_encode( 'tag' ) . '|' . base64_encode( 'data' ) );
        $result = $this->encryptionService->decrypt( $invalidData );
        $this->assertFalse( $result, 'Should fail with unsupported version' );
    }

    /**
     * Test tamper detection
     */
    public function testTamperDetection(): void {
        $testData = 'Original data before tampering';

        $encrypted = $this->encryptionService->encrypt( $testData );

        // Tamper with the encrypted data
        $decoded = base64_decode( $encrypted );
        $parts = explode( '|', $decoded );

        // Modify the encrypted data part
        $parts[3] = base64_encode( 'tampered-data' );
        $tampered = base64_encode( implode( '|', $parts ) );

        // Decryption should fail due to authentication tag mismatch
        $result = $this->encryptionService->decrypt( $tampered );

        $this->assertFalse(
            $result,
            'Should detect tampering and fail decryption'
        );
    }

    /**
     * Test encryption statistics
     */
    public function testGetStatistics(): void {
        $stats = $this->encryptionService->getStatistics();

        $this->assertIsArray( $stats, 'Should return statistics array' );
        $this->assertArrayHasKey( 'available', $stats );
        $this->assertArrayHasKey( 'cipher', $stats );
        $this->assertArrayHasKey( 'key_version', $stats );
        $this->assertArrayHasKey( 'openssl_version', $stats );
        $this->assertArrayHasKey( 'test_successful', $stats );

        $this->assertEquals( 'aes-256-gcm', $stats['cipher'] );
        $this->assertEquals( 'v1', $stats['key_version'] );
        $this->assertTrue( $stats['available'] );
        $this->assertTrue( $stats['test_successful'] );
    }

    /**
     * Test the self-test functionality
     */
    public function testSelfTest(): void {
        $result = $this->encryptionService->testEncryption();

        $this->assertTrue(
            $result,
            'Self-test should pass with proper configuration'
        );
    }

    /**
     * Test encryption with special characters
     */
    public function testEncryptSpecialCharacters(): void {
        $testData = "Special chars: !@#$%^&*()_+-=[]{}|;':\",./<>?\n\t\r\0";

        $encrypted = $this->encryptionService->encrypt( $testData );
        $this->assertNotFalse( $encrypted, 'Should encrypt special characters' );

        $decrypted = $this->encryptionService->decrypt( $encrypted );
        $this->assertEquals( $testData, $decrypted, 'Special characters should decrypt correctly' );
    }

    /**
     * Test encryption with UTF-8 data
     */
    public function testEncryptUtf8Data(): void {
        $testData = 'UTF-8 test: 你好世界 🌍 Ñoño café';

        $encrypted = $this->encryptionService->encrypt( $testData );
        $this->assertNotFalse( $encrypted, 'Should encrypt UTF-8 data' );

        $decrypted = $this->encryptionService->decrypt( $encrypted );
        $this->assertEquals( $testData, $decrypted, 'UTF-8 data should decrypt correctly' );
    }
}