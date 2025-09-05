<?php
/**
 * Integration Tests for FileScanner
 */

namespace EightyFourEM\FileIntegrityChecker\Tests\Integration;

use PHPUnit\Framework\TestCase;
use EightyFourEM\FileIntegrityChecker\Scanner\FileScanner;
use EightyFourEM\FileIntegrityChecker\Scanner\ChecksumGenerator;
use EightyFourEM\FileIntegrityChecker\Services\SettingsService;

class FileScannerIntegrationTest extends TestCase {
    private FileScanner $fileScanner;
    private string $testDirectory;
    private array $testFiles = [];

    protected function setUp(): void {
        $checksumGenerator = new ChecksumGenerator();
        $settingsService = new SettingsService();
        
        $this->fileScanner = new FileScanner( $checksumGenerator, $settingsService );
        
        // Create temporary test directory
        $this->testDirectory = sys_get_temp_dir() . '/file_scanner_test_' . uniqid();
        mkdir( $this->testDirectory, 0755, true );
        
        $this->createTestFiles();
    }

    protected function tearDown(): void {
        $this->cleanupTestFiles();
    }

    private function createTestFiles(): void {
        $files = [
            'test.php' => '<?php echo "Hello World";',
            'style.css' => 'body { color: red; }',
            'script.js' => 'console.log("test");',
            'readme.txt' => 'This is a readme file',
            'image.png' => 'fake image content', // Not a real PNG but sufficient for testing
            'subdir/nested.php' => '<?php echo "Nested file";',
        ];

        foreach ( $files as $relativePath => $content ) {
            $fullPath = $this->testDirectory . '/' . $relativePath;
            $dir = dirname( $fullPath );
            
            if ( ! is_dir( $dir ) ) {
                mkdir( $dir, 0755, true );
            }
            
            file_put_contents( $fullPath, $content );
            $this->testFiles[] = $fullPath;
        }
    }

    private function cleanupTestFiles(): void {
        foreach ( $this->testFiles as $file ) {
            if ( file_exists( $file ) ) {
                unlink( $file );
            }
        }
        
        // Remove directories
        if ( is_dir( $this->testDirectory . '/subdir' ) ) {
            rmdir( $this->testDirectory . '/subdir' );
        }
        
        if ( is_dir( $this->testDirectory ) ) {
            rmdir( $this->testDirectory );
        }
    }

    public function testScanDirectoryFindsAllFiles(): void {
        $scannedFiles = $this->fileScanner->scanDirectory( $this->testDirectory );
        
        // Should find PHP, CSS, JS files (default file types), but not TXT or PNG
        $this->assertGreaterThan( 0, count( $scannedFiles ) );
        
        $foundPaths = array_column( $scannedFiles, 'file_path' );
        $expectedPaths = [
            $this->testDirectory . '/test.php',
            $this->testDirectory . '/style.css',
            $this->testDirectory . '/script.js',
            $this->testDirectory . '/subdir/nested.php',
        ];
        
        foreach ( $expectedPaths as $expectedPath ) {
            $this->assertContains( $expectedPath, $foundPaths );
        }
    }

    public function testScanDirectoryFileStructure(): void {
        $scannedFiles = $this->fileScanner->scanDirectory( $this->testDirectory );
        
        foreach ( $scannedFiles as $fileData ) {
            $this->assertArrayHasKey( 'file_path', $fileData );
            $this->assertArrayHasKey( 'file_size', $fileData );
            $this->assertArrayHasKey( 'checksum', $fileData );
            $this->assertArrayHasKey( 'last_modified', $fileData );
            $this->assertArrayHasKey( 'status', $fileData );
            
            $this->assertIsString( $fileData['file_path'] );
            $this->assertIsInt( $fileData['file_size'] );
            $this->assertIsString( $fileData['checksum'] );
            $this->assertIsString( $fileData['last_modified'] );
            $this->assertEquals( 'new', $fileData['status'] );
        }
    }

    public function testCompareScansDetectsNewFiles(): void {
        $firstScan = $this->fileScanner->scanDirectory( $this->testDirectory );
        
        // Create a new file
        $newFilePath = $this->testDirectory . '/new.php';
        file_put_contents( $newFilePath, '<?php echo "New file";' );
        $this->testFiles[] = $newFilePath;
        
        $secondScan = $this->fileScanner->scanDirectory( $this->testDirectory );
        
        // Convert first scan to the format expected by compareScans (simulating DB format)
        $previousFiles = [];
        foreach ( $firstScan as $file ) {
            $obj = new \stdClass();
            $obj->file_path = $file['file_path'];
            $obj->checksum = $file['checksum'];
            $obj->file_size = $file['file_size'];
            $obj->last_modified = $file['last_modified'];
            $previousFiles[] = $obj;
        }
        
        $comparedFiles = $this->fileScanner->compareScans( $secondScan, $previousFiles );
        
        // Find the new file
        $newFiles = array_filter( $comparedFiles, function ( $file ) {
            return $file['status'] === 'new';
        } );
        
        $this->assertCount( 1, $newFiles );
        
        $newFile = array_values( $newFiles )[0];
        $this->assertEquals( $newFilePath, $newFile['file_path'] );
    }

