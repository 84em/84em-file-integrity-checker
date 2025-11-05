<?php
/**
 * Priority Rules Repository
 *
 * @package EightyFourEM\FileIntegrityChecker\Database
 */

namespace EightyFourEM\FileIntegrityChecker\Database;

/**
 * Repository for managing priority monitoring rules
 */
class PriorityRulesRepository {
	/**
	 * Table name
	 */
	private const TABLE_NAME = 'eightyfourem_integrity_priority_rules';

	/**
	 * Create a new priority rule
	 *
	 * @param array $data Rule data
	 * @return int|false Rule ID on success, false on failure
	 */
	public function create( array $data ): int|false {
		global $wpdb;

		$required_fields = [ 'path', 'priority_level' ];

		foreach ( $required_fields as $field ) {
			if ( ! isset( $data[ $field ] ) ) {
				return false;
			}
		}

		$defaults = [
			'path_type'                    => 'file',
			'match_type'                   => 'exact',
			'reason'                       => null,
			'notify_immediately'           => 0,
			'ignore_in_bulk_changes'       => 0,
			'maintenance_window_start'     => null,
			'maintenance_window_end'       => null,
			'suppress_during_maintenance'  => 0,
			'change_velocity_threshold'    => null,
			'velocity_window_hours'        => 24,
			'inherit_priority'             => 0,
			'parent_rule_id'               => null,
			'execution_order'              => 100,
			'throttle_minutes'             => null,
			'max_notifications_per_day'    => null,
			'wp_version_min'               => null,
			'wp_version_max'               => null,
			'created_by'                   => get_current_user_id(),
			'is_active'                    => 1,
		];

		$data = wp_parse_args( $data, $defaults );

		$result = $wpdb->insert(
			$wpdb->prefix . self::TABLE_NAME,
			$data,
			[
				'%s', // path
				'%s', // path_type
				'%s', // priority_level
				'%s', // match_type
				'%s', // reason
				'%d', // notify_immediately
				'%d', // ignore_in_bulk_changes
				'%s', // maintenance_window_start
				'%s', // maintenance_window_end
				'%d', // suppress_during_maintenance
				'%d', // change_velocity_threshold
				'%d', // velocity_window_hours
				'%d', // inherit_priority
				'%d', // parent_rule_id
				'%d', // execution_order
				'%d', // throttle_minutes
				'%d', // max_notifications_per_day
				'%s', // wp_version_min
				'%s', // wp_version_max
				'%d', // created_by
				'%d', // is_active
			]
		);

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Update an existing priority rule
	 *
	 * @param int   $id   Rule ID
	 * @param array $data Rule data to update
	 * @return bool True on success, false on failure
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		// Remove fields that shouldn't be updated directly
		unset( $data['id'], $data['created_at'], $data['created_by'] );

		// Add updated_at timestamp
		$data['updated_at'] = current_time( 'mysql' );

		$format = $this->getFormatArray( $data );

		$result = $wpdb->update(
			$wpdb->prefix . self::TABLE_NAME,
			$data,
			[ 'id' => $id ],
			$format,
			[ '%d' ]
		);

		return $result !== false;
	}

