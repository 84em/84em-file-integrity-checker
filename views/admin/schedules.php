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
                'day_of_week' => isset( $_POST['day_of_week'] ) ? absint( $_POST['day_of_week'] ) : null,
                'day_of_month' => isset( $_POST['day_of_month'] ) ? absint( $_POST['day_of_month'] ) : null,
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
                    'day_of_week' => isset( $_POST['day_of_week'] ) ? absint( $_POST['day_of_week'] ) : null,
                    'day_of_month' => isset( $_POST['day_of_month'] ) ? absint( $_POST['day_of_month'] ) : null,
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
                            <option value="monthly">Monthly</option>
                        </select>
                        <p class="description">How often the scan should run</p>
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
                        <label for="day_of_week">Day of Week</label>
                    </th>
                    <td>
                        <select id="day_of_week" name="day_of_week">
                            <option value="0">Sunday</option>
                            <option value="1" selected>Monday</option>
                            <option value="2">Tuesday</option>
                            <option value="3">Wednesday</option>
                            <option value="4">Thursday</option>
                            <option value="5">Friday</option>
                            <option value="6">Saturday</option>
                        </select>
                        <p class="description">Day of the week to run the scan</p>
                    </td>
                </tr>
                
                <tr class="schedule-day-of-month-row" style="display: none;">
                    <th scope="row">
                        <label for="day_of_month">Day of Month</label>
                    </th>
                    <td>
                        <input type="number" id="day_of_month" name="day_of_month" min="1" max="31" value="1">
                        <p class="description">Day of the month to run the scan (1-31)</p>
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
                            <option value="monthly">Monthly</option>
                        </select>
                        <p class="description">How often the scan should run</p>
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
                        <label for="edit_day_of_week">Day of Week</label>
                    </th>
                    <td>
                        <select id="edit_day_of_week" name="day_of_week">
                            <option value="0">Sunday</option>
                            <option value="1">Monday</option>
                            <option value="2">Tuesday</option>
                            <option value="3">Wednesday</option>
                            <option value="4">Thursday</option>
                            <option value="5">Friday</option>
                            <option value="6">Saturday</option>
                        </select>
                        <p class="description">Day of the week to run the scan</p>
                    </td>
                </tr>
                
                <tr class="edit-schedule-day-of-month-row" style="display: none;">
                    <th scope="row">
                        <label for="edit_day_of_month">Day of Month</label>
                    </th>
                    <td>
                        <input type="number" id="edit_day_of_month" name="day_of_month" min="1" max="31">
                        <p class="description">Day of the month to run the scan (1-31)</p>
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
                                        $day = $days[$schedule->day_of_week ?? 1];
                                        $time = $schedule->time ?: sprintf( '%02d:%02d', $schedule->hour ?? 0, $schedule->minute ?? 0 );
                                        $schedule_desc = "Every $day at " . esc_html( $time );
                                        break;
                                    case 'monthly':
                                        $day = $schedule->day_of_month ?? 1;
                                        $time = $schedule->time ?: sprintf( '%02d:%02d', $schedule->hour ?? 0, $schedule->minute ?? 0 );
                                        $schedule_desc = "Day $day of each month at " . esc_html( $time );
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
                                    // Convert UTC to local for display
                                    $last_run_utc = new DateTime( $schedule->last_run, new DateTimeZone( 'UTC' ) );
                                    $last_run_local = clone $last_run_utc;
                                    $last_run_local->setTimezone( wp_timezone() );
                                    
                                    echo esc_html( human_time_diff( $last_run_local->getTimestamp(), current_time( 'timestamp' ) ) . ' ago' );
                                } else {
                                    echo '<em>Never</em>';
                                }
                                ?>
                            </td>
                            <td>
                                <?php
                                if ( $schedule->next_run && $schedule->is_active ) {
                                    // WordPress stores in UTC, convert to site timezone for display
                                    $next_utc = new DateTime( $schedule->next_run, new DateTimeZone( 'UTC' ) );
                                    $next_local = clone $next_utc;
                                    $next_local->setTimezone( wp_timezone() );
                                    
                                    $now = current_datetime();
                                    
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
                                        
                                        // Also show the exact time
                                        echo '<br><small>' . esc_html( $next_local->format( 'M j, g:i A' ) ) . '</small>';
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
                                        data-day-of-week="<?php echo esc_attr( $schedule->day_of_week ?? '' ); ?>"
                                        data-day-of-month="<?php echo esc_attr( $schedule->day_of_month ?? '' ); ?>"
                                        data-is-active="<?php echo esc_attr( $schedule->is_active ); ?>">
                                    <span class="dashicons dashicons-edit"></span> Edit
                                </button>
                                
                                <form method="post" style="display: inline;">
                                    <?php wp_nonce_field( 'file_integrity_schedule_action' ); ?>
                                    <input type="hidden" name="action" value="toggle_schedule">
                                    <input type="hidden" name="schedule_id" value="<?php echo esc_attr( $schedule->id ); ?>">
                                    <input type="hidden" name="enable" value="<?php echo $schedule->is_active ? '0' : '1'; ?>">
                                    <button type="submit" class="button button-small">
                                        <?php if ( $schedule->is_active ) : ?>
                                            <span class="dashicons dashicons-pause"></span> Disable
                                        <?php else : ?>
                                            <span class="dashicons dashicons-controls-play"></span> Enable
                                        <?php endif; ?>
                                    </button>
                                </form>
                                
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
            form.find('.schedule-day-of-week-row, .schedule-day-of-month-row').hide();
            
            // Show relevant fields
            switch(frequency) {
                case 'hourly':
                    // Only show time (for minute selection)
                    form.find('.schedule-time-row').show();
                    break;
                case 'daily':
                    form.find('.schedule-time-row').show();
                    break;
                case 'weekly':
                    form.find('.schedule-time-row').show();
                    form.find('.schedule-day-of-week-row').show();
                    break;
                case 'monthly':
                    form.find('.schedule-time-row').show();
                    form.find('.schedule-day-of-month-row').show();
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
        var dayOfWeek = btn.data('day-of-week');
        var dayOfMonth = btn.data('day-of-month');
        var isActive = btn.data('is-active');
        
        // Populate the edit form
        $('#edit-schedule-id').val(scheduleId);
        $('#edit-schedule-name').val(scheduleName);
        $('#edit-frequency').val(frequency);
        $('#edit-time').val(time);
        $('#edit-day-of-week').val(dayOfWeek);
        $('#edit-day-of-month').val(dayOfMonth);
        $('#edit-is-active').prop('checked', isActive == 1);
        
        // Trigger frequency change to show/hide appropriate fields
        $('#edit-frequency').trigger('change');
        
        // Show edit form and hide create form
        $('#edit-schedule-form').show();
        $('#create-schedule-form').hide();
        
        // Scroll to the edit form
        $('html, body').animate({
            scrollTop: $('#edit-schedule-form').offset().top - 50
        }, 500);
    });
    
    // Handle cancel edit button
    $('#cancel-edit-btn').on('click', function() {
        // Hide edit form and show create form
        $('#edit-schedule-form').hide();
        $('#create-schedule-form').show();
        
        // Clear edit form
        $('#edit-schedule-form form')[0].reset();
    });
    
    // Initially hide edit form
    $('#edit-schedule-form').hide();
});
</script>