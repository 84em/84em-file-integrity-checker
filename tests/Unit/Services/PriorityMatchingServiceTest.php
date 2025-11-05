<?php
/**
 * Tests for PriorityMatchingService
 *
 * @package EightyFourEM\FileIntegrityChecker\Tests\Unit\Services
 */

namespace EightyFourEM\FileIntegrityChecker\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use EightyFourEM\FileIntegrityChecker\Services\PriorityMatchingService;
use EightyFourEM\FileIntegrityChecker\Services\LoggerService;
use EightyFourEM\FileIntegrityChecker\Database\PriorityRulesRepository;
use EightyFourEM\FileIntegrityChecker\Database\VelocityLogRepository;

/**
 * Test priority matching service functionality
 */
class PriorityMatchingServiceTest extends TestCase {
	/**
	 * Priority matching service
	 *
	 * @var PriorityMatchingService
	 */
	private PriorityMatchingService $service;

	/**
	 * Mock rules repository
	 *
	 * @var PriorityRulesRepository|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $rulesRepository;

	/**
	 * Mock velocity repository
	 *
	 * @var VelocityLogRepository|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $velocityRepository;

	/**
	 * Mock logger
	 *
	 * @var LoggerService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logger;

	/**
	 * Set up test environment
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->rulesRepository = $this->createMock( PriorityRulesRepository::class );
		$this->velocityRepository = $this->createMock( VelocityLogRepository::class );
		$this->logger = $this->createMock( LoggerService::class );

		$this->service = new PriorityMatchingService(
			$this->rulesRepository,
			$this->velocityRepository,
			$this->logger
		);
	}

	/**
	 * Test getPriorityForFile method
	 */
	public function testGetPriorityForFile(): void {
		$file_path = '/wp-config.php';

		$this->rulesRepository->expects( $this->once() )
			->method( 'getPriorityForPath' )
			->with( $file_path )
			->willReturn( 'critical' );

		$result = $this->service->getPriorityForFile( $file_path );

		$this->assertEquals( 'critical', $result );
	}

	/**
	 * Test getPriorityForFile with exception
	 */
	public function testGetPriorityForFileWithException(): void {
		$file_path = '/test.php';

		$this->rulesRepository->expects( $this->once() )
			->method( 'getPriorityForPath' )
			->willThrowException( new \Exception( 'Database error' ) );

		$this->logger->expects( $this->once() )
			->method( 'error' );

		$result = $this->service->getPriorityForFile( $file_path );

		$this->assertNull( $result );
	}

	/**
	 * Test getMatchingRules method
	 */
	public function testGetMatchingRules(): void {
		$file_path = '/wp-config.php';
		$rules = [
			(object) [ 'id' => 1, 'path' => '/wp-config.php', 'priority_level' => 'critical' ],
		];

		$this->rulesRepository->expects( $this->once() )
			->method( 'findMatchingRules' )
			->with( $file_path )
			->willReturn( $rules );

		$result = $this->service->getMatchingRules( $file_path );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
		$this->assertEquals( 1, $result[0]->id );
	}

	/**
	 * Test isInMaintenanceWindow method
	 */
	public function testIsInMaintenanceWindow(): void {
		$file_path = '/wp-config.php';

		$this->rulesRepository->expects( $this->once() )
			->method( 'isInMaintenanceWindow' )
			->with( $file_path )
			->willReturn( true );

		$result = $this->service->isInMaintenanceWindow( $file_path );

		$this->assertTrue( $result );
	}

	/**
	 * Test logChange method
	 */
	public function testLogChange(): void {
		$rule_id = 1;
		$file_path = '/wp-config.php';
		$scan_id = 100;

		$this->velocityRepository->expects( $this->once() )
			->method( 'log' )
			->with( $this->callback( function ( $data ) use ( $rule_id, $file_path, $scan_id ) {
				return $data['rule_id'] === $rule_id &&
				       $data['file_path'] === $file_path &&
				       $data['scan_id'] === $scan_id &&
				       $data['change_type'] === 'modified';
			} ) )
			->willReturn( 1 );

		$result = $this->service->logChange( $rule_id, $file_path, $scan_id );

		$this->assertEquals( 1, $result );
	}

	/**
	 * Test exceedsVelocityThreshold method
	 */
	public function testExceedsVelocityThreshold(): void {
		$rule_id = 1;
		$file_path = '/wp-config.php';
		$rule = (object) [
			'id'                        => 1,
			'change_velocity_threshold' => 5,
			'velocity_window_hours'     => 24,
		];

		$this->rulesRepository->expects( $this->once() )
			->method( 'find' )
			->with( $rule_id )
			->willReturn( $rule );

		$this->velocityRepository->expects( $this->once() )
			->method( 'exceedsVelocityThreshold' )
			->with( $rule_id, $file_path, 5, 24 )
			->willReturn( true );

		$result = $this->service->exceedsVelocityThreshold( $rule_id, $file_path );

		$this->assertTrue( $result );
	}

