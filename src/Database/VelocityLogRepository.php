<?php
/**
 * Velocity Log Repository
 *
 * @package EightyFourEM\FileIntegrityChecker\Database
 */

namespace EightyFourEM\FileIntegrityChecker\Database;

/**
 * Repository for managing change velocity tracking
 */
class VelocityLogRepository {
	/**
	 * Table name
	 */
	private const TABLE_NAME = 'eightyfourem_integrity_velocity_log';

	/**
	 * Log a file change for velocity tracking
	 *
	 * @param array $data Log data
	 * @return int|false Log ID on success, false on failure
	 */
	public function log( array $data ): int|false {
		global $wpdb;

		$required_fields = [ 'rule_id', 'file_path', 'scan_id' ];

		foreach ( $required_fields as $field ) {
			if ( ! isset( $data[ $field ] ) ) {
				return false;
			}
		}

		$defaults = [
			'change_detected_at' => current_time( 'mysql' ),
			'change_type'        => 'modified',
		];

		$data = wp_parse_args( $data, $defaults );

		$result = $wpdb->insert(
			$wpdb->prefix . self::TABLE_NAME,
			$data,
			[
				'%d', // rule_id
				'%s', // file_path
				'%s', // change_detected_at
				'%d', // scan_id
				'%s', // change_type
			]
		);

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Get change count for a file within a time window
	 *
	 * @param int    $rule_id        Rule ID
	 * @param string $file_path      File path
	 * @param int    $window_hours   Time window in hours
	 * @param string $reference_time Reference time (default: now)
	 * @return int Number of changes
	 */
	public function getChangeCount( int $rule_id, string $file_path, int $window_hours, string $reference_time = null ): int {
		global $wpdb;

		if ( $reference_time === null ) {
			$reference_time = current_time( 'mysql' );
		}

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $table_name
				WHERE rule_id = %d
				AND file_path = %s
				AND change_detected_at >= DATE_SUB(%s, INTERVAL %d HOUR)
				AND change_detected_at <= %s",
				$rule_id,
				$file_path,
				$reference_time,
				$window_hours,
				$reference_time
			)
		);

		return (int) $count;
	}

	/**
	 * Check if file changes exceed velocity threshold
	 *
	 * @param int    $rule_id      Rule ID
	 * @param string $file_path    File path
	 * @param int    $threshold    Change threshold
	 * @param int    $window_hours Time window in hours
	 * @return bool True if threshold exceeded
	 */
	public function exceedsVelocityThreshold( int $rule_id, string $file_path, int $threshold, int $window_hours ): bool {
		$change_count = $this->getChangeCount( $rule_id, $file_path, $window_hours );
		return $change_count > $threshold;
	}

	/**
	 * Get recent changes for a rule
	 *
	 * @param int $rule_id     Rule ID
	 * @param int $window_hours Time window in hours
	 * @param int $limit        Maximum results
	 * @return array Array of log entries
	 */
	public function getRecentChanges( int $rule_id, int $window_hours = 24, int $limit = 100 ): array {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table_name
				WHERE rule_id = %d
				AND change_detected_at >= DATE_SUB(NOW(), INTERVAL %d HOUR)
				ORDER BY change_detected_at DESC
				LIMIT %d",
				$rule_id,
				$window_hours,
				$limit
			)
		);

		return $results ?: [];
	}

	/**
	 * Get velocity statistics for a rule
	 *
	 * @param int $rule_id      Rule ID
	 * @param int $window_hours Time window in hours
	 * @return array Statistics array
	 */
	public function getVelocityStats( int $rule_id, int $window_hours = 24 ): array {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		$stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) as total_changes,
					COUNT(DISTINCT file_path) as unique_files,
					MIN(change_detected_at) as first_change,
					MAX(change_detected_at) as last_change
				FROM $table_name
				WHERE rule_id = %d
				AND change_detected_at >= DATE_SUB(NOW(), INTERVAL %d HOUR)",
				$rule_id,
				$window_hours
			),
			ARRAY_A
		);

		return $stats ?: [
			'total_changes' => 0,
			'unique_files'  => 0,
			'first_change'  => null,
			'last_change'   => null,
		];
	}

	/**
	 * Get files with highest change velocity
	 *
	 * @param int $rule_id      Rule ID
	 * @param int $window_hours Time window in hours
	 * @param int $limit        Maximum results
	 * @return array Array of files with change counts
	 */
	public function getTopChangedFiles( int $rule_id, int $window_hours = 24, int $limit = 10 ): array {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					file_path,
					COUNT(*) as change_count,
					MAX(change_detected_at) as last_change
				FROM $table_name
				WHERE rule_id = %d
				AND change_detected_at >= DATE_SUB(NOW(), INTERVAL %d HOUR)
				GROUP BY file_path
				ORDER BY change_count DESC, last_change DESC
				LIMIT %d",
				$rule_id,
				$window_hours,
				$limit
			)
		);

		return $results ?: [];
	}

	/**
	 * Clean up old velocity log entries
	 *
	 * @param int $days Number of days to retain
	 * @return int Number of entries deleted
	 */
	public function cleanup( int $days = 30 ): int {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $table_name
				WHERE change_detected_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$days
			)
		);

		return $result !== false ? $result : 0;
	}

	/**
	 * Delete velocity logs for a specific rule
	 *
	 * @param int $rule_id Rule ID
	 * @return int Number of entries deleted
	 */
	public function deleteByRule( int $rule_id ): int {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		$result = $wpdb->delete(
			$table_name,
			[ 'rule_id' => $rule_id ],
			[ '%d' ]
		);

		return $result !== false ? $result : 0;
	}

	/**
	 * Delete velocity logs for a specific scan
	 *
	 * @param int $scan_id Scan ID
	 * @return int Number of entries deleted
	 */
	public function deleteByScan( int $scan_id ): int {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		$result = $wpdb->delete(
			$table_name,
			[ 'scan_id' => $scan_id ],
			[ '%d' ]
		);

		return $result !== false ? $result : 0;
	}

	/**
	 * Get total velocity log count
	 *
	 * @return int Total count
	 */
	public function count(): int {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" );
	}

	/**
	 * Get velocity alert candidates
	 *
	 * Returns files that have exceeded their velocity thresholds.
	 *
	 * @param array $rules Array of rule objects with velocity settings
	 * @return array Array of alert data
	 */
	public function getVelocityAlerts( array $rules ): array {
		$alerts = [];

		foreach ( $rules as $rule ) {
			if ( ! $rule->change_velocity_threshold || ! $rule->velocity_window_hours ) {
				continue;
			}

			$top_files = $this->getTopChangedFiles(
				$rule->id,
				$rule->velocity_window_hours,
				100
			);

			foreach ( $top_files as $file ) {
				if ( $file->change_count > $rule->change_velocity_threshold ) {
					$alerts[] = [
						'rule_id'         => $rule->id,
						'rule_path'       => $rule->path,
						'priority_level'  => $rule->priority_level,
						'file_path'       => $file->file_path,
						'change_count'    => $file->change_count,
						'threshold'       => $rule->change_velocity_threshold,
						'window_hours'    => $rule->velocity_window_hours,
						'last_change'     => $file->last_change,
					];
				}
			}
		}

		return $alerts;
	}
}
