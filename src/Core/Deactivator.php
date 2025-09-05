<?php
/**
 * Plugin Deactivator
 *
 * @package EightyFourEM\FileIntegrityChecker\Core
 */

namespace EightyFourEM\FileIntegrityChecker\Core;

use EightyFourEM\FileIntegrityChecker\Services\SchedulerService;

/**
 * Handles plugin deactivation
 */
class Deactivator {
    /**
     * Scheduler service
     *
     * @var SchedulerService
     */
    private SchedulerService $schedulerService;

    /**
     * Constructor
     *
     * @param SchedulerService $schedulerService Scheduler service
     */
    public function __construct( SchedulerService $schedulerService ) {
        $this->schedulerService = $schedulerService;
    }

    /**
     * Deactivate the plugin
     */
    public function deactivate(): void {
        // Cancel all scheduled scans if Action Scheduler is available
        // TODO: Implement cancelAllScans() method in SchedulerService
        // if ( $this->schedulerService->isAvailable() ) {
        //     $this->schedulerService->cancelAllScans();
        // }

        // Note: We don't delete database tables or options on deactivation
        // as users might want to reactivate the plugin later
        // Tables and options can be cleaned up manually if needed
    }
}