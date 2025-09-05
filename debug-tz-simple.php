<?php
/**
 * Simple timezone debug
 */

echo "=== Timezone Debug ===\n\n";

// System info
echo "System date: " . shell_exec('date');
echo "PHP timezone: " . date_default_timezone_get() . "\n";
echo "PHP time: " . date('Y-m-d H:i:s') . "\n\n";

// Test with different timezone assumptions
$timezones_to_test = [
    'America/Chicago',  // CDT
    'America/New_York', // EDT
    'UTC',
    'America/Los_Angeles' // PDT
];

foreach ($timezones_to_test as $tz_name) {
    echo "=== Testing with timezone: $tz_name ===\n";
    
    $timezone = new DateTimeZone($tz_name);
    $now = new DateTime('now', $timezone);
    echo "Now: " . $now->format('Y-m-d H:i:s T (P)') . "\n";
    
    // Test setting to 13:32
    $next = clone $now;
    $next->setTime(13, 32, 0);
    echo "13:32 in this timezone: " . $next->format('Y-m-d H:i:s T') . "\n";
    
    if ($next <= $now) {
        echo "  -> Would add 1 day (time has passed)\n";
        $next->modify('+1 day');
    } else {
        echo "  -> Today (time hasn't passed yet)\n";
    }
    
    echo "Next run: " . $next->format('Y-m-d H:i:s T') . "\n";
    
    // Calculate difference
    $diff = $now->diff($next);
    $total_hours = $diff->h + ($diff->days * 24);
    echo "Time until: {$total_hours} hours, {$diff->i} minutes\n\n";
}

// The problem might be timezone offset
echo "=== Timezone Offset Test ===\n";
$chicago = new DateTimeZone('America/Chicago');
$utc = new DateTimeZone('UTC');
$now = new DateTime('now');

echo "Chicago offset from UTC: " . ($chicago->getOffset($now) / 3600) . " hours\n";

// Show what happens if we interpret a Chicago time as UTC
$chicago_time = new DateTime('2025-09-05 13:32:00', $chicago);
echo "13:32 Chicago time: " . $chicago_time->format('Y-m-d H:i:s T') . "\n";
echo "Same moment in UTC: " . $chicago_time->setTimezone($utc)->format('Y-m-d H:i:s T') . "\n\n";

// And vice versa
$utc_time = new DateTime('2025-09-05 13:32:00', $utc);
echo "13:32 UTC time: " . $utc_time->format('Y-m-d H:i:s T') . "\n";
echo "Same moment in Chicago: " . $utc_time->setTimezone($chicago)->format('Y-m-d H:i:s T') . "\n";