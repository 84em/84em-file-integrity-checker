<?php
/**
 * Test file to verify schedule improvements
 * Run this file with: wp eval-file test-schedule-improvements.php
 */

// Test the schedule repository changes
use EightyFourEM\FileIntegrityChecker\Database\DatabaseManager;
use EightyFourEM\FileIntegrityChecker\Database\ScanSchedulesRepository;

require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';

$db_manager = new DatabaseManager();
$repo = new ScanSchedulesRepository( $db_manager );

echo "Testing Schedule Improvements\n";
echo "==============================\n\n";

// Test 1: Hourly schedule with minute
echo "Test 1: Creating hourly schedule at minute 30...\n";
$hourly_config = [
    'name' => 'Test Hourly',
    'frequency' => 'hourly',
    'minute' => 30,
    'is_active' => 1,
];
$hourly_id = $repo->create( $hourly_config );
if ( $hourly_id ) {
    $schedule = $repo->get( $hourly_id );
    echo "✓ Created hourly schedule: runs at :{$schedule->minute} of every hour\n";
    echo "  Next run: {$schedule->next_run}\n";
    $repo->delete( $hourly_id );
} else {
    echo "✗ Failed to create hourly schedule\n";
}

echo "\n";

// Test 2: Weekly schedule with multiple days
echo "Test 2: Creating weekly schedule for Mon, Wed, Fri...\n";
$weekly_config = [
    'name' => 'Test Weekly Multi-Day',
    'frequency' => 'weekly',
    'days_of_week' => [ 1, 3, 5 ], // Monday, Wednesday, Friday
    'time' => '14:00',
    'is_active' => 1,
];
$weekly_id = $repo->create( $weekly_config );
if ( $weekly_id ) {
    $schedule = $repo->get( $weekly_id );
    $days = json_decode( $schedule->days_of_week, true );
    $day_names = [ 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' ];
    $selected_days = array_map( function( $d ) use ( $day_names ) {
        return $day_names[$d];
    }, $days );
    echo "✓ Created weekly schedule: runs on " . implode( ', ', $selected_days ) . " at {$schedule->time}\n";
    echo "  Next run: {$schedule->next_run}\n";
    $repo->delete( $weekly_id );
} else {
    echo "✗ Failed to create weekly schedule\n";
}

echo "\n";

// Test 3: Daily schedule (unchanged)
echo "Test 3: Creating daily schedule at 03:00...\n";
$daily_config = [
    'name' => 'Test Daily',
    'frequency' => 'daily',
    'time' => '03:00',
    'is_active' => 1,
];
$daily_id = $repo->create( $daily_config );
if ( $daily_id ) {
    $schedule = $repo->get( $daily_id );
    echo "✓ Created daily schedule: runs every day at {$schedule->time}\n";
    echo "  Next run: {$schedule->next_run}\n";
    $repo->delete( $daily_id );
} else {
    echo "✗ Failed to create daily schedule\n";
}

echo "\n";

// Test 4: Verify monthly is removed
echo "Test 4: Attempting to create monthly schedule (should fail)...\n";
$monthly_config = [
    'name' => 'Test Monthly',
    'frequency' => 'monthly',
    'day_of_month' => 15,
    'time' => '12:00',
    'is_active' => 1,
];

// Try through the service which validates frequencies
$services = new \EightyFourEM\FileIntegrityChecker\Services\SchedulerService(
    new \EightyFourEM\FileIntegrityChecker\Services\IntegrityService(
        new \EightyFourEM\FileIntegrityChecker\Database\FileRecordsRepository( $db_manager ),
        new \EightyFourEM\FileIntegrityChecker\Database\ScanResultsRepository( $db_manager ),
        new \EightyFourEM\FileIntegrityChecker\Scanner\FileScanner(
            new \EightyFourEM\FileIntegrityChecker\Services\SettingsService()
        ),
        new \EightyFourEM\FileIntegrityChecker\Services\LoggerService( $db_manager )
    ),
    $repo,
    new \EightyFourEM\FileIntegrityChecker\Services\LoggerService( $db_manager ),
    new \EightyFourEM\FileIntegrityChecker\Services\NotificationService(
        new \EightyFourEM\FileIntegrityChecker\Services\SettingsService(),
        new \EightyFourEM\FileIntegrityChecker\Database\ScanResultsRepository( $db_manager ),
        new \EightyFourEM\FileIntegrityChecker\Database\FileRecordsRepository( $db_manager ),
        new \EightyFourEM\FileIntegrityChecker\Services\LoggerService( $db_manager )
    )
);

$monthly_id = $services->createSchedule( $monthly_config );
if ( ! $monthly_id ) {
    echo "✓ Monthly schedule correctly rejected (not in valid frequencies)\n";
} else {
    echo "✗ Monthly schedule was created (should have been rejected)\n";
    $repo->delete( $monthly_id );
}

echo "\nAll tests completed!\n";