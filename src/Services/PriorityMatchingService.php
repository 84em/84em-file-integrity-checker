<?php
/**
 * Priority Matching Service
 *
 * @package EightyFourEM\FileIntegrityChecker\Services
 */

namespace EightyFourEM\FileIntegrityChecker\Services;

use EightyFourEM\FileIntegrityChecker\Database\PriorityRulesRepository;
use EightyFourEM\FileIntegrityChecker\Database\VelocityLogRepository;

/**
 * Service for matching files against priority rules
 */
class PriorityMatchingService {
	/**
	 * Priority rules repository
	 *
	 * @var PriorityRulesRepository
	 */
	private PriorityRulesRepository $rulesRepository;

	/**
	 * Velocity log repository
	 *
	 * @var VelocityLogRepository
	 */
	private VelocityLogRepository $velocityRepository;

	/**
	 * Logger service
	 *
	 * @var LoggerService
	 */
	private LoggerService $logger;

	/**
	 * Constructor
	 *
	 * @param PriorityRulesRepository $rulesRepository    Priority rules repository
	 * @param VelocityLogRepository   $velocityRepository Velocity log repository
	 * @param LoggerService           $logger             Logger service
	 */
	public function __construct(
		PriorityRulesRepository $rulesRepository,
		VelocityLogRepository $velocityRepository,
		LoggerService $logger
	) {
		$this->rulesRepository    = $rulesRepository;
		$this->velocityRepository = $velocityRepository;
		$this->logger             = $logger;
	}

	/**
	 * Get priority level for a file path
	 *
	 * @param string $file_path File path to evaluate
	 * @return string|null Priority level (critical, high, normal) or null if no rules match
	 */
	public function getPriorityForFile( string $file_path ): ?string {
		try {
			return $this->rulesRepository->getPriorityForPath( $file_path );
		} catch ( \Exception $e ) {
			$this->logger->error(
				'Failed to get priority for file',
				'priority_matching',
				[
					'file_path' => $file_path,
					'error'     => $e->getMessage(),
				]
			);
			return null;
		}
	}

	/**
	 * Get all matching rules for a file path
	 *
	 * @param string $file_path File path to evaluate
	 * @return array Array of matching rule objects
	 */
	public function getMatchingRules( string $file_path ): array {
		try {
			return $this->rulesRepository->findMatchingRules( $file_path );
		} catch ( \Exception $e ) {
			$this->logger->error(
				'Failed to get matching rules for file',
				'priority_matching',
				[
					'file_path' => $file_path,
					'error'     => $e->getMessage(),
				]
			);
			return [];
		}
	}

	/**
	 * Check if file is in maintenance window
	 *
	 * @param string $file_path File path to check
	 * @return bool True if in maintenance window
	 */
	public function isInMaintenanceWindow( string $file_path ): bool {
		try {
			return $this->rulesRepository->isInMaintenanceWindow( $file_path );
		} catch ( \Exception $e ) {
			$this->logger->error(
				'Failed to check maintenance window',
				'priority_matching',
				[
					'file_path' => $file_path,
					'error'     => $e->getMessage(),
				]
			);
			return false;
		}
	}

	/**
	 * Log file change for velocity tracking
	 *
	 * @param int    $rule_id   Rule ID
	 * @param string $file_path File path
	 * @param int    $scan_id   Scan ID
	 * @param string $change_type Change type (modified, added, deleted)
	 * @return int|false Log ID on success, false on failure
	 */
	public function logChange( int $rule_id, string $file_path, int $scan_id, string $change_type = 'modified' ): int|false {
		try {
			return $this->velocityRepository->log( [
				'rule_id'     => $rule_id,
				'file_path'   => $file_path,
				'scan_id'     => $scan_id,
				'change_type' => $change_type,
			] );
		} catch ( \Exception $e ) {
			$this->logger->error(
				'Failed to log change for velocity tracking',
				'priority_matching',
				[
					'rule_id'     => $rule_id,
					'file_path'   => $file_path,
					'scan_id'     => $scan_id,
					'change_type' => $change_type,
					'error'       => $e->getMessage(),
				]
			);
			return false;
		}
	}

	/**
	 * Check if file exceeds velocity threshold
	 *
	 * @param int    $rule_id   Rule ID
	 * @param string $file_path File path
	 * @return bool True if threshold exceeded
	 */
	public function exceedsVelocityThreshold( int $rule_id, string $file_path ): bool {
		try {
			$rule = $this->rulesRepository->find( $rule_id );
			if ( ! $rule || ! $rule->change_velocity_threshold || ! $rule->velocity_window_hours ) {
				return false;
			}

			return $this->velocityRepository->exceedsVelocityThreshold(
				$rule_id,
				$file_path,
				$rule->change_velocity_threshold,
				$rule->velocity_window_hours
			);
		} catch ( \Exception $e ) {
			$this->logger->error(
				'Failed to check velocity threshold',
				'priority_matching',
				[
					'rule_id'   => $rule_id,
					'file_path' => $file_path,
					'error'     => $e->getMessage(),
				]
			);
			return false;
		}
	}

