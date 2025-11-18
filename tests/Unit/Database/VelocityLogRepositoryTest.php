<?php
/**
 * Tests for VelocityLogRepository
 *
 * @package EightyFourEM\FileIntegrityChecker\Tests\Unit\Database
 */

namespace EightyFourEM\FileIntegrityChecker\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use EightyFourEM\FileIntegrityChecker\Database\VelocityLogRepository;

/**
 * Test velocity log repository functionality
 */
class VelocityLogRepositoryTest extends TestCase {
	/**
	 * Velocity log repository
	 *
	 * @var VelocityLogRepository
	 */
	private VelocityLogRepository $repository;

	/**
	 * Mock wpdb instance
	 *
	 * @var object
	 */
	private $wpdbMock;

	/**
	 * Set up test environment
	 */
	protected function setUp(): void {
		parent::setUp();

		// Create mock wpdb
		$this->wpdbMock = $this->createMock( \wpdb::class );
		$this->wpdbMock->prefix = 'wp_';
		$this->wpdbMock->insert_id = 1;

		// Set global $wpdb
		$GLOBALS['wpdb'] = $this->wpdbMock;

		$this->repository = new VelocityLogRepository();
	}

	/**
	 * Test log method with valid data
	 */
	public function testLogWithValidData(): void {
		$data = [
			'rule_id'   => 1,
			'file_path' => '/wp-config.php',
			'scan_id'   => 100,
		];

		$this->wpdbMock->expects( $this->once() )
			->method( 'insert' )
			->with(
				$this->equalTo( 'wp_eightyfourem_integrity_velocity_log' ),
				$this->callback( function ( $insert_data ) {
					return isset( $insert_data['rule_id'] ) &&
					       isset( $insert_data['file_path'] ) &&
					       isset( $insert_data['scan_id'] ) &&
					       isset( $insert_data['change_detected_at'] ) &&
					       isset( $insert_data['change_type'] );
				} )
			)
			->willReturn( 1 );

		$result = $this->repository->log( $data );

		$this->assertEquals( 1, $result );
	}

	/**
	 * Test log method with missing required fields
	 */
	public function testLogWithMissingRequiredFields(): void {
		$data = [
			'rule_id'   => 1,
			'file_path' => '/wp-config.php',
			// Missing scan_id
		];

		$result = $this->repository->log( $data );

		$this->assertFalse( $result );
	}

	/**
	 * Test getChangeCount method
	 */
	public function testGetChangeCount(): void {
		$this->wpdbMock->expects( $this->once() )
			->method( 'prepare' )
			->willReturnArgument( 0 );

		$this->wpdbMock->expects( $this->once() )
			->method( 'get_var' )
			->willReturn( '5' );

		$count = $this->repository->getChangeCount( 1, '/wp-config.php', 24 );

		$this->assertEquals( 5, $count );
	}

	/**
	 * Test getChangeCount with custom reference time
	 */
	public function testGetChangeCountWithCustomReferenceTime(): void {
		$reference_time = '2025-11-05 12:00:00';

		$this->wpdbMock->expects( $this->once() )
			->method( 'prepare' )
			->with(
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->equalTo( $reference_time ),
				$this->anything(),
				$this->equalTo( $reference_time )
			)
			->willReturnArgument( 0 );

		$this->wpdbMock->expects( $this->once() )
			->method( 'get_var' )
			->willReturn( '3' );

		$count = $this->repository->getChangeCount( 1, '/wp-config.php', 24, $reference_time );

		$this->assertEquals( 3, $count );
	}

	/**
	 * Test exceedsVelocityThreshold method - threshold not exceeded
	 */
	public function testExceedsVelocityThresholdNotExceeded(): void {
		$this->wpdbMock->expects( $this->once() )
			->method( 'prepare' )
			->willReturnArgument( 0 );

		$this->wpdbMock->expects( $this->once() )
			->method( 'get_var' )
			->willReturn( '3' );

		$result = $this->repository->exceedsVelocityThreshold( 1, '/wp-config.php', 5, 24 );

		$this->assertFalse( $result );
	}

	/**
	 * Test exceedsVelocityThreshold method - threshold exceeded
	 */
	public function testExceedsVelocityThresholdExceeded(): void {
		$this->wpdbMock->expects( $this->once() )
			->method( 'prepare' )
			->willReturnArgument( 0 );

		$this->wpdbMock->expects( $this->once() )
			->method( 'get_var' )
			->willReturn( '10' );

		$result = $this->repository->exceedsVelocityThreshold( 1, '/wp-config.php', 5, 24 );

		$this->assertTrue( $result );
	}

