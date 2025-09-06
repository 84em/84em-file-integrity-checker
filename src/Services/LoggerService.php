<?php
/**
 * Logger Service
 *
 * @package EightyFourEM\FileIntegrityChecker\Services
 */

namespace EightyFourEM\FileIntegrityChecker\Services;

use EightyFourEM\FileIntegrityChecker\Database\LogRepository;

/**
 * Service for logging system events and messages
 */
class LoggerService {
    /**
     * Log levels
     */
    public const LEVEL_SUCCESS = 'success';
    public const LEVEL_ERROR = 'error';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_INFO = 'info';
    public const LEVEL_DEBUG = 'debug';

    /**
     * Log contexts
     */
    public const CONTEXT_SCANNER = 'scanner';
    public const CONTEXT_SCHEDULER = 'scheduler';
    public const CONTEXT_NOTIFICATIONS = 'notifications';
    public const CONTEXT_ADMIN = 'admin';
    public const CONTEXT_CLI = 'cli';
    public const CONTEXT_SETTINGS = 'settings';
    public const CONTEXT_DATABASE = 'database';
    public const CONTEXT_SECURITY = 'security';
    public const CONTEXT_GENERAL = 'general';

    /**
     * Log repository
     *
     * @var LogRepository
     */
    private LogRepository $logRepository;

    /**
     * Settings service
     *
     * @var SettingsService
     */
    private SettingsService $settingsService;

    /**
     * Constructor
     *
     * @param LogRepository   $logRepository   Log repository
     * @param SettingsService $settingsService Settings service
     */
    public function __construct( LogRepository $logRepository, SettingsService $settingsService ) {
        $this->logRepository = $logRepository;
        $this->settingsService = $settingsService;
    }

    /**
     * Log a success message
     *
     * @param string $message The message to log
     * @param string $context The context of the log
     * @param array  $data    Additional data to log
     * @return int|false Log ID on success, false on failure
     */
    public function success( string $message, string $context = self::CONTEXT_GENERAL, array $data = [] ): int|false {
        return $this->log( self::LEVEL_SUCCESS, $message, $context, $data );
    }

    /**
     * Log an error message
     *
     * @param string $message The message to log
     * @param string $context The context of the log
     * @param array  $data    Additional data to log
     * @return int|false Log ID on success, false on failure
     */
    public function error( string $message, string $context = self::CONTEXT_GENERAL, array $data = [] ): int|false {
        return $this->log( self::LEVEL_ERROR, $message, $context, $data );
    }

    /**
     * Log a warning message
     *
     * @param string $message The message to log
     * @param string $context The context of the log
     * @param array  $data    Additional data to log
     * @return int|false Log ID on success, false on failure
     */
    public function warning( string $message, string $context = self::CONTEXT_GENERAL, array $data = [] ): int|false {
        return $this->log( self::LEVEL_WARNING, $message, $context, $data );
    }

    /**
     * Log an info message
     *
     * @param string $message The message to log
     * @param string $context The context of the log
     * @param array  $data    Additional data to log
     * @return int|false Log ID on success, false on failure
     */
    public function info( string $message, string $context = self::CONTEXT_GENERAL, array $data = [] ): int|false {
        return $this->log( self::LEVEL_INFO, $message, $context, $data );
    }

    /**
     * Log a debug message
     *
     * @param string $message The message to log
     * @param string $context The context of the log
     * @param array  $data    Additional data to log
     * @return int|false Log ID on success, false on failure
     */
    public function debug( string $message, string $context = self::CONTEXT_GENERAL, array $data = [] ): int|false {
        // Check if debug logging is enabled
        if ( ! $this->isDebugEnabled() ) {
            return false;
        }
        
        return $this->log( self::LEVEL_DEBUG, $message, $context, $data );
    }

