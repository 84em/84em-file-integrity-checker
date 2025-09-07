<?php
/**
 * Admin schedules page template
 *
 * @package EightyFourEM\FileIntegrityChecker
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Get schedules from the service
$schedules = $scheduler_service->getSchedules();
$stats = $scheduler_service->getScheduleStats();

// Handle form submissions
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['action'] ) ) {
    if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'file_integrity_schedule_action' ) ) {
        wp_die( 'Security check failed' );
    }

    $action = sanitize_text_field( $_POST['action'] );
    
    switch ( $action ) {
        case 'create_schedule':
            $config = [
                'name' => sanitize_text_field( $_POST['schedule_name'] ?? '' ),
                'frequency' => sanitize_text_field( $_POST['frequency'] ?? 'daily' ),
                'time' => sanitize_text_field( $_POST['time'] ?? '02:00' ),
                'minute' => isset( $_POST['minute'] ) ? absint( $_POST['minute'] ) : null,
                'days_of_week' => isset( $_POST['days_of_week'] ) ? array_map( 'absint', $_POST['days_of_week'] ) : null,
                'is_active' => ! empty( $_POST['is_active'] ) ? 1 : 0,
            ];
            
            $schedule_id = $scheduler_service->createSchedule( $config );
            if ( $schedule_id ) {
                echo '<div class="notice notice-success"><p>Schedule created successfully!</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Failed to create schedule.</p></div>';
            }
            
            // Refresh schedules list
            $schedules = $scheduler_service->getSchedules();
            break;
            
        case 'delete_schedule':
            $schedule_id = absint( $_POST['schedule_id'] ?? 0 );
            if ( $schedule_id && $scheduler_service->deleteSchedule( $schedule_id ) ) {
                echo '<div class="notice notice-success"><p>Schedule deleted successfully!</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Failed to delete schedule.</p></div>';
            }
            
            // Refresh schedules list
            $schedules = $scheduler_service->getSchedules();
            break;
            
        case 'toggle_schedule':
            $schedule_id = absint( $_POST['schedule_id'] ?? 0 );
            $enable = ! empty( $_POST['enable'] );
            
            if ( $schedule_id ) {
                if ( $enable ) {
                    $result = $scheduler_service->enableSchedule( $schedule_id );
                } else {
                    $result = $scheduler_service->disableSchedule( $schedule_id );
                }
                
                if ( $result ) {
                    echo '<div class="notice notice-success"><p>Schedule updated successfully!</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>Failed to update schedule.</p></div>';
                }
            }
            
            // Refresh schedules list
            $schedules = $scheduler_service->getSchedules();
            break;
            
        case 'update_schedule':
            $schedule_id = absint( $_POST['schedule_id'] ?? 0 );
            if ( $schedule_id ) {
                $config = [
                    'name' => sanitize_text_field( $_POST['schedule_name'] ?? '' ),
                    'frequency' => sanitize_text_field( $_POST['frequency'] ?? 'daily' ),
                    'time' => sanitize_text_field( $_POST['time'] ?? '02:00' ),
                    'minute' => isset( $_POST['minute'] ) ? absint( $_POST['minute'] ) : null,
                    'days_of_week' => isset( $_POST['days_of_week'] ) ? array_map( 'absint', $_POST['days_of_week'] ) : null,
                    'is_active' => ! empty( $_POST['is_active'] ) ? 1 : 0,
                ];
                
                if ( $scheduler_service->updateSchedule( $schedule_id, $config ) ) {
                    echo '<div class="notice notice-success"><p>Schedule updated successfully!</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>Failed to update schedule.</p></div>';
                }
            }
            
            // Refresh schedules list
            $schedules = $scheduler_service->getSchedules();
            break;
    }
}
?>

<div class="wrap">
    <h1>Scan Schedules</h1>
    
    <?php if ( ! $scheduler_service->isAvailable() ) : ?>
        <div class="file-integrity-alert alert-warning">
            <span class="dashicons dashicons-warning"></span>
            <span>Action Scheduler is not available. Please install and activate a plugin that provides Action Scheduler (such as WooCommerce) to use scheduling features.</span>
        </div>
    <?php endif; ?>

    <!-- Schedule Statistics -->
    <div class="file-integrity-dashboard">
        <div class="file-integrity-card">
            <h3>Schedule Overview</h3>
            <div class="stats-grid">
                <div class="stat-item">
                    <span class="stat-number"><?php echo esc_html( $stats['total'] ); ?></span>
                    <span class="stat-label">Total Schedules</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number success"><?php echo esc_html( $stats['active'] ); ?></span>
                    <span class="stat-label">Active</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number warning"><?php echo esc_html( $stats['inactive'] ); ?></span>
                    <span class="stat-label">Inactive</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Create New Schedule Form -->
    <div class="file-integrity-card">
        <h3>Create New Schedule</h3>
        <form method="post" class="schedule-form">
            <?php wp_nonce_field( 'file_integrity_schedule_action' ); ?>
            <input type="hidden" name="action" value="create_schedule">
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="schedule_name">Schedule Name</label>
                    </th>
                    <td>
                        <input type="text" id="schedule_name" name="schedule_name" class="regular-text" required 
                               placeholder="e.g., Daily Security Scan">
                        <p class="description">A descriptive name for this schedule</p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="frequency">Frequency</label>
                    </th>
                    <td>
                        <select id="frequency" name="frequency" required>
                            <option value="hourly">Hourly</option>
                            <option value="daily" selected>Daily</option>
                            <option value="weekly">Weekly</option>
                        </select>
                        <p class="description">How often the scan should run</p>
                    </td>
                </tr>
                
                <tr class="schedule-minute-row" style="display: none;">
                    <th scope="row">
                        <label for="minute">Minute</label>
                    </th>
                    <td>
                        <input type="number" id="minute" name="minute" min="0" max="59" value="0">
                        <p class="description">Run at this minute of every hour (0-59)</p>
                    </td>
                </tr>
                
                <tr class="schedule-time-row">
                    <th scope="row">
                        <label for="time">Time</label>
                    </th>
                    <td>
                        <input type="time" id="time" name="time" value="02:00">
                        <p class="description">Time of day to run the scan (Timezone: <?php echo esc_html( wp_timezone_string() ); ?>)</p>
                    </td>
                </tr>
                
                <tr class="schedule-day-of-week-row" style="display: none;">
                    <th scope="row">
                        <label>Days of Week</label>
                    </th>
                    <td>
                        <fieldset>
                            <label><input type="checkbox" name="days_of_week[]" value="1" checked> Monday</label><br>
                            <label><input type="checkbox" name="days_of_week[]" value="2"> Tuesday</label><br>
                            <label><input type="checkbox" name="days_of_week[]" value="3"> Wednesday</label><br>
                            <label><input type="checkbox" name="days_of_week[]" value="4"> Thursday</label><br>
                            <label><input type="checkbox" name="days_of_week[]" value="5"> Friday</label><br>
                            <label><input type="checkbox" name="days_of_week[]" value="6"> Saturday</label><br>
                            <label><input type="checkbox" name="days_of_week[]" value="0"> Sunday</label>
                        </fieldset>
                        <p class="description">Select the days when the scan should run</p>
                    </td>
                </tr>
                
                
                <tr>
                    <th scope="row">
                        <label for="is_active">Active</label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" id="is_active" name="is_active" value="1" checked>
                            Enable this schedule immediately
                        </label>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <button type="submit" class="button button-primary">
                    <span class="dashicons dashicons-plus-alt"></span>
                    Create Schedule
                </button>
            </p>
        </form>
    </div>

    <!-- Edit Schedule Form (Initially Hidden) -->
    <div class="file-integrity-card" id="edit-schedule-form" style="display: none;">
        <h3>Edit Schedule</h3>
        <form method="post" class="schedule-form">
            <?php wp_nonce_field( 'file_integrity_schedule_action' ); ?>
            <input type="hidden" name="action" value="update_schedule">
            <input type="hidden" id="edit_schedule_id" name="schedule_id" value="">
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="edit_schedule_name">Schedule Name</label>
                    </th>
                    <td>
                        <input type="text" id="edit_schedule_name" name="schedule_name" class="regular-text" required>
                        <p class="description">A descriptive name for this schedule</p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="edit_frequency">Frequency</label>
                    </th>
                    <td>
                        <select id="edit_frequency" name="frequency" required>
                            <option value="hourly">Hourly</option>
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                        </select>
                        <p class="description">How often the scan should run</p>
                    </td>
                </tr>
                
                <tr class="edit-schedule-minute-row" style="display: none;">
                    <th scope="row">
                        <label for="edit_minute">Minute</label>
                    </th>
                    <td>
                        <input type="number" id="edit_minute" name="minute" min="0" max="59">
                        <p class="description">Run at this minute of every hour (0-59)</p>
                    </td>
                </tr>
                
                <tr class="edit-schedule-time-row">
                    <th scope="row">
                        <label for="edit_time">Time</label>
                    </th>
                    <td>
                        <input type="time" id="edit_time" name="time">
                        <p class="description">Time of day to run the scan (Timezone: <?php echo esc_html( wp_timezone_string() ); ?>)</p>
                    </td>
                </tr>
                
                <tr class="edit-schedule-day-of-week-row" style="display: none;">
                    <th scope="row">
                        <label>Days of Week</label>
                    </th>
                    <td>
                        <fieldset>
                            <label><input type="checkbox" name="days_of_week[]" value="1" class="edit-day-checkbox"> Monday</label><br>
                            <label><input type="checkbox" name="days_of_week[]" value="2" class="edit-day-checkbox"> Tuesday</label><br>
                            <label><input type="checkbox" name="days_of_week[]" value="3" class="edit-day-checkbox"> Wednesday</label><br>
                            <label><input type="checkbox" name="days_of_week[]" value="4" class="edit-day-checkbox"> Thursday</label><br>
                            <label><input type="checkbox" name="days_of_week[]" value="5" class="edit-day-checkbox"> Friday</label><br>
                            <label><input type="checkbox" name="days_of_week[]" value="6" class="edit-day-checkbox"> Saturday</label><br>
                            <label><input type="checkbox" name="days_of_week[]" value="0" class="edit-day-checkbox"> Sunday</label>
                        </fieldset>
                        <p class="description">Select the days when the scan should run</p>
                    </td>
                </tr>
                
                
                <tr>
                    <th scope="row">
                        <label for="edit_is_active">Active</label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" id="edit_is_active" name="is_active" value="1">
                            Enable this schedule
                        </label>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <button type="submit" class="button button-primary">
                    <span class="dashicons dashicons-saved"></span>
                    Update Schedule
                </button>
                <button type="button" class="button cancel-edit-btn">
                    Cancel
                </button>
            </p>
        </form>
    </div>

    <!-- Existing Schedules -->
    <div class="file-integrity-card">
        <h3>Existing Schedules</h3>
        
        <?php if ( empty( $schedules ) ) : ?>
            <p>No schedules have been created yet.</p>
        <?php else : ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Frequency</th>
                        <th>Schedule</th>
                        <th>Status</th>
                        <th>Last Run</th>
                        <th>Next Run</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $schedules as $schedule ) : ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html( $schedule->name ); ?></strong>
                            </td>
                            <td>
                                <span class="schedule-frequency"><?php echo esc_html( ucfirst( $schedule->frequency ) ); ?></span>
                            </td>
                            <td>
                                <?php
                                $schedule_desc = '';
                                switch ( $schedule->frequency ) {
                                    case 'hourly':
                                        $schedule_desc = sprintf( 'Every hour at :%02d', $schedule->minute ?? 0 );
                                        break;
                                    case 'daily':
                                        $time = $schedule->time ?: sprintf( '%02d:%02d', $schedule->hour ?? 0, $schedule->minute ?? 0 );
                                        $schedule_desc = 'Every day at ' . esc_html( $time );
                                        break;
                                    case 'weekly':
                                        $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                                        $days_of_week = ! empty( $schedule->days_of_week ) ? json_decode( $schedule->days_of_week, true ) : [ $schedule->day_of_week ?? 1 ];
                                        $selected_days = [];
                                        foreach ( $days_of_week as $day_num ) {
                                            if ( isset( $days[$day_num] ) ) {
                                                $selected_days[] = $days[$day_num];
                                            }
                                        }
                                        $time = $schedule->time ?: sprintf( '%02d:%02d', $schedule->hour ?? 0, $schedule->minute ?? 0 );
                                        if ( count( $selected_days ) > 1 ) {
                                            $schedule_desc = 'Every ' . implode( ', ', $selected_days ) . ' at ' . esc_html( $time );
                                        } else {
                                            $schedule_desc = 'Every ' . ( $selected_days[0] ?? 'Monday' ) . ' at ' . esc_html( $time );
                                        }
                                        break;
                                }
                                echo esc_html( $schedule_desc );
                                ?>
                            </td>
                            <td>
                                <?php if ( $schedule->is_active ) : ?>
                                    <span class="status-badge status-completed">Active</span>
                                <?php else : ?>
                                    <span class="status-badge status-failed">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                if ( $schedule->last_run ) {
                                    // Convert UTC to WordPress timezone for display
                                    $last_run_utc = new DateTime( $schedule->last_run, new DateTimeZone( 'UTC' ) );
                                    $now_utc = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
                                    
                                    // Use UTC timestamps for consistent comparison
                                    echo esc_html( human_time_diff( $last_run_utc->getTimestamp(), $now_utc->getTimestamp() ) . ' ago' );
                                } else {
                                    echo '<em>Never</em>';
                                }
                                ?>
                            </td>
                            <td>
                                <?php
                                if ( $schedule->next_run && $schedule->is_active ) {
                                    // Convert UTC next_run to site timezone for display
                                    $next_utc = new DateTime( $schedule->next_run, new DateTimeZone( 'UTC' ) );
                                    $next_local = clone $next_utc;
                                    $next_local->setTimezone( wp_timezone() );
                                    
                                    $now = current_datetime(); // Already in site timezone
                                    
                                    if ( $next_local > $now ) {
                                        $diff = $now->diff( $next_local );
                                        $hours = $diff->h + ($diff->days * 24);
                                        
                                        if ( $diff->days > 0 ) {
                                            echo sprintf( 'In %d day%s, %d hour%s', 
                                                $diff->days, 
                                                $diff->days > 1 ? 's' : '',
                                                $diff->h,
                                                $diff->h != 1 ? 's' : ''
                                            );
                                        } elseif ( $hours > 0 ) {
                                            echo sprintf( 'In %d hour%s, %d minute%s', 
                                                $hours, 
                                                $hours > 1 ? 's' : '',
                                                $diff->i,
                                                $diff->i != 1 ? 's' : ''
                                            );
                                        } else {
                                            echo sprintf( 'In %d minute%s', 
                                                $diff->i,
                                                $diff->i != 1 ? 's' : ''
                                            );
                                        }
                                        
                                        // Also show the exact time in local timezone
                                        echo '<br><small>' . esc_html( $next_local->format( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) . '</small>';
                                    } else {
                                        echo '<em>Due now</em>';
                                    }
                                } else {
                                    echo '<em>—</em>';
                                }
                                ?>
                            </td>
                            <td>
                                <button type="button" class="button button-small edit-schedule-btn" 
                                        data-schedule-id="<?php echo esc_attr( $schedule->id ); ?>"
                                        data-schedule-name="<?php echo esc_attr( $schedule->name ); ?>"
                                        data-frequency="<?php echo esc_attr( $schedule->frequency ); ?>"
                                        data-time="<?php echo esc_attr( $schedule->time ); ?>"
                                        data-minute="<?php echo esc_attr( $schedule->minute ?? '' ); ?>"
                                        data-days-of-week="<?php echo esc_attr( $schedule->days_of_week ?? '' ); ?>"
                                        data-is-active="<?php echo esc_attr( $schedule->is_active ); ?>">
                                    <span class="dashicons dashicons-edit"></span> Edit
                                </button>
                                
                                <form method="post" style="display: inline;" onsubmit="event.preventDefault(); FICModal.confirm('Are you sure you want to delete this schedule?', 'Delete Schedule', 'Yes, Delete', 'Cancel').then(confirmed => { if(confirmed) this.submit(); }); return false;">
                                    <?php wp_nonce_field( 'file_integrity_schedule_action' ); ?>
                                    <input type="hidden" name="action" value="delete_schedule">
                                    <input type="hidden" name="schedule_id" value="<?php echo esc_attr( $schedule->id ); ?>">
                                    <button type="submit" class="button button-small button-link-delete">
                                        <span class="dashicons dashicons-trash"></span> Delete
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Show/hide fields based on frequency selection - for both create and edit forms
    function setupFrequencyHandlers(formSelector) {
        $(formSelector + ' select[name="frequency"]').on('change', function() {
            var frequency = $(this).val();
            var form = $(this).closest('.file-integrity-card');
            
            // Hide all conditional rows first
            form.find('.schedule-minute-row, .schedule-time-row, .schedule-day-of-week-row, .edit-schedule-minute-row, .edit-schedule-time-row, .edit-schedule-day-of-week-row').hide();
            
            // Show relevant fields - determine class prefix based on form
            var isEditForm = formSelector.indexOf('edit') > -1;
            var minuteRowClass = isEditForm ? '.edit-schedule-minute-row' : '.schedule-minute-row';
            var timeRowClass = isEditForm ? '.edit-schedule-time-row' : '.schedule-time-row';
            var weekRowClass = isEditForm ? '.edit-schedule-day-of-week-row' : '.schedule-day-of-week-row';
            
            switch(frequency) {
                case 'hourly':
                    // Only show minute selector for hourly
                    form.find(minuteRowClass).show();
                    break;
                case 'daily':
                    form.find(timeRowClass).show();
                    break;
                case 'weekly':
                    form.find(timeRowClass).show();
                    form.find(weekRowClass).show();
                    break;
            }
        }).trigger('change');
    }
    
    // Setup frequency handlers for create form
    setupFrequencyHandlers('#create-schedule-form');
    setupFrequencyHandlers('#edit-schedule-form');
    
    // Handle edit button clicks
    $('.edit-schedule-btn').on('click', function() {
        var btn = $(this);
        
        // Get schedule data from data attributes
        var scheduleId = btn.data('schedule-id');
        var scheduleName = btn.data('schedule-name');
        var frequency = btn.data('frequency');
        var time = btn.data('time');
        var minute = btn.data('minute');
        var daysOfWeek = btn.data('days-of-week');
        var isActive = btn.data('is-active');
        
        // Populate the edit form (use underscores to match the form field IDs)
        $('#edit_schedule_id').val(scheduleId);
        $('#edit_schedule_name').val(scheduleName);
        $('#edit_frequency').val(frequency);
        $('#edit_time').val(time);
        $('#edit_minute').val(minute);
        $('#edit_is_active').prop('checked', isActive == 1);
        
        // Handle days of week for weekly schedules
        $('.edit-day-checkbox').prop('checked', false);
        if (daysOfWeek) {
            try {
                var days = JSON.parse(daysOfWeek);
                if (Array.isArray(days)) {
                    days.forEach(function(day) {
                        $('.edit-day-checkbox[value="' + day + '"]').prop('checked', true);
                    });
                }
            } catch (e) {
                // Fallback for single day (legacy format)
                if (daysOfWeek !== '') {
                    $('.edit-day-checkbox[value="' + daysOfWeek + '"]').prop('checked', true);
                }
            }
        }
        
        // Trigger frequency change to show/hide appropriate fields
        $('#edit_frequency').trigger('change');
        
        // Show edit form and hide create form
        $('#edit-schedule-form').show();
        $('#create-schedule-form').hide();
        
        // Scroll to the edit form
        $('html, body').animate({
            scrollTop: $('#edit-schedule-form').offset().top - 50
        }, 500);
    });
    
    // Handle cancel edit button
    $('.cancel-edit-btn').on('click', function() {
        // Hide edit form and show create form  
        $('#edit-schedule-form').hide();
        
        // Clear edit form
        $('#edit-schedule-form form')[0].reset();
    });
    
    // Initially hide edit form
    $('#edit-schedule-form').hide();
});
</script>