	/**
	 * Delete a priority rule
	 *
	 * @param int $id Rule ID
	 * @return bool True on success, false on failure
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		$result = $wpdb->delete(
			$wpdb->prefix . self::TABLE_NAME,
			[ 'id' => $id ],
			[ '%d' ]
		);

		return $result !== false;
	}

	/**
	 * Get a priority rule by ID
	 *
	 * @param int $id Rule ID
	 * @return object|null Rule object or null if not found
	 */
	public function find( int $id ): ?object {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		$rule = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $table_name WHERE id = %d",
				$id
			)
		);

		return $rule ?: null;
	}

	/**
	 * Get all priority rules
	 *
	 * @param array $args Query arguments
	 * @return array Array of rule objects
	 */
	public function findAll( array $args = [] ): array {
		global $wpdb;

		$defaults = [
			'is_active'      => null,
			'priority_level' => null,
			'path_type'      => null,
			'match_type'     => null,
			'order_by'       => 'execution_order',
			'order'          => 'ASC',
			'limit'          => null,
			'offset'         => 0,
		];

		$args = wp_parse_args( $args, $defaults );

		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$where_clauses = [ '1=1' ];
		$where_values = [];

		if ( $args['is_active'] !== null ) {
			$where_clauses[] = 'is_active = %d';
			$where_values[] = $args['is_active'];
		}

		if ( $args['priority_level'] !== null ) {
			$where_clauses[] = 'priority_level = %s';
			$where_values[] = $args['priority_level'];
		}

		if ( $args['path_type'] !== null ) {
			$where_clauses[] = 'path_type = %s';
			$where_values[] = $args['path_type'];
		}

		if ( $args['match_type'] !== null ) {
			$where_clauses[] = 'match_type = %s';
			$where_values[] = $args['match_type'];
		}

		$where_sql = implode( ' AND ', $where_clauses );

		$order_by = sanitize_sql_orderby( $args['order_by'] . ' ' . $args['order'] );
		if ( ! $order_by ) {
			$order_by = 'execution_order ASC';
		}

		$limit_sql = '';
		if ( $args['limit'] !== null ) {
			$limit_sql = $wpdb->prepare( 'LIMIT %d OFFSET %d', $args['limit'], $args['offset'] );
		}

		$sql = "SELECT * FROM $table_name WHERE $where_sql ORDER BY $order_by $limit_sql";

		if ( ! empty( $where_values ) ) {
			$sql = $wpdb->prepare( $sql, $where_values );
		}

		$results = $wpdb->get_results( $sql );

		return $results ?: [];
	}

	/**
	 * Get active rules ordered by execution priority
	 *
	 * @return array Array of rule objects
	 */
	public function getActiveRules(): array {
		return $this->findAll( [
			'is_active' => 1,
			'order_by'  => 'execution_order',
			'order'     => 'ASC',
		] );
	}

	/**
	 * Get rules by priority level
	 *
	 * @param string $level Priority level (critical, high, normal)
	 * @return array Array of rule objects
	 */
	public function findByPriority( string $level ): array {
		return $this->findAll( [
			'priority_level' => $level,
			'is_active'      => 1,
		] );
	}

	/**
	 * Get rules matching a specific file path
	 *
	 * This method evaluates all active rules against the given path.
	 *
	 * @param string $file_path File path to match
	 * @return array Array of matching rule objects
	 */
	public function findMatchingRules( string $file_path ): array {
		$active_rules = $this->getActiveRules();
		$matching_rules = [];

		foreach ( $active_rules as $rule ) {
			if ( $this->pathMatchesRule( $file_path, $rule ) ) {
				$matching_rules[] = $rule;
			}
		}

		return $matching_rules;
	}

	/**
	 * Check if a file path matches a rule
	 *
	 * @param string $file_path File path
	 * @param object $rule      Rule object
	 * @return bool True if path matches rule
	 */
	private function pathMatchesRule( string $file_path, object $rule ): bool {
		$pattern = $rule->path;
		$match_type = $rule->match_type;

		switch ( $match_type ) {
			case 'exact':
				return $file_path === $pattern;

			case 'prefix':
				return str_starts_with( $file_path, rtrim( $pattern, '*' ) );

			case 'suffix':
				return str_ends_with( $file_path, ltrim( $pattern, '*' ) );

			case 'contains':
				return str_contains( $file_path, trim( $pattern, '*' ) );

			case 'glob':
				return $this->globMatch( $pattern, $file_path );

			case 'regex':
				return @preg_match( $pattern, $file_path ) === 1;

			default:
				return false;
		}
	}

	/**
	 * Match file path against glob pattern
	 *
	 * @param string $pattern Glob pattern
	 * @param string $path    File path
	 * @return bool True if path matches pattern
	 */
	private function globMatch( string $pattern, string $path ): bool {
		// Convert glob pattern to regex
		$regex = preg_quote( $pattern, '#' );
		$regex = str_replace( '\*', '.*', $regex );
		$regex = str_replace( '\?', '.', $regex );
		$regex = '#^' . $regex . '$#';

		return preg_match( $regex, $path ) === 1;
	}

	/**
	 * Get highest priority level for a file path
	 *
	 * @param string $file_path File path
	 * @return string|null Priority level (critical, high, normal) or null if no rules match
	 */
	public function getPriorityForPath( string $file_path ): ?string {
		$matching_rules = $this->findMatchingRules( $file_path );

		if ( empty( $matching_rules ) ) {
			return null;
		}

		$priority_order = [ 'critical', 'high', 'normal' ];

		foreach ( $priority_order as $priority ) {
			foreach ( $matching_rules as $rule ) {
				if ( $rule->priority_level === $priority ) {
					return $priority;
				}
			}
		}

		return null;
	}

	/**
	 * Check if path is in maintenance window
	 *
	 * @param string $file_path File path
	 * @return bool True if path is in maintenance window
	 */
	public function isInMaintenanceWindow( string $file_path ): bool {
		$matching_rules = $this->findMatchingRules( $file_path );
		$current_time = current_time( 'mysql' );

		foreach ( $matching_rules as $rule ) {
			if ( ! $rule->suppress_during_maintenance ) {
				continue;
			}

			if ( $rule->maintenance_window_start && $rule->maintenance_window_end ) {
				if ( $current_time >= $rule->maintenance_window_start &&
				     $current_time <= $rule->maintenance_window_end ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Count total rules
	 *
	 * @param array $args Query arguments
	 * @return int Total count
	 */
	public function count( array $args = [] ): int {
		global $wpdb;

		$defaults = [
			'is_active'      => null,
			'priority_level' => null,
		];

		$args = wp_parse_args( $args, $defaults );

		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$where_clauses = [ '1=1' ];
		$where_values = [];

		if ( $args['is_active'] !== null ) {
			$where_clauses[] = 'is_active = %d';
			$where_values[] = $args['is_active'];
		}

		if ( $args['priority_level'] !== null ) {
			$where_clauses[] = 'priority_level = %s';
			$where_values[] = $args['priority_level'];
		}

		$where_sql = implode( ' AND ', $where_clauses );
		$sql = "SELECT COUNT(*) FROM $table_name WHERE $where_sql";

		if ( ! empty( $where_values ) ) {
			$sql = $wpdb->prepare( $sql, $where_values );
		}

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Activate/deactivate a rule
	 *
	 * @param int  $id        Rule ID
	 * @param bool $is_active Activation status
	 * @return bool True on success, false on failure
	 */
	public function setActive( int $id, bool $is_active ): bool {
		return $this->update( $id, [ 'is_active' => $is_active ? 1 : 0 ] );
	}

	/**
	 * Bulk activate/deactivate rules
	 *
	 * @param array $ids       Array of rule IDs
	 * @param bool  $is_active Activation status
	 * @return int Number of rules updated
	 */
	public function bulkSetActive( array $ids, bool $is_active ): int {
		global $wpdb;

		if ( empty( $ids ) ) {
			return 0;
		}

		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$ids_string = implode( ',', array_map( 'absint', $ids ) );
		$active_value = $is_active ? 1 : 0;

		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE $table_name SET is_active = %d, updated_at = %s WHERE id IN ($ids_string)",
				$active_value,
				current_time( 'mysql' )
			)
		);

		return $result !== false ? $result : 0;
	}

	/**
	 * Bulk delete rules
	 *
	 * @param array $ids Array of rule IDs
	 * @return int Number of rules deleted
	 */
	public function bulkDelete( array $ids ): int {
		global $wpdb;

		if ( empty( $ids ) ) {
			return 0;
		}

		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$ids_string = implode( ',', array_map( 'absint', $ids ) );

		$result = $wpdb->query(
			"DELETE FROM $table_name WHERE id IN ($ids_string)"
		);

		return $result !== false ? $result : 0;
	}

	/**
	 * Get format array for wpdb operations
	 *
	 * @param array $data Data array
	 * @return array Format array
	 */
	private function getFormatArray( array $data ): array {
		$format_map = [
			'path'                        => '%s',
			'path_type'                   => '%s',
			'priority_level'              => '%s',
			'match_type'                  => '%s',
			'reason'                      => '%s',
			'notify_immediately'          => '%d',
			'ignore_in_bulk_changes'      => '%d',
			'maintenance_window_start'    => '%s',
			'maintenance_window_end'      => '%s',
			'suppress_during_maintenance' => '%d',
			'change_velocity_threshold'   => '%d',
			'velocity_window_hours'       => '%d',
			'inherit_priority'            => '%d',
			'parent_rule_id'              => '%d',
			'execution_order'             => '%d',
			'throttle_minutes'            => '%d',
			'max_notifications_per_day'   => '%d',
			'wp_version_min'              => '%s',
			'wp_version_max'              => '%s',
			'updated_at'                  => '%s',
			'is_active'                   => '%d',
		];

		$format = [];
		foreach ( $data as $key => $value ) {
			$format[] = $format_map[ $key ] ?? '%s';
		}

		return $format;
	}
}