    /**
     * Log a message
     *
     * @param string $level   The log level
     * @param string $message The message to log
     * @param string $context The context of the log
     * @param array  $data    Additional data to log
     * @return int|false Log ID on success, false on failure
     */
    private function log( string $level, string $message, string $context, array $data = [] ): int|false {
        // Check if this log level should be recorded
        if ( ! $this->shouldLog( $level ) ) {
            return false;
        }

        // Add additional context data
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            $data['cli'] = true;
            $context = self::CONTEXT_CLI;
        }

        // Add memory usage for certain contexts
        if ( in_array( $context, [ self::CONTEXT_SCANNER, self::CONTEXT_SCHEDULER ], true ) ) {
            $data['memory_usage'] = memory_get_usage( true );
            $data['memory_peak'] = memory_get_peak_usage( true );
        }

        // Create log entry
        return $this->logRepository->create( [
            'log_level' => $level,
            'context' => $context,
            'message' => $message,
            'data' => ! empty( $data ) ? $data : null,
        ] );
    }

    /**
     * Check if a log level should be recorded
     *
     * @param string $level The log level
     * @return bool
     */
    private function shouldLog( string $level ): bool {
        $enabled_levels = $this->settingsService->getEnabledLogLevels();
        return in_array( $level, $enabled_levels, true );
    }

    /**
     * Check if debug logging is enabled
     *
     * @return bool
     */
    private function isDebugEnabled(): bool {
        return $this->settingsService->isDebugModeEnabled() || 
               ( defined( 'WP_DEBUG' ) && WP_DEBUG );
    }

    /**
     * Clean up old logs based on retention settings
     *
     * @return int Number of deleted logs
     */
    public function cleanupOldLogs(): int {
        $retention_days = $this->settingsService->getLogRetentionDays();
        
        if ( $retention_days <= 0 ) {
            return 0;
        }

        return $this->logRepository->deleteOld( $retention_days );
    }

    /**
     * Clear all logs
     *
     * @return bool
     */
    public function clearAllLogs(): bool {
        $this->logRepository->deleteAll();
        return true;
    }

    /**
     * Get logs with filters
     *
     * @param array $args Query arguments
     * @return array
     */
    public function getLogs( array $args = [] ): array {
        return $this->logRepository->getAll( $args );
    }

    /**
     * Get total log count with filters
     *
     * @param array $args Query arguments
     * @return int
     */
    public function getLogCount( array $args = [] ): int {
        return $this->logRepository->getCount( $args );
    }

    /**
     * Get available contexts
     *
     * @return array
     */
    public function getAvailableContexts(): array {
        return $this->logRepository->getContexts();
    }

    /**
     * Get available log levels
     *
     * @return array
     */
    public function getAvailableLevels(): array {
        return [
            self::LEVEL_SUCCESS,
            self::LEVEL_ERROR,
            self::LEVEL_WARNING,
            self::LEVEL_INFO,
            self::LEVEL_DEBUG,
        ];
    }

    /**
     * Get log level label
     *
     * @param string $level The log level
     * @return string
     */
    public function getLevelLabel( string $level ): string {
        $labels = [
            self::LEVEL_SUCCESS => __( 'Success', '84em-file-integrity-checker' ),
            self::LEVEL_ERROR => __( 'Error', '84em-file-integrity-checker' ),
            self::LEVEL_WARNING => __( 'Warning', '84em-file-integrity-checker' ),
            self::LEVEL_INFO => __( 'Info', '84em-file-integrity-checker' ),
            self::LEVEL_DEBUG => __( 'Debug', '84em-file-integrity-checker' ),
        ];

        return $labels[ $level ] ?? $level;
    }

    /**
     * Get log level color
     *
     * @param string $level The log level
     * @return string
     */
    public function getLevelColor( string $level ): string {
        $colors = [
            self::LEVEL_SUCCESS => '#46b450',
            self::LEVEL_ERROR => '#dc3232',
            self::LEVEL_WARNING => '#ffb900',
            self::LEVEL_INFO => '#00a0d2',
            self::LEVEL_DEBUG => '#826eb4',
        ];

        return $colors[ $level ] ?? '#666666';
    }
}