	/**
	 * Test exceedsVelocityThreshold with no threshold set
	 */
	public function testExceedsVelocityThresholdWithNoThreshold(): void {
		$rule_id = 1;
		$file_path = '/wp-config.php';
		$rule = (object) [
			'id'                        => 1,
			'change_velocity_threshold' => null,
			'velocity_window_hours'     => 24,
		];

		$this->rulesRepository->expects( $this->once() )
			->method( 'find' )
			->with( $rule_id )
			->willReturn( $rule );

		$result = $this->service->exceedsVelocityThreshold( $rule_id, $file_path );

		$this->assertFalse( $result );
	}

	/**
	 * Test getVelocityAlerts method
	 */
	public function testGetVelocityAlerts(): void {
		$rules = [
			(object) [
				'id'                        => 1,
				'path'                      => '/wp-config.php',
				'priority_level'            => 'critical',
				'change_velocity_threshold' => 5,
				'velocity_window_hours'     => 24,
			],
		];

		$alerts = [
			[
				'rule_id'   => 1,
				'file_path' => '/wp-config.php',
			],
		];

		$this->rulesRepository->expects( $this->once() )
			->method( 'getActiveRules' )
			->willReturn( $rules );

		$this->velocityRepository->expects( $this->once() )
			->method( 'getVelocityAlerts' )
			->with( $rules )
			->willReturn( $alerts );

		$result = $this->service->getVelocityAlerts();

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
	}

	/**
	 * Test processChangedFile method
	 */
	public function testProcessChangedFile(): void {
		$file_path = '/wp-config.php';
		$scan_id = 100;
		$rules = [
			(object) [
				'id'                        => 1,
				'notify_immediately'        => 1,
				'change_velocity_threshold' => 5,
				'velocity_window_hours'     => 24,
			],
		];

		$this->rulesRepository->expects( $this->once() )
			->method( 'getPriorityForPath' )
			->with( $file_path )
			->willReturn( 'critical' );

		$this->rulesRepository->expects( $this->once() )
			->method( 'findMatchingRules' )
			->with( $file_path )
			->willReturn( $rules );

		$this->rulesRepository->expects( $this->once() )
			->method( 'isInMaintenanceWindow' )
			->with( $file_path )
			->willReturn( false );

		$this->velocityRepository->expects( $this->once() )
			->method( 'log' )
			->willReturn( 1 );

		$this->rulesRepository->expects( $this->once() )
			->method( 'find' )
			->willReturn( $rules[0] );

		$this->velocityRepository->expects( $this->once() )
			->method( 'exceedsVelocityThreshold' )
			->willReturn( false );

		$result = $this->service->processChangedFile( $file_path, $scan_id );

		$this->assertIsArray( $result );
		$this->assertEquals( 'critical', $result['priority'] );
		$this->assertTrue( $result['should_notify'] );
		$this->assertFalse( $result['in_maintenance'] );
		$this->assertFalse( $result['velocity_exceeded'] );
	}

	/**
	 * Test batchProcessFiles method
	 */
	public function testBatchProcessFiles(): void {
		$file_paths = [
			'/wp-config.php',
			'/wp-content/themes/test/functions.php',
		];
		$scan_id = 100;

		$this->rulesRepository->expects( $this->exactly( 2 ) )
			->method( 'getPriorityForPath' )
			->willReturnOnConsecutiveCalls( 'critical', 'normal' );

		$this->rulesRepository->expects( $this->exactly( 2 ) )
			->method( 'findMatchingRules' )
			->willReturn( [] );

		$this->rulesRepository->expects( $this->exactly( 2 ) )
			->method( 'isInMaintenanceWindow' )
			->willReturn( false );

		$result = $this->service->batchProcessFiles( $file_paths, $scan_id );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );
		$this->assertArrayHasKey( '/wp-config.php', $result );
		$this->assertArrayHasKey( '/wp-content/themes/test/functions.php', $result );
	}

	/**
	 * Test calculatePriorityStats method
	 */
	public function testCalculatePriorityStats(): void {
		$priority_results = [
			'/wp-config.php' => [
				'priority'          => 'critical',
				'in_maintenance'    => false,
				'should_notify'     => true,
				'velocity_exceeded' => false,
			],
			'/test.php' => [
				'priority'          => 'high',
				'in_maintenance'    => false,
				'should_notify'     => false,
				'velocity_exceeded' => true,
			],
			'/other.php' => [
				'priority'          => null,
				'in_maintenance'    => true,
				'should_notify'     => false,
				'velocity_exceeded' => false,
			],
		];

		$result = $this->service->calculatePriorityStats( $priority_results );

		$this->assertIsArray( $result );
		$this->assertEquals( 1, $result['critical_count'] );
		$this->assertEquals( 1, $result['high_count'] );
		$this->assertEquals( 0, $result['normal_count'] );
		$this->assertEquals( 1, $result['no_priority_count'] );
		$this->assertEquals( 1, $result['maintenance_count'] );
		$this->assertEquals( 1, $result['immediate_notify_count'] );
		$this->assertEquals( 1, $result['velocity_exceeded_count'] );
		$this->assertEquals( 3, $result['total_files'] );
	}
}
