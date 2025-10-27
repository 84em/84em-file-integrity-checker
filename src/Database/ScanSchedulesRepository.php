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

        // Handle days_of_week array for weekly schedules
        if ( isset( $data['days_of_week'] ) && is_array( $data['days_of_week'] ) ) {
            $data['days_of_week'] = json_encode( array_values( $data['days_of_week'] ) );
        }

        $defaults = [
            'name' => '',
            'frequency' => 'daily',
            'time' => null,
            'days_of_week' => null,
            'hour' => null,
            'minute' => null,
            'timezone' => wp_timezone_string(),
            'is_active' => 1,
            'next_run' => $this->calculateNextRun( $data ),
        ];

        $data = wp_parse_args( $data, $defaults );

        // Map the data to database columns
        $db_data = [
            'name' => $data['name'],
            'frequency' => $data['frequency'],
            'time' => $data['time'],
            'day_of_week' => $data['days_of_week'], // Store JSON in day_of_week column
            'hour' => $data['hour'],
            'minute' => $data['minute'],
            'timezone' => $data['timezone'],
            'is_active' => $data['is_active'],
            'next_run' => $data['next_run'],
        ];

        $result = $wpdb->insert(
            $table,
            $db_data,
            [
                '%s', // name
                '%s', // frequency
                '%s', // time
                '%s', // day_of_week (now stores JSON string)
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

        // Handle days_of_week array for weekly schedules (must be before cleanup)
        if ( isset( $data['days_of_week'] ) ) {
            if ( is_array( $data['days_of_week'] ) ) {
                $data['day_of_week'] = json_encode( array_values( $data['days_of_week'] ) );
            } else {
                $data['day_of_week'] = $data['days_of_week'];
            }
        }
        // Always unset days_of_week as it's not a real database column
        unset( $data['days_of_week'] );

        // Clean up fields that don't apply to the new frequency
        if ( isset( $data['frequency'] ) ) {
            switch ( $data['frequency'] ) {
                case 'hourly':
                    // Hourly only uses minute field
                    $data['time'] = null;
                    $data['hour'] = null;
                    $data['day_of_week'] = null;
                    break;
                case 'daily':
                    // Daily only uses time field
                    $data['minute'] = null;
                    $data['day_of_week'] = null;
                    break;
                case 'weekly':
                    // Weekly uses time and day_of_week
                    $data['minute'] = null;
                    $data['hour'] = null;
                    break;
            }
        }

        // Recalculate next run if schedule timing changed
        if ( isset( $data['frequency'] ) || isset( $data['time'] ) ||
             isset( $data['day_of_week'] ) || isset( $data['days_of_week'] ) ||
             isset( $data['hour'] ) || isset( $data['minute'] ) ) {

            $existing = $this->get( $id );
            if ( $existing ) {
                $merged = array_merge( (array) $existing, $data );
                // Convert back for calculation
                if ( isset( $merged['day_of_week'] ) && is_string( $merged['day_of_week'] ) && strpos( $merged['day_of_week'], '[' ) === 0 ) {
                    $merged['days_of_week'] = json_decode( $merged['day_of_week'], true );
                }
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

        $schedule = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE id = %d",
                $id
            )
        );

        // Process schedule to add days_of_week for backward compatibility
        if ( $schedule && $schedule->frequency === 'weekly' && ! empty( $schedule->day_of_week ) ) {
            // Check if it's JSON
            if ( strpos( $schedule->day_of_week, '[' ) === 0 ) {
                $schedule->days_of_week = $schedule->day_of_week;
            } else {
                // Legacy single day format
                $schedule->days_of_week = json_encode( [ (int) $schedule->day_of_week ] );
            }
        }

        return $schedule;
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

        $schedules = $wpdb->get_results(
            $wpdb->prepare( $query, $where_values )
        );

        // Process schedules to add days_of_week for backward compatibility
        foreach ( $schedules as &$schedule ) {
            if ( $schedule->frequency === 'weekly' && ! empty( $schedule->day_of_week ) ) {
                // Check if it's JSON
                if ( strpos( $schedule->day_of_week, '[' ) === 0 ) {
                    $schedule->days_of_week = $schedule->day_of_week;
                } else {
                    // Legacy single day format
                    $schedule->days_of_week = json_encode( [ (int) $schedule->day_of_week ] );
                }
            }
        }

        return $schedules;
    }

    /**
     * Get active schedules that need to run
     *
     * @return array Array of schedule objects
     */
    public function getDueSchedules(): array {
        global $wpdb;

        $table = $this->dbManager->getScanSchedulesTableName();
        // Use GMT time since we store in UTC
        $now = current_time( 'mysql', true );

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
                'last_run' => current_time( 'mysql', true ), // Use GMT/UTC time
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
     * @return string Next run datetime in MySQL format (UTC)
     */
    private function calculateNextRun( array $schedule ): string {
        // WordPress core approach: work in site timezone, store in UTC
        $timezone = wp_timezone();
        $now = new \DateTime( 'now', $timezone );
        $next = clone $now;

        switch ( $schedule['frequency'] ) {
            case 'hourly':
                $minute = $schedule['minute'] ?? 0;
                $next->setTime( (int) $next->format( 'H' ), (int) $minute, 0 );
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
                $next->setTime( (int) $hour, (int) $minute, 0 );
                if ( $next <= $now ) {
                    $next->modify( '+1 day' );
                }
                break;

            case 'weekly':
                // Handle multiple days of week
                $days_of_week = [];
                if ( isset( $schedule['days_of_week'] ) ) {
                    if ( is_string( $schedule['days_of_week'] ) ) {
                        $days_of_week = json_decode( $schedule['days_of_week'], true ) ?: [];
                    } elseif ( is_array( $schedule['days_of_week'] ) ) {
                        $days_of_week = $schedule['days_of_week'];
                    }
                } elseif ( isset( $schedule['day_of_week'] ) ) {
                    if ( is_string( $schedule['day_of_week'] ) && strpos( $schedule['day_of_week'], '[' ) === 0 ) {
                        $days_of_week = json_decode( $schedule['day_of_week'], true ) ?: [];
                    } else {
                        $days_of_week = [ $schedule['day_of_week'] ];
                    }
                }
                
                if ( empty( $days_of_week ) ) {
                    $days_of_week = [ 1 ]; // Monday by default
                }
                
                if ( ! empty( $schedule['time'] ) ) {
                    list( $hour, $minute ) = explode( ':', $schedule['time'] );
                } else {
                    $hour = $schedule['hour'] ?? 0;
                    $minute = $schedule['minute'] ?? 0;
                }
                
                // Find the next occurrence among selected days
                $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                $next_dates = [];
                
                foreach ( $days_of_week as $day_num ) {
                    if ( ! isset( $days[$day_num] ) ) {
                        continue;
                    }
                    
                    $temp = clone $now;
                    $target_day = $days[$day_num];
                    
                    // Get next occurrence of this day
                    $current_day = (int) $temp->format( 'w' );
                    if ( $current_day === $day_num ) {
                        // It's today, check if time has passed
                        $temp->setTime( (int) $hour, (int) $minute, 0 );
                        if ( $temp <= $now ) {
                            // Time has passed, move to next week
                            $temp->modify( '+1 week' );
                        }
                    } else {
                        // Move to the next occurrence of this day
                        $temp->modify( "next $target_day" );
                        $temp->setTime( (int) $hour, (int) $minute, 0 );
                    }
                    
                    $next_dates[] = $temp;
                }
                
                // Get the earliest next date
                if ( ! empty( $next_dates ) ) {
                    usort( $next_dates, function( $a, $b ) {
                        return $a <=> $b;
                    });
                    $next = $next_dates[0];
                } else {
                    // Fallback to next Monday if no days selected
                    $next->modify( 'next Monday' );
                    $next->setTime( (int) $hour, (int) $minute, 0 );
                }
                break;


            default:
                // Default to daily at midnight
                $next->setTime( 0, 0, 0 );
                $next->modify( '+1 day' );
                break;
        }

        // Convert to UTC for storage (WordPress standard)
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

    /**
     * Recalculate next run time for a schedule
     *
     * @param int $id Schedule ID
     * @return bool True on success, false on failure
     */
    public function recalculateNextRun( int $id ): bool {
        $schedule = $this->get( $id );
        
        if ( ! $schedule ) {
            return false;
        }

        $next_run = $this->calculateNextRun( (array) $schedule );
        
        return $this->update( $id, [ 'next_run' => $next_run ] );
    }

    /**
     * Recalculate next run times for all active schedules
     *
     * @return int Number of schedules updated
     */
    public function recalculateAllNextRuns(): int {
        $schedules = $this->getAll( [ 'is_active' => 1 ] );
        $updated = 0;

        foreach ( $schedules as $schedule ) {
            if ( $this->recalculateNextRun( $schedule->id ) ) {
                $updated++;
            }
        }

        return $updated;
    }
}