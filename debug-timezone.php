<?php
/**
 * Debug timezone issues
 */

// Load WordPress
require_once dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) . '/wp-load.php';

echo "=== Timezone Debug ===\n\n";

// System info
echo "System date: " . shell_exec('date') . "\n";
echo "PHP timezone: " . date_default_timezone_get() . "\n";
echo "PHP time: " . date('Y-m-d H:i:s') . "\n\n";

// WordPress info
echo "WordPress timezone string: " . wp_timezone_string() . "\n";
echo "WordPress timezone offset: " . get_option('gmt_offset') . " hours\n";
echo "WordPress current_time('mysql'): " . current_time('mysql') . "\n";
echo "WordPress current_time('timestamp'): " . current_time('timestamp') . "\n";
echo "WordPress date: " . date('Y-m-d H:i:s', current_time('timestamp')) . "\n\n";

// Test schedule calculation
$test_schedule = [
    'frequency' => 'daily',
    'time' => '13:32',
    'timezone' => wp_timezone_string()
];

echo "Test schedule input:\n";
print_r($test_schedule);
echo "\n";

// Calculate next run using the same logic as the plugin
$timezone = new DateTimeZone( $test_schedule['timezone'] ?? wp_timezone_string() );
$now = new DateTime( 'now', $timezone );
echo "Now in " . $timezone->getName() . ": " . $now->format('Y-m-d H:i:s T') . "\n";

$next = clone $now;
list( $hour, $minute ) = explode( ':', $test_schedule['time'] );
$next->setTime( (int) $hour, (int) $minute, 0 );

echo "Setting time to {$hour}:{$minute} gives: " . $next->format('Y-m-d H:i:s T') . "\n";

if ( $next <= $now ) {
    echo "Time is in the past, adding 1 day\n";
    $next->modify( '+1 day' );
    echo "Next run after adding 1 day: " . $next->format('Y-m-d H:i:s T') . "\n";
} else {
    echo "Time is in the future today\n";
}

echo "\nFinal next_run to be stored: " . $next->format('Y-m-d H:i:s') . "\n";

// Test display
echo "\n=== Display Test ===\n";
$stored_time = $next->format('Y-m-d H:i:s');
echo "Stored time string: " . $stored_time . "\n";

$display_tz = new DateTimeZone( wp_timezone_string() );
$display_now = new DateTime( 'now', $display_tz );
$display_next = new DateTime( $stored_time, $display_tz );

echo "Display now: " . $display_now->format('Y-m-d H:i:s T') . "\n";
echo "Display next: " . $display_next->format('Y-m-d H:i:s T') . "\n";

$diff = $display_now->diff( $display_next );
echo "Difference: " . $diff->format('%h hours, %i minutes') . "\n";