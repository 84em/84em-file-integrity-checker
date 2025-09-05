<?php
/**
 * Scan Schedules Repository
 *
 * @package EightyFourEM\FileIntegrityChecker\Database
 */

namespace EightyFourEM\FileIntegrityChecker\Database;

/**
 * Repository for managing scan schedules
 */
class ScanSchedulesRepository {
    /**
     * Database manager instance
     *
     * @var DatabaseManager
     */
    private DatabaseManager $dbManager;

    /**
     * Constructor
     *
     * @param DatabaseManager $dbManager Database manager instance
     */
    public function __construct( DatabaseManager $dbManager ) {
        $this->dbManager = $dbManager;
    }

    /**
     * Create a new scan schedule
     *
     * @param array $data Schedule data
     * @return int|false Schedule ID on success, false on failure
     */
    public function create( array $data ) {
        global $wpdb;

        $table = $this->dbManager->getScanSchedulesTableName();

        $defaults = [
            'name' => '',
            'frequency' => 'daily',
            'time' => null,
            'day_of_week' => null,
            'day_of_month' => null,
            'hour' => null,
            'minute' => null,
            'timezone' => wp_timezone_string(),
            'is_active' => 1,
            'next_run' => $this->calculateNextRun( $data ),
        ];

        $data = wp_parse_args( $data, $defaults );

        $result = $wpdb->insert(
            $table,
            $data,
            [
                '%s', // name
                '%s', // frequency
                '%s', // time
                '%d', // day_of_week
                '%d', // day_of_month
                '%d', // hour
                '%d', // minute
                '%s', // timezone
                '%d', // is_active
                '%s', // next_run
            ]
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Update a scan schedule
     *
     * @param int   $id   Schedule ID
     * @param array $data Schedule data
     * @return bool True on success, false on failure
     */
    public function update( int $id, array $data ): bool {
        global $wpdb;

        $table = $this->dbManager->getScanSchedulesTableName();

        // Recalculate next run if schedule timing changed
        if ( isset( $data['frequency'] ) || isset( $data['time'] ) || 
             isset( $data['day_of_week'] ) || isset( $data['day_of_month'] ) ||
             isset( $data['hour'] ) || isset( $data['minute'] ) ) {
            
            $existing = $this->get( $id );
            if ( $existing ) {
                $merged = array_merge( (array) $existing, $data );
                $data['next_run'] = $this->calculateNextRun( $merged );
            }
        }

        $result = $wpdb->update(
            $table,
            $data,
            [ 'id' => $id ],
            null,
            [ '%d' ]
        );

        return $result !== false;
    }

    /**
     * Get a scan schedule by ID
     *
     * @param int $id Schedule ID
     * @return object|null Schedule object or null if not found
     */
    public function get( int $id ) {
        global $wpdb;

        $table = $this->dbManager->getScanSchedulesTableName();

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE id = %d",
                $id
            )
        );
    }

    /**
     * Get all scan schedules
     *
     * @param array $args Query arguments
     * @return array Array of schedule objects
     */
    public function getAll( array $args = [] ): array {
        global $wpdb;

        $table = $this->dbManager->getScanSchedulesTableName();

        $defaults = [
            'is_active' => null,
            'frequency' => null,
            'orderby' => 'next_run',
            'order' => 'ASC',
            'limit' => 100,
            'offset' => 0,
        ];

        $args = wp_parse_args( $args, $defaults );

        $where = [];
        $where_values = [];

        if ( $args['is_active'] !== null ) {
            $where[] = 'is_active = %d';
            $where_values[] = $args['is_active'];
        }

        if ( $args['frequency'] !== null ) {
            $where[] = 'frequency = %s';
            $where_values[] = $args['frequency'];
        }

        $where_clause = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';

        $query = "SELECT * FROM $table $where_clause 
                  ORDER BY {$args['orderby']} {$args['order']} 
                  LIMIT %d OFFSET %d";

        $where_values[] = $args['limit'];
        $where_values[] = $args['offset'];

        return $wpdb->get_results(
            $wpdb->prepare( $query, $where_values )
        );
    }

