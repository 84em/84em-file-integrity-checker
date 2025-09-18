<?php
/**
 * Plugin Links Test
 *
 * @package EightyFourEM\FileIntegrityChecker\Tests\Unit\Admin
 */

namespace EightyFourEM\FileIntegrityChecker\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use EightyFourEM\FileIntegrityChecker\Admin\PluginLinks;
use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * Test the PluginLinks class
 */
class PluginLinksTest extends TestCase {
    /**
     * Set up test environment
     */
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Define constants if not already defined
        if ( ! defined( 'EIGHTYFOUREM_FILE_INTEGRITY_CHECKER_PATH' ) ) {
            define( 'EIGHTYFOUREM_FILE_INTEGRITY_CHECKER_PATH', '/path/to/plugin/' );
        }
    }

    /**
     * Tear down test environment
     */
    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Test that init method registers the correct filter
     */
    public function testInitRegistersPluginActionLinksFilter(): void {
        Functions\expect( 'plugin_basename' )
            ->once()
            ->with( EIGHTYFOUREM_FILE_INTEGRITY_CHECKER_PATH . '84em-file-integrity-checker.php' )
            ->andReturn( '84em-file-integrity-checker/84em-file-integrity-checker.php' );

        Functions\expect( 'add_filter' )
            ->once()
            ->with(
                'plugin_action_links_84em-file-integrity-checker/84em-file-integrity-checker.php',
                \Mockery::type( 'array' )
            );

        $pluginLinks = new PluginLinks();
        $pluginLinks->init();
    }

    /**
     * Test that addActionLinks adds settings link for users with manage_options capability
     */
    public function testAddActionLinksAddsSettingsLinkForAuthorizedUsers(): void {
        Functions\expect( 'current_user_can' )
            ->once()
            ->with( 'manage_options' )
            ->andReturn( true );

        Functions\expect( 'admin_url' )
            ->once()
            ->with( 'admin.php?page=file-integrity-checker' )
            ->andReturn( 'http://example.com/wp-admin/admin.php?page=file-integrity-checker' );

        Functions\expect( 'esc_url' )
            ->once()
            ->andReturnUsing( function( $url ) {
                return $url;
            } );

        Functions\expect( 'esc_html__' )
            ->once()
            ->with( 'Settings', '84em-file-integrity-checker' )
            ->andReturn( 'Settings' );

        $pluginLinks = new PluginLinks();

        $existingLinks = [
            'deactivate' => '<a href="#">Deactivate</a>',
        ];

        $result = $pluginLinks->addActionLinks( $existingLinks );

        $this->assertCount( 2, $result );
        $this->assertStringContainsString( 'Settings', $result[0] );
        $this->assertStringContainsString( 'admin.php?page=file-integrity-checker', $result[0] );
        $this->assertEquals( '<a href="#">Deactivate</a>', $result[1] );
    }

    /**
     * Test that addActionLinks returns unchanged links for users without manage_options capability
     */
    public function testAddActionLinksReturnsUnchangedLinksForUnauthorizedUsers(): void {
        Functions\expect( 'current_user_can' )
            ->once()
            ->with( 'manage_options' )
            ->andReturn( false );

        $pluginLinks = new PluginLinks();

        $existingLinks = [
            'deactivate' => '<a href="#">Deactivate</a>',
        ];

        $result = $pluginLinks->addActionLinks( $existingLinks );

        $this->assertCount( 1, $result );
        $this->assertEquals( $existingLinks, $result );
    }
}