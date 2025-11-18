<?php
/**
 * Log Repository
 *
 * @package EightyFourEM\FileIntegrityChecker\Database
 */

namespace EightyFourEM\FileIntegrityChecker\Database;

/**
 * Repository for managing system logs
 */
class LogRepository {
    /**
     * Table name
     */
    private const TABLE_NAME = 'eightyfourem_integrity_logs';

    /**
     * Maximum number of logs to return per page
     */
    private const MAX_PER_PAGE = 100;

    /**
     * Create a new log entry
     *
     * @param array $data Log data
     * @return int|false Log ID on success, false on failure
     */
    public function create( array $data ): int|false {
        global $wpdb;

        $defaults = [
            'log_level' => 'info',
            'context' => 'general',
            'message' => '',
            'data' => null,
            'user_id' => get_current_user_id() ?: null,
            'ip_address' => $this->getCurrentIpAddress(),
            'created_at' => current_time( 'mysql' ),
        ];

        $data = wp_parse_args( $data, $defaults );

        // Encode data array as JSON if provided
        if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
            $data['data'] = wp_json_encode( $data['data'] );
        }

        $result = $wpdb->insert(
            $wpdb->prefix . self::TABLE_NAME,
            $data,
            [
                '%s', // log_level
                '%s', // context
                '%s', // message
                '%s', // data
                '%d', // user_id
                '%s', // ip_address
                '%s', // created_at
            ]
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Get all logs with optional filters
     *
     * @param array $args Query arguments
     * @return array Array of log entries
     */
    public function getAll( array $args = [] ): array {
        global $wpdb;

        $defaults = [
            'log_level' => null,
            'context' => null,
            'user_id' => null,
            'date_from' => null,
            'date_to' => null,
            'search' => null,
            'orderby' => 'created_at',
            'order' => 'DESC',
            'limit' => 50,
            'offset' => 0,
        ];

        $args = wp_parse_args( $args, $defaults );
        
        // Sanitize limit
        $args['limit'] = min( (int) $args['limit'], self::MAX_PER_PAGE );

        $table = $wpdb->prefix . self::TABLE_NAME;
        $where_clauses = [];
        $where_values = [];

        // Build WHERE clauses
        if ( ! empty( $args['log_level'] ) ) {
            $where_clauses[] = 'log_level = %s';
            $where_values[] = $args['log_level'];
        }

        if ( ! empty( $args['context'] ) ) {
            $where_clauses[] = 'context = %s';
            $where_values[] = $args['context'];
        }

        if ( ! empty( $args['user_id'] ) ) {
            $where_clauses[] = 'user_id = %d';
            $where_values[] = (int) $args['user_id'];
        }

        if ( ! empty( $args['date_from'] ) ) {
            $where_clauses[] = 'created_at >= %s';
            $where_values[] = $args['date_from'];
        }

        if ( ! empty( $args['date_to'] ) ) {
            $where_clauses[] = 'created_at <= %s';
            $where_values[] = $args['date_to'];
        }

        if ( ! empty( $args['search'] ) ) {
            $where_clauses[] = 'message LIKE %s';
            $where_values[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
        }

        // Build query
        $query = "SELECT * FROM $table";
        
        if ( ! empty( $where_clauses ) ) {
            $query .= ' WHERE ' . implode( ' AND ', $where_clauses );
        }

        // Add ORDER BY
        $allowed_orderby = [ 'id', 'log_level', 'context', 'created_at' ];
        $orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
        $order = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';
        $query .= " ORDER BY $orderby $order";

        // Add LIMIT and OFFSET
        $query .= ' LIMIT %d OFFSET %d';
        $where_values[] = $args['limit'];
        $where_values[] = $args['offset'];

        // Execute query
        if ( ! empty( $where_values ) ) {
            $query = $wpdb->prepare( $query, $where_values );
        }

        $results = $wpdb->get_results( $query, ARRAY_A );

        // Decode JSON data fields
        foreach ( $results as &$row ) {
            if ( ! empty( $row['data'] ) ) {
                $row['data'] = json_decode( $row['data'], true );
            }
        }

        return $results ?: [];
    }

    /**
     * Get logs by context
     *
     * @param string $context The context to filter by
     * @param int    $limit   Maximum number of results
     * @return array Array of log entries
     */
    public function getByContext( string $context, int $limit = 50 ): array {
        return $this->getAll( [
            'context' => $context,
            'limit' => $limit,
        ] );
    }

    /**
     * Get logs by level
     *
     * @param string $level The log level to filter by
     * @param int    $limit Maximum number of results
     * @return array Array of log entries
     */
    public function getByLevel( string $level, int $limit = 50 ): array {
        return $this->getAll( [
            'log_level' => $level,
            'limit' => $limit,
        ] );
    }

    /**
     * Get total count of logs with optional filters
     *
     * @param array $args Query arguments (same as getAll but without pagination)
     * @return int Total count
     */
    public function getCount( array $args = [] ): int {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;
        $where_clauses = [];
        $where_values = [];

        // Build WHERE clauses (same as getAll)
        if ( ! empty( $args['log_level'] ) ) {
            $where_clauses[] = 'log_level = %s';
            $where_values[] = $args['log_level'];
        }

        if ( ! empty( $args['context'] ) ) {
            $where_clauses[] = 'context = %s';
            $where_values[] = $args['context'];
        }

        if ( ! empty( $args['user_id'] ) ) {
            $where_clauses[] = 'user_id = %d';
            $where_values[] = (int) $args['user_id'];
        }

        if ( ! empty( $args['date_from'] ) ) {
            $where_clauses[] = 'created_at >= %s';
            $where_values[] = $args['date_from'];
        }

        if ( ! empty( $args['date_to'] ) ) {
            $where_clauses[] = 'created_at <= %s';
            $where_values[] = $args['date_to'];
        }

        if ( ! empty( $args['search'] ) ) {
            $where_clauses[] = 'message LIKE %s';
            $where_values[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
        }

        // Build query
        $query = "SELECT COUNT(*) FROM $table";
        
        if ( ! empty( $where_clauses ) ) {
            $query .= ' WHERE ' . implode( ' AND ', $where_clauses );
        }

        // Execute query
        if ( ! empty( $where_values ) ) {
            $query = $wpdb->prepare( $query, $where_values );
        }

        return (int) $wpdb->get_var( $query );
    }

    /**
     * Delete old logs
     *
     * @param int $days_old Delete logs older than this many days
     * @return int Number of deleted rows
     */
    public function deleteOld( int $days_old = 30 ): int {
        global $wpdb;

        $cutoff_date = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days_old} days" ) );

        $result = $wpdb->delete(
            $wpdb->prefix . self::TABLE_NAME,
            [ 'created_at <' => $cutoff_date ],
            [ '%s' ]
        );

        // Use custom query since wpdb::delete doesn't support operators
        $query = $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}" . self::TABLE_NAME . " WHERE created_at < %s",
            $cutoff_date
        );

        return $wpdb->query( $query );
    }

