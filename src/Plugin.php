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
use EightyFourEM\FileIntegrityChecker\Database\LogRepository;
use EightyFourEM\FileIntegrityChecker\Database\ChecksumCacheRepository;
use EightyFourEM\FileIntegrityChecker\Database\PriorityRulesRepository;
use EightyFourEM\FileIntegrityChecker\Database\VelocityLogRepository;
use EightyFourEM\FileIntegrityChecker\Scanner\FileScanner;
use EightyFourEM\FileIntegrityChecker\Scanner\ChecksumGenerator;
use EightyFourEM\FileIntegrityChecker\Services\SchedulerService;
use EightyFourEM\FileIntegrityChecker\Services\SettingsService;
use EightyFourEM\FileIntegrityChecker\Services\IntegrityService;
use EightyFourEM\FileIntegrityChecker\Services\LoggerService;
use EightyFourEM\FileIntegrityChecker\Services\ScheduledCacheCleanup;
use EightyFourEM\FileIntegrityChecker\Services\NotificationService;
use EightyFourEM\FileIntegrityChecker\Services\EncryptionService;
use EightyFourEM\FileIntegrityChecker\Services\PriorityMatchingService;
use EightyFourEM\FileIntegrityChecker\Admin\AdminPages;
use EightyFourEM\FileIntegrityChecker\Admin\DashboardWidget;
use EightyFourEM\FileIntegrityChecker\Admin\PluginLinks;
use EightyFourEM\FileIntegrityChecker\Admin\PriorityRulesPage;
use EightyFourEM\FileIntegrityChecker\CLI\IntegrityCommand;
use EightyFourEM\FileIntegrityChecker\CLI\PriorityRulesCommand;
use EightyFourEM\FileIntegrityChecker\Security\FileAccessSecurity;
use EightyFourEM\FileIntegrityChecker\Utils\DiffGenerator;

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
        // Initialize database manager
        $databaseManager = $this->container->get( DatabaseManager::class );
        $databaseManager->setLoggerService( $this->container->get( LoggerService::class ) );
        $databaseManager->init();

        // Register Action Scheduler hook for async database migrations
        add_action(
            hook_name: 'eightyfourem_file_integrity_run_migrations',
            callback: [ $databaseManager, 'runMigrationsAsync' ]
        );

        // Initialize scheduler service
        $schedulerService = $this->container->get( SchedulerService::class );
        $schedulerService->init();

        // Initialize cache cleanup scheduler
        $cacheCleanup = $this->container->get( ScheduledCacheCleanup::class );
        $cacheCleanup->init();

        // Initialize admin interface
        if ( is_admin() ) {
            $adminPages = $this->container->get( AdminPages::class );
            $adminPages->init();

            $dashboardWidget = $this->container->get( DashboardWidget::class );
            $dashboardWidget->init();

            $pluginLinks = $this->container->get( PluginLinks::class );
            $pluginLinks->init();

            $priorityRulesPage = $this->container->get( PriorityRulesPage::class );
            $priorityRulesPage->init();
        }

        // Register WP-CLI commands if available
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            $integrityCommand = $this->container->get( IntegrityCommand::class );
            \WP_CLI::add_command( '84em integrity', $integrityCommand );

            $priorityRulesCommand = $this->container->get( PriorityRulesCommand::class );
            \WP_CLI::add_command( '84em priority-rules', $priorityRulesCommand );
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
                $container->get( DatabaseManager::class ),
                $container->get( SchedulerService::class ),
                $container->get( LoggerService::class )
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

        $this->container->register( LogRepository::class, function () {
            return new LogRepository();
        } );

        $this->container->register( PriorityRulesRepository::class, function () {
            return new PriorityRulesRepository();
        } );

        $this->container->register( VelocityLogRepository::class, function () {
            return new VelocityLogRepository();
        } );

        // Security services
        $this->container->register( FileAccessSecurity::class, function () {
            return new FileAccessSecurity();
        } );

        // Scanner services
        $this->container->register( ChecksumGenerator::class, function ( $container ) {
            return new ChecksumGenerator(
                $container->get( LoggerService::class )
            );
        } );

        $this->container->register( DiffGenerator::class, function () {
            return new DiffGenerator();
        } );

        $this->container->register( PriorityMatchingService::class, function ( $container ) {
            return new PriorityMatchingService(
                $container->get( PriorityRulesRepository::class ),
                $container->get( VelocityLogRepository::class ),
                $container->get( LoggerService::class )
            );
        } );

        $this->container->register( FileScanner::class, function ( $container ) {
            return new FileScanner(
                $container->get( ChecksumGenerator::class ),
                $container->get( SettingsService::class ),
                $container->get( FileAccessSecurity::class ),
                $container->get( ChecksumCacheRepository::class ),
                $container->get( DiffGenerator::class ),
                $container->get( PriorityMatchingService::class )
            );
        } );

        // Core services
        $this->container->register( SettingsService::class, function () {
            return new SettingsService();
        } );

        $this->container->register( LoggerService::class, function ( $container ) {
            return new LoggerService(
                $container->get( LogRepository::class ),
                $container->get( SettingsService::class )
            );
        } );

        // Encryption service
        $this->container->register( EncryptionService::class, function ( $container ) {
            return new EncryptionService(
                $container->get( LoggerService::class )
            );
        } );

        $this->container->register( SchedulerService::class, function ( $container ) {
            return new SchedulerService(
                $container->get( IntegrityService::class ),
                $container->get( ScanSchedulesRepository::class ),
                $container->get( ScanResultsRepository::class ),
                $container->get( LoggerService::class ),
            );
        } );

        $this->container->register( NotificationService::class, function ( $container ) {
            return new NotificationService(
                $container->get( SettingsService::class ),
                $container->get( ScanResultsRepository::class ),
                $container->get( FileRecordRepository::class ),
                $container->get( LoggerService::class )
            );
        } );

        $this->container->register( IntegrityService::class, function ( $container ) {
            return new IntegrityService(
                $container->get( FileScanner::class ),
                $container->get( ScanResultsRepository::class ),
                $container->get( FileRecordRepository::class ),
                $container->get( SettingsService::class ),
                $container->get( LoggerService::class ),
                $container->get( NotificationService::class )
            );
        } );

        // Cache services
        $this->container->register( ChecksumCacheRepository::class, function ( $container ) {
            return new ChecksumCacheRepository(
                $container->get( EncryptionService::class )
            );
        } );

        $this->container->register( ScheduledCacheCleanup::class, function ( $container ) {
            return new ScheduledCacheCleanup(
                $container->get( ChecksumCacheRepository::class ),
                $container->get( ScanResultsRepository::class ),
                $container->get( LogRepository::class ),
                $container->get( LoggerService::class ),
                $container->get( SettingsService::class )
            );
        } );

        // Admin services
        $this->container->register( AdminPages::class, function ( $container ) {
            return new AdminPages(
                $container->get( IntegrityService::class ),
                $container->get( SettingsService::class ),
                $container->get( SchedulerService::class ),
                $container->get( ScanResultsRepository::class ),
                $container->get( LoggerService::class ),
                $container->get( NotificationService::class )
            );
        } );

        $this->container->register( DashboardWidget::class, function ( $container ) {
            return new DashboardWidget(
                $container->get( IntegrityService::class ),
                $container->get( ScanResultsRepository::class )
            );
        } );

        $this->container->register( PluginLinks::class, function () {
            return new PluginLinks();
        } );

        $this->container->register( PriorityRulesPage::class, function ( $container ) {
            return new PriorityRulesPage(
                $container->get( PriorityRulesRepository::class ),
                $container->get( LoggerService::class )
            );
        } );

        // CLI services
        $this->container->register( IntegrityCommand::class, function ( $container ) {
            return new IntegrityCommand(
                $container->get( IntegrityService::class ),
                $container->get( SettingsService::class ),
                $container->get( SchedulerService::class ),
                $container->get( ScanResultsRepository::class ),
                $container->get( LogRepository::class )
            );
        } );

        $this->container->register( PriorityRulesCommand::class, function ( $container ) {
            return new PriorityRulesCommand(
                $container->get( PriorityRulesRepository::class ),
                $container->get( VelocityLogRepository::class )
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
