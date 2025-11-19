<?php
/**
 * Uninstall Script for 84EM File Integrity Checker
 *
 * This file is executed when the plugin is uninstalled.
 * It will only delete data if the user has explicitly opted in.
 *
 * @package EightyFourEM\FileIntegrityChecker
 */

// Exit if uninstall not called from WordPress
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * IMPORTANT SAFETY CHECK:
 * Only delete data if the user has explicitly opted in via the settings.
 * By default, NO DATA IS DELETED to prevent accidental data loss.
 */

// Check if the user has opted to delete data on uninstall
$delete_data_option = get_option( 'eightyfourem_file_integrity_delete_data_on_uninstall', false );

// If the option is not explicitly set to true, preserve all data
if ( ! $delete_data_option ) {
    // Log that data was preserved (if WP_DEBUG is enabled)
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '84EM File Integrity Checker: Uninstall called but data preserved (delete_data_on_uninstall = false)' );
    }

    // Exit without deleting anything
    return;
}

/**
 * USER HAS EXPLICITLY OPTED TO DELETE ALL DATA
 * Proceed with complete cleanup
 */

global $wpdb;

// Load the plugin's autoloader to access DatabaseManager
require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';

// Use DatabaseManager to drop tables properly
if ( class_exists( 'EightyFourEM\FileIntegrityChecker\Database\DatabaseManager' ) ) {
    $database_manager = new \EightyFourEM\FileIntegrityChecker\Database\DatabaseManager();
    $database_manager->dropTables();
}

// Delete all plugin options
$options_to_delete = [
    'eightyfourem_file_integrity_scan_types',
    'eightyfourem_file_integrity_scan_interval',
    'eightyfourem_file_integrity_exclude_patterns',
    'eightyfourem_file_integrity_max_file_size',
    'eightyfourem_file_integrity_notification_enabled',
    'eightyfourem_file_integrity_notification_email',
    'eightyfourem_file_integrity_slack_enabled',
    'eightyfourem_file_integrity_slack_webhook_url',
    'eightyfourem_file_integrity_retention_period',
    'eightyfourem_file_integrity_content_retention_limit',
    'eightyfourem_file_integrity_log_levels',
    'eightyfourem_file_integrity_log_retention_days',
    'eightyfourem_file_integrity_auto_log_cleanup',
    'eightyfourem_file_integrity_debug_mode',
    'eightyfourem_file_integrity_delete_data_on_uninstall',
    'eightyfourem_file_integrity_db_version',
];

foreach ( $options_to_delete as $option ) {
    delete_option( $option );
}

// Delete transients
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        '_transient_file_integrity_%',
        '_transient_timeout_file_integrity_%'
    )
);

// Cancel all scheduled actions (if Action Scheduler is available)
if ( function_exists( 'as_unschedule_all_actions' ) ) {
    // Cancel all file integrity scan actions
    as_unschedule_all_actions( 'eightyfourem_file_integrity_scan' );
    as_unschedule_all_actions( 'eightyfourem_check_scan_schedules' );
    as_unschedule_all_actions( 'eightyfourem_file_integrity_log_cleanup' );
    as_unschedule_all_actions( 'file_integrity_cleanup_cache' ); // Legacy cache cleanup action
    as_unschedule_all_actions( 'eightyfourem_file_integrity_cleanup_cache' ); // Current cache cleanup action
    as_unschedule_all_actions( 'file_integrity_cleanup_legacy_content_table' ); // Legacy cleanup

    // Remove all actions in our group
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}actionscheduler_actions'" ) ) {
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}actionscheduler_actions
                 WHERE group_id IN (
                    SELECT group_id FROM {$wpdb->prefix}actionscheduler_groups
                    WHERE slug = %s
                 )",
                'file-integrity-checker'
            )
        );
    }
}

// Clear any user meta related to the plugin
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
        'file_integrity_%'
    )
);

// Clear rate limiting transients
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        '_transient_rate_limit_%'
    )
);

// Log successful uninstall (if WP_DEBUG is enabled)
if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
    error_log( '84EM File Integrity Checker: Plugin data successfully deleted during uninstall' );
}

// Clear any cached data
wp_cache_flush();
