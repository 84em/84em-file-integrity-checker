<?php
/**
 * Tests for PriorityRulesRepository
 *
 * @package EightyFourEM\FileIntegrityChecker\Tests\Unit\Database
 */

namespace EightyFourEM\FileIntegrityChecker\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use EightyFourEM\FileIntegrityChecker\Database\PriorityRulesRepository;

/**
 * Test priority rules repository functionality
 */
class PriorityRulesRepositoryTest extends TestCase {
	/**
	 * Priority rules repository
	 *
	 * @var PriorityRulesRepository
	 */
	private PriorityRulesRepository $repository;

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

		// Mock WordPress functions
		if ( ! function_exists( 'get_current_user_id' ) ) {
			eval( 'function get_current_user_id() { return 1; }' );
		}

		if ( ! function_exists( 'sanitize_sql_orderby' ) ) {
			eval( 'function sanitize_sql_orderby( $orderby ) { return $orderby; }' );
		}

		$this->repository = new PriorityRulesRepository();
	}

	/**
	 * Test create method with valid data
	 */
	public function testCreateWithValidData(): void {
		$data = [
			'path'           => '/wp-config.php',
			'priority_level' => 'critical',
		];

		$this->wpdbMock->expects( $this->once() )
			->method( 'insert' )
			->with(
				$this->equalTo( 'wp_eightyfourem_integrity_priority_rules' ),
				$this->callback( function ( $insert_data ) {
					return isset( $insert_data['path'] ) &&
					       isset( $insert_data['priority_level'] ) &&
					       isset( $insert_data['path_type'] ) &&
					       $insert_data['path_type'] === 'file';
				} )
			)
			->willReturn( 1 );

		$result = $this->repository->create( $data );

		$this->assertEquals( 1, $result );
	}

	/**
	 * Test create method with missing required fields
	 */
	public function testCreateWithMissingRequiredFields(): void {
		$data = [
			'path' => '/wp-config.php',
			// Missing priority_level
		];

		$result = $this->repository->create( $data );

		$this->assertFalse( $result );
	}

	/**
	 * Test update method
	 */
	public function testUpdate(): void {
		$data = [
			'priority_level' => 'high',
			'reason'         => 'Updated reason',
		];

		$this->wpdbMock->expects( $this->once() )
			->method( 'update' )
			->willReturn( 1 );

		$result = $this->repository->update( 1, $data );

		$this->assertTrue( $result );
	}

	/**
	 * Test delete method
	 */
	public function testDelete(): void {
		$this->wpdbMock->expects( $this->once() )
			->method( 'delete' )
			->with(
				$this->equalTo( 'wp_eightyfourem_integrity_priority_rules' ),
				$this->equalTo( [ 'id' => 1 ] ),
				$this->equalTo( [ '%d' ] )
			)
			->willReturn( 1 );

		$result = $this->repository->delete( 1 );

		$this->assertTrue( $result );
	}

	/**
	 * Test find method
	 */
	public function testFind(): void {
		$rule = (object) [
			'id'             => 1,
			'path'           => '/wp-config.php',
			'priority_level' => 'critical',
		];

		$this->wpdbMock->expects( $this->once() )
			->method( 'get_row' )
			->willReturn( $rule );

		$result = $this->repository->find( 1 );

		$this->assertNotNull( $result );
		$this->assertEquals( 1, $result->id );
	}

	/**
	 * Test findAll method with filters
	 */
	public function testFindAllWithFilters(): void {
		$rules = [
			(object) [
				'id'             => 1,
				'path'           => '/wp-config.php',
				'priority_level' => 'critical',
			],
		];

		$this->wpdbMock->expects( $this->once() )
			->method( 'prepare' )
			->willReturnArgument( 0 );

		$this->wpdbMock->expects( $this->once() )
			->method( 'get_results' )
			->willReturn( $rules );

		$result = $this->repository->findAll( [
			'is_active'      => 1,
			'priority_level' => 'critical',
		] );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
	}

	/**
	 * Test getActiveRules method
	 */
	public function testGetActiveRules(): void {
		$rules = [
			(object) [
				'id'             => 1,
				'path'           => '/wp-config.php',
				'priority_level' => 'critical',
				'is_active'      => 1,
			],
		];

		$this->wpdbMock->expects( $this->once() )
			->method( 'get_results' )
			->willReturn( $rules );

		$result = $this->repository->getActiveRules();

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
	}

	/**
	 * Test pathMatchesRule with exact match
	 */
	public function testPathMatchesRuleExact(): void {
		$rule = (object) [
			'path'       => '/wp-config.php',
			'match_type' => 'exact',
		];

		// Use reflection to access private method
		$reflection = new \ReflectionClass( $this->repository );
		$method = $reflection->getMethod( 'pathMatchesRule' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( $this->repository, '/wp-config.php', $rule ) );
		$this->assertFalse( $method->invoke( $this->repository, '/wp-config-backup.php', $rule ) );
	}

	/**
	 * Test pathMatchesRule with prefix match
	 */
	public function testPathMatchesRulePrefix(): void {
		$rule = (object) [
			'path'       => '/wp-admin/*',
			'match_type' => 'prefix',
		];

		$reflection = new \ReflectionClass( $this->repository );
		$method = $reflection->getMethod( 'pathMatchesRule' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( $this->repository, '/wp-admin/index.php', $rule ) );
		$this->assertFalse( $method->invoke( $this->repository, '/wp-content/index.php', $rule ) );
	}

	/**
	 * Test pathMatchesRule with suffix match
	 */
	public function testPathMatchesRuleSuffix(): void {
		$rule = (object) [
			'path'       => '*.php',
			'match_type' => 'suffix',
		];

		$reflection = new \ReflectionClass( $this->repository );
		$method = $reflection->getMethod( 'pathMatchesRule' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( $this->repository, '/some-file.php', $rule ) );
		$this->assertFalse( $method->invoke( $this->repository, '/some-file.js', $rule ) );
	}

	/**
	 * Test pathMatchesRule with contains match
	 */
	public function testPathMatchesRuleContains(): void {
		$rule = (object) [
			'path'       => '*backup*',
			'match_type' => 'contains',
		];

		$reflection = new \ReflectionClass( $this->repository );
		$method = $reflection->getMethod( 'pathMatchesRule' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( $this->repository, '/wp-content/backup-file.php', $rule ) );
		$this->assertFalse( $method->invoke( $this->repository, '/wp-content/normal-file.php', $rule ) );
	}

	/**
	 * Test pathMatchesRule with glob match
	 */
	public function testPathMatchesRuleGlob(): void {
		$rule = (object) [
			'path'       => '/wp-content/plugins/*/config.php',
			'match_type' => 'glob',
		];

		$reflection = new \ReflectionClass( $this->repository );
		$method = $reflection->getMethod( 'pathMatchesRule' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( $this->repository, '/wp-content/plugins/my-plugin/config.php', $rule ) );
		$this->assertFalse( $method->invoke( $this->repository, '/wp-content/plugins/my-plugin/index.php', $rule ) );
	}

	/**
	 * Test pathMatchesRule with regex match
	 */
	public function testPathMatchesRuleRegex(): void {
		$rule = (object) [
			'path'       => '/^\/wp-content\/uploads\/.*\.php$/',
			'match_type' => 'regex',
		];

		$reflection = new \ReflectionClass( $this->repository );
		$method = $reflection->getMethod( 'pathMatchesRule' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( $this->repository, '/wp-content/uploads/malicious.php', $rule ) );
		$this->assertFalse( $method->invoke( $this->repository, '/wp-content/uploads/image.jpg', $rule ) );
	}

	/**
	 * Test globMatch helper method
	 */
	public function testGlobMatch(): void {
		$reflection = new \ReflectionClass( $this->repository );
		$method = $reflection->getMethod( 'globMatch' );
		$method->setAccessible( true );

		// Test single wildcard
		$this->assertTrue( $method->invoke( $this->repository, '*.php', 'file.php' ) );
		$this->assertFalse( $method->invoke( $this->repository, '*.php', 'file.js' ) );

		// Test directory wildcard
		$this->assertTrue( $method->invoke( $this->repository, '/plugins/*/config.php', '/plugins/test/config.php' ) );
		$this->assertFalse( $method->invoke( $this->repository, '/plugins/*/config.php', '/plugins/test/other.php' ) );

		// Test question mark
		$this->assertTrue( $method->invoke( $this->repository, 'file?.php', 'file1.php' ) );
		$this->assertFalse( $method->invoke( $this->repository, 'file?.php', 'file12.php' ) );
	}

	/**
	 * Test getPriorityForPath method
	 */
	public function testGetPriorityForPath(): void {
		$rules = [
			(object) [
				'id'             => 1,
				'path'           => '/wp-config.php',
				'priority_level' => 'critical',
				'match_type'     => 'exact',
				'is_active'      => 1,
			],
			(object) [
				'id'             => 2,
				'path'           => '/wp-admin/*',
				'priority_level' => 'high',
				'match_type'     => 'prefix',
				'is_active'      => 1,
			],
		];

		$this->wpdbMock->expects( $this->once() )
			->method( 'get_results' )
			->willReturn( $rules );

		$priority = $this->repository->getPriorityForPath( '/wp-config.php' );

		$this->assertEquals( 'critical', $priority );
	}

	/**
	 * Test isInMaintenanceWindow method
	 */
	public function testIsInMaintenanceWindow(): void {
		$now = current_time( 'mysql' );
		$future = date( 'Y-m-d H:i:s', strtotime( '+1 hour' ) );

		$rules = [
			(object) [
				'path'                        => '/wp-config.php',
				'match_type'                  => 'exact',
				'suppress_during_maintenance' => 1,
				'maintenance_window_start'    => $now,
				'maintenance_window_end'      => $future,
			],
		];

		$this->wpdbMock->expects( $this->once() )
			->method( 'get_results' )
			->willReturn( $rules );

		$result = $this->repository->isInMaintenanceWindow( '/wp-config.php' );

		$this->assertTrue( $result );
	}

	/**
	 * Test count method
	 */
	public function testCount(): void {
		$this->wpdbMock->expects( $this->once() )
			->method( 'get_var' )
			->willReturn( '5' );

		$result = $this->repository->count( [
			'is_active' => 1,
		] );

		$this->assertEquals( 5, $result );
	}

	/**
	 * Test setActive method
	 */
	public function testSetActive(): void {
		$this->wpdbMock->expects( $this->once() )
			->method( 'update' )
			->willReturn( 1 );

		$result = $this->repository->setActive( 1, true );

		$this->assertTrue( $result );
	}

	/**
	 * Test bulkSetActive method
	 */
	public function testBulkSetActive(): void {
		$this->wpdbMock->expects( $this->once() )
			->method( 'prepare' )
			->willReturnArgument( 0 );

		$this->wpdbMock->expects( $this->once() )
			->method( 'query' )
			->willReturn( 3 );

		$result = $this->repository->bulkSetActive( [ 1, 2, 3 ], true );

		$this->assertEquals( 3, $result );
	}

	/**
	 * Test bulkDelete method
	 */
	public function testBulkDelete(): void {
		$this->wpdbMock->expects( $this->once() )
			->method( 'query' )
			->willReturn( 2 );

		$result = $this->repository->bulkDelete( [ 1, 2 ] );

		$this->assertEquals( 2, $result );
	}
}
