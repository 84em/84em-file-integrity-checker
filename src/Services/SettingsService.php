<?php
/**
 * Settings Service
 *
 * @package EightyFourEM\FileIntegrityChecker\Services
 */

namespace EightyFourEM\FileIntegrityChecker\Services;

/**
 * Manages plugin settings
 */
class SettingsService {
    /**
     * Option prefix
     */
    private const OPTION_PREFIX = 'eightyfourem_file_integrity_';

    /**
     * Get scan file types
     *
     * @return array Array of file extensions to scan
     */
    public function getScanFileTypes(): array {
        return get_option( 
            self::OPTION_PREFIX . 'scan_types', 
            [ '.js', '.css', '.html', '.php' ] 
        );
    }

    /**
     * Set scan file types
     *
     * @param array $types Array of file extensions
     * @return bool True on success, false on failure
     */
    public function setScanFileTypes( array $types ): bool {
        // Sanitize file types
        $sanitized_types = array_map( function ( $type ) {
            $type = trim( $type );
            if ( ! str_starts_with( $type, '.' ) ) {
                $type = '.' . $type;
            }
            return strtolower( $type );
        }, $types );

        update_option( self::OPTION_PREFIX . 'scan_types', array_unique( $sanitized_types ) );
        return true;
    }

    /**
     * Get scan interval
     *
     * @return string Scan interval (hourly, daily, weekly, monthly)
     */
    public function getScanInterval(): string {
        return get_option( self::OPTION_PREFIX . 'scan_interval', 'daily' );
    }

    /**
     * Set scan interval
     *
     * @param string $interval Scan interval
     * @return bool True on success, false on failure
     */
    public function setScanInterval( string $interval ): bool {
        $allowed_intervals = [ 'hourly', 'daily', 'weekly', 'monthly' ];
        
        if ( ! in_array( $interval, $allowed_intervals, true ) ) {
            return false;
        }

        update_option( self::OPTION_PREFIX . 'scan_interval', $interval );
        return true;
    }

    /**
     * Get exclude patterns
     *
     * @return array Array of glob patterns to exclude
     */
    public function getExcludePatterns(): array {
        return get_option( 
            self::OPTION_PREFIX . 'exclude_patterns', 
            [
                '*/cache/*',
                '*/logs/*',
                '*/uploads/*',
                '*/wp-content/cache/*',
                '*/wp-content/backup*',
            ]
        );
    }

    /**
     * Set exclude patterns
     *
     * @param array $patterns Array of glob patterns
     * @return bool True on success, false on failure
     */
    public function setExcludePatterns( array $patterns ): bool {
        // Sanitize patterns
        $sanitized_patterns = array_map( 'trim', $patterns );
        $sanitized_patterns = array_filter( $sanitized_patterns ); // Remove empty patterns

        update_option( self::OPTION_PREFIX . 'exclude_patterns', $sanitized_patterns );
        return true;
    }

    /**
     * Get maximum file size to scan
     *
     * @return int Maximum file size in bytes
     */
    public function getMaxFileSize(): int {
        return (int) get_option( self::OPTION_PREFIX . 'max_file_size', 10485760 ); // 10MB default
    }

    /**
     * Set maximum file size to scan
     *
     * @param int $size Maximum file size in bytes
     * @return bool True on success, false on failure
     */
    public function setMaxFileSize( int $size ): bool {
        if ( $size <= 0 || $size > 104857600 ) { // Max 100MB
            return false;
        }

        // update_option returns false if value doesn't change, but that's not an error
        update_option( self::OPTION_PREFIX . 'max_file_size', $size );
        return true;
    }

    /**
     * Check if notifications are enabled
     *
     * @return bool True if notifications are enabled, false otherwise
     */
    public function isNotificationEnabled(): bool {
        return (bool) get_option( self::OPTION_PREFIX . 'notification_enabled', true );
    }

    /**
     * Set notification status
     *
     * @param bool $enabled Whether notifications are enabled
     * @return bool True on success, false on failure
     */
    public function setNotificationEnabled( bool $enabled ): bool {
        update_option( self::OPTION_PREFIX . 'notification_enabled', $enabled );
        return true;
    }

    /**
     * Get notification email address
     *
     * @return string Email address
     */
    public function getNotificationEmail(): string {
        return get_option( self::OPTION_PREFIX . 'notification_email', get_option( 'admin_email' ) );
    }

    /**
     * Set notification email address
     *
     * @param string $email Email address
     * @return bool True on success, false on failure
     */
    public function setNotificationEmail( string $email ): bool {
        if ( ! is_email( $email ) ) {
            return false;
        }

        update_option( self::OPTION_PREFIX . 'notification_email', sanitize_email( $email ) );
        return true;
    }

    /**
     * Check if Slack notifications are enabled
     *
     * @return bool True if Slack notifications are enabled
     */
    public function isSlackEnabled(): bool {
        return (bool) get_option( self::OPTION_PREFIX . 'slack_enabled', false );
    }

