<?php
/**
 * Scheduled Cache Cleanup Service
 *
 * @package EightyFourEM\FileIntegrityChecker\Services
 */

namespace EightyFourEM\FileIntegrityChecker\Services;

use EightyFourEM\FileIntegrityChecker\Database\ChecksumCacheRepository;
use EightyFourEM\FileIntegrityChecker\Database\ScanResultsRepository;
use EightyFourEM\FileIntegrityChecker\Database\LogRepository;
use EightyFourEM\FileIntegrityChecker\Database\FileRecordRepository;

/**
 * Handles scheduled cleanup of expired cache entries using Action Scheduler
 */
class ScheduledCacheCleanup {
    /**
     * Action hook name for cache cleanup
     */
    public const CLEANUP_ACTION = 'eightyfourem_file_integrity_cleanup_cache';

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
     * Scan results repository
     *
     * @var ScanResultsRepository
     */
    private ScanResultsRepository $scanResultsRepository;

    /**
     * Log repository
     *
     * @var LogRepository
     */
    private LogRepository $logRepository;

    /**
     * File record repository
     *
     * @var FileRecordRepository
     */
    private FileRecordRepository $fileRecordRepository;

    /**
     * Logger service
     *
     * @var LoggerService
     */
    private LoggerService $logger;

    /**
     * Settings service
     *
     * @var SettingsService
     */
    private SettingsService $settings;

    /**
     * Constructor
     *
     * @param ChecksumCacheRepository $cacheRepository Cache repository
     * @param ScanResultsRepository $scanResultsRepository Scan results repository
     * @param LogRepository $logRepository Log repository
     * @param FileRecordRepository $fileRecordRepository File record repository
     * @param LoggerService $logger Logger service
     * @param SettingsService $settings Settings service
     */
    public function __construct(
        ChecksumCacheRepository $cacheRepository,
        ScanResultsRepository $scanResultsRepository,
        LogRepository $logRepository,
        FileRecordRepository $fileRecordRepository,
        LoggerService $logger,
        SettingsService $settings
    ) {
        $this->cacheRepository = $cacheRepository;
        $this->scanResultsRepository = $scanResultsRepository;
        $this->logRepository = $logRepository;
        $this->fileRecordRepository = $fileRecordRepository;
        $this->logger = $logger;
        $this->settings = $settings;
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
            // Get retention settings
            $tier2_days = $this->settings->getRetentionTier2Days();
            $tier3_days = $this->settings->getRetentionTier3Days();
            $keep_baseline = $this->settings->shouldKeepBaseline();

            // Get statistics before cleanup
            $stats_before = $this->cacheRepository->getStatistics();

            // Clean up expired cache entries
            $deleted_count = $this->cacheRepository->cleanupExpired();

            // Run tiered retention cleanup on scans
            $scan_stats = $this->scanResultsRepository->deleteOldScansWithTiers(
                tier2_days: $tier2_days,
                tier3_days: $tier3_days,
                keep_baseline: $keep_baseline
            );

            // Run tiered retention cleanup on logs
            $log_stats = $this->logRepository->deleteOldWithTiers(
                tier2_days: $tier2_days,
                tier3_days: $tier3_days
            );

            // CRITICAL: Aggressive file_records cleanup to prevent bloat
            // Delete file_records for scans older than tier2_days, preserving baseline and critical
            $protected_ids = [];
            if ( $keep_baseline ) {
                $baseline_id = $this->scanResultsRepository->getBaselineScanId();
                if ( $baseline_id ) {
                    $protected_ids[] = $baseline_id;
                }
            }

            $critical_scan_ids = $this->scanResultsRepository->getScansWithCriticalFiles();
            $protected_ids = array_merge( $protected_ids, $critical_scan_ids );
            $protected_ids = array_unique( $protected_ids );

            $file_records_deleted = $this->fileRecordRepository->deleteFileRecordsForOldScans(
                days_old: $tier2_days,
                protected_ids: $protected_ids
            );

            // Get statistics after cleanup
            $stats_after = $this->cacheRepository->getStatistics();
            $file_record_stats = $this->fileRecordRepository->getTableStatistics();

            // Log cleanup results
            $this->logger->info(
                sprintf(
                    'Tiered retention cleanup completed: %d cache entries removed, %d scans deleted, %d diffs removed, %d file_records deleted, %d logs deleted',
                    $deleted_count,
                    $scan_stats['scans_deleted'],
                    $scan_stats['tier3_diff_removed'],
                    $file_records_deleted,
                    $log_stats['total_deleted']
                ),
                'cache_cleanup',
                [
                    'cache_deleted_entries' => $deleted_count,
                    'cache_size_before' => $stats_before['total_size'],
                    'cache_size_after' => $stats_after['total_size'],
                    'cache_size_freed' => $stats_before['total_size'] - $stats_after['total_size'],
                    'cache_entries_before' => $stats_before['total_entries'],
                    'cache_entries_after' => $stats_after['total_entries'],
                    'scans_deleted' => $scan_stats['scans_deleted'],
                    'diffs_removed' => $scan_stats['tier3_diff_removed'],
                    'file_records_deleted' => $file_records_deleted,
                    'file_records_total_rows' => $file_record_stats['total_rows'],
                    'file_records_table_size_mb' => $file_record_stats['total_size_mb'],
                    'logs_tier2_deleted' => $log_stats['tier2_deleted'],
                    'logs_tier3_deleted' => $log_stats['tier3_deleted'],
                    'logs_total_deleted' => $log_stats['total_deleted'],
                ]
            );

            // If cache is getting too large, perform aggressive cleanup
            if ( $stats_after['total_entries'] > 10000 ) {
                $this->performAggressiveCleanup();
            }

        } catch ( \Exception $e ) {
            $this->logger->error(
                'Tiered retention cleanup failed: ' . $e->getMessage(),
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
