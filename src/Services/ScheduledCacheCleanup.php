<?php
/**
 * Scheduled Cache Cleanup Service
 *
 * @package EightyFourEM\FileIntegrityChecker\Services
 */

namespace EightyFourEM\FileIntegrityChecker\Services;

use EightyFourEM\FileIntegrityChecker\Database\ChecksumCacheRepository;

/**
 * Handles scheduled cleanup of expired cache entries using Action Scheduler
 */
class ScheduledCacheCleanup {
    /**
     * Action hook name for cache cleanup
     */
    public const CLEANUP_ACTION = 'file_integrity_cleanup_cache';

    /**
     * Cleanup interval in seconds (6 hours)
     */
    private const CLEANUP_INTERVAL = 6 * HOUR_IN_SECONDS;

    /**
     * Checksum cache repository
     *
     * @var ChecksumCacheRepository
     */
    private ChecksumCacheRepository $cacheRepository;

    /**
     * Logger service
     *
     * @var LoggerService
     */
    private LoggerService $logger;

    /**
     * Constructor
     *
     * @param ChecksumCacheRepository $cacheRepository Cache repository
     * @param LoggerService $logger Logger service
     */
    public function __construct( ChecksumCacheRepository $cacheRepository, LoggerService $logger ) {
        $this->cacheRepository = $cacheRepository;
        $this->logger = $logger;
    }

    /**
     * Initialize scheduled cleanup
     */
    public function init(): void {
        // Register the cleanup action handler
        add_action( self::CLEANUP_ACTION, [ $this, 'runCleanup' ] );

        // Schedule the recurring cleanup if not already scheduled
        $this->scheduleCleanup();
    }

    /**
     * Schedule recurring cleanup task
     */
    public function scheduleCleanup(): void {
        if ( ! function_exists( 'as_has_scheduled_action' ) ) {
            // Action Scheduler not available
            return;
        }

        // Wait for Action Scheduler to be fully initialized
        add_action( 'action_scheduler_init', function() {
            // Check if cleanup is already scheduled
            if ( ! as_has_scheduled_action( self::CLEANUP_ACTION ) ) {
                // Schedule recurring cleanup every 6 hours
                as_schedule_recurring_action(
                    time() + HOUR_IN_SECONDS,
                    self::CLEANUP_INTERVAL,
                    self::CLEANUP_ACTION,
                    [],
                    'file-integrity-checker'
                );

                $this->logger->info(
                    'Scheduled cache cleanup task registered',
                    'cache_cleanup'
                );
            }
        } );
    }

    /**
     * Run cache cleanup
     * This method is called by Action Scheduler
     */
    public function runCleanup(): void {
        try {
            // Get statistics before cleanup
            $stats_before = $this->cacheRepository->getStatistics();

            // Clean up expired entries
            $deleted_count = $this->cacheRepository->cleanupExpired();

            // Get statistics after cleanup
            $stats_after = $this->cacheRepository->getStatistics();

            // Log cleanup results
            $this->logger->info(
                sprintf(
                    'Cache cleanup completed: %d expired entries removed, %d entries remaining',
                    $deleted_count,
                    $stats_after['total_entries']
                ),
                'cache_cleanup',
                [
                    'deleted_entries' => $deleted_count,
                    'size_before' => $stats_before['total_size'],
                    'size_after' => $stats_after['total_size'],
                    'size_freed' => $stats_before['total_size'] - $stats_after['total_size'],
                    'entries_before' => $stats_before['total_entries'],
                    'entries_after' => $stats_after['total_entries'],
                ]
            );

            // If cache is getting too large, perform aggressive cleanup
            if ( $stats_after['total_entries'] > 10000 ) {
                $this->performAggressiveCleanup();
            }

        } catch ( \Exception $e ) {
            $this->logger->error(
                'Cache cleanup failed: ' . $e->getMessage(),
                'cache_cleanup',
                [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );
        }
    }

    /**
     * Perform aggressive cleanup when cache is too large
     */
    private function performAggressiveCleanup(): void {
        // Clear entries older than 24 hours
        global $wpdb;
        $table = $wpdb->prefix . 'eightyfourem_integrity_checksum_cache';

        $cutoff_time = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM $table WHERE created_at < %s",
                $cutoff_time
            )
        );

        if ( $deleted > 0 ) {
            $this->logger->warning(
                sprintf( 'Aggressive cleanup performed: %d old entries removed', $deleted ),
                'cache_cleanup',
                [ 'cutoff_time' => $cutoff_time ]
            );
        }
    }

    /**
     * Unschedule cleanup task
     * Called during plugin deactivation
     */
    public function unschedule(): void {
        if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
            return;
        }

        as_unschedule_all_actions( self::CLEANUP_ACTION );

        $this->logger->info(
            'Cache cleanup task unscheduled',
            'cache_cleanup'
        );
    }

    /**
     * Run immediate cleanup
     * Can be triggered manually from admin interface
     *
     * @return array Cleanup results
     */
    public function runImmediateCleanup(): array {
        $stats_before = $this->cacheRepository->getStatistics();
        $deleted_count = $this->cacheRepository->cleanupExpired();
        $stats_after = $this->cacheRepository->getStatistics();

        return [
            'deleted_entries' => $deleted_count,
            'size_freed' => $stats_before['total_size'] - $stats_after['total_size'],
            'entries_remaining' => $stats_after['total_entries'],
            'size_remaining' => $stats_after['total_size'],
        ];
    }

    /**
     * Get next scheduled cleanup time
     *
     * @return int|null Unix timestamp or null if not scheduled
     */
    public function getNextCleanupTime(): ?int {
        if ( ! function_exists( 'as_next_scheduled_action' ) ) {
            return null;
        }

        $next = as_next_scheduled_action( self::CLEANUP_ACTION );

        return $next !== false ? $next : null;
    }
}