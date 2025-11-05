<?php
/**
 * Priority Rules WP-CLI Command
 *
 * @package EightyFourEM\FileIntegrityChecker\CLI
 */

namespace EightyFourEM\FileIntegrityChecker\CLI;

use EightyFourEM\FileIntegrityChecker\Database\PriorityRulesRepository;
use EightyFourEM\FileIntegrityChecker\Database\VelocityLogRepository;
use WP_CLI;

/**
 * Manage priority monitoring rules
 */
class PriorityRulesCommand {
	/**
	 * Priority rules repository
	 *
	 * @var PriorityRulesRepository
	 */
	private PriorityRulesRepository $repository;

	/**
	 * Velocity log repository
	 *
	 * @var VelocityLogRepository
	 */
	private VelocityLogRepository $velocityRepository;

	/**
	 * Constructor
	 *
	 * @param PriorityRulesRepository $repository         Priority rules repository
	 * @param VelocityLogRepository   $velocityRepository Velocity log repository
	 */
	public function __construct( PriorityRulesRepository $repository, VelocityLogRepository $velocityRepository ) {
		$this->repository         = $repository;
		$this->velocityRepository = $velocityRepository;
	}

	/**
	 * List all priority rules
	 *
	 * ## OPTIONS
	 *
	 * [--priority=<level>]
	 * : Filter by priority level (critical, high, normal)
	 *
	 * [--active]
	 * : Show only active rules
	 *
	 * [--format=<format>]
	 * : Output format (table, json, csv). Default: table
	 *
	 * ## EXAMPLES
	 *
	 *     wp eightyfourem priority-rules list
	 *     wp eightyfourem priority-rules list --priority=critical
	 *     wp eightyfourem priority-rules list --active --format=json
	 *
	 * @param array $args       Positional arguments
	 * @param array $assoc_args Named arguments
	 */
	public function list( array $args, array $assoc_args ): void {
		$filters = [];

		if ( isset( $assoc_args['priority'] ) ) {
			$filters['priority_level'] = $assoc_args['priority'];
		}

		if ( isset( $assoc_args['active'] ) ) {
			$filters['is_active'] = 1;
		}

		$rules = $this->repository->findAll( $filters );

		if ( empty( $rules ) ) {
			WP_CLI::warning( 'No priority rules found.' );
			return;
		}

		$format = $assoc_args['format'] ?? 'table';

		$items = array_map( function ( $rule ) {
			return [
				'ID'       => $rule->id,
				'Path'     => $rule->path,
				'Priority' => $rule->priority_level,
				'Match'    => $rule->match_type,
				'Notify'   => $rule->notify_immediately ? 'Yes' : 'No',
				'Active'   => $rule->is_active ? 'Yes' : 'No',
			];
		}, $rules );

		WP_CLI\Utils\format_items( $format, $items, [ 'ID', 'Path', 'Priority', 'Match', 'Notify', 'Active' ] );
	}

	/**
	 * Add a new priority rule
	 *
	 * ## OPTIONS
	 *
	 * <path>
	 * : File path or pattern
	 *
	 * <priority>
	 * : Priority level (critical, high, normal)
	 *
	 * [--match-type=<type>]
	 * : Match type (exact, prefix, suffix, contains, glob, regex). Default: exact
	 *
	 * [--reason=<reason>]
	 * : Reason for prioritizing this path
	 *
	 * [--notify]
	 * : Send immediate notification on change
	 *
	 * [--velocity=<threshold>]
	 * : Change velocity threshold
	 *
	 * [--window=<hours>]
	 * : Velocity window in hours. Default: 24
	 *
	 * ## EXAMPLES
	 *
	 *     wp eightyfourem priority-rules add /wp-config.php critical --notify
	 *     wp eightyfourem priority-rules add "/wp-admin/*" high --match-type=prefix
	 *     wp eightyfourem priority-rules add "*.php" normal --match-type=suffix --velocity=10
	 *
	 * @param array $args       Positional arguments
	 * @param array $assoc_args Named arguments
	 */
	public function add( array $args, array $assoc_args ): void {
		list( $path, $priority ) = $args;

		$data = [
			'path'                      => $path,
			'priority_level'            => $priority,
			'match_type'                => $assoc_args['match-type'] ?? 'exact',
			'reason'                    => $assoc_args['reason'] ?? null,
			'notify_immediately'        => isset( $assoc_args['notify'] ) ? 1 : 0,
			'change_velocity_threshold' => isset( $assoc_args['velocity'] ) ? absint( $assoc_args['velocity'] ) : null,
			'velocity_window_hours'     => isset( $assoc_args['window'] ) ? absint( $assoc_args['window'] ) : 24,
		];

		$rule_id = $this->repository->create( $data );

		if ( $rule_id ) {
			WP_CLI::success( "Priority rule created with ID: $rule_id" );
		} else {
			WP_CLI::error( 'Failed to create priority rule.' );
		}
	}