	/**
	 * Test getRecentChanges method
	 */
	public function testGetRecentChanges(): void {
		$changes = [
			(object) [
				'id'                  => 1,
				'rule_id'             => 1,
				'file_path'           => '/wp-config.php',
				'change_detected_at'  => '2025-11-05 10:00:00',
			],
			(object) [
				'id'                  => 2,
				'rule_id'             => 1,
				'file_path'           => '/wp-config.php',
				'change_detected_at'  => '2025-11-05 09:00:00',
			],
		];

		$this->wpdbMock->expects( $this->once() )
			->method( 'prepare' )
			->willReturnArgument( 0 );

		$this->wpdbMock->expects( $this->once() )
			->method( 'get_results' )
			->willReturn( $changes );

		$result = $this->repository->getRecentChanges( 1, 24, 100 );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );
	}

	/**
	 * Test getVelocityStats method
	 */
	public function testGetVelocityStats(): void {
		$stats = [
			'total_changes' => 10,
			'unique_files'  => 3,
			'first_change'  => '2025-11-04 10:00:00',
			'last_change'   => '2025-11-05 10:00:00',
		];

		$this->wpdbMock->expects( $this->once() )
			->method( 'prepare' )
			->willReturnArgument( 0 );

		$this->wpdbMock->expects( $this->once() )
			->method( 'get_row' )
			->willReturn( $stats );

		$result = $this->repository->getVelocityStats( 1, 24 );

		$this->assertIsArray( $result );
		$this->assertEquals( 10, $result['total_changes'] );
		$this->assertEquals( 3, $result['unique_files'] );
	}

	/**
	 * Test getVelocityStats with no data
	 */
	public function testGetVelocityStatsWithNoData(): void {
		$this->wpdbMock->expects( $this->once() )
			->method( 'prepare' )
			->willReturnArgument( 0 );

		$this->wpdbMock->expects( $this->once() )
			->method( 'get_row' )
			->willReturn( null );

		$result = $this->repository->getVelocityStats( 1, 24 );

		$this->assertIsArray( $result );
		$this->assertEquals( 0, $result['total_changes'] );
		$this->assertEquals( 0, $result['unique_files'] );
		$this->assertNull( $result['first_change'] );
		$this->assertNull( $result['last_change'] );
	}

	/**
	 * Test getTopChangedFiles method
	 */
	public function testGetTopChangedFiles(): void {
		$files = [
			(object) [
				'file_path'    => '/wp-config.php',
				'change_count' => 10,
				'last_change'  => '2025-11-05 10:00:00',
			],
			(object) [
				'file_path'    => '/wp-content/themes/my-theme/functions.php',
				'change_count' => 5,
				'last_change'  => '2025-11-05 09:00:00',
			],
		];

		$this->wpdbMock->expects( $this->once() )
			->method( 'prepare' )
			->willReturnArgument( 0 );

		$this->wpdbMock->expects( $this->once() )
			->method( 'get_results' )
			->willReturn( $files );

		$result = $this->repository->getTopChangedFiles( 1, 24, 10 );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );
		$this->assertEquals( 10, $result[0]->change_count );
	}

	/**
	 * Test cleanup method
	 */
	public function testCleanup(): void {
		$this->wpdbMock->expects( $this->once() )
			->method( 'prepare' )
			->willReturnArgument( 0 );

		$this->wpdbMock->expects( $this->once() )
			->method( 'query' )
			->willReturn( 15 );

		$result = $this->repository->cleanup( 30 );

		$this->assertEquals( 15, $result );
	}

	/**
	 * Test deleteByRule method
	 */
	public function testDeleteByRule(): void {
		$this->wpdbMock->expects( $this->once() )
			->method( 'delete' )
			->with(
				$this->anything(),
				$this->equalTo( [ 'rule_id' => 1 ] ),
				$this->equalTo( [ '%d' ] )
			)
			->willReturn( 20 );

		$result = $this->repository->deleteByRule( 1 );

		$this->assertEquals( 20, $result );
	}

	/**
	 * Test deleteByScan method
	 */
	public function testDeleteByScan(): void {
		$this->wpdbMock->expects( $this->once() )
			->method( 'delete' )
			->with(
				$this->anything(),
				$this->equalTo( [ 'scan_id' => 100 ] ),
				$this->equalTo( [ '%d' ] )
			)
			->willReturn( 5 );

		$result = $this->repository->deleteByScan( 100 );

		$this->assertEquals( 5, $result );
	}

	/**
	 * Test count method
	 */
	public function testCount(): void {
		$this->wpdbMock->expects( $this->once() )
			->method( 'get_var' )
			->willReturn( '100' );

		$result = $this->repository->count();

		$this->assertEquals( 100, $result );
	}

	/**
	 * Test getVelocityAlerts method with no alerts
	 */
	public function testGetVelocityAlertsNoAlerts(): void {
		$rules = [
			(object) [
				'id'                         => 1,
				'path'                       => '/wp-config.php',
				'priority_level'             => 'critical',
				'change_velocity_threshold'  => 5,
				'velocity_window_hours'      => 24,
			],
		];

		$files = [
			(object) [
				'file_path'    => '/wp-config.php',
				'change_count' => 3, // Below threshold
				'last_change'  => '2025-11-05 10:00:00',
			],
		];

		$this->wpdbMock->expects( $this->once() )
			->method( 'prepare' )
			->willReturnArgument( 0 );

		$this->wpdbMock->expects( $this->once() )
			->method( 'get_results' )
			->willReturn( $files );

		$result = $this->repository->getVelocityAlerts( $rules );

		$this->assertIsArray( $result );
		$this->assertCount( 0, $result );
	}

	/**
	 * Test getVelocityAlerts method with alerts
	 */
	public function testGetVelocityAlertsWithAlerts(): void {
		$rules = [
			(object) [
				'id'                         => 1,
				'path'                       => '/wp-config.php',
				'priority_level'             => 'critical',
				'change_velocity_threshold'  => 5,
				'velocity_window_hours'      => 24,
			],
		];

		$files = [
			(object) [
				'file_path'    => '/wp-config.php',
				'change_count' => 10, // Exceeds threshold
				'last_change'  => '2025-11-05 10:00:00',
			],
		];

		$this->wpdbMock->expects( $this->once() )
			->method( 'prepare' )
			->willReturnArgument( 0 );

		$this->wpdbMock->expects( $this->once() )
			->method( 'get_results' )
			->willReturn( $files );

		$result = $this->repository->getVelocityAlerts( $rules );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
		$this->assertEquals( 1, $result[0]['rule_id'] );
		$this->assertEquals( '/wp-config.php', $result[0]['file_path'] );
		$this->assertEquals( 10, $result[0]['change_count'] );
		$this->assertEquals( 5, $result[0]['threshold'] );
	}

	/**
	 * Test getVelocityAlerts skips rules without velocity settings
	 */
	public function testGetVelocityAlertsSkipsRulesWithoutSettings(): void {
		$rules = [
			(object) [
				'id'                         => 1,
				'path'                       => '/wp-config.php',
				'priority_level'             => 'critical',
				'change_velocity_threshold'  => null, // No threshold set
				'velocity_window_hours'      => 24,
			],
			(object) [
				'id'                         => 2,
				'path'                       => '/wp-admin/*',
				'priority_level'             => 'high',
				'change_velocity_threshold'  => 5,
				'velocity_window_hours'      => null, // No window set
			],
		];

		$result = $this->repository->getVelocityAlerts( $rules );

		$this->assertIsArray( $result );
		$this->assertCount( 0, $result );
	}

	/**
	 * Test getVelocityAlerts with multiple rules and alerts
	 */
	public function testGetVelocityAlertsMultipleRules(): void {
		$rules = [
			(object) [
				'id'                         => 1,
				'path'                       => '/wp-config.php',
				'priority_level'             => 'critical',
				'change_velocity_threshold'  => 3,
				'velocity_window_hours'      => 24,
			],
			(object) [
				'id'                         => 2,
				'path'                       => '/wp-admin/*',
				'priority_level'             => 'high',
				'change_velocity_threshold'  => 10,
				'velocity_window_hours'      => 12,
			],
		];

		// First call for rule 1
		$files1 = [
			(object) [
				'file_path'    => '/wp-config.php',
				'change_count' => 5,
				'last_change'  => '2025-11-05 10:00:00',
			],
		];

		// Second call for rule 2
		$files2 = [
			(object) [
				'file_path'    => '/wp-admin/index.php',
				'change_count' => 15,
				'last_change'  => '2025-11-05 09:00:00',
			],
		];

		$this->wpdbMock->expects( $this->exactly( 2 ) )
			->method( 'prepare' )
			->willReturnArgument( 0 );

		$this->wpdbMock->expects( $this->exactly( 2 ) )
			->method( 'get_results' )
			->willReturnOnConsecutiveCalls( $files1, $files2 );

		$result = $this->repository->getVelocityAlerts( $rules );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );
		$this->assertEquals( 1, $result[0]['rule_id'] );
		$this->assertEquals( 2, $result[1]['rule_id'] );
	}
}
