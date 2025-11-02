<?php
/**
 * Plugin Links
 *
 * @package EightyFourEM\FileIntegrityChecker\Admin
 */

namespace EightyFourEM\FileIntegrityChecker\Admin;

/**
 * Manages plugin action links on the plugins page
 */
class PluginLinks {
    /**
     * Initialize plugin links
     */
    public function init(): void {
        $plugin_basename = plugin_basename( EIGHTYFOUREM_FILE_INTEGRITY_CHECKER_PATH . '84em-file-integrity-checker.php' );
        add_filter( 'plugin_action_links_' . $plugin_basename, [ $this, 'addActionLinks' ] );
    }

    /**
     * Add custom action links to the plugin on the plugins page
     *
     * @param array $links Existing plugin action links
     * @return array Modified plugin action links
     */
    public function addActionLinks( array $links ): array {
        $custom_links = [];

        // Settings link (admin only)
        if ( current_user_can( 'manage_options' ) ) {
            $custom_links[] = sprintf(
                '<a href="%s">%s</a>',
                esc_url( admin_url( 'admin.php?page=file-integrity-checker' ) ),
                esc_html__( 'Settings', '84em-file-integrity-checker' )
            );
        }

        // Changelog link (all users)
        $custom_links[] = sprintf(
            '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
            esc_url( 'https://github.com/84emllc/84em-file-integrity-checker/blob/main/CHANGELOG.md' ),
            esc_html__( 'Changelog', '84em-file-integrity-checker' )
        );

        // View on GitHub link (all users)
        $custom_links[] = sprintf(
            '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
            esc_url( 'https://github.com/84emllc/84em-file-integrity-checker/' ),
            esc_html__( 'View on GitHub', '84em-file-integrity-checker' )
        );

        return array_merge( $custom_links, $links );
    }
}