    /**
     * Set Slack notification status
     *
     * @param bool $enabled Whether Slack notifications are enabled
     * @return bool True on success
     */
    public function setSlackEnabled( bool $enabled ): bool {
        update_option( self::OPTION_PREFIX . 'slack_enabled', $enabled );
        return true;
    }

    /**
     * Get Slack webhook URL
     *
     * @return string Slack webhook URL
     */
    public function getSlackWebhookUrl(): string {
        return get_option( self::OPTION_PREFIX . 'slack_webhook_url', '' );
    }

    /**
     * Set Slack webhook URL
     *
     * @param string $url Slack webhook URL
     * @return bool True on success, false on failure
     */
    public function setSlackWebhookUrl( string $url ): bool {
        // Basic validation for Slack webhook URL
        if ( ! empty( $url ) && ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
            return false;
        }
        
        // Slack webhooks should start with https://hooks.slack.com/
        if ( ! empty( $url ) && strpos( $url, 'https://hooks.slack.com/' ) !== 0 ) {
            return false;
        }
        
        update_option( self::OPTION_PREFIX . 'slack_webhook_url', sanitize_url( $url ) );
        return true;
    }

    /**
     * Check if file lists should be included in notifications
     *
     * @return bool True if file lists should be included, false otherwise
     */
    public function shouldIncludeFileList(): bool {
        return (bool) get_option( self::OPTION_PREFIX . 'include_file_list', true );
    }

    /**
     * Set whether to include file lists in notifications
     *
     * @param bool $include Whether to include file lists
     * @return bool True on success
     */
    public function setIncludeFileList( bool $include ): bool {
        update_option( self::OPTION_PREFIX . 'include_file_list', $include );
        return true;
    }

    /**
     * Check if auto-scheduling is enabled
     *
     * @return bool True if auto-scheduling is enabled, false otherwise
     */
    public function isAutoScheduleEnabled(): bool {
        return (bool) get_option( self::OPTION_PREFIX . 'auto_schedule', true );
    }

    /**
     * Set auto-schedule status
     *
     * @param bool $enabled Whether auto-scheduling is enabled
     * @return bool True on success, false on failure
     */
    public function setAutoScheduleEnabled( bool $enabled ): bool {
        update_option( self::OPTION_PREFIX . 'auto_schedule', $enabled );
        return true;
    }

    /**
     * Get retention period for old scans
     *
     * @return int Number of days to keep old scans
     */
    public function getRetentionPeriod(): int {
        return (int) get_option( self::OPTION_PREFIX . 'retention_period', 90 );
    }

    /**
     * Set retention period for old scans
     *
     * @param int $days Number of days to keep old scans
     * @return bool True on success, false on failure
     */
    public function setRetentionPeriod( int $days ): bool {
        if ( $days < 1 || $days > 365 ) {
            return false;
        }

        update_option( self::OPTION_PREFIX . 'retention_period', $days );
        return true;
    }

    /**
     * Get content retention limit
     *
     * @return int Number of file content entries to retain
     */
    public function getContentRetentionLimit(): int {
        return (int) get_option( self::OPTION_PREFIX . 'content_retention_limit', 50000 );
    }

    /**
     * Set content retention limit
     *
     * @param int $limit Number of entries to retain
     * @return bool True on success
     */
    public function setContentRetentionLimit( int $limit ): bool {
        // Minimum of 1000, maximum of 500000
        if ( $limit < 1000 || $limit > 500000 ) {
            return false;
        }
        
        update_option( self::OPTION_PREFIX . 'content_retention_limit', $limit );
        return true;
    }

    /**
     * Get log retention period in days
     *
     * @return int Number of days to keep logs
     */
    public function getLogRetentionDays(): int {
        return (int) get_option( self::OPTION_PREFIX . 'log_retention_days', 30 );
    }

    /**
     * Set log retention period
     *
     * @param int $days Number of days to keep logs
     * @return bool True on success, false on failure
     */
    public function setLogRetentionDays( int $days ): bool {
        if ( $days < 1 || $days > 365 ) {
            return false;
        }
        
        update_option( self::OPTION_PREFIX . 'log_retention_days', $days );
        return true;
    }

    /**
     * Get enabled log levels
     *
     * @return array Array of enabled log levels
     */
    public function getEnabledLogLevels(): array {
        return get_option( 
            self::OPTION_PREFIX . 'log_levels',
            [ 'success', 'error', 'warning', 'info' ]
        );
    }

    /**
     * Set enabled log levels
     *
     * @param array $levels Array of log levels to enable
     * @return bool True on success
     */
    public function setEnabledLogLevels( array $levels ): bool {
        $valid_levels = [ 'success', 'error', 'warning', 'info', 'debug' ];
        $levels = array_intersect( $levels, $valid_levels );
        
        update_option( self::OPTION_PREFIX . 'log_levels', $levels );
        return true;
    }

