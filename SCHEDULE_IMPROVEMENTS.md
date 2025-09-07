# Schedule Improvements - 84em File Integrity Checker

## Changes Implemented

### 1. Hourly Scans - Minute Selection
- **Added**: Minute selector (0-59) for hourly scans
- **UI**: Shows minute input field when "Hourly" is selected
- **Behavior**: Scans run every hour at the specified minute (e.g., every hour at :15)

### 2. Weekly Scans - Multiple Days Selection
- **Changed**: Single day dropdown to multiple day checkboxes
- **UI**: Shows checkboxes for all 7 days of the week when "Weekly" is selected
- **Behavior**: Scans run on all selected days at the specified time
- **Display**: Shows "Every Monday, Wednesday, Friday at 14:00" format

### 3. Monthly Option Removed
- **Removed**: Monthly frequency option from all dropdowns
- **Cleaned**: Database and backend code still supports it for backward compatibility
- **Validation**: SchedulerService now rejects monthly frequency

### 4. Database Changes
- **Modified**: `day_of_week` column from `tinyint(1)` to `varchar(100)` to store JSON array
- **Format**: Weekly schedules store days as JSON array: `[1, 3, 5]` for Mon, Wed, Fri
- **Backward Compatible**: Single day values are converted to array format

## Files Modified

### Frontend/UI
- `views/admin/schedules.php` - Updated forms and JavaScript for new UI
- Forms now have minute input for hourly, checkboxes for weekly days
- JavaScript handles dynamic show/hide of relevant fields

### Backend/PHP
- `src/Database/DatabaseManager.php` - Updated schema for day_of_week column
- `src/Database/ScanSchedulesRepository.php` - Added JSON handling for multiple days
- `src/Services/SchedulerService.php` - Removed monthly from valid frequencies

### Key Features
1. **Improved Hourly**: Can now specify exact minute of the hour
2. **Flexible Weekly**: Can select multiple days for weekly scans
3. **Simplified Options**: Removed redundant monthly option
4. **Better UX**: Dynamic form fields based on frequency selection

## Testing

Run the test file with WP-CLI:
```bash
wp eval-file wp-content/plugins/84em-file-integrity-checker/test-schedule-improvements.php
```

This will test:
1. Creating hourly schedule with specific minute
2. Creating weekly schedule with multiple days
3. Daily schedule (unchanged functionality)
4. Verify monthly is properly rejected

## Database Migration

The database schema change is handled automatically by WordPress's `dbDelta()` function when the plugin is activated or updated. The change from `tinyint(1)` to `varchar(100)` for the `day_of_week` column allows storing JSON arrays for multiple days.