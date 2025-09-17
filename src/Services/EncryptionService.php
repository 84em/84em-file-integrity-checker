<?php
/**
 * Encryption Service
 *
 * @package EightyFourEM\FileIntegrityChecker\Services
 */

namespace EightyFourEM\FileIntegrityChecker\Services;

/**
 * Service for encrypting and decrypting sensitive data
 *
 * Uses AES-256-GCM for authenticated encryption with:
 * - Unique IV per encryption operation
 * - Authentication tag for integrity verification
 * - Key derivation from WordPress salts
 */
class EncryptionService {
    /**
     * Encryption cipher method
     */
    private const CIPHER_METHOD = 'aes-256-gcm';

    /**
     * IV length for AES-256-GCM
     */
    private const IV_LENGTH = 16;

    /**
     * Key identifier for versioning
     */
    private const KEY_VERSION = 'v1';

    /**
     * Logger service
     *
     * @var LoggerService
     */
    private LoggerService $logger;

    /**
     * Encryption key
     *
     * @var string|null
     */
    private ?string $encryptionKey = null;

    /**
     * Constructor
     *
     * @param LoggerService $logger Logger service
     */
    public function __construct( LoggerService $logger ) {
        $this->logger = $logger;
    }

    /**
     * Encrypt data
     *
     * @param string $data Data to encrypt
     * @return string|false Base64 encoded encrypted data with IV and tag, or false on failure
     */
    public function encrypt( string $data ) {
        try {
            // Get encryption key
            $key = $this->getEncryptionKey();
            if ( ! $key ) {
                $this->logger->error( 'Failed to generate encryption key', 'encryption' );
                return false;
            }

            // Generate random IV
            $iv = openssl_random_pseudo_bytes( self::IV_LENGTH );
            if ( $iv === false ) {
                $this->logger->error( 'Failed to generate IV', 'encryption' );
                return false;
            }

            // Encrypt the data
            $tag = '';
            $encryptedData = openssl_encrypt(
                $data,
                self::CIPHER_METHOD,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            if ( $encryptedData === false ) {
                $this->logger->error( 'Encryption failed', 'encryption' );
                return false;
            }

            // Combine version, IV, tag, and encrypted data
            $combined = self::KEY_VERSION . '|' .
                       base64_encode( $iv ) . '|' .
                       base64_encode( $tag ) . '|' .
                       base64_encode( $encryptedData );

            // Return base64 encoded for safe storage
            return base64_encode( $combined );

        } catch ( \Exception $e ) {
            $this->logger->error(
                'Encryption exception: ' . $e->getMessage(),
                'encryption',
                [ 'trace' => $e->getTraceAsString() ]
            );
            return false;
        }
    }

    /**
     * Decrypt data
     *
     * @param string $encryptedData Base64 encoded encrypted data
     * @return string|false Decrypted data or false on failure
     */
    public function decrypt( string $encryptedData ) {
        try {
            // Decode the base64 data
            $decoded = base64_decode( $encryptedData, true );
            if ( $decoded === false ) {
                $this->logger->error( 'Invalid base64 data', 'encryption' );
                return false;
            }

            // Parse the components
            $parts = explode( '|', $decoded );
            if ( count( $parts ) !== 4 ) {
                $this->logger->error( 'Invalid encrypted data format', 'encryption' );
                return false;
            }

            list( $version, $ivBase64, $tagBase64, $dataBase64 ) = $parts;

            // Check version
            if ( $version !== self::KEY_VERSION ) {
                $this->logger->error(
                    'Unsupported encryption version: ' . $version,
                    'encryption'
                );
                return false;
            }

            // Decode components
            $iv = base64_decode( $ivBase64, true );
            $tag = base64_decode( $tagBase64, true );
            $ciphertext = base64_decode( $dataBase64, true );

            if ( $iv === false || $tag === false || $ciphertext === false ) {
                $this->logger->error( 'Failed to decode encryption components', 'encryption' );
                return false;
            }

            // Get encryption key
            $key = $this->getEncryptionKey();
            if ( ! $key ) {
                $this->logger->error( 'Failed to generate decryption key', 'encryption' );
                return false;
            }

            // Decrypt the data
            $decryptedData = openssl_decrypt(
                $ciphertext,
                self::CIPHER_METHOD,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            if ( $decryptedData === false ) {
                $this->logger->error( 'Decryption failed - possible data corruption or tampering', 'encryption' );
                return false;
            }

            return $decryptedData;

        } catch ( \Exception $e ) {
            $this->logger->error(
                'Decryption exception: ' . $e->getMessage(),
                'encryption',
                [ 'trace' => $e->getTraceAsString() ]
            );
            return false;
        }
    }

    /**
     * Get or generate encryption key
     *
     * @return string|false Encryption key or false on failure
     */
    private function getEncryptionKey() {
        if ( $this->encryptionKey !== null ) {
            return $this->encryptionKey;
        }

        // Use WordPress salts to derive key
        $salts = [
            defined( 'AUTH_KEY' ) ? AUTH_KEY : '',
            defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '',
            defined( 'LOGGED_IN_KEY' ) ? LOGGED_IN_KEY : '',
            defined( 'NONCE_KEY' ) ? NONCE_KEY : '',
        ];

        // Check if salts are properly defined
        $saltString = implode( '', $salts );
        if ( strlen( $saltString ) < 64 ) {
            $this->logger->error(
                'WordPress salts not properly configured',
                'encryption'
            );
            return false;
        }

        // Create a deterministic key from salts
        $keyMaterial = hash( 'sha512', $saltString . 'file_integrity_encryption' . self::KEY_VERSION, true );

        // Use first 32 bytes for AES-256
        $this->encryptionKey = substr( $keyMaterial, 0, 32 );

        return $this->encryptionKey;
    }

    /**
     * Check if encryption is available
     *
     * @return bool True if encryption can be used
     */
    public function isAvailable(): bool {
        // Check OpenSSL extension
        if ( ! extension_loaded( 'openssl' ) ) {
            return false;
        }

        // Check cipher availability
        $availableCiphers = openssl_get_cipher_methods();
        if ( ! in_array( self::CIPHER_METHOD, $availableCiphers, true ) ) {
            return false;
        }

        // Check if we can generate a key
        return $this->getEncryptionKey() !== false;
    }

    /**
     * Test encryption/decryption with sample data
     *
     * @return bool True if encryption works correctly
     */
    public function testEncryption(): bool {
        $testData = 'Test data for encryption verification: ' . wp_generate_password( 32 );

        $encrypted = $this->encrypt( $testData );
        if ( $encrypted === false ) {
            return false;
        }

        $decrypted = $this->decrypt( $encrypted );
        if ( $decrypted === false ) {
            return false;
        }

        return $decrypted === $testData;
    }

    /**
     * Get encryption statistics
     *
     * @return array Statistics about encryption
     */
    public function getStatistics(): array {
        return [
            'available' => $this->isAvailable(),
            'cipher' => self::CIPHER_METHOD,
            'key_version' => self::KEY_VERSION,
            'openssl_version' => defined( 'OPENSSL_VERSION_TEXT' ) ? OPENSSL_VERSION_TEXT : 'Unknown',
            'test_successful' => $this->isAvailable() ? $this->testEncryption() : false,
        ];
    }
}