    /**
     * Get active schedules that need to run
     *
     * @return array Array of schedule objects
     */
    public function getDueSchedules(): array {
        global $wpdb;

        $table = $this->dbManager->getScanSchedulesTableName();
        $now = current_time( 'mysql' );

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table 
                 WHERE is_active = 1 
                 AND next_run <= %s 
                 ORDER BY next_run ASC",
                $now
            )
        );
    }

    /**
     * Delete a scan schedule
     *
     * @param int $id Schedule ID
     * @return bool True on success, false on failure
     */
    public function delete( int $id ): bool {
        global $wpdb;

        $table = $this->dbManager->getScanSchedulesTableName();

        $result = $wpdb->delete(
            $table,
            [ 'id' => $id ],
            [ '%d' ]
        );

        return $result !== false;
    }

    /**
     * Update last run time
     *
     * @param int $id Schedule ID
     * @return bool True on success, false on failure
     */
    public function updateLastRun( int $id ): bool {
        global $wpdb;

        $table = $this->dbManager->getScanSchedulesTableName();
        $schedule = $this->get( $id );

        if ( ! $schedule ) {
            return false;
        }

        $next_run = $this->calculateNextRun( (array) $schedule );

        $result = $wpdb->update(
            $table,
            [
                'last_run' => current_time( 'mysql' ),
                'next_run' => $next_run,
            ],
            [ 'id' => $id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        return $result !== false;
    }

    /**
     * Calculate next run time based on schedule configuration
     *
     * @param array $schedule Schedule data
     * @return string Next run datetime in MySQL format
     */
    private function calculateNextRun( array $schedule ): string {
        $timezone = new \DateTimeZone( $schedule['timezone'] ?? wp_timezone_string() );
        $now = new \DateTime( 'now', $timezone );
        $next = clone $now;

        switch ( $schedule['frequency'] ) {
            case 'hourly':
                $minute = $schedule['minute'] ?? 0;
                $next->setTime( $next->format( 'H' ), $minute, 0 );
                if ( $next <= $now ) {
                    $next->modify( '+1 hour' );
                }
                break;

            case 'daily':
                if ( ! empty( $schedule['time'] ) ) {
                    list( $hour, $minute ) = explode( ':', $schedule['time'] );
                } else {
                    $hour = $schedule['hour'] ?? 0;
                    $minute = $schedule['minute'] ?? 0;
                }
                $next->setTime( $hour, $minute, 0 );
                if ( $next <= $now ) {
                    $next->modify( '+1 day' );
                }
                break;

            case 'weekly':
                $day_of_week = $schedule['day_of_week'] ?? 1; // Monday by default
                if ( ! empty( $schedule['time'] ) ) {
                    list( $hour, $minute ) = explode( ':', $schedule['time'] );
                } else {
                    $hour = $schedule['hour'] ?? 0;
                    $minute = $schedule['minute'] ?? 0;
                }
                
                // Set to the specified day of week
                $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                $target_day = $days[$day_of_week];
                $next->modify( "next $target_day" );
                $next->setTime( $hour, $minute, 0 );
                
                // If we're already past this week's scheduled time, move to next week
                if ( $next <= $now ) {
                    $next->modify( '+1 week' );
                }
                break;

            case 'monthly':
                $day_of_month = $schedule['day_of_month'] ?? 1;
                if ( ! empty( $schedule['time'] ) ) {
                    list( $hour, $minute ) = explode( ':', $schedule['time'] );
                } else {
                    $hour = $schedule['hour'] ?? 0;
                    $minute = $schedule['minute'] ?? 0;
                }
                
                // Set to the specified day of month
                $next->setDate( $next->format( 'Y' ), $next->format( 'm' ), $day_of_month );
                $next->setTime( $hour, $minute, 0 );
                
                // If we're already past this month's scheduled time, move to next month
                if ( $next <= $now ) {
                    $next->modify( '+1 month' );
                }
                break;

            default:
                // Default to daily at midnight
                $next->setTime( 0, 0, 0 );
                $next->modify( '+1 day' );
                break;
        }

        // Convert to WordPress timezone for storage
        $next->setTimezone( new \DateTimeZone( 'UTC' ) );
        return $next->format( 'Y-m-d H:i:s' );
    }

    /**
     * Activate a schedule
     *
     * @param int $id Schedule ID
     * @return bool True on success, false on failure
     */
    public function activate( int $id ): bool {
        return $this->update( $id, [ 'is_active' => 1 ] );
    }

    /**
     * Deactivate a schedule
     *
     * @param int $id Schedule ID
     * @return bool True on success, false on failure
     */
    public function deactivate( int $id ): bool {
        return $this->update( $id, [ 'is_active' => 0 ] );
    }

    /**
     * Get schedule count by status
     *
     * @param bool|null $is_active Active status filter
     * @return int Count of schedules
     */
    public function getCount( ?bool $is_active = null ): int {
        global $wpdb;

        $table = $this->dbManager->getScanSchedulesTableName();

        if ( $is_active === null ) {
            return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE is_active = %d",
                $is_active
            )
        );
    }
}