    /**
     * Delete old logs using tiered retention policy
     * Tier 2: Keep all logs for tier2_days (30 days default)
     * Tier 3: Keep only warning/error logs for tier3_days (90 days default)
     * After tier3_days: Delete all logs
     *
     * @param int $tier2_days Days to keep all logs (default 30)
     * @param int $tier3_days Days to keep warning/error logs (default 90)
     * @return array Statistics about deleted logs
     */
    public function deleteOldWithTiers( int $tier2_days = 30, int $tier3_days = 90 ): array {
        global $wpdb;

        $stats = [
            'tier2_deleted' => 0,
            'tier3_deleted' => 0,
            'total_deleted' => 0,
        ];

        $tier2_cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$tier2_days} days" ) );
        $tier3_cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$tier3_days} days" ) );

        // Tier 2: Delete info/debug logs older than tier2_days but keep warning/error
        $tier2_sql = $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}" . self::TABLE_NAME .
            " WHERE created_at < %s
            AND created_at >= %s
            AND log_level NOT IN ('warning', 'error')",
            $tier2_cutoff,
            $tier3_cutoff
        );

        $stats['tier2_deleted'] = $wpdb->query( $tier2_sql );

        // Tier 3: Delete all logs older than tier3_days
        $tier3_sql = $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}" . self::TABLE_NAME .
            " WHERE created_at < %s",
            $tier3_cutoff
        );

        $stats['tier3_deleted'] = $wpdb->query( $tier3_sql );
        $stats['total_deleted'] = $stats['tier2_deleted'] + $stats['tier3_deleted'];

        return $stats;
    }

    /**
     * Delete all logs
     *
     * @return int Number of deleted rows
     */
    public function deleteAll(): int {
        global $wpdb;
        
        $query = "TRUNCATE TABLE {$wpdb->prefix}" . self::TABLE_NAME;
        $wpdb->query( $query );
        
        // Return 0 as TRUNCATE doesn't return affected rows
        return 0;
    }

    /**
     * Delete logs by context
     *
     * @param string $context The context to delete
     * @return int Number of deleted rows
     */
    public function deleteByContext( string $context ): int {
        global $wpdb;

        return $wpdb->delete(
            $wpdb->prefix . self::TABLE_NAME,
            [ 'context' => $context ],
            [ '%s' ]
        );
    }

    /**
     * Get distinct contexts
     *
     * @return array Array of distinct contexts
     */
    public function getContexts(): array {
        global $wpdb;

        $query = "SELECT DISTINCT context FROM {$wpdb->prefix}" . self::TABLE_NAME . " ORDER BY context ASC";
        
        return $wpdb->get_col( $query ) ?: [];
    }

    /**
     * Get distinct log levels
     *
     * @return array Array of distinct log levels
     */
    public function getLevels(): array {
        global $wpdb;

        $query = "SELECT DISTINCT log_level FROM {$wpdb->prefix}" . self::TABLE_NAME . " ORDER BY log_level ASC";
        
        return $wpdb->get_col( $query ) ?: [];
    }

    /**
     * Get current IP address
     *
     * @return string|null
     */
    private function getCurrentIpAddress(): ?string {
        // Check for various IP headers
        $ip_keys = [ 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ];
        
        foreach ( $ip_keys as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                $ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
                
                // Handle comma-separated IPs (from proxies)
                if ( strpos( $ip, ',' ) !== false ) {
                    $ip = trim( explode( ',', $ip )[0] );
                }
                
                // Validate IP
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }
        
        return null;
    }
}