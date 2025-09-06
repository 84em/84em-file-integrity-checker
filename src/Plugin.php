<?php
/**
 * Main Plugin Class
 *
 * @package EightyFourEM\FileIntegrityChecker
 */

namespace EightyFourEM\FileIntegrityChecker;

use EightyFourEM\FileIntegrityChecker\Core\Requirements;
use EightyFourEM\FileIntegrityChecker\Core\Activator;
use EightyFourEM\FileIntegrityChecker\Core\Deactivator;
use EightyFourEM\FileIntegrityChecker\Database\DatabaseManager;
use EightyFourEM\FileIntegrityChecker\Database\ScanResultsRepository;
use EightyFourEM\FileIntegrityChecker\Database\FileRecordRepository;
use EightyFourEM\FileIntegrityChecker\Database\ScanSchedulesRepository;
use EightyFourEM\FileIntegrityChecker\Scanner\FileScanner;
use EightyFourEM\FileIntegrityChecker\Scanner\ChecksumGenerator;
use EightyFourEM\FileIntegrityChecker\Services\SchedulerService;
use EightyFourEM\FileIntegrityChecker\Services\SettingsService;
use EightyFourEM\FileIntegrityChecker\Services\IntegrityService;
use EightyFourEM\FileIntegrityChecker\Services\FileViewerService;
use EightyFourEM\FileIntegrityChecker\Admin\AdminPages;
use EightyFourEM\FileIntegrityChecker\Admin\DashboardWidget;
use EightyFourEM\FileIntegrityChecker\CLI\IntegrityCommand;
use EightyFourEM\FileIntegrityChecker\Security\SecurityHeaders;

/**
 * Main plugin bootstrap class
 */
class Plugin {
    /**
     * Plugin instance
     *
     * @var Plugin|null
     */
    private static ?Plugin $instance = null;

    /**
     * Dependency injection container
     *
     * @var Container
     */
    private Container $container;

    /**
     * Get plugin instance
     *
     * @return Plugin
     */
    public static function getInstance(): Plugin {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->container = new Container();
        $this->registerServices();
    }

    /**
     * Run the plugin
     */
    public function run(): void {
        // Check requirements
        $requirements = $this->container->get( Requirements::class );
        if ( ! $requirements->check() ) {
            return;
        }

        // Initialize plugin components
        $this->initializeComponents();
    }

    /**
     * Initialize plugin components
     */
    private function initializeComponents(): void {
        // Initialize security headers and hardening
        $securityHeaders = new SecurityHeaders();
        $securityHeaders->init();
        
        // Initialize database manager
        $databaseManager = $this->container->get( DatabaseManager::class );
        $databaseManager->init();

        // Initialize scheduler service
        $schedulerService = $this->container->get( SchedulerService::class );
        $schedulerService->init();

        // Initialize admin interface
        if ( is_admin() ) {
            $adminPages = $this->container->get( AdminPages::class );
            $adminPages->init();

            $dashboardWidget = $this->container->get( DashboardWidget::class );
            $dashboardWidget->init();
        }

        // Register WP-CLI commands if available
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            $integrityCommand = $this->container->get( IntegrityCommand::class );
            \WP_CLI::add_command( '84em integrity', $integrityCommand );
        }
    }

    /**
     * Register services in the container
     */
    private function registerServices(): void {
        // Core services
        $this->container->register( Requirements::class, function () {
            return new Requirements();
        } );

        $this->container->register( Activator::class, function ( $container ) {
            return new Activator(
                $container->get( DatabaseManager::class )
            );
        } );

        $this->container->register( Deactivator::class, function ( $container ) {
            return new Deactivator(
                $container->get( SchedulerService::class )
            );
        } );

        // Database services
        $this->container->register( DatabaseManager::class, function () {
            return new DatabaseManager();
        } );

        $this->container->register( ScanResultsRepository::class, function () {
            return new ScanResultsRepository();
        } );

        $this->container->register( FileRecordRepository::class, function () {
            return new FileRecordRepository();
        } );

        $this->container->register( ScanSchedulesRepository::class, function ( $container ) {
            return new ScanSchedulesRepository(
                $container->get( DatabaseManager::class )
            );
        } );

        // Scanner services
        $this->container->register( ChecksumGenerator::class, function () {
            return new ChecksumGenerator();
        } );

        $this->container->register( FileScanner::class, function ( $container ) {
            return new FileScanner(
                $container->get( ChecksumGenerator::class ),
                $container->get( SettingsService::class )
            );
        } );

        // Core services
        $this->container->register( SettingsService::class, function () {
            return new SettingsService();
        } );

        $this->container->register( SchedulerService::class, function ( $container ) {
            return new SchedulerService(
                $container->get( IntegrityService::class ),
                $container->get( ScanSchedulesRepository::class )
            );
        } );

        $this->container->register( IntegrityService::class, function ( $container ) {
            return new IntegrityService(
                $container->get( FileScanner::class ),
                $container->get( ScanResultsRepository::class ),
                $container->get( FileRecordRepository::class ),
                $container->get( SettingsService::class )
            );
        } );

        $this->container->register( FileViewerService::class, function ( $container ) {
            return new FileViewerService(
                $container->get( FileRecordRepository::class )
            );
        } );

        // Admin services
        $this->container->register( AdminPages::class, function ( $container ) {
            return new AdminPages(
                $container->get( IntegrityService::class ),
                $container->get( SettingsService::class ),
                $container->get( SchedulerService::class ),
                $container->get( ScanResultsRepository::class ),
                $container->get( FileViewerService::class )
            );
        } );

        $this->container->register( DashboardWidget::class, function ( $container ) {
            return new DashboardWidget(
                $container->get( IntegrityService::class ),
                $container->get( ScanResultsRepository::class )
            );
        } );

        // CLI services
        $this->container->register( IntegrityCommand::class, function ( $container ) {
            return new IntegrityCommand(
                $container->get( IntegrityService::class ),
                $container->get( SettingsService::class ),
                $container->get( SchedulerService::class ),
                $container->get( ScanResultsRepository::class )
            );
        } );
    }

    /**
     * Get the container
     *
     * @return Container
     */
    public function getContainer(): Container {
        return $this->container;
    }

    /**
     * Handle activation
     */
    public static function activate(): void {
        $instance  = self::getInstance();
        $activator = $instance->getContainer()->get( Activator::class );
        $activator->activate();
        
        // Apply security hardening measures
        SecurityHeaders::applyHardening();
    }

    /**
     * Handle deactivation
     */
    public static function deactivate(): void {
        $instance    = self::getInstance();
        $deactivator = $instance->getContainer()->get( Deactivator::class );
        $deactivator->deactivate();
    }
}