    public function testCompareScansDetectsChangedFiles(): void {
        $firstScan = $this->fileScanner->scanDirectory( $this->testDirectory );
        
        // Modify an existing file
        $testFile = $this->testDirectory . '/test.php';
        file_put_contents( $testFile, '<?php echo "Modified content";' );
        
        $secondScan = $this->fileScanner->scanDirectory( $this->testDirectory );
        
        // Convert first scan to DB format
        $previousFiles = [];
        foreach ( $firstScan as $file ) {
            $obj = new \stdClass();
            $obj->file_path = $file['file_path'];
            $obj->checksum = $file['checksum'];
            $obj->file_size = $file['file_size'];
            $obj->last_modified = $file['last_modified'];
            $previousFiles[] = $obj;
        }
        
        $comparedFiles = $this->fileScanner->compareScans( $secondScan, $previousFiles );
        
        // Find the changed file
        $changedFiles = array_filter( $comparedFiles, function ( $file ) use ( $testFile ) {
            return $file['status'] === 'changed' && $file['file_path'] === $testFile;
        } );
        
        $this->assertCount( 1, $changedFiles );
        
        $changedFile = array_values( $changedFiles )[0];
        $this->assertArrayHasKey( 'previous_checksum', $changedFile );
        $this->assertNotEquals( $changedFile['checksum'], $changedFile['previous_checksum'] );
    }

    public function testCompareScansDetectsDeletedFiles(): void {
        $firstScan = $this->fileScanner->scanDirectory( $this->testDirectory );
        
        // Delete a file
        $testFile = $this->testDirectory . '/test.php';
        unlink( $testFile );
        
        $secondScan = $this->fileScanner->scanDirectory( $this->testDirectory );
        
        // Convert first scan to DB format
        $previousFiles = [];
        foreach ( $firstScan as $file ) {
            $obj = new \stdClass();
            $obj->file_path = $file['file_path'];
            $obj->checksum = $file['checksum'];
            $obj->file_size = $file['file_size'];
            $obj->last_modified = $file['last_modified'];
            $previousFiles[] = $obj;
        }
        
        $comparedFiles = $this->fileScanner->compareScans( $secondScan, $previousFiles );
        
        // Find the deleted file
        $deletedFiles = array_filter( $comparedFiles, function ( $file ) use ( $testFile ) {
            return $file['status'] === 'deleted' && $file['file_path'] === $testFile;
        } );
        
        $this->assertCount( 1, $deletedFiles );
        
        $deletedFile = array_values( $deletedFiles )[0];
        $this->assertEquals( '', $deletedFile['checksum'] ); // Empty checksum for deleted files
        $this->assertArrayHasKey( 'previous_checksum', $deletedFile );
    }

    public function testGetStatistics(): void {
        $files = [
            [
                'file_path' => '/test/file1.php',
                'file_size' => 100,
                'checksum' => 'abc123',
                'status' => 'new',
                'last_modified' => '2023-01-01 12:00:00'
            ],
            [
                'file_path' => '/test/file2.php',
                'file_size' => 200,
                'checksum' => 'def456',
                'status' => 'changed',
                'last_modified' => '2023-01-01 12:00:00'
            ],
            [
                'file_path' => '/test/file3.php',
                'file_size' => 150,
                'checksum' => '',
                'status' => 'deleted',
                'last_modified' => '2023-01-01 12:00:00'
            ],
            [
                'file_path' => '/test/file4.php',
                'file_size' => 300,
                'checksum' => 'ghi789',
                'status' => 'unchanged',
                'last_modified' => '2023-01-01 12:00:00'
            ],
        ];
        
        $stats = $this->fileScanner->getStatistics( $files );
        
        $this->assertEquals( 4, $stats['total_files'] );
        $this->assertEquals( 1, $stats['new_files'] );
        $this->assertEquals( 1, $stats['changed_files'] );
        $this->assertEquals( 1, $stats['deleted_files'] );
        $this->assertEquals( 1, $stats['unchanged_files'] );
        $this->assertEquals( 750, $stats['total_size'] ); // Sum of all file sizes
    }

    public function testProgressCallback(): void {
        $callbackCalls = [];
        
        $progressCallback = function ( $count, $currentFile ) use ( &$callbackCalls ) {
            $callbackCalls[] = [ 'count' => $count, 'file' => $currentFile ];
        };
        
        $this->fileScanner->scanDirectory( $this->testDirectory, $progressCallback );
        
        $this->assertGreaterThan( 0, count( $callbackCalls ) );
        
        // Check final callback
        $lastCall = end( $callbackCalls );
        $this->assertIsArray( $lastCall );
        $this->assertArrayHasKey( 'count', $lastCall );
        $this->assertArrayHasKey( 'file', $lastCall );
    }
}