	/**
	 * Delete a priority rule
	 *
	 * ## OPTIONS
	 *
	 * <rule-id>
	 * : Rule ID to delete
	 *
	 * [--yes]
	 * : Skip confirmation
	 *
	 * ## EXAMPLES
	 *
	 *     wp eightyfourem priority-rules delete 5
	 *     wp eightyfourem priority-rules delete 5 --yes
	 *
	 * @param array $args       Positional arguments
	 * @param array $assoc_args Named arguments
	 */
	public function delete( array $args, array $assoc_args ): void {
		list( $rule_id ) = $args;

		$rule = $this->repository->find( absint( $rule_id ) );

		if ( ! $rule ) {
			WP_CLI::error( 'Rule not found.' );
		}

		WP_CLI::confirm( "Are you sure you want to delete rule '{$rule->path}'?", $assoc_args );

		if ( $this->repository->delete( $rule->id ) ) {
			WP_CLI::success( 'Priority rule deleted.' );
		} else {
			WP_CLI::error( 'Failed to delete priority rule.' );
		}
	}

	/**
	 * Get velocity statistics for a rule
	 *
	 * ## OPTIONS
	 *
	 * <rule-id>
	 * : Rule ID
	 *
	 * [--window=<hours>]
	 * : Time window in hours. Default: 24
	 *
	 * [--format=<format>]
	 * : Output format (table, json, yaml). Default: table
	 *
	 * ## EXAMPLES
	 *
	 *     wp eightyfourem priority-rules stats 1
	 *     wp eightyfourem priority-rules stats 1 --window=48 --format=json
	 *
	 * @param array $args       Positional arguments
	 * @param array $assoc_args Named arguments
	 */
	public function stats( array $args, array $assoc_args ): void {
		list( $rule_id ) = $args;
		$window = isset( $assoc_args['window'] ) ? absint( $assoc_args['window'] ) : 24;

		$stats = $this->velocityRepository->getVelocityStats( absint( $rule_id ), $window );

		if ( empty( $stats ) || $stats['total_changes'] === 0 ) {
			WP_CLI::warning( 'No velocity data found for this rule.' );
			return;
		}

		$format = $assoc_args['format'] ?? 'table';

		WP_CLI\Utils\format_items( $format, [ $stats ], [ 'total_changes', 'unique_files', 'first_change', 'last_change' ] );
	}

	/**
	 * Get velocity alerts for all rules
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format (table, json, csv). Default: table
	 *
	 * ## EXAMPLES
	 *
	 *     wp eightyfourem priority-rules alerts
	 *     wp eightyfourem priority-rules alerts --format=json
	 *
	 * @param array $args       Positional arguments
	 * @param array $assoc_args Named arguments
	 */
	public function alerts( array $args, array $assoc_args ): void {
		$rules = $this->repository->getActiveRules();
		$alerts = $this->velocityRepository->getVelocityAlerts( $rules );

		if ( empty( $alerts ) ) {
			WP_CLI::success( 'No velocity alerts found.' );
			return;
		}

		$format = $assoc_args['format'] ?? 'table';

		$items = array_map( function ( $alert ) {
			return [
				'Rule ID'     => $alert['rule_id'],
				'Path'        => $alert['rule_path'],
				'File'        => $alert['file_path'],
				'Changes'     => $alert['change_count'],
				'Threshold'   => $alert['threshold'],
				'Window (hrs)' => $alert['window_hours'],
			];
		}, $alerts );

		WP_CLI\Utils\format_items( $format, $items, [ 'Rule ID', 'Path', 'File', 'Changes', 'Threshold', 'Window (hrs)' ] );

		WP_CLI::warning( count( $alerts ) . ' files have exceeded velocity thresholds!' );
	}

	/**
	 * Activate or deactivate a rule
	 *
	 * ## OPTIONS
	 *
	 * <rule-id>
	 * : Rule ID
	 *
	 * <status>
	 * : Status (active, inactive)
	 *
	 * ## EXAMPLES
	 *
	 *     wp eightyfourem priority-rules toggle 5 active
	 *     wp eightyfourem priority-rules toggle 5 inactive
	 *
	 * @param array $args       Positional arguments
	 * @param array $assoc_args Named arguments
	 */
	public function toggle( array $args, array $assoc_args ): void {
		list( $rule_id, $status ) = $args;

		$is_active = $status === 'active' ? 1 : 0;

		if ( $this->repository->setActive( absint( $rule_id ), $is_active ) ) {
			WP_CLI::success( "Rule $status." );
		} else {
			WP_CLI::error( 'Failed to update rule status.' );
		}
	}
}
