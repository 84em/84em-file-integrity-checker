<?php
/**
 * Tests for Activator
 *
 * @package EightyFourEM\FileIntegrityChecker\Tests\Unit\Core
 */

namespace EightyFourEM\FileIntegrityChecker\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use EightyFourEM\FileIntegrityChecker\Core\Activator;
use EightyFourEM\FileIntegrityChecker\Database\DatabaseManager;
use EightyFourEM\FileIntegrityChecker\Services\SchedulerService;
use EightyFourEM\FileIntegrityChecker\Services\LoggerService;

/**
 * Activator Test
 */
class ActivatorTest extends TestCase {
    private DatabaseManager $databaseManager;
    private SchedulerService $schedulerService;
    private LoggerService $loggerService;
    private Activator $activator;

    protected function setUp(): void {
        parent::setUp();

        // Create mock dependencies
        $this->databaseManager = $this->createMock( DatabaseManager::class );
        $this->schedulerService = $this->createMock( SchedulerService::class );
        $this->loggerService = $this->createMock( LoggerService::class );

        // Create Activator instance
        $this->activator = new Activator(
            $this->databaseManager,
            $this->schedulerService,
            $this->loggerService
        );
    }

    /**
     * Test that Activator can be instantiated
     */
    public function testActivatorCanBeInstantiated(): void {
        $this->assertInstanceOf( Activator::class, $this->activator );
    }

    /**
     * Test that activate method calls createTables
     *
     * Note: Full integration testing of migration scheduling logic
     * requires WordPress environment and Action Scheduler availability
     */
    public function testActivateMethodCallsCreateTables(): void {
        $this->databaseManager
            ->expects( $this->once() )
            ->method( 'createTables' );

        // The activate method will call createTables
        // Migration scheduling logic is tested via integration tests
        $this->activator->activate();
    }

    /**
     * Test migration applied check logic
     *
     * Note: This test verifies the method signature and accessibility
     * The actual migration logic requires WordPress environment and is tested via integration tests
     */
    public function testAreMigrationsAppliedMethodExists(): void {
        $reflection = new \ReflectionClass( $this->activator );

        $this->assertTrue(
            $reflection->hasMethod( 'areMigrationsApplied' ),
            'Activator should have areMigrationsApplied method'
        );

        $method = $reflection->getMethod( 'areMigrationsApplied' );
        $this->assertTrue(
            $method->isPrivate(),
            'areMigrationsApplied should be private'
        );
    }

    /**
     * Test scheduleMigrationsIfNeeded method exists
     */
    public function testScheduleMigrationsIfNeededMethodExists(): void {
        $reflection = new \ReflectionClass( $this->activator );

        $this->assertTrue(
            $reflection->hasMethod( 'scheduleMigrationsIfNeeded' ),
            'Activator should have scheduleMigrationsIfNeeded method'
        );

        $method = $reflection->getMethod( 'scheduleMigrationsIfNeeded' );
        $this->assertTrue(
            $method->isPrivate(),
            'scheduleMigrationsIfNeeded should be private'
        );
    }

    /**
     * Test setDefaultOptions method exists
     */
    public function testSetDefaultOptionsMethodExists(): void {
        $reflection = new \ReflectionClass( $this->activator );

        $this->assertTrue(
            $reflection->hasMethod( 'setDefaultOptions' ),
            'Activator should have setDefaultOptions method'
        );

        $method = $reflection->getMethod( 'setDefaultOptions' );
        $this->assertTrue(
            $method->isPrivate(),
            'setDefaultOptions should be private'
        );
    }
}