    /**
     * Check if debug mode is enabled
     *
     * @return bool
     */
    public function isDebugModeEnabled(): bool {
        return (bool) get_option( self::OPTION_PREFIX . 'debug_mode', false );
    }

    /**
     * Set debug mode
     *
     * @param bool $enabled Whether debug mode is enabled
     * @return bool True on success
     */
    public function setDebugMode( bool $enabled ): bool {
        update_option( self::OPTION_PREFIX . 'debug_mode', $enabled );
        return true;
    }

    /**
     * Check if auto log cleanup is enabled
     *
     * @return bool
     */
    public function isAutoLogCleanupEnabled(): bool {
        return (bool) get_option( self::OPTION_PREFIX . 'auto_log_cleanup', true );
    }

    /**
     * Set auto log cleanup
     *
     * @param bool $enabled Whether auto cleanup is enabled
     * @return bool True on success
     */
    public function setAutoLogCleanup( bool $enabled ): bool {
        update_option( self::OPTION_PREFIX . 'auto_log_cleanup', $enabled );
        return true;
    }

    /**
     * Get all settings as an array
     *
     * @return array All plugin settings
     */
    public function getAllSettings(): array {
        return [
            'scan_types' => $this->getScanFileTypes(),
            'scan_interval' => $this->getScanInterval(),
            'exclude_patterns' => $this->getExcludePatterns(),
            'max_file_size' => $this->getMaxFileSize(),
            'notification_enabled' => $this->isNotificationEnabled(),
            'notification_email' => $this->getNotificationEmail(),
            'slack_enabled' => $this->isSlackEnabled(),
            'slack_webhook_url' => $this->getSlackWebhookUrl(),
            'auto_schedule' => $this->isAutoScheduleEnabled(),
            'retention_period' => $this->getRetentionPeriod(),
            'content_retention_limit' => $this->getContentRetentionLimit(),
            'log_levels' => $this->getEnabledLogLevels(),
            'log_retention_days' => $this->getLogRetentionDays(),
            'auto_log_cleanup' => $this->isAutoLogCleanupEnabled(),
            'debug_mode' => $this->isDebugModeEnabled(),
        ];
    }

    /**
     * Update multiple settings at once
     *
     * @param array $settings Settings array
     * @return array Array of success/failure for each setting
     */
    public function updateSettings( array $settings ): array {
        $results = [];

        foreach ( $settings as $key => $value ) {
            switch ( $key ) {
                case 'scan_types':
                    $results[ $key ] = $this->setScanFileTypes( $value );
                    break;
                case 'scan_interval':
                    $results[ $key ] = $this->setScanInterval( $value );
                    break;
                case 'exclude_patterns':
                    $results[ $key ] = $this->setExcludePatterns( $value );
                    break;
                case 'max_file_size':
                    $results[ $key ] = $this->setMaxFileSize( $value );
                    break;
                case 'notification_enabled':
                    $results[ $key ] = $this->setNotificationEnabled( $value );
                    break;
                case 'notification_email':
                    $results[ $key ] = $this->setNotificationEmail( $value );
                    break;
                case 'slack_enabled':
                    $results[ $key ] = $this->setSlackEnabled( $value );
                    break;
                case 'slack_webhook_url':
                    $results[ $key ] = $this->setSlackWebhookUrl( $value );
                    break;
                case 'auto_schedule':
                    $results[ $key ] = $this->setAutoScheduleEnabled( $value );
                    break;
                case 'retention_period':
                    $results[ $key ] = $this->setRetentionPeriod( $value );
                    break;
                case 'content_retention_limit':
                    $results[ $key ] = $this->setContentRetentionLimit( $value );
                    break;
                case 'log_levels':
                    $results[ $key ] = $this->setEnabledLogLevels( $value );
                    break;
                case 'log_retention_days':
                    $results[ $key ] = $this->setLogRetentionDays( $value );
                    break;
                case 'auto_log_cleanup':
                    $results[ $key ] = $this->setAutoLogCleanup( $value );
                    break;
                case 'debug_mode':
                    $results[ $key ] = $this->setDebugMode( $value );
                    break;
                default:
                    $results[ $key ] = false;
            }
        }

        return $results;
    }

    /**
     * Reset settings to defaults
     *
     * @return bool True on success, false on failure
     */
    public function resetToDefaults(): bool {
        $options = [
            'scan_types',
            'scan_interval',
            'exclude_patterns',
            'max_file_size',
            'notification_enabled',
            'notification_email',
            'auto_schedule',
            'retention_period',
            'content_retention_limit',
            'log_levels',
            'log_retention_days',
            'auto_log_cleanup',
            'debug_mode',
        ];

        foreach ( $options as $option ) {
            delete_option( self::OPTION_PREFIX . $option );
        }

        return true;
    }
}