	/**
	 * Get velocity alerts for all active rules
	 *
	 * @return array Array of alert data
	 */
	public function getVelocityAlerts(): array {
		try {
			$rules = $this->rulesRepository->getActiveRules();
			return $this->velocityRepository->getVelocityAlerts( $rules );
		} catch ( \Exception $e ) {
			$this->logger->error(
				'Failed to get velocity alerts',
				'priority_matching',
				[
					'error' => $e->getMessage(),
				]
			);
			return [];
		}
	}

	/**
	 * Process changed file and determine priority
	 *
	 * Returns an array with priority information:
	 * - priority: string|null - Priority level (critical, high, normal)
	 * - rules: array - All matching rules
	 * - in_maintenance: bool - Whether file is in maintenance window
	 * - should_notify: bool - Whether to send immediate notification
	 * - velocity_exceeded: bool - Whether velocity threshold exceeded
	 *
	 * @param string $file_path File path
	 * @param int    $scan_id   Scan ID for velocity logging
	 * @param string $change_type Change type (modified, added, deleted)
	 * @return array Priority information
	 */
	public function processChangedFile( string $file_path, int $scan_id, string $change_type = 'modified' ): array {
		$priority        = $this->getPriorityForFile( $file_path );
		$rules           = $this->getMatchingRules( $file_path );
		$in_maintenance  = $this->isInMaintenanceWindow( $file_path );
		$should_notify   = false;
		$velocity_exceeded = false;

		// Determine if immediate notification is needed
		foreach ( $rules as $rule ) {
			// Check notify_immediately flag
			if ( $rule->notify_immediately && ! $in_maintenance ) {
				$should_notify = true;
			}

			// Log change for velocity tracking
			if ( $rule->change_velocity_threshold && $rule->velocity_window_hours ) {
				$this->logChange( $rule->id, $file_path, $scan_id, $change_type );

				// Check if velocity threshold exceeded
				if ( $this->exceedsVelocityThreshold( $rule->id, $file_path ) ) {
					$velocity_exceeded = true;
					if ( ! $in_maintenance ) {
						$should_notify = true;
					}
				}
			}
		}

		return [
			'priority'          => $priority,
			'rules'             => $rules,
			'in_maintenance'    => $in_maintenance,
			'should_notify'     => $should_notify,
			'velocity_exceeded' => $velocity_exceeded,
		];
	}

	/**
	 * Batch process multiple changed files
	 *
	 * @param array $file_paths Array of file paths
	 * @param int   $scan_id    Scan ID for velocity logging
	 * @param string $change_type Change type (modified, added, deleted)
	 * @return array Array of priority information keyed by file path
	 */
	public function batchProcessFiles( array $file_paths, int $scan_id, string $change_type = 'modified' ): array {
		$results = [];

		foreach ( $file_paths as $file_path ) {
			$results[ $file_path ] = $this->processChangedFile( $file_path, $scan_id, $change_type );
		}

		return $results;
	}

	/**
	 * Calculate priority statistics for a set of files
	 *
	 * @param array $priority_results Results from batchProcessFiles or processChangedFile
	 * @return array Statistics array
	 */
	public function calculatePriorityStats( array $priority_results ): array {
		$stats = [
			'critical_count'        => 0,
			'high_count'            => 0,
			'normal_count'          => 0,
			'no_priority_count'     => 0,
			'maintenance_count'     => 0,
			'immediate_notify_count' => 0,
			'velocity_exceeded_count' => 0,
			'total_files'           => count( $priority_results ),
		];

		foreach ( $priority_results as $result ) {
			// Count by priority level
			switch ( $result['priority'] ) {
				case 'critical':
					$stats['critical_count']++;
					break;
				case 'high':
					$stats['high_count']++;
					break;
				case 'normal':
					$stats['normal_count']++;
					break;
				default:
					$stats['no_priority_count']++;
					break;
			}

			// Count maintenance window files
			if ( $result['in_maintenance'] ) {
				$stats['maintenance_count']++;
			}

			// Count immediate notifications
			if ( $result['should_notify'] ) {
				$stats['immediate_notify_count']++;
			}

			// Count velocity exceeded
			if ( $result['velocity_exceeded'] ) {
				$stats['velocity_exceeded_count']++;
			}
		}

		return $stats;
	}
}
