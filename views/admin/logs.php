<?php
/**
 * System Logs Admin Page
 *
 * @package EightyFourEM\FileIntegrityChecker
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Get logger service from admin context
$logger = $this->logger;

// Handle clear logs action
if ( isset( $_POST['clear_logs'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'clear_logs' ) ) {
    $logger->clearAllLogs();
    echo '<div class="notice notice-success"><p>' . esc_html__( 'All logs have been cleared.', '84em-file-integrity-checker' ) . '</p></div>';
}

// Get filter parameters
$filter_level = isset( $_GET['level'] ) ? sanitize_text_field( $_GET['level'] ) : '';
$filter_context = isset( $_GET['context'] ) ? sanitize_text_field( $_GET['context'] ) : '';
$filter_search = isset( $_GET['search'] ) ? sanitize_text_field( $_GET['search'] ) : '';
$filter_date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( $_GET['date_from'] ) : '';
$filter_date_to = isset( $_GET['date_to'] ) ? sanitize_text_field( $_GET['date_to'] ) : '';

// Pagination
$current_page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
$per_page = 50;
$offset = ( $current_page - 1 ) * $per_page;

// Build query args
$query_args = [
    'limit' => $per_page,
    'offset' => $offset,
];

if ( $filter_level ) {
    $query_args['log_level'] = $filter_level;
}
if ( $filter_context ) {
    $query_args['context'] = $filter_context;
}
if ( $filter_search ) {
    $query_args['search'] = $filter_search;
}
if ( $filter_date_from ) {
    $query_args['date_from'] = $filter_date_from . ' 00:00:00';
}
if ( $filter_date_to ) {
    $query_args['date_to'] = $filter_date_to . ' 23:59:59';
}

// Get logs
$logs = $logger->getLogs( $query_args );
$total_logs = $logger->getLogCount( array_diff_key( $query_args, [ 'limit' => '', 'offset' => '' ] ) );
$total_pages = ceil( $total_logs / $per_page );

// Get available filters
$available_contexts = $logger->getAvailableContexts();
$available_levels = $logger->getAvailableLevels();
?>

<div class="wrap">
    <h1 class="wp-heading-inline">
        <?php esc_html_e( 'System Logs', '84em-file-integrity-checker' ); ?>
    </h1>

    <div class="notice notice-info">
        <p><?php esc_html_e( 'System logs help you track plugin activities and diagnose issues.', '84em-file-integrity-checker' ); ?></p>
    </div>

    <!-- Filters -->
    <div class="tablenav top">
        <form method="get" action="">
            <input type="hidden" name="page" value="file-integrity-checker-logs">
            
            <div class="alignleft actions">
                <select name="level" id="filter-level">
                    <option value=""><?php esc_html_e( 'All Levels', '84em-file-integrity-checker' ); ?></option>
                    <?php foreach ( $available_levels as $level ) : ?>
                        <option value="<?php echo esc_attr( $level ); ?>" <?php selected( $filter_level, $level ); ?>>
                            <?php echo esc_html( $logger->getLevelLabel( $level ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="context" id="filter-context">
                    <option value=""><?php esc_html_e( 'All Contexts', '84em-file-integrity-checker' ); ?></option>
                    <?php foreach ( $available_contexts as $context ) : ?>
                        <option value="<?php echo esc_attr( $context ); ?>" <?php selected( $filter_context, $context ); ?>>
                            <?php echo esc_html( ucfirst( $context ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <input type="date" name="date_from" value="<?php echo esc_attr( $filter_date_from ); ?>" placeholder="<?php esc_attr_e( 'From Date', '84em-file-integrity-checker' ); ?>">
                <input type="date" name="date_to" value="<?php echo esc_attr( $filter_date_to ); ?>" placeholder="<?php esc_attr_e( 'To Date', '84em-file-integrity-checker' ); ?>">
                
                <input type="search" name="search" value="<?php echo esc_attr( $filter_search ); ?>" placeholder="<?php esc_attr_e( 'Search messages...', '84em-file-integrity-checker' ); ?>">
                
                <input type="submit" class="button" value="<?php esc_attr_e( 'Filter', '84em-file-integrity-checker' ); ?>">
                
                <?php if ( $filter_level || $filter_context || $filter_search || $filter_date_from || $filter_date_to ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=file-integrity-checker-logs' ) ); ?>" class="button">
                        <?php esc_html_e( 'Clear Filters', '84em-file-integrity-checker' ); ?>
                    </a>
                <?php endif; ?>
            </div>
        </form>

        <div class="alignright">
            <form method="post" action="" id="clear-logs-form" style="display: inline;">
                <?php wp_nonce_field( 'clear_logs' ); ?>
                <input type="hidden" name="clear_logs" value="1">
                <button type="button" id="clear-logs-btn" class="button button-secondary">
                    <?php esc_html_e( 'Clear All Logs', '84em-file-integrity-checker' ); ?>
                </button>
            </form>
        </div>
    </div>

    <?php if ( empty( $logs ) ) : ?>
        <div class="notice notice-info">
            <p><?php esc_html_e( 'No logs found matching your criteria.', '84em-file-integrity-checker' ); ?></p>
        </div>
    <?php else : ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col" class="column-timestamp" style="width: 150px;"><?php esc_html_e( 'Timestamp', '84em-file-integrity-checker' ); ?></th>
                    <th scope="col" class="column-level" style="width: 100px;"><?php esc_html_e( 'Level', '84em-file-integrity-checker' ); ?></th>
                    <th scope="col" class="column-context" style="width: 120px;"><?php esc_html_e( 'Context', '84em-file-integrity-checker' ); ?></th>
                    <th scope="col" class="column-message"><?php esc_html_e( 'Message', '84em-file-integrity-checker' ); ?></th>
                    <th scope="col" class="column-user" style="width: 100px;"><?php esc_html_e( 'User', '84em-file-integrity-checker' ); ?></th>
                    <th scope="col" class="column-details" style="width: 80px;"><?php esc_html_e( 'Details', '84em-file-integrity-checker' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $logs as $log ) : ?>
                    <?php
                    $level_color = $logger->getLevelColor( $log['log_level'] );
                    $user_info = $log['user_id'] ? get_userdata( $log['user_id'] ) : null;
                    ?>
                    <tr>
                        <td class="column-timestamp">
                            <?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $log['created_at'] ) ); ?>
                        </td>
                        <td class="column-level">
                            <span class="log-level-badge" style="
                                background-color: <?php echo esc_attr( $level_color ); ?>;
                                color: white;
                                padding: 3px 8px;
                                border-radius: 3px;
                                font-size: 12px;
                                display: inline-block;
                            ">
                                <?php echo esc_html( $logger->getLevelLabel( $log['log_level'] ) ); ?>
                            </span>
                        </td>
                        <td class="column-context">
                            <code><?php echo esc_html( $log['context'] ); ?></code>
                        </td>
                        <td class="column-message">
                            <?php echo esc_html( $log['message'] ); ?>
                        </td>
                        <td class="column-user">
                            <?php if ( $user_info ) : ?>
                                <a href="<?php echo esc_url( get_edit_user_link( $log['user_id'] ) ); ?>">
                                    <?php echo esc_html( $user_info->user_login ); ?>
                                </a>
                            <?php else : ?>
                                <span style="color: #666;">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="column-details">
                            <?php if ( ! empty( $log['data'] ) ) : ?>
                                <button type="button" class="button button-small view-log-details" 
                                        data-log-id="<?php echo esc_attr( $log['id'] ); ?>"
                                        data-log-data="<?php echo esc_attr( wp_json_encode( $log['data'] ) ); ?>">
                                    <?php esc_html_e( 'View', '84em-file-integrity-checker' ); ?>
                                </button>
                            <?php else : ?>
                                <span style="color: #666;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Pagination -->
        <?php if ( $total_pages > 1 ) : ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <span class="displaying-num">
                        <?php printf(
                            esc_html( _n( '%s item', '%s items', $total_logs, '84em-file-integrity-checker' ) ),
                            number_format_i18n( $total_logs )
                        ); ?>
                    </span>
                    <?php
                    $pagination_args = [
                        'base' => add_query_arg( 'paged', '%#%' ),
                        'format' => '',
                        'current' => $current_page,
                        'total' => $total_pages,
                        'prev_text' => '&laquo;',
                        'next_text' => '&raquo;',
                    ];
                    echo paginate_links( $pagination_args );
                    ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Log Details Modal -->
<div id="log-details-modal" style="display: none;">
    <div class="log-details-overlay" style="
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0,0,0,0.7);
        z-index: 159900;
    "></div>
    <div class="log-details-content" style="
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        background: white;
        padding: 20px;
        border-radius: 5px;
        max-width: 600px;
        max-height: 80vh;
        overflow-y: auto;
        z-index: 160000;
        box-shadow: 0 3px 30px rgba(0,0,0,0.2);
    ">
        <h3><?php esc_html_e( 'Log Details', '84em-file-integrity-checker' ); ?></h3>
        <pre id="log-details-data" style="
            background: #f5f5f5;
            padding: 15px;
            border-radius: 3px;
            overflow-x: auto;
            font-size: 12px;
            line-height: 1.4;
        "></pre>
        <button type="button" class="button button-primary" id="close-log-details">
            <?php esc_html_e( 'Close', '84em-file-integrity-checker' ); ?>
        </button>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // View log details
    $('.view-log-details').on('click', function() {
        var logData = $(this).data('log-data');
        $('#log-details-data').text(JSON.stringify(logData, null, 2));
        $('#log-details-modal').show();
    });

    // Close modal
    $('#close-log-details, .log-details-overlay').on('click', function() {
        $('#log-details-modal').hide();
    });

    // Close modal on escape key
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape') {
            $('#log-details-modal').hide();
        }
    });

    // Handle clear logs button with FICModal
    $('#clear-logs-btn').on('click', function(e) {
        e.preventDefault();
        
        // Use FICModal for confirmation
        if (typeof FICModal !== 'undefined') {
            FICModal.confirm(
                '<?php echo esc_js( __( 'Are you sure you want to clear all logs? This action cannot be undone.', '84em-file-integrity-checker' ) ); ?>',
                '<?php echo esc_js( __( 'Clear All Logs', '84em-file-integrity-checker' ) ); ?>',
                '<?php echo esc_js( __( 'Yes, Clear Logs', '84em-file-integrity-checker' ) ); ?>',
                '<?php echo esc_js( __( 'Cancel', '84em-file-integrity-checker' ) ); ?>'
            ).then(function(confirmed) {
                if (confirmed) {
                    $('#clear-logs-form').submit();
                }
            });
        } else {
            // Fallback to native confirm if FICModal is not available
            if (confirm('<?php echo esc_js( __( 'Are you sure you want to clear all logs? This action cannot be undone.', '84em-file-integrity-checker' ) ); ?>')) {
                $('#clear-logs-form').submit();
            }
        }
    });
});
</script>

<style>
/* Additional styles for better log display */
.wp-list-table .column-message {
    word-break: break-word;
}

.wp-list-table .column-timestamp {
    white-space: nowrap;
}

.tablenav .actions select,
.tablenav .actions input[type="date"],
.tablenav .actions input[type="search"] {
    margin-right: 5px;
}

@media screen and (max-width: 782px) {
    .tablenav .actions {
        display: block;
        margin-bottom: 10px;
    }
    
    .tablenav .actions select,
    .tablenav .actions input {
        display: block;
        width: 100%;
        margin-bottom: 5px;
    }
}
</style>